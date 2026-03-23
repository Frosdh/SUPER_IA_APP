import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:fu_uber/Core/Preferences/DriverPrefs.dart';
import 'package:http/http.dart' as http;

@pragma('vm:entry-point')
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  
  // IMPORTANTE: En background, el plugin debe inicializarse de nuevo porque es un proceso diferente (Isolate)
  final FlutterLocalNotificationsPlugin localNotifications = FlutterLocalNotificationsPlugin();
  const AndroidInitializationSettings androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
  await localNotifications.initialize(const InitializationSettings(android: androidSettings));
  
  // Mostrar la notificación
  await localNotifications
      .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
      ?.createNotificationChannel(PushNotificationService.channel);
      
  PushNotificationService._showNotificationWithPlugin(localNotifications, message);
}

class PushNotificationService {
  static final FirebaseMessaging _messaging = FirebaseMessaging.instance;
  static final FlutterLocalNotificationsPlugin _localNotifications = FlutterLocalNotificationsPlugin();
  static GlobalKey<NavigatorState>? _navigatorKey;
  static bool _initialized = false;

  static const AndroidNotificationChannel channel = AndroidNotificationChannel(
    'high_importance_channel_v3',
    'Notificaciones de Viaje',
    description: 'Este canal se usa para avisar sobre nuevos viajes.',
    importance: Importance.max,
    playSound: true,
  );

  static const AndroidNotificationChannel chatChannel = AndroidNotificationChannel(
    'chat_channel_v1',
    'Mensajes de Chat',
    description: 'Mensajes en tiempo real entre pasajero y conductor.',
    importance: Importance.high,
    playSound: true,
  );

  static Future<void> initialize(GlobalKey<NavigatorState> navigatorKey) async {
    if (_initialized) return;
    _initialized = true;
    _navigatorKey = navigatorKey;

    // Configurar handler de segundo plano (Debe ser llamado antes de otras configuraciones de FCM)
    FirebaseMessaging.onBackgroundMessage(_firebaseMessagingBackgroundHandler);

    // Solicitar permiso de notificaciones
    await _messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    // Handle message when app is in foreground
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      final tipo = message.data['tipo'] ?? '';
      if (tipo == 'chat_mensaje') {
        // Notificación estilo chat — solo burbuja, sin diálogo
        _showChatNotification(message);
      } else {
        _showLocalNotification(message);
        _mostrarDialogoForeground(message);
      }
    });

    // Handle message when app is opened from a notification (tap)
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      _navegarDesdeTap(message);
    });

    // Configurar notificaciones locales para Android
    const AndroidInitializationSettings initializationSettingsAndroid = AndroidInitializationSettings('@mipmap/ic_launcher');
    const InitializationSettings initializationSettings = InitializationSettings(android: initializationSettingsAndroid);
    await _localNotifications.initialize(initializationSettings);

    await _localNotifications
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);

    await _localNotifications
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(chatChannel);

    final token = await _messaging.getToken();
    if (token != null && token.isNotEmpty) {
      await AuthPrefs.saveFcmToken(token);
      await syncTokenWithBackend(force: true);
    }

    _messaging.onTokenRefresh.listen((token) async {
      if (token.isEmpty) return;
      await AuthPrefs.saveFcmToken(token);
      await syncTokenWithBackend(force: true);
    });
  }

  /// Solicita el permiso de notificaciones en tiempo de ejecución.
  /// Retorna true si fue concedido, false si fue denegado.
  static Future<bool> requestPermission() async {
    try {
      final settings = await _messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );
      return settings.authorizationStatus == AuthorizationStatus.authorized ||
          settings.authorizationStatus == AuthorizationStatus.provisional;
    } catch (_) {
      return false;
    }
  }

  static Future<String> getToken() async {
    try {
      final token = await _messaging.getToken();
      return token ?? '';
    } catch (_) {
      return '';
    }
  }

  static Future<void> syncTokenWithBackend({bool force = false}) async {
    final token = await AuthPrefs.getFcmToken();
    final syncedToken = await AuthPrefs.getSyncedFcmToken();

    if (token.trim().isEmpty) return;
    if (!force && syncedToken == token) return;

    // ── Determinar si es conductor o pasajero y usar SOLO el endpoint correcto ──
    // Esto evita que el token del conductor quede guardado en la tabla de usuarios
    // (lo que causaba que las notificaciones para el pasajero llegaran al conductor)
    final conductorId = await DriverPrefs.getDriverId();
    final esConductor = conductorId != 0;

    try {
      final Map<String, String> body = {'token_fcm': token};
      String url;

      if (esConductor) {
        // Solo sincronizar como conductor
        url = '${Constants.apiBaseUrl}/actualizar_token_fcm_conductor.php';
        body['conductor_id'] = conductorId.toString();
      } else {
        // Solo sincronizar como pasajero
        final phone = await AuthPrefs.getUserPhone();
        if (phone.trim().isEmpty) return;
        url = '${Constants.apiBaseUrl}/actualizar_token_fcm.php';
        body['telefono'] = phone;
      }

      print('>>> [FCM] Sincronizando como ${esConductor ? "conductor" : "pasajero"} en $url');

      final response = await http.post(
        Uri.parse(url),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: body,
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = json.decode(response.body) as Map<String, dynamic>;
        if (data['status'] == 'success') {
          await AuthPrefs.saveSyncedFcmToken(token);
          print('>>> [FCM] Token sincronizado correctamente');
        }
      } else {
        print('>>> [FCM] Error HTTP ${response.statusCode}');
      }
    } catch (e) {
      print('>>> [FCM] Error general en syncTokenWithBackend: $e');
    }
  }

  static void _mostrarDialogoForeground(RemoteMessage message) {
    final context = _navigatorKey?.currentState?.overlay?.context;
    if (context == null) return;

    final String title = message.notification?.title ?? message.data['title'] ?? 'Nueva notificacion';
    final String body = message.notification?.body ?? message.data['body'] ?? 'Tienes una actualizacion en tu viaje.';

    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            child: const Text('OK'),
            onPressed: () => Navigator.of(context, rootNavigator: true).pop(),
          ),
        ],
      ),
    );
  }

  static void _showLocalNotification(RemoteMessage message) {
    _showNotificationWithPlugin(_localNotifications, message);
  }

  static void _showNotificationWithPlugin(
      FlutterLocalNotificationsPlugin plugin, RemoteMessage message) {
    RemoteNotification? notification = message.notification;

    final String title = notification?.title ?? message.data['title'] ?? 'Nueva actualización';
    final String body  = notification?.body  ?? message.data['body']  ?? 'Tienes un nuevo mensaje.';
    final int id = notification?.hashCode ?? DateTime.now().millisecondsSinceEpoch.remainder(100000);

    plugin.show(
      id,
      title,
      body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          channel.id,
          channel.name,
          channelDescription: channel.description,
          importance: Importance.max,
          priority: Priority.max,
          ticker: 'ticker',
          icon: '@mipmap/ic_launcher',
          fullScreenIntent: true,
          category: AndroidNotificationCategory.call,
        ),
      ),
    );
  }

  // ── Notificación estilo burbuja de chat ───────────────────
  static void _showChatNotification(RemoteMessage message) {
    final String nombre  = message.data['nombre']  ?? 'Mensaje nuevo';
    final String texto   = message.data['mensaje'] ?? message.data['body'] ?? '';
    final String viajeId = message.data['viaje_id'] ?? '0';
    final String remitente = message.data['remitente'] ?? 'pasajero';

    // ID estable por viaje para agrupar mensajes del mismo chat
    final int notifId = int.tryParse(viajeId) ?? 0;

    // Estilo de mensajería (igual a WhatsApp / Telegram)
    final pessoa = Person(
      name: nombre,
      bot: false,
    );

    final mensajeStyle = MessagingStyleInformation(
      pessoa,
      conversationTitle: nombre,
      groupConversation: false,
      messages: [
        Message(
          texto,
          DateTime.now(),
          pessoa,
        ),
      ],
    );

    _localNotifications.show(
      notifId,
      nombre,
      texto,
      NotificationDetails(
        android: AndroidNotificationDetails(
          'chat_channel_v1',
          'Mensajes de Chat',
          channelDescription: 'Mensajes entre pasajero y conductor',
          importance: Importance.high,
          priority: Priority.high,
          styleInformation: mensajeStyle,
          icon: '@mipmap/ic_launcher',
          color: const Color(0xFF7C3AED), // violeta GeoMove
          // Datos para cuando el usuario toca la notificación
          additionalFlags: Int32List.fromList(<int>[4]), // FLAG_AUTO_CANCEL
        ),
      ),
      payload: 'chat|$viajeId|$remitente',
    );
  }

  // ── Navegar cuando el usuario toca una notificación ───────
  static void _navegarDesdeTap(RemoteMessage message) {
    final tipo    = message.data['tipo']     ?? '';
    final viajeId = int.tryParse(message.data['viaje_id'] ?? '0') ?? 0;

    if (tipo == 'chat_mensaje' && viajeId > 0) {
      final context = _navigatorKey?.currentState?.overlay?.context;
      if (context == null) return;

      final remitente  = message.data['remitente'] ?? 'pasajero';
      // El otro es el contrario al remitente que envió
      final miRol      = remitente == 'pasajero' ? 'conductor' : 'pasajero';
      final nombreOtro = message.data['nombre'] ?? 'Usuario';

      Navigator.pushNamed(
        context,
        '/chat',
        arguments: {
          'viaje_id':     viajeId,
          'remitente':    miRol,
          'nombre_otro':  nombreOtro,
          'telefono_otro': '',
        },
      );
    }
  }
}

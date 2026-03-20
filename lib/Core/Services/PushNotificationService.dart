import 'dart:convert';
import 'dart:io';

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
    'high_importance_channel_v3', // id
    'Notificaciones de Viaje', // title
    description: 'Este canal se usa para avisar sobre nuevos viajes.', // description
    importance: Importance.max,
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
      _showLocalNotification(message);
      _mostrarDialogoForeground(message);
    });

    // Handle message when app is opened from a notification
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      print('>>> [FCM] onMessageOpenedApp: ${message.data}');
    });

    // Configurar notificaciones locales para Android
    const AndroidInitializationSettings initializationSettingsAndroid = AndroidInitializationSettings('@mipmap/ic_launcher');
    const InitializationSettings initializationSettings = InitializationSettings(android: initializationSettingsAndroid);
    await _localNotifications.initialize(initializationSettings);

    await _localNotifications
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(channel);

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
    String phone = await AuthPrefs.getUserPhone();
    
    // Si no hay teléfono de pasajero, intentar con el de conductor
    if (phone.isEmpty) {
      phone = await DriverPrefs.getDriverPhone();
    }
    
    final token = await AuthPrefs.getFcmToken();
    final syncedToken = await AuthPrefs.getSyncedFcmToken();

    if (phone.trim().isEmpty || token.trim().isEmpty) return;
    if (!force && syncedToken == token) return;

    // Intentar sincronizar con ambos endpoints por seguridad
    final urls = <String>[
      '${Constants.apiBaseUrl}/actualizar_token_fcm_conductor.php',
      '${Constants.apiBaseUrl}/actualizar_token_fcm.php',
    ];

    for (final url in urls) {
      try {
        final Map<String, String> body = {
          'token_fcm': token,
        };
        
        // El endpoint de conductor usa 'conductor_id' o 'telefono'? 
        // Revisando ApiProvider, usa 'conductor_id'.
        if (url.contains('conductor')) {
          final id = await DriverPrefs.getDriverId();
          if (id == 0) continue;
          body['conductor_id'] = id.toString();
        } else {
          body['telefono'] = phone;
        }

        final response = await http.post(
          Uri.parse(url),
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: body,
        ).timeout(const Duration(seconds: 10));

        if (response.statusCode != 200) continue;

        final data = json.decode(response.body) as Map<String, dynamic>;
        if (data['status'] == 'success') {
          await AuthPrefs.saveSyncedFcmToken(token);
          print('>>> [FCM] Token sincronizado');
          return;
        }
      } catch (e) {
        print('>>> [FCM] Error sincronizando token en $url: $e');
      }
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
    AndroidNotification? android = message.notification?.android;

    final String title = notification?.title ?? message.data['title'] ?? 'Nueva actualización';
    final String body = notification?.body ?? message.data['body'] ?? 'Tienes un nuevo mensaje.';
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
          // Esto es lo que hace que la notificación "salte" (Heads-up)
          fullScreenIntent: true,
          category: AndroidNotificationCategory.call,
        ),
      ),
    );
  }
}

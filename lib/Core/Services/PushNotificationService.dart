import 'dart:convert';
import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:http/http.dart' as http;

class PushNotificationService {
  static final FirebaseMessaging _messaging = FirebaseMessaging.instance;
  static GlobalKey<NavigatorState>? _navigatorKey;
  static bool _initialized = false;

  static Future<void> initialize(GlobalKey<NavigatorState> navigatorKey) async {
    if (_initialized) return;
    _initialized = true;
    _navigatorKey = navigatorKey;

    if (Platform.isIOS) {
      await _messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
      );
    }

    // Handle message when app is in foreground
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      _mostrarDialogoForeground(message);
    });

    // Handle message when app is opened from a notification
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      print('>>> [FCM] onMessageOpenedApp: ${message.data}');
    });

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

  static Future<String> getToken() async {
    try {
      final token = await _messaging.getToken();
      return token ?? '';
    } catch (_) {
      return '';
    }
  }

  static Future<void> syncTokenWithBackend({bool force = false}) async {
    final phone = await AuthPrefs.getUserPhone();
    final token = await AuthPrefs.getFcmToken();
    final syncedToken = await AuthPrefs.getSyncedFcmToken();

    if (phone.trim().isEmpty || token.trim().isEmpty) return;
    if (!force && syncedToken == token) return;

    final urls = <String>[
      '${Constants.apiBaseUrl}/actualizar_token_fcm.php',
    ];

    for (final url in urls) {
      try {
        final response = await http.post(
          Uri.parse(url),
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'telefono': phone,
            'token_fcm': token,
          },
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
}

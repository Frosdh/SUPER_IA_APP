import 'dart:convert';
import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:http/http.dart' as http;

class PushNotificationService {
  static final FirebaseMessaging _messaging = FirebaseMessaging();
  static GlobalKey<NavigatorState> _navigatorKey;
  static bool _initialized = false;

  static Future<void> initialize(GlobalKey<NavigatorState> navigatorKey) async {
    if (_initialized) return;
    _initialized = true;
    _navigatorKey = navigatorKey;

    if (Platform.isIOS) {
      _messaging.requestNotificationPermissions(
        const IosNotificationSettings(
          alert: true,
          badge: true,
          sound: true,
        ),
      );
      _messaging.onIosSettingsRegistered.listen((_) {});
    }

    _messaging.configure(
      onMessage: (Map<String, dynamic> message) async {
        _mostrarDialogoForeground(message);
      },
      onLaunch: (Map<String, dynamic> message) async {
        print('>>> [FCM] onLaunch: $message');
      },
      onResume: (Map<String, dynamic> message) async {
        print('>>> [FCM] onResume: $message');
      },
    );

    final token = await _messaging.getToken();
    if (token != null && token.isNotEmpty) {
      await AuthPrefs.saveFcmToken(token);
      await syncTokenWithBackend(force: true);
    }

    _messaging.onTokenRefresh.listen((token) async {
      if (token == null || token.isEmpty) return;
      await AuthPrefs.saveFcmToken(token);
      await syncTokenWithBackend(force: true);
    });
  }

  static Future<void> syncTokenWithBackend({bool force = false}) async {
    final phone = await AuthPrefs.getUserPhone();
    final token = await AuthPrefs.getFcmToken();
    final syncedToken = await AuthPrefs.getSyncedFcmToken();

    if (phone.trim().isEmpty || token.trim().isEmpty) return;
    if (!force && syncedToken == token) return;

    final urls = <String>[
      '${Constants.apiBaseUrl}/actualizar_token_fcm.php',
      'http://10.0.2.2/fuber_api/actualizar_token_fcm.php',
    ];

    for (final url in urls) {
      try {
        final response = await http.post(
          url,
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

  static void _mostrarDialogoForeground(Map<String, dynamic> message) {
    final context = _navigatorKey?.currentState?.overlay?.context;
    if (context == null) return;

    final dynamic notification = message['notification'];
    final Map<String, dynamic> data =
        (message['data'] as Map)?.cast<String, dynamic>() ??
            <String, dynamic>{};

    final String title = (notification is Map && notification['title'] != null)
        ? notification['title'].toString()
        : (data['title']?.toString() ?? 'Nueva notificacion');
    final String body = (notification is Map && notification['body'] != null)
        ? notification['body'].toString()
        : (data['body']?.toString() ?? 'Tienes una actualizacion en tu viaje.');

    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          FlatButton(
            child: Text('OK'),
            onPressed: () => Navigator.of(context, rootNavigator: true).pop(),
          ),
        ],
      ),
    );
  }
}

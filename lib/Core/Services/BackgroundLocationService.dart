import 'dart:async';
import 'dart:developer';
import 'dart:io';
import 'dart:isolate';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Preferences/DriverPrefs.dart';
import 'package:http/http.dart' as http;

// El manejador de la tarea debe ser una función de nivel superior o un método estático.
@pragma('vm:entry-point')
void startCallback() {
  FlutterForegroundTask.setTaskHandler(MyTaskHandler());
}

class MyTaskHandler extends TaskHandler {
  Timer? _timer;
  int _eventCount = 0;

  @override
  void onStart(DateTime timestamp, SendPort? sendPort) async {
    _timer = Timer.periodic(const Duration(seconds: 15), (timer) async {
      await _sendLocation();
      _eventCount++;
      
      FlutterForegroundTask.updateService(
        notificationTitle: 'GeoMove Conductor',
        notificationText: 'Rastreo activo - Actu. #$_eventCount',
      );
    });
  }

  @override
  void onRepeatEvent(DateTime timestamp, SendPort? sendPort) async {}

  @override
  void onDestroy(DateTime timestamp, SendPort? sendPort) async {
    _timer?.cancel();
  }

  Future<void> _sendLocation() async {
    try {
      final isDriver = await DriverPrefs.isDriverLoggedIn();
      if (!isDriver) return;

      final int id = await DriverPrefs.getDriverId();
      if (id <= 0) return;

      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );

      final response = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/actualizar_ubicacion_conductor.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {
          'conductor_id': id.toString(),
          'latitud': position.latitude.toString(),
          'longitud': position.longitude.toString(),
        },
      ).timeout(const Duration(seconds: 8));

      log('>>> [BG_SERVICE] Ubicación enviada: ${response.statusCode}');
    } catch (e) {
      log('>>> [BG_SERVICE] Error enviando ubicación: $e');
    }
  }
}

class BackgroundLocationService {
  BackgroundLocationService._();

  static Future<void> initialize() async {
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: 'foreground_service_channel',
        channelName: 'Rastreo en Segundo Plano',
        channelDescription: 'Mantiene al conductor conectado y visible en el mapa.',
        channelImportance: NotificationChannelImportance.LOW,
        priority: NotificationPriority.LOW,
        iconData: const NotificationIconData(
          resType: ResourceType.mipmap,
          resPrefix: ResourcePrefix.ic,
          name: 'launcher',
        ),
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: true,
        playSound: false,
      ),
      foregroundTaskOptions: const ForegroundTaskOptions(
        interval: 15000,
        isOnceEvent: false,
        autoRunOnBoot: true,
        allowWakeLock: true,
        allowWifiLock: true,
      ),
    );
  }

  static Future<void> start() async {
    if (await FlutterForegroundTask.isRunningService) return;

    final serviceRequestResult = await FlutterForegroundTask.startService(
      notificationTitle: 'GeoMove Conductor',
      notificationText: 'Buscando señal GPS...',
      callback: startCallback,
    );

    log('>>> [BG_SERVICE] Service start result: $serviceRequestResult');
  }

  static Future<void> stop() async {
    await FlutterForegroundTask.stopService();
  }
}

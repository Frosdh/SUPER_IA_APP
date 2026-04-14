import 'dart:async';
import 'dart:developer';
import 'dart:io';
import 'dart:isolate';

import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Preferences/DriverPrefs.dart';
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
    // Notificar al servidor que el asesor se desconectó para que desaparezca
    // del mapa web de inmediato (sin esperar el intervalo de 3 min).
    try {
      final asesorId  = await AuthPrefs.getAsesorId();
      final usuarioId = await AuthPrefs.getUsuarioId();
      if (asesorId.isNotEmpty || usuarioId.isNotEmpty) {
        // Obtener última posición para marcar el punto final de ruta
        double? lat, lng;
        try {
          final pos = await Geolocator.getLastKnownPosition();
          lat = pos?.latitude;
          lng = pos?.longitude;
        } catch (_) {}

        // 1. Cerrar segmento de ruta activo (sin iniciar uno nuevo)
        try {
          await http.post(
            Uri.parse('${Constants.apiBaseUrl}/api_cerrar_segmento.php'),
            headers: {'ngrok-skip-browser-warning': 'true'},
            body: {
              'asesor_id':  asesorId,
              'usuario_id': usuarioId,
              'latitud':    lat?.toString() ?? '',
              'longitud':   lng?.toString() ?? '',
              'razon':      'logout',
            },
          ).timeout(const Duration(seconds: 6));
          log('>>> [BG_SERVICE] Segmento de ruta cerrado en logout');
        } catch (e) {
          log('>>> [BG_SERVICE] No se pudo cerrar segmento de ruta: $e');
        }

        // 2. Marcar presencia como desconectado
        await http.post(
          Uri.parse('${Constants.apiBaseUrl}/actualizar_estado_asesor.php'),
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'estado':     'desconectado',
            'asesor_id':  asesorId,
            'usuario_id': usuarioId,
          },
        ).timeout(const Duration(seconds: 6));
        log('>>> [BG_SERVICE] Asesor marcado como desconectado en onDestroy');
      }
    } catch (e) {
      log('>>> [BG_SERVICE] onDestroy: no se pudo notificar desconexión: $e');
    }
  }

  Future<void> _sendLocation() async {
    try {
      final isDriver = await DriverPrefs.isDriverLoggedIn();

      // asesor_id es un UUID (char 36), NO un entero — se usa como String directamente
      final String asesorId = await AuthPrefs.getAsesorId();
      final String usuarioId = await AuthPrefs.getUsuarioId();

      // No enviar si no hay rol rastreable
      if (!isDriver && asesorId.isEmpty && usuarioId.isEmpty) return;

      final position = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );

      if (isDriver) {
        final int id = await DriverPrefs.getDriverId();
        if (id <= 0) return;

        final response = await http.post(
          Uri.parse('${Constants.apiBaseUrl}/actualizar_ubicacion_conductor.php'),
          headers: {'ngrok-skip-browser-warning': 'true'},
          body: {
            'conductor_id': id.toString(),
            'latitud': position.latitude.toString(),
            'longitud': position.longitude.toString(),
          },
        ).timeout(const Duration(seconds: 8));

        log('>>> [BG_SERVICE] Ubicación CONDUCTOR enviada: ${response.statusCode}');
        return;
      }

      // Enviar ubicación de asesor: se manda asesor_id (UUID) y usuario_id como respaldo
      final response = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/actualizar_ubicacion_asesor.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {
          'asesor_id':  asesorId,   // UUID string, p.ej. "3f25-..."
          'usuario_id': usuarioId,  // UUID string como respaldo
          'latitud': position.latitude.toString(),
          'longitud': position.longitude.toString(),
          'precision_m': position.accuracy.toString(),
        },
      ).timeout(const Duration(seconds: 8));

      log('>>> [BG_SERVICE] Ubicación ASESOR enviada: ${response.statusCode}');
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

    final isDriver = await DriverPrefs.isDriverLoggedIn();
    // asesor_id es UUID string, verificar que no sea vacío
    final String asesorId = await AuthPrefs.getAsesorId();
    final String usuarioId = await AuthPrefs.getUsuarioId();
    if (!isDriver && asesorId.isEmpty && usuarioId.isEmpty) {
      return;
    }

    final serviceRequestResult = await FlutterForegroundTask.startService(
      notificationTitle: isDriver ? 'GeoMove Conductor' : 'GeoMove Asesor',
      notificationText: 'Buscando señal GPS...',
      callback: startCallback,
    );

    log('>>> [BG_SERVICE] Service start result: $serviceRequestResult');
  }

  static Future<void> stop() async {
    await FlutterForegroundTask.stopService();
  }
}

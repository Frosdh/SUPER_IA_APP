import 'dart:convert';
import 'package:fu_uber/Core/Constants/Constants.dart';
import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class RideHistoryService {
  // Clave local única por usuario: ride_history_<telefono>
  static Future<String> _claveLocal() async {
    final telefono = await AuthPrefs.getUserPhone();
    return 'ride_history_$telefono';
  }

  // URLs del servidor
  static List<String> _urlsGuardar() => [
        '${Constants.apiBaseUrl}/guardar_viaje.php',
        'http://10.0.2.2/fuber_api/guardar_viaje.php',
      ];

  static List<String> _urlsObtener() => [
        '${Constants.apiBaseUrl}/obtener_viajes.php',
        'http://10.0.2.2/fuber_api/obtener_viajes.php',
      ];

  /// Guarda un viaje: actualiza el registro existente en servidor + guarda en caché local
  static Future<void> guardarViaje({
    int viajeId,
    String origen,
    String destino,
    double distanciaKm,
    int duracionMin,
    double precio,
    int calificacion,
    int conductorId,
    String comentario,
    String conductorNombre,
    String conductorAuto,
    String conductorPlaca,
    double origenLat,
    double origenLng,
    double destinoLat,
    double destinoLng,
  }) async {

    // 1. Actualizar el viaje en el servidor (UPDATE, no INSERT)
    bool guardadoEnServidor = false;
    if (viajeId != null && viajeId > 0) {
      for (final url in _urlsGuardar()) {
        try {
          final response = await http.post(url, body: {
            'viaje_id':     viajeId.toString(),
            'calificacion': (calificacion ?? 0).toString(),
            'conductor_id': (conductorId ?? '').toString(),
            'comentario':   comentario ?? '',
          }).timeout(const Duration(seconds: 10));

          if (response.statusCode == 200) {
            final data = json.decode(response.body);
            if (data['status'] == 'success') {
              guardadoEnServidor = true;
              break;
            }
          }
        } catch (_) {}
      }
    }

    // 2. Siempre guardar localmente como caché (para acceso sin internet)
    await _guardarLocal(
      origen: origen,
      destino: destino,
      distanciaKm: distanciaKm,
      duracionMin: duracionMin,
      precio: precio,
      calificacion: calificacion,
      comentario: comentario,
      conductorNombre: conductorNombre,
      conductorAuto: conductorAuto,
      conductorPlaca: conductorPlaca,
    );

    print(guardadoEnServidor
        ? '>>> [HISTORIAL] Viaje guardado en servidor y local'
        : '>>> [HISTORIAL] Servidor no disponible, guardado solo en local');
  }

  /// Obtiene viajes: primero del servidor, si falla usa caché local
  static Future<List<Map<String, dynamic>>> obtenerViajes() async {
    final telefono = await AuthPrefs.getUserPhone();

    // 1. Intentar cargar desde el servidor
    for (final url in _urlsObtener()) {
      try {
        final response = await http.post(url, body: {
          'telefono': telefono,
        }).timeout(const Duration(seconds: 10));

        if (response.statusCode == 200) {
          final data = json.decode(response.body);
          if (data['status'] == 'success') {
            final lista = (data['viajes'] as List)
                .map((e) => Map<String, dynamic>.from(e))
                .toList();
            // Actualizar caché local con datos del servidor
            await _sobreescribirLocal(lista);
            return lista;
          }
        }
      } catch (_) {}
    }

    // 2. Si el servidor no responde, usar caché local
    print('>>> [HISTORIAL] Usando caché local');
    return await _obtenerLocal();
  }

  // ── Métodos internos para caché local ──────────────────

  static Future<void> _guardarLocal({
    String origen,
    String destino,
    double distanciaKm,
    int duracionMin,
    double precio,
    int calificacion,
    String comentario,
    String conductorNombre,
    String conductorAuto,
    String conductorPlaca,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final clave = await _claveLocal();
    final lista = await _obtenerLocal();

    lista.insert(0, {
      'fecha':             DateTime.now().toIso8601String(),
      'origen':            origen           ?? '',
      'destino':           destino          ?? '',
      'distancia_km':      distanciaKm      ?? 0.0,
      'duracion_min':      duracionMin      ?? 0,
      'precio':            precio           ?? 0.0,
      'calificacion':      calificacion     ?? 0,
      'comentario':        comentario       ?? '',
      'conductor_nombre':  conductorNombre  ?? '',
      'conductor_auto':    conductorAuto    ?? '',
      'conductor_placa':   conductorPlaca   ?? '',
    });

    if (lista.length > 50) lista.removeLast();
    await prefs.setString(clave, jsonEncode(lista));
  }

  static Future<List<Map<String, dynamic>>> _obtenerLocal() async {
    final prefs = await SharedPreferences.getInstance();
    final clave = await _claveLocal();
    final raw = prefs.getString(clave);
    if (raw == null || raw.isEmpty) return [];
    try {
      final lista = jsonDecode(raw) as List;
      return lista.map((e) => Map<String, dynamic>.from(e)).toList();
    } catch (_) {
      return [];
    }
  }

  static Future<void> _sobreescribirLocal(
      List<Map<String, dynamic>> viajes) async {
    final prefs = await SharedPreferences.getInstance();
    final clave = await _claveLocal();
    await prefs.setString(clave, jsonEncode(viajes));
  }

  /// Borra el historial local del usuario actual
  static Future<void> limpiarHistorial() async {
    final prefs = await SharedPreferences.getInstance();
    final clave = await _claveLocal();
    await prefs.remove(clave);
  }
}

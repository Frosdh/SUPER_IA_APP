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
  static final List<String> _urlsGuardar = [
        '${Constants.apiBaseUrl}/guardar_viaje.php',
      ];

  static final List<String> _urlsObtener = [
        '${Constants.apiBaseUrl}/obtener_viajes.php',
      ];

  /// Guarda un viaje: actualiza el registro existente en servidor + guarda en caché local
  static Future<void> guardarViaje({
    required int viajeId,
    required String origen,
    required String destino,
    required double distanciaKm,
    required int duracionMin,
    required double precio,
    required int calificacion,
    required int conductorId,
    required String comentario,
    required String conductorNombre,
    required String conductorAuto,
    required String conductorPlaca,
    required double origenLat,
    required double origenLng,
    required double destinoLat,
    required double destinoLng,
    required double descuento,
    required String codigoDescuento,
  }) async {

    // 1. Actualizar el viaje en el servidor (UPDATE, no INSERT)
    bool guardadoEnServidor = false;
    if (viajeId > 0) {
      for (final url in _urlsGuardar) {
        try {
          final response = await http.post(Uri.parse(url), body: {
            'viaje_id':         viajeId.toString(),
            'calificacion':     calificacion.toString(),
            'conductor_id':     conductorId.toString(),
            'comentario':       comentario,
            'descuento':        descuento.toString(),
            'codigo_descuento': codigoDescuento,
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
      viajeId: viajeId,
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
      origenLat: origenLat,
      origenLng: origenLng,
      destinoLat: destinoLat,
      destinoLng: destinoLng,
      descuento: descuento,
      codigoDescuento: codigoDescuento,
    );

    print(guardadoEnServidor
        ? '>>> [HISTORIAL] Viaje guardado en servidor y local'
        : '>>> [HISTORIAL] Servidor no disponible, guardado solo en local');
  }

  /// Obtiene viajes: primero del servidor, si falla usa caché local
  static Future<List<Map<String, dynamic>>> obtenerViajes() async {
    final telefono = await AuthPrefs.getUserPhone();

    // 1. Intentar cargar desde el servidor
    for (final url in _urlsObtener) {
      try {
        final response = await http.post(Uri.parse(url), body: {
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
    required int viajeId,
    required String origen,
    required String destino,
    required double distanciaKm,
    required int duracionMin,
    required double precio,
    required int calificacion,
    required String comentario,
    required String conductorNombre,
    required String conductorAuto,
    required String conductorPlaca,
    required double origenLat,
    required double origenLng,
    required double destinoLat,
    required double destinoLng,
    required double descuento,
    required String codigoDescuento,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final clave = await _claveLocal();
    final lista = await _obtenerLocal();

    lista.insert(0, {
      'id':                viajeId,
      'estado':            'terminado',
      'fecha':             DateTime.now().toIso8601String(),
      'origen':            origen,
      'destino':           destino,
      'distancia_km':      distanciaKm,
      'duracion_min':      duracionMin,
      'precio':            precio,
      'calificacion':      calificacion,
      'comentario':        comentario,
      'conductor_nombre':  conductorNombre,
      'conductor_auto':    conductorAuto,
      'conductor_placa':   conductorPlaca,
      'origen_lat':        origenLat,
      'origen_lng':        origenLng,
      'destino_lat':       destinoLat,
      'destino_lng':       destinoLng,
      'descuento':         descuento,
      'codigo_descuento':  codigoDescuento,
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

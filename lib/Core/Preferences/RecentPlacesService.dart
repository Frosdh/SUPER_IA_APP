import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

/// Un destino reciente guardado por el pasajero
class LugarReciente {
  final String nombre;
  final double lat;
  final double lng;
  final DateTime fecha;

  LugarReciente({
    this.nombre,
    this.lat,
    this.lng,
    this.fecha,
  });

  Map<String, dynamic> toJson() => {
        'nombre': nombre,
        'lat':    lat,
        'lng':    lng,
        'fecha':  fecha?.toIso8601String(),
      };

  factory LugarReciente.fromJson(Map<String, dynamic> json) => LugarReciente(
        nombre: json['nombre'] as String,
        lat:    (json['lat'] as num).toDouble(),
        lng:    (json['lng'] as num).toDouble(),
        fecha:  json['fecha'] != null
            ? DateTime.tryParse(json['fecha'] as String)
            : null,
      );
}

class RecentPlacesService {
  static const String _KEY      = 'recent_places';
  static const int    _MAX_SIZE = 8; // máximo 8 recientes

  // ── Guardar destino reciente ─────────────────────────────────────────
  static Future<void> guardarReciente(LugarReciente lugar) async {
    final prefs = await SharedPreferences.getInstance();
    final lista = await obtenerRecientes();

    // Evitar duplicados por nombre (actualiza la fecha si ya existe)
    lista.removeWhere((l) => l.nombre.trim() == lugar.nombre.trim());

    // Insertar al inicio
    lista.insert(0, lugar);

    // Mantener solo los últimos _MAX_SIZE
    if (lista.length > _MAX_SIZE) lista.removeRange(_MAX_SIZE, lista.length);

    await prefs.setString(_KEY, jsonEncode(lista.map((l) => l.toJson()).toList()));
  }

  // ── Obtener recientes ─────────────────────────────────────────────────
  static Future<List<LugarReciente>> obtenerRecientes() async {
    final prefs = await SharedPreferences.getInstance();
    final raw   = prefs.getString(_KEY);
    if (raw == null || raw.isEmpty) return [];
    try {
      final lista = jsonDecode(raw) as List;
      return lista
          .map((e) => LugarReciente.fromJson(Map<String, dynamic>.from(e)))
          .toList();
    } catch (_) {
      return [];
    }
  }

  // ── Limpiar recientes ─────────────────────────────────────────────────
  static Future<void> limpiarRecientes() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_KEY);
  }
}

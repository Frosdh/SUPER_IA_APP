import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

/// Modelo de lugar favorito (Casa / Trabajo / Personalizado)
class LugarFavorito {
  final String tipo;   // 'casa', 'trabajo', 'custom'
  final String nombre;
  final String direccion;
  final double lat;
  final double lng;

  LugarFavorito({
    this.tipo,
    this.nombre,
    this.direccion,
    this.lat,
    this.lng,
  });

  Map<String, dynamic> toJson() => {
        'tipo':      tipo,
        'nombre':    nombre,
        'direccion': direccion,
        'lat':       lat,
        'lng':       lng,
      };

  factory LugarFavorito.fromJson(Map<String, dynamic> json) => LugarFavorito(
        tipo:      json['tipo']      as String,
        nombre:    json['nombre']    as String,
        direccion: json['direccion'] as String,
        lat:       (json['lat'] as num).toDouble(),
        lng:       (json['lng'] as num).toDouble(),
      );

  String get icono {
    switch (tipo) {
      case 'casa':    return '🏠';
      case 'trabajo': return '💼';
      default:        return '⭐';
    }
  }
}

class FavoritePlacesService {
  static const String _KEY = 'favorite_places';

  // ── Obtener todos los favoritos ─────────────────────────────────────
  static Future<List<LugarFavorito>> obtenerFavoritos() async {
    final prefs = await SharedPreferences.getInstance();
    final raw   = prefs.getString(_KEY);
    if (raw == null || raw.isEmpty) return [];
    try {
      final lista = jsonDecode(raw) as List;
      return lista.map((e) => LugarFavorito.fromJson(Map<String, dynamic>.from(e))).toList();
    } catch (_) {
      return [];
    }
  }

  // ── Guardar o actualizar favorito ───────────────────────────────────
  static Future<void> guardarFavorito(LugarFavorito lugar) async {
    final prefs   = await SharedPreferences.getInstance();
    final lista   = await obtenerFavoritos();

    // Reemplazar si ya existe uno del mismo tipo
    lista.removeWhere((l) => l.tipo == lugar.tipo);
    lista.add(lugar);

    await prefs.setString(_KEY, jsonEncode(lista.map((l) => l.toJson()).toList()));
  }

  // ── Eliminar un favorito por tipo ───────────────────────────────────
  static Future<void> eliminarFavorito(String tipo) async {
    final prefs = await SharedPreferences.getInstance();
    final lista = await obtenerFavoritos();
    lista.removeWhere((l) => l.tipo == tipo);
    await prefs.setString(_KEY, jsonEncode(lista.map((l) => l.toJson()).toList()));
  }

  // ── Obtener favorito específico (casa o trabajo) ────────────────────
  static Future<LugarFavorito> obtenerPorTipo(String tipo) async {
    final lista = await obtenerFavoritos();
    try {
      return lista.firstWhere((l) => l.tipo == tipo);
    } catch (_) {
      return null;
    }
  }
}

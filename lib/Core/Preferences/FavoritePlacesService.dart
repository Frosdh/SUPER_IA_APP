import 'dart:convert';

import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:shared_preferences/shared_preferences.dart';

class LugarFavorito {
  final String tipo;
  final String nombre;
  final String direccion;
  final double lat;
  final double lng;

  LugarFavorito({
    required this.tipo,
    required this.nombre,
    required this.direccion,
    required this.lat,
    required this.lng,
  });

  Map<String, dynamic> toJson() => {
        'tipo': tipo,
        'nombre': nombre,
        'direccion': direccion,
        'lat': lat,
        'lng': lng,
      };

  factory LugarFavorito.fromJson(Map<String, dynamic> json) => LugarFavorito(
        tipo: json['tipo'] as String,
        nombre: json['nombre'] as String,
        direccion: json['direccion'] as String,
        lat: (json['lat'] as num).toDouble(),
        lng: (json['lng'] as num).toDouble(),
      );

  String get icono {
    switch (tipo) {
      case 'casa':
        return '🏠';
      case 'trabajo':
        return '💼';
      default:
        return '⭐';
    }
  }
}

class FavoritePlacesService {
  static const String _baseKey = 'favorite_places';

  static Future<String> _key() async {
    final telefono = (await AuthPrefs.getUserPhone()).trim();
    if (telefono.isEmpty) {
      return _baseKey;
    }
    return '${_baseKey}_$telefono';
  }

  static Future<List<LugarFavorito>> obtenerFavoritos() async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _key();
    final raw = prefs.getString(key) ?? prefs.getString(_baseKey);
    if (raw == null || raw.isEmpty) return <LugarFavorito>[];

    try {
      final lista = jsonDecode(raw) as List;
      return lista
          .map((e) => LugarFavorito.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
    } catch (_) {
      return <LugarFavorito>[];
    }
  }

  static Future<void> guardarFavorito(LugarFavorito lugar) async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _key();
    final lista = await obtenerFavoritos();

    lista.removeWhere((l) => l.tipo == lugar.tipo);
    lista.add(lugar);

    await prefs.setString(
      key,
      jsonEncode(lista.map((l) => l.toJson()).toList()),
    );
  }

  static Future<void> eliminarFavorito(String tipo) async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _key();
    final lista = await obtenerFavoritos();
    lista.removeWhere((l) => l.tipo == tipo);

    await prefs.setString(
      key,
      jsonEncode(lista.map((l) => l.toJson()).toList()),
    );
  }

  static Future<LugarFavorito?> obtenerPorTipo(String tipo) async {
    final lista = await obtenerFavoritos();
    try {
      return lista.firstWhere((l) => l.tipo == tipo);
    } catch (_) {
      return null;
    }
  }
}

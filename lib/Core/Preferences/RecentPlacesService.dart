import 'dart:convert';

import 'package:fu_uber/Core/Preferences/AuthPrefs.dart';
import 'package:shared_preferences/shared_preferences.dart';

class LugarReciente {
  final String nombre;
  final double lat;
  final double lng;
  final DateTime? fecha;

  LugarReciente({
    required this.nombre,
    required this.lat,
    required this.lng,
    this.fecha,
  });

  Map<String, dynamic> toJson() => {
        'nombre': nombre,
        'lat': lat,
        'lng': lng,
        'fecha': fecha?.toIso8601String(),
      };

  factory LugarReciente.fromJson(Map<String, dynamic> json) => LugarReciente(
        nombre: json['nombre'] as String,
        lat: (json['lat'] as num).toDouble(),
        lng: (json['lng'] as num).toDouble(),
        fecha: json['fecha'] != null
            ? DateTime.tryParse(json['fecha'] as String)
            : null,
      );
}

class RecentPlacesService {
  static const String _baseKey = 'recent_places';
  static const int _maxSize = 8;

  static Future<String> _key() async {
    final telefono = (await AuthPrefs.getUserPhone()).trim();
    if (telefono.isEmpty) {
      return _baseKey;
    }
    return '${_baseKey}_$telefono';
  }

  static Future<void> guardarReciente(LugarReciente lugar) async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _key();
    final lista = await obtenerRecientes();

    lista.removeWhere((l) => l.nombre.trim() == lugar.nombre.trim());
    lista.insert(0, lugar);

    if (lista.length > _maxSize) {
      lista.removeRange(_maxSize, lista.length);
    }

    await prefs.setString(
      key,
      jsonEncode(lista.map((l) => l.toJson()).toList()),
    );
  }

  static Future<List<LugarReciente>> obtenerRecientes() async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _key();
    final raw = prefs.getString(key) ?? prefs.getString(_baseKey);
    if (raw == null || raw.isEmpty) return <LugarReciente>[];

    try {
      final lista = jsonDecode(raw) as List;
      return lista
          .map((e) => LugarReciente.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
    } catch (_) {
      return <LugarReciente>[];
    }
  }

  static Future<void> limpiarRecientes() async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _key();
    await prefs.remove(key);
  }
}

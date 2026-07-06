// ============================================================
// InstitucionesCacheService.dart
// ------------------------------------------------------------
// Guarda en el celular (SQLite) una copia de la lista de bancos/
// cooperativas que devuelve api_cooperativas.php (la misma lista
// que usan NuevaEncuestaScreen y EncuestaProductoScreen para el
// selector de "Institución" en cuenta de ahorros/corriente).
//
// Antes, si el asesor abría la encuesta sin internet, esa lista
// llegaba vacía y solo quedaba el campo de texto manual ("no salen
// los bancos"). Con esta caché, la lista descargada la última vez
// que hubo conexión queda guardada en el celular y se usa como
// respaldo automático cuando falla la llamada al servidor — igual
// que EmpresaCacheService hace con los prospectos/empresas.
// ============================================================

import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:sqflite/sqflite.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';

class InstitucionesCacheService {
  static bool _refrescando = false;

  // Reutiliza la MISMA conexión/base que OfflineQueueService
  // ('super_ia_offline.db'), donde vive la tabla 'instituciones_cache'
  // (el esquema completo se define en OfflineQueueService para no abrir
  // el mismo archivo SQLite dos veces con configuraciones distintas).
  static Future<Database> get _db => OfflineQueueService.database;

  /// Descarga la lista de instituciones del servidor y reemplaza la caché
  /// local. Solo tiene sentido llamarla cuando hay internet (si falla por
  /// conectividad u otro error, simplemente no toca la caché existente).
  /// Devuelve la lista descargada, o null si no se pudo refrescar.
  static Future<List<String>?> refreshCache() async {
    if (_refrescando) return null;
    _refrescando = true;
    try {
      final cacheBuster = DateTime.now().millisecondsSinceEpoch;
      final resp = await http.get(
        Uri.parse('${Constants.apiBaseUrl}/api_cooperativas.php?_ts=$cacheBuster'),
        headers: {
          'ngrok-skip-browser-warning': 'true',
          'Cache-Control': 'no-cache',
          'Pragma': 'no-cache',
        },
      ).timeout(const Duration(seconds: 15));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) return null;
      final data = Map<String, dynamic>.from(decoded);

      List<String> inst = [];
      if (data['status'] == 'success' && data['data'] is List) {
        inst = (data['data'] as List)
            .map((e) => e is Map ? (e['nombre']?.toString() ?? '') : '')
            .map((s) => s.trim())
            .where((s) => s.isNotEmpty)
            .toSet()
            .toList();
      } else if (data['status'] == 'ok' && data['instituciones'] is List) {
        inst = (data['instituciones'] as List)
            .map((e) => e.toString().trim())
            .where((s) => s.isNotEmpty)
            .toSet()
            .toList();
      }
      if (inst.isEmpty) return null;
      inst.sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));

      await saveList(inst);
      return inst;
    } catch (_) {
      // Sin internet u otro error: se deja la caché existente intacta.
      return null;
    } finally {
      _refrescando = false;
    }
  }

  /// Reemplaza la caché local con la lista dada. Se usa tanto desde
  /// [refreshCache] como directamente desde las pantallas de encuesta
  /// cuando ya cargaron la lista fresca del servidor, para no tener que
  /// repetir la llamada HTTP.
  static Future<void> saveList(List<String> instituciones) async {
    if (instituciones.isEmpty) return;
    final db = await _db;
    final ahora = DateTime.now().toIso8601String();
    await db.transaction((txn) async {
      await txn.delete('instituciones_cache');
      final batch = txn.batch();
      for (final nombre in instituciones) {
        batch.insert(
          'instituciones_cache',
          {'nombre': nombre, 'actualizado_at': ahora},
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  /// Devuelve la copia guardada en el celular (vacía si nunca se pudo
  /// descargar, p. ej. primer uso del celular sin haber tenido internet).
  static Future<List<String>> getCached() async {
    final db = await _db;
    final rows = await db.query('instituciones_cache', orderBy: 'nombre ASC');
    return rows.map((r) => r['nombre'] as String).toList();
  }

  static Future<int> getCachedCount() async {
    final db = await _db;
    final result = await db.rawQuery('SELECT COUNT(*) as cnt FROM instituciones_cache');
    return Sqflite.firstIntValue(result) ?? 0;
  }
}

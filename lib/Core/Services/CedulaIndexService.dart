// ============================================================
// CedulaIndexService.dart
// ------------------------------------------------------------
// Índice local liviano (SQLite, tabla 'cedulas_index') de TODA la
// empresa (todos los asesores), a diferencia de ClienteCacheService que
// solo cubre la cartera propia con el detalle completo.
//
// Por qué existe: si un asesor busca sin internet una cédula que no es
// "suya" (la levantó otro asesor, o ya es cliente por otra vía),
// ClienteCacheService no la va a tener. Sin este índice, la app no
// tendría forma de avisarle que esa cédula ya existe en el sistema.
//
// Se mantiene liviano a propósito: solo guarda identidad + estado
// (¿existe? ¿es cliente? ¿tiene empresa? ¿de qué asesor?), NO el detalle
// pesado (RUC, régimen tributario, dirección, etc.), así que cubrir toda
// la empresa no vuelve pesado al celular. Igual que ClienteCacheService,
// la sincronización es incremental (delta por 'updated_at').
//
// Orden de búsqueda recomendado en NuevaEncuestaScreen cuando no hay
// internet:
//   1) ClienteCacheService.searchByCedula() → cartera propia, detalle
//      completo, se puede prellenar el formulario entero.
//   2) CedulaIndexService.searchByCedula() → resto de la empresa, solo
//      alcanza para avisar "ya existe" / "ya es cliente", sin poder
//      prellenar todo el detalle hasta que haya conexión.
// ============================================================

import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite/sqflite.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';

class CedulaIndexService {
  static bool _refrescando = false;

  static const String _kLastSync = 'cedula_index_last_sync';

  // Reutiliza la MISMA conexión/base que OfflineQueueService
  // ('super_ia_offline.db'), donde vive la tabla 'cedulas_index'.
  static Future<Database> get _db => OfflineQueueService.database;

  /// Descarga (o actualiza de forma incremental) el índice de TODA la
  /// empresa. Solo tiene sentido llamarla cuando hay internet; si falla
  /// por conectividad u otro motivo, no toca el índice existente.
  /// Devuelve cuántos registros llegaron en esta pasada (-1 si no se
  /// pudo refrescar).
  static Future<int> refreshCache({int limit = 2000}) async {
    if (_refrescando) return -1;
    _refrescando = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      final since = prefs.getString(_kLastSync) ?? '';

      final resp = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/sync_cedulas_index.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {'since': since, 'limit': '$limit'},
      ).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) return -1;
      final data = Map<String, dynamic>.from(decoded);
      if (data['status'] != 'success') return -1;

      final items = (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          [];

      String maxUpdatedAt = since;

      if (items.isNotEmpty) {
        final db = await _db;
        final ahora = DateTime.now().toIso8601String();

        await db.transaction((txn) async {
          final batch = txn.batch();
          for (final item in items) {
            final cedula = item['cedula']?.toString().trim() ?? '';
            if (cedula.isEmpty) continue;

            final updatedAt = item['updated_at']?.toString() ?? '';
            if (updatedAt.isNotEmpty && updatedAt.compareTo(maxUpdatedAt) > 0) {
              maxUpdatedAt = updatedAt;
            }

            batch.insert(
              'cedulas_index',
              {
                'cedula': cedula,
                'cliente_id': item['id']?.toString() ?? '',
                'nombre': item['nombre']?.toString() ?? '',
                'estado': item['estado_db']?.toString() ?? '',
                'es_cliente': _truthy(item['es_cliente']) ? 1 : 0,
                'tiene_empresa': _truthy(item['tiene_empresa']) ? 1 : 0,
                'nombre_empresa': item['nombre_empresa']?.toString() ?? '',
                'asesor_id': item['asesor_id']?.toString() ?? '',
                'updated_at': updatedAt,
                'actualizado_at': ahora,
              },
              conflictAlgorithm: ConflictAlgorithm.replace,
            );
          }
          await batch.commit(noResult: true);
        });
      }

      if (maxUpdatedAt.isNotEmpty) {
        await prefs.setString(_kLastSync, maxUpdatedAt);
      }

      return items.length;
    } catch (_) {
      // Sin internet u otro error: se deja el índice existente intacto.
      return -1;
    } finally {
      _refrescando = false;
    }
  }

  static bool _truthy(dynamic v) => v == 1 || v == '1' || v == true;

  /// Busca por cédula EXACTA en el índice liviano (cubre toda la
  /// empresa). Devuelve null si no está (puede ser una cédula nueva de
  /// verdad, o simplemente no haberse sincronizado todavía a este
  /// celular).
  ///
  /// A diferencia de ClienteCacheService.searchByCedula(), el mapa que
  /// devuelve NO trae el detalle completo (no hay dirección, RUC,
  /// régimen tributario, etc. en este índice) — solo alcanza para avisar
  /// que la cédula ya existe y si ya es cliente.
  static Future<Map<String, dynamic>?> searchByCedula(String cedula) async {
    final texto = cedula.trim();
    if (texto.isEmpty) return null;

    final db = await _db;
    final rows = await db.query(
      'cedulas_index',
      where: 'cedula = ?',
      whereArgs: [texto],
      limit: 1,
    );
    if (rows.isEmpty) return null;

    final row = rows.first;
    final esCliente = (row['es_cliente'] as int? ?? 0) == 1;
    final estado = (row['estado'] as String? ?? '').toLowerCase();
    final tipo = esCliente
        ? 'cliente'
        : (estado == 'descartado' ? 'descartado' : 'prospecto');

    return {
      'tipo': tipo,
      'nombre': (row['nombre'] as String? ?? ''),
      'cedula': texto,
      'tiene_empresa': (row['tiene_empresa'] as int? ?? 0),
      'nombre_empresa': (row['nombre_empresa'] as String? ?? ''),
      'asesor_id': (row['asesor_id'] as String? ?? ''),
    };
  }

  static Future<int> getCachedCount() async {
    final db = await _db;
    final result = await db.rawQuery('SELECT COUNT(*) as cnt FROM cedulas_index');
    return Sqflite.firstIntValue(result) ?? 0;
  }
}

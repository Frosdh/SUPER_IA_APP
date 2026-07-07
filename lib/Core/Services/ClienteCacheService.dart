// ============================================================
// ClienteCacheService.dart
// ------------------------------------------------------------
// Guarda en el celular (SQLite, tabla 'clientes_cache') una copia de
// los prospectos/clientes que le pertenecen al asesor logueado, para
// poder responder "¿esta cédula ya existe? ¿es cliente o prospecto?
// ¿ya tiene empresa registrada?" SIN internet en NuevaEncuestaScreen
// (ver _iniciarFlujoConCedula).
//
// Dos decisiones de diseño pensadas para que esto no vuelva lento al
// celular con el tiempo:
//
//  1) Solo se descarga la cartera del asesor (ver sync_clientes_cache.php,
//     filtra por asesor_id), no toda la base de clientes/prospectos.
//  2) Sincronización incremental ("delta"): se guarda la fecha del
//     último registro recibido (ver 'updated_at') y en cada refresco
//     solo se piden los que cambiaron desde entonces, en vez de volver
//     a bajar y reescribir toda la tabla cada vez que hay conexión.
//
// La búsqueda por cédula usa comparación exacta contra una columna
// indexada (ver idx_clientes_cache_cedula en OfflineQueueService), a
// diferencia de EmpresaCacheService que busca por texto parcial.
// ============================================================

import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite/sqflite.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Preferences/AuthPrefs.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';

class ClienteCacheService {
  static bool _refrescando = false;

  static const String _kLastSync = 'cliente_cache_last_sync';
  static const String _kAsesorId = 'cliente_cache_asesor_id';

  // Reutiliza la MISMA conexión/base que OfflineQueueService
  // ('super_ia_offline.db'), donde vive la tabla 'clientes_cache'.
  static Future<Database> get _db => OfflineQueueService.database;

  /// Descarga (o actualiza de forma incremental) la cartera del asesor
  /// logueado. Solo tiene sentido llamarla cuando hay internet; si falla
  /// por conectividad u otro motivo, simplemente no toca la caché
  /// existente. Devuelve cuántos registros llegaron en esta pasada
  /// (0 si ya estaba al día, -1 si no se pudo refrescar).
  static Future<int> refreshCache({int limit = 1000}) async {
    if (_refrescando) return -1;
    _refrescando = true;
    try {
      final asesorId = await AuthPrefs.getAsesorId();
      if (asesorId.isEmpty) return -1;

      final prefs = await SharedPreferences.getInstance();
      final asesorGuardado = prefs.getString(_kAsesorId) ?? '';
      String since = prefs.getString(_kLastSync) ?? '';

      final db = await _db;

      // Celular compartido entre asesores: si cambió quién está logueado,
      // se descarta la caché anterior (sería la cartera de otra persona)
      // y se vuelve a sincronizar completa para este asesor.
      if (asesorGuardado.isNotEmpty && asesorGuardado != asesorId) {
        await db.delete('clientes_cache');
        since = '';
      }

      final resp = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/sync_clientes_cache.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {'asesor_id': asesorId, 'since': since, 'limit': '$limit'},
      ).timeout(const Duration(seconds: 20));

      final decoded = json.decode(resp.body);
      if (decoded is! Map) return -1;
      final data = Map<String, dynamic>.from(decoded);
      if (data['status'] != 'success') return -1;

      final items = (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          [];

      final ahora = DateTime.now().toIso8601String();
      String maxUpdatedAt = since;

      if (items.isNotEmpty) {
        await db.transaction((txn) async {
          final batch = txn.batch();
          for (final item in items) {
            final id = item['id']?.toString() ?? '';
            if (id.isEmpty) continue;

            final updatedAt = item['updated_at']?.toString() ?? '';
            if (updatedAt.isNotEmpty && updatedAt.compareTo(maxUpdatedAt) > 0) {
              maxUpdatedAt = updatedAt;
            }

            batch.insert(
              'clientes_cache',
              {
                'cliente_id': id,
                'cedula': item['cedula']?.toString() ?? '',
                'nombre': item['nombre']?.toString() ?? '',
                'estado': item['estado_db']?.toString() ?? '',
                'es_cliente': _truthy(item['es_cliente']) ? 1 : 0,
                'tiene_empresa': _truthy(item['tiene_empresa']) ? 1 : 0,
                'nombre_empresa': item['nombre_empresa']?.toString() ?? '',
                'data_json': json.encode(item),
                'updated_at': updatedAt,
                'actualizado_at': ahora,
              },
              conflictAlgorithm: ConflictAlgorithm.replace,
            );
          }
          await batch.commit(noResult: true);
        });
      }

      // Guarda a quién pertenece esta caché y hasta dónde se sincronizó,
      // para que el próximo refresco solo pida lo nuevo (delta-sync).
      await prefs.setString(_kAsesorId, asesorId);
      if (maxUpdatedAt.isNotEmpty) {
        await prefs.setString(_kLastSync, maxUpdatedAt);
      }

      return items.length;
    } catch (_) {
      // Sin internet u otro error: se deja la caché existente intacta.
      return -1;
    } finally {
      _refrescando = false;
    }
  }

  static bool _truthy(dynamic v) => v == 1 || v == '1' || v == true;

  /// Busca en la copia local por cédula EXACTA (usa el índice
  /// idx_clientes_cache_cedula, no es un LIKE). Se usa como respaldo en
  /// NuevaEncuestaScreen cuando falla la consulta a
  /// buscar_prospecto_por_cedula.php por falta de conexión.
  ///
  /// Devuelve null si la cédula no está en la caché local (puede ser que
  /// no exista de verdad, o que exista en el servidor pero este celular
  /// no la haya sincronizado todavía; en ambos casos se sigue el mismo
  /// camino que hoy: se continúa como prospecto nuevo y se resuelve al
  /// sincronizar, gracias a la deduplicación por cédula que ya hace
  /// guardar_cliente_encuesta.php).
  ///
  /// Si hay coincidencia, devuelve un mapa con la MISMA forma que la
  /// respuesta 'found' de buscar_prospecto_por_cedula.php
  /// ({'data': {...}, 'tipo': 'cliente'|'prospecto'|'descartado'}) para
  /// poder reusar _aplicarDatosProspectoEncontrado() sin cambios.
  static Future<Map<String, dynamic>?> searchByCedula(String cedula) async {
    final texto = cedula.trim();
    if (texto.isEmpty) return null;

    final db = await _db;
    final rows = await db.query(
      'clientes_cache',
      where: 'cedula = ?',
      whereArgs: [texto],
      limit: 1,
    );
    if (rows.isEmpty) return null;

    final row = rows.first;
    try {
      final data = Map<String, dynamic>.from(
        json.decode(row['data_json'] as String) as Map,
      );
      final esCliente = (row['es_cliente'] as int? ?? 0) == 1;
      final estado = (row['estado'] as String? ?? '').toLowerCase();
      final tipo = esCliente
          ? 'cliente'
          : (estado == 'descartado' ? 'descartado' : 'prospecto');
      return {'data': data, 'tipo': tipo};
    } catch (_) {
      return null;
    }
  }

  static Future<int> getCachedCount() async {
    final db = await _db;
    final result = await db.rawQuery('SELECT COUNT(*) as cnt FROM clientes_cache');
    return Sqflite.firstIntValue(result) ?? 0;
  }
}

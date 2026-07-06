// ============================================================
// EmpresaCacheService.dart
// ------------------------------------------------------------
// Guarda en el celular (SQLite) una copia de la lista de
// clientes/prospectos con empresa (la misma que usa
// LevantarEmpresaScreen para buscar por nombre de empresa).
// Se refresca solo cuando hay internet (ver SyncService /
// main.dart) y permite buscar localmente cuando no hay
// conexión, para que el asesor pueda abrir el levantamiento de
// una empresa aunque esté sin señal.
// ============================================================

import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:sqflite/sqflite.dart';
import 'package:super_ia/Core/Constants/Constants.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';

class EmpresaCacheService {
  static bool _refrescando = false;

  // Reutiliza la MISMA conexión/base que OfflineQueueService
  // ('super_ia_offline.db'), donde ya vive la tabla 'empresas_cache'
  // (el esquema completo se define en OfflineQueueService para no abrir
  // el mismo archivo SQLite dos veces con configuraciones distintas).
  static Future<Database> get _db => OfflineQueueService.database;

  /// Descarga la lista de clientes/empresas del servidor y reemplaza la
  /// caché local. Solo tiene sentido llamarla cuando hay internet (si
  /// falla por conectividad, simplemente no toca la caché existente).
  /// Devuelve cuántos registros quedaron guardados, o -1 si no se pudo
  /// refrescar (sin cambiar lo que ya había guardado).
  static Future<int> refreshCache({int limit = 500}) async {
    if (_refrescando) return -1;
    _refrescando = true;
    try {
      final resp = await http.post(
        Uri.parse('${Constants.apiBaseUrl}/buscar_cliente_por_empresa.php'),
        headers: {'ngrok-skip-browser-warning': 'true'},
        body: {'nombre_empresa': '', 'limit': '$limit'},
      ).timeout(const Duration(seconds: 20));

      final data = json.decode(resp.body) as Map<String, dynamic>;
      if (data['status'] != 'success') return -1;

      final items = (data['items'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          [];

      final db = await _db;
      final ahora = DateTime.now().toIso8601String();

      await db.transaction((txn) async {
        await txn.delete('empresas_cache');
        final batch = txn.batch();
        for (final item in items) {
          final id = item['id']?.toString() ?? '';
          if (id.isEmpty) continue;
          batch.insert(
            'empresas_cache',
            {
              'cliente_id': id,
              'nombre': item['nombre']?.toString() ?? '',
              'cedula': item['cedula']?.toString() ?? '',
              'nombre_empresa': item['nombre_empresa']?.toString() ?? '',
              'ciudad': item['ciudad']?.toString() ?? '',
              'data_json': json.encode(item),
              'actualizado_at': ahora,
            },
            conflictAlgorithm: ConflictAlgorithm.replace,
          );
        }
        await batch.commit(noResult: true);
      });

      return items.length;
    } catch (_) {
      // Sin internet u otro error: se deja la caché existente intacta.
      return -1;
    } finally {
      _refrescando = false;
    }
  }

  /// Busca en la copia local por nombre de empresa, nombre del contacto o
  /// cédula. Se usa como respaldo cuando LevantarEmpresaScreen no logra
  /// conectarse al servidor.
  static Future<List<Map<String, dynamic>>> searchLocal(String texto) async {
    final db = await _db;
    final q = '%${texto.trim()}%';
    final rows = await db.query(
      'empresas_cache',
      where: 'nombre_empresa LIKE ? OR nombre LIKE ? OR cedula LIKE ?',
      whereArgs: [q, q, q],
      orderBy: 'nombre_empresa ASC',
      limit: 100,
    );
    return rows.map((r) {
      try {
        return Map<String, dynamic>.from(json.decode(r['data_json'] as String) as Map);
      } catch (_) {
        return <String, dynamic>{
          'id': r['cliente_id'],
          'nombre': r['nombre'],
          'cedula': r['cedula'],
          'nombre_empresa': r['nombre_empresa'],
          'ciudad': r['ciudad'],
        };
      }
    }).toList();
  }

  static Future<int> getCachedCount() async {
    final db = await _db;
    final result = await db.rawQuery('SELECT COUNT(*) as cnt FROM empresas_cache');
    return Sqflite.firstIntValue(result) ?? 0;
  }

  /// Fecha del último refresco exitoso (o null si nunca se ha podido
  /// descargar la lista, p. ej. primer uso del celular sin haber tenido
  /// internet todavía).
  static Future<DateTime?> getLastUpdated() async {
    final db = await _db;
    final result = await db.rawQuery(
      'SELECT actualizado_at FROM empresas_cache ORDER BY actualizado_at DESC LIMIT 1',
    );
    if (result.isEmpty) return null;
    final raw = result.first['actualizado_at'] as String?;
    if (raw == null) return null;
    return DateTime.tryParse(raw);
  }
}

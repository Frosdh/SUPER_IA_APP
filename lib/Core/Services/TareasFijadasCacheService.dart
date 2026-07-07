// ============================================================
// TareasFijadasCacheService.dart
// ------------------------------------------------------------
// Guarda en el celular (SQLite, tabla 'tareas_fijadas_cache') una copia
// de las tareas que el asesor YA fijó para hoy en PendientesTareasScreen
// (botón "Fijar tareas": una vez fijadas, ya no se pueden quitar de la
// lista del día). A propósito solo se cachean las FIJADAS, no todas las
// tareas pendientes/futuras: el objetivo es puntual — que la ruta del
// día que el asesor ya se comprometió a hacer siga disponible aunque
// pierda señal en la calle, no duplicar toda la agenda offline.
//
// Se refresca cada vez que PendientesTareasScreen carga la lista con
// internet (ver _cargar() en ese archivo): se reemplaza por completo con
// las fijadas más recientes, así la copia local nunca queda más
// "adelantada" que el servidor.
//
// Al guardar el registro completo tal cual lo entrega
// obtener_tareas_pendientes_asesor.php (incluye cliente_latitud/longitud,
// dirección, cédula, teléfono), esta caché también resuelve:
//   - Que el botón "Ruta" siga funcionando sin internet (ya usa los
//     datos que están en memoria, no hace una llamada aparte).
//   - Que al abrir "Ir a la actividad" sin conexión, NuevaEncuestaScreen
//     pueda prellenar al menos los datos básicos del cliente en vez de
//     mostrar el formulario vacío (ver _cargarEncuestaEnEdicion allá).
// ============================================================

import 'dart:convert';

import 'package:sqflite/sqflite.dart';
import 'package:super_ia/Core/Services/OfflineQueueService.dart';

class TareasFijadasCacheService {
  static Future<Database> get _db => OfflineQueueService.database;

  /// Reemplaza la copia local por la lista de tareas fijadas recibida
  /// (normalmente el subconjunto "fijadasHoy" que ya calcula
  /// PendientesTareasScreen). Solo tiene sentido llamarla justo después
  /// de una carga exitosa desde el servidor.
  static Future<void> saveFijadas(List<Map<String, dynamic>> fijadas) async {
    final db = await _db;
    final ahora = DateTime.now().toIso8601String();

    await db.transaction((txn) async {
      await txn.delete('tareas_fijadas_cache');
      final batch = txn.batch();
      for (final t in fijadas) {
        final id = t['id']?.toString() ?? '';
        if (id.isEmpty) continue;
        batch.insert(
          'tareas_fijadas_cache',
          {
            'tarea_id': id,
            'tipo_tarea': t['tipo_tarea']?.toString() ?? '',
            'cliente_nombre': t['cliente_nombre']?.toString() ?? '',
            'data_json': json.encode(t),
            'actualizado_at': ahora,
          },
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
      await batch.commit(noResult: true);
    });
  }

  /// Todas las tareas fijadas guardadas localmente (misma forma que las
  /// devuelve obtener_tareas_pendientes_asesor.php), para mostrarlas en
  /// PendientesTareasScreen cuando no hay internet.
  static Future<List<Map<String, dynamic>>> getFijadas() async {
    final db = await _db;
    final rows = await db.query('tareas_fijadas_cache', orderBy: 'actualizado_at DESC');
    return rows.map((r) {
      try {
        return Map<String, dynamic>.from(json.decode(r['data_json'] as String) as Map);
      } catch (_) {
        return <String, dynamic>{};
      }
    }).where((m) => m.isNotEmpty).toList();
  }

  /// Busca una tarea fijada por id (para prellenar NuevaEncuestaScreen en
  /// modo edición cuando obtener_encuesta_completa.php no responde por
  /// falta de conexión).
  static Future<Map<String, dynamic>?> getById(String tareaId) async {
    if (tareaId.trim().isEmpty) return null;
    final db = await _db;
    final rows = await db.query(
      'tareas_fijadas_cache',
      where: 'tarea_id = ?',
      whereArgs: [tareaId.trim()],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    try {
      return Map<String, dynamic>.from(json.decode(rows.first['data_json'] as String) as Map);
    } catch (_) {
      return null;
    }
  }

  static Future<int> getCachedCount() async {
    final db = await _db;
    final result = await db.rawQuery('SELECT COUNT(*) as cnt FROM tareas_fijadas_cache');
    return Sqflite.firstIntValue(result) ?? 0;
  }
}

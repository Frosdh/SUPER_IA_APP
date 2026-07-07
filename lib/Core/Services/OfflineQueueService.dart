// ============================================================
// OfflineQueueService.dart
// ------------------------------------------------------------
// Cola local (SQLite) para las encuestas de "Levantar Empresa" y
// las tareas agendadas (paso "Acuerdo Logrado") de
// NuevaEncuestaScreen. Cuando el asesor guarda una encuesta sin
// conexión a internet, el envío completo (endpoint + body + GPS)
// se guarda en el celular y queda "pendiente". SyncService se
// encarga de reintentar el envío automáticamente en cuanto vuelve
// la conexión, sin que el asesor tenga que hacer nada más.
// ============================================================

import 'dart:convert';
import 'dart:math';

import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart';

class OfflineQueueService {
  static Database? _db;

  static Future<Database> get database async {
    if (_db != null) return _db!;
    _db = await _initDB();
    return _db!;
  }

  // NOTA: 'super_ia_offline.db' es compartida con EmpresaCacheService (tabla
  // 'empresas_cache'), InstitucionesCacheService (tabla
  // 'instituciones_cache'), ClienteCacheService (tabla 'clientes_cache'),
  // CedulaIndexService (tabla 'cedulas_index') y
  // TareasFijadasCacheService (tabla 'tareas_fijadas_cache'). Todo el
  // esquema (creación y migraciones) vive en un solo lugar (aquí) para
  // evitar que varios servicios abran el mismo archivo con versiones
  // distintas, lo que puede romper la apertura de la BD.
  static const int _dbVersion = 6;

  static Future<Database> _initDB() async {
    final dbPath = await getDatabasesPath();
    final path = join(dbPath, 'super_ia_offline.db');

    return await openDatabase(
      path,
      version: _dbVersion,
      onCreate: (db, version) async {
        await _crearTablaEncuestas(db);
        await _crearTablaEmpresas(db);
        await _crearTablaInstituciones(db);
        await _crearTablaClientes(db);
        await _crearTablaCedulasIndex(db);
        await _crearTablaTareasFijadas(db);
      },
      onUpgrade: (db, oldVersion, newVersion) async {
        if (oldVersion < 2) {
          await _crearTablaEmpresas(db);
        }
        if (oldVersion < 3) {
          await _crearTablaInstituciones(db);
        }
        if (oldVersion < 4) {
          await _crearTablaClientes(db);
        }
        if (oldVersion < 5) {
          await _crearTablaCedulasIndex(db);
        }
        if (oldVersion < 6) {
          await _crearTablaTareasFijadas(db);
        }
      },
    );
  }

  static Future<void> _crearTablaEncuestas(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS encuestas_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_uuid TEXT UNIQUE NOT NULL,
        endpoint TEXT NOT NULL,
        tipo_tarea TEXT,
        body_json TEXT NOT NULL,
        latitud REAL,
        longitud REAL,
        estado TEXT NOT NULL DEFAULT 'pendiente',
        intentos INTEGER NOT NULL DEFAULT 0,
        ultimo_error TEXT,
        creado_at TEXT NOT NULL,
        sincronizado_at TEXT
      )
    ''');
  }

  static Future<void> _crearTablaEmpresas(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS empresas_cache (
        cliente_id TEXT PRIMARY KEY,
        nombre TEXT,
        cedula TEXT,
        nombre_empresa TEXT,
        ciudad TEXT,
        data_json TEXT NOT NULL,
        actualizado_at TEXT NOT NULL
      )
    ''');
  }

  static Future<void> _crearTablaInstituciones(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS instituciones_cache (
        nombre TEXT PRIMARY KEY,
        actualizado_at TEXT NOT NULL
      )
    ''');
  }

  // NOTA: tabla usada por ClienteCacheService para poder verificar una
  // cédula (¿ya existe como prospecto/cliente? ¿tiene empresa?) sin
  // internet, en NuevaEncuestaScreen. Solo guarda la cartera del asesor
  // logueado (ver sync_clientes_cache.php), no toda la base, para que la
  // tabla se mantenga chica y las búsquedas rápidas en el celular.
  // 'cedula' tiene índice porque la búsqueda offline es por cédula exacta
  // (no LIKE), a diferencia de 'empresas_cache' que busca por texto parcial.
  static Future<void> _crearTablaClientes(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS clientes_cache (
        cliente_id TEXT PRIMARY KEY,
        cedula TEXT NOT NULL,
        nombre TEXT,
        estado TEXT,
        es_cliente INTEGER NOT NULL DEFAULT 0,
        tiene_empresa INTEGER NOT NULL DEFAULT 0,
        nombre_empresa TEXT,
        data_json TEXT NOT NULL,
        updated_at TEXT,
        actualizado_at TEXT NOT NULL
      )
    ''');
    await db.execute(
      'CREATE INDEX IF NOT EXISTS idx_clientes_cache_cedula ON clientes_cache(cedula)',
    );
  }

  // NOTA: a diferencia de 'clientes_cache' (que solo guarda la cartera del
  // asesor logueado, con TODO el detalle), esta tabla cubre TODA la
  // empresa (todos los asesores) pero solo con los campos mínimos para
  // responder "¿esta cédula ya existe? ¿es cliente? ¿tiene empresa?".
  // Existe porque, sin esto, un asesor que busca sin internet una cédula
  // que no es "suya" (la levantó otro asesor, o ya es cliente de la
  // empresa por otra vía) no tendría cómo saberlo: 'clientes_cache' solo
  // conoce su propia cartera. Al no guardar el detalle pesado (RUC,
  // régimen tributario, dirección, etc.) por fila, cubrir a toda la
  // empresa sigue siendo liviano. cedula ya es PRIMARY KEY, así que SQLite
  // ya la indexa automáticamente sin necesidad de un CREATE INDEX aparte.
  static Future<void> _crearTablaCedulasIndex(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS cedulas_index (
        cedula TEXT PRIMARY KEY,
        cliente_id TEXT,
        nombre TEXT,
        estado TEXT,
        es_cliente INTEGER NOT NULL DEFAULT 0,
        tiene_empresa INTEGER NOT NULL DEFAULT 0,
        nombre_empresa TEXT,
        asesor_id TEXT,
        updated_at TEXT,
        actualizado_at TEXT NOT NULL
      )
    ''');
  }

  // NOTA: tabla usada por TareasFijadasCacheService. Cuando el asesor abre
  // "Lista tareas" con internet, se guarda aquí una copia de las tareas que
  // YA fijó para hoy (seleccion_fijada=1, estado en_proceso) — a propósito
  // NO se guardan todas las tareas pendientes/futuras, solo las fijadas:
  // esas son las que el asesor ya se comprometió a hacer hoy y para las que
  // necesita poder trabajar aunque se quede sin señal en la calle. Guarda
  // el registro completo (incluye cliente_latitud/longitud) para que tanto
  // la lista de "Tareas fijadas de hoy" como el botón "Ruta" y el prellenado
  // básico al abrir la actividad sigan funcionando sin conexión.
  static Future<void> _crearTablaTareasFijadas(Database db) async {
    await db.execute('''
      CREATE TABLE IF NOT EXISTS tareas_fijadas_cache (
        tarea_id TEXT PRIMARY KEY,
        tipo_tarea TEXT,
        cliente_nombre TEXT,
        data_json TEXT NOT NULL,
        actualizado_at TEXT NOT NULL
      )
    ''');
  }

  /// Genera un UUID v4 sin depender de paquetes externos.
  static String generateUuid() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0F) | 0x40;
    bytes[8] = (bytes[8] & 0x3F) | 0x80;
    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-'
        '${hex.substring(16, 20)}-${hex.substring(20, 32)}';
  }

  /// Guarda una encuesta/tarea pendiente de subir. [body] debe incluir ya
  /// la clave 'client_uuid' (para que el backend pueda deduplicar cuando
  /// se reintente el envío).
  static Future<int> saveEncuesta({
    required String clientUuid,
    required String endpoint,
    required Map<String, String> body,
    String? tipoTarea,
    double? latitud,
    double? longitud,
  }) async {
    final db = await database;
    return await db.insert(
      'encuestas_pendientes',
      {
        'client_uuid': clientUuid,
        'endpoint': endpoint,
        'tipo_tarea': tipoTarea ?? '',
        'body_json': json.encode(body),
        'latitud': latitud,
        'longitud': longitud,
        'estado': 'pendiente',
        'intentos': 0,
        'creado_at': DateTime.now().toIso8601String(),
      },
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  static Future<List<Map<String, dynamic>>> getPendientes() async {
    final db = await database;
    final rows = await db.query(
      'encuestas_pendientes',
      where: "estado = 'pendiente'",
      orderBy: 'creado_at ASC',
    );
    return rows.map(_withDecodedBody).toList();
  }

  static Future<int> getPendingCount() async {
    final db = await database;
    final result = await db.rawQuery(
      "SELECT COUNT(*) as cnt FROM encuestas_pendientes WHERE estado = 'pendiente'",
    );
    return Sqflite.firstIntValue(result) ?? 0;
  }

  static Future<void> markSynced(String clientUuid) async {
    final db = await database;
    await db.update(
      'encuestas_pendientes',
      {
        'estado': 'sincronizado',
        'sincronizado_at': DateTime.now().toIso8601String(),
      },
      where: 'client_uuid = ?',
      whereArgs: [clientUuid],
    );
  }

  static Future<void> incrementIntentos(String clientUuid, {String? error}) async {
    final db = await database;
    await db.rawUpdate(
      'UPDATE encuestas_pendientes SET intentos = intentos + 1, ultimo_error = ? WHERE client_uuid = ?',
      [error ?? '', clientUuid],
    );
  }

  static Future<void> markError(String clientUuid, String mensaje) async {
    final db = await database;
    await db.update(
      'encuestas_pendientes',
      {'estado': 'error', 'ultimo_error': mensaje},
      where: 'client_uuid = ?',
      whereArgs: [clientUuid],
    );
  }

  static Future<List<Map<String, dynamic>>> getAll() async {
    final db = await database;
    final rows = await db.query('encuestas_pendientes', orderBy: 'creado_at DESC');
    return rows.map(_withDecodedBody).toList();
  }

  static Future<void> deleteById(int id) async {
    final db = await database;
    await db.delete('encuestas_pendientes', where: 'id = ?', whereArgs: [id]);
  }

  static Future<void> clearSynced() async {
    final db = await database;
    await db.delete('encuestas_pendientes', where: "estado = 'sincronizado'");
  }

  static Map<String, dynamic> _withDecodedBody(Map<String, dynamic> row) {
    final copy = Map<String, dynamic>.from(row);
    try {
      copy['body'] = Map<String, String>.from(
        (json.decode(copy['body_json'] as String) as Map)
            .map((k, v) => MapEntry(k.toString(), v?.toString() ?? '')),
      );
    } catch (_) {
      copy['body'] = <String, String>{};
    }
    return copy;
  }
}

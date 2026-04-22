<?php
// ============================================================
// obtener_tareas_pendientes_asesor.php
// Lista tareas pendientes/programadas de un asesor (mobile)
// ============================================================

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario_id   = trim($_POST['usuario_id'] ?? '');
$asesor_id_in = trim($_POST['asesor_id'] ?? '');
$desde        = trim($_POST['desde'] ?? '');

if ($usuario_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($desde === '') {
    $desde = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    echo json_encode(['status' => 'error', 'message' => 'desde invalido (YYYY-MM-DD)'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Resolver asesor_id desde usuario_id (y validar asesor_id si viene)
    $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->bind_param('s', $usuario_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Asesor no encontrado para este usuario'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $asesor_id = (string)$row['id'];
    if ($asesor_id_in !== '' && $asesor_id_in !== $asesor_id) {
        echo json_encode(['status' => 'error', 'message' => 'asesor_id no coincide con la sesion'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Asegurar columnas para selección diaria / fijado (migración no destructiva)
    foreach ([
        'estado_seleccion_prev' => "ADD COLUMN estado_seleccion_prev VARCHAR(20) DEFAULT NULL AFTER estado",
        'seleccionada_dia'      => "ADD COLUMN seleccionada_dia DATE DEFAULT NULL AFTER estado_seleccion_prev",
        'seleccionada_at'       => "ADD COLUMN seleccionada_at DATETIME DEFAULT NULL AFTER seleccionada_dia",
        'seleccion_fijada'      => "ADD COLUMN seleccion_fijada TINYINT(1) NOT NULL DEFAULT 0 AFTER seleccionada_at",
        'seleccion_fijada_at'   => "ADD COLUMN seleccion_fijada_at DATETIME DEFAULT NULL AFTER seleccion_fijada",
    ] as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM tarea LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE tarea $ddl");
        }
    }

    // Regla 8 horas: lo que quedó 'en_proceso' pasa a 'pendiente' para el día siguiente
    // (limpia selección y desbloquea)
    $stExp = $conn->prepare(
        "UPDATE tarea
         SET estado='pendiente',
             fecha_programada = DATE_ADD(DATE(seleccionada_at), INTERVAL 1 DAY),
             estado_seleccion_prev = NULL,
             seleccionada_dia = NULL,
             seleccionada_at  = NULL,
             seleccion_fijada = 0,
             seleccion_fijada_at = NULL
         WHERE asesor_id = ?
           AND estado = 'en_proceso'
           AND seleccionada_at IS NOT NULL
           AND seleccionada_at < (NOW() - INTERVAL 8 HOUR)"
    );
    if ($stExp) {
        $stExp->bind_param('s', $asesor_id);
        $stExp->execute();
        $stExp->close();
    }

    $sql = "
        SELECT
            t.id,
            t.tipo_tarea,
            t.estado,
            t.fecha_programada,
            t.hora_programada,
            t.fecha_realizada,
            t.hora_realizada,
            t.observaciones,
            t.seleccionada_dia,
            t.seleccionada_at,
            t.seleccion_fijada,
            t.seleccion_fijada_at,
            t.created_at,
            cp.id        AS cliente_id,
            cp.nombre    AS cliente_nombre,
            cp.ciudad    AS cliente_ciudad,
            cp.direccion AS cliente_direccion,
            cp.latitud   AS cliente_latitud,
            cp.longitud  AS cliente_longitud
        FROM tarea t
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE t.asesor_id = ?
          AND (
            (t.estado IN ('programada','pendiente','postergada','en_proceso') AND (t.fecha_programada IS NULL OR t.fecha_programada = '0000-00-00' OR t.fecha_programada >= ?))
            OR
            (t.estado = 'completada' AND t.fecha_realizada >= ?)
          )
        ORDER BY
          CASE WHEN t.estado = 'completada' THEN t.fecha_realizada ELSE t.fecha_programada END ASC,
          CASE WHEN t.estado = 'completada' THEN t.hora_realizada  ELSE t.hora_programada  END ASC,
          t.created_at DESC
        LIMIT 200
    ";

    $st = $conn->prepare($sql);
    $st->bind_param('sss', $asesor_id, $desde, $desde);
    $st->execute();
    $res = $st->get_result();

    $tareas = [];
    while ($r = $res->fetch_assoc()) {
        $tareas[] = [
            'id' => (string)($r['id'] ?? ''),
            'tipo_tarea' => (string)($r['tipo_tarea'] ?? ''),
            'estado' => (string)($r['estado'] ?? ''),
            'fecha_programada' => (string)($r['fecha_programada'] ?? ''),
            'hora_programada' => (string)($r['hora_programada'] ?? ''),
            'fecha_realizada' => (string)($r['fecha_realizada'] ?? ''),
            'hora_realizada' => (string)($r['hora_realizada'] ?? ''),
            'observaciones' => (string)($r['observaciones'] ?? ''),
            'seleccionada_dia' => (string)($r['seleccionada_dia'] ?? ''),
            'seleccionada_at'  => (string)($r['seleccionada_at'] ?? ''),
            'seleccion_fijada' => (string)($r['seleccion_fijada'] ?? '0'),
            'seleccion_fijada_at' => (string)($r['seleccion_fijada_at'] ?? ''),
            'cliente_id' => (string)($r['cliente_id'] ?? ''),
            'cliente_nombre' => (string)($r['cliente_nombre'] ?? ''),
            'cliente_ciudad' => (string)($r['cliente_ciudad'] ?? ''),
            'cliente_direccion' => (string)($r['cliente_direccion'] ?? ''),
            'cliente_latitud' => (string)($r['cliente_latitud'] ?? ''),
            'cliente_longitud' => (string)($r['cliente_longitud'] ?? ''),
        ];
    }
    $st->close();

    echo json_encode([
        'status' => 'success',
        'asesor_id' => $asesor_id,
        'desde' => $desde,
        'tareas' => $tareas,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[obtener_tareas_pendientes_asesor] ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor',
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        try { $conn->close(); } catch (Throwable $_) {}
    }
}

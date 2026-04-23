<?php
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

if ($desde === '') $desde = date('Y-m-d');

try {
    // Resolver asesor_id desde usuario_id
    $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->bind_param('s', $usuario_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || empty($row['id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Asesor no encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $asesor_id = (string)$row['id'];
    if ($asesor_id_in !== '' && $asesor_id_in !== $asesor_id) {
        echo json_encode(['status' => 'error', 'message' => 'asesor_id no coincide'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Regla 8h: tareas en_proceso viejas vuelven a programada
    $stExp = $conn->prepare(
        "UPDATE tarea
         SET estado = 'programada',
             fecha_programada = DATE_ADD(DATE(seleccionada_at), INTERVAL 1 DAY),
             seleccionada_dia = NULL, seleccionada_at = NULL,
             seleccion_fijada = 0, seleccion_fijada_at = NULL
         WHERE asesor_id = ? AND tipo_tarea = 'recuperacion'
           AND estado = 'en_proceso' AND seleccionada_at IS NOT NULL
           AND seleccionada_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)"
    );
    if ($stExp) { $stExp->bind_param('s', $asesor_id); $stExp->execute(); $stExp->close(); }

    $sql = "
        SELECT
            t.id, t.tipo_tarea, t.estado,
            t.fecha_programada, t.hora_programada,
            t.fecha_realizada,  t.hora_realizada,
            t.observaciones,
            t.seleccionada_dia, t.seleccionada_at,
            t.seleccion_fijada, t.seleccion_fijada_at,
            t.created_at, t.asesor_id,
            cp.id        AS cliente_id,
            cp.nombre    AS cliente_nombre,
            COALESCE(cp.cedula,   '') AS cliente_cedula,
            COALESCE(cp.telefono, '') AS cliente_telefono,
            COALESCE(cp.ciudad,   '') AS cliente_ciudad,
            COALESCE(cp.direccion,'') AS cliente_direccion,
            COALESCE(cp.latitud,  '') AS cliente_latitud,
            COALESCE(cp.longitud, '') AS cliente_longitud
        FROM tarea t
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE t.tipo_tarea = 'recuperacion'
          AND t.asesor_id  = ?
          AND (
               t.estado IN ('programada','postergada','en_proceso')
            OR (t.estado = 'completada' AND t.fecha_realizada >= ?)
          )
        ORDER BY
            CASE WHEN t.estado='completada' THEN t.fecha_realizada ELSE t.fecha_programada END ASC,
            t.created_at DESC
        LIMIT 300
    ";

    $st = $conn->prepare($sql);
    $st->bind_param('ss', $asesor_id, $desde);
    $st->execute();
    $res = $st->get_result();

    $tareas = [];
    while ($r = $res->fetch_assoc()) {
        $tareas[] = [
            'id'                  => (string)($r['id'] ?? ''),
            'tipo_tarea'          => (string)($r['tipo_tarea'] ?? ''),
            'estado'              => (string)($r['estado'] ?? ''),
            'fecha_programada'    => (string)($r['fecha_programada'] ?? ''),
            'hora_programada'     => (string)($r['hora_programada'] ?? ''),
            'fecha_realizada'     => (string)($r['fecha_realizada'] ?? ''),
            'hora_realizada'      => (string)($r['hora_realizada'] ?? ''),
            'observaciones'       => (string)($r['observaciones'] ?? ''),
            'seleccionada_dia'    => (string)($r['seleccionada_dia'] ?? ''),
            'seleccionada_at'     => (string)($r['seleccionada_at'] ?? ''),
            'seleccion_fijada'    => (string)($r['seleccion_fijada'] ?? '0'),
            'seleccion_fijada_at' => (string)($r['seleccion_fijada_at'] ?? ''),
            'es_pool'             => '0',
            'cliente_id'          => (string)($r['cliente_id'] ?? ''),
            'cliente_nombre'      => (string)($r['cliente_nombre'] ?? ''),
            'cliente_cedula'      => (string)($r['cliente_cedula'] ?? ''),
            'cliente_telefono'    => (string)($r['cliente_telefono'] ?? ''),
            'cliente_ciudad'      => (string)($r['cliente_ciudad'] ?? ''),
            'cliente_direccion'   => (string)($r['cliente_direccion'] ?? ''),
            'cliente_latitud'     => (string)($r['cliente_latitud'] ?? ''),
            'cliente_longitud'    => (string)($r['cliente_longitud'] ?? ''),
        ];
    }
    $st->close();

    echo json_encode(['status' => 'success', 'asesor_id' => $asesor_id, 'tareas' => $tareas], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[recuperacion_asesor] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) { try { $conn->close(); } catch (Throwable $_) {} }
    
}

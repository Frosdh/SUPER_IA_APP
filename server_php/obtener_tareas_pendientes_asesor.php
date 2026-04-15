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

    $sql = "
        SELECT
            t.id,
            t.tipo_tarea,
            t.estado,
            t.fecha_programada,
            t.hora_programada,
            t.observaciones,
            t.created_at,
            cp.id     AS cliente_id,
            cp.nombre AS cliente_nombre,
            cp.ciudad AS cliente_ciudad
        FROM tarea t
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE t.asesor_id = ?
          AND t.estado IN ('programada','pendiente','postergada','en_proceso')
          AND t.fecha_programada >= ?
        ORDER BY t.fecha_programada ASC,
                 t.hora_programada ASC,
                 t.created_at DESC
        LIMIT 200
    ";

    $st = $conn->prepare($sql);
    $st->bind_param('ss', $asesor_id, $desde);
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
            'observaciones' => (string)($r['observaciones'] ?? ''),
            'cliente_id' => (string)($r['cliente_id'] ?? ''),
            'cliente_nombre' => (string)($r['cliente_nombre'] ?? ''),
            'cliente_ciudad' => (string)($r['cliente_ciudad'] ?? ''),
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

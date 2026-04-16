<?php
// ============================================================
// fijar_tareas_hoy.php
// Fija (confirma) la selección diaria del asesor.
// Una vez fijada, NO se permite deseleccionar desde mobile.
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

if ($usuario_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id requerido'], JSON_UNESCAPED_UNICODE);
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

    $stFix = $conn->prepare(
        "UPDATE tarea
         SET seleccion_fijada = 1,
             seleccion_fijada_at = NOW()
         WHERE asesor_id = ?
           AND estado = 'en_proceso'
           AND seleccionada_dia = CURDATE()
           AND seleccion_fijada = 0"
    );
    $stFix->bind_param('s', $asesor_id);
    $ok = $stFix->execute();
    $affected = $stFix->affected_rows;
    $stFix->close();

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Selección fijada' : 'No se pudo fijar la selección',
        'cantidad' => (int)$affected,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[fijar_tareas_hoy] ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor',
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        try { $conn->close(); } catch (Throwable $_) {}
    }
}

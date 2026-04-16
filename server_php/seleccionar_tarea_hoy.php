<?php
// ============================================================
// seleccionar_tarea_hoy.php
// Marca una tarea como seleccionada para hoy (mobile)
// - Cambia estado a 'en_proceso'
// - Guarda timestamps de selección
// - Aplica regla 8 horas: si una tarea queda 'en_proceso' > 8h, pasa a 'pendiente' para el día siguiente
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
$tarea_id     = trim($_POST['tarea_id'] ?? '');
$accion       = trim($_POST['accion'] ?? 'seleccionar'); // seleccionar | deseleccionar

if ($usuario_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($tarea_id === '' || strlen($tarea_id) > 64) {
    echo json_encode(['status' => 'error', 'message' => 'tarea_id invalido'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($accion, ['seleccionar', 'deseleccionar'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'accion invalida'], JSON_UNESCAPED_UNICODE);
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

    // Verificar tarea pertenece al asesor
    $stChk = $conn->prepare(
        "SELECT estado, fecha_programada, seleccionada_dia, seleccion_fijada, estado_seleccion_prev
         FROM tarea
         WHERE id = ? AND asesor_id = ?
         LIMIT 1"
    );
    $stChk->bind_param('ss', $tarea_id, $asesor_id);
    $stChk->execute();
    $r = $stChk->get_result()->fetch_assoc();
    $stChk->close();

    if (!$r) {
        echo json_encode(['status' => 'error', 'message' => 'Tarea no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $estado     = (string)($r['estado'] ?? '');
    $fecha_prog = (string)($r['fecha_programada'] ?? '');
    $sel_dia    = (string)($r['seleccionada_dia'] ?? '');
    $fijada     = (int)($r['seleccion_fijada'] ?? 0);
    $prev       = (string)($r['estado_seleccion_prev'] ?? '');

    if ($accion === 'seleccionar') {
        if ($estado === 'completada' || $estado === 'cancelada') {
            echo json_encode(['status' => 'error', 'message' => 'Esta tarea no se puede seleccionar'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // No permitir seleccionar tareas futuras
        if ($fecha_prog !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_prog)) {
            if ($fecha_prog > date('Y-m-d')) {
                echo json_encode(['status' => 'error', 'message' => 'Solo puedes seleccionar tareas de hoy o anteriores'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Si ya está seleccionada hoy, no hacer nada
        if ($estado === 'en_proceso' && $sel_dia === date('Y-m-d')) {
            echo json_encode(['status' => 'success', 'message' => 'Tarea ya seleccionada'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Guardar estado previo solo la primera vez
        if ($estado !== 'en_proceso' && $prev === '') {
            $prev = $estado;
        }

        $stUp = $conn->prepare(
            "UPDATE tarea
             SET estado='en_proceso',
                 estado_seleccion_prev = ?,
                 seleccionada_dia = CURDATE(),
                 seleccionada_at  = NOW(),
                 seleccion_fijada = 0,
                 seleccion_fijada_at = NULL
             WHERE id = ? AND asesor_id = ?
               AND estado IN ('programada','pendiente','postergada','en_proceso')"
        );
        $stUp->bind_param('sss', $prev, $tarea_id, $asesor_id);
        $ok = $stUp->execute();
        $stUp->close();

        echo json_encode([
            'status' => $ok ? 'success' : 'error',
            'message' => $ok ? 'Tarea seleccionada para hoy' : 'No se pudo seleccionar la tarea',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // deseleccionar
    if ($fijada === 1) {
        echo json_encode(['status' => 'error', 'message' => 'La selección ya fue fijada y no se puede deseleccionar'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($estado !== 'en_proceso') {
        echo json_encode(['status' => 'success', 'message' => 'La tarea no está seleccionada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $restore = $prev !== '' ? $prev : 'programada';

    $stUp = $conn->prepare(
        "UPDATE tarea
         SET estado = ?,
             estado_seleccion_prev = NULL,
             seleccionada_dia = NULL,
             seleccionada_at  = NULL,
             seleccion_fijada = 0,
             seleccion_fijada_at = NULL
         WHERE id = ? AND asesor_id = ?
           AND estado = 'en_proceso'
           AND (seleccion_fijada = 0 OR seleccion_fijada IS NULL)"
    );
    $stUp->bind_param('sss', $restore, $tarea_id, $asesor_id);
    $ok = $stUp->execute();
    $stUp->close();

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Tarea deseleccionada' : 'No se pudo deseleccionar la tarea',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[seleccionar_tarea_hoy] ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor',
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        try { $conn->close(); } catch (Throwable $_) {}
    }
}

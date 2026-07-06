<?php
// ============================================================
// descartar_tarea.php
// Marca una tarea como descartada (mobile).
// - El cliente/prospecto ya no quiere continuar con el trámite.
// - Cambia estado a 'cancelada' (valor ya existente en el ENUM tarea.estado)
// - Guarda el motivo del descarte y la fecha/hora en que ocurrió
// - Limpia la selección/fijado del día para que desaparezca de "Hoy"
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
$motivo       = trim($_POST['motivo'] ?? '');

if ($usuario_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($tarea_id === '' || strlen($tarea_id) > 64) {
    echo json_encode(['status' => 'error', 'message' => 'tarea_id invalido'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($motivo) > 500) {
    $motivo = substr($motivo, 0, 500);
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

    // Asegurar columnas necesarias (migración no destructiva)
    foreach ([
        'estado_seleccion_prev' => "ADD COLUMN estado_seleccion_prev VARCHAR(20) DEFAULT NULL AFTER estado",
        'seleccionada_dia'      => "ADD COLUMN seleccionada_dia DATE DEFAULT NULL AFTER estado_seleccion_prev",
        'seleccionada_at'       => "ADD COLUMN seleccionada_at DATETIME DEFAULT NULL AFTER seleccionada_dia",
        'seleccion_fijada'      => "ADD COLUMN seleccion_fijada TINYINT(1) NOT NULL DEFAULT 0 AFTER seleccionada_at",
        'seleccion_fijada_at'   => "ADD COLUMN seleccion_fijada_at DATETIME DEFAULT NULL AFTER seleccion_fijada",
        'motivo_descarte'       => "ADD COLUMN motivo_descarte TEXT DEFAULT NULL AFTER motivo_postergacion",
        'descartada_at'         => "ADD COLUMN descartada_at DATETIME DEFAULT NULL AFTER motivo_descarte",
    ] as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM tarea LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE tarea $ddl");
        }
    }

    // Verificar tarea pertenece al asesor
    $stChk = $conn->prepare('SELECT estado FROM tarea WHERE id = ? AND asesor_id = ? LIMIT 1');
    $stChk->bind_param('ss', $tarea_id, $asesor_id);
    $stChk->execute();
    $r = $stChk->get_result()->fetch_assoc();
    $stChk->close();

    if (!$r) {
        echo json_encode(['status' => 'error', 'message' => 'Tarea no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $estado = (string)($r['estado'] ?? '');
    if ($estado === 'cancelada') {
        echo json_encode(['status' => 'success', 'message' => 'La tarea ya estaba descartada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($estado === 'completada') {
        echo json_encode(['status' => 'error', 'message' => 'La tarea ya fue finalizada, no se puede descartar'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stUp = $conn->prepare(
        "UPDATE tarea
         SET estado = 'cancelada',
             motivo_descarte = ?,
             descartada_at = NOW(),
             estado_seleccion_prev = NULL,
             seleccionada_dia = NULL,
             seleccionada_at  = NULL,
             seleccion_fijada = 0,
             seleccion_fijada_at = NULL
         WHERE id = ? AND asesor_id = ?"
    );
    $motivoOrNull = $motivo !== '' ? $motivo : null;
    $stUp->bind_param('sss', $motivoOrNull, $tarea_id, $asesor_id);
    $ok = $stUp->execute();
    $stUp->close();

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Tarea descartada' : 'No se pudo descartar la tarea',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[descartar_tarea] ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor',
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        try { $conn->close(); } catch (Throwable $_) {}
    }
}

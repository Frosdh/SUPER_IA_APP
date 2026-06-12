<?php
// admin/revisar_recuperacion.php
// El supervisor aprueba o rechaza una tarea de recuperación finalizada por un asesor.
// Parámetros JSON / POST:
//   tarea_id     — id de la tarea (tipo_tarea='recuperacion', estado='completada', revision_recuperacion='pendiente')
//   accion       — 'aprobar' | 'rechazar'
//   observacion  — texto opcional con el motivo/nota del supervisor
//
// Aprobar  -> revision_recuperacion='aprobada' (la recuperación queda finalizada)
// Rechazar -> revision_recuperacion='rechazada' y la tarea vuelve a estado='programada'
//             para que el asesor la vea de nuevo en su agenda.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!isset($_SESSION['supervisor_logged_in']) && !$is_admin_gerente) {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Resolver supervisor.id de forma robusta
$supervisor_table_id = null;
try {
    $sess_sup = $_SESSION['supervisor_id'] ?? null;
    if ($sess_sup) {
        $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$sess_sup]);
        $supervisor_table_id = $st->fetchColumn() ?: null;
        if (!$supervisor_table_id) {
            $st = $pdo->prepare('SELECT id FROM supervisor WHERE id = ? LIMIT 1');
            $st->execute([$sess_sup]);
            $supervisor_table_id = $st->fetchColumn() ?: null;
        }
    }
} catch (Throwable $_) {}

// Quién hace la revisión (para revision_recuperacion_por)
$revisor_id = $is_admin_gerente
    ? ($_SESSION['admin_usuario_id'] ?? $_SESSION['admin_id'] ?? 'gerente')
    : ($_SESSION['supervisor_id'] ?? '');

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$tarea_id   = trim((string)($payload['tarea_id'] ?? ''));
$accion     = trim((string)($payload['accion'] ?? ''));
$observacion = trim((string)($payload['observacion'] ?? ''));

if ($tarea_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'tarea_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!in_array($accion, ['aprobar', 'rechazar'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'accion invalida (use aprobar|rechazar)'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Asegurar columnas de revisión de recuperación (migración no destructiva)
try {
    foreach ([
        'revision_recuperacion'     => "ADD COLUMN revision_recuperacion ENUM('pendiente','aprobada','rechazada') DEFAULT NULL AFTER hora_realizada",
        'revision_recuperacion_at'  => "ADD COLUMN revision_recuperacion_at DATETIME DEFAULT NULL AFTER revision_recuperacion",
        'revision_recuperacion_por' => "ADD COLUMN revision_recuperacion_por VARCHAR(64) DEFAULT NULL AFTER revision_recuperacion_at",
        'revision_recuperacion_obs' => "ADD COLUMN revision_recuperacion_obs TEXT DEFAULT NULL AFTER revision_recuperacion_por",
    ] as $col => $ddl) {
        $chk = $pdo->query("SHOW COLUMNS FROM tarea LIKE '$col'");
        if ($chk && $chk->rowCount() === 0) {
            $pdo->exec("ALTER TABLE tarea $ddl");
        }
    }
} catch (Throwable $_) {}

// Asesores del equipo (o todos, si es gerente) — para validar pertenencia
$asesor_ids = [];
try {
    if ($is_admin_gerente) {
        $st = $pdo->query('SELECT id FROM asesor');
        $asesor_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($supervisor_table_id) {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE supervisor_id = ?');
        $st->execute([$supervisor_table_id]);
        $asesor_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $_) {}

if (empty($asesor_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No se encontraron asesores para este supervisor'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Verificar que la tarea exista, sea recuperación pendiente de revisión y pertenezca al equipo
    $ph = implode(',', array_fill(0, count($asesor_ids), '?'));
    $st = $pdo->prepare(
        "SELECT id, asesor_id, estado, tipo_tarea, revision_recuperacion
         FROM tarea
         WHERE id = ? AND tipo_tarea = 'recuperacion' AND asesor_id IN ($ph)
         LIMIT 1"
    );
    $st->execute(array_merge([$tarea_id], $asesor_ids));
    $tarea = $st->fetch(PDO::FETCH_ASSOC);

    if (!$tarea) {
        echo json_encode(['status' => 'error', 'message' => 'Tarea de recuperación no encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($tarea['estado'] !== 'completada' || $tarea['revision_recuperacion'] !== 'pendiente') {
        echo json_encode(['status' => 'error', 'message' => 'Esta recuperación no está pendiente de revisión'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'aprobar') {
        $stUp = $pdo->prepare(
            "UPDATE tarea
             SET revision_recuperacion = 'aprobada',
                 revision_recuperacion_at = NOW(),
                 revision_recuperacion_por = ?,
                 revision_recuperacion_obs = ?
             WHERE id = ?"
        );
        $stUp->execute([$revisor_id, $observacion, $tarea_id]);
        $msg = 'Recuperación aprobada. La gestión queda finalizada.';
    } else {
        // Rechazar: vuelve a la agenda del asesor como tarea programada para hoy
        $stUp = $pdo->prepare(
            "UPDATE tarea
             SET revision_recuperacion = 'rechazada',
                 revision_recuperacion_at = NOW(),
                 revision_recuperacion_por = ?,
                 revision_recuperacion_obs = ?,
                 estado = 'programada',
                 fecha_programada = CURDATE(),
                 fecha_realizada = NULL,
                 hora_realizada = NULL,
                 estado_seleccion_prev = NULL,
                 seleccionada_dia = NULL,
                 seleccionada_at = NULL,
                 seleccion_fijada = 0,
                 seleccion_fijada_at = NULL
             WHERE id = ?"
        );
        $stUp->execute([$revisor_id, $observacion, $tarea_id]);
        $msg = 'Recuperación rechazada. La tarea vuelve a la agenda del asesor.';
    }

    echo json_encode(['status' => 'success', 'message' => $msg], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

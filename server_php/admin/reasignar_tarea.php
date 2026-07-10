<?php
// ============================================================
// admin/reasignar_tarea.php
// Reasigna una tarea 'incumplida' (5 posposiciones) a otro asesor.
// Solo supervisor / gerente / admin. Reinicia posposiciones y estado.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php'; // PDO ($pdo)

header('Content-Type: application/json; charset=utf-8');

function ra_respond(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin    = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
$is_supervisor     = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;

if (!$is_admin_gerente && !$is_super_admin && !$is_supervisor) {
    http_response_code(200);
    ra_respond(['status' => 'error', 'message' => 'Sesión no autorizada']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ra_respond(['status' => 'error', 'message' => 'Método no permitido']);
}

function genUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

$tarea_id       = trim($_POST['tarea_id'] ?? '');
$nuevo_asesor_id = trim($_POST['nuevo_asesor_id'] ?? '');
$nueva_fecha    = trim($_POST['nueva_fecha'] ?? '');
$nueva_hora     = trim($_POST['nueva_hora'] ?? '');

if ($tarea_id === '' || $nuevo_asesor_id === '') {
    ra_respond(['status' => 'error', 'message' => 'tarea_id y nuevo_asesor_id son requeridos']);
}
if ($nueva_fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nueva_fecha)) {
    $nueva_fecha = date('Y-m-d', strtotime('+1 day'));
}
if ($nueva_hora !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $nueva_hora)) {
    $nueva_hora = '';
}

try {
    // Resolver supervisor.id (para acotar el equipo si aplica)
    $supervisor_table_id = null;
    if ($is_supervisor) {
        $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$_SESSION['supervisor_id'] ?? '']);
        $supervisor_table_id = $st->fetchColumn() ?: null;
    }

    // Validar que la tarea existe y está incumplida
    $st = $pdo->prepare('SELECT id, asesor_id, estado, tipo_tarea, cliente_prospecto_id FROM tarea WHERE id = ? LIMIT 1');
    $st->execute([$tarea_id]);
    $tarea = $st->fetch();

    if (!$tarea) {
        ra_respond(['status' => 'error', 'message' => 'Tarea no encontrada']);
    }
    if ($tarea['estado'] !== 'incumplida') {
        ra_respond(['status' => 'error', 'message' => 'Solo se pueden reasignar tareas marcadas como incumplidas']);
    }

    $asesor_anterior_id = $tarea['asesor_id'];

    // Validar que el nuevo asesor existe (y, si es supervisor, que pertenezca a su equipo)
    $sqlAsesor = 'SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ?';
    $paramsAsesor = [$nuevo_asesor_id];
    if ($is_supervisor && $supervisor_table_id) {
        $sqlAsesor .= ' AND a.supervisor_id = ?';
        $paramsAsesor[] = $supervisor_table_id;
    }
    $st = $pdo->prepare($sqlAsesor . ' LIMIT 1');
    $st->execute($paramsAsesor);
    $nuevoAsesor = $st->fetch();

    if (!$nuevoAsesor) {
        ra_respond(['status' => 'error', 'message' => 'El asesor seleccionado no es válido o no pertenece a tu equipo']);
    }

    if ($nuevoAsesor['id'] === $asesor_anterior_id) {
        ra_respond(['status' => 'error', 'message' => 'Selecciona un asesor distinto al que tenía la tarea']);
    }

    // Nombre del asesor anterior (para el registro de auditoría)
    $nombreAnterior = '—';
    if ($asesor_anterior_id) {
        $st = $pdo->prepare('SELECT u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ? LIMIT 1');
        $st->execute([$asesor_anterior_id]);
        $nombreAnterior = $st->fetchColumn() ?: '—';
    }

    // Reasignar: nuevo dueño, se reinicia el ciclo de la tarea por completo
    $stUp = $pdo->prepare(
        "UPDATE tarea
         SET asesor_id = ?,
             estado = 'programada',
             fecha_programada = ?,
             hora_programada = ?,
             posposiciones = 0,
             pospuesta_de_dia = NULL,
             incumplida_at = NULL,
             estado_seleccion_prev = NULL,
             seleccionada_dia = NULL,
             seleccionada_at = NULL,
             seleccion_fijada = 0,
             seleccion_fijada_at = NULL
         WHERE id = ?"
    );
    $ok = $stUp->execute([$nuevo_asesor_id, $nueva_fecha, ($nueva_hora !== '' ? $nueva_hora : null), $tarea_id]);

    if (!$ok) {
        ra_respond(['status' => 'error', 'message' => 'No se pudo reasignar la tarea']);
    }

    // Registro de auditoría (best-effort, no bloquea la respuesta si falla)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS alerta_modificacion (
            id               CHAR(36)     NOT NULL PRIMARY KEY,
            tarea_id         CHAR(36)     NOT NULL,
            asesor_id        CHAR(36)     NOT NULL,
            supervisor_id    CHAR(36)     DEFAULT NULL,
            campo_modificado VARCHAR(120) DEFAULT 'visita_cliente',
            valor_anterior   TEXT         DEFAULT NULL,
            valor_nuevo      TEXT         DEFAULT NULL,
            vista_supervisor TINYINT(1)   NOT NULL DEFAULT 0,
            vista_at         DATETIME     DEFAULT NULL,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_am_asesor (asesor_id),
            KEY idx_am_supervisor (supervisor_id),
            KEY idx_am_no_vista (vista_supervisor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $alertaId = genUUID();
        $stAl = $pdo->prepare(
            "INSERT INTO alerta_modificacion
             (id, tarea_id, asesor_id, supervisor_id, campo_modificado, valor_anterior, valor_nuevo, vista_supervisor)
             VALUES (?, ?, ?, ?, 'reasignacion_tarea', ?, ?, 1)"
        );
        $stAl->execute([
            $alertaId, $tarea_id, $nuevo_asesor_id, $supervisor_table_id,
            $nombreAnterior, $nuevoAsesor['nombre'],
        ]);
    } catch (Throwable $e) {
        // no crítico
    }

    ra_respond([
        'status'  => 'success',
        'message' => 'Tarea reasignada a ' . $nuevoAsesor['nombre'],
    ]);

} catch (Throwable $e) {
    error_log('[reasignar_tarea] ' . $e->getMessage());
    ra_respond(['status' => 'error', 'message' => 'Error del servidor']);
}

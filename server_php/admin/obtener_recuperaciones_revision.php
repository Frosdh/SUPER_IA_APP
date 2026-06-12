<?php
// admin/obtener_recuperaciones_revision.php
// Lista las tareas de recuperación finalizadas por los asesores que están
// pendientes de revisión (aprobar/rechazar) por el supervisor.
// Devuelve también un resumen de aprobadas/rechazadas recientes (opcional, ?incluir_historial=1).

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!isset($_SESSION['supervisor_logged_in']) && !$is_admin_gerente) {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Resolver supervisor.id de forma robusta (igual que recuperacion.php)
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

// Asesores del equipo (o todos, si es gerente)
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

$pendientes = [];
$historial  = [];

if (!empty($asesor_ids)) {
    try {
        $ph = implode(',', array_fill(0, count($asesor_ids), '?'));

        // Pendientes de revisión
        $sqlPend = "
            SELECT
                t.id,
                t.asesor_id,
                u.nombre                              AS asesor_nombre,
                t.cliente_prospecto_id,
                cp.nombre                              AS cliente_nombre,
                cp.cedula                              AS cliente_cedula,
                cp.telefono                            AS cliente_telefono,
                t.observaciones,
                t.fecha_programada,
                t.fecha_realizada,
                t.hora_realizada
            FROM tarea t
            LEFT JOIN asesor a            ON a.id = t.asesor_id
            LEFT JOIN usuario u           ON u.id = a.usuario_id
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE t.tipo_tarea = 'recuperacion'
              AND t.estado = 'completada'
              AND (t.revision_recuperacion = 'pendiente' OR t.revision_recuperacion IS NULL)
              AND t.asesor_id IN ($ph)
            ORDER BY t.fecha_realizada DESC, t.hora_realizada DESC
            LIMIT 300
        ";
        $st = $pdo->prepare($sqlPend);
        $st->execute($asesor_ids);
        $pendientes = $st->fetchAll(PDO::FETCH_ASSOC);

        // Historial reciente (aprobadas/rechazadas) — opcional
        if (isset($_GET['incluir_historial']) && $_GET['incluir_historial'] == '1') {
            $sqlHist = "
                SELECT
                    t.id,
                    t.asesor_id,
                    u.nombre                              AS asesor_nombre,
                    t.cliente_prospecto_id,
                    cp.nombre                              AS cliente_nombre,
                    cp.cedula                              AS cliente_cedula,
                    t.observaciones,
                    t.revision_recuperacion,
                    t.revision_recuperacion_at,
                    t.revision_recuperacion_obs
                FROM tarea t
                LEFT JOIN asesor a            ON a.id = t.asesor_id
                LEFT JOIN usuario u           ON u.id = a.usuario_id
                LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                WHERE t.tipo_tarea = 'recuperacion'
                  AND t.revision_recuperacion IN ('aprobada','rechazada')
                  AND t.asesor_id IN ($ph)
                ORDER BY t.revision_recuperacion_at DESC
                LIMIT 50
            ";
            $st2 = $pdo->prepare($sqlHist);
            $st2->execute($asesor_ids);
            $historial = $st2->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode([
    'status'     => 'success',
    'total'      => count($pendientes),
    'pendientes' => $pendientes,
    'historial'  => $historial,
], JSON_UNESCAPED_UNICODE);

<?php
// admin/obtener_lista_recuperaciones.php
// Lista todas las tareas de recuperación (tipo_tarea='recuperacion') del equipo
// del supervisor (o de todos los asesores, si es gerente), sin importar su estado.
// Soporta filtros opcionales por GET:
//   ?estado=programada|en_proceso|postergada|completada|cancelada
//   ?asesor_id=<id>
//   ?q=<texto búsqueda por nombre/cédula>

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

$tareas = [];
$resumen = ['programada' => 0, 'en_proceso' => 0, 'postergada' => 0, 'completada' => 0, 'cancelada' => 0, 'total' => 0];

if (!empty($asesor_ids)) {
    try {
        $ph = implode(',', array_fill(0, count($asesor_ids), '?'));
        $params = $asesor_ids;

        $where = "t.tipo_tarea = 'recuperacion' AND t.asesor_id IN ($ph)";

        $estadoFiltro = trim($_GET['estado'] ?? '');
        if ($estadoFiltro !== '' && in_array($estadoFiltro, ['programada','en_proceso','postergada','completada','cancelada'], true)) {
            $where .= " AND t.estado = ?";
            $params[] = $estadoFiltro;
        }

        $asesorFiltro = trim($_GET['asesor_id'] ?? '');
        if ($asesorFiltro !== '') {
            $where .= " AND t.asesor_id = ?";
            $params[] = $asesorFiltro;
        }

        $qFiltro = trim($_GET['q'] ?? '');
        if ($qFiltro !== '') {
            $where .= " AND (cp.nombre LIKE ? OR cp.cedula LIKE ?)";
            $params[] = '%' . $qFiltro . '%';
            $params[] = '%' . $qFiltro . '%';
        }

        $sql = "
            SELECT
                t.id,
                t.asesor_id,
                u.nombre                              AS asesor_nombre,
                t.cliente_prospecto_id,
                cp.nombre                              AS cliente_nombre,
                cp.cedula                              AS cliente_cedula,
                cp.telefono                            AS cliente_telefono,
                t.estado,
                t.observaciones,
                t.fecha_programada,
                t.hora_programada,
                t.fecha_realizada,
                t.hora_realizada,
                t.revision_recuperacion,
                t.revision_recuperacion_at,
                t.revision_recuperacion_obs,
                t.created_at
            FROM tarea t
            LEFT JOIN asesor a            ON a.id = t.asesor_id
            LEFT JOIN usuario u           ON u.id = a.usuario_id
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE $where
            ORDER BY t.created_at DESC
            LIMIT 300
        ";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $tareas = $st->fetchAll(PDO::FETCH_ASSOC);

        // Resumen de conteos por estado (sin filtros de estado/asesor/q, solo del equipo)
        $sqlResumen = "
            SELECT t.estado, COUNT(*) AS total
            FROM tarea t
            WHERE t.tipo_tarea = 'recuperacion' AND t.asesor_id IN ($ph)
            GROUP BY t.estado
        ";
        $stR = $pdo->prepare($sqlResumen);
        $stR->execute($asesor_ids);
        foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $estado = $row['estado'];
            $cnt = (int)$row['total'];
            if (isset($resumen[$estado])) $resumen[$estado] = $cnt;
            $resumen['total'] += $cnt;
        }
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

echo json_encode([
    'status'  => 'success',
    'total'   => count($tareas),
    'resumen' => $resumen,
    'tareas'  => $tareas,
], JSON_UNESCAPED_UNICODE);

<?php
/**
 * api_alertas_flotantes.php
 * Devuelve las últimas 3 alertas no vistas para el supervisor activo.
 * También permite marcar una alerta como vista (cerrar notificación).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db_admin.php';

// Verificar sesión (supervisor o gerente/admin)
$is_supervisor = !empty($_SESSION['supervisor_logged_in']);
$is_admin      = !empty($_SESSION['admin_logged_in']);

if (!$is_supervisor && !$is_admin) {
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

$supervisor_table_ids = [];

if ($is_supervisor) {
    $supervisor_usuario_id = $_SESSION['supervisor_id'] ?? null;
    try {
        $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$supervisor_usuario_id]);
        $sid = $st->fetchColumn() ?: null;
        if ($sid) $supervisor_table_ids[] = $sid;
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'db_error']);
        exit;
    }
} elseif ($is_admin) {
    $admin_id = $_SESSION['admin_id'] ?? null;
    $admin_rol = $_SESSION['admin_rol'] ?? 'jefe_agencia';
    $jaIds = [];
    try {
        if ($admin_rol === 'jefe_agencia') {
            $st = $pdo->prepare('SELECT id FROM jefe_agencia WHERE usuario_id = ?');
            $st->execute([$admin_id]);
            $jaIds = $st->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($admin_rol === 'gerente_general') {
            $st = $pdo->prepare('SELECT unidad_bancaria_id FROM gerente_general WHERE usuario_id = ? LIMIT 1');
            $st->execute([$admin_id]);
            $ub_id = $st->fetchColumn() ?: null;
            if ($ub_id) {
                $st = $pdo->prepare('SELECT ja.id FROM jefe_agencia ja JOIN agencia ag ON ag.id=ja.agencia_id WHERE ag.unidad_bancaria_id=?');
                $st->execute([$ub_id]);
                $jaIds = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        if (!empty($jaIds)) {
            $phJa = implode(',', array_fill(0, count($jaIds), '?'));
            $st = $pdo->prepare("SELECT id FROM supervisor WHERE jefe_agencia_id IN ($phJa)");
            $st->execute($jaIds);
            $supervisor_table_ids = $st->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'db_error']);
        exit;
    }
}

if (empty($supervisor_table_ids)) {
    echo json_encode(['ok' => false, 'alertas' => []]);
    exit;
}

// ── Acción: marcar alerta como vista ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body  = json_decode(file_get_contents('php://input'), true);
    $accion = $body['accion'] ?? '';

    if ($accion === 'marcar_vista') {
        $alerta_id = (int)($body['alerta_id'] ?? 0);
        if ($alerta_id > 0) {
            try {
                $phIds = implode(',', array_fill(0, count($supervisor_table_ids), '?'));
                $upd = $pdo->prepare(
                    "UPDATE alerta_modificacion SET vista_supervisor = 1
                     WHERE id = ? AND supervisor_id IN ($phIds)"
                );
                $upd->execute(array_merge([$alerta_id], $supervisor_table_ids));
                echo json_encode(['ok' => true]);
            } catch (PDOException $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'invalid_id']);
        }
        exit;
    }
}

// ── GET: obtener últimas 3 alertas no vistas ──────────────────
try {
    $phIds = implode(',', array_fill(0, count($supervisor_table_ids), '?'));
    $st = $pdo->prepare("
        SELECT
            am.id            AS id_alerta,
            am.campo_modificado,
            am.valor_nuevo,
            am.created_at,
            u.nombre         AS asesor_nombre,
            COALESCE(cp.nombre, CONCAT('Tarea #', am.tarea_id)) AS cliente_nombre
        FROM alerta_modificacion am
        JOIN asesor  a  ON a.id  = am.asesor_id
        JOIN usuario u  ON u.id  = a.usuario_id
        LEFT JOIN tarea t ON t.id = am.tarea_id
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE am.supervisor_id IN ($phIds) AND am.vista_supervisor = 0
        ORDER BY am.created_at DESC
        LIMIT 3
    ");
    $st->execute($supervisor_table_ids);
    $alertas = $st->fetchAll(PDO::FETCH_ASSOC);

    // Formatear tiempo relativo
    foreach ($alertas as &$a) {
        $diff = time() - strtotime($a['created_at']);
        if ($diff < 60)          $a['tiempo'] = 'hace ' . $diff . 's';
        elseif ($diff < 3600)    $a['tiempo'] = 'hace ' . floor($diff/60) . 'min';
        elseif ($diff < 86400)   $a['tiempo'] = 'hace ' . floor($diff/3600) . 'h';
        else                     $a['tiempo'] = date('d/m H:i', strtotime($a['created_at']));
    }

    echo json_encode(['ok' => true, 'alertas' => $alertas]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'alertas' => []]);
}

<?php
/**
 * api_alertas_flotantes.php
 * Devuelve las últimas 3 alertas no vistas para el supervisor activo.
 * También permite marcar una alerta como vista (cerrar notificación).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'db_admin.php';

// Verificar sesión supervisor
if (empty($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

$supervisor_usuario_id = $_SESSION['supervisor_id'] ?? null;

// Resolver supervisor.id desde usuario_id
$supervisor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$supervisor_usuario_id]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'db_error']);
    exit;
}

if (!$supervisor_table_id) {
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
                $upd = $pdo->prepare(
                    'UPDATE alerta_modificacion SET vista_supervisor = 1
                     WHERE id = ? AND supervisor_id = ?'
                );
                $upd->execute([$alerta_id, $supervisor_table_id]);
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
        WHERE am.supervisor_id = ? AND am.vista_supervisor = 0
        ORDER BY am.created_at DESC
        LIMIT 3
    ");
    $st->execute([$supervisor_table_id]);
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

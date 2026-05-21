<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_admin.php';

$out = ['status'=>'ok'];
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }
    if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
        throw new Exception('No autorizado');
    }
    $id = $_POST['id'] ?? null;
    if (!$id) throw new Exception('Falta id');

    $sess_usuario_id = $_SESSION['supervisor_id'] ?? null;
    if (!$sess_usuario_id) throw new Exception('Sesión inválida');

    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$sess_usuario_id]);
    $sup_table_id = $st->fetchColumn();
    if (!$sup_table_id) throw new Exception('Supervisor no encontrado');

    $u = $pdo->prepare('UPDATE alerta_modificacion SET vista_supervisor = 1, vista_at = NOW() WHERE id = ? AND supervisor_id = ?');
    $u->execute([$id, $sup_table_id]);

    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
exit;

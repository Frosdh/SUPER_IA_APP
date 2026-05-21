<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_admin.php'; // provides $pdo

$out = ['status'=>'ok'];
try {
    if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
        throw new Exception('No autorizado');
    }
    $sess_usuario_id = $_SESSION['supervisor_id'] ?? null;
    if (!$sess_usuario_id) throw new Exception('Sesión inválida');

    // resolver supervisor table id
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$sess_usuario_id]);
    $sup_table_id = $st->fetchColumn();
    if (!$sup_table_id) throw new Exception('Supervisor no encontrado');

    // intentar obtener un asesor asociado para rellenar asesor_id (si existe)
    $asesor_id = null;
    try {
        $s2 = $pdo->prepare('SELECT id FROM asesor WHERE supervisor_id = ? LIMIT 1');
        $s2->execute([$sup_table_id]);
        $asesor_id = $s2->fetchColumn() ?: null;
    } catch (Throwable $_) { $asesor_id = null; }

    $id = bin2hex(random_bytes(8));
    $tarea_id = null;
    $campo = 'Prueba de alerta';
    $valor_old = null;
    $valor_new = 'Asesor: ' . ($asesor_id ? $asesor_id : 'N/A') . ' | Cliente: Prueba Usuario | Fecha: ' . date('d/m/Y H:i');

    $ins = $pdo->prepare('INSERT INTO alerta_modificacion (id, tarea_id, asesor_id, supervisor_id, campo_modificado, valor_anterior, valor_nuevo, vista_supervisor, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())');
    $ins->execute([$id, $tarea_id, $asesor_id, $sup_table_id, $campo, $valor_old, $valor_new]);

    $out['id'] = $id;
    $out['message'] = 'Alerta de prueba creada';
    echo json_encode($out);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
exit;

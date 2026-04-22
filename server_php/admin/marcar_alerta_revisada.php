<?php
// marcar_alerta_revisada.php
// Marca una alerta de modificación como revisada (vista_supervisor = 1).
// Responde JSON: { "success": true/false, "message": "..." }

require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

// Solo POST + AJAX
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$is_ajax) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$id = trim($_POST['id'] ?? '');
if ($id === '') {
    echo json_encode(['success' => false, 'message' => 'ID requerido']);
    exit;
}

try {
    // Verificar que la alerta exista
    $chk = $pdo->prepare('SELECT id, vista_supervisor FROM alerta_modificacion WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Alerta no encontrada']);
        exit;
    }

    // Marcar como revisada
    $upd = $pdo->prepare(
        'UPDATE alerta_modificacion SET vista_supervisor = 1, vista_at = NOW() WHERE id = ?'
    );
    $upd->execute([$id]);

    echo json_encode(['success' => true, 'message' => 'Alerta marcada como revisada']);

} catch (\Throwable $e) {
    error_log('[marcar_alerta_revisada] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}

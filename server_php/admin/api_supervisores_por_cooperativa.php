<?php
// ============================================================
// api_supervisores_por_cooperativa.php
// Carga supervisores dinámicamente según cooperativa seleccionada
// ============================================================

header('Content-Type: application/json');
require_once '../db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$cooperativa_id = $_POST['cooperativa_id'] ?? '';

if (empty($cooperativa_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Cooperativa ID requerido']);
    exit;
}

try {
    // Obtener todos los supervisores de la base
    // Nota: En SUPER_IA LOGAN, los supervisores no tienen relación directa con cooperativa
    // Aquí obtenemos todos los supervisores activos
    // Si en el futuro hay una relación, esta query puede actualizarse
    
    $stmt = $conn->prepare("
        SELECT u.id, u.nombre, u.email, u.rol
        FROM usuario u
        WHERE u.rol = 'supervisor' AND u.activo = 1 AND u.estado_aprobacion = 'aprobado'
        ORDER BY u.nombre ASC
    ");
    
    $stmt->execute();
    $supervisores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'supervisores' => $supervisores
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

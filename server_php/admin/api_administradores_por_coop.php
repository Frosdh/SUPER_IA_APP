<?php
require_once 'db_admin.php';

header('Content-Type: application/json');

if (!isset($_GET['cooperativa_id'])) {
    echo json_encode(['error' => 'cooperativa_id es requerido']);
    exit;
}

$cooperativa_id = intval($_GET['cooperativa_id']);

// Obtener administradores de esa cooperativa
// Por ahora, devolvemos todos los admins como asociados a la coop
// En una aplicación real, habría una tabla que relacione admins con cooperativas
try {
    $stmt = $pdo->query("
        SELECT u.id_usuario, CONCAT(u.nombres, ' ', u.apellidos) as nombre, u.email
        FROM usuarios u
        JOIN roles r ON u.id_rol_fk = r.id_rol
        WHERE r.nombre IN ('Admin', 'SuperAdmin')
        ORDER BY u.nombres ASC
        LIMIT 20
    ");
    $administradores = $stmt->fetchAll();
    
    // En el futuro, filtrar por cooperativa_id si hay tabla relacional
    // Por ahora devolvemos todos
    
    echo json_encode([
        'status' => 'ok',
        'administradores' => $administradores
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error al cargar administradores: ' . $e->getMessage()
    ]);
}

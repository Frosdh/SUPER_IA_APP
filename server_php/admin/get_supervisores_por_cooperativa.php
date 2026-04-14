<?php
require_once 'db_admin.php';

header('Content-Type: application/json');

$id_cooperativa = $_GET['id_cooperativa'] ?? null;

if (!$id_cooperativa || !is_numeric($id_cooperativa)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de cooperativa inválido', 'supervisores' => []]);
    exit;
}

$supervisores = [];

try {
    // Detectar columna de cooperativa en usuarios
    $userCols = $pdo->query("SHOW COLUMNS FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
    $userFields = array_map(fn($c) => $c['Field'], $userCols);

    $userCoopCol = null;
    foreach (['id_cooperativa_fk', 'id_cooperativa', 'cooperativa_id'] as $candidate) {
        if (in_array($candidate, $userFields, true)) {
            $userCoopCol = $candidate;
            break;
        }
    }

    if ($userCoopCol) {
        $stmt = $pdo->prepare("
            SELECT u.id_usuario, CONCAT(u.nombres, ' ', u.apellidos) AS nombre
            FROM usuarios u
            JOIN roles r ON u.id_rol_fk = r.id_rol
            WHERE r.nombre = 'Supervisor'
              AND u.`{$userCoopCol}` = ?
            ORDER BY u.nombres, u.apellidos
        ");
        $stmt->execute([$id_cooperativa]);
        $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Si no existe relación, devolver todos (mantiene compatibilidad)
        $stmt = $pdo->prepare("
            SELECT u.id_usuario, CONCAT(u.nombres, ' ', u.apellidos) AS nombre
            FROM usuarios u
            JOIN roles r ON u.id_rol_fk = r.id_rol
            WHERE r.nombre = 'Supervisor'
            ORDER BY u.nombres, u.apellidos
        ");
        $stmt->execute();
        $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Si la relación u.id_cooperativa_fk no existe, intentar con query simple
    try {
        $stmt = $pdo->prepare("
            SELECT u.id_usuario, CONCAT(u.nombres, ' ', u.apellidos) AS nombre
            FROM usuarios u
            JOIN roles r ON u.id_rol_fk = r.id_rol
            WHERE r.nombre = 'Supervisor'
            ORDER BY u.nombres, u.apellidos
        ");
        $stmt->execute();
        $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al obtener supervisores', 'supervisores' => []]);
        exit;
    }
}

echo json_encode(['supervisores' => $supervisores]);
exit;
?>

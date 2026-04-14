<?php
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$stmt = $conn->prepare("SELECT id, nombre FROM cooperativas ORDER BY nombre ASC");
$stmt->execute();
$result = $stmt->get_result();

$cooperativas = [];
while ($row = $result->fetch_assoc()) {
    $cooperativas[] = [
        'id'     => (int)$row['id'],
        'nombre' => $row['nombre']
    ];
}

echo json_encode([
    'status'       => 'success',
    'cooperativas' => $cooperativas
]);

$stmt->close();
$conn->close();
?>

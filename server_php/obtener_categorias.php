<?php
require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

$stmt = $conn->prepare(
    "SELECT id, nombre, tarifa_base, precio_km, precio_minuto FROM categorias ORDER BY id ASC"
);
$stmt->execute();
$result = $stmt->get_result();

$categorias = [];
while ($row = $result->fetch_assoc()) {
    $categorias[] = [
        'id'            => (int)$row['id'],
        'nombre'        => $row['nombre'],
        'tarifa_base'   => (float)$row['tarifa_base'],
        'precio_km'     => (float)$row['precio_km'],
        'precio_minuto' => (float)$row['precio_minuto'],
    ];
}

echo json_encode([
    'status'     => 'success',
    'categorias' => $categorias,
]);

$stmt->close();
$conn->close();
?>

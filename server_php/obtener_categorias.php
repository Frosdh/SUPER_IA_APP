<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

$host     = "localhost";
$dbname   = "fuber_db";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->connect_error]);
    exit;
}

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

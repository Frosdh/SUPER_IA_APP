<?php
require_once __DIR__ . '/db_config.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
    exit;
}

$stmt = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE telefono = ? LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        "status" => "success",
        "exists" => true,
        "id"     => intval($row["id"]),
        "nombre" => $row["nombre"] ?? "",
        "email"  => $row["email"] ?? ""
    ]);
} else {
    echo json_encode([
        "status" => "success",
        "exists" => false
    ]);
}

$stmt->close();
$conn->close();
?>

<?php
require_once __DIR__ . '/db_config.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (empty($email)) {
    echo json_encode(["status" => "error", "message" => "El correo es requerido"]);
    exit;
}

$stmt = $conn->prepare("SELECT id, nombre, email, telefono, activo FROM usuarios WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        "status"   => "success",
        "exists"   => true,
        "id"       => intval($row["id"]),
        "nombre"   => $row["nombre"] ?? "",
        "email"    => $row["email"] ?? "",
        "telefono" => $row["telefono"] ?? "",
        "activo"   => isset($row["activo"]) ? intval($row["activo"]) : 1
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

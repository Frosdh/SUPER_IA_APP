<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (empty($email)) {
    echo json_encode(["status" => "error", "message" => "El correo es requerido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode([
        "status"  => "error",
        "message" => "Error de conexion a base de datos: " . $conn->connect_error
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT id, nombre, email, telefono FROM usuarios WHERE email = ? LIMIT 1");
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
        "telefono" => $row["telefono"] ?? ""
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

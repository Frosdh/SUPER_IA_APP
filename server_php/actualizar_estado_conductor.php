<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
$estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';
$estadosPermitidos = ["libre", "desconectado", "ocupado"];

if ($conductorId <= 0 || !in_array($estado, $estadosPermitidos)) {
    echo json_encode([
        "status" => "error",
        "message" => "conductor_id o estado invalidos"
    ]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Error de conexion: " . $conn->connect_error
    ]);
    exit;
}

$stmt = $conn->prepare("UPDATE conductores SET estado = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "message" => "Error al preparar consulta: " . $conn->error
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param("si", $estado, $conductorId);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Estado actualizado",
        "estado" => $estado
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo actualizar el estado: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>

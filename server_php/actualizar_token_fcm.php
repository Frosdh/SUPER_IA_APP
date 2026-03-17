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
$tokenFcm = isset($_POST['token_fcm']) ? trim($_POST['token_fcm']) : '';

if ($telefono === '' || $tokenFcm === '') {
    echo json_encode(["status" => "error", "message" => "Telefono y token requeridos"]);
    exit;
}

$stmt = $conn->prepare("UPDATE usuarios SET token_fcm = ? WHERE telefono = ?");
$stmt->bind_param("ss", $tokenFcm, $telefono);

if ($stmt->execute() && $stmt->affected_rows >= 0) {
    echo json_encode([
        "status" => "success",
        "message" => "Token FCM actualizado",
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo actualizar el token: " . $stmt->error,
    ]);
}

$stmt->close();
$conn->close();
?>

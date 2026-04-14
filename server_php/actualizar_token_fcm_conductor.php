<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// actualizar_token_fcm_conductor.php
// Guarda el token FCM del dispositivo del conductor.
// POST params: conductor_id (int), token_fcm (string)
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
$tokenFcm    = isset($_POST['token_fcm'])    ? trim($_POST['token_fcm'])     : '';

if ($conductorId <= 0 || $tokenFcm === '') {
    echo json_encode(["status" => "error", "message" => "conductor_id y token_fcm son requeridos"]);
    exit;
}

$stmt = $conn->prepare("UPDATE conductores SET token_fcm = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "Error al preparar consulta: " . $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param("si", $tokenFcm, $conductorId);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Token FCM actualizado"]);
} else {
    echo json_encode(["status" => "error", "message" => "No se pudo actualizar: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>

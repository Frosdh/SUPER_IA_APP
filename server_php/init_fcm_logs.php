<?php
require_once __DIR__ . '/db_config.php';
header("Content-Type: application/json");

$sql = "CREATE TABLE IF NOT EXISTS fcm_debug_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    viaje_id INT,
    conductor_id INT,
    token_fcm TEXT,
    response_code INT,
    response_text TEXT,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "Tabla fcm_debug_logs creada o ya existia"]);
} else {
    echo json_encode(["status" => "error", "message" => "Error creando tabla: " . $conn->error]);
}
?>

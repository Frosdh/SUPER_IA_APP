<?php
// ============================================================
// cancelar_viaje.php - Cancela un viaje activo
// Colocar en: /fuber_api/cancelar_viaje.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host     = "localhost";
$dbname   = "fuber_db";
$username = "root";
$password = "";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$viaje_id = isset($_POST['viaje_id']) ? intval($_POST['viaje_id']) : 0;

if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id invalido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

// Solo cancelar si el viaje no está ya terminado
$stmt = $conn->prepare("
    UPDATE viajes
    SET estado = 'cancelado', fecha_fin = NOW()
    WHERE id = ? AND estado NOT IN ('terminado', 'cancelado')
");
$stmt->bind_param("i", $viaje_id);
$stmt->execute();
$afectados = $stmt->affected_rows;
$stmt->close();
$conn->close();

if ($afectados > 0) {
    echo json_encode(["status" => "success", "message" => "Viaje cancelado"]);
} else {
    echo json_encode(["status" => "error", "message" => "No se pudo cancelar (ya terminado o no existe)"]);
}
?>

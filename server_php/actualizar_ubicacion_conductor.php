<?php
require_once __DIR__ . '/db_config.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
$latitud = isset($_POST['latitud']) ? trim($_POST['latitud']) : '';
$longitud = isset($_POST['longitud']) ? trim($_POST['longitud']) : '';

if ($conductorId <= 0 || $latitud === '' || $longitud === '') {
    echo json_encode([
        "status" => "error",
        "message" => "conductor_id, latitud y longitud requeridos"
    ]);
    exit;
}

$stmt = $conn->prepare("UPDATE conductores SET latitud = ?, longitud = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "message" => "Error al preparar consulta: " . $conn->error
    ]);
    $conn->close();
    exit;
}

$lat = (double)$latitud;
$lng = (double)$longitud;
$stmt->bind_param("ddi", $lat, $lng, $conductorId);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Ubicacion actualizada",
        "latitud" => $lat,
        "longitud" => $lng
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo actualizar la ubicacion: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>

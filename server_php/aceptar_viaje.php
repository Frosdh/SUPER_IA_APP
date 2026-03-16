<?php
// ============================================================
// aceptar_viaje.php - Permite al conductor aceptar una solicitud
// ============================================================
header("Content-Type: application/json");

require_once 'db_config.php'; // Asegúrate de crear este archivo

// Simulación de conexión si no tienes db_config aún
if (!isset($conn)) {
    $host = "localhost";
    $dbname = "corporat_fuber_db";
    $user = "corporat_fuber_user";
    $pass = "FuB3r!Db#2026$Qx9"; // Mover a archivo seguro
    $conn = new mysqli($host, $user, $pass, $dbname);
}

$viaje_id = $_POST['viaje_id'] ?? 0;
$conductor_id = $_POST['conductor_id'] ?? 0;

if ($viaje_id == 0 || $conductor_id == 0) {
    echo json_encode(["status" => "error", "message" => "Datos incompletos"]);
    exit;
}

// 1. Verificar si el viaje sigue disponible (estado 'pendiente')
$sql_check = "SELECT estado FROM viajes WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param("i", $viaje_id);
$stmt->execute();
$result = $stmt->get_result();
$viaje = $result->fetch_assoc();

if (!$viaje) {
    echo json_encode(["status" => "error", "message" => "Viaje no existe"]);
    exit;
}

if ($viaje['estado'] !== 'pendiente') {
    echo json_encode(["status" => "error", "message" => "El viaje ya fue tomado por otro conductor"]);
    exit;
}

// 2. Asignar el viaje al conductor
$sql_update = "UPDATE viajes SET 
                conductor_id = ?, 
                estado = 'aceptado',
                fecha_aceptacion = NOW() 
               WHERE id = ? AND estado = 'pendiente'";

$stmt_up = $conn->prepare($sql_update);
$stmt_up->bind_param("ii", $conductor_id, $viaje_id);

if ($stmt_up->execute() && $stmt_up->affected_rows > 0) {
    echo json_encode([
        "status" => "success", 
        "message" => "Viaje aceptado correctamente",
        "viaje_id" => $viaje_id
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "No se pudo asignar el viaje"]);
}
$conn->close();
?>
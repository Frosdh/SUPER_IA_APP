<?php
// ============================================================
// guardar_viaje.php - Guarda un viaje completado en la BD
// Colocar en: /fuber_api/guardar_viaje.php
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

$viaje_id         = isset($_POST['viaje_id'])         ? intval($_POST['viaje_id'])           : 0;
$calificacion     = isset($_POST['calificacion'])     ? floatval($_POST['calificacion'])      : null;
$comentario       = isset($_POST['comentario'])       ? trim($_POST['comentario'])            : '';
$descuento        = isset($_POST['descuento'])        ? floatval($_POST['descuento'])         : 0.0;
$codigo_descuento = isset($_POST['codigo_descuento']) ? strtoupper(trim($_POST['codigo_descuento'])) : '';

if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id requerido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

// Actualizar calificacion, comentario, descuento y fecha_fin
// No actualizamos conductor_id porque el conductor es simulado (sin FK real)
$stmt = $conn->prepare("
    UPDATE viajes
    SET estado = 'terminado',
        fecha_fin = NOW(),
        calificacion = ?,
        comentario = ?,
        descuento = ?,
        codigo_descuento = ?
    WHERE id = ?
");

$stmt->bind_param("dsdsi", $calificacion, $comentario, $descuento, $codigo_descuento, $viaje_id);

if ($stmt->execute()) {
    echo json_encode([
        "status"  => "success",
        "message" => "Viaje actualizado correctamente",
        "viaje_id" => $viaje_id
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "Error al actualizar: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>

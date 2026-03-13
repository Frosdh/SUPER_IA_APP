<?php
// ============================================================
// estado_viaje.php - Devuelve el estado actual de un viaje
// Colocar en: /fuber_api/estado_viaje.php
// ============================================================
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

// Obtener el estado del viaje junto con info del conductor (si está asignado)
$stmt = $conn->prepare("
    SELECT
        v.estado,
        v.tarifa_total,
        v.distancia_km,
        v.duracion_min,
        c.nombre  AS conductor_nombre,
        c.telefono AS conductor_telefono,
        c.calificacion_promedio AS conductor_calificacion,
        vh.marca  AS auto_marca,
        vh.modelo AS auto_modelo,
        vh.color  AS auto_color,
        vh.placa  AS auto_placa
    FROM viajes v
    LEFT JOIN conductores c  ON v.conductor_id = c.id
    LEFT JOIN vehiculos   vh ON vh.conductor_id = c.id
    WHERE v.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $viaje_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) {
    echo json_encode(["status" => "error", "message" => "Viaje no encontrado"]);
    exit;
}

$auto = '';
if (!empty($row['auto_marca']) || !empty($row['auto_modelo'])) {
    $auto = trim($row['auto_marca'] . ' ' . $row['auto_modelo']);
}

echo json_encode([
    "status"  => "success",
    "estado"  => $row['estado'],
    "conductor" => [
        "nombre"       => $row['conductor_nombre']      ?? '',
        "calificacion" => floatval($row['conductor_calificacion'] ?? 5.0),
        "auto"         => $auto,
        "color"        => $row['auto_color']  ?? '',
        "placa"        => $row['auto_placa']  ?? '',
    ]
]);
?>

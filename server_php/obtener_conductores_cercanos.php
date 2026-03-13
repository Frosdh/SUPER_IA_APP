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

$lat = isset($_POST['lat']) ? floatval($_POST['lat']) : null;
$lng = isset($_POST['lng']) ? floatval($_POST['lng']) : null;
$categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 0;
$radioKm = isset($_POST['radio_km']) ? floatval($_POST['radio_km']) : 0.0;

if ($lat === null || $lng === null) {
    echo json_encode(["status" => "error", "message" => "Latitud y longitud requeridas"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

$radioConfig = 5.0;
$cfg = $conn->query("SELECT valor FROM configuracion WHERE clave = 'radio_busqueda_conductores_km' LIMIT 1");
if ($cfg && $rowCfg = $cfg->fetch_assoc()) {
    $radioConfig = floatval($rowCfg['valor']);
}

if ($radioKm <= 0) {
    $radioKm = $radioConfig > 0 ? $radioConfig : 5.0;
}

$sql = "
    SELECT
        c.id,
        c.nombre,
        c.telefono,
        c.latitud,
        c.longitud,
        c.calificacion_promedio,
        v.categoria_id,
        v.placa,
        v.marca,
        v.modelo,
        v.color,
        (
            6371 * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(c.latitud)) *
                COS(RADIANS(c.longitud) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(c.latitud))
            )
        ) AS distancia_km
    FROM conductores c
    INNER JOIN vehiculos v ON v.conductor_id = c.id
    WHERE c.estado = 'libre'
      AND c.verificado = 1
      AND c.latitud IS NOT NULL
      AND c.longitud IS NOT NULL
";

if ($categoria_id > 0) {
    $sql .= " AND v.categoria_id = ? ";
}

$sql .= "
    HAVING distancia_km <= ?
    ORDER BY distancia_km ASC
    LIMIT 30
";

if ($categoria_id > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dddid", $lat, $lng, $lat, $categoria_id, $radioKm);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("dddd", $lat, $lng, $lat, $radioKm);
}

$stmt->execute();
$result = $stmt->get_result();

$conductores = [];
while ($row = $result->fetch_assoc()) {
    $conductores[] = [
        "id" => intval($row['id']),
        "nombre" => $row['nombre'] ?? '',
        "telefono" => $row['telefono'] ?? '',
        "latitud" => floatval($row['latitud']),
        "longitud" => floatval($row['longitud']),
        "calificacion" => floatval($row['calificacion_promedio'] ?? 5.0),
        "categoria_id" => intval($row['categoria_id']),
        "placa" => $row['placa'] ?? '',
        "marca" => $row['marca'] ?? '',
        "modelo" => $row['modelo'] ?? '',
        "color" => $row['color'] ?? '',
        "distancia_km" => round(floatval($row['distancia_km']), 2),
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "radio_km" => $radioKm,
    "conductores" => $conductores
]);
?>

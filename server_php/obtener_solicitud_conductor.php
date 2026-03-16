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

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;

if ($conductorId <= 0) {
    echo json_encode(["status" => "error", "message" => "conductor_id requerido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

$conn->query("
    CREATE TABLE IF NOT EXISTS solicitud_viajes (
      id INT PRIMARY KEY AUTO_INCREMENT,
      conductor_id INT NOT NULL,
      viaje_id INT NOT NULL,
      estado ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
      fecha_oferta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_conductor_viaje (conductor_id, viaje_id)
    )
");

$stmtConductor = $conn->prepare("
    SELECT c.estado, IFNULL(v.categoria_id, 0)
    FROM conductores c
    LEFT JOIN vehiculos v ON v.conductor_id = c.id
    WHERE c.id = ? AND c.verificado = 1
    LIMIT 1
");
$stmtConductor->bind_param("i", $conductorId);
$stmtConductor->execute();
$stmtConductor->bind_result($estadoConductor, $categoriaId);
$conductorEncontrado = $stmtConductor->fetch();
$stmtConductor->close();

if (!$conductorEncontrado) {
    echo json_encode(["status" => "error", "message" => "Conductor no encontrado"]);
    $conn->close();
    exit;
}

if ($estadoConductor !== 'libre') {
    echo json_encode(["status" => "success", "found" => false]);
    $conn->close();
    exit;
}

$stmtViaje = $conn->prepare("
    SELECT
        v.id,
        u.nombre,
        u.telefono,
        v.origen_texto,
        v.destino_texto,
        IFNULL(v.distancia_km, 0),
        IFNULL(v.duracion_min, 0),
        IFNULL(v.tarifa_total, 0)
    FROM viajes v
    INNER JOIN usuarios u ON u.id = v.usuario_id
    LEFT JOIN solicitud_viajes sv
        ON sv.viaje_id = v.id AND sv.conductor_id = ?
    WHERE v.estado = 'pedido'
      AND v.conductor_id IS NULL
      AND (? = 0 OR v.categoria_id = ?)
      AND (sv.estado IS NULL OR sv.estado <> 'rechazado')
    ORDER BY v.fecha_pedido ASC, v.id ASC
    LIMIT 1
");
$stmtViaje->bind_param("iii", $conductorId, $categoriaId, $categoriaId);
$stmtViaje->execute();
$stmtViaje->bind_result(
    $viajeId,
    $pasajeroNombre,
    $pasajeroTelefono,
    $origenTexto,
    $destinoTexto,
    $distanciaKm,
    $duracionMin,
    $tarifaTotal
);
$viajeEncontrado = $stmtViaje->fetch();
$stmtViaje->close();

if (!$viajeEncontrado) {
    echo json_encode(["status" => "success", "found" => false]);
    $conn->close();
    exit;
}

$stmtInsert = $conn->prepare("
    INSERT INTO solicitud_viajes (conductor_id, viaje_id, estado)
    VALUES (?, ?, 'pendiente')
    ON DUPLICATE KEY UPDATE fecha_oferta = CURRENT_TIMESTAMP
");
$stmtInsert->bind_param("ii", $conductorId, $viajeId);
$stmtInsert->execute();
$stmtInsert->close();

echo json_encode([
    "status" => "success",
    "found" => true,
    "solicitud" => [
        "viaje_id" => $viajeId,
        "pasajero_nombre" => $pasajeroNombre,
        "pasajero_telefono" => $pasajeroTelefono,
        "origen_texto" => $origenTexto,
        "destino_texto" => $destinoTexto,
        "distancia_km" => floatval($distanciaKm),
        "duracion_min" => intval($duracionMin),
        "tarifa_total" => floatval($tarifaTotal),
    ]
]);

$conn->close();
?>

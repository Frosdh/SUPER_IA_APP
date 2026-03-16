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
$viajeId = isset($_POST['viaje_id']) ? intval($_POST['viaje_id']) : 0;
$accion = isset($_POST['accion']) ? trim($_POST['accion']) : '';

if ($conductorId <= 0 || $viajeId <= 0 || ($accion !== 'aceptar' && $accion !== 'rechazar')) {
    echo json_encode(["status" => "error", "message" => "Datos invalidos"]);
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

if ($accion === 'rechazar') {
    $stmtReject = $conn->prepare("
        INSERT INTO solicitud_viajes (conductor_id, viaje_id, estado)
        VALUES (?, ?, 'rechazado')
        ON DUPLICATE KEY UPDATE estado = 'rechazado', fecha_oferta = CURRENT_TIMESTAMP
    ");
    $stmtReject->bind_param("ii", $conductorId, $viajeId);
    $stmtReject->execute();
    $stmtReject->close();

    echo json_encode([
        "status" => "success",
        "message" => "Solicitud rechazada"
    ]);
    $conn->close();
    exit;
}

$stmtTake = $conn->prepare("
    UPDATE viajes
    SET conductor_id = ?, estado = 'aceptado'
    WHERE id = ? AND conductor_id IS NULL AND estado = 'pedido'
");
$stmtTake->bind_param("ii", $conductorId, $viajeId);
$stmtTake->execute();
$affected = $stmtTake->affected_rows;
$stmtTake->close();

if ($affected <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "La solicitud ya no esta disponible"
    ]);
    $conn->close();
    exit;
}

$stmtAccept = $conn->prepare("
    INSERT INTO solicitud_viajes (conductor_id, viaje_id, estado)
    VALUES (?, ?, 'aceptado')
    ON DUPLICATE KEY UPDATE estado = 'aceptado', fecha_oferta = CURRENT_TIMESTAMP
");
$stmtAccept->bind_param("ii", $conductorId, $viajeId);
$stmtAccept->execute();
$stmtAccept->close();

$stmtDriver = $conn->prepare("UPDATE conductores SET estado = 'ocupado' WHERE id = ?");
$stmtDriver->bind_param("i", $conductorId);
$stmtDriver->execute();
$stmtDriver->close();

echo json_encode([
    "status" => "success",
    "message" => "Solicitud aceptada"
]);

$conn->close();
?>

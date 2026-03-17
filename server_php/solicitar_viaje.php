<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// solicitar_viaje.php - Crea un viaje nuevo con estado 'pedido'
// Colocar en: /fuber_api/solicitar_viaje.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$telefono      = isset($_POST['telefono'])      ? trim($_POST['telefono'])           : '';
$origen_texto  = isset($_POST['origen_texto'])  ? trim($_POST['origen_texto'])       : '';
$destino_texto = isset($_POST['destino_texto']) ? trim($_POST['destino_texto'])      : '';
$distancia_km  = isset($_POST['distancia_km'])  ? floatval($_POST['distancia_km'])   : 0;
$duracion_min  = isset($_POST['duracion_min'])  ? intval($_POST['duracion_min'])     : 0;
$tarifa_total  = isset($_POST['tarifa_total'])  ? floatval($_POST['tarifa_total'])   : 0;
$origen_lat    = isset($_POST['origen_lat'])    ? floatval($_POST['origen_lat'])     : null;
$origen_lng    = isset($_POST['origen_lng'])    ? floatval($_POST['origen_lng'])     : null;
$destino_lat   = isset($_POST['destino_lat'])   ? floatval($_POST['destino_lat'])    : null;
$destino_lng   = isset($_POST['destino_lng'])   ? floatval($_POST['destino_lng'])    : null;

if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
    exit;
}

// Buscar usuario por teléfono
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? AND activo = 1 LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$stmt->bind_result($usuario_id);
$usuarioEncontrado = $stmt->fetch();
$stmt->close();

if (!$usuarioEncontrado) {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    $conn->close();
    exit;
}

$categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 1;

// Crear el viaje con estado 'pedido'
$insert = $conn->prepare("
    INSERT INTO viajes
        (usuario_id, conductor_id, categoria_id,
         origen_texto, destino_texto,
         origen_lat, origen_lng, destino_lat, destino_lng,
         distancia_km, duracion_min, tarifa_total,
         estado, fecha_pedido)
    VALUES
        (?, NULL, ?,
         ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?,
         'pedido', NOW())
");

$insert->bind_param(
    "iissdddddid",
    $usuario_id, $categoria_id,
    $origen_texto, $destino_texto,
    $origen_lat, $origen_lng, $destino_lat, $destino_lng,
    $distancia_km, $duracion_min, $tarifa_total
);

if ($insert->execute()) {
    $viaje_id = $conn->insert_id;
    echo json_encode([
        "status"   => "success",
        "message"  => "Viaje creado correctamente",
        "viaje_id" => $viaje_id
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "Error al crear viaje: " . $insert->error
    ]);
}

$insert->close();
$conn->close();
?>

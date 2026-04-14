<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// obtener_viajes.php - Devuelve el historial de viajes
// Colocar en: /fuber_api/obtener_viajes.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
    exit;
}

// Buscar usuario por teléfono
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$result = $stmt->get_result();
$usuario = $result->fetch_assoc();
$stmt->close();

if (!$usuario) {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    $conn->close();
    exit;
}

$usuario_id = $usuario['id'];

// Obtener los viajes terminados más recientes (máx 50) con datos del conductor
$query = $conn->prepare("
    SELECT
        v.id,
        v.origen_texto,
        v.destino_texto,
        v.distancia_km,
        v.duracion_min,
        v.tarifa_total,
        v.fecha_pedido,
        v.fecha_fin,
        v.estado,
        v.calificacion,
        v.comentario,
        v.descuento,
        v.codigo_descuento,
        c.nombre        AS conductor_nombre,
        CONCAT(COALESCE(ve.marca,''), ' ', COALESCE(ve.modelo,'')) AS conductor_auto,
        ve.placa        AS conductor_placa,
        ve.color        AS conductor_color
    FROM viajes v
    LEFT JOIN conductores c  ON c.id = v.conductor_id
    LEFT JOIN vehiculos  ve ON ve.conductor_id = v.conductor_id
    WHERE v.usuario_id = ?
      AND v.estado = 'terminado'
    ORDER BY v.fecha_pedido DESC
    LIMIT 50
");

$query->bind_param("i", $usuario_id);
$query->execute();
$result = $query->get_result();

$viajes = [];
while ($row = $result->fetch_assoc()) {
    $viajes[] = [
        "id"               => intval($row['id']),
        "origen"           => $row['origen_texto']  ?? '',
        "destino"          => $row['destino_texto'] ?? '',
        "distancia_km"     => floatval($row['distancia_km']),
        "duracion_min"     => intval($row['duracion_min']),
        "precio"           => floatval($row['tarifa_total']),
        "fecha"            => $row['fecha_pedido'],
        "conductor_nombre" => trim($row['conductor_nombre'] ?? ''),
        "conductor_auto"   => trim($row['conductor_auto']   ?? ''),
        "conductor_placa"  => trim($row['conductor_placa']  ?? ''),
        "conductor_color"  => trim($row['conductor_color']  ?? ''),
        "calificacion"     => $row['calificacion'] !== null ? floatval($row['calificacion']) : 0,
        "comentario"       => $row['comentario']       ?? '',
        "descuento"        => floatval($row['descuento'] ?? 0),
        "codigo_descuento" => $row['codigo_descuento'] ?? '',
    ];
}

$query->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "viajes" => $viajes
]);
?>

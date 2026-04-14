<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// estado_viaje.php - Devuelve el estado actual de un viaje
// Colocar en: /fuber_api/estado_viaje.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$viaje_id = isset($_POST['viaje_id']) ? intval($_POST['viaje_id']) : 0;

if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id invalido"]);
    exit;
}

// Obtener el estado del viaje junto con info del conductor (si esta asignado).
// Incluye un ETA estimado usando la ubicacion del conductor vs el punto de recogida.
$stmt = $conn->prepare("
    SELECT
        v.estado,
        v.tarifa_total,
        v.distancia_km,
        v.duracion_min,
        v.origen_lat,
        v.origen_lng,
        c.id      AS conductor_id,
        c.nombre  AS conductor_nombre,
        c.telefono AS conductor_telefono,
        c.calificacion_promedio AS conductor_calificacion,
        c.latitud AS conductor_latitud,
        c.longitud AS conductor_longitud,
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
$stmt->bind_result(
    $estado,
    $tarifaTotal,
    $distanciaKm,
    $duracionMin,
    $origenLat,
    $origenLng,
    $conductorId,
    $conductorNombre,
    $conductorTelefono,
    $conductorCalificacion,
    $conductorLat,
    $conductorLng,
    $autoMarca,
    $autoModelo,
    $autoColor,
    $autoPlaca
);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    echo json_encode(["status" => "error", "message" => "Viaje no encontrado"]);
    $conn->close();
    exit;
}

$auto = '';
if (!empty($autoMarca) || !empty($autoModelo)) {
    $auto = trim($autoMarca . ' ' . $autoModelo);
}

$viajesConductor = 0;
if (!empty($conductorId)) {
    $stmtTrips = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM viajes
        WHERE conductor_id = ? AND estado = 'terminado'
    ");
    if ($stmtTrips) {
        $stmtTrips->bind_param("i", $conductorId);
        $stmtTrips->execute();
        $stmtTrips->bind_result($viajesConductor);
        $stmtTrips->fetch();
        $stmtTrips->close();
    }
}

$etaMin = 0;
if (!empty($conductorLat) && !empty($conductorLng) && !empty($origenLat) && !empty($origenLng)) {
    // Distancia Haversine (km)
    $earthRadius = 6371.0;
    $dLat = deg2rad(((double)$origenLat) - ((double)$conductorLat));
    $dLng = deg2rad(((double)$origenLng) - ((double)$conductorLng));
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad((double)$conductorLat)) * cos(deg2rad((double)$origenLat)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $distKm = $earthRadius * $c;

    // Velocidad promedio estimada 25 km/h
    $etaMin = (int)round(($distKm / 25.0) * 60.0);
    if ($etaMin < 1) $etaMin = 1;
    if ($etaMin > 99) $etaMin = 99;
}

$conn->close();

echo json_encode([
    "status"  => "success",
    "estado"  => $estado,
    "conductor" => [
        "id"           => intval($conductorId ?? 0),
        "nombre"       => $conductorNombre ?? '',
        "telefono"     => $conductorTelefono ?? '',
        "calificacion" => floatval($conductorCalificacion ?? 5.0),
        "viajes"       => intval($viajesConductor ?? 0),
        "auto"         => $auto,
        "color"        => $autoColor ?? '',
        "placa"        => $autoPlaca ?? '',
        "eta_min"      => intval($etaMin ?? 0),
        "latitud"      => floatval($conductorLat ?? 0),
        "longitud"     => floatval($conductorLng ?? 0),
    ]
]);
?>

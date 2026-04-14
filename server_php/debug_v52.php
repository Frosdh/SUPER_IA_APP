<?php
require_once __DIR__ . '/db_config.php';
header("Content-Type: application/json");

// Diagnóstico de viaje y conductor
$viajeId = 52;
$conductorId = 14;

$res = [];

// 1. Datos del viaje
$stmtV = $conn->prepare("SELECT id, origen_lat, origen_lng, estado FROM viajes WHERE id = ?");
$stmtV->bind_param("i", $viajeId);
$stmtV->execute();
$res['viaje'] = $stmtV->get_result()->fetch_assoc();
$stmtV->close();

// 2. Datos del conductor
$stmtC = $conn->prepare("SELECT id, nombre, estado, latitud, longitud, token_fcm FROM conductores WHERE id = ?");
$stmtC->bind_param("i", $conductorId);
$stmtC->execute();
$res['conductor'] = $stmtC->get_result()->fetch_assoc();
$stmtC->close();

// 3. Cálculo de distancia (Manual)
if ($res['viaje'] && $res['conductor']) {
    $lat1 = $res['viaje']['origen_lat'];
    $lng1 = $res['viaje']['origen_lng'];
    $lat2 = $res['conductor']['latitud'];
    $lng2 = $res['conductor']['longitud'];
    
    $radio = 6371 * acos(
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        cos(deg2rad($lng2) - deg2rad($lng1)) +
        sin(deg2rad($lat1)) * sin(deg2rad($lat2))
    );
    $res['distancia_calculada_km'] = $radio;
    $res['dentro_del_radio_6km'] = ($radio <= 6);
}

echo json_encode($res, JSON_PRETTY_PRINT);
?>

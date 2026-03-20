<?php
require_once __DIR__ . '/db_config.php';
header("Content-Type: application/json");

// debug_v57.php - Analiza por qué no se encontraron conductores para el viaje 57
$viaje_id = 57;

// 1. Obtener datos del viaje
$resV = $conn->query("SELECT * FROM viajes WHERE id = $viaje_id");
$viaje = $resV->fetch_assoc();

if (!$viaje) {
    die(json_encode(["error" => "Viaje $viaje_id no encontrado"]));
}

$latP = $viaje['origen_lat'];
$lngP = $viaje['origen_lng'];
$catP = $viaje['categoria_id'];

// 2. Ver conductores "cercanos" comparando tablas conductores y vehiculos
$sql = "
    SELECT c.id, c.nombre, c.estado, c.latitud, c.longitud, c.categoria_id as cat_conductor, 
           IFNULL(v.categoria_id, 'NO TIENE') as cat_vehiculo, c.token_fcm,
    (6371 * ACOS(COS(RADIANS($latP)) * COS(RADIANS(c.latitud)) * COS(RADIANS(c.longitud) - RADIANS($lngP)) + SIN(RADIANS($latP)) * SIN(RADIANS(c.latitud)))) AS distancia
    FROM conductores c
    LEFT JOIN vehiculos v ON v.conductor_id = c.id
";
$resC = $conn->query($sql);
$analisis = [];
while ($c = $resC->fetch_assoc()) {
    $c['dentro_del_radio'] = ($c['distancia'] <= 6.0);
    $c['categoria_correcta'] = ($c['cat_conductor'] == $catP || $catP == 0);
    $c['vehiculo_categoria_correcta'] = ($c['cat_vehiculo'] == $catP || $catP == 0);
    $c['esta_libre'] = ($c['estado'] == 'libre');
    $c['tiene_token'] = (!empty($c['token_fcm']));
    
    $analisis[] = $c;
}

echo json_encode([
    "viaje_contexto" => [
        "id" => $viaje_id,
        "lat" => $latP,
        "lng" => $lngP,
        "categoria_esperada" => $catP
    ],
    "conductores_analizados" => $analisis
], JSON_PRETTY_PRINT);
?>

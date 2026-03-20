<?php
require_once __DIR__ . '/db_config.php';
header("Content-Type: application/json");

// check_gpx_data.php - Verifica si hay datos en la tabla de rutas
try {
    $resCount = $conn->query("SELECT COUNT(*) as total FROM conductores_rutas");
    if (!$resCount) {
        die(json_encode(["error" => "La tabla conductores_rutas no existe o tiene errores: " . $conn->error]));
    }
    $total = $resCount->fetch_assoc()['total'];

    $resUltimos = $conn->query("
        SELECT r.*, c.nombre 
        FROM conductores_rutas r
        JOIN conductores c ON c.id = r.conductor_id
        ORDER BY r.id DESC 
        LIMIT 5
    ");
    $ultimos = [];
    while ($row = $resUltimos->fetch_assoc()) {
        $ultimos[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "total_puntos" => $total,
        "ultimos_puntos" => $ultimos
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>

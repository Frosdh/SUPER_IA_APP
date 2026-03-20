<?php
require_once __DIR__ . '/db_config.php';
header("Content-Type: application/json");

// Obtener los últimos 10 logs de FCM
$result = $conn->query("SELECT * FROM fcm_debug_logs ORDER BY id DESC LIMIT 10");
$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}

echo json_encode($logs, JSON_PRETTY_PRINT);
?>

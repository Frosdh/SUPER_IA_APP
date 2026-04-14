<?php
// ============================================================
// chat_mensajes.php — Devuelve mensajes de chat de un viaje
// GET  ?viaje_id=123&ultimo_id=0
// Retorna solo los mensajes con id > ultimo_id (polling incremental)
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once __DIR__ . '/db_config.php';

$viaje_id  = (int)($_GET['viaje_id']  ?? 0);
$ultimo_id = (int)($_GET['ultimo_id'] ?? 0);

if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id requerido"]);
    exit;
}

$sql = "SELECT id, remitente, mensaje, DATE_FORMAT(fecha, '%Y-%m-%dT%H:%i:%s') AS fecha
        FROM chat_mensajes
        WHERE viaje_id = ? AND id > ?
        ORDER BY id ASC
        LIMIT 50";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $viaje_id, $ultimo_id);
$stmt->execute();
$result = $stmt->get_result();

$mensajes = [];
while ($row = $result->fetch_assoc()) {
    $mensajes[] = [
        "id"        => (int)$row['id'],
        "remitente" => $row['remitente'],
        "mensaje"   => $row['mensaje'],
        "fecha"     => $row['fecha'],
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    "status"   => "success",
    "mensajes" => $mensajes,
    "total"    => count($mensajes),
]);
?>

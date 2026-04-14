<?php
// ============================================================
// obtener_tickets.php — El pasajero consulta sus tickets
// POST: telefono
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/db_config.php';

$telefono = trim($_POST['telefono'] ?? $_GET['telefono'] ?? '');

if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "Teléfono requerido"]);
    exit;
}

// Buscar usuario
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? AND activo = 1 LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$stmt->bind_result($usuario_id);
if (!$stmt->fetch()) {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    $conn->close(); exit;
}
$stmt->close();

// Obtener tickets del usuario
$stmtT = $conn->prepare("
    SELECT id, tipo, asunto, mensaje, estado, respuesta, respondido_en, creado_en
    FROM tickets_soporte
    WHERE usuario_id = ?
    ORDER BY creado_en DESC
    LIMIT 20
");
$stmtT->bind_param("i", $usuario_id);
$stmtT->execute();
$result = $stmtT->get_result();

$tickets = [];
while ($row = $result->fetch_assoc()) {
    $tickets[] = $row;
}
$stmtT->close();
$conn->close();

echo json_encode([
    "status"  => "success",
    "tickets" => $tickets
]);
?>

<?php
// ============================================================
// crear_ticket.php — El pasajero envía un ticket de soporte
// POST: telefono, tipo, asunto, mensaje, viaje_id (opcional)
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit;
}

$telefono = trim($_POST['telefono'] ?? '');
$tipo     = trim($_POST['tipo']     ?? 'otro');
$asunto   = trim($_POST['asunto']   ?? '');
$mensaje  = trim($_POST['mensaje']  ?? '');
$viaje_id = isset($_POST['viaje_id']) && $_POST['viaje_id'] !== '' ? intval($_POST['viaje_id']) : null;

// Validaciones
if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "Teléfono requerido"]);
    exit;
}
if (empty($asunto)) {
    echo json_encode(["status" => "error", "message" => "El asunto es requerido"]);
    exit;
}
if (empty($mensaje)) {
    echo json_encode(["status" => "error", "message" => "El mensaje es requerido"]);
    exit;
}

$tiposValidos = ['problema_tecnico', 'pago', 'conductor', 'cuenta', 'otro'];
if (!in_array($tipo, $tiposValidos)) $tipo = 'otro';

// Buscar usuario por teléfono
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? AND activo = 1 LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$stmt->bind_result($usuario_id);
if (!$stmt->fetch()) {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    $conn->close(); exit;
}
$stmt->close();

// Limitar: máximo 3 tickets abiertos por usuario
$stmtLimit = $conn->prepare("
    SELECT COUNT(*) FROM tickets_soporte
    WHERE usuario_id = ? AND estado IN ('abierto', 'en_proceso')
");
$stmtLimit->bind_param("i", $usuario_id);
$stmtLimit->execute();
$stmtLimit->bind_result($abiertos);
$stmtLimit->fetch();
$stmtLimit->close();

if ($abiertos >= 3) {
    echo json_encode([
        "status"  => "error",
        "message" => "Ya tienes 3 tickets abiertos. Espera a que sean resueltos antes de crear uno nuevo."
    ]);
    $conn->close(); exit;
}

// Insertar ticket
$stmtIns = $conn->prepare("
    INSERT INTO tickets_soporte (usuario_id, viaje_id, tipo, asunto, mensaje)
    VALUES (?, ?, ?, ?, ?)
");
$stmtIns->bind_param("iisss", $usuario_id, $viaje_id, $tipo, $asunto, $mensaje);

if ($stmtIns->execute()) {
    $ticket_id = $conn->insert_id;
    echo json_encode([
        "status"    => "success",
        "message"   => "Tu ticket fue enviado. Te responderemos pronto.",
        "ticket_id" => $ticket_id
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Error al crear el ticket"]);
}

$stmtIns->close();
$conn->close();
?>

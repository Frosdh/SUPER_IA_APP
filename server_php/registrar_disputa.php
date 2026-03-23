<?php
// ============================================================
// registrar_disputa.php — Registra una disputa/reclamación de cobro
// POST JSON: {
//   "viaje_id": 123,
//   "motivo": "cobro_incorrecto",
//   "descripcion": "texto libre",
//   "telefono_usuario": "0991234567"
// }
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

// Leer body JSON o POST form
$input       = json_decode(file_get_contents("php://input"), true) ?? [];
$viaje_id    = (int)($input['viaje_id']         ?? $_POST['viaje_id']         ?? 0);
$motivo      = trim($input['motivo']             ?? $_POST['motivo']             ?? '');
$descripcion = trim($input['descripcion']        ?? $_POST['descripcion']        ?? '');
$telefono    = trim($input['telefono_usuario']   ?? $_POST['telefono_usuario']   ?? '');

// Motivos válidos (deben coincidir con DisputeScreen.dart)
$motivosValidos = [
    'cobro_incorrecto',
    'viaje_cancelado',
    'doble_cobro',
    'no_viaje',
    'otro',
];

if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id inválido"]);
    exit;
}
if (!in_array($motivo, $motivosValidos)) {
    echo json_encode(["status" => "error", "message" => "motivo no válido"]);
    exit;
}

// Verificar que no exista ya una disputa abierta para este viaje
$sql_check = "SELECT id FROM disputas WHERE viaje_id = ? AND estado IN ('abierta','en_revision') LIMIT 1";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->bind_param("i", $viaje_id);
$stmt_check->execute();
$stmt_check->store_result();
if ($stmt_check->num_rows > 0) {
    $stmt_check->close();
    $conn->close();
    echo json_encode([
        "status"  => "success", // Devolvemos success para no bloquear la UX del usuario
        "message" => "Ya existe una disputa abierta para este viaje",
        "duplicado" => true,
    ]);
    exit;
}
$stmt_check->close();

// Insertar disputa
$sql = "INSERT INTO disputas (viaje_id, motivo, descripcion, telefono_usuario) VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isss", $viaje_id, $motivo, $descripcion, $telefono);

if ($stmt->execute()) {
    $disputa_id = $stmt->insert_id;
    $stmt->close();

    // ── Notificación interna (opcional) ─────────────────────
    // Si quieres alertar al admin por email cuando llega una disputa,
    // descomenta y configura:
    /*
    try {
        require_once __DIR__ . '/email_helper.php';
        enviarEmailAdmin(
            "Nueva disputa #$disputa_id",
            "Viaje $viaje_id | Motivo: $motivo\n\n$descripcion"
        );
    } catch (Exception $e) {}
    */

    $conn->close();
    echo json_encode([
        "status"     => "success",
        "message"    => "Disputa registrada correctamente. Te contactaremos en 24-48 horas.",
        "disputa_id" => $disputa_id,
    ]);
} else {
    $stmt->close();
    $conn->close();
    echo json_encode(["status" => "error", "message" => "No se pudo registrar la disputa"]);
}
?>

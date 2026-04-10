<?php
// ============================================================
// actualizar_token_fcm_asesor.php — Guarda token FCM del asesor
// POST: usuario_id (UUID), token_fcm (string)
// Esquema: SUPER_IA LOGAN
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/db_config.php';

// Asegurar columna token_fcm (MySQL 8+)
$conn->query("ALTER TABLE usuario ADD COLUMN IF NOT EXISTS token_fcm VARCHAR(500) DEFAULT NULL");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$usuario_id = trim($_POST['usuario_id'] ?? '');
$token_fcm  = trim($_POST['token_fcm']  ?? '');

if ($usuario_id === '' || $token_fcm === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id y token_fcm son requeridos']);
    exit;
}

$stmt = $conn->prepare("UPDATE usuario SET token_fcm = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param('ss', $token_fcm, $usuario_id);

if ($stmt->execute() && $stmt->affected_rows >= 0) {
    echo json_encode(['status' => 'success', 'message' => 'Token FCM actualizado']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo actualizar: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>

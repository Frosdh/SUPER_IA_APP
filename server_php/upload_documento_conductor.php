<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// upload_documento_conductor.php
// Sube o actualiza la foto de un documento del conductor
// POST: conductor_id, tipo, imagen_base64
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit;
}

$conductor_id  = isset($_POST['conductor_id'])  ? intval($_POST['conductor_id'])      : 0;
$tipo          = isset($_POST['tipo'])          ? trim($_POST['tipo'])                : '';
$imagen_base64 = isset($_POST['imagen_base64']) ? trim($_POST['imagen_base64'])       : '';

$tipos_validos = ['licencia_frente','licencia_reverso','cedula','soat','matricula','foto_perfil'];

if ($conductor_id <= 0) {
    echo json_encode(["status" => "error", "message" => "conductor_id inválido"]);
    exit;
}
if (!in_array($tipo, $tipos_validos)) {
    echo json_encode(["status" => "error", "message" => "Tipo de documento inválido: $tipo"]);
    exit;
}
if (empty($imagen_base64)) {
    echo json_encode(["status" => "error", "message" => "Imagen vacía"]);
    exit;
}

// Verificar que el conductor existe
$chk = $conn->prepare("SELECT id FROM conductores WHERE id = ? LIMIT 1");
$chk->bind_param("i", $conductor_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    $conn->close();
    echo json_encode(["status" => "error", "message" => "Conductor no encontrado"]);
    exit;
}
$chk->close();

// Si es foto_perfil, actualiza directo en conductores
if ($tipo === 'foto_perfil') {
    $stmt = $conn->prepare("UPDATE conductores SET foto_perfil = ? WHERE id = ?");
    $stmt->bind_param("si", $imagen_base64, $conductor_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    echo json_encode(["status" => "success", "message" => "Foto de perfil guardada"]);
    exit;
}

// Para documentos: INSERT … ON DUPLICATE KEY UPDATE
$stmt = $conn->prepare("
    INSERT INTO documentos_conductor (conductor_id, tipo, imagen, estado)
    VALUES (?, ?, ?, 'pendiente')
    ON DUPLICATE KEY UPDATE imagen = VALUES(imagen), estado = 'pendiente', updated_at = NOW()
");
$stmt->bind_param("iss", $conductor_id, $tipo, $imagen_base64);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Documento guardado"]);
} else {
    echo json_encode(["status" => "error", "message" => "Error al guardar: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>

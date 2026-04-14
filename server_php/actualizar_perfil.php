<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// actualizar_perfil.php - Actualiza nombre y email del usuario
// Colocar en: /fuber_api/actualizar_perfil.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$nombre   = isset($_POST['nombre'])   ? trim($_POST['nombre'])   : '';
$email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';

if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "Telefono requerido"]);
    exit;
}

if (empty($nombre)) {
    echo json_encode(["status" => "error", "message" => "El nombre no puede estar vacío"]);
    exit;
}

$stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE telefono = ?");
$stmt->bind_param("sss", $nombre, $email, $telefono);

if ($stmt->execute()) {
    if ($stmt->affected_rows >= 0) {
        echo json_encode([
            "status"  => "success",
            "message" => "Perfil actualizado correctamente",
            "nombre"  => $nombre,
            "email"   => $email,
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Error al actualizar: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>

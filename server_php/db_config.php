<?php
// ============================================================
// db_config.php — Conexión centralizada a la base de datos
// NUNCA subir este archivo a repositorios públicos (GitHub, etc.)
// Agregar a .gitignore: server_php/db_config.php
// ============================================================

$db_host     = "localhost";
$db_name     = "base_super_ia";
$db_user     = "root";
$db_password = '';

$conn = new mysqli($db_host, $db_user, $db_password, $db_name);

if ($conn->connect_error) {
    header("Content-Type: application/json");
    echo json_encode([
        "status"  => "error",
        "message" => "Error de conexión a la base de datos"
    ]);
    exit;
}

$conn->set_charset("utf8mb4");
?>

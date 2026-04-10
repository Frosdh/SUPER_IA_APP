<?php
// update_documento_to_legacy.php — actualizar documento_path a archivo legacy para un usuario
require_once __DIR__ . '/../db_config.php';

$usuario_id = $argv[1] ?? null;
$legacy_filename = $argv[2] ?? null;

if (!$usuario_id || !$legacy_filename) {
    echo "Uso: php update_documento_to_legacy.php <usuario_id> <legacy_filename>\n";
    echo "Ejemplo: php update_documento_to_legacy.php usr_69d80652e63ba2.40018101 asesor_edwins_1775675340.pdf\n";
    exit(1);
}

$mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);
if ($mysqli->connect_error) {
    die('Conexión fallida: ' . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');

$path = 'uploads/asesor_credentials/' . $mysqli->real_escape_string($legacy_filename);
$sql = "UPDATE asesor SET documento_path = '" . $mysqli->real_escape_string($path) . "' WHERE usuario_id = '" . $mysqli->real_escape_string($usuario_id) . "'";

if ($mysqli->query($sql)) {
    echo "OK: documento_path actualizado a $path para usuario_id=$usuario_id\n";
} else {
    echo "ERROR: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
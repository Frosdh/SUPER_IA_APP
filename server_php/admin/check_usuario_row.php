<?php
/**
 * check_usuario_row.php
 * Usage: php check_usuario_row.php <usuario_id>
 */
require_once __DIR__ . '/../db_config.php';

$id = $argv[1] ?? null;
if (!$id) {
    echo json_encode(["error" => "usage: php check_usuario_row.php <usuario_id>"], JSON_PRETTY_PRINT);
    exit(1);
}

$stmt = $conn->prepare("SELECT id, nombre, email, activo, estado_aprobacion FROM usuario WHERE id = ? LIMIT 1");
$stmt->bind_param('s', $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

echo json_encode($row ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>

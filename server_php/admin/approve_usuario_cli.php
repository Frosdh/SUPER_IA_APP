<?php
/**
 * approve_usuario_cli.php
 * Usage: php approve_usuario_cli.php <usuario_id>
 */
require_once __DIR__ . '/../db_config.php';

$id = $argv[1] ?? null;
if (!$id) {
    echo "usage: php approve_usuario_cli.php <usuario_id>\n";
    exit(1);
}

$stmt = $conn->prepare("UPDATE usuario SET activo = 1, estado_aprobacion = 'aprobado' WHERE id = ?");
$stmt->bind_param('s', $id);
if ($stmt->execute()) {
    echo "OK: usuario $id actualizado a activo=1, estado_aprobacion=aprobado\n";
} else {
    echo "ERROR: " . $stmt->error . "\n";
}

?>

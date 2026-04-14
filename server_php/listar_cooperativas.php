<?php
require_once 'db_config.php';

$conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
$conexion->set_charset('utf8mb4');

$result = $conexion->query('SELECT COUNT(*) as total FROM unidad_bancaria WHERE activo = 1');
$row = $result->fetch_assoc();
echo "Total cooperativas activas: " . $row['total'] . "\n";

echo "\nListado de cooperativas:\n";
$all = $conexion->query('SELECT id, nombre, codigo FROM unidad_bancaria WHERE activo = 1 ORDER BY nombre');
while ($r = $all->fetch_assoc()) {
    echo "- " . $r['nombre'] . " (" . $r['codigo'] . ")\n";
}

$conexion->close();
?>

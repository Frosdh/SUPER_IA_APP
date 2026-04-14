<?php
require_once 'db_config.php';

$c = new mysqli($db_host, $db_user, $db_password, $db_name);
$c->set_charset('utf8mb4');

echo "<h2>Listado COMPLETO de cooperativas/bancos en la base:</h2>";

$r = $c->query('SELECT id, nombre, codigo, activo FROM unidad_bancaria ORDER BY nombre');
echo "<p><strong>Total: " . $r->num_rows . " registros</strong></p>";
echo "<table border='1' cellpadding='10' style='border-collapse:collapse'>";
echo "<tr><th>Estado</th><th>Nombre</th><th>Código</th><th>ID</th></tr>";

while ($row = $r->fetch_assoc()) {
    $estado = $row['activo'] ? '✅ Activo' : '❌ Inactivo';
    echo "<tr>";
    echo "<td style='background:".($row['activo'] ? '#90EE90' : '#FFB6C6')."'>" . $estado . "</td>";
    echo "<td>" . $row['nombre'] . "</td>";
    echo "<td>" . $row['codigo'] . "</td>";
    echo "<td style='font-size:11px'>" . $row['id'] . "</td>";
    echo "</tr>";
}

echo "</table>";

$c->close();
?>

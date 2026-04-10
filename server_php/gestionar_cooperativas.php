<?php
require_once 'db_config.php';

$c = new mysqli($db_host, $db_user, $db_password, $db_name);
$c->set_charset('utf8mb4');

echo "<h2>COOPERATIVAS EN BASE DE DATOS</h2>";

// Contar todas
$total = $c->query('SELECT COUNT(*) as cnt FROM unidad_bancaria')->fetch_assoc();
echo "<p><strong>Total de cooperativas: " . $total['cnt'] . "</strong></p>";

echo "<h3>Estado actual:</h3>";
$r = $c->query('SELECT COUNT(*) as activas FROM unidad_bancaria WHERE activo = 1');
$row = $r->fetch_assoc();
echo "- Activas: " . $row['activas'] . "<br>";

$r = $c->query('SELECT COUNT(*) as inactivas FROM unidad_bancaria WHERE activo = 0');
$row = $r->fetch_assoc();
echo "- Inactivas: " . $row['inactivas'] . "<br>";

echo "<h3>Listado:</h3>";
echo "<table border='1' cellpadding='8' style='border-collapse:collapse; font-size:14px'>";
echo "<tr style='background:#333; color:white'><th>Nombre</th><th>Código</th><th>Estado</th></tr>";

$r = $c->query('SELECT nombre, codigo, activo FROM unidad_bancaria ORDER BY nombre');
while ($row = $r->fetch_assoc()) {
    $bg = $row['activo'] ? '#90EE90' : '#FFB6C6';
    $estado = $row['activo'] ? '✅ Activo' : '❌ Inactivo';
    echo "<tr style='background:$bg'>";
    echo "<td>" . $row['nombre'] . "</td>";
    echo "<td>" . $row['codigo'] . "</td>";
    echo "<td>" . $estado . "</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Acciones:</h3>";
echo "<p><a href='?action=activar_todas' style='padding:10px; background:#003D7A; color:white; text-decoration:none; font-weight:bold'>✅ ACTIVAR TODAS</a></p>";

if ($_GET['action'] == 'activar_todas') {
    $c->query('UPDATE unidad_bancaria SET activo = 1');
    echo "<div style='background:#90EE90; padding:10px; margin-top:10px'><strong>✅ Todas las cooperativas han sido activadas</strong></div>";
}

$c->close();
?>

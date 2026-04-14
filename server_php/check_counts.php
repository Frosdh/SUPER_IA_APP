<?php
require_once 'db_config.php';

$tables = ['clientes', 'operacion_credito', 'gestiones_cobranza', 'usuarios', 'alertas'];

foreach ($tables as $tabla) {
    $result = $conn->query("SELECT COUNT(*) as total FROM $tabla");
    $row = $result->fetch_assoc();
    echo "Total $tabla: " . $row['total'] . "\n";
}

$conn->close();
?>

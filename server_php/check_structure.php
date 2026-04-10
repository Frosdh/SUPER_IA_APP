<?php
require_once 'db_config.php';

echo "=== ESTRUCTURA DE TABLAS ===\n\n";

$tablas = ['activos', 'pasivos', 'operacion_credito', 'gestiones_cobranza', 'region', 'roles', 'clientes'];

foreach ($tablas as $tabla) {
    $query = "DESCRIBE $tabla";
    $result = $conn->query($query);
    
    echo "Tabla: $tabla\n";
    echo "=====================================\n";
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "  ✗ Tabla no existe\n";
    }
    echo "\n";
}

$conn->close();
?>

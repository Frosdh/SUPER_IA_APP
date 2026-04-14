<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    $conexion->set_charset('utf8mb4');

    // Revisar estructura de todas las tablas necesarias
    $tables = ['zona', 'agencia', 'jefe_agencia', 'supervisor', 'usuario'];
    
    foreach ($tables as $table) {
        echo "<h3>Estructura de: $table</h3>";
        $result = $conexion->query("DESCRIBE $table");
        echo "<pre>";
        while ($row = $result->fetch_assoc()) {
            echo $row['Field'] . " | " . $row['Type'] . " | " . $row['Null'] . " | " . $row['Key'] . "\n";
        }
        echo "</pre>";
    }
    
    $conexion->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>

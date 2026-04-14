<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    $conexion->set_charset('utf8mb4');

    echo "<h2>Buscando columna unidad_bancaria_id en agencia:</h2>";
    $result = $conexion->query("DESCRIBE agencia");
    $campos = [];
    while ($row = $result->fetch_assoc()) {
        $campos[] = $row['Field'];
        echo $row['Field'] . "<br>";
    }
    
    if (!in_array('unidad_bancaria_id', $campos)) {
        echo "<h2 style='color:red'>❌ La tabla agencia NO tiene unidad_bancaria_id</h2>";
        echo "<p>¿Hay autre manera de conectar agencia con unidad_bancaria?</p>";
        
        // Buscar todas las tablas y sus relaciones
        echo "<h3>Todas las tablas en base_super_ia:</h3>";
        $tables = $conexion->query("SHOW TABLES");
        while ($table = $tables->fetch_row()) {
            echo $table[0] . "<br>";
        }
    }
    
    $conexion->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>

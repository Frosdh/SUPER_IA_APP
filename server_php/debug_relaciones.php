<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    $conexion->set_charset('utf8mb4');

    // Ver todas las tablas
    echo "<h2>Todas las tablas:</h2>";
    $tables = $conexion->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='corporat_base_super_ia'");
    while ($row = $tables->fetch_assoc()) {
        echo $row['TABLE_NAME'] . "<br>";
    }

    // Ver si existe una tabla que relacione unidad_bancaria con agencia/zona
    echo "<h2>Tablas que contienen 'unidad' o 'bancaria':</h2>";
    $search = $conexion->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='corporat_base_super_ia' AND (TABLE_NAME LIKE '%unidad%' OR TABLE_NAME LIKE '%bancaria%')");
    while ($row = $search->fetch_assoc()) {
        echo $row['TABLE_NAME'] . "<br>";
    }

    // Ver columnas de zona
    echo "<h2>Columnas de zona (para ver si se conecta con unidad_bancaria):</h2>";
    $cols = $conexion->query("DESCRIBE zona");
    while ($row = $cols->fetch_assoc()) {
        echo $row['Field'] . " | " . $row['Type'] . "<br>";
    }

    $conexion->close();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>

<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($conexion->connect_error) {
        throw new Exception("Conexión fallida: " . $conexion->connect_error);
    }
    $conexion->set_charset('utf8mb4');

    echo "<h2>Estructura de la tabla unidad_bancaria:</h2>";
    $columns = $conexion->query("DESCRIBE unidad_bancaria");
    echo "<pre>";
    while ($col = $columns->fetch_assoc()) {
        print_r($col);
    }
    echo "</pre>";

    echo "<h2>Todas las cooperativas (sin filtro):</h2>";
    $all = $conexion->query("SELECT * FROM unidad_bancaria");
    if (!$all) {
        echo "Error: " . $conexion->error;
    } else {
        echo "Total registros: " . $all->num_rows . "<br>";
        while ($row = $all->fetch_assoc()) {
            echo "<pre>";
            print_r($row);
            echo "</pre>";
        }
    }

    echo "<h2>Cooperativas con activo = 1:</h2>";
    $active = $conexion->query("SELECT * FROM unidad_bancaria WHERE activo = 1");
    if (!$active) {
        echo "Error: " . $conexion->error;
    } else {
        echo "Total registros activos: " . $active->num_rows . "<br>";
        while ($row = $active->fetch_assoc()) {
            echo "<pre>";
            print_r($row);
            echo "</pre>";
        }
    }

    $conexion->close();
    
} catch (Exception $e) {
    echo "<h2>❌ Error: " . $e->getMessage() . "</h2>";
}
?>

<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
    $conexion->set_charset('utf8mb4');

    // Agregar columna unidad_bancaria_id a agencia si no existe
    echo "<h2>Agregando relación unidad_bancaria a tabla agencia...</h2>";
    
    $result = $conexion->query("DESCRIBE agencia");
    $campos = [];
    while ($row = $result->fetch_assoc()) {
        $campos[] = $row['Field'];
    }
    
    if (!in_array('unidad_bancaria_id', $campos)) {
        echo "❌ Columna no existe. Creándola...<br>";
        $alter_query = "ALTER TABLE agencia ADD COLUMN unidad_bancaria_id CHAR(36) AFTER zona_id";
        if ($conexion->query($alter_query)) {
            echo "✅ Columna agregada exitosamente<br>";
        } else {
            throw new Exception("Error al agregar columna: " . $conexion->error);
        }
    } else {
        echo "✅ Columna unidad_bancaria_id ya existe<br>";
    }

    // Ahora actualizar la agencia que creamos para que tenga la unidad_bancaria_id
    $coop = $conexion->query("SELECT id FROM unidad_bancaria LIMIT 1")->fetch_assoc();
    $coop_id = $coop['id'];
    
    $update_query = "UPDATE agencia SET unidad_bancaria_id = '$coop_id' WHERE unidad_bancaria_id IS NULL";
    if ($conexion->query($update_query)) {
        $affected = $conexion->affected_rows;
        echo "✅ $affected agencia(s) actualizadas con unidad_bancaria_id<br>";
    } else {
        throw new Exception("Error al actualizar agencia: " . $conexion->error);
    }

    // Verificar
    $verify = $conexion->query("SELECT id, nombre, unidad_bancaria_id FROM agencia");
    echo "<h3>Agencias después de actualización:</h3>";
    while ($row = $verify->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Nombre: " . $row['nombre'] . " | unidad_bancaria_id: " . ($row['unidad_bancaria_id'] ?? 'NULL') . "<br>";
    }

    $conexion->close();
} catch (Exception $e) {
    echo "<h2>❌ Error: " . $e->getMessage() . "</h2>";
}
?>

<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
    $conexion->set_charset('utf8mb4');

    // Verificar si la columna existe
    $result = $conexion->query("DESCRIBE asesor");
    $campos = [];
    while ($row = $result->fetch_assoc()) {
        $campos[] = $row['Field'];
    }

    if (!in_array('documento_path', $campos)) {
        echo "Agregando columna documento_path a la tabla asesor...\n";
        $alter_query = "ALTER TABLE asesor ADD COLUMN documento_path VARCHAR(255) NULL AFTER created_at";
        if ($conexion->query($alter_query)) {
            echo "✅ Columna documento_path agregada\n";
        } else {
            throw new Exception("Error: " . $conexion->error);
        }
    } else {
        echo "✅ Columna documento_path ya existe\n";
    }

    $conexion->close();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

<?php
require_once 'db_config.php';
$conn = new mysqli($db_host, $db_user, $db_password, $db_name);
$conn->set_charset('utf8mb4');

echo "=== AGREGANDO COLUMNA 'telefono' A TABLA 'usuario' ===\n\n";

// Verificar si la columna ya existe
$result = $conn->query("SHOW COLUMNS FROM usuario LIKE 'telefono'");
if ($result && $result->num_rows > 0) {
    echo "✅ La columna 'telefono' ya existe.\n";
} else {
    echo "❌ La columna 'telefono' NO existe. Agregando...\n";
    
    // Agregar la columna
    $sql = "ALTER TABLE usuario ADD COLUMN telefono VARCHAR(20) AFTER email";
    
    if ($conn->query($sql)) {
        echo "✅ Columna 'telefono' agregada exitosamente.\n";
    } else {
        echo "❌ Error al agregar columna: " . $conn->error . "\n";
    }
}

// Mostrar nueva estructura
echo "\n=== ESTRUCTURA ACTUALIZADA ===\n";
$result = $conn->query('DESCRIBE usuario');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}

$conn->close();
?>

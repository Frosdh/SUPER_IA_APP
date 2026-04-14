<?php
// ============================================================
// globalize_db.php - Añade campos de globalización a la DB
// ============================================================
require_once __DIR__ . '/db_config.php';

echo "<pre>";

// 1. Alterar tabla conductores
$sql1 = "ALTER TABLE conductores 
         ADD COLUMN IF NOT EXISTS pais VARCHAR(100) DEFAULT 'Ecuador',
         ADD COLUMN IF NOT EXISTS provincia VARCHAR(100) DEFAULT 'Azuay',
         ADD COLUMN IF NOT EXISTS canton VARCHAR(100) DEFAULT 'Cuenca',
         ADD COLUMN IF NOT EXISTS ciudad VARCHAR(100) DEFAULT 'Cuenca'";

if ($conn->query($sql1)) {
    echo "Tabla 'conductores' actualizada correctamente.\n";
} else {
    echo "Error en conductores: " . $conn->error . "\n";
}

// 2. Alterar tabla viajes
$sql2 = "ALTER TABLE viajes 
         ADD COLUMN IF NOT EXISTS pais VARCHAR(100) DEFAULT 'Ecuador',
         ADD COLUMN IF NOT EXISTS provincia VARCHAR(100) DEFAULT 'Azuay',
         ADD COLUMN IF NOT EXISTS canton VARCHAR(100) DEFAULT 'Cuenca',
         ADD COLUMN IF NOT EXISTS cooperativa_id INT NULL";

if ($conn->query($sql2)) {
    echo "Tabla 'viajes' actualizada correctamente.\n";
} else {
    echo "Error en viajes: " . $conn->error . "\n";
}

echo "\nMigración completada.";
$conn->close();
?>

<?php
require_once __DIR__ . '/db_config.php';
header("Content-Type: application/json");

// 1. Añadir columna categoria_id si no existe
$checkSql = "SHOW COLUMNS FROM conductores LIKE 'categoria_id'";
$result = $conn->query($checkSql);

if ($result->num_rows == 0) {
    // No existe, la creamos
    $alterSql = "ALTER TABLE conductores ADD COLUMN categoria_id INT DEFAULT 1 AFTER cooperativa_id";
    if ($conn->query($alterSql) === TRUE) {
        // Actualizar conductores existentes a categoría 1 (Fuber-X)
        $conn->query("UPDATE conductores SET categoria_id = 1 WHERE categoria_id IS NULL");
        echo json_encode(["status" => "success", "message" => "Columna categoria_id añadida y conductores actualizados"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error al añadir columna: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "info", "message" => "La columna categoria_id ya existe"]);
}
?>

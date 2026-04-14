<?php
require_once 'db_config.php';

echo "=== ESTRUCTURA TABLA CLIENTES ===\n\n";

$query = "DESCRIBE clientes";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "  ✗ Tabla no existe\n";
}

echo "\n=== DATOS EJEMPLO ===\n";
$queryData = "SELECT * FROM clientes LIMIT 1";
$resultData = $conn->query($queryData);
if ($resultData->num_rows > 0) {
    $row = $resultData->fetch_assoc();
    echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>

<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

echo "=== ESTRUCTURA DE TABLAS operacion_credito Y alertas ===\n\n";

// Verificar operacion_credito
echo "--- Columnas de operacion_credito ---\n";
$result = $conn->query("DESC operacion_credito");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n--- Primeras filas de operacion_credito ---\n";
$result = $conn->query("SELECT * FROM operacion_credito LIMIT 3");
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Sin datos\n";
}

// Verificar alertas
echo "\n--- Columnas de alertas ---\n";
if ($conn->query("SELECT 1 FROM alertas LIMIT 1")) {
    $result = $conn->query("DESC alertas");
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
    
    echo "\n--- Primeras filas de alertas ---\n";
    $result = $conn->query("SELECT * FROM alertas LIMIT 3");
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            print_r($row);
        }
    } else {
        echo "Sin datos\n";
    }
} else {
    echo "Tabla alertas NO existe\n";
}

$conn->close();
?>

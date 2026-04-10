<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

echo "=== ESTRUCTURA TABLA CLIENTES ===\n";
$result = $conn->query("DESC clientes");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== PRIMEROS REGISTROS DE CLIENTES ===\n";
$result = $conn->query("SELECT * FROM clientes LIMIT 3");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

$conn->close();
?>

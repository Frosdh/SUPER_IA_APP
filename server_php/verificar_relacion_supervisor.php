<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

echo "=== ESTRUCTURA TABLA USUARIOS ===\n";
$result = $conn->query("DESC usuarios");
while ($row = $result->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== SUPERVISORES Y ASESORES ===\n";
$result = $conn->query("
    SELECT u.id_usuario, u.usuario, u.nombres, r.nombre as rol
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE r.nombre IN ('Supervisor', 'Asesor')
    ORDER BY r.nombre, u.usuario
");

while ($row = $result->fetch_assoc()) {
    echo $row['rol'] . ": " . $row['usuario'] . " (" . $row['id_usuario'] . ")\n";
}

$conn->close();
?>

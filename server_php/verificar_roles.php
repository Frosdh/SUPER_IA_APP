<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

echo "=== ROLES EN LA BD ===\n";
$result = $conn->query("SELECT * FROM roles");
while ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id_rol'] . " → " . $row['nombre'] . "\n";
}

echo "\n=== USUARIOS Y SUS ROLES ===\n";
$result = $conn->query("
    SELECT u.id_usuario, u.usuario, u.nombres, r.id_rol, r.nombre as rol
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    ORDER BY r.id_rol, u.usuario
");

while ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id_usuario'] . " | Usuario: " . $row['usuario'] . " | Rol: " . $row['rol'] . " (id_rol=" . $row['id_rol'] . ")\n";
}

$conn->close();
?>

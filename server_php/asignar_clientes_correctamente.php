<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// 1. Distribuir clientes entre asesores (IDs correctos: 5, 6, 7, 8)
echo "--- Asignando clientes a asesores ---\n";

$result = $conn->query("SELECT id_cliente FROM clientes");
$clientes_ids = [];
while ($row = $result->fetch_assoc()) {
    $clientes_ids[] = $row['id_cliente'];
}

// Asesores disponibles con sus IDs correctos
$asesores = [5, 6, 7, 8]; // asesor_q1=5, asesor_q2=6, asesor_g1=7, asesor_g2=8

// Distribuir clientes de manera equilibrada
$asesores_count = count($asesores);
foreach ($clientes_ids as $index => $cliente_id) {
    $asesor_id = $asesores[$index % $asesores_count];
    $conn->query("UPDATE clientes SET asesor_id_fk = $asesor_id WHERE id_cliente = $cliente_id");
}

echo "✅ Clientes distribuidos\n";

// 2. Mostrar clientes detalladamente
echo "\n--- Clientes por asesor ---\n";

$result = $conn->query("
    SELECT u.usuario, u.id_usuario, COUNT(c.id_cliente) as total_clientes, r.nombre as rol
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    LEFT JOIN clientes c ON c.asesor_id_fk = u.id_usuario
    WHERE r.nombre = 'Asesor'
    GROUP BY u.id_usuario, u.usuario
    ORDER BY u.usuario
");

while ($row = $result->fetch_assoc()) {
    echo $row['usuario'] . " (ID: " . $row['id_usuario'] . ") → " . $row['total_clientes'] . " clientes\n";
}

// 3. Mostrar detalles de clientes
echo "\n--- Detalle de clientes ---\n";

$result = $conn->query("
    SELECT u.usuario, c.id_cliente, c.nombre, c.apellidos, c.email, c.activo
    FROM usuarios u
    LEFT JOIN clientes c ON c.asesor_id_fk = u.id_usuario
    WHERE u.id_rol_fk = 4
    ORDER BY u.usuario, c.nombre
");

$current_asesor = '';
while ($row = $result->fetch_assoc()) {
    if ($row['usuario'] !== $current_asesor) {
        if ($current_asesor !== '') echo "\n";
        $current_asesor = $row['usuario'];
        echo "📌 " . $current_asesor . ":\n";
    }
    if ($row['id_cliente']) {
        $status = $row['activo'] ? "✓" : "✗";
        echo "   " . $status . " #" . $row['id_cliente'] . " - " . $row['nombre'] . " " . $row['apellidos'] . "\n";
    }
}

$conn->close();
echo "\n✅ Asignación completada\n";
?>

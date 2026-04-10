<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "corporat_radix_copia";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// 1. Verificar si la columna asesor_id_fk ya existe
$result = $conn->query("SHOW COLUMNS FROM clientes LIKE 'asesor_id_fk'");
if ($result->num_rows == 0) {
    echo "Agregando columna asesor_id_fk a clientes...\n";
    $conn->query("ALTER TABLE clientes ADD COLUMN asesor_id_fk INT(11) NULL AFTER id_activ_cliente_fk");
    echo "✅ Columna agregada\n";
} else {
    echo "La columna asesor_id_fk ya existe\n";
}

// 2. Distribuir clientes entre asesores
echo "\n--- Distribuyendo clientes entre asesores ---\n";

// Obtener todos los clientes
$result = $conn->query("SELECT id_cliente FROM clientes");
$clientes_ids = [];
while ($row = $result->fetch_assoc()) {
    $clientes_ids[] = $row['id_cliente'];
}

echo "Total de clientes: " . count($clientes_ids) . "\n";

// Asesores disponibles
$asesores = [5, 6, 7, 8]; // asesor_q1=5, asesor_q2=6, asesor_g1=7, asesor_g2=8

// Distribuir clientes de manera equilibrada
$asesores_count = count($asesores);
foreach ($clientes_ids as $index => $cliente_id) {
    $asesor_id = $asesores[$index % $asesores_count];
    $conn->query("UPDATE clientes SET asesor_id_fk = $asesor_id WHERE id_cliente = $cliente_id");
}

echo "✅ Clientes distribuidos entre asesores\n";

// 3. Verificar las asignaciones
echo "\n--- Clientes por asesor ---\n";

$result = $conn->query("
    SELECT u.usuario, u.id_usuario, COUNT(c.id_cliente) as total_clientes
    FROM usuarios u
    LEFT JOIN clientes c ON c.asesor_id_fk = u.id_usuario
    WHERE u.id_rol_fk = 3
    GROUP BY u.id_usuario
");

while ($row = $result->fetch_assoc()) {
    echo $row['usuario'] . " (ID: " . $row['id_usuario'] . ") → " . $row['total_clientes'] . " clientes\n";
}

// 4. Mostrar clientes detalladamente
echo "\n--- Clientes asignados por asesor ---\n";

$result = $conn->query("
    SELECT u.usuario, c.id_cliente, c.nombre, c.apellidos, c.email
    FROM usuarios u
    LEFT JOIN clientes c ON c.asesor_id_fk = u.id_usuario
    WHERE u.id_rol_fk = 3
    ORDER BY u.usuario, c.nombre
");

$current_asesor = '';
while ($row = $result->fetch_assoc()) {
    if ($row['usuario'] !== $current_asesor) {
        $current_asesor = $row['usuario'];
        echo "\n📌 " . $current_asesor . ":\n";
    }
    if ($row['id_cliente']) {
        echo "   ✓ #" . $row['id_cliente'] . " - " . $row['nombre'] . " " . $row['apellidos'] . "\n";
    }
}

$conn->close();
echo "\n✅ Relación asesor-cliente configurada correctamente\n";
?>

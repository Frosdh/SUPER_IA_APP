<?php
require_once 'db_config.php';
$conn = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
$conn->set_charset('utf8mb4');

echo "=== SUPERVISORES EN BASE DE DATOS ===\n";
$result = $conn->query("
    SELECT 
        u.id,
        u.nombre,
        u.email,
        u.rol,
        u.activo,
        u.estado_aprobacion
    FROM usuario u
    WHERE u.rol = 'supervisor'
    LIMIT 10
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

echo "\n=== AGENCIAS Y UNIDADES BANCARIAS ===\n";
$result = $conn->query("
    SELECT 
        a.id,
        a.unidad_bancaria_id,
        a.nombre,
        ub.id as ub_id,
        ub.nombre as ub_nombre
    FROM agencia a
    LEFT JOIN unidad_bancaria ub ON ub.id = a.unidad_bancaria_id
    LIMIT 10
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}
?>

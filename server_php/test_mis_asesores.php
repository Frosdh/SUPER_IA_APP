<?php
session_start();

// Simular sesión de supervisor_g1 (ID 4)
$_SESSION['supervisor_logged_in'] = true;
$_SESSION['supervisor_id'] = 4;
$_SESSION['supervisor_nombre'] = 'Jessica Ramírez';
$_SESSION['supervisor_rol'] = 'Supervisor';

echo "=== PRUEBA: Asesores y clientes para supervisor_g1 (ID 4) ===\n\n";

require_once 'admin/db_admin.php';

$supervisor_id = $_SESSION['supervisor_id'];

// Obtener asesores con conteo de clientes
$asesores = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, u.telefono, u.ciudad, r.nombre as rol,
           COUNT(c.id_cliente) as total_clientes
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    LEFT JOIN clientes c ON c.asesor_id_fk = u.id_usuario
    WHERE r.nombre = 'Asesor' AND u.supervisor_id_fk = $supervisor_id
    GROUP BY u.id_usuario, u.usuario
    ORDER BY u.nombres
")->fetchAll();

echo "Total de asesores: " . count($asesores) . "\n\n";

foreach ($asesores as $asesor) {
    echo "📌 " . $asesor['nombres'] . " " . $asesor['apellidos'] . " (Clientes: " . $asesor['total_clientes'] . ")\n";
    echo "   Usuario: " . $asesor['usuario'] . "\n";
    echo "   Email: " . $asesor['email'] . "\n";
    
    // Obtener clientes de este asesor
    $clientes = $pdo->query("
        SELECT c.id_cliente, c.nombre, c.apellidos, c.email, c.telefono, c.activo
        FROM clientes c
        WHERE c.asesor_id_fk = " . $asesor['id_usuario'] . "
        ORDER BY c.nombre
    ")->fetchAll();
    
    if (empty($clientes)) {
        echo "   ✓ Sin clientes asignados\n";
    } else {
        foreach ($clientes as $cliente) {
            $status = $cliente['activo'] ? "✓" : "✗";
            echo "   " . $status . " #" . $cliente['id_cliente'] . " - " . $cliente['nombre'] . " " . $cliente['apellidos'] . " (" . $cliente['email'] . ")\n";
        }
    }
    echo "\n";
}

echo "✅ Prueba completada\n";
?>

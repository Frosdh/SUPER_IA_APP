<?php
session_start();

// Simular sesión de supervisor_g1 (ID 4)
$_SESSION['supervisor_logged_in'] = true;
$_SESSION['supervisor_id'] = 4;
$_SESSION['supervisor_nombre'] = 'Jessica Ramírez';
$_SESSION['supervisor_rol'] = 'Supervisor';

echo "=== PRUEBA: Asesores para supervisor_g1 (ID 4) ===\n\n";

require_once 'admin/db_admin.php';

$supervisor_id = $_SESSION['supervisor_id'];
$asesores = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, u.telefono, u.ciudad, r.nombre as rol
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE r.nombre = 'Asesor' AND u.supervisor_id_fk = $supervisor_id
    ORDER BY u.nombres
")->fetchAll();

echo "Total de asesores: " . count($asesores) . "\n\n";
foreach ($asesores as $asesor) {
    echo "✅ " . $asesor['usuario'] . " - " . $asesor['nombres'] . "\n";
}

echo "\n=== PRUEBA: Asesores para supervisor_q1 (ID 3) ===\n\n";

// Simular sesión de supervisor_q1 (ID 3)
$_SESSION['supervisor_id'] = 3;
$_SESSION['supervisor_nombre'] = 'Marco Chávez';

$supervisor_id = $_SESSION['supervisor_id'];
$asesores = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, u.telefono, u.ciudad, r.nombre as rol
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE r.nombre = 'Asesor' AND u.supervisor_id_fk = $supervisor_id
    ORDER BY u.nombres
")->fetchAll();

echo "Total de asesores: " . count($asesores) . "\n\n";
foreach ($asesores as $asesor) {
    echo "✅ " . $asesor['usuario'] . " - " . $asesor['nombres'] . "\n";
}
?>

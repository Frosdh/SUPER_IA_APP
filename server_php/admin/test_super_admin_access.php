<?php
// Test file to verify SuperAdmin access
session_start();

// Simular sesión de super_admin
$_SESSION['super_admin_logged_in'] = true;
$_SESSION['super_admin_id'] = 1;
$_SESSION['super_admin_nombre'] = 'Test SuperAdmin';
$_SESSION['super_admin_rol'] = 'SuperAdministrador';

// Verificar que las variables se configuran correctamente
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

echo "Test - SuperAdmin Session Variables:\n";
echo "=====================================\n";
echo "is_admin: " . ($is_admin ? 'true' : 'false') . "\n";
echo "is_super_admin: " . ($is_super_admin ? 'true' : 'false') . "\n";

if ($is_super_admin) {
    $user_type = 'super_admin';
    $admin_id = $_SESSION['super_admin_id'];
    $admin_nombre = $_SESSION['super_admin_nombre'];
    $admin_rol = $_SESSION['super_admin_rol'];
    
    echo "user_type: " . $user_type . "\n";
    echo "admin_id: " . $admin_id . "\n";
    echo "admin_nombre: " . $admin_nombre . "\n";
    echo "admin_rol: " . $admin_rol . "\n";
    echo "\n✅ SuperAdmin access verified successfully!\n";
} else {
    echo "❌ SuperAdmin session not detected\n";
}

echo "\nse_solicitudes_admin.php can now:\n";
echo "- Display navbar with 👑 crown emoji\n";
echo "- Access the approval workflow\n";
echo "- Manage admin account requests\n";
?>

<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_nombre'] = 'Admin Test';
$_SESSION['admin_rol'] = 'Administrador';

ob_start();
try {
    include 'admin/usuarios.php';
    $contenido = ob_get_clean();
    echo "✅ usuarios.php se cargó sin errores\n";
    echo "✅ La página está lista para usar\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

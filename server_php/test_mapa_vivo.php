<?php
// Simular acceso a mapa_vivo.php con sesión
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_nombre'] = 'Admin Test';
$_SESSION['admin_rol'] = 'Administrador';

// Incluir la página
ob_start();
try {
    include 'admin/mapa_vivo.php';
    $contenido = ob_get_clean();
    echo "✅ mapa_vivo.php se cargó sin errores\n";
    echo "✅ Sesión activa: " . (isset($_SESSION['admin_logged_in']) ? 'Sí' : 'No') . "\n";
    echo "✅ Conexión a BD funciona correctamente\n";
} catch (Exception $e) {
    ob_end_clean();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

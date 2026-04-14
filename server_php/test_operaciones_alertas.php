<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_nombre'] = 'Test Admin';
$_SESSION['admin_rol'] = 'SuperAdmin';

ob_start();

try {
    include('admin/operaciones.php');
    $content = ob_get_clean();
    if (strpos($content, 'Operaciones de Crédito') !== false) {
        echo "✅ operaciones.php se cargó correctamente - tabla visible\n";
    } else {
        echo "❌ operaciones.php se cargó pero la tabla no se encontró\n";
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "❌ Error en operaciones.php: " . $e->getMessage() . "\n";
}

echo "\n---\n\n";

ob_start();

try {
    include('admin/alertas.php');
    $content = ob_get_clean();
    if (strpos($content, 'Centro de Alertas') !== false) {
        echo "✅ alertas.php se cargó correctamente - tabla visible\n";
    } else {
        echo "❌ alertas.php se cargó pero la tabla no se encontró\n";
    }
} catch (Exception $e) {
    ob_end_clean();
    echo "❌ Error en alertas.php: " . $e->getMessage() . "\n";
}
?>

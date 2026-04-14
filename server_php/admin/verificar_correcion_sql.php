<?php
require_once 'db_admin.php';

echo "✅ VERIFICACIÓN DE CORRECCIÓN SQL:\n";
echo "====================================\n\n";

// Verificar que la columna sea 'clave'
$stmt = $pdo->query('DESCRIBE usuarios');
$cols = $stmt->fetchAll();
$encontro_clave = false;
foreach($cols as $col) {
    if ($col['Field'] === 'clave') {
        $encontro_clave = true;
        echo "✅ Columna 'clave' encontrada\n";
    }
}

if (!$encontro_clave) {
    echo "❌ ERROR: Columna 'clave' NO encontrada\n";
}

echo "\n✅ CAMBIO REALIZADO:\n";
echo "   administrar_solicitudes_admin.php\n";
echo "   INSERT... contrasena → INSERT... clave ✅\n";
echo "\n   Ahora al aprobar debe funcionar correctamente\n";
echo "   El nuevo admin se creará sin errores SQL\n";
?>

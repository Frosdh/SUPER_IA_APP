<?php
require_once 'admin/db_admin.php';

// Verificar estructura de tabla usuarios
$stmt = $pdo->query('DESCRIBE usuarios');
$cols = $stmt->fetchAll();

echo "Columnas de la tabla usuarios:\n";
echo "============================\n";
foreach($cols as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ")\n";
}
?>

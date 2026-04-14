<?php
require_once 'admin/db_admin.php';

echo "=== ESTRUCTURA TABLA COOPERATIVA ===\n";
$resultado = $pdo->query("DESC cooperativa")->fetchAll();
foreach ($resultado as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

echo "\n=== ESTRUCTURA TABLA USUARIOS ===\n";
$resultado = $pdo->query("DESC usuarios")->fetchAll();
foreach ($resultado as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

echo "\n=== DATOS COOPERATIVA ===\n";
$resultado = $pdo->query("SELECT * FROM cooperativa LIMIT 1")->fetch();
if ($resultado) {
    echo "Columnas encontradas: " . implode(", ", array_keys($resultado)) . "\n";
} else {
    echo "No hay datos en cooperativa\n";
}
?>

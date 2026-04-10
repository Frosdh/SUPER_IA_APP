<?php
require_once 'admin/db_admin.php';

echo "=== ESTRUCTURA TABLA REGION ===\n";
$resultado = $pdo->query("DESC region")->fetchAll();
foreach ($resultado as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

echo "\n=== ESTRUCTURA TABLA CLIENTES ===\n";
$resultado = $pdo->query("DESC clientes")->fetchAll();
foreach ($resultado as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

echo "\n=== DATOS REGION (ejemplo) ===\n";
$resultado = $pdo->query("SELECT * FROM region LIMIT 1")->fetch();
if ($resultado) {
    echo "Columnas encontradas: " . implode(", ", array_keys($resultado)) . "\n";
    print_r($resultado);
} else {
    echo "No hay datos en region\n";
}

echo "\n=== DATOS CLIENTES (ejemplo) ===\n";
$resultado = $pdo->query("SELECT * FROM clientes LIMIT 1")->fetch();
if ($resultado) {
    echo "Columnas encontradas: " . implode(", ", array_keys($resultado)) . "\n";
}
?>

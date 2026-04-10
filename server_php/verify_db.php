<?php
require_once 'admin/db_admin.php';

// Verificar tabla clientes
echo "=== TABLA CLIENTES ===\n";
$clientes = $pdo->query('DESC clientes')->fetchAll();
foreach ($clientes as $col) {
    echo $col['Field'] . ' - ' . $col['Type'] . "\n";
}

// Verificar datos
echo "\n=== DATOS CLIENTES ===\n";
$data = $pdo->query('SELECT * FROM clientes LIMIT 3')->fetchAll();
foreach ($data as $row) {
    echo 'ID: ' . $row['id_cliente'] . ', Nombre: ' . $row['nombre'] . "\n";
}

echo "\n=== USUARIOS ===\n";
$usuarios = $pdo->query('SELECT id_usuario, usuario, nombres, apellidos, id_rol_fk FROM usuarios LIMIT 5')->fetchAll();
foreach ($usuarios as $row) {
    echo 'ID: ' . $row['id_usuario'] . ', Usuario: ' . $row['usuario'] . ', Rol: ' . $row['id_rol_fk'] . "\n";
}
?>

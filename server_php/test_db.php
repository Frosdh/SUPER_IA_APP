<?php
// test_db.php
require_once __DIR__ . '/admin/db_admin.php';

try {
    $stmt = $pdo->query("SELECT id, nombre, email, rol, activo FROM usuario");
    $users = $stmt->fetchAll();
    echo "Usuarios en la base de datos:\n";
    print_r($users);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

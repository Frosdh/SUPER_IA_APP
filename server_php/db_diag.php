<?php
// db_diag.php
require_once __DIR__ . '/admin/db_admin.php';

try {
    echo "PHP Version: " . phpversion() . "\n";
    echo "DB Host: $db_host\n";
    echo "DB Name: $db_name\n";
    echo "DB User: $db_user\n";
    echo "DB Pass: $db_password\n";
    
    $stmt = $pdo->query("SELECT id, nombre, email, rol, activo FROM usuario");
    $users = $stmt->fetchAll();
    echo "Connected successfully!\nUsers found:\n";
    print_r($users);
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}

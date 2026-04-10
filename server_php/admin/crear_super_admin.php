<?php
require_once 'db_admin.php';

// Crear/actualizar super admin con credenciales claras
$usuario = 'superadmin';
$password = 'SuperAdmin123!';
$password_hash = hash('sha256', $password);

try {
    // Verificar si ya existe
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND id_rol_fk = 1");
    $stmt->execute([$usuario]);
    $super_admin = $stmt->fetch();
    
    if ($super_admin) {
        // Actualizar contraseña
        $stmt = $pdo->prepare("UPDATE usuarios SET clave = ? WHERE usuario = ? AND id_rol_fk = 1");
        $stmt->execute([$password_hash, $usuario]);
        echo "✅ SUPER ADMIN ACTUALIZADO:\n";
    } else {
        // Crear nuevo
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, ciudad, provincia, canton, activo, id_rol_fk)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");
        
        $stmt->execute([
            $usuario,
            $password_hash,
            'SUPER',
            'ADMINISTRADOR',
            'superadmin@coac.finance',
            '0999888777',
            'Quito',
            'Pichincha',
            'Quito'
        ]);
        echo "✅ SUPER ADMIN CREADO:\n";
    }
    
    echo "========================================\n";
    echo "👑 CREDENCIALES DEL SUPER ADMINISTRADOR:\n";
    echo "========================================\n";
    echo "Usuario:      superadmin\n";
    echo "Contraseña:   SuperAdmin123!\n";
    echo "Email:        superadmin@coac.finance\n";
    echo "Rol:          SuperAdministrador (id=1)\n";
    echo "========================================\n";
    echo "\n🔗 ACCESO:\n";
    echo "1. Ir a: http://localhost/admin/login_selector.php\n";
    echo "2. Seleccionar: 👑 Super Administrador\n";
    echo "3. Ingresar credenciales arriba\n";
    echo "========================================\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>

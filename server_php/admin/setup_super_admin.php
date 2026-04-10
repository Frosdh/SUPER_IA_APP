<?php
require_once 'db_admin.php';

// Verificar si existe super admin
$stmt = $pdo->query("SELECT * FROM usuarios WHERE id_rol_fk = 1 LIMIT 1");
$super_admin = $stmt->fetch();

if ($super_admin) {
    echo "✅ SUPER ADMIN YA EXISTE:\n";
    echo "========================================\n";
    echo "Usuario: " . $super_admin['usuario'] . "\n";
    echo "Nombre: " . $super_admin['nombres'] . " " . $super_admin['apellidos'] . "\n";
    echo "Email: " . $super_admin['email'] . "\n";
    echo "Activo: " . ($super_admin['activo'] ? 'Sí' : 'No') . "\n";
    echo "Creado: " . $super_admin['fecha_creacion'] . "\n";
    echo "\n⚠️ NOTA: La contraseña no se muestra por seguridad.\n";
} else {
    echo "❌ NO EXISTE SUPER ADMIN\n";
    echo "========================================\n";
    echo "Creando cuenta super admin por defecto...\n\n";
    
    // Crear super admin con credenciales por defecto
    $usuario = 'superadmin';
    $password = 'SuperAdmin2026!';
    $password_hash = hash('sha256', $password);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (usuario, contrasena, nombres, apellidos, email, telefono, ciudad, provincia, region, activo, id_rol_fk)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
        ");
        
        $stmt->execute([
            $usuario,
            $password_hash,
            'Super',
            'Administrador',
            'superadmin@coac-finance.com',
            '0999999999',
            'Quito',
            'Pichincha',
            'Quito'
        ]);
        
        echo "✅ SUPER ADMIN CREADO EXITOSAMENTE:\n";
        echo "========================================\n";
        echo "Usuario: " . $usuario . "\n";
        echo "Contraseña: " . $password . "\n";
        echo "Email: superadmin@coac-finance.com\n";
        echo "Rol: SuperAdministrador (id_rol_fk = 1)\n";
        echo "\n⚠️ IMPORTANT: Guarda estas credenciales en un lugar seguro.\n";
        echo "   Puedes cambiar la contraseña después en el panel.\n";
        
    } catch (PDOException $e) {
        echo "❌ ERROR al crear super admin:\n";
        echo $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo "LOGIN: http://your-site/admin/login.php\n";
echo "SELECT ROLE: SuperAdministrador (👑)\n";
echo "========================================\n";
?>

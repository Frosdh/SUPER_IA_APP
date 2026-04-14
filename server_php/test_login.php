<?php
require_once 'db_config.php';

echo "=== VERIFICANDO BASE DE DATOS ===\n\n";

// Verificar usuario admin
$query = "SELECT id_usuario, usuario, id_rol_fk, nombres, clave FROM usuarios WHERE usuario = 'admin' LIMIT 1";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "✓ Usuario encontrado\n";
    echo "  - Usuario: " . $row['usuario'] . "\n";
    echo "  - ID: " . $row['id_usuario'] . "\n";
    echo "  - Rol ID: " . $row['id_rol_fk'] . "\n";
    echo "  - Nombre: " . $row['nombres'] . "\n";
    
    // Verificar que la contraseña correcta sea admin123
    $claveCorrecta = hash('sha256', 'admin123');
    if ($row['clave'] === $claveCorrecta) {
        echo "  - Contraseña: ✓ Correcta (admin123)\n";
    } else {
        echo "  - Contraseña: ✗ NO COINCIDE\n";
        echo "    Hash en BD: " . substr($row['clave'], 0, 20) . "...\n";
        echo "    Hash esperado: " . substr($claveCorrecta, 0, 20) . "...\n";
    }
} else {
    echo "✗ Usuario admin no encontrado en la base de datos\n";
}

// Verificar que existe rol Admin (id_rol_fk = 2)
echo "\n=== VERIFICANDO ROLES ===\n";
$rolesQuery = "SELECT * FROM roles";
$rolesResult = $conn->query($rolesQuery);
if ($rolesResult->num_rows > 0) {
    while ($role = $rolesResult->fetch_assoc()) {
        echo "- Rol ID " . $role['id_rol'] . ": " . $role['nombre'] . "\n";
    }
} else {
    echo "✗ No hay roles en la base de datos\n";
}

$conn->close();
?>

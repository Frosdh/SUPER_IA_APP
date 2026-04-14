<?php
// ============================================================
// TEST_LOGIN_EMAIL.php - Verificar que el login con email funciona
// ============================================================

require_once 'db_config.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Test Login</title></head><body>";
echo "<h1>🧪 Test de Login con Email</h1>";

// 1. Verificar conexión
if ($conn->connect_error) {
    echo "<p style='color:red;'>❌ Error de conexión: " . $conn->connect_error . "</p>";
    exit;
}
echo "<p style='color:green;'>✅ Conexión OK</p>";

// 2. Verificar tabla usuario existe
$check = $conn->query("SHOW TABLES LIKE 'usuario'");
if ($check && $check->num_rows > 0) {
    echo "<p style='color:green;'>✅ Tabla 'usuario' existe</p>";
} else {
    echo "<p style='color:red;'>❌ Tabla 'usuario' NO existe</p>";
    exit;
}

// 3. Verificar estructura de tabla
echo "<h3>Estructura de tabla 'usuario':</h3>";
$structure = $conn->query("DESCRIBE usuario");
echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
while ($row = $structure->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Contar usuarios
$count = $conn->query("SELECT COUNT(*) as total FROM usuario");
$countRow = $count->fetch_assoc();
echo "<p>Total usuarios en BD: <strong>" . $countRow['total'] . "</strong></p>";

// 5. Listar usuarios (sin mostrar contraseñas)
if ($countRow['total'] > 0) {
    echo "<h3>Usuarios disponibles para test:</h3>";
    $users = $conn->query("SELECT id, nombre, email, rol, activo, estado_aprobacion FROM usuario LIMIT 10");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Email</th><th>Nombre</th><th>Rol</th><th>Activo</th><th>Estado</th></tr>";
    while ($user = $users->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($user['email']) . "</td>";
        echo "<td>" . htmlspecialchars($user['nombre']) . "</td>";
        echo "<td>" . htmlspecialchars($user['rol']) . "</td>";
        echo "<td>" . ($user['activo'] ? '✅ Sí' : '❌ No') . "</td>";
        echo "<td>" . htmlspecialchars($user['estado_aprobacion']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>📝 Para probar el login:</h3>";
    echo "<ol>";
    echo "<li>Accede a: <strong>index.php</strong></li>";
    echo "<li>Selecciona el rol del usuario de arriba</li>";
    echo "<li>Ingresa su email</li>";
    echo "<li>Ingresa la contraseña (debe haber sido creada con password_hash)</li>";
    echo "</ol>";
} else {
    echo "<p style='color:orange;'>⚠️ No hay usuarios en la BD. Crea uno primero usando register_user.php</p>";
}

// 6. Verificar que password_hash funciona
echo "<h3>🔐 Test de password_hash:</h3>";
$testPass = 'test123';
$testHash = password_hash($testPass, PASSWORD_DEFAULT);
$verify = password_verify($testPass, $testHash);
echo "<p>Password de prueba: <code>$testPass</code></p>";
echo "<p>Hash generado: <code>" . substr($testHash, 0, 50) . "...</code></p>";
if ($verify) {
    echo "<p style='color:green;'>✅ password_verify funciona correctamente</p>";
} else {
    echo "<p style='color:red;'>❌ password_verify NO funciona</p>";
}

$conn->close();
?>
</body></html>

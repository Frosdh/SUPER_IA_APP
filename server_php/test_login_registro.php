<?php
// ============================================================
// test_login_registro.php - PRUEBA DE LOGIN Y REGISTRO
// ============================================================

header('Content-Type: text/html; charset=utf-8');

// Conectar a la base de datos
require_once __DIR__ . '/db_config.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>SUPER_IA - Test Login y Registro</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .section { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #7C3AED; }
        h2 { color: #0EA5E9; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { background: #e3f2fd; padding: 10px; border-left: 4px solid #2196F3; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        .test-form { background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin: 10px 0; }
        input, button { padding: 8px; margin: 5px 0; }
        button { background: #7C3AED; color: white; border: none; cursor: pointer; border-radius: 3px; }
        button:hover { background: #6d28d9; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🧪 SUPER_IA - Test Login y Registro</h1>
        <div class='info'>
            <strong>Base de datos:</strong> super_ia_logan<br>
            <strong>Host:</strong> " . htmlspecialchars('127.0.0.1') . "<br>
            <strong>Fecha:</strong> " . date('Y-m-d H:i:s') . "
        </div>
";

// 1. VERIFICAR CONEXIÓN
echo "<div class='section'>
    <h2>✅ Estado de Conexión</h2>";
if ($conn->connect_error) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($conn->connect_error) . "</div>";
} else {
    echo "<div class='success'>✓ Conexión establecida a la base de datos</div>";
}
echo "</div>";

// 2. VERIFICAR TABLAS
echo "<div class='section'>
    <h2>📊 Tablas en la Base de Datos</h2>";
$result = $conn->query("SHOW TABLES FROM super_ia_logan");
if ($result) {
    echo "<table><tr><th>Tabla</th><th>Registros</th></tr>";
    while ($row = $result->fetch_array()) {
        $tableName = $row[0];
        $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$tableName`");
        $count = $countResult->fetch_assoc()['cnt'];
        echo "<tr><td><code>$tableName</code></td><td>$count</td></tr>";
    }
    echo "</table>";
} else {
    echo "<div class='error'>Error al listar tablas: " . htmlspecialchars($conn->error) . "</div>";
}
echo "</div>";

// 3. CREAR USUARIO DE PRUEBA
echo "<div class='section'>
    <h2>➕ Crear Usuario Asesor de Prueba</h2>";

$test_email = 'asesor.prueba@superialogal.test';
$test_password = 'Test@1234';
$test_name = 'Juan Pérez Asesor';
$test_rol = 'asesor';

// Verificar si el usuario ya existe
$checkStmt = $conn->prepare("SELECT id FROM usuario WHERE email = ?");
$checkStmt->bind_param('s', $test_email);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    echo "<div class='info'>⚠️ El usuario de prueba ya existe con email: <code>$test_email</code></div>";
    $user_data = $checkResult->fetch_assoc();
    $usuario_id = $user_data['id'];
} else {
    echo "<div class='info'>Creando usuario de prueba...</div>";
    
    $password_hash = password_hash($test_password, PASSWORD_DEFAULT);
    
    $insertStmt = $conn->prepare(
        "INSERT INTO usuario (nombre, email, password_hash, rol, estado_registro, activo, created_at) 
         VALUES (?, ?, ?, ?, 'aprobado', 1, NOW())"
    );
    $insertStmt->bind_param('ssss', $test_name, $test_email, $password_hash, $test_rol);
    
    if ($insertStmt->execute()) {
        $usuario_id = $conn->insert_id;
        echo "<div class='success'>✓ Usuario creado con ID: $usuario_id</div>";
        echo "<div class='info'>
            <strong>Credenciales de prueba:</strong><br>
            Email: <code>$test_email</code><br>
            Contraseña: <code>$test_password</code>
        </div>";
    } else {
        echo "<div class='error'>Error al crear usuario: " . htmlspecialchars($insertStmt->error) . "</div>";
        $usuario_id = null;
    }
    $insertStmt->close();
}
$checkStmt->close();

echo "</div>";

// 4. SIMULAR PRUEBA DE LOGIN
if ($usuario_id) {
    echo "<div class='section'>
        <h2>🔐 Prueba de Login (Simulado)</h2>";
    
    // Simular login
    $loginStmt = $conn->prepare("SELECT id, nombre, email, password_hash, rol, estado_registro, activo FROM usuario WHERE email = ?");
    $loginStmt->bind_param('s', $test_email);
    $loginStmt->execute();
    $loginResult = $loginStmt->get_result();
    
    if ($loginResult->num_rows > 0) {
        $user = $loginResult->fetch_assoc();
        
        // Verificar contraseña
        if (password_verify($test_password, $user['password_hash'])) {
            echo "<div class='success'>✓ Login exitoso</div>";
            echo "<table>
                <tr><td><strong>ID</strong></td><td>" . htmlspecialchars($user['id']) . "</td></tr>
                <tr><td><strong>Nombre</strong></td><td>" . htmlspecialchars($user['nombre']) . "</td></tr>
                <tr><td><strong>Email</strong></td><td>" . htmlspecialchars($user['email']) . "</td></tr>
                <tr><td><strong>Rol</strong></td><td>" . htmlspecialchars($user['rol']) . "</td></tr>
                <tr><td><strong>Estado</strong></td><td>" . htmlspecialchars($user['estado_registro']) . "</td></tr>
                <tr><td><strong>Activo</strong></td><td>" . ($user['activo'] ? 'Sí' : 'No') . "</td></tr>
            </table>";
        } else {
            echo "<div class='error'>❌ Contraseña incorrecta</div>";
        }
    }
    $loginStmt->close();
    
    echo "</div>";
}

// 5. ESTRUCTURA DE TABLA usuario
echo "<div class='section'>
    <h2>📋 Estructura de la Tabla 'usuario'</h2>";
$fieldsResult = $conn->query("DESCRIBE usuario");
if ($fieldsResult) {
    echo "<table>
        <tr>
            <th>Campo</th>
            <th>Tipo</th>
            <th>Nulo</th>
            <th>Clave</th>
            <th>Por defecto</th>
            <th>Extra</th>
        </tr>";
    while ($field = $fieldsResult->fetch_assoc()) {
        echo "<tr>
            <td><code>" . htmlspecialchars($field['Field']) . "</code></td>
            <td>" . htmlspecialchars($field['Type']) . "</td>
            <td>" . htmlspecialchars($field['Null']) . "</td>
            <td>" . htmlspecialchars($field['Key']) . "</td>
            <td>" . htmlspecialchars($field['Default'] ?? '-') . "</td>
            <td>" . htmlspecialchars($field['Extra']) . "</td>
        </tr>";
    }
    echo "</table>";
}
echo "</div>";

// 6. PRUEBAS DISPONIBLES
echo "<div class='section'>
    <h2>🧪 Comandos CURL para Pruebas</h2>
    <div class='info'>
        <strong>Test Login:</strong><br>
        <code>curl -X POST http://localhost/FUBER_APP/server_php/login.php \\<br>
        -d \"email=$test_email\" \\<br>
        -d \"password=$test_password\"</code>
        <br><br>
        <strong>Test Registro:</strong><br>
        <code>curl -X POST http://localhost/FUBER_APP/server_php/register_user.php \\<br>
        -d \"nombre=Nuevo Asesor\" \\<br>
        -d \"email=nuevo@test.com\" \\<br>
        -d \"password=Pass@1234\" \\<br>
        -d \"rol=asesor\"</code>
    </div>
</div>";

// 7. LISTAR USUARIOS
echo "<div class='section'>
    <h2>👥 Usuarios Registrados</h2>";
$usersResult = $conn->query("SELECT id, nombre, email, rol, estado_registro, activo, created_at FROM usuario LIMIT 10");
if ($usersResult && $usersResult->num_rows > 0) {
    echo "<table>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Email</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>Activo</th>
            <th>Creado</th>
        </tr>";
    while ($user = $usersResult->fetch_assoc()) {
        $activo = $user['activo'] ? '✓' : '✗';
        echo "<tr>
            <td>" . htmlspecialchars($user['id']) . "</td>
            <td>" . htmlspecialchars($user['nombre']) . "</td>
            <td>" . htmlspecialchars($user['email']) . "</td>
            <td>" . htmlspecialchars($user['rol']) . "</td>
            <td>" . htmlspecialchars($user['estado_registro']) . "</td>
            <td>$activo</td>
            <td>" . htmlspecialchars($user['created_at']) . "</td>
        </tr>";
    }
    echo "</table>";
} else {
    echo "<p>No hay usuarios registrados.</p>";
}
echo "</div>";

// Cerrar conexión
if ($conn) {
    $conn->close();
}

echo "
    <br><div class='info'>
        <strong>Última actualización:</strong> " . date('Y-m-d H:i:s') . "
    </div>
    </div>
</body>
</html>";
?>

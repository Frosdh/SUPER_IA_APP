<?php
/**
 * Debug: Verificar supervisor y probar login
 * Ejecutar: http://localhost/FUBER_APP/server_php/debug_supervisor_login.php
 */

header('Content-Type: text/html; charset=utf-8');

require 'db_config.php';

// Crear conexión MySQLi
$conexion = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
if ($conexion->connect_error) {
    die("Conexión fallida: " . $conexion->connect_error);
}
$conexion->set_charset('utf8mb4');

echo "<h1>Debug: Verificar Supervisor y Login</h1>";
echo "<hr>";

// 1. Verificar que el supervisor existe
echo "<h2>1️⃣  Supervisores en la base de datos</h2>";
$result = $conexion->query(
    "SELECT 
        u.id,
        u.nombre,
        u.email,
        u.rol,
        u.activo,
        u.estado_aprobacion,
        LENGTH(u.password_hash) as hash_long
     FROM usuario u 
     WHERE u.rol = 'supervisor' 
     ORDER BY u.email"
);

if ($result && $result->num_rows > 0) {
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Nombre</th><th>Email</th><th>Activo</th><th>Estado Aprobación</th><th>Hash Largo</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='font-size: 0.8em;'>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['nombre']) . "</td>";
        echo "<td><strong>" . htmlspecialchars($row['email']) . "</strong></td>";
        echo "<td>" . ($row['activo'] ? '✓ Sí' : '✗ No') . "</td>";
        echo "<td>" . htmlspecialchars($row['estado_aprobacion']) . "</td>";
        echo "<td>" . $row['hash_long'] . " chars</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ No hay supervisores en la base de datos</p>";
}

echo "<br>";

// 2. Probar password_verify con contraseña
echo "<h2>2️⃣  Prueba de password_verify()</h2>";

$email_prueba = 'supervisor@superialogan.com';
$password_prueba = 'password';

$result = $conexion->query(
    "SELECT u.id, u.nombre, u.email, u.password_hash, u.rol 
     FROM usuario u 
     WHERE u.email = '$email_prueba' 
     AND u.rol = 'supervisor' 
     AND u.activo = 1 
     AND u.estado_aprobacion = 'aprobado'"
);

if ($result && $result->num_rows > 0) {
    $usuario = $result->fetch_assoc();
    echo "<p><strong>Usuario encontrado:</strong> " . htmlspecialchars($usuario['nombre']) . "</p>";
    echo "<p><strong>Email:</strong> " . htmlspecialchars($usuario['email']) . "</p>";
    echo "<p><strong>Hash almacenado:</strong> <code>" . htmlspecialchars(substr($usuario['password_hash'], 0, 30)) . "...</code></p>";
    
    // Verificar contraseña
    echo "<p><strong>Probando password_verify():</strong></p>";
    $es_valida = password_verify($password_prueba, $usuario['password_hash']);
    
    if ($es_valida) {
        echo "<p style='color: green; font-size: 1.2em;'>✓ <strong>Contraseña CORRECTA</strong></p>";
    } else {
        echo "<p style='color: red; font-size: 1.2em;'>✗ <strong>Contraseña INCORRECTA</strong></p>";
        echo "<p style='color: orange;'>Nota: Esto significa que el hash almacenado no corresponde a 'password'</p>";
    }
    
    // Mostrar hash completo para análisis
    echo "<p><strong>Hash completo:</strong></p>";
    echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>" . htmlspecialchars($usuario['password_hash']) . "</pre>";
    
} else {
    echo "<p style='color: red;'>❌ Supervisor no encontrado con criterios: email=$email_prueba, rol=supervisor, activo=1, estado_aprobacion=aprobado</p>";
}

echo "<br>";

// 3. Verificar estructura de la tabla usuario
echo "<h2>3️⃣  Estructura de tabla usuario</h2>";
$result = $conexion->query("DESCRIBE usuario");
if ($result) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($row['Field']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "<br>";

// 4. Contar usuarios por rol
echo "<h2>4️⃣  Conteo de usuarios por rol</h2>";
$result = $conexion->query("SELECT rol, COUNT(*) as total FROM usuario GROUP BY rol ORDER BY total DESC");
if ($result) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Rol</th><th>Total</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['rol']) . "</td><td>" . $row['total'] . "</td></tr>";
    }
    echo "</table>";
}

$conexion->close();
?>
<hr>
<p><a href="crear_supervisor_prueba.php">← Crear supervisor de prueba</a></p>

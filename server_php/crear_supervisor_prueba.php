<?php
/**
 * Script para crear Supervisor de prueba en SUPER_IA LOGAN
 * Ejecutar en navegador: http://localhost/FUBER_APP/server_php/crear_supervisor_prueba.php
 */

header('Content-Type: text/html; charset=utf-8');

require 'db_config.php';

try {
    // Usar MySQLi para esta operación
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($conexion->connect_error) {
        die("Conexión fallida: " . $conexion->connect_error);
    }

    // Establecer charset
    $conexion->set_charset('utf8mb4');

    // Generar emails únicos con timestamp
    $timestamp = time();
    $email_jefe = "jefe_" . $timestamp . "@superialogan.com";
    $email_supervisor = "supervisor_" . $timestamp . "@superialogan.com";

    // Iniciar transacción
    $conexion->begin_transaction();

    // 1. Crear UUIDs
    $zona_id       = uniqid('zona_', true);
    $agencia_id    = uniqid('agencia_', true);
    $usr_jefe_id   = uniqid('usr_jefe_', true);
    $jefe_ag_id    = uniqid('jefe_ag_', true);
    $usr_sup_id    = uniqid('usr_sup_', true);
    $supervisor_id = uniqid('sup_', true);

    echo "<h2>Creando datos de prueba...</h2>";

    // 2. Zona
    $sql = "INSERT INTO zona (id, nombre, ciudad) VALUES ('$zona_id', 'Zona Centro', 'Cuenca')";
    if (!$conexion->query($sql)) {
        throw new Exception("Error zona: " . $conexion->error);
    }
    echo "✓ Zona creada<br>";

    // 3. Agencia
    $sql = "INSERT INTO agencia (id, zona_id, nombre, ciudad, direccion, activo) 
            VALUES ('$agencia_id', '$zona_id', 'Agencia Principal', 'Cuenca', 'Av. Solano 1-23', 1)";
    if (!$conexion->query($sql)) {
        throw new Exception("Error agencia: " . $conexion->error);
    }
    echo "✓ Agencia creada<br>";

    // 4. Usuario Jefe de Agencia
    $password_hash = password_hash('password', PASSWORD_DEFAULT);
    $sql = "INSERT INTO usuario (id, nombre, email, password_hash, rol, agencia_id, activo, estado_aprobacion)
            VALUES ('$usr_jefe_id', 'Jefe Agencia Prueba', '$email_jefe', '$password_hash', 'jefe_agencia', '$agencia_id', 1, 'aprobado')";
    if (!$conexion->query($sql)) {
        throw new Exception("Error usuario jefe: " . $conexion->error);
    }
    echo "✓ Usuario Jefe de Agencia creado<br>";

    // 5. Perfil jefe_agencia
    $sql = "INSERT INTO jefe_agencia (id, usuario_id, agencia_id) 
            VALUES ('$jefe_ag_id', '$usr_jefe_id', '$agencia_id')";
    if (!$conexion->query($sql)) {
        throw new Exception("Error perfil jefe: " . $conexion->error);
    }
    echo "✓ Perfil Jefe de Agencia creado<br>";

    // 6. Usuario Supervisor  
    $password_hash = password_hash('password', PASSWORD_DEFAULT);
    $sql = "INSERT INTO usuario (id, nombre, email, password_hash, rol, agencia_id, activo, estado_aprobacion)
            VALUES ('$usr_sup_id', 'Carlos Supervisor', '$email_supervisor', '$password_hash', 'supervisor', '$agencia_id', 1, 'aprobado')";
    if (!$conexion->query($sql)) {
        throw new Exception("Error usuario supervisor: " . $conexion->error);
    }
    echo "✓ Usuario Supervisor creado<br>";

    // 7. Perfil supervisor
    $sql = "INSERT INTO supervisor (id, usuario_id, jefe_agencia_id, meta_asesores) 
            VALUES ('$supervisor_id', '$usr_sup_id', '$jefe_ag_id', 5)";
    if (!$conexion->query($sql)) {
        throw new Exception("Error perfil supervisor: " . $conexion->error);
    }
    echo "✓ Perfil Supervisor creado<br>";

    // Commit transacción
    $conexion->commit();

    echo "<hr>";
    echo "<h2 style='color: green;'>✓ Datos de prueba creados exitosamente</h2>";
    echo "<p><strong>Credenciales para login supervisor:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Email:</strong> <code>" . htmlspecialchars($email_supervisor) . "</code></li>";
    echo "<li><strong>Contraseña:</strong> <code>password</code></li>";
    echo "</ul>";

    // Verificar
    echo "<hr>";
    echo "<h3>Verificación en base de datos:</h3>";
    $result = $conexion->query(
        "SELECT u.nombre, u.email, u.rol, u.activo, a.nombre AS agencia 
         FROM usuario u 
         JOIN agencia a ON a.id = u.agencia_id 
         WHERE u.email IN ('$email_supervisor', '$email_jefe')
         ORDER BY u.rol DESC"
    );

    if ($result && $result->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Activo</th><th>Agencia</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['nombre']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['rol']) . "</td>";
            echo "<td>" . ($row['activo'] ? 'Sí' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($row['agencia']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }

    $conexion->close();

} catch (Exception $e) {
    echo "<h2 style='color: red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</h2>";
    if (isset($conexion)) {
        $conexion->rollback();
        $conexion->close();
    }
}
?>

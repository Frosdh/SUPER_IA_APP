<?php
/**
 * Script: Crear supervisores de prueba asociados a la cooperativa
 */

require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
    if ($conexion->connect_error) {
        throw new Exception("Conexión fallida: " . $conexion->connect_error);
    }
    $conexion->set_charset('utf8mb4');

    echo "<h2>Creando datos de prueba: ZONA → AGENCIA → JEFE → SUPERVISOR</h2>";

    // 1. Obtener la cooperativa existente
    $coop_result = $conexion->query("SELECT id FROM unidad_bancaria LIMIT 1");
    if (!$coop_result || $coop_result->num_rows === 0) {
        throw new Exception("No hay cooperativas en la base de datos");
    }
    $coop = $coop_result->fetch_assoc();
    $coop_id = $coop['id'];
    echo "✅ Cooperativa encontrada: $coop_id<br>";

    $conexion->begin_transaction();

    // 2. Crear zona
    $zona_id = bin2hex(random_bytes(16));
    $zona_query = "INSERT INTO zona (id, nombre, ciudad) VALUES ('$zona_id', 'Zona Norte', 'Quito')";
    if (!$conexion->query($zona_query)) {
        throw new Exception("Error creando zona: " . $conexion->error);
    }
    echo "✅ Zona creada: $zona_id<br>";

    // 3. Crear agencia
    $agencia_id = bin2hex(random_bytes(16));
    $agencia_query = "INSERT INTO agencia (id, nombre, zona_id, ciudad, activo) 
                      VALUES ('$agencia_id', 'Agencia Principal', '$zona_id', 'Quito', 1)";
    if (!$conexion->query($agencia_query)) {
        throw new Exception("Error creando agencia: " . $conexion->error);
    }
    echo "✅ Agencia creada: $agencia_id<br>";

    // 4. Crear jefe_agencia (usuario tipo jefe_agencia)
    $jefe_user_id = bin2hex(random_bytes(16));
    $jefe_email = 'jefe_' . time() . '@test.com';
    $jefe_pass = password_hash('password', PASSWORD_DEFAULT);
    
    $jefe_user_query = "INSERT INTO usuario 
                        (id, email, nombre, password_hash, rol, agencia_id, activo, estado_aprobacion) 
                        VALUES ('$jefe_user_id', '$jefe_email', 'Carlos Jefa', '$jefe_pass', 'jefe_agencia', '$agencia_id', 1, 'aprobado')";
    if (!$conexion->query($jefe_user_query)) {
        throw new Exception("Error creando usuario jefe: " . $conexion->error);
    }
    echo "✅ Usuario Jefe creado: $jefe_email<br>";

    // 5. Crear registro de jefe_agencia
    $jefe_agencia_id = bin2hex(random_bytes(16));
    $jefe_agencia_query = "INSERT INTO jefe_agencia (id, usuario_id, agencia_id) 
                           VALUES ('$jefe_agencia_id', '$jefe_user_id', '$agencia_id')";
    if (!$conexion->query($jefe_agencia_query)) {
        throw new Exception("Error creando jefe_agencia: " . $conexion->error);
    }
    echo "✅ Jefe de Agencia creado: $jefe_agencia_id<br>";

    // 6. Crear 3 supervisores
    $supervisores_creados = [];
    for ($i = 1; $i <= 3; $i++) {
        $sup_user_id = bin2hex(random_bytes(16));
        $sup_email = 'supervisor_' . time() . '_' . $i . '@test.com';
        $sup_pass = password_hash('password', PASSWORD_DEFAULT);
        
        $sup_user_query = "INSERT INTO usuario 
                           (id, email, nombre, password_hash, rol, agencia_id, activo, estado_aprobacion) 
                           VALUES ('$sup_user_id', '$sup_email', 'Supervisor $i Prueba', '$sup_pass', 'supervisor', '$agencia_id', 1, 'aprobado')";
        if (!$conexion->query($sup_user_query)) {
            throw new Exception("Error creando usuario supervisor: " . $conexion->error);
        }

        $sup_id = bin2hex(random_bytes(16));
        $sup_query = "INSERT INTO supervisor (id, usuario_id, jefe_agencia_id, meta_asesores) 
                      VALUES ('$sup_id', '$sup_user_id', '$jefe_agencia_id', 5)";
        if (!$conexion->query($sup_query)) {
            throw new Exception("Error creando supervisor: " . $conexion->error);
        }
        
        $supervisores_creados[] = ['email' => $sup_email, 'id' => $sup_user_id];
        echo "✅ Supervisor $i creado: $sup_email<br>";
    }

    $conexion->commit();
    
    echo "<h2>✅ ¡Datos de prueba creados exitosamente!</h2>";
    echo "<h3>Credenciales de supervisores:</h3>";
    echo "<ul>";
    foreach ($supervisores_creados as $sup) {
        echo "<li><strong>" . $sup['email'] . "</strong> / password</li>";
    }
    echo "</ul>";

    $conexion->close();
    
} catch (Exception $e) {
    echo "<h2>❌ Error: " . $e->getMessage() . "</h2>";
    echo "<pre>" . $conexion->error . "</pre>";
}
?>

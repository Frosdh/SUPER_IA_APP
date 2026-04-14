<?php
/**
 * Script: Crear cooperativas/bancos de prueba
 */

require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
    if ($conexion->connect_error) {
        throw new Exception("Conexión fallida: " . $conexion->connect_error);
    }
    $conexion->set_charset('utf8mb4');

    // Verificar si ya existen cooperativas
    $check = $conexion->query("SELECT COUNT(*) as total FROM unidad_bancaria WHERE activo = 1");
    
    if (!$check) {
        throw new Exception("Error en consulta: " . $conexion->error);
    }
    
    $row = $check->fetch_assoc();
    
    if ($row['total'] > 0) {
        echo "<h2>✅ Ya existen " . $row['total'] . " cooperativas activas</h2>";
        $result = $conexion->query("SELECT id, nombre, codigo, ciudad, activo FROM unidad_bancaria LIMIT 10");
        echo "<pre>";
        while ($r = $result->fetch_assoc()) {
            print_r($r);
        }
        echo "</pre>";
    } else {
        echo "<h2>⚠️ No hay cooperativas. Creando datos de prueba...</h2>";
        
        // Crear cooperativas de prueba
        $cooperativas = [
            ['nombre' => 'Banco Pichincha', 'codigo' => 'BP001', 'ciudad' => 'Quito'],
            ['nombre' => 'Banco Central del Ecuador', 'codigo' => 'BCE001', 'ciudad' => 'Guayaquil'],
            ['nombre' => 'Cooperativa COAC', 'codigo' => 'COAC001', 'ciudad' => 'Ambato'],
            ['nombre' => 'Banco Guayaquil', 'codigo' => 'BG001', 'ciudad' => 'Guayaquil'],
            ['nombre' => 'Cooperativa San José', 'codigo' => 'CSJ001', 'ciudad' => 'Cuenca'],
        ];

        $conexion->begin_transaction();
        
        foreach ($cooperativas as $coop) {
            $id = bin2hex(random_bytes(16));
            $nombre = $conexion->real_escape_string($coop['nombre']);
            $codigo = $conexion->real_escape_string($coop['codigo']);
            $ciudad = $conexion->real_escape_string($coop['ciudad']);
            
            $query = "INSERT INTO unidad_bancaria (id, nombre, codigo, ciudad, activo, fecha_creacion) 
                      VALUES ('$id', '$nombre', '$codigo', '$ciudad', 1, NOW())";
            
            if (!$conexion->query($query)) {
                throw new Exception("Error inserting: " . $conexion->error);
            }
            echo "✅ Creada cooperativa: $nombre<br>";
        }
        
        $conexion->commit();
        echo "<h2>✅ Datos de prueba creados exitosamente</h2>";
    }
    
    $conexion->close();
    
} catch (Exception $e) {
    echo "<h2>❌ Error: " . $e->getMessage() . "</h2>";
}
?>

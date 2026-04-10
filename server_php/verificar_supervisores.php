<?php
require_once 'db_config.php';

echo "<h2>🔍 Supervisores en la Base de Datos</h2>";

try {
    // Buscar todos los supervisores en la tabla usuario
    $stmt = $conn->prepare("
        SELECT id, nombre, email, rol, estado_aprobacion, activo
        FROM usuario
        WHERE rol = 'supervisor'
        ORDER BY nombre ASC
    ");
    
    $stmt->execute();
    $supervisores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($supervisores)) {
        echo "<p style='color: red;'><strong>❌ No hay supervisores registrados en tabla 'usuario'</strong></p>";
        echo "<h3>Solución: Ejecuta este script SQL en phpMyAdmin:</h3>";
        echo "<pre style='background: #f0f0f0; padding: 15px; border-radius: 8px; overflow-x: auto;'>";
        echo htmlspecialchars(file_get_contents('crear_supervisor_prueba.sql'));
        echo "</pre>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
        echo "<tr style='background: #4CAF50; color: white;'>";
        echo "<th>Nombre</th>";
        echo "<th>Email</th>";
        echo "<th>Rol</th>";
        echo "<th>Estado</th>";
        echo "<th>Activo</th>";
        echo "</tr>";
        
        foreach ($supervisores as $sup) {
            $activo_style = $sup['activo'] ? 'color: green;' : 'color: red;';
            $aprobado_style = $sup['estado_aprobacion'] === 'aprobado' ? 'color: green;' : 'color: orange;';
            
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($sup['nombre']) . "</strong></td>";
            echo "<td><code>" . htmlspecialchars($sup['email']) . "</code></td>";
            echo "<td>" . htmlspecialchars($sup['rol']) . "</td>";
            echo "<td style='" . $aprobado_style . "'>" . htmlspecialchars($sup['estado_aprobacion']) . "</td>";
            echo "<td style='" . $activo_style . "'>" . ($sup['activo'] ? '✅ Sí' : '❌ No') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<h3 style='margin-top: 20px;'>📋 Usa estas credenciales para iniciar sesión:</h3>";
        echo "<ul>";
        foreach ($supervisores as $sup) {
            echo "<li><strong>Email:</strong> " . htmlspecialchars($sup['email']) . "</li>";
            echo "<li><strong>Contraseña:</strong> <code>password</code> (o la que hayas configurado)</li>";
            if ($sup['estado_aprobacion'] !== 'aprobado') {
                echo "<li style='color: orange;'><strong>⚠️ ALERTA:</strong> Este supervisor está en estado: " . $sup['estado_aprobacion'] . " (debe ser 'aprobado')</li>";
            }
            if (!$sup['activo']) {
                echo "<li style='color: red;'><strong>⚠️ ALERTA:</strong> Este supervisor NO está activo (debe estar activo = 1)</li>";
            }
            echo "<hr>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verificar Supervisores</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        h2, h3 { color: #333; }
        pre { margin: 10px 0; }
        code { background: #e0e0e0; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>

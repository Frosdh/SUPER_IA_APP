<?php
// ============================================================
// check_databases.php - Verificar y Unificar Bases de Datos
// ============================================================

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Unificar Base de Datos SUPER_IA</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .section { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #7C3AED; }
        h2 { color: #0EA5E9; }
        .success { color: green; padding: 10px; background: #e8f5e9; border-left: 4px solid green; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #ffebee; border-left: 4px solid red; margin: 10px 0; }
        .info { background: #e3f2fd; padding: 10px; border-left: 4px solid #2196F3; margin: 10px 0; }
        .warning { background: #fff3e0; padding: 10px; border-left: 4px solid #ff9800; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        code { background: #f0f0f0; padding: 2px 6px; font-family: monospace; }
        .column { display: inline-block; width: 48%; margin: 1%; vertical-align: top; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔌 Unificar Base de Datos SUPER_IA</h1>
";

// Conectar a MySQL
$mysqli = new mysqli('127.0.0.1', 'root', '');

if ($mysqli->connect_error) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($mysqli->connect_error) . "</div>";
    exit;
}

echo "<div class='section'>
    <h2>📊 Bases de Datos Disponibles</h2>";

// Listar bases de datos
$result = $mysqli->query("SHOW DATABASES");
$databases = [];
while ($row = $result->fetch_array()) {
    $db = $row[0];
    // Filtrar solo las relevantes
    if (strpos($db, 'super') !== false || strpos($db, 'corporat') !== false || strpos($db, 'uber') !== false) {
        $databases[] = $db;
    }
}

if (empty($databases)) {
    echo "<div class='warning'>⚠️ No se encontraron bases de datos del proyecto</div>";
} else {
    foreach ($databases as $db) {
        $mysqli->select_db($db);
        $tableResult = $mysqli->query("SHOW TABLES");
        $tableCount = $tableResult->num_rows;
        
        echo "<div class='info'><strong>$db</strong> - $tableCount tablas</div>";
        
        // Listar tablas
        echo "<table>
            <tr>
                <th>Tabla</th>
                <th>Registros</th>
                <th>Campos</th>
            </tr>";
        
        while ($tableRow = $tableResult->fetch_array()) {
            $table = $tableRow[0];
            $countRes = $mysqli->query("SELECT COUNT(*) as cnt FROM `{$table}`");
            $count = $countRes->fetch_assoc()['cnt'];
            
            $fieldsRes = $mysqli->query("DESCRIBE `{$table}`");
            $fieldCount = $fieldsRes->num_rows;
            
            echo "<tr>
                <td><code>{$table}</code></td>
                <td>$count</td>
                <td>$fieldCount</td>
            </tr>";
        }
        echo "</table>";
    }
}

echo "</div>";

// Analizar tablas de usuarios
echo "<div class='section'>
    <h2>👥 Análisis de Tabla de Usuarios</h2>";

foreach ($databases as $db) {
    $mysqli->select_db($db);
    
    // Buscar tabla de usuarios
    $tableResult = $mysqli->query("SHOW TABLES LIKE '%usuario%'");
    if ($tableResult->num_rows > 0) {
        $tableRow = $tableResult->fetch_array();
        $userTable = $tableRow[0];
        
        echo "<div class='info'><strong>Base de datos: $db</strong> | Tabla: <code>$userTable</code></div>";
        
        // Mostrar estructura
        $fieldsRes = $mysqli->query("DESCRIBE `{$userTable}`");
        echo "<table>
            <tr>
                <th>Campo</th>
                <th>Tipo</th>
                <th>Nulo</th>
                <th>Clave</th>
                <th>Por defecto</th>
            </tr>";
        
        while ($field = $fieldsRes->fetch_assoc()) {
            echo "<tr>
                <td><code>" . htmlspecialchars($field['Field']) . "</code></td>
                <td>" . htmlspecialchars($field['Type']) . "</td>
                <td>" . htmlspecialchars($field['Null']) . "</td>
                <td>" . htmlspecialchars($field['Key']) . "</td>
                <td>" . htmlspecialchars($field['Default'] ?? '-') . "</td>
            </tr>";
        }
        echo "</table>";
        
        // Mostrar registros
        $recordsRes = $mysqli->query("SELECT * FROM `{$userTable}` LIMIT 5");
        $recordCount = $recordsRes->num_rows;
        
        echo "<div class='info'>Total de registros: <strong>$recordCount</strong></div>";
        
        if ($recordCount > 0) {
            echo "<table>
                <tr>";
            
            // Headers
            $fields = $recordsRes->fetch_fields();
            foreach ($fields as $field) {
                echo "<th>" . htmlspecialchars($field->name) . "</th>";
            }
            echo "</tr>";
            
            // Reset y mostrar datos
            $recordsRes = $mysqli->query("SELECT * FROM `{$userTable}` LIMIT 5");
            while ($record = $recordsRes->fetch_assoc()) {
                echo "<tr>";
                foreach ($record as $value) {
                    echo "<td>" . htmlspecialchars(substr((string)$value, 0, 50)) . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        }
    }
}

echo "</div>";

// Resumen y recomendaciones
echo "<div class='section'>
    <h2>✅ Recomendaciones</h2>
    <ol>
        <li>Verifica cuál es la base de datos actual que quieres usar</li>
        <li>Revisa la configuración de <code>server_php/db_config.php</code> (host, usuario, base de datos)</li>
        <li>Si necesitas cambiar, edita <code>server_php/db_config.php</code></li>
        <li>Los scripts de login y registro han sido adaptados a la estructura nueva</li>
    </ol>
</div>";

// Link a tester
echo "<div class='section'>
    <h2>🧪 Probar Sistemas</h2>
    <a href='test_login_registro.php' style='color: #7C3AED; text-decoration: none; font-weight: bold;'>→ Ver Estado de Login/Registro</a>
</div>";

$mysqli->close();

echo "
    </div>
</body>
</html>";
?>

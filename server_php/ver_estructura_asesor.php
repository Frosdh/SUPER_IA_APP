<?php
require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    $conexion->set_charset('utf8mb4');

    echo "<h2>Estructura de la tabla ASESOR:</h2>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th></tr>";
    
    $result = $conexion->query("DESCRIBE asesor");
    $campos = [];
    while ($row = $result->fetch_assoc()) {
        $campos[] = $row['Field'];
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<h3>Campos encontrados:</h3>";
    foreach ($campos as $c) {
        echo "- " . $c . "<br>";
    }

    $conexion->close();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

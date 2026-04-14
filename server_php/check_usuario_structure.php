<?php
require_once 'db_config.php';
$conn = new mysqli($db_host, $db_user, $db_password, $db_name);
$conn->set_charset('utf8mb4');

echo "=== ESTRUCTURA DE TABLA USUARIO ===\n";
$result = $conn->query('DESCRIBE usuario');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " | " . $row['Type'] . " | " . ($row['Null'] == 'YES' ? 'NULL' : 'NO NULL') . "\n";
}
?>

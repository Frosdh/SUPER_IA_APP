<?php
require 'db_config.php';
$conn = new mysqli($db_host, $db_user, $db_password, $db_name);
$conn->set_charset('utf8mb4');
$result = $conn->query('DESCRIBE asesor');
echo "Columnas en tabla asesor:\n";
while($row = $result->fetch_assoc()) {
    echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>

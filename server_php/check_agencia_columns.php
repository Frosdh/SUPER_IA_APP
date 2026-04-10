<?php
require 'db_config.php';
$conn = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
$conn->set_charset('utf8mb4');

echo "=== TABLA AGENCIA ===\n";
$result = $conn->query('DESCRIBE agencia');
while($row = $result->fetch_assoc()) {
    echo "- " . $row['Field'] . "\n";
}
?>

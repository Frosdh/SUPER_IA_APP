<?php
require_once 'db_admin.php';
echo "--- CATEGORIAS ---\n";
$cat = $pdo->query("SELECT * FROM categorias")->fetchAll();
print_r($cat);
echo "\n--- COLUMNS IN CONDUCTORES ---\n";
$col = $pdo->query("DESCRIBE conductores")->fetchAll();
print_r($col);
echo "\n--- COLUMNS IN VIAJES ---\n";
$colv = $pdo->query("DESCRIBE viajes")->fetchAll();
print_r($colv);
?>

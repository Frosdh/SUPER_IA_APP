<?php
require_once 'c:/xampp/htdocs/SUPER_IA/server_php/db_config.php';
$tables = ['encuesta_negocio', 'encuesta_comercial', 'cliente_prospecto'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $st = $conn->query("DESCRIBE $table");
    while ($row = $st->fetch_assoc()) {
        echo "{$row['Field']} ({$row['Type']})\n";
    }
}
?>

<?php
require_once 'db_admin.php';
$tables = ['encuesta_negocio', 'encuesta_comercial', 'cliente_prospecto'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $st = $pdo->query("DESCRIBE $table");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} ({$row['Type']})\n";
    }
}
?>

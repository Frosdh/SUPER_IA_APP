<?php
require 'server_php/db_admin.php';

$st = $pdo->query('DESCRIBE cliente_prospecto');
foreach($st->fetchAll(PDO::FETCH_ASSOC) as $col) {
    if($col['Field'] === 'actividad' || $col['Field'] === 'nombre_empresa') {
        echo $col['Field'] . ': ' . $col['Type'] . ' | Null: ' . $col['Null'] . PHP_EOL;
    }
}

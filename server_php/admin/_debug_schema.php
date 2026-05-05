<?php
require __DIR__ . '/db_admin.php';
header('Content-Type: text/plain; charset=utf-8');

echo "DB OK\n";

$tables = ['tarea','encuesta_comercial','ficha_producto'];
foreach ($tables as $t) {
    echo "\n== $t ==\n";
    try {
        $st = $pdo->query("DESCRIBE `$t`");
        $cols = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            $field = $c['Field'] ?? '';
            $type  = $c['Type'] ?? '';
            $null  = $c['Null'] ?? '';
            $def   = array_key_exists('Default', $c) ? ($c['Default'] === null ? 'NULL' : $c['Default']) : '';
            echo "$field | $type | Null:$null | Default:$def\n";
        }
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

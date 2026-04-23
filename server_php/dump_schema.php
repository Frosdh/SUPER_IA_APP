<?php
require_once 'admin/db_admin.php';

try {
    $tables = ['tarea', 'cliente_prospecto', 'credito_proceso', 'recuperacion'];
    foreach ($tables as $t) {
        echo "Table: $t\n";
        $stmt = $pdo->query("DESCRIBE $t");
        if ($stmt) {
            $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                echo "{$c['Field']} - {$c['Type']}\n";
            }
        } else {
            echo "Table not found.\n";
        }
        echo "--------------------------\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}

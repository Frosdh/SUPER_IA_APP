<?php
require_once 'db_admin.php';
$table = 'kpi_asesor';
echo "--- $table ---\n";
try {
    $st = $pdo->query("DESCRIBE $table");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['Field']} ({$row['Type']})\n";
    }
} catch (Exception $e) {
    echo "Table not found or error: " . $e->getMessage();
}
?>

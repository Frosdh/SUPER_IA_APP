<?php
require_once 'db_admin.php';
$st = $pdo->query("SHOW CREATE VIEW v_meta_asesor_avance");
$row = $st->fetch();
echo $row['Create View'];
?>

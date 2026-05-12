<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=corporat_base_super_ia", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "SUCCESS: Connected as root\n";
    $st = $pdo->query("SHOW CREATE VIEW v_meta_asesor_avance");
    $row = $st->fetch();
    echo $row['Create View'];
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>

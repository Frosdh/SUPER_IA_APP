<?php
// test_pass.php

$passes = ['', 'root', 'admin', '123456', 'mysql', '1234', '*6LuhePgy=9?Zy-&'];

foreach ($passes as $p) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1', 'root', $p);
        echo "SUCCESS: connected with password: '$p'\n";
        
        $res = $pdo->query("SHOW DATABASES");
        while ($row = $res->fetchColumn()) {
            echo "  DB: $row\n";
        }
        $pdo = null;
        break;
    } catch (Exception $e) {
        echo "Failed with password '$p': " . $e->getMessage() . "\n";
    }
}

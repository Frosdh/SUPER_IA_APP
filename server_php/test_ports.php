<?php
// test_ports.php

$configs = [
    ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => ''],
    ['host' => '127.0.0.1', 'port' => 3308, 'user' => 'root', 'pass' => ''],
    ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'corporat_coac_user', 'pass' => '*6LuhePgy=9?Zy-&'],
    ['host' => '127.0.0.1', 'port' => 3308, 'user' => 'corporat_coac_user', 'pass' => '*6LuhePgy=9?Zy-&'],
];

foreach ($configs as $c) {
    try {
        echo "Testing {$c['user']}@{$c['host']}:{$c['port']} ... ";
        $conn = new mysqli($c['host'], $c['user'], $c['pass'], '', $c['port']);
        if ($conn->connect_error) {
            echo "FAILED: " . $conn->connect_error . "\n";
        } else {
            echo "SUCCESS!\n";
            $res = $conn->query("SHOW DATABASES");
            while ($row = $res->fetch_row()) {
                echo "  Database: " . $row[0] . "\n";
            }
            $conn->close();
        }
    } catch (Exception $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
    }
}

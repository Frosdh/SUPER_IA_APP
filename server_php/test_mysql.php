<?php
// test_mysql.php

$tests = [
    ['host' => '127.0.0.1', 'user' => 'root', 'pass' => ''],
    ['host' => 'localhost', 'user' => 'root', 'pass' => ''],
    ['host' => '127.0.0.1', 'user' => 'corporat_coac_user', 'pass' => '*6LuhePgy=9?Zy-&'],
    ['host' => 'localhost', 'user' => 'corporat_coac_user', 'pass' => '*6LuhePgy=9?Zy-&'],
];

foreach ($tests as $t) {
    try {
        $mysqli = @new mysqli($t['host'], $t['user'], $t['pass']);
        if ($mysqli->connect_error) {
            echo "Failed: {$t['user']}@{$t['host']} - Error: " . $mysqli->connect_error . "\n";
        } else {
            echo "SUCCESS: Connected as {$t['user']}@{$t['host']}!\n";
            // Show databases
            $res = $mysqli->query("SHOW DATABASES");
            while ($row = $res->fetch_row()) {
                echo "  Database: " . $row[0] . "\n";
            }
            $mysqli->close();
        }
    } catch (Exception $e) {
        echo "Exception: {$t['user']}@{$t['host']} - " . $e->getMessage() . "\n";
    }
}

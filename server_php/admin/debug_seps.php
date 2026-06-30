<?php
// debug_seps.php — BORRAR después de diagnosticar
require_once 'db_admin.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO SEPS ===\n\n";

// 1. Total en seps_cooperativas
try {
    $total    = $pdo->query("SELECT COUNT(*) FROM seps_cooperativas")->fetchColumn();
    $activos  = $pdo->query("SELECT COUNT(*) FROM seps_cooperativas WHERE activo=1")->fetchColumn();
    echo "seps_cooperativas total : $total\n";
    echo "seps_cooperativas activo=1: $activos\n\n";
} catch(PDOException $e) { echo "ERROR tabla seps: " . $e->getMessage() . "\n\n"; }

// 2. Primeras 5 filas
try {
    $rows = $pdo->query("SELECT id, razon_social, activo FROM seps_cooperativas LIMIT 5")->fetchAll();
    echo "Primeras 5 filas:\n";
    foreach ($rows as $r) echo "  id={$r['id']} activo={$r['activo']} nombre={$r['razon_social']}\n";
    echo "\n";
} catch(PDOException $e) { echo "ERROR leyendo filas: " . $e->getMessage() . "\n\n"; }

// 3. Total unidad_bancaria
try {
    $ub = $pdo->query("SELECT COUNT(*) FROM unidad_bancaria")->fetchColumn();
    echo "unidad_bancaria total: $ub\n\n";
} catch(PDOException $e) { echo "ERROR unidad_bancaria: " . $e->getMessage() . "\n\n"; }

// 4. Probar el mismo query de nueva_encuesta.php
try {
    $r2 = $pdo->query("SELECT razon_social AS nombre FROM seps_cooperativas WHERE activo=1 ORDER BY razon_social LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    echo "Query directa SEPS (primeros 5):\n";
    foreach ($r2 as $n) echo "  - $n\n";
    echo "\n";
} catch(PDOException $e) { echo "ERROR query SEPS: " . $e->getMessage() . "\n\n"; }

// 5. Columnas de seps_cooperativas
try {
    $cols = $pdo->query("DESCRIBE seps_cooperativas")->fetchAll(PDO::FETCH_COLUMN);
    echo "Columnas de seps_cooperativas:\n  " . implode(', ', $cols) . "\n\n";
} catch(PDOException $e) { echo "ERROR DESCRIBE: " . $e->getMessage() . "\n"; }

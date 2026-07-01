<?php
// debug_seps.php — standalone, sin sesión requerida. BORRAR después.
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$conn = new mysqli("localhost", "corporat_coac_user", '*6LuhePgy=9?Zy-&', "corporat_base_super_ia");
if ($conn->connect_error) { die("CONEXION FALLIDA: " . $conn->connect_error); }
$conn->set_charset('utf8mb4');
echo "CONEXION OK\n\n";

// seps_cooperativas
$r = $conn->query("SELECT COUNT(*) AS t FROM seps_cooperativas");
$t = $r ? $r->fetch_assoc()['t'] : 'ERROR: '.$conn->error;
echo "seps_cooperativas total: $t\n";

$r = $conn->query("SELECT COUNT(*) AS t FROM seps_cooperativas WHERE activo=1");
$t = $r ? $r->fetch_assoc()['t'] : 'ERROR: '.$conn->error;
echo "seps_cooperativas activo=1: $t\n\n";

// Primeras 3 filas
$r = $conn->query("SELECT id, razon_social, activo FROM seps_cooperativas LIMIT 3");
if ($r) {
    echo "Primeras filas:\n";
    while ($row = $r->fetch_assoc())
        echo "  id={$row['id']} activo={$row['activo']} nombre={$row['razon_social']}\n";
} else echo "ERROR filas: ".$conn->error."\n";
echo "\n";

// unidad_bancaria
$r = $conn->query("SELECT nombre FROM unidad_bancaria LIMIT 5");
echo "unidad_bancaria:\n";
if ($r) { while ($row=$r->fetch_assoc()) echo "  - {$row['nombre']}\n"; }
else echo "  ERROR: ".$conn->error."\n";
echo "\n";

// Simular api_cooperativas
$r = $conn->query("SELECT razon_social FROM seps_cooperativas WHERE activo=1 LIMIT 5");
echo "API query test (primeras 5 de SEPS activo=1):\n";
if ($r) { while ($row=$r->fetch_assoc()) echo "  - {$row['razon_social']}\n"; }
else echo "  ERROR: ".$conn->error."\n";
$conn->close();

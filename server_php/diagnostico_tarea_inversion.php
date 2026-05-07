<?php
require_once __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$resultado = [];

// 1. Ver el ENUM actual de tipo_tarea en la tabla tarea
$col = $conn->query("SHOW COLUMNS FROM tarea LIKE 'tipo_tarea'")->fetch_assoc();
$resultado['enum_actual'] = $col ? $col['Type'] : 'NO SE PUDO LEER';
$resultado['tiene_nueva_cita_inversion'] = $col ? (strpos($col['Type'], "'nueva_cita_inversion'") !== false ? 'SI' : 'NO') : 'NO SE PUDO VERIFICAR';

// 2. Intentar agregar nueva_cita_inversion si no está
if ($col && strpos($col['Type'], "'nueva_cita_inversion'") === false) {
    preg_match("/enum\((.+)\)/i", $col['Type'], $m);
    $currentVals = isset($m[1]) ? $m[1] : "'prospecto_nuevo'";
    $newVals = $currentVals . ",'nueva_cita_inversion'";
    $ok = $conn->query("ALTER TABLE tarea MODIFY COLUMN tipo_tarea ENUM($newVals) NOT NULL DEFAULT 'prospecto_nuevo'");
    $resultado['alter_ejecutado'] = $ok ? 'EXITOSO' : ('FALLO: ' . $conn->error);
    
    // Verificar resultado
    $col2 = $conn->query("SHOW COLUMNS FROM tarea LIKE 'tipo_tarea'")->fetch_assoc();
    $resultado['enum_despues_alter'] = $col2 ? $col2['Type'] : 'NO SE PUDO LEER';
} else {
    $resultado['alter_ejecutado'] = 'NO NECESARIO (ya existe)';
}

// 3. Buscar tareas de tipo nueva_cita_inversion en la DB (últimas 20)
$rows = [];
$res = $conn->query("SELECT id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, created_at FROM tarea WHERE tipo_tarea = 'nueva_cita_inversion' ORDER BY created_at DESC LIMIT 20");
if ($res) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
}
$resultado['tareas_nueva_cita_inversion'] = $rows;
$resultado['total_encontradas'] = count($rows);

// 4. Verificar asesores disponibles
$asesores = [];
$resA = $conn->query("SELECT id, usuario_id FROM asesor LIMIT 10");
if ($resA) {
    while ($r = $resA->fetch_assoc()) $asesores[] = $r;
}
$resultado['asesores'] = $asesores;

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

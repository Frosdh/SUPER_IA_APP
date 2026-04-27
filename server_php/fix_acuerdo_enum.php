<?php
// ============================================================
// fix_acuerdo_enum.php  —  Migración de columnas de acuerdo
// ------------------------------------------------------------
// Corre UNA sola vez en producción:
//   http://corporativoqbank.com/SUPER_IA/server_php/fix_acuerdo_enum.php
// ============================================================
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_config.php';
mysqli_report(MYSQLI_REPORT_OFF);   // sin excepciones: usamos retorno bool

$results = [];
$ev = "'nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro'";
$ed = "ENUM($ev)";

// 1. Limpiar filas con valores inválidos en tablas nullable
foreach (['encuesta_comercial','encuesta_crediticia'] as $t) {
    $ok = $conn->query("UPDATE $t SET acuerdo_logrado=NULL WHERE acuerdo_logrado IS NOT NULL AND acuerdo_logrado NOT IN ($ev)");
    $results[] = ['tabla'=>$t,'paso'=>'UPDATE','status'=>$ok?'ok':'error','error'=>$ok?null:$conn->error];
}

// 2. Ampliar ENUM de tablas nullable
foreach (['encuesta_comercial'=>'acuerdo_logrado','encuesta_crediticia'=>'acuerdo_logrado'] as $t=>$col) {
    $ok = $conn->query("ALTER TABLE $t MODIFY COLUMN $col $ed NULL");
    $results[] = ['tabla'=>$t,'paso'=>'ALTER','status'=>$ok?'ok':'error','error'=>$ok?null:$conn->error];
}

// 3. acuerdo_visita: convertir tipo_acuerdo a VARCHAR(30)
//    (evita problemas con ENUMs restrictivos; acepta cualquier valor)
$ok = $conn->query("ALTER TABLE acuerdo_visita MODIFY COLUMN tipo_acuerdo VARCHAR(30) NOT NULL");
$results[] = ['tabla'=>'acuerdo_visita','paso'=>'ALTER VARCHAR','status'=>$ok?'ok':'error','error'=>$ok?null:$conn->error];

$conn->close();
$all_ok = count(array_filter($results, fn($r) => $r['status']==='error')) === 0;

echo json_encode([
    'status'  => $all_ok ? 'done' : 'partial',
    'message' => $all_ok
        ? 'Migración completada. La encuesta ya acepta todos los acuerdos.'
        : 'Migración parcial. Revisa "results" para ver qué falló.',
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

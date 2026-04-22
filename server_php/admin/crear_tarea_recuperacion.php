<?php
// Endpoint AJAX para crear tareas de recuperación a partir de créditos aprobados
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php'; // proporciona $pdo

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    echo json_encode(['status'=>'error','message'=>'Acceso denegado']); exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$creditos = [];
// allow passing cliente_id to resolve latest credito_proceso
$cliente_id_in = !empty($payload['cliente_id']) ? (string)$payload['cliente_id'] : null;
if (!empty($payload['credito_ids'])) {
    if (is_array($payload['credito_ids'])) $creditos = $payload['credito_ids'];
    else $creditos = array_filter(array_map('trim', explode(',', (string)$payload['credito_ids'])));
} elseif (!empty($payload['credito_id'])) {
    $creditos = [ (string)$payload['credito_id'] ];
}
if (empty($creditos)) { echo json_encode(['status'=>'error','message'=>'credito_id(s) requerido']); exit; }

$asesor_override = !empty($payload['asesor_id']) ? (string)$payload['asesor_id'] : null;
$mensaje = trim((string)($payload['mensaje'] ?? 'Recuperación: contactar cliente por cuotas pendientes'));
$fecha_prog = trim((string)($payload['fecha_programada'] ?? date('Y-m-d')));

$created = [];
foreach ($creditos as $cid) {
    // if cliente_id provided and credito id is empty, try to find latest credito_proceso for client
    if ((empty($cid) || $cid === '') && $cliente_id_in) {
        try {
            $st0 = $pdo->prepare('SELECT id FROM credito_proceso WHERE cliente_prospecto_id = ? ORDER BY created_at DESC LIMIT 1');
            $st0->execute([$cliente_id_in]);
            $found = $st0->fetchColumn();
            if ($found) $cid = $found;
        } catch (Throwable $_) { }
    }
    try {
        $st = $pdo->prepare('SELECT cliente_prospecto_id, asesor_id FROM credito_proceso WHERE id = ? LIMIT 1');
        $st->execute([$cid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) continue;
        $cliente_id = $r['cliente_prospecto_id'];
        $asesor_id = $asesor_override ?: ($r['asesor_id'] ?: null);
        if (!$asesor_id) continue;

        $tarea_id = bin2hex(random_bytes(16));
        $est = 'programada';
        $tipo = 'recuperacion';
        $obs = $mensaje . ' (origen_credito:' . $cid . ')';

        $ins = $pdo->prepare("INSERT INTO tarea (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$tarea_id, $asesor_id, $cliente_id, $tipo, $est, $fecha_prog, $obs]);
        $created[] = ['credito_id'=>$cid, 'tarea_id'=>$tarea_id];
    } catch (Throwable $e) {
        // ignorar y continuar
    }
}

if (empty($created)) echo json_encode(['status'=>'error','message'=>'No se crearon tareas (verifique datos)']);
else echo json_encode(['status'=>'success','created'=>$created]);

?>

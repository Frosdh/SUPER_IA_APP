<?php
require_once 'db_admin.php';

// Verificar sesión según rol
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
    $user_id = $_SESSION['super_admin_id'];
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
    $user_id = $_SESSION['admin_id'];
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
    $user_id = $_SESSION['supervisor_id'];
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    $user_id = $_SESSION['asesor_id'];
} else {
    header('Location: login.php?role=admin');
    exit;
}

// Resolver IDs reales de tablas supervisor/asesor cuando la sesión guarda usuario.id
$supervisor_table_id = null;
$asesor_table_id = null;
if ($user_role === 'supervisor') {
    $stmtSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = :uid LIMIT 1');
    $stmtSup->execute([':uid' => $user_id]);
    $supervisor_table_id = $stmtSup->fetchColumn();
}
if ($user_role === 'asesor') {
    $stmtAs = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = :uid LIMIT 1');
    $stmtAs->execute([':uid' => $user_id]);
    $asesor_table_id = $stmtAs->fetchColumn();
}

// ======================
// 1. Alertas de modificaciones de tareas
// ======================
if ($user_role === 'super_admin' || $user_role === 'admin') {
    // Se agregan los JOIN hacia unidad_bancaria (via supervisor/jefe_agencia/
    // agencia) para poder filtrar el listado por banco/cooperativa en la UI.
    $sqlAlertas = "
        SELECT
            am.id as id_alerta,
            am.tarea_id as tarea_id,
            am.valor_anterior as valor_anterior,
            am.valor_nuevo as valor_nuevo,
            'Modificación de tarea' as tipo,
            CONCAT('La tarea ', t.id, ' fue modificada por el asesor ', u_asesor.nombre) as mensaje,
            cp.nombre as cliente_nombre,
            u_asesor.nombre as asesor_nombre,
            am.created_at as fecha,
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado,
            ub.id as banco_id,
            ub.nombre as banco_nombre
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        JOIN asesor a ON am.asesor_id = a.id
        JOIN usuario u_asesor ON a.usuario_id = u_asesor.id
        LEFT JOIN supervisor sv_al ON sv_al.id = a.supervisor_id
        LEFT JOIN jefe_agencia ja_al ON ja_al.id = sv_al.jefe_agencia_id
        LEFT JOIN agencia ag_al ON ag_al.id = ja_al.agencia_id
        LEFT JOIN unidad_bancaria ub ON ub.id = ag_al.unidad_bancaria_id
        ORDER BY am.created_at DESC
    ";
    $stmt = $pdo->query($sqlAlertas);
    $alertas = $stmt->fetchAll();
    $col_asesor = true;

} elseif ($user_role === 'supervisor') {
    $sqlAlertas = "
        SELECT
            am.id as id_alerta,
            am.tarea_id as tarea_id,
            am.valor_anterior as valor_anterior,
            am.valor_nuevo as valor_nuevo,
            'Modificación de tarea' as tipo,
            CONCAT('La tarea ', t.id, ' fue modificada por el asesor ', u_asesor.nombre) as mensaje,
            cp.nombre as cliente_nombre,
            cp.cedula as cliente_cedula,
            u_asesor.nombre as asesor_nombre,
            am.created_at as fecha,
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        JOIN asesor a ON am.asesor_id = a.id
        JOIN usuario u_asesor ON a.usuario_id = u_asesor.id
        WHERE a.supervisor_id = :supervisor_id
        ORDER BY am.created_at DESC
    ";
    $stmt = $pdo->prepare($sqlAlertas);
    $stmt->execute([':supervisor_id' => $supervisor_table_id ?: '']);
    $alertas = $stmt->fetchAll();
    $col_asesor = true;

} else { // asesor
    $sqlAlertas = "
        SELECT
            am.id as id_alerta,
            am.tarea_id as tarea_id,
            am.valor_anterior as valor_anterior,
            am.valor_nuevo as valor_nuevo,
            'Modificación de tarea' as tipo,
            CONCAT('La tarea ', t.id, ' fue modificada') as mensaje,
            cp.nombre as cliente_nombre,
            am.created_at as fecha,
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        WHERE am.asesor_id = :asesor_id
        ORDER BY am.created_at DESC
    ";
    $stmt = $pdo->prepare($sqlAlertas);
    $stmt->execute([':asesor_id' => $asesor_table_id ?: '']);
    $alertas = $stmt->fetchAll();
    $col_asesor = false;
}

// Dedupe alerts by tarea_id
$deduped = [];
foreach ($alertas as $row) {
    $tid = $row['tarea_id'] ?? null;
    if ($tid === null) { $deduped[] = $row; continue; }
    if (!isset($deduped[$tid])) { $deduped[$tid] = $row; continue; }
    $cur = $deduped[$tid];
    $cur_has_prev = !empty($cur['valor_anterior']);
    $row_has_prev = !empty($row['valor_anterior']);
    if ($row_has_prev && !$cur_has_prev) { $deduped[$tid] = $row; continue; }
    $cur_time = strtotime($cur['fecha'] ?? '1970-01-01');
    $row_time = strtotime($row['fecha'] ?? '1970-01-01');
    if ($row_time > $cur_time) $deduped[$tid] = $row;
}
$alertas = array_values($deduped);

function extract_cliente_from_snapshot($txt) {
    if (empty($txt)) return null;
    $d = json_decode($txt, true);
    if ($d && is_array($d)) {
        if (!empty($d['cliente']) && is_array($d['cliente'])) {
            $c = $d['cliente'];
            if (!empty($c['nombre'])) return $c['nombre'];
            if (!empty($c['nombre_completo'])) return $c['nombre_completo'];
            if (!empty($c['nombre_cliente'])) return $c['nombre_cliente'];
        }
        if (!empty($d['summary']) && is_string($d['summary'])) return $d['summary'];
    }
    return null;
}

function extract_cliente_details($txt) {
    $out = ['name'=>null,'phone'=>null,'email'=>null,'tramites'=>[], 'cedula'=>null];
    if (empty($txt)) return $out;
    $d = json_decode($txt, true);
    if (!is_array($d)) return $out;
    if (!empty($d['cliente']) && is_array($d['cliente'])) {
        $c = $d['cliente'];
        $out['name'] = $c['nombre'] ?? $c['nombre_completo'] ?? $out['name'];
        $out['phone'] = $c['telefono'] ?? $c['telefono2'] ?? $out['phone'];
        $out['email'] = $c['email'] ?? $c['email_cliente'] ?? $out['email'];
        $out['cedula'] = $c['cedula'] ?? $c['identificacion'] ?? $c['dni'] ?? null;
    }
    if (!empty($d['encuesta_comercial']) && is_array($d['encuesta_comercial'])) {
        $e = $d['encuesta_comercial'];
        if (!empty($e['tiene_inversiones']) || !empty($e['valor_inversion'])) $out['tramites'][] = 'Inversión';
        if (!empty($e['interes_cc'])) $out['tramites'][] = 'Cuenta Débito';
        if (!empty($e['interes_ahorro'])) $out['tramites'][] = 'Cuenta Ahorros';
        if (!empty($e['interes_credito'])) $out['tramites'][] = 'Interés Crédito';
        if (!empty($e['interes_inversion'])) $out['tramites'][] = 'Interés Inversión';
        if (!empty($e['acuerdo_logrado']) && $e['acuerdo_logrado'] !== 'ninguno') $out['tramites'][] = 'Acuerdo: ' . $e['acuerdo_logrado'];
    }
    if (!empty($d['acuerdo_visita']) && is_array($d['acuerdo_visita'])) {
        $a = $d['acuerdo_visita'];
        if (!empty($a['tipo_acuerdo'])) $out['tramites'][] = 'Acuerdo visita: ' . $a['tipo_acuerdo'];
    }
    $out['tramites'] = array_values(array_unique($out['tramites']));
    return $out;
}

function collect_tramites_from_decoded($d) {
    $out = [];
    if (!is_array($d)) return $out;
    if (!empty($d['encuesta_comercial']) && is_array($d['encuesta_comercial'])) {
        $e = $d['encuesta_comercial'];
        if (!empty($e['tiene_inversiones']) || !empty($e['valor_inversion'])) $out[] = 'inversion';
        if (!empty($e['interes_cc'])) $out[] = 'cuenta_debito';
        if (!empty($e['interes_ahorro'])) $out[] = 'cuenta_ahorros';
        if (!empty($e['interes_credito'])) $out[] = 'interes_credito';
        if (!empty($e['interes_inversion'])) $out[] = 'interes_inversion';
        if (!empty($e['acuerdo_logrado']) && $e['acuerdo_logrado'] !== 'ninguno') $out[] = 'acuerdo_' . $e['acuerdo_logrado'];
    }
    if (!empty($d['acuerdo_visita']) && is_array($d['acuerdo_visita'])) {
        $a = $d['acuerdo_visita'];
        if (!empty($a['tipo_acuerdo'])) $out[] = 'acuerdo_visita_' . $a['tipo_acuerdo'];
    }
    return array_values(array_unique($out));
}

function compare_tramites($prevTxt, $newTxt) {
    $prev = []; $new = [];
    if (!empty($prevTxt)) { $d = json_decode($prevTxt, true); if (is_array($d)) $prev = collect_tramites_from_decoded($d); }
    if (!empty($newTxt))  { $d2 = json_decode($newTxt, true); if (is_array($d2)) $new = collect_tramites_from_decoded($d2); }
    $added = array_values(array_diff($new, $prev));
    $removed = array_values(array_diff($prev, $new));
    $changes = [];
    foreach ($added as $k) $changes[] = ['key'=>$k,'status'=>'added'];
    foreach ($removed as $k) $changes[] = ['key'=>$k,'status'=>'removed'];
    return $changes;
}

function tramite_label_and_color($key) {
    $map = [
        'inversion' => ['Inversión','success'],
        'cuenta_debito' => ['Cuenta Débito','primary'],
        'cuenta_ahorros' => ['Cuenta Ahorros','info'],
        'interes_credito' => ['Interés Crédito','danger'],
        'interes_inversion' => ['Interés Inversión','warning'],
        'acuerdo_nueva_cita_campo' => ['Acuerdo: Cita Campo','warning'],
        'acuerdo_nueva_cita_oficina' => ['Acuerdo: Cita Oficina','warning'],
        'acuerdo_reprogramacion' => ['Acuerdo: Reprogramación','warning'],
        'acuerdo_seguimiento' => ['Acuerdo: Seguimiento','warning'],
        'acuerdo_otro' => ['Acuerdo: Otro','warning'],
        'acuerdo_visita_nueva_cita_campo' => ['Acuerdo Visita: Cita Campo','warning'],
        'acuerdo_visita_nueva_cita_oficina' => ['Acuerdo Visita: Cita Oficina','warning'],
    ];
    if (isset($map[$key])) return $map[$key];
    return [ucfirst(str_replace(['_','-'], [' ',' '], $key)), 'secondary'];
}

foreach ($alertas as &$a) {
    $ant_txt = $a['valor_anterior'] ?? null;
    $new_txt = $a['valor_nuevo'] ?? null;
    $ant_det = extract_cliente_details($ant_txt);
    $new_det = extract_cliente_details($new_txt);
    
    if ($ant_det['name']) {
        $a['cliente_nombre_display'] = $ant_det['name'];
        $a['cliente_cedula_display'] = $ant_det['cedula'] ?? ($a['cliente_cedula'] ?? 'S/N');
        $a['cliente_phone'] = $ant_det['phone'];
        $a['cliente_email'] = $ant_det['email'];
        $a['cliente_tramites'] = compare_tramites($ant_txt, $new_txt);
    } elseif ($new_det['name']) {
        $a['cliente_nombre_display'] = $new_det['name'];
        $a['cliente_cedula_display'] = $new_det['cedula'] ?? ($a['cliente_cedula'] ?? 'S/N');
        $a['cliente_phone'] = $new_det['phone'];
        $a['cliente_email'] = $new_det['email'];
        $a['cliente_tramites'] = compare_tramites($ant_txt, $new_txt);
    } else {
        $a['cliente_nombre_display'] = $a['cliente_nombre'] ?? 'Sin cliente';
        $a['cliente_cedula_display'] = $a['cliente_cedula'] ?? 'S/N';
        $a['cliente_phone'] = null;
        $a['cliente_email'] = null;
        $a['cliente_tramites'] = [];
    }
}
unset($a);

// ======================
// Estadísticas
// ======================
$total_alertas = count($alertas);
$pendientes = 0;
$revisadas = 0;
foreach ($alertas as $a) {
    if ($a['estado'] === 'abierta') $pendientes++;
    else $revisadas++;
}
$stats = [
    'total_alertas' => $total_alertas,
    'pendientes' => $pendientes,
    'revisadas' => $revisadas
];

$currentPage        = 'alertas';
$alertas_pendientes = 0;
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
$is_supervisor_ui = ($user_role === 'supervisor');
$is_admin_role    = ($user_role === 'super_admin' || $user_role === 'admin');

// Lista de bancos/cooperativas para el filtro por escritura (solo admin/super_admin)
$bancos_alertas = [];
if ($is_admin_role) {
    try {
        $bancos_alertas = $pdo->query("SELECT id, nombre FROM unidad_bancaria ORDER BY nombre ASC")->fetchAll();
    } catch (Throwable $e) {
        $bancos_alertas = [];
    }
}

// Handler: marcar alerta como revisada (acepta POST normal o AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_revisada']) && isset($_POST['id'])) {
    $id_to_mark = $_POST['id'];
    $up = $pdo->prepare('UPDATE alerta_modificacion SET vista_supervisor = 1, vista_at = NOW() WHERE id = :id');
    $ok = $up->execute([':id' => $id_to_mark]);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => (bool)$ok]);
        exit;
    } else {
        header('Location: alertas.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Alertas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="js/cooperativa_buscador.js"></script>
    <style>
<?php if ($is_supervisor_ui || $user_role === 'super_admin'): ?>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-gray: #6b7280;
            --brand-border: #d7e0ea;
            --brand-card: #ffffff;
            --brand-bg: #f4f6f9;
            --brand-shadow: 0 16px 34px rgba(18, 58, 109, 0.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: linear-gradient(180deg, #f8fafc 0%, var(--brand-bg) 100%); display: flex; height: 100vh; color: var(--brand-navy-deep); }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: sticky; height: 100vh; top: 0; flex-shrink: 0; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 22px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin: 18px 0 26px; }
        .stat-card { background: var(--brand-card); border-radius: 18px; border: 1px solid var(--brand-border); box-shadow: var(--brand-shadow); padding: 18px; text-align: center; }
        .stat-card .number { font-size: 34px; font-weight: 900; color: var(--brand-navy-deep); line-height: 1; }
        .stat-card .label { margin-top: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: var(--brand-gray); font-weight: 700; }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 800; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: var(--brand-gray); border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: rgba(215,224,234,0.55); }
        .table tbody tr:hover { background: rgba(255,221,0,0.06); }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php else: ?>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar { width: 230px; background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #6b11ff, #7c3aed); color: #fff; }
        .main-content { flex: 1; margin-left: 0; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) { .sidebar { width: 200px; } .main-content { margin-left: 0; } }
        @media (max-width: 768px) { .sidebar { width: 180px; } .main-content { margin-left: 0; } }
        .navbar-custom { background: linear-gradient(135deg, #6b11ff, #3182fe); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 22px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin: 18px 0 26px; }
        .stat-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 18px; text-align: center; }
        .stat-card .number { font-size: 34px; font-weight: 800; color: #111827; line-height: 1; }
        .stat-card .label { margin-top: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; font-weight: 700; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php endif; ?>
    </style>

    <!-- Badges de trámite -->
    <style>
        .tram-badge { padding:4px 8px; border-radius:8px; font-weight:600; display:inline-block; margin-right:6px; font-size:0.85rem; }
        .tram-success { background:#d1fae5; color:#065f46; }
        .tram-primary { background:#dbeafe; color:#1e3a8a; }
        .tram-info    { background:#e0f2fe; color:#0369a1; }
        .tram-danger  { background:#fee2e2; color:#7f1d1d; }
        .tram-warning { background:#fffbeb; color:#92400e; }
        .tram-secondary { background:#f3f4f6; color:#374151; }

        /* Estilos específicos para Alertas */
        .alerts-table-custom {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px; /* Espaciado entre filas */
            margin-top: -12px;
        }
        .alerts-table-custom thead th {
            padding: 12px 20px;
            color: var(--brand-gray);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            border: none;
        }
        .alerts-table-custom tbody tr {
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
            border-radius: 12px;
        }
        .alerts-table-custom tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(18,58,109,0.08);
            background: #fff !important;
        }
        .alerts-table-custom tbody td {
            padding: 18px 20px;
            border: none;
            vertical-align: middle;
        }
        .alerts-table-custom tbody td:first-child { border-top-left-radius: 14px; border-bottom-left-radius: 14px; }
        .alerts-table-custom tbody td:last-child { border-top-right-radius: 14px; border-bottom-right-radius: 14px; }
        
        .search-container-premium {
            background: #fff;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 25px;
            border: 1px solid var(--brand-border);
            box-shadow: var(--brand-shadow);
        }
        .btn-premium-filter {
            background: #f1f5f9;
            color: var(--brand-navy, #123a6d);
            border: 1px solid var(--brand-border, #d7e0ea);
            transition: all 0.25s ease;
        }
        .btn-premium-filter:hover {
            background: #e2e8f0;
            color: var(--brand-navy-deep, #0a2748);
        }
        .btn-premium-filter.active {
            background: var(--brand-navy, #123a6d);
            color: #fff;
            border-color: var(--brand-navy, #123a6d);
            box-shadow: 0 4px 12px rgba(18, 58, 109, 0.15);
        }
        .btn-alphabet {
            background: #f1f5f9;
            color: var(--brand-navy, #123a6d);
            border: 1px solid var(--brand-border, #d7e0ea);
            transition: all 0.2s ease;
        }
        .btn-alphabet:hover {
            background: #e2e8f0;
            color: var(--brand-navy-deep, #0a2748);
            transform: translateY(-1px);
        }
        .btn-alphabet.active {
            background: var(--brand-navy, #123a6d);
            color: #fff;
            border-color: var(--brand-navy, #123a6d);
            box-shadow: 0 4px 10px rgba(18, 58, 109, 0.15);
        }

        /* ── Combobox de búsqueda por escritura para el filtro de Empresa/Banco ── */
        .coop-buscador-list {
            display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 50;
            max-height: 260px; overflow-y: auto; background: #fff; border: 1.5px solid #E2E8F0;
            border-radius: 10px; margin-top: 6px; box-shadow: 0 12px 28px rgba(18,58,109,.16);
        }
        .coop-buscador-item { padding: 9px 14px; font-size: 13.5px; color: #0D1929; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .coop-buscador-item:last-child { border-bottom: none; }
        .coop-buscador-item:hover { background: rgba(255,221,0,.16); }
        .coop-buscador-empty { padding: 10px 14px; font-size: 12.5px; color: #94a3b8; font-style: italic; }
    </style>

    <!-- ================================================================
         VENTANA EMERGENTE (modal) PROPIA — dentro de la aplicación.
         Namespace .alm-*  para evitar colisión con cualquier otro CSS/modal.
         z-index alto + aislamiento total del contenido.
         ================================================================ -->
    <style>
        .alm-backdrop {
            position: fixed; inset: 0;
            background: rgba(10, 39, 72, 0.55);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            z-index: 99990;
            display: none;
            align-items: center; justify-content: center;
            padding: 24px;
            animation: almFade .18s ease-out;
        }
        .alm-backdrop.alm-open { display: flex; }
        @keyframes almFade { from { opacity:0; } to { opacity:1; } }

        .alm-dialog {
            background: #ffffff;
            width: 100%;
            max-width: 1100px;
            max-height: 92vh;
            border-radius: 18px;
            box-shadow: 0 30px 80px rgba(0,0,0,.35);
            display: flex; flex-direction: column;
            overflow: hidden;
            position: relative;
            isolation: isolate;                /* aísla z-index hijos */
            animation: almPop .22s cubic-bezier(.2,.9,.3,1.1);
        }
        @keyframes almPop { from { transform: translateY(14px) scale(.98); opacity:0; } to { transform:none; opacity:1; } }

        .alm-header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
            padding: 18px 22px;
            background: linear-gradient(135deg, #0a2748, #123a6d);
            color: #fff;
            border-bottom: 3px solid #ffdd00;
        }
        .alm-header .alm-title {
            display:flex; align-items:center; gap:10px;
            font-weight: 800; font-size: 16px; letter-spacing:.2px;
        }
        .alm-header .alm-title i { color:#ffdd00; }
        .alm-header-actions { display:flex; align-items:center; gap:10px; }

        .alm-btn {
            border: none; cursor: pointer;
            padding: 9px 16px; border-radius: 10px;
            font-weight: 700; font-size: 13px;
            display:inline-flex; align-items:center; gap:8px;
            transition: all .18s ease;
        }
        .alm-btn-mark {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            box-shadow: 0 8px 16px rgba(16,185,129,.25);
        }
        .alm-btn-mark:hover { transform: translateY(-1px); box-shadow: 0 12px 20px rgba(16,185,129,.35); }
        .alm-btn-mark:disabled { opacity:.6; cursor:not-allowed; transform:none; box-shadow:none; }

        .alm-footer {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 14px 22px;
            border-top: 1px solid rgba(255,255,255,.12);
            background: linear-gradient(135deg, #0a2748, #123a6d);
            flex-shrink: 0;
        }
        .alm-btn-open {
            background: transparent;
            color: rgba(255,255,255,.7);
            border: 1px solid rgba(255,255,255,.2);
            font-size: 12px; padding: 7px 14px;
            text-decoration: none;
        }
        .alm-btn-open:hover { background: rgba(255,255,255,.1); color: #fff; }
        .alm-footer-center { display:flex; gap:10px; align-items:center; }
        .alm-btn-close {
            background: rgba(255,255,255,.1);
            color: #fff;
            width: 38px; height: 38px; padding: 0;
            border-radius: 50%;
            font-size: 18px;
            justify-content: center;
        }
        .alm-btn-close:hover { background: rgba(255,221,0,.25); color:#ffdd00; }

        .alm-body {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: hidden;
            background: #f4f6f9;
            padding: 0;
            contain: content;
            min-width: 0;
        }
        .alm-body > * { max-width: 100%; box-sizing: border-box; }
        /* Forzar que TODO el contenido interno se quede dentro del modal */
        .alm-body .alm-detalle { overflow-x: hidden; max-width: 100%; min-width: 0; }
        .alm-body .alm-detalle * { box-sizing: border-box; min-width: 0; }
        .alm-body .alm-detalle .d-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
        }
        .alm-body .alm-detalle .d-row { min-width: 0; overflow: hidden; }
        .alm-body .alm-detalle .d-val { word-break: break-word; overflow-wrap: anywhere; }
        .alm-body .alm-detalle .sec-body { overflow-x: auto; max-width: 100%; }
        .alm-body .alm-detalle .diff-table { table-layout: fixed; width: 100%; }
        .alm-body .alm-detalle table { max-width: 100%; word-break: break-word; }
        @media(max-width:860px){
            .alm-body .alm-detalle .d-grid { grid-template-columns: repeat(2,1fr); }
        }
        @media(max-width:560px){
            .alm-body .alm-detalle .d-grid { grid-template-columns: 1fr; }
        }

        .alm-loader {
            display:flex; align-items:center; justify-content:center;
            gap: 14px; padding: 80px 20px;
            color: #6b7280; font-weight: 600;
        }
        .alm-loader .alm-spin {
            width: 28px; height: 28px;
            border: 3px solid #e5e9f0;
            border-top-color: #0a2748;
            border-radius: 50%;
            animation: almSpin .8s linear infinite;
        }
        @keyframes almSpin { to { transform: rotate(360deg); } }

        /* evitar scroll del body mientras el modal está abierto */
        body.alm-lock { overflow: hidden; }

        @media (max-width: 720px) {
            .alm-backdrop { padding: 8px; }
            .alm-dialog { max-height: 96vh; border-radius: 14px; }
            .alm-header { padding: 14px 16px; }
        }
    </style>
<?php 
if ($user_role === 'supervisor') {
    $navTitle = ''; $navIcon = ''; $navSubtitle = '';
    require_once '_sidebar_supervisor.php';
} elseif ($user_role === 'admin') {
    $currentPage = 'alertas';
    require_once '_sidebar_gerente.php';
    ?>
<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-bell me-2" style="color:var(--brand-yellow)"></i> Centro de Alertas</h2>
        <div class="user-info">
            <div style="text-align:right;">
                <strong><?= htmlspecialchars($_SESSION['admin_nombre'] ?? 'Gerente') ?></strong><br>
                <small style="opacity:.7;"><?= htmlspecialchars($_SESSION['admin_rol'] ?? 'Gerente') ?></small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i> Cerrar Sesión</a>
        </div>
    </div>
    <div class="content-area">
<?php } elseif ($user_role === 'asesor') { ?>
<!-- SIDEBAR ASESOR -->
<?php
    $currentPage = 'alertas';
    $asesor_nombre = $_SESSION['asesor_nombre'] ?? 'Asesor';
    require_once '_sidebar_asesor.php';
?>
<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-bell me-2" style="color:var(--brand-yellow)"></i> Centro de Alertas</h2>
        <div class="user-info">
            <div style="text-align:right;">
                <strong><?= htmlspecialchars($asesor_nombre) ?></strong><br>
                <small style="opacity:.7;">Asesor</small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i> Cerrar Sesión</a>
        </div>
    </div>
    <div class="content-area">
<?php } else {
    // Sidebar único de SuperAdmin (mismo archivo compartido en todas las páginas)
    $currentPage = 'alertas';
    require_once '_sidebar_super_admin.php';
    ?>
<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-bell me-2" style="color:var(--brand-yellow)"></i> Centro de Alertas</h2>
        <div class="user-info">
            <div style="text-align:right;">
                <strong><?= htmlspecialchars($_SESSION['super_admin_nombre'] ?? 'SuperAdmin') ?></strong><br>
                <small style="opacity:.7;">SuperAdministrador</small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i> Cerrar Sesión</a>
        </div>
    </div>
    <div class="content-area">
<?php } ?>

        <div class="page-header">
            <h1><i class="fas fa-bell me-2"></i>Centro de Alertas</h1>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div id="alertas-total" class="number"><?php echo $stats['total_alertas']; ?></div>
                <div class="label">Total de Alertas</div>
            </div>
            <div class="stat-card">
                <div id="alertas-pendientes" class="number" style="color: #ef4444;"><?php echo $stats['pendientes']; ?></div>
                <div class="label">Pendientes</div>
            </div>
            <div class="stat-card">
                <div id="alertas-revisadas" class="number" style="color: #10b981;"><?php echo $stats['revisadas']; ?></div>
                <div class="label">Revisadas</div>
            </div>
        </div>

        <!-- Buscador y Filtros Premium -->
        <div class="search-container-premium">
            <div class="row g-3 align-items-center">
                <!-- Título -->
                <div class="col-12 <?php echo $is_admin_role ? 'col-md-3' : 'col-md-4'; ?>">
                    <h5 class="mb-0 fw-800 text-navy"><i class="fas fa-list-ul me-2 text-primary"></i> Registro de Modificaciones</h5>
                    <small class="text-muted">Gestiona y revisa los cambios realizados por tu equipo</small>
                </div>

                <!-- Filtro por Banco/Cooperativa (búsqueda por escritura, solo admin/super_admin) — primero -->
                <?php if ($is_admin_role): ?>
                <div class="col-12 col-sm-6 col-md-3">
                    <div class="coop-buscador-wrap" style="position:relative;">
                        <div class="input-group" style="box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 10px; overflow: hidden;">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-university text-muted"></i></span>
                            <input type="text" id="filterEmpresaBuscar" class="form-control border-start-0" style="padding: 10px;" placeholder="Filtrar por banco..." autocomplete="off">
                            <button type="button" id="filterEmpresaClear" class="btn btn-outline-secondary border-start-0" style="display:none;" title="Quitar filtro">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <input type="hidden" id="filterEmpresaHidden">
                        <div id="filterEmpresaLista" class="coop-buscador-list"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Buscador por nombre o cédula -->
                <div class="col-12 col-sm-6 <?php echo $is_admin_role ? 'col-md-3' : ($col_asesor ? 'col-md-4' : 'col-md-8'); ?>">
                    <div class="input-group" style="box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 10px; overflow: hidden;">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="alertSearch" class="form-control border-start-0" style="padding: 10px;" placeholder="Buscar por cliente o cédula...">
                    </div>
                </div>

                <!-- Filtro por Asesor (si aplica) -->
                <?php if ($col_asesor): ?>
                <div class="col-12 col-sm-6 <?php echo $is_admin_role ? 'col-md-3' : 'col-md-4'; ?>">
                    <div class="input-group" style="box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 10px; overflow: hidden;">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-user-tie text-muted"></i></span>
                        <select id="filterAsesor" class="form-select border-start-0" style="padding: 10px;">
                            <option value="">Todos los Asesores</option>
                            <?php
                            $asesores = [];
                            foreach ($alertas as $al) {
                                if (!empty($al['asesor_nombre'])) {
                                    $asesores[] = $al['asesor_nombre'];
                                }
                            }
                            $asesores = array_values(array_unique($asesores));
                            sort($asesores);
                            foreach ($asesores as $as) {
                                echo '<option value="' . htmlspecialchars(strtolower($as)) . '">' . htmlspecialchars($as) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Segunda Fila: Filtros de Estado y Ordenamiento -->
            <div class="row g-3 align-items-center mt-2 pt-2 border-top">
                <!-- Botones de Estado -->
                <div class="col-12 col-md-8 d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-premium-filter active" data-filter-status="todos" style="border-radius:20px; font-weight:600; padding:6px 16px;">
                        <i class="fas fa-border-all me-1"></i> Todos
                    </button>
                    <button type="button" class="btn btn-premium-filter" data-filter-status="abierta" style="border-radius:20px; font-weight:600; padding:6px 16px;">
                        <i class="fas fa-clock me-1 text-danger"></i> Pendientes
                    </button>
                    <button type="button" class="btn btn-premium-filter" data-filter-status="cerrada" style="border-radius:20px; font-weight:600; padding:6px 16px;">
                        <i class="fas fa-check-circle me-1 text-success"></i> Revisadas
                    </button>
                </div>
                
                <!-- Selector de Ordenamiento -->
                <div class="col-12 col-md-4 d-flex justify-content-md-end">
                    <div class="input-group" style="width: 260px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-radius: 10px; overflow: hidden;">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-sort-alpha-down text-muted"></i></span>
                        <select id="sortAlerts" class="form-select border-start-0" style="padding: 10px;">
                            <option value="fecha-desc">Más recientes primero</option>
                            <option value="fecha-asc">Más antiguas primero</option>
                            <option value="name-asc">Nombre: A a la Z</option>
                            <option value="name-desc">Nombre: Z a la A</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Tercera Fila: Selector del Abecedario (A-Z) por Letra -->
            <div class="row mt-2 pt-2 border-top align-items-center">
                <div class="col-12">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <small class="text-muted fw-bold text-uppercase" style="font-size: 10px; letter-spacing: 0.5px; white-space: nowrap;">Filtrar por letra:</small>
                        <div class="d-flex flex-wrap gap-1 alphabet-container" style="flex: 1;">
                            <button type="button" class="btn btn-sm btn-alphabet active" data-letter="todos" style="border-radius:8px; font-weight:700; padding: 4px 10px; font-size:11px;">Todas</button>
                            <?php
                            $alphabet = range('A', 'Z');
                            foreach ($alphabet as $char) {
                                echo '<button type="button" class="btn btn-sm btn-alphabet" data-letter="' . strtolower($char) . '" style="border-radius:8px; font-weight:700; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; font-size:11px;">' . $char . '</button>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla de alertas -->
        <div class="table-responsive">
            <table class="alerts-table-custom" id="alertas-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">Cliente</th>
                        <th style="width: 20%;">Cédula / Prospecto</th>
                        <th style="width: 15%;">Fecha Modificado</th>
                        <?php if ($col_asesor): ?><th style="width: 20%;">Asesor</th><?php endif; ?>
                        <th class="text-center" style="width: 10%;">Estado</th>
                        <th class="text-center" style="width: 10%;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alertas)): ?>
                    <tr>
                        <td colspan="<?php echo $col_asesor ? 6 : 5; ?>" class="text-center py-5">
                            <div class="text-muted">
                                <i class="fas fa-check-circle fa-3x mb-3 d-block opacity-25"></i>
                                No hay alertas pendientes por revisar
                            </div>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($alertas as $alerta): ?>
                        <tr class="alert-row" 
                            data-alerta-id="<?php echo htmlspecialchars($alerta['id_alerta']); ?>"
                            data-search-name="<?php echo strtolower(htmlspecialchars($alerta['cliente_nombre_display'])); ?>"
                            data-search-id="<?php echo strtolower(htmlspecialchars($alerta['cliente_cedula_display'])); ?>"
                            data-estado="<?php echo htmlspecialchars($alerta['estado']); ?>"
                            data-asesor="<?php echo strtolower(htmlspecialchars($alerta['asesor_nombre'] ?? '')); ?>"
                            data-banco-id="<?php echo htmlspecialchars($alerta['banco_id'] ?? ''); ?>"
                            data-fecha="<?php echo strtotime($alerta['fecha']); ?>">
                            
                            <td>
                                <div class="fw-bold text-navy"><?php echo htmlspecialchars($alerta['cliente_nombre_display']); ?></div>
                                <?php if (!empty($alerta['cliente_tramites']) && is_array($alerta['cliente_tramites'])): ?>
                                    <div class="mt-1">
                                        <?php foreach (array_slice($alerta['cliente_tramites'], 0, 2) as $ct): ?>
                                            <?php $info = tramite_label_and_color($ct['key']); ?>
                                            <span class="badge-premium badge-info-soft" style="font-size:9px; padding:2px 6px;">
                                                <?php echo htmlspecialchars($info[0]); ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($alerta['cliente_tramites']) > 2): ?>
                                            <span class="text-muted small" style="font-size:10px;">+<?php echo count($alerta['cliente_tramites']) - 2; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            
                            <td>
                                <span class="badge-premium badge-navy-soft">
                                    <i class="far fa-id-card me-1"></i>
                                    <?php echo htmlspecialchars($alerta['cliente_cedula_display']); ?>
                                </span>
                            </td>
                            
                            <td>
                                <div class="fw-bold"><?php echo date('d/m/Y', strtotime($alerta['fecha'])); ?></div>
                                <div class="text-muted small"><?php echo date('H:i', strtotime($alerta['fecha'])); ?></div>
                            </td>

                            <?php if ($col_asesor): ?>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="act-dot dot-blue" style="width:28px; height:28px; font-size:10px;">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <span class="fw-600 small"><?php echo htmlspecialchars($alerta['asesor_nombre'] ?? 'N/A'); ?></span>
                                </div>
                            </td>
                            <?php endif; ?>

                            <td class="text-center">
                                <?php if ($alerta['estado'] === 'abierta'): ?>
                                    <span class="badge-premium badge-danger-soft">Pendiente</span>
                                <?php else: ?>
                                    <span class="badge-premium badge-success-soft">Revisada</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-center">
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary open-alert-detail"
                                        style="border-radius:10px; padding:6px 12px;"
                                        data-alerta-id="<?php echo htmlspecialchars($alerta['id_alerta']); ?>"
                                        data-estado="<?php echo htmlspecialchars($alerta['estado']); ?>">
                                    <i class="fas fa-eye me-1"></i> Ver
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- =========================================================
     VENTANA EMERGENTE (dentro de la aplicación)
     Se inyecta al final del <body> para evitar cualquier
     contexto de apilamiento (stacking) raro del layout.
     ========================================================= -->
<div id="alm-backdrop" class="alm-backdrop" role="dialog" aria-modal="true" aria-labelledby="alm-title">
    <div class="alm-dialog">
        <div class="alm-header">
            <div class="alm-title" id="alm-title">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Detalle de la Alerta</span>
            </div>
            <div class="alm-header-actions">
                <button type="button" class="alm-btn alm-btn-close" id="alm-btn-close" aria-label="Cerrar">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="alm-body" id="alm-body">
            <div class="alm-loader">
                <div class="alm-spin"></div>
                <span>Cargando detalle de la alerta...</span>
            </div>
        </div>
        <div class="alm-footer">
            <a href="#" id="alm-btn-open" class="alm-btn alm-btn-open" target="_blank">
                <i class="fas fa-arrow-up-right-from-square"></i> Abrir en página completa
            </a>
            <div class="alm-footer-center">
                <button type="button" id="alm-btn-mark" class="alm-btn alm-btn-mark" style="display:none;">
                    <i class="fas fa-check"></i> Marcar como Revisada
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    'use strict';

    // Bancos/cooperativas para el combobox de búsqueda del filtro "Empresa" (solo admin/super_admin)
    var BANCOS_ALERTAS = <?= json_encode(array_map(fn($b) => ['id' => (string)$b['id'], 'nombre' => $b['nombre']], $bancos_alertas), JSON_UNESCAPED_UNICODE) ?>;

    var _currentId  = null;
    var _currentEstado = null;

    var backdrop = document.getElementById('alm-backdrop');
    var body     = document.getElementById('alm-body');
    var btnClose = document.getElementById('alm-btn-close');
    var btnMark  = document.getElementById('alm-btn-mark');
    var btnOpen  = document.getElementById('alm-btn-open');

    function openModal(alertaId, estado) {
        _currentId     = alertaId;
        _currentEstado = estado;

        // Resetea contenido a loader
        body.innerHTML = '<div class="alm-loader"><div class="alm-spin"></div><span>Cargando detalle de la alerta...</span></div>';

        // Botón marcar: solo visible si la alerta está abierta/pendiente
        btnMark.disabled = false;
        btnMark.innerHTML = '<i class="fas fa-check"></i> Marcar como Revisada';
        btnMark.style.display = (estado === 'abierta') ? 'inline-flex' : 'none';

        // Link "Abrir en página completa"
        btnOpen.href = 'alertas_detalle.php?id=' + encodeURIComponent(alertaId);

        backdrop.classList.add('alm-open');
        document.body.classList.add('alm-lock');

        // Fetch AJAX al MISMO archivo alertas_detalle.php
        var url = 'alertas_detalle.php?id=' + encodeURIComponent(alertaId) + '&ajax=1&_ts=' + Date.now();
        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
            cache: 'no-store'
        })
        .then(function(r){
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(function(html){
            // Protección: si el server devolvió un documento HTML completo (por error),
            // extraer solo el contenido útil (el <div class="adx-wrap">...</div>).
            var cleaned = html;
            var iHead = html.toLowerCase().indexOf('<!doctype');
            if (iHead === -1) iHead = html.toLowerCase().indexOf('<html');
            if (iHead !== -1) {
                // intenta aislar adx-wrap
                var m = html.match(/<div[^>]*class=["'][^"']*adx-wrap[^"']*["'][\s\S]*?<\/div>\s*(?:<\/body>|<\/main>|$)/i);
                if (m && m[0]) cleaned = m[0];
            }
            body.innerHTML = cleaned;
            body.scrollTop = 0;
        })
        .catch(function(err){
            body.innerHTML =
              '<div style="padding:60px 20px; text-align:center;">' +
                '<i class="fas fa-triangle-exclamation fa-2x" style="color:#ef4444;"></i>' +
                '<p style="margin-top:14px; color:#b91c1c; font-weight:700;">No se pudo cargar el detalle.</p>' +
                '<small style="color:#6b7280;">' + (err && err.message ? err.message : '') + '</small>' +
              '</div>';
        });
    }

    function closeModal() {
        backdrop.classList.remove('alm-open');
        document.body.classList.remove('alm-lock');
        body.innerHTML = '';
        _currentId     = null;
        _currentEstado = null;
    }

    // Click en "Ver detalles" (delegación)
    document.addEventListener('click', function(e){
        var t = e.target.closest('.open-alert-detail');
        if (t) {
            e.preventDefault();
            var id = t.getAttribute('data-alerta-id');
            var es = t.getAttribute('data-estado') || 'abierta';
            if (id) openModal(id, es);
            return;
        }
    });

    // Cerrar: botón X
    btnClose.addEventListener('click', closeModal);
    // Cerrar: click fuera del dialog
    backdrop.addEventListener('click', function(e){
        if (e.target === backdrop) closeModal();
    });
    // Cerrar: tecla ESC
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && backdrop.classList.contains('alm-open')) closeModal();
    });

    // ── BUSCADOR Y FILTROS EN TIEMPO REAL ──────────────────────────
    var alertSearch = document.getElementById('alertSearch');
    var filterAsesor = document.getElementById('filterAsesor');
    var filterEmpresaHidden = document.getElementById('filterEmpresaHidden');
    var sortAlerts = document.getElementById('sortAlerts');
    var statusButtons = document.querySelectorAll('.btn-premium-filter');
    var alphabetButtons = document.querySelectorAll('.btn-alphabet');

    function applyFiltersAndSort() {
        var val = alertSearch ? alertSearch.value.toLowerCase().trim() : '';
        var activeBtn = document.querySelector('.btn-premium-filter.active');
        var activeStatus = activeBtn ? activeBtn.getAttribute('data-filter-status') : 'todos';
        var activeAsesor = filterAsesor ? filterAsesor.value : '';
        var activeBancoId = filterEmpresaHidden ? filterEmpresaHidden.value : '';
        var sortValue = sortAlerts ? sortAlerts.value : 'fecha-desc';
        
        var activeLetterBtn = document.querySelector('.btn-alphabet.active');
        var activeLetter = activeLetterBtn ? activeLetterBtn.getAttribute('data-letter') : 'todos';

        var tbody = document.querySelector('#alertas-table tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('.alert-row'));
        if (rows.length === 0) return;

        // 1. Ordenar las filas en memoria
        rows.sort(function(a, b) {
            if (sortValue === 'name-asc' || sortValue === 'name-desc') {
                var nameA = a.getAttribute('data-search-name') || '';
                var nameB = b.getAttribute('data-search-name') || '';
                var cmp = nameA.localeCompare(nameB, 'es', { sensitivity: 'base' });
                return sortValue === 'name-asc' ? cmp : -cmp;
            } else {
                // Ordenar por fecha
                var tA = parseInt(a.getAttribute('data-fecha') || 0, 10);
                var tB = parseInt(b.getAttribute('data-fecha') || 0, 10);
                return sortValue === 'fecha-asc' ? (tA - tB) : (tB - tA);
            }
        });

        // 2. Filtrar y re-añadir filas ordenadas
        var visibleCount = 0;
        // Contadores de las tarjetas superiores (Total/Pendientes/Revisadas):
        // reflejan el alcance actual (texto, asesor, empresa, letra) SIN
        // aplicar el filtro de estado, para que las 3 tarjetas muestren el
        // desglose completo de lo que hay dentro de ese alcance (por
        // ejemplo, al filtrar por un banco, "Total" pasa a ser el total de
        // ESE banco, no el total global).
        var scopeTotal = 0, scopePend = 0, scopeRev = 0;
        rows.forEach(function(row) {
            var name = row.getAttribute('data-search-name') || '';
            var id   = row.getAttribute('data-search-id') || '';
            var status = row.getAttribute('data-estado') || '';
            var advisor = row.getAttribute('data-asesor') || '';
            var bancoId = row.getAttribute('data-banco-id') || '';

            // Filtro de texto (nombre o cédula)
            var matchesText = !val || name.includes(val) || id.includes(val);

            // Filtro de estado
            var matchesStatus = (activeStatus === 'todos') || (status === activeStatus);

            // Filtro de asesor
            var matchesAsesor = !activeAsesor || (advisor === activeAsesor);

            // Filtro de empresa/banco (solo admin/super_admin)
            var matchesEmpresa = !activeBancoId || (bancoId === activeBancoId);

            // Filtro de letra (A-Z)
            var matchesLetter = (activeLetter === 'todos') || name.startsWith(activeLetter);

            // Alcance para las tarjetas de arriba: todo menos el filtro de estado
            var matchesScope = matchesText && matchesAsesor && matchesEmpresa && matchesLetter;
            if (matchesScope) {
                scopeTotal++;
                if (status === 'abierta') scopePend++; else scopeRev++;
            }

            if (matchesText && matchesStatus && matchesAsesor && matchesEmpresa && matchesLetter) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }

            // Mueve la fila en el DOM
            tbody.appendChild(row);
        });

        // Actualizar las tarjetas de Total/Pendientes/Revisadas según el alcance actual
        var elTotalCard = document.getElementById('alertas-total');
        var elPendCard  = document.getElementById('alertas-pendientes');
        var elRevCard   = document.getElementById('alertas-revisadas');
        if (elTotalCard) elTotalCard.textContent = scopeTotal;
        if (elPendCard)  elPendCard.textContent  = scopePend;
        if (elRevCard)   elRevCard.textContent   = scopeRev;

        // Manejo de la fila de "sin resultados"
        var placeholderRow = document.getElementById('no-alerts-placeholder');
        if (visibleCount === 0) {
            if (!placeholderRow) {
                placeholderRow = document.createElement('tr');
                placeholderRow.id = 'no-alerts-placeholder';
                var colSpan = tbody.querySelector('.alert-row') ? tbody.querySelector('.alert-row').cells.length : 6;
                placeholderRow.innerHTML = `
                    <td colspan="${colSpan}" class="text-center py-5">
                        <div class="text-muted">
                            <i class="fas fa-search fa-3x mb-3 d-block opacity-25"></i>
                            Ninguna alerta coincide con los filtros aplicados
                        </div>
                    </td>
                `;
                tbody.appendChild(placeholderRow);
            } else {
                placeholderRow.style.display = '';
                tbody.appendChild(placeholderRow);
            }
        } else {
            if (placeholderRow) {
                placeholderRow.style.display = 'none';
            }
        }
    }

    // Escuchadores de eventos para los filtros
    if (alertSearch) {
        alertSearch.addEventListener('input', applyFiltersAndSort);
    }
    if (filterAsesor) {
        filterAsesor.addEventListener('change', applyFiltersAndSort);
    }
    if (sortAlerts) {
        sortAlerts.addEventListener('change', applyFiltersAndSort);
    }
    statusButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            statusButtons.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            applyFiltersAndSort();
        });
    });
    alphabetButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            alphabetButtons.forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            applyFiltersAndSort();
        });
    });

    // ── Filtro por Empresa/Banco (búsqueda por escritura) ──────────
    var filterEmpresaBuscarInput = document.getElementById('filterEmpresaBuscar');
    var filterEmpresaClearBtn    = document.getElementById('filterEmpresaClear');
    if (filterEmpresaBuscarInput && typeof initCooperativaBuscador === 'function') {
        initCooperativaBuscador({
            inputId:  'filterEmpresaBuscar',
            hiddenId: 'filterEmpresaHidden',
            listId:   'filterEmpresaLista',
            data: BANCOS_ALERTAS,
            onSelect: function () {
                filterEmpresaClearBtn.style.display = 'inline-block';
                applyFiltersAndSort();
            }
        });
        filterEmpresaClearBtn.addEventListener('click', function () {
            filterEmpresaBuscarInput.value = '';
            filterEmpresaHidden.value = '';
            filterEmpresaClearBtn.style.display = 'none';
            applyFiltersAndSort();
        });
        filterEmpresaBuscarInput.addEventListener('input', function () {
            if (!filterEmpresaHidden.value) {
                filterEmpresaClearBtn.style.display = 'none';
                applyFiltersAndSort();
            }
        });
    }

    // Ejecutar ordenamiento y filtrado inicial
    applyFiltersAndSort();

    // ── MARCAR COMO REVISADA ──────────────────────────────────────
    btnMark.addEventListener('click', function(){
        if (!_currentId) return;
        var id = _currentId;

        btnMark.disabled = true;
        btnMark.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

        var fd = new FormData();
        fd.append('marcar_revisada', '1');
        fd.append('id', id);

        fetch('marcar_alerta_revisada.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data && data.success) {
                // 1. Actualizar badge de la fila en la tabla
                var tr = document.querySelector('tr[data-alerta-id="' + id + '"]');
                if (tr) {
                    // Estado badge en columna
                    var estadoCell = tr.querySelector('td:nth-last-child(2)');
                    if (estadoCell) estadoCell.innerHTML = '<span class="badge-premium badge-success-soft">Revisada</span>';
                    // Actualizar data-estado de la fila y el botón "Ver"
                    tr.setAttribute('data-estado', 'cerrada');
                    var btnRow = tr.querySelector('.open-alert-detail');
                    if (btnRow) btnRow.setAttribute('data-estado', 'cerrada');
                    
                    // Volver a aplicar filtros para refrescar la lista de inmediato;
                    // esto también recalcula Total/Pendientes/Revisadas según el
                    // alcance actual (texto/asesor/empresa/letra), así que no hace
                    // falta ajustarlos manualmente aquí aparte.
                    applyFiltersAndSort();
                }
                // 2. Feedback visual y cerrar modal
                btnMark.innerHTML = '<i class="fas fa-check-double"></i> ¡Revisada!';
                setTimeout(closeModal, 700);
            } else {
                btnMark.disabled = false;
                btnMark.innerHTML = '<i class="fas fa-check"></i> Marcar como Revisada';
                alert(data && data.message ? data.message : 'No se pudo marcar la alerta.');
            }
        })
        .catch(function(err){
            btnMark.disabled = false;
            btnMark.innerHTML = '<i class="fas fa-check"></i> Marcar como Revisada';
            alert('Error de red: ' + (err ? err.message : ''));
        });
    });

})();
</script>

</body>
</html>

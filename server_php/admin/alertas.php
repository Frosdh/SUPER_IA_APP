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
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        JOIN asesor a ON am.asesor_id = a.id
        JOIN usuario u_asesor ON a.usuario_id = u_asesor.id
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
    $out = ['name'=>null,'phone'=>null,'email'=>null,'tramites'=>[]];
    if (empty($txt)) return $out;
    $d = json_decode($txt, true);
    if (!is_array($d)) return $out;
    if (!empty($d['cliente']) && is_array($d['cliente'])) {
        $c = $d['cliente'];
        $out['name'] = $c['nombre'] ?? $c['nombre_completo'] ?? $out['name'];
        $out['phone'] = $c['telefono'] ?? $c['telefono2'] ?? $out['phone'];
        $out['email'] = $c['email'] ?? $c['email_cliente'] ?? $out['email'];
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
        $a['cliente_phone'] = $ant_det['phone'];
        $a['cliente_email'] = $ant_det['email'];
        $a['cliente_tramites'] = compare_tramites($ant_txt, $new_txt);
        $a['cliente_display_source'] = 'antes';
    } elseif ($new_det['name']) {
        $a['cliente_nombre_display'] = $new_det['name'];
        $a['cliente_phone'] = $new_det['phone'];
        $a['cliente_email'] = $new_det['email'];
        $a['cliente_tramites'] = compare_tramites($ant_txt, $new_txt);
        $a['cliente_display_source'] = 'despues';
    } else {
        $a['cliente_nombre_display'] = $a['cliente_nombre'] ?? 'Sin cliente';
        $a['cliente_phone'] = null;
        $a['cliente_email'] = null;
        $a['cliente_tramites'] = [];
        $a['cliente_display_source'] = 'actual';
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
    <style>
<?php if ($is_supervisor_ui): ?>
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
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
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
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) { .sidebar { width: 200px; } .main-content { margin-left: 200px; } }
        @media (max-width: 768px) { .sidebar { width: 180px; } .main-content { margin-left: 180px; } }
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
            overflow: auto;
            background: #f4f6f9;
            padding: 0;                        /* el partial trae su propio padding */
            /* aislar el contenido cargado por AJAX para que NADA herede de la página */
            contain: content;
        }
        .alm-body > * { max-width: 100%; }

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
    </head>
    <body>

<!-- SIDEBAR -->
<?php if ($user_role === 'supervisor'): require_once '_sidebar_supervisor.php'; else: ?>
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> Super_IA
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <?php if ($user_role === 'supervisor'): ?>
        <a href="supervisor_index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <?php elseif ($user_role === 'super_admin'): ?>
        <a href="super_admin_index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <a href="mapa_calor.php" class="sidebar-link"><i class="fas fa-fire"></i> Mapa de Calor</a>
        <a href="historial_rutas.php" class="sidebar-link"><i class="fas fa-history"></i> Historial de Viajes</a>
        <?php elseif ($user_role === 'admin'): ?>
        <a href="index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <a href="mapa_calor.php" class="sidebar-link"><i class="fas fa-fire"></i> Mapa de Calor</a>
        <a href="historial_rutas.php" class="sidebar-link"><i class="fas fa-history"></i> Historial de Viajes</a>
        <?php else: ?>
        <a href="<?php echo ($user_role === 'supervisor') ? 'supervisor_index.php' : 'asesor_index.php'; ?>" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <?php endif; ?>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
        <a href="usuarios.php" class="sidebar-link"><i class="fas fa-users"></i> Usuarios</a>
        <?php endif; ?>
        <a href="clientes.php" class="sidebar-link"><i class="fas fa-briefcase"></i> <?php echo ($user_role === 'asesor') ? 'Mis ' : ''; ?>Clientes</a>
        <a href="operaciones.php" class="sidebar-link"><i class="fas fa-handshake"></i> <?php echo ($user_role === 'asesor') ? 'Mis ' : ''; ?>Operaciones</a>
        <a href="alertas.php" class="sidebar-link active"><i class="fas fa-bell"></i> Alertas</a>
    </div>
    <?php if ($user_role === 'supervisor'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Mi Equipo</div>
        <a href="mis_asesores.php" class="sidebar-link"><i class="fas fa-users"></i> Mis Asesores</a>
        <a href="registro_asesor.php" class="sidebar-link"><i class="fas fa-user-plus"></i> Crear Asesor</a>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link"><i class="fas fa-file-circle-check"></i> Solicitudes de Asesor</a>
    </div>
    <?php endif; ?>
    <?php if ($user_role === 'super_admin'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link"><i class="fas fa-file-alt"></i> Solicitudes de Admin</a>
    </div>
    <?php endif; ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Configuración</div>
        <a href="#" class="sidebar-link"><i class="fas fa-cog"></i> Configuración</a>
    </div>
</div>
<?php endif; ?>

<div class="main-content">
    <div class="navbar-custom">
        <?php if ($user_role === 'supervisor'): ?>
            <h2><i class="fas fa-shield-halved me-2" style="color: var(--brand-yellow);"></i>Super_IA - Supervisor</h2>
        <?php else: ?>
            <h2><?php echo $user_role === 'super_admin' ? '👑' : '🎯'; ?> Super_IA
                <?php
                if ($user_role === 'super_admin') echo '- SuperAdministrador';
                elseif ($user_role === 'admin') echo '- Admin';
                elseif ($user_role === 'supervisor') echo '- Supervisor';
                else echo '- Asesor';
                ?>
            </h2>
        <?php endif; ?>
        <div class="user-info">
            <div>
                <strong>
                    <?php
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_nombre']);
                    elseif ($user_role === 'supervisor') echo htmlspecialchars($_SESSION['supervisor_nombre']);
                    else echo htmlspecialchars($_SESSION['asesor_nombre']);
                    ?>
                </strong><br>
                <small>
                    <?php
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_rol']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_rol']);
                    elseif ($user_role === 'supervisor') echo htmlspecialchars($_SESSION['supervisor_rol']);
                    else echo htmlspecialchars($_SESSION['asesor_rol']);
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
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

        <!-- Tabla de alertas -->
        <div class="table-card">
            <div class="card-header-custom">
                <h6>⚠️ Listado de Alertas</h6>
            </div>
            <table class="table table-hover" id="alertas-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Tipo</th>
                        <th>Mensaje</th>
                        <th>Cliente</th>
                        <?php if ($col_asesor): ?><th>Asesor Asignado</th><?php endif; ?>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alertas)): ?>
                    <tr>
                        <td colspan="<?php echo $col_asesor ? 8 : 7; ?>" class="text-center py-4">
                            <i class="fas fa-check-circle me-2" style="color: #10b981;"></i>No hay alertas pendientes
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($alertas as $alerta): ?>
                        <tr data-alerta-id="<?php echo htmlspecialchars($alerta['id_alerta']); ?>">
                            <td><strong>#<?php echo htmlspecialchars(substr($alerta['id_alerta'], 0, 8)); ?></strong></td>
                            <td><span class="badge" style="background: #3182fe;"><?php echo htmlspecialchars($alerta['tipo']); ?></span></td>
                            <td><?php echo htmlspecialchars(substr($alerta['mensaje'], 0, 80) . (strlen($alerta['mensaje']) > 80 ? '…' : '')); ?></td>
                            <td>
                                <?php echo htmlspecialchars($alerta['cliente_nombre_display'] ?? ($alerta['cliente_nombre'] ?? 'Sin cliente')); ?>
                                <?php if (!empty($alerta['cliente_phone']) || !empty($alerta['cliente_email'])): ?>
                                    <br/>
                                    <small class="text-muted">
                                        <?php if (!empty($alerta['cliente_phone'])): ?>📞 <?php echo htmlspecialchars($alerta['cliente_phone']); ?><?php endif; ?>
                                        <?php if (!empty($alerta['cliente_phone']) && !empty($alerta['cliente_email'])): ?> — <?php endif; ?>
                                        <?php if (!empty($alerta['cliente_email'])): ?>✉️ <?php echo htmlspecialchars($alerta['cliente_email']); ?><?php endif; ?>
                                    </small>
                                <?php endif; ?>
                                <?php if (!empty($alerta['cliente_display_source'])): ?>
                                    <br/><small class="text-muted">Mostrando: <?php echo $alerta['cliente_display_source'] === 'antes' ? 'Antes' : ($alerta['cliente_display_source'] === 'despues' ? 'Ahora' : 'Actual'); ?></small>
                                <?php endif; ?>
                                <?php if (!empty($alerta['cliente_tramites']) && is_array($alerta['cliente_tramites'])): ?>
                                    <div style="margin-top:6px;">
                                        <?php foreach ($alerta['cliente_tramites'] as $ct): ?>
                                            <?php $info = tramite_label_and_color($ct['key']); $label = $info[0]; $color = $info[1]; ?>
                                            <?php if ($ct['status'] === 'added'): ?>
                                                <span class="badge bg-<?php echo $color; ?> text-white" style="margin-right:6px; font-weight:600;">+ <?php echo htmlspecialchars($label); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-<?php echo $color; ?> text-white" style="margin-right:6px; font-weight:600; opacity:0.85;">− <?php echo htmlspecialchars($label); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <?php if ($col_asesor): ?>
                            <td><?php echo htmlspecialchars($alerta['asesor_nombre'] ?? 'N/A'); ?></td>
                            <?php endif; ?>
                            <td><?php echo date('d/m/Y H:i', strtotime($alerta['fecha'])); ?></td>
                            <td>
                                <?php if ($alerta['estado'] === 'abierta'): ?>
                                    <span class="badge" style="background: #ef4444;">⏳ Abierta</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #10b981;">✓ Cerrada</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary open-alert-detail"
                                        title="Ver detalles"
                                        data-alerta-id="<?php echo htmlspecialchars($alerta['id_alerta']); ?>"
                                        data-estado="<?php echo htmlspecialchars($alerta['estado']); ?>">
                                    <i class="fas fa-eye"></i>
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
                    if (estadoCell) estadoCell.innerHTML = '<span class="badge" style="background:#10b981;">✓ Cerrada</span>';
                    // Actualizar data-estado del botón "Ver"
                    var btnRow = tr.querySelector('.open-alert-detail');
                    if (btnRow) btnRow.setAttribute('data-estado', 'cerrada');
                }
                // 2. Actualizar contadores en el encabezado
                var elPend = document.getElementById('alertas-pendientes');
                var elRev  = document.getElementById('alertas-revisadas');
                if (elPend) {
                    var p = parseInt(elPend.textContent, 10);
                    if (!isNaN(p) && p > 0) elPend.textContent = (p - 1);
                }
                if (elRev) {
                    var r = parseInt(elRev.textContent, 10);
                    if (!isNaN(r)) elRev.textContent = (r + 1);
                }
                // 3. Feedback visual y cerrar modal
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

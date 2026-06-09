<?php
// ============================================================
// admin/gerente_index.php — Dashboard Gerente Super_IA
// Análogo a supervisor_index.php pero para el rol Gerente
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php'; // PDO ($pdo)

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php?role=admin');
    exit;
}

$gerente_usuario_id = $_SESSION['admin_id'];
$gerente_nombre     = $_SESSION['admin_nombre'];
$gerente_rol        = $_SESSION['admin_rol'] ?? 'Gerente';

// ── Resolver supervisores a cargo según rol ──────────────────
// jefe_agencia  → supervisor.jefe_agencia_id = jefe_agencia.id (WHERE jefe_agencia.usuario_id = admin_id)
// gerente_general → gerente_general.unidad_bancaria_id → agencia → jefe_agencia → supervisor
$supervisor_ids = [];
$ja_ids         = [];   // jefe_agencia ids (puede ser >1 para gerente_general)

require_once __DIR__ . '/helper_ja_ids.php';
try {
    // Combina jefe_agencia propio + cadena gerente_general (sin depender del rol)
    $ja_ids = resolver_ja_ids($pdo, $gerente_usuario_id);

    // Supervisores de esos jefe_agencia
    if (!empty($ja_ids)) {
        $phJa = implode(',', array_fill(0, count($ja_ids), '?'));
        $st = $pdo->prepare("SELECT id FROM supervisor WHERE jefe_agencia_id IN ($phJa)");
        $st->execute($ja_ids);
        $supervisor_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    error_log('gerente_index resolver: ' . $e->getMessage());
}

// Para compatibilidad con código antiguo que usaba $ja_id (singular)
$ja_id = $ja_ids[0] ?? null;

// ── IDs de asesores: TODOS (igual que clientes.php para rol admin) ──────────
// El gerente tiene visión global del equipo, igual que el directorio de clientes
$asesor_ids = [];
try {
    $st = $pdo->query("SELECT id FROM asesor");
    $asesor_ids = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// ── KPIs principales ─────────────────────────────────────────
$total_supervisores  = count($supervisor_ids);
$total_asesores      = 0;
$total_clientes      = 0;
$clientes_activos    = 0;
$tareas_hoy          = 0;
$tareas_completadas  = 0;
$alertas_pendientes  = 0;
$fichas_credito      = 0;
$monto_fichas        = 0.0;
$ops_aprobadas       = 0;
$monto_ops           = 0.0;

$mesI = date('Y-m-01'); $mesF = date('Y-m-t');

// Total asesores (todos, igual que directorio)
try {
    $st = $pdo->query("SELECT COUNT(*) FROM asesor");
    $total_asesores = (int)$st->fetchColumn();
} catch (PDOException $e) {}

if (!empty($asesor_ids)) {
    try {
        $phAs = implode(',', array_fill(0, count($asesor_ids), '?'));

        // Clientes totales / activos (todos los del sistema)
        $st = $pdo->query("SELECT COUNT(*) as tot, SUM(CASE WHEN estado!='descartado' THEN 1 ELSE 0 END) as act FROM cliente_prospecto");
        $rowC = $st->fetch();
        $total_clientes   = (int)($rowC['tot'] ?? 0);
        $clientes_activos = (int)($rowC['act'] ?? 0);

        // Tareas de hoy
        $st = $pdo->prepare("SELECT COUNT(*) as tot, SUM(CASE WHEN estado='completada' THEN 1 ELSE 0 END) as comp FROM tarea WHERE asesor_id IN ($phAs) AND fecha_programada=CURDATE()");
        $st->execute($asesor_ids);
        $rowT = $st->fetch();
        $tareas_hoy        = (int)($rowT['tot']  ?? 0);
        $tareas_completadas = (int)($rowT['comp'] ?? 0);

        // Alertas sin ver (todas, vista global igual que clientes)
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM alerta_modificacion WHERE vista_supervisor=0");
            $alertas_pendientes = (int)$st->fetchColumn();
        } catch (PDOException $e) {}

        // Fichas de crédito aprobadas del mes
        $st = $pdo->prepare("SELECT COUNT(DISTINCT fp.id) as cnt, COALESCE(SUM(CAST(fc.monto_credito AS DECIMAL(15,2))),0) as monto
                              FROM ficha_producto fp LEFT JOIN ficha_credito fc ON fc.ficha_id=fp.id
                              WHERE fp.asesor_id IN ($phAs) AND fp.producto_tipo='credito'
                                AND fp.estado_revision IN ('aprobada','aprobado')
                                AND DATE(COALESCE(fp.updated_at,fp.created_at)) BETWEEN ? AND ?");
        $st->execute(array_merge($asesor_ids, [$mesI, $mesF]));
        $rowF = $st->fetch();
        $fichas_credito = (int)($rowF['cnt']  ?? 0);
        $monto_fichas   = (float)($rowF['monto'] ?? 0);

        // Créditos aprobados/desembolsados del mes
        $st = $pdo->prepare("SELECT COUNT(DISTINCT cp.id) as cnt, COALESCE(SUM(cp.monto_aprobado),0) as monto
                              FROM credito_proceso cp
                              WHERE cp.asesor_id IN ($phAs)
                                AND cp.estado_credito IN ('aprobado','desembolsado')
                                AND DATE(COALESCE(cp.fecha_desembolso,cp.updated_at,cp.created_at)) BETWEEN ? AND ?");
        $st->execute(array_merge($asesor_ids, [$mesI, $mesF]));
        $rowO = $st->fetch();
        $ops_aprobadas = (int)($rowO['cnt']  ?? 0);
        $monto_ops     = (float)($rowO['monto'] ?? 0);

    } catch (PDOException $e) { /* silencioso */ }
}

$total_ops_credito = $ops_aprobadas + $fichas_credito;
$monto_total       = $monto_ops + $monto_fichas;
$pct_tareas        = $tareas_hoy > 0 ? round($tareas_completadas * 100 / $tareas_hoy) : 0;

// ── KPIs detallados ──────────────────────────────────────────
$periodo_inicio = date('Y-m-01');
$periodo_fin    = date('Y-m-t');
$kpi_dash = [
    'actividad_pct' => $pct_tareas,
    'actividad_realizadas' => $tareas_completadas,
    'actividad_total' => $tareas_hoy,
    'penetracion_pct' => 0, 'penetracion_clientes' => 0, 'penetracion_visitas' => 0,
    'interes_pct' => 0, 'interes_si' => 0, 'interes_total' => 0,
    'prospeccion_pct' => 0, 'prospeccion_avance' => 0, 'prospeccion_meta' => 0,
    'levantamiento_pct' => 0, 'levantamientos' => 0, 'interesados' => 0,
    'frio_pct' => 0, 'frio_visitas' => 0,
    'recuperacion_pct' => 0, 'recuperaciones' => 0,
    'eficiencia_pct' => 0, 'postventa_pct' => 0,
    'operaciones_total' => $total_ops_credito,
    'operaciones_monto' => $monto_total,
];

if (!empty($asesor_ids)) {
    try {
        $ph = implode(',', array_fill(0, count($asesor_ids), '?'));
        $paramsPeriodo = array_merge($asesor_ids, [$periodo_inicio, $periodo_fin]);

        $st = $pdo->prepare("SELECT COUNT(t.id) as visitas, SUM(CASE WHEN (ec.p2_es_cliente=1 OR cp.estado='cliente') THEN 1 ELSE 0 END) as clientes
            FROM tarea t LEFT JOIN encuesta_comercial ec ON ec.tarea_id=t.id
            LEFT JOIN cliente_prospecto cp ON cp.id=t.cliente_prospecto_id
            WHERE t.asesor_id IN ($ph) AND t.estado='completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi_dash['penetracion_visitas']  = (int)($r['visitas']  ?? 0);
        $kpi_dash['penetracion_clientes'] = (int)($r['clientes'] ?? 0);
        $kpi_dash['penetracion_pct'] = $kpi_dash['penetracion_visitas'] > 0 ? round($kpi_dash['penetracion_clientes'] * 100 / $kpi_dash['penetracion_visitas'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(ec.id) as total, SUM(CASE WHEN ec.interes_conocer_productos=1 THEN 1 ELSE 0 END) as si
            FROM encuesta_comercial ec JOIN tarea t ON t.id=ec.tarea_id
            WHERE t.asesor_id IN ($ph) AND t.estado='completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi_dash['interes_total'] = (int)($r['total'] ?? 0);
        $kpi_dash['interes_si']    = (int)($r['si']    ?? 0);
        $kpi_dash['interes_pct']   = $kpi_dash['interes_total'] > 0 ? round($kpi_dash['interes_si'] * 100 / $kpi_dash['interes_total'], 1) : 0;

        $st = $pdo->prepare("SELECT COALESCE(SUM(meta_visitas_mes),0) FROM asesor WHERE id IN ($ph)");
        $st->execute($asesor_ids);
        $kpi_dash['prospeccion_meta'] = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id IN ($ph) AND estado='completada' AND DATE(COALESCE(fecha_realizada,fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $kpi_dash['prospeccion_avance'] = (int)$st->fetchColumn();
        $kpi_dash['prospeccion_pct'] = $kpi_dash['prospeccion_meta'] > 0 ? round($kpi_dash['prospeccion_avance'] * 100 / $kpi_dash['prospeccion_meta'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id IN ($ph) AND t.estado='completada' AND t.tipo_tarea='levantamiento' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $kpi_dash['levantamientos'] = (int)$st->fetchColumn();
        $kpi_dash['interesados']    = $kpi_dash['interes_si'];
        $kpi_dash['levantamiento_pct'] = $kpi_dash['interesados'] > 0 ? round($kpi_dash['levantamientos'] * 100 / $kpi_dash['interesados'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tarea t LEFT JOIN cliente_prospecto cp ON cp.id=t.cliente_prospecto_id
            WHERE t.asesor_id IN ($ph) AND t.estado='completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?
              AND (t.tipo_tarea='visita_frio' OR cp.origen_prospecto='frio')");
        $st->execute($paramsPeriodo);
        $kpi_dash['frio_visitas'] = (int)$st->fetchColumn();
        $kpi_dash['frio_pct'] = $kpi_dash['prospeccion_avance'] > 0 ? round($kpi_dash['frio_visitas'] * 100 / $kpi_dash['prospeccion_avance'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id IN ($ph) AND t.estado='completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ? AND (t.tipo_tarea='recuperacion' OR t.tipo_tarea LIKE '%recupera%')");
        $st->execute($paramsPeriodo);
        $kpi_dash['recuperaciones'] = (int)$st->fetchColumn();
        $kpi_dash['recuperacion_pct'] = $kpi_dash['prospeccion_avance'] > 0 ? round($kpi_dash['recuperaciones'] * 100 / $kpi_dash['prospeccion_avance'], 1) : 0;
        $kpi_dash['eficiencia_pct']   = $kpi_dash['penetracion_visitas'] > 0 ? round($kpi_dash['interes_si'] * 100 / $kpi_dash['penetracion_visitas'], 1) : 0;
        $kpi_dash['postventa_pct']    = $clientes_activos > 0 ? round(min($ops_aprobadas, $clientes_activos) * 100 / $clientes_activos, 1) : 0;

    } catch (Throwable $e) {
        error_log('Gerente Dashboard KPI: ' . $e->getMessage());
    }
}

// ── Últimos supervisores (filtrados por supervisor_ids resueltos) ─
$ultimos_supervisores = [];
if (!empty($supervisor_ids)) {
    try {
        $phSup6 = implode(',', array_fill(0, count($supervisor_ids), '?'));
        $st = $pdo->prepare("
            SELECT u.id, u.nombre, u.email, u.activo, u.estado_aprobacion,
                   (SELECT COUNT(*) FROM asesor a WHERE a.supervisor_id = s.id AND EXISTS (SELECT 1 FROM usuario ua WHERE ua.id=a.usuario_id AND ua.activo=1)) as total_asesores,
                   (SELECT COUNT(*) FROM asesor a2 JOIN cliente_prospecto cp ON cp.asesor_id=a2.id WHERE a2.supervisor_id=s.id) as total_clientes
            FROM supervisor s
            JOIN usuario u ON u.id = s.usuario_id
            WHERE s.id IN ($phSup6) AND u.activo = 1 AND u.estado_aprobacion = 'aprobado'
            ORDER BY u.nombre ASC
            LIMIT 6
        ");
        $st->execute($supervisor_ids);
        $ultimos_supervisores = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Últimas alertas recientes (de supervisores a cargo) ───────
$ultimas_alertas = [];
if (!empty($supervisor_ids)) {
    try {
        $phSup = implode(',', array_fill(0, count($supervisor_ids), '?'));
        $st = $pdo->prepare("
            SELECT am.id as id_alerta, am.campo_modificado, am.valor_nuevo, am.created_at,
                   u.nombre as asesor_nombre,
                   us.nombre as supervisor_nombre
            FROM alerta_modificacion am
            JOIN asesor a ON a.id = am.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN supervisor s ON s.id = a.supervisor_id
            LEFT JOIN usuario us ON us.id = s.usuario_id
            WHERE am.supervisor_id IN ($phSup) AND am.vista_supervisor = 0
            ORDER BY am.created_at DESC LIMIT 5
        ");
        $st->execute($supervisor_ids);
        $ultimas_alertas = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Últimos clientes registrados ─────────────────────────────
$ultimos_clientes = [];
try {
    $st = $pdo->query("
        SELECT cp.nombre, cp.cedula, cp.ciudad, cp.estado, cp.created_at,
               u.nombre as asesor_nombre
        FROM cliente_prospecto cp
        JOIN asesor a ON a.id = cp.asesor_id
        JOIN usuario u ON u.id = a.usuario_id
        ORDER BY cp.created_at DESC LIMIT 5
    ");
    $ultimos_clientes = $st->fetchAll();
} catch (PDOException $e) {}

$gkpis = [
    ['k'=>'actividad',    'lbl'=>'Actividad',      'ico'=>'fa-bolt',            'c'=>'#60a5fa','cr'=>'96,165,250',   'v'=>$kpi_dash['actividad_pct'],     'meta'=>$kpi_dash['actividad_realizadas'].'/'.$kpi_dash['actividad_total'].' hoy',            'url'=>'kpi_penetracion.php?view=actividad'],
    ['k'=>'penetracion',  'lbl'=>'Penetración',    'ico'=>'fa-chart-pie',       'c'=>'#4ade80','cr'=>'74,222,128',   'v'=>$kpi_dash['penetracion_pct'],   'meta'=>$kpi_dash['penetracion_clientes'].'/'.$kpi_dash['penetracion_visitas'].' visitas',   'url'=>'kpi_penetracion.php?view=mercado'],
    ['k'=>'interes',      'lbl'=>'Interés',        'ico'=>'fa-heart-pulse',     'c'=>'#fbbf24','cr'=>'251,191,36',   'v'=>$kpi_dash['interes_pct'],       'meta'=>$kpi_dash['interes_si'].'/'.$kpi_dash['interes_total'].' encuestas',              'url'=>'kpi_penetracion.php?view=interes'],
    ['k'=>'prospeccion',  'lbl'=>'Prospección',    'ico'=>'fa-route',           'c'=>'#a78bfa','cr'=>'167,139,250',  'v'=>$kpi_dash['prospeccion_pct'],   'meta'=>$kpi_dash['prospeccion_avance'].'/'.$kpi_dash['prospeccion_meta'].' meta',          'url'=>'kpi_penetracion.php?view=prospeccion'],
    ['k'=>'levantam',     'lbl'=>'Levantamientos', 'ico'=>'fa-clipboard-check', 'c'=>'#38bdf8','cr'=>'56,189,248',   'v'=>$kpi_dash['levantamiento_pct'],'meta'=>$kpi_dash['levantamientos'].'/'.$kpi_dash['interesados'].' interesados',           'url'=>'kpi_penetracion.php?view=evaluacion'],
    ['k'=>'frio',         'lbl'=>'Visitas Frío',   'ico'=>'fa-snowflake',       'c'=>'#fb923c','cr'=>'251,146,60',   'v'=>$kpi_dash['frio_pct'],          'meta'=>$kpi_dash['frio_visitas'].' visitas frías',                                       'url'=>'kpi_penetracion.php?view=frio'],
    ['k'=>'eficiencia',   'lbl'=>'Eficiencia',     'ico'=>'fa-bolt-lightning',  'c'=>'#f472b6','cr'=>'244,114,182',  'v'=>$kpi_dash['eficiencia_pct'],    'meta'=>$kpi_dash['interes_si'].' con interés',                                          'url'=>'kpi_penetracion.php?view=eficiencia'],
    ['k'=>'postventa',    'lbl'=>'Post-Venta',     'ico'=>'fa-rotate',          'c'=>'#2dd4bf','cr'=>'45,212,191',   'v'=>$kpi_dash['postventa_pct'],     'meta'=>$ops_aprobadas.' aprobados',                                                      'url'=>'kpi_penetracion.php?view=postventa'],
    ['k'=>'recuperacion', 'lbl'=>'Recuperación',   'ico'=>'fa-shield-halved',   'c'=>'#ef4444','cr'=>'239,68,68',    'v'=>$kpi_dash['recuperacion_pct'],  'meta'=>$kpi_dash['recuperaciones'].' gestiones',                                         'url'=>'kpi_penetracion.php?view=recuperacion'],
];

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super_IA — Dashboard Gerente</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
:root{
  --y:#ffdd00; --yd:#f4c400;
  --n1:#0a2748; --n2:#123a6d; --n3:#1e4d8c;
  --bg:#f0f4f9;
  --card:#ffffff;
  --nborder:#e2e8f0;
  --tm:#1a2744; --td:#64748b;
  --shadow:0 2px 14px rgba(18,58,109,.07);
  /* Color acento azul para gerente */
  --g-accent:#60a5fa; --g-accent-dk:#3b82f6;
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter','Segoe UI',sans-serif;}
body{background:var(--bg);color:var(--tm);height:100vh;display:flex;overflow:hidden;}

.main-content{flex:1;margin-left:0 !important;display:flex;flex-direction:column;overflow:hidden;}
.content-area{flex:1;padding:24px 28px 32px;overflow-y:auto;}
::-webkit-scrollbar{width:5px;}
::-webkit-scrollbar-thumb{background:rgba(18,58,109,.25);border-radius:3px;}

/* ── HERO ── */
.hero{
  background:linear-gradient(125deg,#06101e 0%,#0a2748 40%,#0f2e5a 70%,#1a3f6f 100%);
  border-radius:22px;padding:26px 32px;margin-bottom:22px;
  display:flex;align-items:center;justify-content:space-between;gap:24px;
  position:relative;overflow:hidden;border:1px solid rgba(96,165,250,.15);
}
.hero::before{
  content:'';position:absolute;right:-80px;top:-80px;width:280px;height:280px;
  background:radial-gradient(circle,rgba(96,165,250,.12) 0%,transparent 65%);pointer-events:none;
}
.hero::after{
  content:'';position:absolute;left:-50px;bottom:-50px;width:200px;height:200px;
  background:radial-gradient(circle,rgba(18,58,109,.9) 0%,transparent 65%);pointer-events:none;
}
.hero-left{position:relative;z-index:1;}
.hero-title{font-size:23px;font-weight:900;color:#fff;margin-bottom:3px;letter-spacing:-.3px;}
.hero-title span{color:var(--g-accent);}
.hero-sub{font-size:12.5px;color:rgba(255,255,255,.55);font-weight:500;margin-bottom:14px;}
.hero-prog-label{font-size:11px;color:rgba(255,255,255,.6);display:flex;justify-content:space-between;margin-bottom:5px;}
.hero-prog-track{width:260px;height:7px;background:rgba(255,255,255,.12);border-radius:99px;overflow:hidden;}
.hero-prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--g-accent),#93c5fd);transition:width 1.6s cubic-bezier(.4,0,.2,1);}
.hero-right{display:flex;gap:10px;flex-shrink:0;position:relative;z-index:1;}
.hs-pill{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:13px 18px;text-align:center;min-width:78px;transition:.2s;}
.hs-pill:hover{background:rgba(255,255,255,.12);}
.hs-num{font-size:24px;font-weight:900;color:var(--g-accent);line-height:1;}
.hs-lbl{font-size:9.5px;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-top:3px;}
.hs-pill.danger{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.1);}
.hs-pill.danger .hs-num{color:#f87171;}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.sc{background:#fff;border:1px solid var(--nborder);border-radius:16px;padding:16px 18px;position:relative;overflow:hidden;box-shadow:var(--shadow);transition:.2s;}
.sc:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(18,58,109,.12);border-color:rgba(18,58,109,.2);}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--ac,var(--g-accent));border-radius:3px 3px 0 0;}
.sc-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:10px;background:var(--aic,rgba(96,165,250,.1));color:var(--ac,var(--g-accent));}
.sc-value{font-size:28px;font-weight:900;color:var(--n1);line-height:1;margin-bottom:3px;}
.sc-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--td);margin-bottom:4px;}
.sc-sub{font-size:11px;color:var(--td);}
.sc-badge{position:absolute;top:14px;right:14px;font-size:10px;font-weight:800;padding:3px 9px;border-radius:6px;}
.sb-green{background:rgba(74,222,128,.13);color:#22c55e;}
.sb-yellow{background:rgba(251,191,36,.13);color:#f59e0b;}
.sb-red{background:rgba(239,68,68,.13);color:#ef4444;}
.sb-blue{background:rgba(96,165,250,.13);color:#3b82f6;}

/* ── SECTION HEADER ── */
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;}
.sec-title{font-size:15px;font-weight:900;color:var(--n1);display:flex;align-items:center;gap:8px;}
.sec-title i{color:var(--g-accent);}
.btn-link{font-size:11.5px;font-weight:700;color:var(--g-accent);text-decoration:none;background:rgba(96,165,250,.09);border:1px solid rgba(96,165,250,.18);padding:5px 13px;border-radius:8px;transition:.18s;}
.btn-link:hover{background:rgba(96,165,250,.18);color:var(--g-accent);}

/* ── KPI SECTION ── */
.kpi-section{
  background:linear-gradient(155deg,#060f1d 0%,#0b1f3a 45%,#0f2d55 100%);
  border-radius:22px;border:1px solid rgba(96,165,250,.16);
  padding:22px 20px 24px;margin-bottom:22px;position:relative;overflow:hidden;
}
.kpi-section::before{
  content:'';position:absolute;inset:0;
  background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(255,255,255,.016) 3px,rgba(255,255,255,.016) 4px);
  pointer-events:none;animation:scanMove 10s linear infinite;
}
@keyframes scanMove{from{background-position:0 0;}to{background-position:0 100px;}}
.kpi-section::after{
  content:'';position:absolute;right:-60px;top:-60px;width:280px;height:280px;
  background:radial-gradient(circle,rgba(96,165,250,.09) 0%,transparent 65%);
  pointer-events:none;animation:orbPulse 4s ease-in-out infinite;
}
@keyframes orbPulse{0%,100%{opacity:.55;transform:scale(1);}50%{opacity:1;transform:scale(1.18);}}
.kpi-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;position:relative;z-index:1;flex-wrap:wrap;gap:10px;}
.kpi-title{font-size:17px;font-weight:900;color:#fff;display:flex;align-items:center;gap:10px;letter-spacing:-.2px;}
.kpi-title i{color:var(--g-accent);font-size:19px;filter:drop-shadow(0 0 9px rgba(96,165,250,.65));animation:iconBounce 2.8s ease-in-out infinite;}
@keyframes iconBounce{0%,100%{transform:translateY(0) rotate(0deg);}40%{transform:translateY(-4px) rotate(-4deg);}70%{transform:translateY(-2px) rotate(3deg);}}
.kpi-title-sub{font-size:11.5px;color:rgba(255,255,255,.42);font-weight:600;margin-top:3px;}
.kpi-actions{display:flex;align-items:center;gap:9px;}
.kpi-live{display:flex;align-items:center;gap:7px;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.32);padding:5px 14px;border-radius:99px;font-size:11.5px;font-weight:800;color:#4ade80;letter-spacing:.5px;}
.kpi-live-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 8px #4ade8088;animation:livePulse 1.8s infinite;}
@keyframes livePulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(.7);}}
.kpi-btn{font-size:11.5px;font-weight:700;color:var(--g-accent);text-decoration:none;background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.28);padding:5px 14px;border-radius:9px;transition:.18s;white-space:nowrap;}
.kpi-btn:hover{background:rgba(96,165,250,.22);color:var(--g-accent);border-color:rgba(96,165,250,.5);}

/* ── KPI GAUGE GRID ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;position:relative;z-index:1;}
@keyframes kpiEnter{from{opacity:0;transform:translateY(32px) scale(.91);}to{opacity:1;transform:translateY(0) scale(1);}}
@keyframes critRing{0%,100%{box-shadow:0 4px 22px rgba(0,0,0,.28),0 0 0 0 rgba(239,68,68,.0);}50%{box-shadow:0 4px 22px rgba(0,0,0,.28),0 0 0 7px rgba(239,68,68,.2);}}
@keyframes fillShimmer{0%{transform:translateX(-100%);}100%{transform:translateX(220%);}}

.g-card{
  background:rgba(255,255,255,.97);border:1.5px solid rgba(255,255,255,.15);border-radius:20px;
  padding:17px 13px 14px;display:flex;flex-direction:column;align-items:center;
  position:relative;overflow:hidden;text-decoration:none;color:inherit;
  transition:transform .28s cubic-bezier(.34,1.56,.64,1),box-shadow .28s,border-color .28s;
  cursor:pointer;box-shadow:0 5px 22px rgba(0,0,0,.28);
  animation:kpiEnter .52s cubic-bezier(.34,1.56,.64,1) both;
}
.g-card:hover{transform:translateY(-9px) scale(1.025);border-color:var(--gc,#60a5fa);box-shadow:0 22px 55px rgba(var(--gc-rgb,96,165,250),.32),0 0 0 1px rgba(var(--gc-rgb,96,165,250),.25);}
.g-card::before{content:'';position:absolute;top:0;left:-120%;width:55%;height:100%;background:linear-gradient(105deg,transparent 35%,rgba(255,255,255,.5) 55%,transparent 75%);transition:left .6s ease;pointer-events:none;z-index:3;}
.g-card:hover::before{left:160%;}
.g-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--gc,var(--g-accent));border-radius:3px 3px 0 0;transition:height .28s,box-shadow .28s;}
.g-card:hover::after{height:4px;box-shadow:0 0 8px rgba(var(--gc-rgb,96,165,250),.6);}
.g-glow{position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(var(--gc-rgb,96,165,250),.07) 0%,transparent 55%);pointer-events:none;z-index:0;}
.g-card.g-crit{animation:kpiEnter .52s cubic-bezier(.34,1.56,.64,1) both,critRing 2.3s ease-in-out 1s infinite;}

.g-title{font-size:10.5px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:flex;align-items:center;gap:5px;margin-bottom:3px;position:relative;z-index:1;}
.g-title i{font-size:12px;color:var(--gc,var(--g-accent));filter:drop-shadow(0 0 5px rgba(var(--gc-rgb,96,165,250),.55));}
.g-chart{width:100%;max-width:175px;height:155px;min-height:155px;}
.g-pct-row{display:flex;align-items:center;gap:8px;margin-top:-9px;position:relative;z-index:1;}
.g-pct{font-size:25px;font-weight:900;color:var(--n1);line-height:1;}
.g-badge{font-size:9px;font-weight:800;padding:2px 7px;border-radius:5px;}
.gb-ok{background:rgba(74,222,128,.13);color:#22c55e;}
.gb-wa{background:rgba(251,191,36,.13);color:#f59e0b;}
.gb-er{background:rgba(239,68,68,.13);color:#ef4444;}
.g-meta{font-size:11px;color:#64748b;margin-top:5px;text-align:center;font-weight:600;position:relative;z-index:1;}
.g-track{width:78%;height:5px;background:rgba(0,0,0,.09);border-radius:99px;overflow:hidden;margin-top:9px;position:relative;z-index:1;}
.g-fill{height:100%;border-radius:99px;background:var(--gc,var(--g-accent));transition:width 1.6s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;}
.g-fill::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.6),transparent);animation:fillShimmer 2.8s ease-in-out infinite;}

/* ops especial */
.g-ops{background:linear-gradient(135deg,#050f1c 0%,#0a2748 55%,#123a6d 100%);border-color:rgba(96,165,250,.28);border-width:1.5px;}
.g-ops:hover{border-color:rgba(96,165,250,.75);box-shadow:0 22px 55px rgba(96,165,250,.25),0 0 0 1px rgba(96,165,250,.3)!important;}
.ops-big{font-size:42px;font-weight:900;color:var(--g-accent);line-height:1;margin:14px 0 5px;filter:drop-shadow(0 0 14px rgba(96,165,250,.45));animation:opsGlow 2.5s ease-in-out infinite;}
@keyframes opsGlow{0%,100%{filter:drop-shadow(0 0 10px rgba(96,165,250,.4));}50%{filter:drop-shadow(0 0 20px rgba(96,165,250,.75));}}
.ops-sub{font-size:15px;font-weight:700;color:rgba(255,255,255,.82);}
.ops-tag{font-size:9px;text-transform:uppercase;color:rgba(255,255,255,.45);font-weight:700;letter-spacing:.6px;margin-top:4px;}

/* ── FUNNEL ── */
.funnel-wrap{background:#fff;border:1px solid var(--nborder);border-radius:18px;padding:20px 24px;margin-bottom:22px;box-shadow:var(--shadow);position:relative;overflow:hidden;}
.funnel-wrap::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% -20%,rgba(96,165,250,.04) 0%,transparent 60%);pointer-events:none;}
.funnel-steps{display:flex;align-items:center;justify-content:space-between;margin-top:18px;position:relative;z-index:1;}
.f-step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;max-width:90px;}
.f-ico{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:19px;position:relative;transition:.2s;}
.f-ico:hover{transform:scale(1.1);}
.f-pct-badge{position:absolute;top:-7px;right:-7px;font-size:8.5px;font-weight:900;padding:1px 5px;border-radius:5px;background:var(--fc,var(--g-accent));color:#fff;white-space:nowrap;}
.f-lbl{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:#64748b;text-align:center;line-height:1.3;}
.f-val{font-size:13px;font-weight:900;color:var(--n1);}
.f-arrow{flex:1;display:flex;align-items:center;justify-content:center;margin-bottom:20px;color:rgba(96,165,250,.35);font-size:14px;}

/* ── BOTTOM GRID ── */
.btm-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.b-card{background:#fff;border:1px solid var(--nborder);border-radius:16px;overflow:hidden;box-shadow:var(--shadow);transition:.2s;}
.b-card:hover{border-color:rgba(18,58,109,.25);box-shadow:0 6px 22px rgba(18,58,109,.1);}
.bh{padding:13px 16px;border-bottom:1px solid var(--nborder);display:flex;align-items:center;gap:9px;background:rgba(248,250,252,.8);}
.bh-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.bh h5{font-size:13px;font-weight:900;color:var(--n1);margin:0;flex:1;}
.bh-num{font-size:10px;font-weight:800;padding:2px 8px;border-radius:6px;border:1px solid;white-space:nowrap;}
.num-navy{color:var(--n2);background:rgba(18,58,109,.07);border-color:rgba(18,58,109,.15);}
.num-red{color:#ef4444;background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.2);}
.bh-link{font-size:11px;font-weight:700;color:var(--g-accent);text-decoration:none;opacity:.8;}
.bh-link:hover{opacity:1;}

/* supervisor rows */
.sup-row{display:flex;align-items:center;gap:10px;padding:10px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .14s;}
.sup-row:hover{background:rgba(96,165,250,.04);}
.sup-row:last-child{border-bottom:none;}
.sup-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#0a2748,#1e4d8c);color:var(--g-accent);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:13px;flex-shrink:0;}
.sup-name{font-size:12.5px;font-weight:700;color:var(--n1);}
.sup-sub{font-size:10.5px;color:var(--td);}
.sup-badges{display:flex;gap:5px;margin-left:auto;flex-shrink:0;}
.sup-tag{font-size:9px;font-weight:800;padding:2px 6px;border-radius:5px;white-space:nowrap;}
.st-a{background:rgba(96,165,250,.12);color:#2563eb;}
.st-c{background:rgba(34,197,94,.12);color:#16a34a;}

/* clients */
.cli-row{display:flex;align-items:center;gap:10px;padding:10px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .14s;}
.cli-row:hover{background:rgba(18,58,109,.03);}
.cli-row:last-child{border-bottom:none;}
.cli-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--n1),var(--n2));color:var(--g-accent);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0;}
.cli-name{font-size:12.5px;font-weight:700;color:var(--n1);}
.cli-sub{font-size:10.5px;color:var(--td);}
.cli-tag{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:5px;margin-left:auto;flex-shrink:0;white-space:nowrap;}
.tag-c{background:rgba(34,197,94,.12);color:#16a34a;}
.tag-p{background:rgba(59,130,246,.1);color:#2563eb;}
.tag-d{background:rgba(239,68,68,.1);color:#dc2626;}

/* quick links */
.qk-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px;}
.qk{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:11px 6px;border-radius:13px;font-weight:800;font-size:11px;text-align:center;min-height:66px;text-decoration:none;transition:.18s;border:1px solid transparent;}
.qk i{font-size:17px;}
.qk:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.25);}
.qk-y{background:linear-gradient(135deg,#ffdd00,#f4c400);color:var(--n1);}
.qk-n{background:linear-gradient(135deg,#0a2748,#123a6d);color:#fff;border-color:#1e4d8c;}
.qk-g{background:linear-gradient(135deg,#064e3b,#059669);color:#fff;}
.qk-b{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;}
.qk-r{background:linear-gradient(135deg,#7f1d1d,#dc2626);color:#fff;}
.qk-p{background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;}
.qk-t{background:linear-gradient(135deg,#134e4a,#0d9488);color:#fff;}
.qk-o{background:linear-gradient(135deg,#7c2d12,#ea580c);color:#fff;}
.qk-bl{background:linear-gradient(135deg,#1e3a5f,#3b82f6);color:#fff;}

/* alert rows */
.al-row{display:flex;align-items:flex-start;gap:10px;padding:10px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .14s;}
.al-row:hover{background:rgba(239,68,68,.03);}
.al-row:last-child{border-bottom:none;}
.al-ic{width:34px;height:34px;border-radius:10px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.18);color:#ef4444;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;margin-top:1px;}
.al-campo{font-size:12.5px;font-weight:800;color:var(--n1);}
.al-asesor{font-size:10.5px;color:var(--td);margin-top:1px;}
.al-t{font-size:9.5px;color:var(--td);margin-left:auto;flex-shrink:0;white-space:nowrap;padding-top:2px;}

.empty{padding:28px;text-align:center;color:var(--td);font-size:12.5px;}
.empty i{display:block;font-size:22px;margin-bottom:7px;opacity:.4;}

@media(max-width:1350px){.kpi-grid{grid-template-columns:repeat(auto-fill,minmax(180px,1fr));}}
@media(max-width:1100px){.stats-row,.btm-grid{grid-template-columns:1fr 1fr;}.funnel-steps .f-arrow{display:none;}.funnel-steps{flex-wrap:wrap;gap:12px;justify-content:center;}}
@media(max-width:800px){.kpi-grid{grid-template-columns:repeat(2,1fr);}.btm-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php $navTitle=''; $navIcon=''; require_once '_sidebar_gerente.php'; ?>

<!-- ── HERO ── -->
<div class="hero">
  <div class="hero-left">
    <div class="hero-title">Panel de Gerencia — <span><?= htmlspecialchars(explode(' ',$gerente_nombre)[0]) ?></span></div>
    <div class="hero-sub">Visión global de operaciones · <?= strtoupper(date('M Y')) ?></div>
    <?php if($tareas_hoy>0): ?>
    <div class="hero-prog-label">
      <span>Progreso de tareas hoy (global)</span><span><?= $tareas_completadas ?>/<?= $tareas_hoy ?></span>
    </div>
    <div class="hero-prog-track"><div class="hero-prog-fill" id="hp-fill" style="width:0%;"></div></div>
    <?php endif; ?>
  </div>
  <div class="hero-right">
    <div class="hs-pill">
      <div class="hs-num" id="cnt-supervisores">0</div>
      <div class="hs-lbl">Supervisores</div>
    </div>
    <div class="hs-pill">
      <div class="hs-num" id="cnt-asesores">0</div>
      <div class="hs-lbl">Asesores</div>
    </div>
    <div class="hs-pill">
      <div class="hs-num" id="cnt-clientes">0</div>
      <div class="hs-lbl">Clientes</div>
    </div>
    <?php if($alertas_pendientes>0): ?>
    <div class="hs-pill danger">
      <div class="hs-num"><?= $alertas_pendientes ?></div>
      <div class="hs-lbl">Alertas</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── STAT CARDS ── -->
<div class="stats-row">
  <div class="sc" style="--ac:var(--g-accent);--aic:rgba(96,165,250,.1);">
    <div class="sc-badge <?= $pct_tareas>=70?'sb-green':($pct_tareas>=35?'sb-yellow':'sb-red') ?>"><?= $pct_tareas ?>%</div>
    <div class="sc-icon"><i class="fas fa-bolt"></i></div>
    <div class="sc-label">Actividad Hoy</div>
    <div class="sc-value" id="cnt-act">0</div>
    <div class="sc-sub"><?= $tareas_completadas ?>/<?= $tareas_hoy ?> tareas completadas</div>
  </div>
  <div class="sc" style="--ac:#22c55e;--aic:rgba(34,197,94,.1);">
    <div class="sc-badge sb-blue"><?= $kpi_dash['penetracion_pct'] ?>%</div>
    <div class="sc-icon"><i class="fas fa-chart-pie"></i></div>
    <div class="sc-label">Penetración Mensual</div>
    <div class="sc-value" id="cnt-pen">0</div>
    <div class="sc-sub"><?= $kpi_dash['penetracion_clientes'] ?>/<?= $kpi_dash['penetracion_visitas'] ?> visitas</div>
  </div>
  <div class="sc" style="--ac:#3b82f6;--aic:rgba(59,130,246,.1);">
    <div class="sc-badge sb-blue"><?= $total_ops_credito ?></div>
    <div class="sc-icon"><i class="fas fa-hand-holding-dollar"></i></div>
    <div class="sc-label">Créditos <?= date('M Y') ?></div>
    <div class="sc-value" style="font-size:20px;">$<?= number_format($monto_total,0,'.',',') ?></div>
    <div class="sc-sub"><?= $total_ops_credito ?> crédito<?= $total_ops_credito!=1?'s':'' ?> desembolsado<?= $total_ops_credito!=1?'s':'' ?></div>
  </div>
  <div class="sc" style="--ac:#ef4444;--aic:rgba(239,68,68,.1);">
    <div class="sc-badge sb-red"><?= $alertas_pendientes ?></div>
    <div class="sc-icon"><i class="fas fa-bell"></i></div>
    <div class="sc-label">Alertas Sin Ver</div>
    <div class="sc-value" style="color:#ef4444;" id="cnt-alerta">0</div>
    <div class="sc-sub">Pendientes de revisión</div>
  </div>
</div>

<!-- ── KPI GAUGES ── -->
<div class="kpi-section">
<div class="kpi-hd">
  <div>
    <div class="kpi-title"><i class="fas fa-gauge-high"></i> KPIs Globales — Tacómetros en Vivo</div>
    <div class="kpi-title-sub">Toda la red · <?= strtoupper(date('M Y')) ?></div>
  </div>
  <div class="kpi-actions">
    <div class="kpi-live"><div class="kpi-live-dot"></div>EN VIVO</div>
    <a href="kpi_penetracion.php" class="kpi-btn"><i class="fas fa-arrow-up-right-from-square"></i> Ver reportes</a>
  </div>
</div>
<div class="kpi-grid">
<?php foreach($gkpis as $g):
  $v=round((float)$g['v'],1);
  $bc=$v>=70?'gb-ok':($v>=35?'gb-wa':'gb-er');
  $bt=$v>=70?'OK':($v>=35?'Bajo':'Crítico');
?>
<a href="<?=htmlspecialchars($g['url'])?>" class="g-card<?=$bc==='gb-er'?' g-crit':''?>" style="--gc:<?=$g['c']?>;--gc-rgb:<?=$g['cr']?>;">
  <div class="g-glow"></div>
  <div class="g-title"><i class="fas <?=$g['ico']?>"></i><?=htmlspecialchars($g['lbl'])?></div>
  <div class="g-chart" id="gc-<?=htmlspecialchars($g['k'])?>"></div>
  <div class="g-pct-row">
    <span class="g-pct"><?=$v?>%</span>
    <span class="g-badge <?=$bc?>"><?=$bt?></span>
  </div>
  <div class="g-meta"><?=htmlspecialchars($g['meta'])?></div>
  <div class="g-track"><div class="g-fill" style="width:<?=min(100,$v)?>%;background:<?=$g['c']?>;"></div></div>
</a>
<?php endforeach; ?>

<!-- CRÉDITOS ESPECIAL -->
<a href="kpi_penetracion.php?view=operaciones" class="g-card g-ops" style="--gc:#60a5fa;--gc-rgb:96,165,250;justify-content:center;">
  <div class="g-glow"></div>
  <div class="g-title" style="color:rgba(255,255,255,.6);"><i class="fas fa-hand-holding-dollar" style="color:var(--g-accent);"></i>Créditos <?=date('M Y')?></div>
  <div class="ops-big" id="cnt-ops-big">0</div>
  <div class="ops-sub">$<?=number_format($monto_total,0,'.',',')?></div>
  <div class="ops-tag">Monto total este mes</div>
  <div class="g-track" style="background:rgba(255,255,255,.1);width:80%;margin-top:12px;">
    <div class="g-fill" style="width:<?=min(100,$total_ops_credito*10)?>%;background:var(--g-accent);"></div>
  </div>
</a>
</div><!-- /kpi-grid -->
</div><!-- /kpi-section -->

<!-- ── FUNNEL ── -->
<div class="funnel-wrap">
  <div class="sec-hd" style="margin-bottom:0;">
    <div class="sec-title"><i class="fas fa-diagram-project"></i> Flujo de Conversión Global</div>
    <span style="font-size:12px;color:var(--td);font-weight:700;"><?= strtoupper(date('M Y')) ?></span>
  </div>
  <div class="funnel-steps">
    <?php
    $fsteps=[
      ['lbl'=>'Prospección','ico'=>'fas fa-route',           'c'=>'#a78bfa','v'=>$kpi_dash['prospeccion_pct'],  'n'=>$kpi_dash['prospeccion_avance']],
      ['lbl'=>'Visitas',    'ico'=>'fas fa-map-marker-alt',  'c'=>'#60a5fa','v'=>$kpi_dash['penetracion_pct'],  'n'=>$kpi_dash['penetracion_visitas']],
      ['lbl'=>'Interés',    'ico'=>'fas fa-heart-pulse',     'c'=>'#fbbf24','v'=>$kpi_dash['interes_pct'],      'n'=>$kpi_dash['interes_si']],
      ['lbl'=>'Levantam.',  'ico'=>'fas fa-clipboard-check', 'c'=>'#38bdf8','v'=>$kpi_dash['levantamiento_pct'],'n'=>$kpi_dash['levantamientos']],
      ['lbl'=>'Eficiencia', 'ico'=>'fas fa-bolt',            'c'=>'#f472b6','v'=>$kpi_dash['eficiencia_pct'],   'n'=>$kpi_dash['interes_si']],
      ['lbl'=>'Créditos',   'ico'=>'fas fa-handshake',       'c'=>'#60a5fa','v'=>min(100,$total_ops_credito*10),'n'=>$total_ops_credito],
    ];
    foreach($fsteps as $i=>$fs): ?>
    <div class="f-step">
      <div class="f-ico" style="color:<?=$fs['c']?>;background:<?=$fs['c']?>18;border:1px solid <?=$fs['c']?>44;">
        <i class="<?=$fs['ico']?>"></i>
        <span class="f-pct-badge" style="background:<?=$fs['c']?>;color:#fff;"><?=(int)$fs['v']?>%</span>
      </div>
      <div class="f-lbl"><?=htmlspecialchars($fs['lbl'])?></div>
      <div class="f-val" style="color:<?=$fs['c']?>"><?=$fs['n']?></div>
    </div>
    <?php if($i<count($fsteps)-1): ?>
    <div class="f-arrow"><i class="fas fa-chevron-right"></i></div>
    <?php endif; endforeach; ?>
  </div>
</div>

<!-- ── BOTTOM GRID ── -->
<div class="btm-grid">

  <!-- Mis Supervisores -->
  <div class="b-card">
    <div class="bh">
      <div class="bh-ic" style="background:rgba(96,165,250,.12);color:var(--g-accent);"><i class="fas fa-users-gear"></i></div>
      <h5>Mis Supervisores</h5>
      <span class="bh-num num-navy"><?= $total_supervisores ?></span>
      <a href="mis_supervisores.php" class="bh-link ms-auto">Ver todos →</a>
    </div>
    <?php if(empty($ultimos_supervisores)): ?>
      <div class="empty"><i class="fas fa-users-slash"></i>Sin supervisores registrados</div>
    <?php else: foreach($ultimos_supervisores as $sv):
      $in = mb_strtoupper(mb_substr(trim($sv['nombre']??'S'),0,1));
    ?>
    <a href="mis_supervisores.php" class="sup-row">
      <div class="sup-av"><?= htmlspecialchars($in) ?></div>
      <div style="flex:1;min-width:0;">
        <div class="sup-name"><?= htmlspecialchars($sv['nombre']??'—') ?></div>
        <div class="sup-sub"><?= htmlspecialchars($sv['email']??'') ?></div>
      </div>
      <div class="sup-badges">
        <span class="sup-tag st-a"><?= (int)($sv['total_asesores']??0) ?> asesores</span>
        <span class="sup-tag st-c"><?= (int)($sv['total_clientes']??0) ?> clientes</span>
      </div>
    </a>
    <?php endforeach; endif; ?>
  </div>

  <!-- Acceso Rápido -->
  <div class="b-card">
    <div class="bh">
      <div class="bh-ic" style="background:rgba(96,165,250,.12);color:var(--g-accent);"><i class="fas fa-bolt"></i></div>
      <h5>Acceso Rápido</h5>
    </div>
    <div class="qk-grid">
      <a href="mis_supervisores.php"    class="qk qk-bl"><i class="fas fa-users-gear"></i>Supervisores</a>
      <a href="clientes.php"            class="qk qk-n"><i class="fas fa-address-book"></i>Clientes</a>
      <a href="operaciones.php"         class="qk qk-g"><i class="fas fa-handshake"></i>Operaciones</a>
      <a href="mapa_vivo_superIA.php"   class="qk qk-b"><i class="fas fa-map-marked-alt"></i>Mapa Vivo</a>
      <a href="alertas.php"             class="qk qk-r"><i class="fas fa-bell"></i>Alertas<?=$alertas_pendientes>0?" ($alertas_pendientes)":''?></a>
      <a href="kpi_penetracion.php"     class="qk qk-p"><i class="fas fa-chart-line"></i>KPI Report</a>
      <a href="registro_supervisor.php" class="qk qk-y"><i class="fas fa-user-plus"></i>Nuevo Supervisor</a>
      <a href="metas.php"               class="qk qk-o"><i class="fas fa-bullseye"></i>Metas</a>
    </div>
  </div>

  <!-- Últimas Alertas Globales -->
  <div class="b-card">
    <div class="bh">
      <div class="bh-ic" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-bell"></i></div>
      <h5>Alertas Recientes</h5>
      <?php if($alertas_pendientes>0): ?>
        <span class="bh-num num-red"><?= $alertas_pendientes ?> pendiente<?= $alertas_pendientes!=1?'s':'' ?></span>
      <?php endif; ?>
      <a href="alertas.php" class="bh-link ms-auto">Ver todas →</a>
    </div>
    <?php if(empty($ultimas_alertas)): ?>
      <div class="empty"><i class="fas fa-check-circle" style="color:#22c55e;opacity:.6;"></i>Sin alertas pendientes</div>
    <?php else: foreach($ultimas_alertas as $al):
      $campo = ucfirst(str_replace('_',' ',$al['campo_modificado']??'modificación'));
      $diff  = time()-strtotime($al['created_at']);
      $t     = $diff<60?'hace '.$diff.'s':($diff<3600?'hace '.floor($diff/60).'min':($diff<86400?'hace '.floor($diff/3600).'h':date('d/m',strtotime($al['created_at']))));
    ?>
    <a href="alertas_detalle.php?id=<?= $al['id_alerta'] ?>" class="al-row">
      <div class="al-ic"><i class="fas fa-triangle-exclamation"></i></div>
      <div style="flex:1;min-width:0;">
        <div class="al-campo"><?= htmlspecialchars($campo) ?></div>
        <div class="al-asesor">
          <i class="fas fa-user-circle" style="font-size:10px;margin-right:3px;"></i><?= htmlspecialchars($al['asesor_nombre']??'Asesor') ?>
          <?php if(!empty($al['supervisor_nombre'])): ?>
            · <i class="fas fa-users-gear" style="font-size:9px;margin-right:2px;"></i><?= htmlspecialchars($al['supervisor_nombre']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="al-t"><?= $t ?></div>
    </a>
    <?php endforeach; endif; ?>
  </div>

</div><!-- /btm-grid -->

<script>
document.addEventListener('DOMContentLoaded',function(){
  function counter(el,target,dur,pre,suf){
    if(!el)return;var s=0,step=target/(dur/16);
    var t=setInterval(function(){s+=step;if(s>=target){s=target;clearInterval(t);}el.textContent=pre+Math.round(s).toLocaleString()+suf;},16);
  }
  setTimeout(function(){
    counter(document.getElementById('cnt-supervisores'),<?=$total_supervisores?>,900,'','');
    counter(document.getElementById('cnt-asesores'),    <?=$total_asesores?>,900,'','');
    counter(document.getElementById('cnt-clientes'),    <?=$total_clientes?>,1200,'','');
    counter(document.getElementById('cnt-act'),         <?=$pct_tareas?>,900,'','%');
    counter(document.getElementById('cnt-pen'),         <?=$kpi_dash['penetracion_pct']?>,900,'','%');
    counter(document.getElementById('cnt-ops-big'),     <?=$total_ops_credito?>,1000,'','');
    counter(document.getElementById('cnt-alerta'),      <?=$alertas_pendientes?>,700,'','');
    var hf=document.getElementById('hp-fill');
    if(hf)setTimeout(function(){hf.style.width='<?=$pct_tareas?>%';},200);
    document.querySelectorAll('.g-fill').forEach(function(el){
      var w=el.style.width;el.style.width='0';
      setTimeout(function(){el.style.width=w;},350);
    });
  },400);

  function makeGauge(id,val,color){
    var el=document.getElementById(id);if(!el)return;
    var fill=val>=70?color:(val>=35?'#fbbf24':'#f87171');
    var trackColor=val>=70?'rgba(74,222,128,.12)':(val>=35?'rgba(251,191,36,.12)':'rgba(239,68,68,.12)');
    new ApexCharts(el,{
      series:[Math.min(100,Math.max(0,val))],
      chart:{type:'radialBar',height:155,width:'100%',toolbar:{show:false},background:'transparent',
        animations:{enabled:true,easing:'easeout',speed:1400,animateGradually:{enabled:true,delay:120},dynamicAnimation:{enabled:true,speed:700}}},
      plotOptions:{radialBar:{
        startAngle:-135,endAngle:135,
        track:{background:trackColor,strokeWidth:'72%',margin:4,dropShadow:{enabled:false}},
        dataLabels:{show:true,name:{show:false},value:{offsetY:8,fontSize:'18px',fontWeight:'900',fontFamily:'Inter,sans-serif',color:'#1a2744',formatter:function(v){return Math.round(v)+'%';}}},
        hollow:{margin:5,size:'50%',background:'transparent',dropShadow:{enabled:true,top:2,left:0,blur:6,opacity:.08}}}},
      fill:{type:'gradient',gradient:{shade:'dark',type:'diagonal1',shadeIntensity:.22,gradientToColors:[fill],inverseColors:false,opacityFrom:1,opacityTo:.85,stops:[0,100]}},
      colors:[color],stroke:{lineCap:'round',width:3},tooltip:{enabled:false},
      grid:{padding:{top:-12,bottom:-12,left:-10,right:-10}},states:{hover:{filter:{type:'none'}},active:{filter:{type:'none'}}}
    }).render();
  }

  var gd=<?php $ga=[];foreach($gkpis as $g)$ga[]=['k'=>$g['k'],'v'=>(float)$g['v'],'c'=>$g['c']];echo json_encode($ga);?>;

  document.querySelectorAll('.kpi-grid .g-card').forEach(function(c,i){c.style.animationDelay=(i*0.065)+'s';});
  gd.forEach(function(g,i){setTimeout(function(){makeGauge('gc-'+g.k,g.v,g.c);},i*70);});
});
</script>
</body>
</html>

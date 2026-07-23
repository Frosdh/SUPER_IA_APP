<?php
// ============================================================
// admin/super_admin_index.php — Dashboard Super_IA (SuperAdmin)
//
// Antes: panel simple (tarjetas básicas + tabla de distribución de
// roles). Ahora: mismo diseño "premium" que usa el dashboard del
// supervisor (supervisor_index.php) — hero, tarjetas KPI, tacómetros
// ApexCharts, embudo de conversión y accesos rápidos — pero con
// alcance GLOBAL (todos los bancos/cooperativas) por defecto, y con
// un combobox de búsqueda por escritura para filtrar TODO el panel
// a un banco/cooperativa específico.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php';   // PDO ($pdo)

if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    header('Location: login.php?role=super_admin');
    exit;
}

$super_admin_nombre = $_SESSION['super_admin_nombre'] ?? 'Super Admin';
$super_admin_rol     = $_SESSION['super_admin_rol'] ?? 'Super Administrador';

// ── Bancos/cooperativas para el combobox de búsqueda ─────────
$bancos_dash = [];
try {
    $bancos_dash = $pdo->query("SELECT id, nombre FROM unidad_bancaria ORDER BY nombre ASC")->fetchAll();
} catch (Throwable $e) {
    $bancos_dash = [];
}
$bancos_dash_ids = array_map(fn($b) => (string)$b['id'], $bancos_dash);

$banco_filtro = trim($_GET['banco_filtro'] ?? '');
if ($banco_filtro !== '' && !in_array($banco_filtro, $bancos_dash_ids, true)) {
    $banco_filtro = '';
}
$nombre_banco_filtro = '';
foreach ($bancos_dash as $b) { if ($b['id'] === $banco_filtro) { $nombre_banco_filtro = $b['nombre']; break; } }

// ── Resolver TODOS los asesor.id que aplican: todo el sistema, o
//    solo los del banco/cooperativa elegido en el combobox. Este
//    array se reutiliza como el "supervisor_table_id" del dashboard
//    original: todas las consultas de abajo filtran por
//    "asesor_id IN (...)" en vez de "supervisor_id = ?".
$asesor_ids_dashboard = [];
try {
    $sqlAseDash = "
        SELECT a.id
        FROM asesor a
        JOIN usuario au ON au.id = a.usuario_id
        LEFT JOIN supervisor sv_d ON sv_d.id = a.supervisor_id
        LEFT JOIN jefe_agencia ja_d ON ja_d.id = sv_d.jefe_agencia_id
        LEFT JOIN agencia ag_d ON ag_d.id = ja_d.agencia_id
        WHERE au.activo = 1
    ";
    $paramsAseDash = [];
    if ($banco_filtro !== '') {
        $sqlAseDash .= " AND ag_d.unidad_bancaria_id = ?";
        $paramsAseDash[] = $banco_filtro;
    }
    $stAseDash = $pdo->prepare($sqlAseDash);
    $stAseDash->execute($paramsAseDash);
    $asesor_ids_dashboard = $stAseDash->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $asesor_ids_dashboard = [];
}

// ── KPIs principales ─────────────────────────────────────────
$total_asesores       = count($asesor_ids_dashboard);
$total_clientes       = 0;
$clientes_activos     = 0;
$tareas_hoy           = 0;
$tareas_completadas   = 0;
$alertas_pendientes   = 0;
$fichas_credito       = 0;
$monto_fichas         = 0.0;
$ops_aprobadas        = 0;
$monto_ops            = 0.0;

if (!empty($asesor_ids_dashboard)) {
    $phG = implode(',', array_fill(0, count($asesor_ids_dashboard), '?'));
    try {
        // Clientes totales / activos
        $st = $pdo->prepare("SELECT COUNT(*) as tot, SUM(CASE WHEN cp.estado!='descartado' THEN 1 ELSE 0 END) as act
                              FROM cliente_prospecto cp WHERE cp.asesor_id IN ($phG)");
        $st->execute($asesor_ids_dashboard);
        $rowC = $st->fetch();
        $total_clientes  = (int)($rowC['tot'] ?? 0);
        $clientes_activos = (int)($rowC['act'] ?? 0);

        // Tareas de hoy
        $st = $pdo->prepare("SELECT COUNT(*) as tot,
                                     SUM(CASE WHEN t.estado='completada' THEN 1 ELSE 0 END) as comp
                              FROM tarea t
                              WHERE t.asesor_id IN ($phG) AND t.fecha_programada=CURDATE()");
        $st->execute($asesor_ids_dashboard);
        $rowT = $st->fetch();
        $tareas_hoy        = (int)($rowT['tot']  ?? 0);
        $tareas_completadas = (int)($rowT['comp'] ?? 0);

        // Alertas sin ver
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE asesor_id IN ($phG) AND vista_supervisor=0");
        $st->execute($asesor_ids_dashboard);
        $alertas_pendientes = (int)$st->fetchColumn();

        // Fichas de crédito aprobadas del mes
        $mesI = date('Y-m-01'); $mesF = date('Y-m-t');
        $st = $pdo->prepare("SELECT COUNT(DISTINCT fp.id) as cnt,
                              COALESCE(SUM(CAST(fc.monto_credito AS DECIMAL(15,2))), 0) as monto
                              FROM ficha_producto fp
                              LEFT JOIN ficha_credito fc ON fc.ficha_id = fp.id
                              WHERE fp.producto_tipo = 'credito'
                                AND fp.estado_revision IN ('aprobada','aprobado')
                                AND fp.asesor_id IN ($phG)
                                AND DATE(COALESCE(fp.updated_at, fp.created_at)) BETWEEN ? AND ?");
        $st->execute(array_merge($asesor_ids_dashboard, [$mesI, $mesF]));
        $rowF = $st->fetch();
        $fichas_credito = (int)($rowF['cnt']  ?? 0);
        $monto_fichas   = (float)($rowF['monto'] ?? 0);

        // Procesos de crédito aprobados/desembolsados del mes
        $st = $pdo->prepare("SELECT COUNT(DISTINCT cp.id) as cnt,
                              COALESCE(SUM(cp.monto_aprobado), 0) as monto
                              FROM credito_proceso cp
                              WHERE cp.asesor_id IN ($phG)
                                AND cp.estado_credito IN ('aprobado','desembolsado')
                                AND DATE(COALESCE(cp.fecha_desembolso, cp.updated_at, cp.created_at)) BETWEEN ? AND ?");
        $st->execute(array_merge($asesor_ids_dashboard, [$mesI, $mesF]));
        $rowO = $st->fetch();
        $ops_aprobadas = (int)($rowO['cnt']  ?? 0);
        $monto_ops     = (float)($rowO['monto'] ?? 0);

        // Fallback: si no hay data mensual en credito_proceso, intentar con cualquier estado que tenga monto
        if ($ops_aprobadas === 0 && $fichas_credito === 0) {
            $st = $pdo->prepare("SELECT COUNT(DISTINCT cp.id) as cnt,
                                  COALESCE(SUM(cp.monto_aprobado), 0) as monto
                                  FROM credito_proceso cp
                                  WHERE cp.asesor_id IN ($phG)
                                    AND cp.monto_aprobado > 0
                                    AND DATE(COALESCE(cp.fecha_desembolso, cp.updated_at, cp.created_at)) BETWEEN ? AND ?");
            $st->execute(array_merge($asesor_ids_dashboard, [$mesI, $mesF]));
            $rowFb = $st->fetch();
            $ops_aprobadas = (int)($rowFb['cnt']  ?? 0);
            $monto_ops     = (float)($rowFb['monto'] ?? 0);
        }
    } catch (PDOException $e) { /* silencioso */ }
}

$total_ops_credito = $ops_aprobadas + $fichas_credito;
$monto_total       = $monto_ops + $monto_fichas;

// ── Últimos clientes registrados ─────────────────────────────
$ultimos_clientes = [];
if (!empty($asesor_ids_dashboard)) {
    try {
        $phG = implode(',', array_fill(0, count($asesor_ids_dashboard), '?'));
        $st = $pdo->prepare("
            SELECT cp.nombre, cp.cedula, cp.ciudad, cp.estado, cp.created_at,
                   u.nombre as asesor_nombre
            FROM cliente_prospecto cp
            JOIN asesor a ON a.id = cp.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE cp.asesor_id IN ($phG)
            ORDER BY cp.created_at DESC
            LIMIT 5
        ");
        $st->execute($asesor_ids_dashboard);
        $ultimos_clientes = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Últimas alertas ──────────────────────────────────────────
$ultimas_alertas = [];
if (!empty($asesor_ids_dashboard)) {
    try {
        $phG = implode(',', array_fill(0, count($asesor_ids_dashboard), '?'));
        $st = $pdo->prepare("SELECT am.id as id_alerta, am.campo_modificado, am.valor_nuevo, am.created_at, u.nombre as asesor_nombre
            FROM alerta_modificacion am
            JOIN asesor a ON a.id = am.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE am.asesor_id IN ($phG) AND am.vista_supervisor = 0
            ORDER BY am.created_at DESC LIMIT 5");
        $st->execute($asesor_ids_dashboard);
        $ultimas_alertas = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Solicitudes pendientes (para el aviso superior) ──────────
$solicitudes_pendientes = 0;
try {
    $solicitudes_pendientes = (int)$pdo->query("SELECT COUNT(*) FROM solicitud_registro WHERE estado = 'pendiente'")->fetchColumn();
} catch (Throwable $e) {
    $solicitudes_pendientes = 0;
}

$pct_tareas = $tareas_hoy > 0 ? round($tareas_completadas * 100 / $tareas_hoy) : 0;
$periodo_inicio = date('Y-m-01');
$periodo_fin    = date('Y-m-t');
$kpi_dash = [
    'actividad_pct' => $pct_tareas,
    'actividad_realizadas' => $tareas_completadas,
    'actividad_total' => $tareas_hoy,
    'penetracion_pct' => 0,
    'penetracion_clientes' => 0,
    'penetracion_visitas' => 0,
    'interes_pct' => 0,
    'interes_si' => 0,
    'interes_total' => 0,
    'prospeccion_pct' => 0,
    'prospeccion_avance' => 0,
    'prospeccion_meta' => 0,
    'levantamiento_pct' => 0,
    'levantamientos' => 0,
    'interesados' => 0,
    'frio_pct' => 0,
    'frio_visitas' => 0,
    'recuperacion_pct' => 0,
    'recuperaciones' => 0,
    'eficiencia_pct' => 0,
    'postventa_pct' => 0,
    'operaciones_total' => $total_ops_credito,
    'operaciones_monto' => $monto_total,
];

if (!empty($asesor_ids_dashboard)) {
    try {
        $phDash = implode(',', array_fill(0, count($asesor_ids_dashboard), '?'));
        $paramsPeriodo = array_merge($asesor_ids_dashboard, [$periodo_inicio, $periodo_fin]);

        $st = $pdo->prepare("SELECT COUNT(t.id) as visitas,
                   SUM(CASE WHEN (ec.p2_es_cliente = 1 OR cp.estado = 'cliente') THEN 1 ELSE 0 END) as clientes
            FROM tarea t
            LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE t.asesor_id IN ($phDash) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi_dash['penetracion_visitas'] = (int)($r['visitas'] ?? 0);
        $kpi_dash['penetracion_clientes'] = (int)($r['clientes'] ?? 0);
        $kpi_dash['penetracion_pct'] = $kpi_dash['penetracion_visitas'] > 0 ? round($kpi_dash['penetracion_clientes'] * 100 / $kpi_dash['penetracion_visitas'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(ec.id) as total,
                   SUM(CASE WHEN ec.interes_conocer_productos = 1 THEN 1 ELSE 0 END) as si
            FROM encuesta_comercial ec
            JOIN tarea t ON t.id = ec.tarea_id
            WHERE t.asesor_id IN ($phDash) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi_dash['interes_total'] = (int)($r['total'] ?? 0);
        $kpi_dash['interes_si'] = (int)($r['si'] ?? 0);
        $kpi_dash['interes_pct'] = $kpi_dash['interes_total'] > 0 ? round($kpi_dash['interes_si'] * 100 / $kpi_dash['interes_total'], 1) : 0;

        // meta_visitas_mes está en tabla asesor (no en meta_asesor_diaria)
        $st = $pdo->prepare("SELECT COALESCE(SUM(meta_visitas_mes), 0) FROM asesor WHERE id IN ($phDash)");
        $st->execute($asesor_ids_dashboard);
        $kpi_dash['prospeccion_meta'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id IN ($phDash) AND estado = 'completada' AND DATE(COALESCE(fecha_realizada,fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $kpi_dash['prospeccion_avance'] = (int)$st->fetchColumn();
        $kpi_dash['prospeccion_pct'] = $kpi_dash['prospeccion_meta'] > 0 ? round($kpi_dash['prospeccion_avance'] * 100 / $kpi_dash['prospeccion_meta'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id IN ($phDash) AND t.estado = 'completada' AND t.tipo_tarea = 'levantamiento' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?");
        $st->execute($paramsPeriodo);
        $kpi_dash['levantamientos'] = (int)$st->fetchColumn();
        $kpi_dash['interesados'] = $kpi_dash['interes_si'];
        $kpi_dash['levantamiento_pct'] = $kpi_dash['interesados'] > 0 ? round($kpi_dash['levantamientos'] * 100 / $kpi_dash['interesados'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id)
            FROM tarea t
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE t.asesor_id IN ($phDash) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?
              AND (t.tipo_tarea = 'visita_frio' OR cp.origen_prospecto = 'frio')");
        $st->execute($paramsPeriodo);
        $kpi_dash['frio_visitas'] = (int)$st->fetchColumn();
        $kpi_dash['frio_pct'] = $kpi_dash['prospeccion_avance'] > 0 ? round($kpi_dash['frio_visitas'] * 100 / $kpi_dash['prospeccion_avance'], 1) : 0;

        $st = $pdo->prepare("SELECT COUNT(DISTINCT t.id)
            FROM tarea t
            WHERE t.asesor_id IN ($phDash) AND t.estado = 'completada' AND DATE(COALESCE(t.fecha_realizada,t.fecha_programada)) BETWEEN ? AND ?
              AND (t.tipo_tarea = 'recuperacion' OR t.tipo_tarea LIKE '%recupera%')");
        $st->execute($paramsPeriodo);
        $kpi_dash['recuperaciones'] = (int)$st->fetchColumn();
        $kpi_dash['recuperacion_pct'] = $kpi_dash['prospeccion_avance'] > 0 ? round($kpi_dash['recuperaciones'] * 100 / $kpi_dash['prospeccion_avance'], 1) : 0;
        $kpi_dash['eficiencia_pct'] = $kpi_dash['penetracion_visitas'] > 0 ? round($kpi_dash['interes_si'] * 100 / $kpi_dash['penetracion_visitas'], 1) : 0;
        $kpi_dash['postventa_pct'] = $clientes_activos > 0 ? round(min($ops_aprobadas, $clientes_activos) * 100 / $clientes_activos, 1) : 0;
    } catch (Throwable $e) {
        error_log('Dashboard SuperAdmin KPI resumen: ' . $e->getMessage());
    }
}

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super_IA — Dashboard Super Administrador</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="js/cooperativa_buscador.js"></script>
<style>
:root{
  --y:#ffdd00; --yd:#f4c400;
  --n1:#0a2748; --n2:#123a6d; --n3:#1e4d8c;
  --bg:#f0f4f9;
  --card:#ffffff;
  --nborder:#e2e8f0;
  --tm:#1a2744; --td:#64748b;
  --shadow:0 2px 14px rgba(18,58,109,.07);
}
*{font-family:'Inter','Segoe UI',sans-serif;}

/* ── FILTRO DE BANCO ─────────────────────────────────────────── */
.dash-banco-bar{
  background:#fff;border:1px solid var(--nborder);border-radius:16px;
  padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow);
  display:flex;align-items:center;gap:14px;flex-wrap:wrap;
}
.dash-banco-bar label{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--n2);white-space:nowrap;}
.coop-buscador-wrap{position:relative;flex:1;min-width:260px;max-width:420px;}
.coop-buscador-clear{
    position:absolute;right:9px;top:50%;transform:translateY(-50%);
    border:none;background:transparent;color:#94a3b8;cursor:pointer;font-size:13px;padding:4px;display:none;
}
.coop-buscador-clear:hover{color:#ef4444;}
.coop-buscador-clear.show{display:block;}
.coop-buscador-list{
    display:none;position:absolute;top:100%;left:0;right:0;z-index:50;
    max-height:260px;overflow-y:auto;background:#fff;border:1.5px solid #E2E8F0;
    border-radius:10px;margin-top:6px;box-shadow:0 12px 28px rgba(18,58,109,.16);
}
.coop-buscador-item{padding:9px 14px;font-size:13.5px;color:#0D1929;cursor:pointer;border-bottom:1px solid #f1f5f9;}
.coop-buscador-item:last-child{border-bottom:none;}
.coop-buscador-item:hover{background:rgba(255,221,0,.16);}
.coop-buscador-empty{padding:10px 14px;font-size:12.5px;color:#94a3b8;font-style:italic;}
.dash-banco-tag{
  display:inline-flex;align-items:center;gap:6px;background:#fffbeb;color:#92400e;
  border:1px solid #fde68a;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;
}

/* ── HERO ────────────────────────────────────────────────── */
.hero{
  background:linear-gradient(125deg,#06101e 0%,#0a2748 55%,#123a6d 100%);
  border-radius:22px;
  padding:26px 32px;
  margin-bottom:22px;
  display:flex;align-items:center;justify-content:space-between;gap:24px;
  position:relative;overflow:hidden;
  border:1px solid rgba(255,221,0,.12);
}
.hero::before{
  content:'';position:absolute;right:-80px;top:-80px;
  width:280px;height:280px;
  background:radial-gradient(circle,rgba(255,221,0,.14) 0%,transparent 65%);
  pointer-events:none;
}
.hero::after{
  content:'';position:absolute;left:-50px;bottom:-50px;
  width:200px;height:200px;
  background:radial-gradient(circle,rgba(18,58,109,.9) 0%,transparent 65%);
  pointer-events:none;
}
.hero-left{position:relative;z-index:1;}
.hero-title{font-size:23px;font-weight:900;color:#fff;margin-bottom:3px;letter-spacing:-.3px;}
.hero-title span{color:var(--y);}
.hero-sub{font-size:12.5px;color:rgba(255,255,255,.55);font-weight:500;margin-bottom:14px;}
.hero-prog-label{font-size:11px;color:rgba(255,255,255,.6);display:flex;justify-content:space-between;margin-bottom:5px;}
.hero-prog-track{width:260px;height:7px;background:rgba(255,255,255,.12);border-radius:99px;overflow:hidden;}
.hero-prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--y),#fbbf24);transition:width 1.6s cubic-bezier(.4,0,.2,1);}
.hero-right{display:flex;gap:10px;flex-shrink:0;position:relative;z-index:1;}
.hs-pill{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:13px 18px;text-align:center;min-width:78px;transition:.2s;}
.hs-pill:hover{background:rgba(255,255,255,.12);}
.hs-num{font-size:24px;font-weight:900;color:var(--y);line-height:1;}
.hs-lbl{font-size:9.5px;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-top:3px;}
.hs-pill.danger{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.1);}
.hs-pill.danger .hs-num{color:#f87171;}

/* ── STAT CARDS ──────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.sc{
  background:#fff;border:1px solid var(--nborder);border-radius:16px;
  padding:16px 18px;position:relative;overflow:hidden;
  box-shadow:var(--shadow);transition:.2s;
}
.sc:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(18,58,109,.12);border-color:rgba(18,58,109,.2);}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--ac,var(--y));border-radius:3px 3px 0 0;}
.sc-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:10px;background:var(--aic,rgba(255,221,0,.12));color:var(--ac,var(--y));}
.sc-value{font-size:28px;font-weight:900;color:var(--n1);line-height:1;margin-bottom:3px;}
.sc-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--td);margin-bottom:4px;}
.sc-sub{font-size:11px;color:var(--td);}
.sc-badge{position:absolute;top:14px;right:14px;font-size:10px;font-weight:800;padding:3px 9px;border-radius:6px;}
.sb-green{background:rgba(74,222,128,.13);color:#22c55e;}
.sb-yellow{background:rgba(251,191,36,.13);color:#f59e0b;}
.sb-red{background:rgba(239,68,68,.13);color:#ef4444;}
.sb-blue{background:rgba(96,165,250,.13);color:#3b82f6;}

/* ── SECTION HEADER ──────────────────────────────────────── */
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;}
.sec-title{font-size:15px;font-weight:900;color:var(--n1);display:flex;align-items:center;gap:8px;}
.sec-title i{color:var(--y);}

/* ── KPI SECTION WRAPPER ────────────────────────────────── */
.kpi-section{
  background:linear-gradient(155deg,#060f1d 0%,#0b1f3a 45%,#0f2d55 100%);
  border-radius:22px;border:1px solid rgba(255,221,0,.14);
  padding:22px 20px 24px;margin-bottom:22px;position:relative;overflow:hidden;
}
.kpi-section::before{
  content:'';position:absolute;inset:0;
  background:repeating-linear-gradient(0deg,transparent,transparent 3px,rgba(255,255,255,.016) 3px,rgba(255,255,255,.016) 4px);
  pointer-events:none;animation:scanMove 10s linear infinite;
}
@keyframes scanMove{from{background-position:0 0;}to{background-position:0 100px;}}
.kpi-section::after{
  content:'';position:absolute;right:-60px;top:-60px;
  width:280px;height:280px;
  background:radial-gradient(circle,rgba(255,221,0,.09) 0%,transparent 65%);
  pointer-events:none;animation:orbPulse 4s ease-in-out infinite;
}
@keyframes orbPulse{0%,100%{opacity:.55;transform:scale(1);}50%{opacity:1;transform:scale(1.18);}}

.kpi-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;position:relative;z-index:1;flex-wrap:wrap;gap:10px;}
.kpi-title{font-size:17px;font-weight:900;color:#fff;display:flex;align-items:center;gap:10px;letter-spacing:-.2px;}
.kpi-title i{color:var(--y);font-size:19px;filter:drop-shadow(0 0 9px rgba(255,221,0,.65));animation:iconBounce 2.8s ease-in-out infinite;}
@keyframes iconBounce{0%,100%{transform:translateY(0) rotate(0deg);}40%{transform:translateY(-4px) rotate(-4deg);}70%{transform:translateY(-2px) rotate(3deg);}}
.kpi-title-sub{font-size:11.5px;color:rgba(255,255,255,.42);font-weight:600;margin-top:3px;letter-spacing:.1px;}
.kpi-actions{display:flex;align-items:center;gap:9px;}
.kpi-live{display:flex;align-items:center;gap:7px;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.32);padding:5px 14px;border-radius:99px;font-size:11.5px;font-weight:800;color:#4ade80;letter-spacing:.5px;}
.kpi-live-dot{width:8px;height:8px;border-radius:50%;background:#4ade80;box-shadow:0 0 8px #4ade8088;animation:livePulse 1.8s infinite;}
@keyframes livePulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(.7);}}

/* ── KPI GAUGE GRID ──────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;position:relative;z-index:1;}

@keyframes kpiEnter{
  from{opacity:0;transform:translateY(32px) scale(.91);}
  to{opacity:1;transform:translateY(0) scale(1);}
}
@keyframes critRing{
  0%,100%{box-shadow:0 4px 22px rgba(0,0,0,.28),0 0 0 0 rgba(239,68,68,.0);}
  50%{box-shadow:0 4px 22px rgba(0,0,0,.28),0 0 0 7px rgba(239,68,68,.2);}
}
@keyframes fillShimmer{
  0%{transform:translateX(-100%);}100%{transform:translateX(220%);}
}

.g-card{
  background:rgba(255,255,255,.97);border:1.5px solid rgba(255,255,255,.15);border-radius:20px;
  padding:17px 13px 14px;display:flex;flex-direction:column;align-items:center;
  position:relative;overflow:hidden;text-decoration:none;color:inherit;
  transition:transform .28s cubic-bezier(.34,1.56,.64,1),box-shadow .28s,border-color .28s;
  box-shadow:0 5px 22px rgba(0,0,0,.28);
  animation:kpiEnter .52s cubic-bezier(.34,1.56,.64,1) both;
}
.g-card::before{
  content:'';position:absolute;top:0;left:-120%;width:55%;height:100%;
  background:linear-gradient(105deg,transparent 35%,rgba(255,255,255,.5) 55%,transparent 75%);
  transition:left .6s ease;pointer-events:none;z-index:3;
}
.g-card::after{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--gc,var(--y));border-radius:3px 3px 0 0;
  transition:height .28s,box-shadow .28s;
}
.g-glow{position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(var(--gc-rgb,255,221,0),.07) 0%,transparent 55%);pointer-events:none;z-index:0;}

.g-card.g-crit{animation:kpiEnter .52s cubic-bezier(.34,1.56,.64,1) both,critRing 2.3s ease-in-out 1s infinite;}

.g-title{font-size:10.5px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;color:#64748b;display:flex;align-items:center;gap:5px;margin-bottom:3px;position:relative;z-index:1;}
.g-title i{font-size:12px;color:var(--gc,var(--y));filter:drop-shadow(0 0 5px rgba(var(--gc-rgb,255,221,0),.55));}
.g-chart{width:100%;max-width:175px;height:155px;min-height:155px;}
.g-pct-row{display:flex;align-items:center;gap:8px;margin-top:-9px;position:relative;z-index:1;}
.g-pct{font-size:25px;font-weight:900;color:var(--n1);line-height:1;}
.g-badge{font-size:9px;font-weight:800;padding:2px 7px;border-radius:5px;}
.gb-ok{background:rgba(74,222,128,.13);color:#22c55e;}
.gb-wa{background:rgba(251,191,36,.13);color:#f59e0b;}
.gb-er{background:rgba(239,68,68,.13);color:#ef4444;}
.g-meta{font-size:11px;color:#64748b;margin-top:5px;text-align:center;font-weight:600;position:relative;z-index:1;}
.g-track{width:78%;height:5px;background:rgba(0,0,0,.09);border-radius:99px;overflow:hidden;margin-top:9px;position:relative;z-index:1;}
.g-fill{height:100%;border-radius:99px;background:var(--gc,var(--y));transition:width 1.6s cubic-bezier(.4,0,.2,1);position:relative;overflow:hidden;}
.g-fill::after{
  content:'';position:absolute;inset:0;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,.6),transparent);
  animation:fillShimmer 2.8s ease-in-out infinite;
}

.g-ops{
  background:linear-gradient(135deg,#050f1c 0%,#0a2748 55%,#123a6d 100%);
  border-color:rgba(255,221,0,.28);border-width:1.5px;
}
.ops-big{
  font-size:42px;font-weight:900;color:var(--y);line-height:1;margin:14px 0 5px;
  filter:drop-shadow(0 0 14px rgba(255,221,0,.45));
  animation:opsGlow 2.5s ease-in-out infinite;
}
@keyframes opsGlow{0%,100%{filter:drop-shadow(0 0 10px rgba(255,221,0,.4));}50%{filter:drop-shadow(0 0 20px rgba(255,221,0,.75));}}
.ops-sub{font-size:15px;font-weight:700;color:rgba(255,255,255,.82);}
.ops-tag{font-size:9px;text-transform:uppercase;color:rgba(255,255,255,.45);font-weight:700;letter-spacing:.6px;margin-top:4px;}

/* ── FUNNEL ──────────────────────────────────────────────── */
.funnel-wrap{
  background:#fff;border:1px solid var(--nborder);border-radius:18px;
  padding:20px 24px;margin-bottom:22px;box-shadow:var(--shadow);
  position:relative;overflow:hidden;
}
.funnel-wrap::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse at 50% -20%,rgba(255,221,0,.04) 0%,transparent 60%);
  pointer-events:none;
}
.funnel-steps{display:flex;align-items:center;justify-content:space-between;margin-top:18px;position:relative;z-index:1;}
.f-step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;max-width:90px;}
.f-ico{
  width:52px;height:52px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;font-size:19px;
  position:relative;transition:.2s;
}
.f-ico:hover{transform:scale(1.1);}
.f-pct-badge{
  position:absolute;top:-7px;right:-7px;
  font-size:8.5px;font-weight:900;padding:1px 5px;border-radius:5px;
  background:var(--fc,var(--y));color:var(--n1);white-space:nowrap;
}
.f-lbl{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;color:#64748b;text-align:center;line-height:1.3;}
.f-val{font-size:13px;font-weight:900;color:var(--n1);}
.f-arrow{flex:1;display:flex;align-items:center;justify-content:center;margin-bottom:20px;color:rgba(255,221,0,.35);font-size:14px;}

/* ── BOTTOM GRID ─────────────────────────────────────────── */
.btm-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.b-card{background:#fff;border:1px solid var(--nborder);border-radius:16px;overflow:hidden;box-shadow:var(--shadow);transition:.2s;}
.b-card:hover{border-color:rgba(18,58,109,.25);box-shadow:0 6px 22px rgba(18,58,109,.1);}
.bh{padding:13px 16px;border-bottom:1px solid var(--nborder);display:flex;align-items:center;gap:9px;background:rgba(248,250,252,.8);}
.bh-ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.bh h5{font-size:13px;font-weight:900;color:var(--n1);margin:0;flex:1;}
.bh-num{font-size:10px;font-weight:800;padding:2px 8px;border-radius:6px;border:1px solid;white-space:nowrap;}
.num-navy{color:var(--n2);background:rgba(18,58,109,.07);border-color:rgba(18,58,109,.15);}
.num-red{color:#ef4444;background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.2);}
.bh-link{font-size:11px;font-weight:700;color:var(--y);text-decoration:none;opacity:.8;}
.bh-link:hover{opacity:1;}

.cli-row{display:flex;align-items:center;gap:10px;padding:10px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .14s;}
.cli-row:hover{background:rgba(18,58,109,.03);}
.cli-row:last-child{border-bottom:none;}
.cli-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--n1),var(--n2));color:var(--y);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0;}
.cli-name{font-size:12.5px;font-weight:700;color:var(--n1);}
.cli-sub{font-size:10.5px;color:var(--td);}
.cli-tag{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:5px;margin-left:auto;flex-shrink:0;white-space:nowrap;}
.tag-c{background:rgba(34,197,94,.12);color:#16a34a;}
.tag-p{background:rgba(59,130,246,.1);color:#2563eb;}
.tag-d{background:rgba(239,68,68,.1);color:#dc2626;}

.qk-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px;}
.qk{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:11px 6px;border-radius:13px;font-weight:800;font-size:11px;text-align:center;min-height:66px;text-decoration:none;transition:.18s;border:1px solid transparent;}
.qk i{font-size:17px;}
.qk:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.25);}
.qk-y{background:linear-gradient(135deg,var(--y),var(--yd));color:var(--n1);}
.qk-n{background:linear-gradient(135deg,#0a2748,#123a6d);color:#fff;border-color:#1e4d8c;}
.qk-g{background:linear-gradient(135deg,#064e3b,#059669);color:#fff;}
.qk-b{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;}
.qk-r{background:linear-gradient(135deg,#7f1d1d,#dc2626);color:#fff;}
.qk-p{background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;}
.qk-t{background:linear-gradient(135deg,#134e4a,#0d9488);color:#fff;}
.qk-o{background:linear-gradient(135deg,#7c2d12,#ea580c);color:#fff;}

.al-row{display:flex;align-items:flex-start;gap:10px;padding:10px 15px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .14s;}
.al-row:hover{background:rgba(239,68,68,.03);}
.al-row:last-child{border-bottom:none;}
.al-ic{width:34px;height:34px;border-radius:10px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.18);color:#ef4444;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;margin-top:1px;}
.al-campo{font-size:12.5px;font-weight:800;color:var(--n1);}
.al-asesor{font-size:10.5px;color:var(--td);margin-top:1px;}
.al-t{font-size:9.5px;color:var(--td);margin-left:auto;flex-shrink:0;white-space:nowrap;padding-top:2px;}

.empty{padding:28px;text-align:center;color:var(--td);font-size:12.5px;}
.empty i{display:block;font-size:22px;margin-bottom:7px;opacity:.4;}

.alert-warning-dash{background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:12px 16px;border-radius:12px;margin-bottom:18px;font-size:13.5px;}

@media(max-width:1350px){.kpi-grid{grid-template-columns:repeat(auto-fill,minmax(180px,1fr));}}
@media(max-width:1100px){.stats-row,.btm-grid{grid-template-columns:1fr 1fr;}.funnel-steps .f-arrow{display:none;}.funnel-steps{flex-wrap:wrap;gap:12px;justify-content:center;}}
@media(max-width:800px){.kpi-grid{grid-template-columns:repeat(2,1fr);}.btm-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php
$currentPage = 'dashboard';
require_once '_sidebar_super_admin.php';
?>
<!-- A diferencia de _sidebar_gerente.php / _sidebar_supervisor.php,
     _sidebar_super_admin.php solo pinta el <div class="sidebar">, así
     que este archivo abre su propio .main-content/.navbar-custom/
     .content-area (mismo patrón ya usado en usuarios.php, mapa_vivo.php
     y metas.php). -->
<div class="main-content">
    <div class="navbar-custom">
        <div class="nav-title-group">
            <h2 style="margin:0;font-size:20px;font-weight:700;"><i class="fas fa-crown me-2" style="color:var(--y);"></i>Panel de Super Administrador</h2>
        </div>
        <div class="user-info">
            <div style="text-align:right;">
                <strong><?= htmlspecialchars($super_admin_nombre) ?></strong><br>
                <small style="opacity:.75;"><?= htmlspecialchars($super_admin_rol) ?></small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i> Cerrar Sesión</a>
        </div>
    </div>
    <div class="content-area">

        <?php if ($solicitudes_pendientes > 0): ?>
        <div class="alert-warning-dash">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Atención:</strong> Tienes <strong><?= $solicitudes_pendientes ?></strong>
            solicitud(es) de registro pendiente(s) de revisar, de cualquier banco/cooperativa.
            <a href="administrar_solicitudes_global.php" style="margin-left:8px;font-weight:700;text-decoration:underline;color:#92400e;">Revisar ahora →</a>
        </div>
        <?php endif; ?>

        <!-- ── FILTRO POR BANCO/COOPERATIVA (búsqueda por escritura) ── -->
        <div class="dash-banco-bar">
            <label><i class="fas fa-university me-1"></i>Banco/Cooperativa:</label>
            <form method="get" id="formBancoDash" style="flex:1;min-width:260px;max-width:420px;">
                <div class="coop-buscador-wrap">
                    <input type="text" id="banco-dash-buscar" class="form-control" placeholder="Escribe para buscar…" autocomplete="off" value="<?= htmlspecialchars($nombre_banco_filtro) ?>" style="border-radius:9px;padding:9px 30px 9px 12px;">
                    <input type="hidden" name="banco_filtro" id="banco-dash-hidden" value="<?= htmlspecialchars($banco_filtro) ?>">
                    <button type="button" class="coop-buscador-clear <?= $banco_filtro !== '' ? 'show' : '' ?>" id="banco-dash-clear" title="Quitar filtro">
                        <i class="fas fa-times-circle"></i>
                    </button>
                    <div id="banco-dash-lista" class="coop-buscador-list"></div>
                </div>
            </form>
            <?php if ($banco_filtro !== ''): ?>
                <span class="dash-banco-tag"><i class="fas fa-filter"></i> Viendo solo: <?= htmlspecialchars($nombre_banco_filtro) ?></span>
            <?php else: ?>
                <span class="dash-banco-tag" style="background:#eff6ff;color:#1e40af;border-color:#bfdbfe;"><i class="fas fa-globe"></i> Viendo todos los bancos/cooperativas</span>
            <?php endif; ?>
        </div>

        <!-- ── HERO ─────────────────────────────────────────────────── -->
        <div class="hero">
          <div class="hero-left">
            <div class="hero-title">Panel de Super Administrador — <span><?= $banco_filtro !== '' ? htmlspecialchars($nombre_banco_filtro) : 'Todos los bancos' ?></span></div>
            <div class="hero-sub">Monitoreo en tiempo real · <?= strtoupper(date('M Y')) ?></div>
            <?php if ($tareas_hoy > 0): ?>
            <div class="hero-prog-label">
              <span>Progreso de tareas hoy</span>
              <span><?= $tareas_completadas ?>/<?= $tareas_hoy ?></span>
            </div>
            <div class="hero-prog-track">
              <div class="hero-prog-fill" id="hp-fill" style="width:0%;"></div>
            </div>
            <?php endif; ?>
          </div>
          <div class="hero-right">
            <div class="hs-pill">
              <div class="hs-num"><?= $total_asesores ?></div>
              <div class="hs-lbl">Asesores</div>
            </div>
            <div class="hs-pill">
              <div class="hs-num" id="cnt-clientes">0</div>
              <div class="hs-lbl">Clientes</div>
            </div>
            <div class="hs-pill">
              <div class="hs-num" id="cnt-activos">0</div>
              <div class="hs-lbl">Activos</div>
            </div>
            <?php if ($alertas_pendientes > 0): ?>
            <div class="hs-pill danger">
              <div class="hs-num"><?= $alertas_pendientes ?></div>
              <div class="hs-lbl">Alertas</div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── STAT CARDS ────────────────────────────────────────────── -->
        <div class="stats-row">
          <div class="sc" style="--ac:#f59e0b;--aic:rgba(245,158,11,.1);">
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
            <div class="sc-badge sb-blue"><?= $kpi_dash['operaciones_total'] ?></div>
            <div class="sc-icon"><i class="fas fa-hand-holding-dollar"></i></div>
            <div class="sc-label">Créditos <?= date('M Y') ?></div>
            <div class="sc-value" style="font-size:20px;">$<?= number_format($kpi_dash['operaciones_monto'],0,'.',',') ?></div>
            <div class="sc-sub"><?= $kpi_dash['operaciones_total'] ?> crédito<?= $kpi_dash['operaciones_total']!=1?'s':'' ?> desembolsado<?= $kpi_dash['operaciones_total']!=1?'s':'' ?></div>
          </div>
          <div class="sc" style="--ac:#ef4444;--aic:rgba(239,68,68,.1);">
            <div class="sc-badge sb-red"><?= $alertas_pendientes ?></div>
            <div class="sc-icon"><i class="fas fa-bell"></i></div>
            <div class="sc-label">Alertas Sin Ver</div>
            <div class="sc-value" style="color:#ef4444;" id="cnt-alerta">0</div>
            <div class="sc-sub">Pendientes de revisión</div>
          </div>
        </div>

        <!-- ── KPI GAUGES ─────────────────────────────────────────────── -->
        <div class="kpi-section">
        <div class="kpi-hd">
          <div>
            <div class="kpi-title"><i class="fas fa-gauge-high"></i> KPIs Globales — Tacómetros en Vivo</div>
            <div class="kpi-title-sub">Rendimiento de <?= $banco_filtro !== '' ? htmlspecialchars($nombre_banco_filtro) : 'todo el sistema' ?> &middot; <?= strtoupper(date('M Y')) ?></div>
          </div>
          <div class="kpi-actions">
            <div class="kpi-live"><div class="kpi-live-dot"></div>EN VIVO</div>
          </div>
        </div>
        <div class="kpi-grid">
        <?php
        $gkpis=[
          ['k'=>'actividad',    'lbl'=>'Actividad',      'ico'=>'fa-bolt',            'c'=>'#60a5fa','cr'=>'96,165,250',   'v'=>$kpi_dash['actividad_pct'],     'meta'=>$kpi_dash['actividad_realizadas'].'/'.$kpi_dash['actividad_total'].' hoy'],
          ['k'=>'penetracion',  'lbl'=>'Penetración',    'ico'=>'fa-chart-pie',       'c'=>'#4ade80','cr'=>'74,222,128',   'v'=>$kpi_dash['penetracion_pct'],    'meta'=>$kpi_dash['penetracion_clientes'].'/'.$kpi_dash['penetracion_visitas'].' visitas'],
          ['k'=>'interes',      'lbl'=>'Interés',        'ico'=>'fa-heart-pulse',     'c'=>'#fbbf24','cr'=>'251,191,36',   'v'=>$kpi_dash['interes_pct'],        'meta'=>$kpi_dash['interes_si'].'/'.$kpi_dash['interes_total'].' encuestas'],
          ['k'=>'prospeccion',  'lbl'=>'Prospección',    'ico'=>'fa-route',           'c'=>'#a78bfa','cr'=>'167,139,250',  'v'=>$kpi_dash['prospeccion_pct'],    'meta'=>$kpi_dash['prospeccion_avance'].'/'.$kpi_dash['prospeccion_meta'].' meta'],
          ['k'=>'levantam',     'lbl'=>'Levantamientos', 'ico'=>'fa-clipboard-check', 'c'=>'#38bdf8','cr'=>'56,189,248',   'v'=>$kpi_dash['levantamiento_pct'],  'meta'=>$kpi_dash['levantamientos'].'/'.$kpi_dash['interesados'].' interesados'],
          ['k'=>'frio',         'lbl'=>'Visitas Frío',   'ico'=>'fa-snowflake',       'c'=>'#fb923c','cr'=>'251,146,60',   'v'=>$kpi_dash['frio_pct'],           'meta'=>$kpi_dash['frio_visitas'].' visitas frías'],
          ['k'=>'eficiencia',   'lbl'=>'Eficiencia',     'ico'=>'fa-bolt-lightning',  'c'=>'#f472b6','cr'=>'244,114,182',  'v'=>$kpi_dash['eficiencia_pct'],     'meta'=>$kpi_dash['interes_si'].' con interés'],
          ['k'=>'postventa',    'lbl'=>'Post-Venta',     'ico'=>'fa-rotate',          'c'=>'#2dd4bf','cr'=>'45,212,191',   'v'=>$kpi_dash['postventa_pct'],      'meta'=>$ops_aprobadas.' aprobados'],
          ['k'=>'recuperacion', 'lbl'=>'Recuperación',   'ico'=>'fa-shield-halved',   'c'=>'#ef4444','cr'=>'239,68,68',    'v'=>$kpi_dash['recuperacion_pct'],   'meta'=>$kpi_dash['recuperaciones'].' gestiones'],
        ];
        foreach($gkpis as $g):
          $v=round((float)$g['v'],1);
          $bc=$v>=70?'gb-ok':($v>=35?'gb-wa':'gb-er');
          $bt=$v>=70?'OK':($v>=35?'Bajo':'Crítico');
        ?>
        <div class="g-card<?=$bc==='gb-er'?' g-crit':''?>" style="--gc:<?=$g['c']?>;--gc-rgb:<?=$g['cr']?>;">
          <div class="g-glow"></div>
          <div class="g-title"><i class="fas <?=$g['ico']?>"></i><?=htmlspecialchars($g['lbl'])?></div>
          <div class="g-chart" id="gc-<?=htmlspecialchars($g['k'])?>"></div>
          <div class="g-pct-row">
            <span class="g-pct"><?=$v?>%</span>
            <span class="g-badge <?=$bc?>"><?=$bt?></span>
          </div>
          <div class="g-meta"><?=htmlspecialchars($g['meta'])?></div>
          <div class="g-track"><div class="g-fill" style="width:<?=min(100,$v)?>%;background:<?=$g['c']?>;"></div></div>
        </div>
        <?php endforeach; ?>

        <!-- CRÉDITOS ESPECIAL -->
        <div class="g-card g-ops" style="--gc:#ffdd00;--gc-rgb:255,221,0;justify-content:center;">
          <div class="g-glow"></div>
          <div class="g-title" style="color:rgba(255,255,255,.6);"><i class="fas fa-hand-holding-dollar" style="color:var(--y);"></i>Créditos <?=date('M Y')?></div>
          <div class="ops-big" id="cnt-ops-big">0</div>
          <div class="ops-sub">$<?=number_format($kpi_dash['operaciones_monto'],0,'.',',')?></div>
          <div class="ops-tag">Monto prestado este mes</div>
          <div class="g-track" style="background:rgba(255,255,255,.1);width:80%;margin-top:12px;">
            <div class="g-fill" style="width:<?=min(100,$kpi_dash['operaciones_total']*10)?>%;background:var(--y);"></div>
          </div>
        </div>
        </div><!-- /kpi-grid -->
        </div><!-- /kpi-section -->

        <!-- ── FUNNEL ─────────────────────────────────────────────────── -->
        <div class="funnel-wrap">
          <div class="sec-hd" style="margin-bottom:0;">
            <div class="sec-title"><i class="fas fa-diagram-project"></i> Flujo de Conversión Global</div>
            <span style="font-size:12px;color:var(--td);font-weight:700;"><?= strtoupper(date('M Y')) ?></span>
          </div>
          <div class="funnel-steps">
            <?php
            $fsteps=[
              ['lbl'=>'Prospección','ico'=>'fas fa-route',           'c'=>'#a78bfa','v'=>$kpi_dash['prospeccion_pct'],   'n'=>$kpi_dash['prospeccion_avance']],
              ['lbl'=>'Visitas',    'ico'=>'fas fa-map-marker-alt',  'c'=>'#60a5fa','v'=>$kpi_dash['penetracion_pct'],   'n'=>$kpi_dash['penetracion_visitas']],
              ['lbl'=>'Interés',    'ico'=>'fas fa-heart-pulse',     'c'=>'#fbbf24','v'=>$kpi_dash['interes_pct'],       'n'=>$kpi_dash['interes_si']],
              ['lbl'=>'Levantam.',  'ico'=>'fas fa-clipboard-check', 'c'=>'#38bdf8','v'=>$kpi_dash['levantamiento_pct'], 'n'=>$kpi_dash['levantamientos']],
              ['lbl'=>'Eficiencia', 'ico'=>'fas fa-bolt',            'c'=>'#f472b6','v'=>$kpi_dash['eficiencia_pct'],    'n'=>$kpi_dash['interes_si']],
              ['lbl'=>'Créditos',   'ico'=>'fas fa-handshake',       'c'=>'#ffdd00','v'=>min(100,$kpi_dash['operaciones_total']*10),'n'=>$kpi_dash['operaciones_total']],
            ];
            foreach($fsteps as $i=>$fs): ?>
            <div class="f-step">
              <div class="f-ico" style="color:<?=$fs['c']?>;background:<?=$fs['c']?>18;border:1px solid <?=$fs['c']?>44;">
                <i class="<?=$fs['ico']?>"></i>
                <span class="f-pct-badge" style="background:<?=$fs['c']?>;color:<?=$i===5?'var(--n1)':'#fff'?>;"><?=(int)$fs['v']?>%</span>
              </div>
              <div class="f-lbl"><?=htmlspecialchars($fs['lbl'])?></div>
              <div class="f-val" style="color:<?=$fs['c']?>"><?=$fs['n']?></div>
            </div>
            <?php if($i<count($fsteps)-1): ?>
            <div class="f-arrow"><i class="fas fa-chevron-right"></i></div>
            <?php endif; endforeach; ?>
          </div>
        </div>

        <!-- ── BOTTOM GRID ───────────────────────────────────────────── -->
        <div class="btm-grid">

          <!-- Últimos Clientes -->
          <div class="b-card">
            <div class="bh">
              <div class="bh-ic" style="background:rgba(18,58,109,.1);color:var(--n2);"><i class="fas fa-users"></i></div>
              <h5>Últimos Clientes</h5>
              <a href="clientes.php" class="bh-link">Ver todos →</a>
            </div>
            <?php if(empty($ultimos_clientes)): ?>
              <div class="empty"><i class="fas fa-user-slash"></i>Sin registros</div>
            <?php else: foreach($ultimos_clientes as $cl):
              $in=mb_strtoupper(mb_substr(trim($cl['nombre']??'C'),0,1));
              $est=$cl['estado']??'prospecto';
              $tc=$est==='cliente'?'tag-c':($est==='descartado'?'tag-d':'tag-p');
            ?>
            <a href="ver_cliente.php?id=<?=urlencode($cl['cedula']??'')?>" class="cli-row">
              <div class="cli-av"><?=htmlspecialchars($in)?></div>
              <div style="flex:1;min-width:0;">
                <div class="cli-name"><?=htmlspecialchars($cl['nombre']??'—')?></div>
                <div class="cli-sub"><?=htmlspecialchars($cl['ciudad']??'')?> · <?=htmlspecialchars($cl['asesor_nombre']??'')?></div>
              </div>
              <span class="cli-tag <?=$tc?>"><?=ucfirst($est)?></span>
            </a>
            <?php endforeach; endif; ?>
          </div>

          <!-- Acceso Rápido -->
          <div class="b-card">
            <div class="bh">
              <div class="bh-ic" style="background:rgba(255,221,0,.12);color:var(--y);"><i class="fas fa-bolt"></i></div>
              <h5>Acceso Rápido</h5>
            </div>
            <div class="qk-grid">
              <a href="crear_asesor_admin.php"  class="qk qk-y"><i class="fas fa-user-plus"></i>Crear Cuenta</a>
              <a href="clientes.php"            class="qk qk-n"><i class="fas fa-address-book"></i>Clientes</a>
              <a href="operaciones.php"         class="qk qk-g"><i class="fas fa-handshake"></i>Operaciones</a>
              <a href="mapa_vivo.php"           class="qk qk-b"><i class="fas fa-map-marked-alt"></i>Mapa Vivo</a>
              <a href="alertas.php"             class="qk qk-r"><i class="fas fa-bell"></i>Alertas<?=$alertas_pendientes>0?" ($alertas_pendientes)":''?></a>
              <a href="usuarios.php"            class="qk qk-p"><i class="fas fa-users-cog"></i>Usuarios</a>
              <a href="administrar_solicitudes_global.php" class="qk qk-t"><i class="fas fa-file-signature"></i>Solicitudes</a>
              <a href="metas.php"               class="qk qk-o"><i class="fas fa-bullseye"></i>Metas</a>
              <a href="tareas_descartadas.php"  class="qk qk-r"><i class="fas fa-ban"></i>Tareas Descartadas</a>
            </div>
          </div>

          <!-- Últimas Alertas -->
          <div class="b-card">
            <div class="bh">
              <div class="bh-ic" style="background:rgba(239,68,68,.1);color:#ef4444;"><i class="fas fa-bell"></i></div>
              <h5>Últimas Alertas</h5>
              <?php if($alertas_pendientes>0): ?>
                <span class="bh-num num-red"><?= $alertas_pendientes ?> pendiente<?= $alertas_pendientes!=1?'s':'' ?></span>
              <?php endif; ?>
              <a href="alertas.php" class="bh-link ms-auto">Ver todas →</a>
            </div>
            <?php if(empty($ultimas_alertas)): ?>
              <div class="empty"><i class="fas fa-check-circle" style="color:#22c55e;opacity:.6;"></i>Sin alertas pendientes</div>
            <?php else: foreach($ultimas_alertas as $al):
              $campo=ucfirst(str_replace('_',' ',$al['campo_modificado']??'modificación'));
              $diff=time()-strtotime($al['created_at']);
              $t=$diff<60?'hace '.$diff.'s':($diff<3600?'hace '.floor($diff/60).'min':($diff<86400?'hace '.floor($diff/3600).'h':date('d/m',strtotime($al['created_at']))));
            ?>
            <a href="alertas_detalle.php?id=<?= $al['id_alerta'] ?>" class="al-row">
              <div class="al-ic"><i class="fas fa-triangle-exclamation"></i></div>
              <div style="flex:1;min-width:0;">
                <div class="al-campo"><?=htmlspecialchars($campo)?></div>
                <div class="al-asesor"><i class="fas fa-user-circle" style="font-size:10px;margin-right:3px;"></i><?=htmlspecialchars($al['asesor_nombre']??'Asesor')?></div>
              </div>
              <div class="al-t"><?= $t ?></div>
            </a>
            <?php endforeach; endif; ?>
          </div>

        </div><!-- /btm-grid -->

    </div><!-- /.content-area -->
</div><!-- /.main-content -->

<script>
// ── Combobox de búsqueda por escritura para el filtro de banco ──
const BANCOS_DASH = <?= json_encode(array_map(fn($b) => ['id' => (string)$b['id'], 'nombre' => $b['nombre']], $bancos_dash), JSON_UNESCAPED_UNICODE) ?>;

const bancoDashBuscarInput = document.getElementById('banco-dash-buscar');
const bancoDashHidden      = document.getElementById('banco-dash-hidden');
const bancoDashClearBtn    = document.getElementById('banco-dash-clear');

if (bancoDashBuscarInput && typeof initCooperativaBuscador === 'function') {
    initCooperativaBuscador({
        inputId:  'banco-dash-buscar',
        hiddenId: 'banco-dash-hidden',
        listId:   'banco-dash-lista',
        data: BANCOS_DASH,
        onSelect: function () {
            bancoDashClearBtn.classList.add('show');
            document.getElementById('formBancoDash').submit();
        }
    });
    bancoDashClearBtn.addEventListener('click', function () {
        window.location.href = 'super_admin_index.php';
    });
    bancoDashBuscarInput.addEventListener('input', function () {
        bancoDashClearBtn.classList.toggle('show', !!bancoDashHidden.value);
    });
}

document.addEventListener('DOMContentLoaded',function(){

  /* COUNTERS */
  function counter(el,target,dur,pre,suf){
    if(!el)return;var s=0,step=target/(dur/16);
    var t=setInterval(function(){s+=step;if(s>=target){s=target;clearInterval(t);}el.textContent=pre+Math.round(s).toLocaleString()+suf;},16);
  }
  setTimeout(function(){
    counter(document.getElementById('cnt-clientes'),<?=$total_clientes?>,1200,'','');
    counter(document.getElementById('cnt-activos'), <?=$clientes_activos?>,1200,'','');
    counter(document.getElementById('cnt-act'),     <?=$pct_tareas?>,900,'','%');
    counter(document.getElementById('cnt-pen'),     <?=$kpi_dash['penetracion_pct']?>,900,'','%');
    counter(document.getElementById('cnt-ops-big'), <?=$kpi_dash['operaciones_total']?>,1000,'','');
    counter(document.getElementById('cnt-alerta'),  <?=$alertas_pendientes?>,700,'','');
    var hf=document.getElementById('hp-fill');
    if(hf)setTimeout(function(){hf.style.width='<?=$pct_tareas?>%';},200);
    document.querySelectorAll('.g-fill').forEach(function(el){
      var w=el.style.width;el.style.width='0';
      setTimeout(function(){el.style.width=w;},350);
    });
  },400);

  /* APEX GAUGES */
  function makeGauge(id,val,color){
    var el=document.getElementById(id);if(!el)return;
    var fill=val>=70?color:(val>=35?'#fbbf24':'#f87171');
    var trackColor=val>=70?'rgba(74,222,128,.12)':(val>=35?'rgba(251,191,36,.12)':'rgba(239,68,68,.12)');
    new ApexCharts(el,{
      series:[Math.min(100,Math.max(0,val))],
      chart:{type:'radialBar',height:155,width:'100%',toolbar:{show:false},
        background:'transparent',
        animations:{enabled:true,easing:'easeout',speed:1400,
          animateGradually:{enabled:true,delay:120},
          dynamicAnimation:{enabled:true,speed:700}}},
      plotOptions:{radialBar:{
        startAngle:-135,endAngle:135,
        track:{background:trackColor,strokeWidth:'72%',margin:4,
          dropShadow:{enabled:false}},
        dataLabels:{show:true,name:{show:false},value:{
          offsetY:8,fontSize:'18px',fontWeight:'900',fontFamily:'Inter,sans-serif',color:'#1a2744',
          formatter:function(v){return Math.round(v)+'%';}}},
        hollow:{margin:5,size:'50%',background:'transparent',
          dropShadow:{enabled:true,top:2,left:0,blur:6,opacity:.08}}}},
      fill:{type:'gradient',gradient:{
        shade:'dark',type:'diagonal1',shadeIntensity:.22,
        gradientToColors:[fill],inverseColors:false,opacityFrom:1,opacityTo:.85,stops:[0,100]}},
      colors:[color],
      stroke:{lineCap:'round',width:3},
      tooltip:{enabled:false},
      grid:{padding:{top:-12,bottom:-12,left:-10,right:-10}},
      states:{hover:{filter:{type:'none'}},active:{filter:{type:'none'}}}
    }).render();
  }

  var gd=<?php $ga=[];foreach($gkpis as $g)$ga[]=['k'=>$g['k'],'v'=>(float)$g['v'],'c'=>$g['c']];echo json_encode($ga);?>;

  document.querySelectorAll('.kpi-grid .g-card').forEach(function(c,i){
    c.style.animationDelay=(i*0.065)+'s';
  });

  gd.forEach(function(g,i){
    setTimeout(function(){makeGauge('gc-'+g.k,g.v,g.c);},i*70);
  });

});
</script>
</body>
</html>

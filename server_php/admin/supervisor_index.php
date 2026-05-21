<?php
// ============================================================
// admin/supervisor_index.php — Dashboard Super_IA Logan
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php';   // PDO ($pdo)

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_usuario_id = $_SESSION['supervisor_id'];     // usuario.id
$supervisor_nombre     = $_SESSION['supervisor_nombre'];
$supervisor_rol        = $_SESSION['supervisor_rol'] ?? 'Supervisor';

// ── Resolver supervisor.id real ──────────────────────────────
$supervisor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$supervisor_usuario_id]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

// ── KPIs principales ─────────────────────────────────────────
$total_asesores       = 0;
$total_clientes       = 0;
$clientes_activos     = 0;
$tareas_hoy           = 0;
$tareas_completadas   = 0;
$alertas_pendientes   = 0;
$fichas_credito       = 0;
$monto_fichas         = 0.0;
$ops_aprobadas        = 0;
$monto_ops            = 0.0;

if ($supervisor_table_id) {
    try {
        // Asesores activos
        $st = $pdo->prepare('SELECT COUNT(*) FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE a.supervisor_id=? AND u.activo=1');
        $st->execute([$supervisor_table_id]);
        $total_asesores = (int)$st->fetchColumn();

        // Clientes totales / activos
        $st = $pdo->prepare('SELECT COUNT(*) as tot, SUM(CASE WHEN cp.estado!="descartado" THEN 1 ELSE 0 END) as act
                              FROM cliente_prospecto cp JOIN asesor a ON a.id=cp.asesor_id WHERE a.supervisor_id=?');
        $st->execute([$supervisor_table_id]);
        $rowC = $st->fetch();
        $total_clientes  = (int)($rowC['tot'] ?? 0);
        $clientes_activos = (int)($rowC['act'] ?? 0);

        // Tareas de hoy
        $st = $pdo->prepare('SELECT COUNT(*) as tot,
                                     SUM(CASE WHEN t.estado="completada" THEN 1 ELSE 0 END) as comp
                              FROM tarea t JOIN asesor a ON a.id=t.asesor_id
                              WHERE a.supervisor_id=? AND t.fecha_programada=CURDATE()');
        $st->execute([$supervisor_table_id]);
        $rowT = $st->fetch();
        $tareas_hoy        = (int)($rowT['tot']  ?? 0);
        $tareas_completadas = (int)($rowT['comp'] ?? 0);

        // Alertas sin ver
        $st = $pdo->prepare('SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id=? AND vista_supervisor=0');
        $st->execute([$supervisor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();

        // Fichas de crédito aprobadas del mes
        // Usa updated_at como fallback para capturar fichas aprobadas/actualizadas este mes
        $mesI = date('Y-m-01'); $mesF = date('Y-m-t');
        $st = $pdo->prepare('SELECT COUNT(DISTINCT fp.id) as cnt,
                              COALESCE(SUM(CAST(fc.monto_credito AS DECIMAL(15,2))), 0) as monto
                              FROM ficha_producto fp
                              LEFT JOIN ficha_credito fc ON fc.ficha_id = fp.id
                              JOIN asesor a ON a.id = fp.asesor_id
                              WHERE fp.producto_tipo = \'credito\'
                                AND fp.estado_revision IN (\'aprobada\',\'aprobado\')
                                AND a.supervisor_id = ?
                                AND DATE(COALESCE(fp.updated_at, fp.created_at)) BETWEEN ? AND ?');
        $st->execute([$supervisor_table_id, $mesI, $mesF]);
        $rowF = $st->fetch();
        $fichas_credito = (int)($rowF['cnt']  ?? 0);
        $monto_fichas   = (float)($rowF['monto'] ?? 0);

        // Procesos de crédito aprobados/desembolsados del mes
        // Prioriza fecha_desembolso, luego updated_at, luego created_at
        // Así se capturan créditos creados antes pero desembolsados este mes
        $st = $pdo->prepare('SELECT COUNT(DISTINCT cp.id) as cnt,
                              COALESCE(SUM(cp.monto_aprobado), 0) as monto
                              FROM credito_proceso cp
                              JOIN asesor a ON a.id = cp.asesor_id
                              WHERE a.supervisor_id = ?
                                AND cp.estado_credito IN (\'aprobado\',\'desembolsado\')
                                AND DATE(COALESCE(cp.fecha_desembolso, cp.updated_at, cp.created_at)) BETWEEN ? AND ?');
        $st->execute([$supervisor_table_id, $mesI, $mesF]);
        $rowO = $st->fetch();
        $ops_aprobadas = (int)($rowO['cnt']  ?? 0);
        $monto_ops     = (float)($rowO['monto'] ?? 0);

        // Fallback: si no hay data mensual en credito_proceso, intentar con cualquier estado que tenga monto
        if ($ops_aprobadas === 0 && $fichas_credito === 0) {
            $st = $pdo->prepare('SELECT COUNT(DISTINCT cp.id) as cnt,
                                  COALESCE(SUM(cp.monto_aprobado), 0) as monto
                                  FROM credito_proceso cp
                                  JOIN asesor a ON a.id = cp.asesor_id
                                  WHERE a.supervisor_id = ?
                                    AND cp.monto_aprobado > 0
                                    AND DATE(COALESCE(cp.fecha_desembolso, cp.updated_at, cp.created_at)) BETWEEN ? AND ?');
            $st->execute([$supervisor_table_id, $mesI, $mesF]);
            $rowFb = $st->fetch();
            $ops_aprobadas = (int)($rowFb['cnt']  ?? 0);
            $monto_ops     = (float)($rowFb['monto'] ?? 0);
        }

    } catch (PDOException $e) { /* silencioso */ }
}

$total_ops_credito = $ops_aprobadas + $fichas_credito;
$monto_total       = $monto_ops + $monto_fichas;

// ── Últimas 6 tareas del equipo (actividad reciente) ─────────
$recientes = [];
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare("
            SELECT t.tipo_tarea, t.estado, t.fecha_programada, t.observaciones,
                   cp.nombre as cliente_nombre, u.nombre as asesor_nombre
            FROM tarea t
            JOIN asesor a ON a.id = t.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE a.supervisor_id = ?
            ORDER BY t.created_at DESC
            LIMIT 8
        ");
        $st->execute([$supervisor_table_id]);
        $recientes = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Últimos clientes registrados ─────────────────────────────
$ultimos_clientes = [];
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare("
            SELECT cp.nombre, cp.cedula, cp.ciudad, cp.estado, cp.created_at,
                   u.nombre as asesor_nombre
            FROM cliente_prospecto cp
            JOIN asesor a ON a.id = cp.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE a.supervisor_id = ?
            ORDER BY cp.created_at DESC
            LIMIT 5
        ");
        $st->execute([$supervisor_table_id]);
        $ultimos_clientes = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Últimas alertas ──────────────────────────────────────────
$ultimas_alertas = [];
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare("SELECT am.id as id_alerta, am.campo_modificado, am.valor_nuevo, am.created_at, u.nombre as asesor_nombre
            FROM alerta_modificacion am
            JOIN asesor a ON a.id = am.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE am.supervisor_id = ? AND am.vista_supervisor = 0
            ORDER BY am.created_at DESC LIMIT 5");
        $st->execute([$supervisor_table_id]);
        $ultimas_alertas = $st->fetchAll();
    } catch (PDOException $e) {}
}

// ── Créditos aprobados para recuperación (últimos aprobados/desembolsados) ──
$creditos_aprobados = [];
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare("SELECT cp.id, cp.cliente_prospecto_id, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                                     cp.monto_aprobado, cp.fecha_desembolso, cp.created_at,
                                     a.id as asesor_id, u.nombre as asesor_nombre
                              FROM credito_proceso cp
                              JOIN cliente_prospecto cl ON cl.id = cp.cliente_prospecto_id
                              LEFT JOIN asesor a ON a.id = cp.asesor_id
                              LEFT JOIN usuario u ON u.id = a.usuario_id
                              WHERE a.supervisor_id = ? AND cp.estado_credito IN('aprobado','desembolsado')
                              ORDER BY cp.created_at DESC LIMIT 12");
        $st->execute([$supervisor_table_id]);
        $creditos_aprobados = $st->fetchAll();
    } catch (PDOException $e) { /* silencioso */ }
}

$pct_tareas = $tareas_hoy > 0 ? round($tareas_completadas * 100 / $tareas_hoy) : 0;
$periodo_inicio = date('Y-m-01');
$periodo_fin    = date('Y-m-t');
$asesor_ids_dashboard = [];
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

if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE supervisor_id = ?');
        $st->execute([$supervisor_table_id]);
        $asesor_ids_dashboard = $st->fetchAll(PDO::FETCH_COLUMN); // UUIDs — NO convertir con intval

        if (!empty($asesor_ids_dashboard)) {
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
        }
    } catch (Throwable $e) {
        error_log('Dashboard KPI resumen: ' . $e->getMessage());
    }
}

$kpi_cards_dash = [
    ['key' => 'actividad', 'label' => 'Actividad', 'value' => $kpi_dash['actividad_pct'], 'meta' => $kpi_dash['actividad_realizadas'] . '/' . $kpi_dash['actividad_total'] . ' hoy', 'icon' => 'fa-bolt', 'color' => '#3b82f6', 'url' => 'kpi_penetracion.php?view=actividad'],
    ['key' => 'penetracion', 'label' => 'Penetración', 'value' => $kpi_dash['penetracion_pct'], 'meta' => $kpi_dash['penetracion_clientes'] . '/' . $kpi_dash['penetracion_visitas'] . ' visitas', 'icon' => 'fa-chart-pie', 'color' => '#10b981', 'url' => 'kpi_penetracion.php?view=mercado'],
    ['key' => 'interes', 'label' => 'Interés', 'value' => $kpi_dash['interes_pct'], 'meta' => $kpi_dash['interes_si'] . '/' . $kpi_dash['interes_total'] . ' encuestas', 'icon' => 'fa-heart', 'color' => '#f59e0b', 'url' => 'kpi_penetracion.php?view=interes'],
    ['key' => 'prospeccion', 'label' => 'Prospección', 'value' => $kpi_dash['prospeccion_pct'], 'meta' => $kpi_dash['prospeccion_avance'] . '/' . $kpi_dash['prospeccion_meta'] . ' meta', 'icon' => 'fa-route', 'color' => '#8b5cf6', 'url' => 'kpi_penetracion.php?view=prospeccion'],
    ['key' => 'levantamientos', 'label' => 'Levantamientos', 'value' => $kpi_dash['levantamiento_pct'], 'meta' => $kpi_dash['levantamientos'] . '/' . $kpi_dash['interesados'] . ' interesados', 'icon' => 'fa-clipboard-check', 'color' => '#0ea5e9', 'url' => 'kpi_penetracion.php?view=evaluacion'],
    ['key' => 'operaciones', 'label' => 'Operaciones', 'value' => min(100, $kpi_dash['operaciones_total'] * 10), 'meta' => $kpi_dash['operaciones_total'] . ' / $' . number_format($kpi_dash['operaciones_monto'], 0), 'icon' => 'fa-hand-holding-dollar', 'color' => '#ef4444', 'url' => 'kpi_penetracion.php?view=operaciones'],
];
$kpi_cards_dash = array_merge(
    array_slice($kpi_cards_dash, 0, 4),
    [
        ['key' => 'frio', 'label' => 'Visitas al Frio', 'value' => $kpi_dash['frio_pct'], 'meta' => $kpi_dash['frio_visitas'] . ' visitas frias', 'icon' => 'fa-snowflake', 'color' => '#f97316', 'url' => 'kpi_penetracion.php?view=frio'],
    ],
    array_slice($kpi_cards_dash, 4, 1),
    [
        ['key' => 'eficiencia', 'label' => 'Eficiencia', 'value' => $kpi_dash['eficiencia_pct'], 'meta' => $kpi_dash['interes_si'] . ' con interes', 'icon' => 'fa-bolt-lightning', 'color' => '#ec4899', 'url' => 'kpi_penetracion.php?view=eficiencia'],
        ['key' => 'postventa', 'label' => 'Post-Venta', 'value' => $kpi_dash['postventa_pct'], 'meta' => $ops_aprobadas . ' aprobados', 'icon' => 'fa-rotate', 'color' => '#14b8a6', 'url' => 'kpi_penetracion.php?view=postventa'],
        ['key' => 'recuperacion', 'label' => 'Recuperacion', 'value' => $kpi_dash['recuperacion_pct'], 'meta' => $kpi_dash['recuperaciones'] . ' gestiones', 'icon' => 'fa-shield-halved', 'color' => '#ef4444', 'url' => 'kpi_penetracion.php?view=recuperacion'],
    ],
    array_slice($kpi_cards_dash, 5)
);
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super_IA — Dashboard Supervisor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
:root{
  --y:#ffdd00; --yd:#f4c400;
  --n1:#0a2748; --n2:#123a6d; --n3:#1e4d8c;
  --bg:#f0f4f9;
  --card:#ffffff;
  --glass:rgba(255,221,0,.08);
  --gborder:rgba(18,58,109,.15);
  --nborder:#e2e8f0;
  --tm:#1a2744; --td:#64748b;
  --shadow:0 2px 12px rgba(18,58,109,.08);
}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Inter','Segoe UI',sans-serif;}
body{background:var(--bg);color:var(--tm);min-height:100vh;display:flex;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse at 15% 20%,rgba(255,221,0,.04) 0%,transparent 45%),radial-gradient(ellipse at 85% 75%,rgba(18,58,109,.06) 0%,transparent 50%);pointer-events:none;z-index:0;}

/* SIDEBAR */
.sidebar{width:220px;background:linear-gradient(180deg,#06101e 0%,var(--n1) 100%);border-right:1px solid var(--nborder);position:fixed;height:100vh;left:0;top:0;z-index:100;padding:20px 0;overflow-y:auto;flex-shrink:0;}
.sidebar-brand{padding:0 18px 22px;font-size:16px;font-weight:900;border-bottom:1px solid rgba(255,221,0,.12);margin-bottom:18px;display:flex;align-items:center;gap:8px;letter-spacing:.5px;}
.sidebar-brand i{color:var(--y);font-size:18px;}
.sidebar-brand span{background:linear-gradient(90deg,var(--y),#fff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
.sidebar-section{padding:0 12px;margin-bottom:20px;}
.sidebar-section-title{font-size:10px;text-transform:uppercase;color:rgba(255,255,255,.3);letter-spacing:.8px;padding:0 8px;margin-bottom:8px;font-weight:700;}
.sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 12px;margin-bottom:3px;border-radius:10px;color:rgba(255,255,255,.65);text-decoration:none;font-size:13px;border:1px solid transparent;transition:all .2s;}
.sidebar-link:hover{background:rgba(255,221,0,.08);color:#fff;border-color:rgba(255,221,0,.12);}
.sidebar-link.active{background:linear-gradient(90deg,var(--y),var(--yd));color:var(--n1);font-weight:800;}
.badge-nav{background:#ef4444;color:#fff;font-size:9px;padding:1px 6px;border-radius:8px;margin-left:auto;font-weight:800;}

/* MAIN */
.main-content{flex:1;margin-left:220px;display:flex;flex-direction:column;min-height:100vh;position:relative;z-index:1;}



/* CONTENT */
.content-area{flex:1;padding:22px 26px 40px;overflow-y:auto;}

/* WELCOME HERO */
.hero{background:linear-gradient(135deg,rgba(10,39,72,.9) 0%,rgba(18,58,109,.7) 60%,rgba(30,77,140,.5) 100%);border:1px solid var(--gborder);border-radius:20px;padding:24px 28px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:20px;position:relative;overflow:hidden;}
.hero::before{content:'';position:absolute;right:-60px;top:-60px;width:200px;height:200px;background:radial-gradient(circle,rgba(255,221,0,.2) 0%,transparent 68%);pointer-events:none;}
.hero::after{content:'';position:absolute;left:-40px;bottom:-40px;width:150px;height:150px;background:radial-gradient(circle,rgba(18,58,109,.6) 0%,transparent 68%);pointer-events:none;}
.hero-text h2{font-size:22px;font-weight:900;color:#fff;margin-bottom:4px;}
.hero-text p{font-size:13px;color:rgba(255,255,255,.7);margin:0;}
.hero-progress{margin-top:12px;max-width:260px;}
.hp-label{font-size:11px;color:rgba(255,255,255,.65);display:flex;justify-content:space-between;margin-bottom:4px;}
.hp-track{background:rgba(255,255,255,.15);border-radius:6px;height:6px;overflow:hidden;}
.hp-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--y),var(--yd));transition:width 1.4s ease;}
.hero-stats{display:flex;gap:10px;flex-shrink:0;position:relative;z-index:1;}
.hs-item{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:13px;padding:11px 16px;text-align:center;min-width:72px;}
.hs-num{font-size:22px;font-weight:900;color:var(--y);line-height:1;}
.hs-lbl{font-size:9.5px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.4px;font-weight:700;margin-top:2px;}
.hs-item.red{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);}
.hs-item.red .hs-num{color:#f87171;}

/* METRICS BAR */
.metrics-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;}
.metric-card{background:#fff;border:1px solid var(--nborder);border-radius:14px;padding:14px 16px;position:relative;overflow:hidden;transition:.2s;box-shadow:var(--shadow);}
.metric-card:hover{border-color:rgba(18,58,109,.25);box-shadow:0 4px 20px rgba(18,58,109,.12);transform:translateY(-2px);}
.metric-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--mc,var(--y));}
.metric-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--td);font-weight:700;margin-bottom:4px;}
.metric-value{font-size:26px;font-weight:900;color:var(--n1);line-height:1;}
.metric-sub{font-size:11px;color:var(--td);margin-top:3px;}
.metric-trend{font-size:10px;font-weight:800;padding:2px 8px;border-radius:5px;position:absolute;top:14px;right:14px;}
.trend-up{background:rgba(74,222,128,.12);color:#4ade80;}
.trend-dn{background:rgba(239,68,68,.12);color:#f87171;}
.trend-nt{background:rgba(148,163,184,.1);color:#94a3b8;}

/* KPI SECTION HEADER */
.section-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.section-hd h3{font-size:15px;font-weight:900;color:var(--n1);display:flex;align-items:center;gap:8px;}
.section-hd h3 i{color:var(--y);}
.live-badge{display:flex;align-items:center;gap:7px;background:rgba(74,222,128,.07);border:1px solid rgba(74,222,128,.2);padding:4px 12px;border-radius:999px;font-size:11px;font-weight:800;color:#4ade80;}
.live-dot{width:7px;height:7px;border-radius:50%;background:#4ade80;animation:pulse 1.8s infinite;}
.all-link{font-size:12px;font-weight:700;color:var(--y);text-decoration:none;background:rgba(255,221,0,.08);border:1px solid rgba(255,221,0,.15);padding:5px 12px;border-radius:8px;}
.all-link:hover{background:rgba(255,221,0,.16);color:var(--y);}

/* GAUGE GRID */
.gauges-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:13px;margin-bottom:22px;}

/* GAUGE CARD */
.g-card{background:#fff;border:1px solid var(--nborder);border-radius:18px;padding:14px 10px 12px;display:flex;flex-direction:column;align-items:center;position:relative;overflow:hidden;text-decoration:none;color:inherit;transition:transform .22s,box-shadow .22s,border-color .22s;cursor:pointer;box-shadow:var(--shadow);}
.g-card:hover{transform:translateY(-5px);border-color:var(--gc,rgba(255,221,0,.4));box-shadow:0 0 30px rgba(var(--gc-rgb,255,221,0),.15);}
.g-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--gc,var(--y));border-radius:3px 3px 0 0;}
.g-card::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(var(--gc-rgb,255,221,0),.06) 0%,transparent 60%);pointer-events:none;}
.g-title{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;color:#64748b;display:flex;align-items:center;gap:5px;margin-bottom:2px;}
.g-title i{font-size:12px;color:var(--gc,var(--y));}
.g-chart{width:100%;max-width:160px;height:130px;min-height:130px;}
.g-pct-row{display:flex;align-items:center;gap:7px;margin-top:-8px;}
.g-pct{font-size:24px;font-weight:900;color:var(--n1);line-height:1;}
.g-badge{font-size:9.5px;font-weight:800;padding:2px 7px;border-radius:5px;}
.gb-good{background:rgba(74,222,128,.15);color:#4ade80;}
.gb-warn{background:rgba(251,191,36,.15);color:#fbbf24;}
.gb-bad{background:rgba(239,68,68,.15);color:#f87171;}
.g-meta{font-size:11px;color:var(--td);margin-top:4px;text-align:center;font-weight:600;}
.g-track{width:76%;height:4px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:7px;}
.g-fill{height:100%;border-radius:999px;background:var(--gc,var(--y));transition:width 1.4s ease;}

/* OPS SPECIAL */
.g-card-ops{background:linear-gradient(135deg,var(--n1),var(--n2));border-color:rgba(255,221,0,.35);}
.g-card-ops:hover{border-color:var(--y);}
.ops-num{font-size:38px;font-weight:900;color:var(--y);line-height:1;margin:8px 0 3px;}
.ops-money{font-size:16px;font-weight:700;color:rgba(255,255,255,.85);}
.ops-lbl{font-size:9px;text-transform:uppercase;color:rgba(255,255,255,.55);font-weight:700;letter-spacing:.6px;margin-top:2px;}

/* FUNNEL FLOW */
.funnel-section{background:#fff;border:1px solid var(--nborder);border-radius:18px;padding:20px 22px;margin-bottom:22px;position:relative;overflow:hidden;box-shadow:var(--shadow);}
.funnel-section::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 50% -10%,rgba(255,221,0,.05) 0%,transparent 55%);pointer-events:none;}
.funnel-steps{display:flex;align-items:center;justify-content:space-between;gap:0;position:relative;z-index:1;margin-top:16px;}
.f-step{display:flex;flex-direction:column;align-items:center;gap:6px;flex:1;max-width:100px;}
.f-ico{width:52px;height:52px;border-radius:14px;background:rgba(18,58,109,.06);border:1px solid var(--nborder);display:flex;align-items:center;justify-content:center;font-size:18px;transition:.2s;position:relative;}
.f-ico::after{content:attr(data-pct);position:absolute;top:-6px;right:-6px;background:var(--fc,var(--y));color:var(--n1);font-size:9px;font-weight:900;padding:1px 5px;border-radius:6px;}
.f-ico:hover{transform:scale(1.1);}
.f-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:#64748b;text-align:center;line-height:1.3;}
.f-val{font-size:13px;font-weight:900;color:var(--n1);}
.f-connector{flex:1;height:2px;background:linear-gradient(90deg,var(--y),rgba(255,221,0,.2));margin:0 4px;margin-bottom:26px;position:relative;overflow:hidden;}
.f-connector::after{content:'';position:absolute;top:0;left:-100%;width:60%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.7),transparent);animation:flow 2.5s linear infinite;}
@keyframes flow{0%{left:-60%;}100%{left:110%;}}

/* BOTTOM GRID */
.bottom-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:13px;}
.b-card{background:#fff;border:1px solid var(--nborder);border-radius:16px;overflow:hidden;transition:border-color .2s,box-shadow .2s;box-shadow:var(--shadow);}
.b-card:hover{border-color:rgba(18,58,109,.3);box-shadow:0 4px 20px rgba(18,58,109,.1);}
.b-head{padding:12px 16px;border-bottom:1px solid var(--nborder);display:flex;align-items:center;gap:9px;background:rgba(18,58,109,.04);}
.b-head h5{font-size:13px;font-weight:900;color:var(--n1);margin:0;flex:1;display:flex;align-items:center;gap:7px;}
.bh-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;}
.b-badge{font-size:10px;font-weight:800;padding:2px 8px;border-radius:7px;background:rgba(18,58,109,.1);color:var(--n2);border:1px solid rgba(18,58,109,.2);}
.b-badge-red{background:rgba(239,68,68,.2);color:#f87171;border-color:rgba(239,68,68,.25);}
.b-link{font-size:11px;font-weight:700;color:var(--y);text-decoration:none;opacity:.8;}
.b-link:hover{opacity:1;}

/* Clients */
.cli-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;transition:background .14s;}
.cli-row:hover{background:rgba(18,58,109,.04);}
.cli-row:last-child{border-bottom:none;}
.cli-av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--n1),var(--n2));border:1px solid rgba(255,221,0,.2);color:var(--y);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0;}
.cli-name{font-size:12.5px;font-weight:700;color:var(--n1);}
.cli-sub{font-size:10.5px;color:var(--td);}
.cli-est{font-size:10px;font-weight:800;padding:2px 7px;border-radius:5px;margin-left:auto;flex-shrink:0;}
.est-c{background:rgba(74,222,128,.12);color:#4ade80;}
.est-p{background:rgba(59,130,246,.12);color:#60a5fa;}
.est-pe{background:rgba(251,191,36,.12);color:#fbbf24;}

/* Quick */
.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px;padding:10px 12px;}
.qb{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:10px 6px;border-radius:13px;font-weight:800;font-size:11px;text-align:center;min-height:65px;text-decoration:none;transition:.2s;border:1px solid transparent;}
.qb i{font-size:16px;}
.qb:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.3);}
.qb-y{background:linear-gradient(135deg,var(--y),var(--yd));color:var(--n1);}
.qb-n{background:linear-gradient(135deg,var(--n1),var(--n2));color:#fff;border-color:var(--nborder);}
.qb-g{background:linear-gradient(135deg,#065f46,#059669);color:#fff;}
.qb-b{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;}
.qb-r{background:linear-gradient(135deg,#7f1d1d,#dc2626);color:#fff;}
.qb-p{background:linear-gradient(135deg,#4c1d95,#7c3aed);color:#fff;}
.qb-t{background:linear-gradient(135deg,#134e4a,#0d9488);color:#fff;}
.qb-o{background:linear-gradient(135deg,#7c2d12,#ea580c);color:#fff;}

/* Alertas */
.al-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f1f5f9;transition:background .14s;}
.al-row:hover{background:rgba(239,68,68,.03);}
.al-row:last-child{border-bottom:none;}
.al-ico{width:34px;height:34px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);color:#f87171;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.al-campo{font-size:12.5px;font-weight:800;color:var(--n1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.al-asesor{font-size:10.5px;color:var(--td);}
.al-valor{font-size:10.5px;color:#4ade80;font-weight:700;margin-top:2px;background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.2);padding:1px 7px;border-radius:5px;display:inline-block;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.al-right{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0;}
.al-time{font-size:10px;color:var(--td);white-space:nowrap;}
.btn-visto{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#f87171;cursor:pointer;font-size:10px;padding:2px 7px;border-radius:6px;font-weight:700;}
.btn-visto:hover{background:rgba(239,68,68,.2);}

/* Actividad */
.act-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f1f5f9;}
.act-row:last-child{border-bottom:none;}
.act-ico{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.ai-ok{background:rgba(74,222,128,.12);color:#4ade80;}
.ai-pe{background:rgba(251,191,36,.12);color:#fbbf24;}
.act-name{font-size:12.5px;font-weight:700;color:var(--n1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.act-sub{font-size:10.5px;color:var(--td);}
.act-date{font-size:10px;color:var(--td);flex-shrink:0;margin-left:auto;}

/* empty */
.empty-b{padding:24px;text-align:center;color:var(--td);font-size:12.5px;}
.empty-b i{display:block;font-size:22px;margin-bottom:6px;opacity:.5;}

/* scrollbar */
::-webkit-scrollbar{width:5px;}
::-webkit-scrollbar-thumb{background:rgba(18,58,109,.3);border-radius:3px;}
::-webkit-scrollbar-track{background:#f1f5f9;}

@media(max-width:1400px){.gauges-grid{grid-template-columns:repeat(auto-fill,minmax(165px,1fr));}}
@media(max-width:1200px){.bottom-grid,.metrics-bar{grid-template-columns:1fr 1fr;} .funnel-steps{flex-wrap:wrap;gap:10px;justify-content:center;} .f-connector{display:none;}}
@media(max-width:900px){.main-content{margin-left:0;} .gauges-grid{grid-template-columns:repeat(2,1fr);} .bottom-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php $navTitle=''; $navIcon=''; require_once '_sidebar_supervisor.php'; ?>



<!-- HERO -->
<div class="hero">
  <div class="hero-text" style="position:relative;z-index:1;">
    <h2>Panel de Supervisión — <?= htmlspecialchars(explode(' ',$supervisor_nombre)[0]) ?></h2>
    <p>Monitoreo en tiempo real · <?= strtoupper(strftime('%B %Y') ?: date('M Y')) ?></p>
    <?php if($tareas_hoy>0): ?>
    <div class="hero-progress">
      <div class="hp-label"><span>Progreso de tareas hoy</span><span><?= $tareas_completadas ?>/<?= $tareas_hoy ?></span></div>
      <div class="hp-track"><div class="hp-fill" id="hp-fill" style="width:0%;"></div></div>
    </div>
    <?php endif; ?>
  </div>
  <div class="hero-stats" style="position:relative;z-index:1;">
    <div class="hs-item"><div class="hs-num"><?= $total_asesores ?></div><div class="hs-lbl">Asesores</div></div>
    <div class="hs-item"><div class="hs-num" id="cnt-clientes">0</div><div class="hs-lbl">Clientes</div></div>
    <div class="hs-item"><div class="hs-num" id="cnt-activos">0</div><div class="hs-lbl">Activos</div></div>
    <?php if($alertas_pendientes>0): ?>
    <div class="hs-item red"><div class="hs-num"><?= $alertas_pendientes ?></div><div class="hs-lbl">Alertas</div></div>
    <?php endif; ?>
  </div>
</div>

<!-- METRICS BAR -->
<div class="metrics-bar">
  <div class="metric-card" style="--mc:#ffdd00;">
    <div class="metric-trend <?= $pct_tareas>=70?'trend-up':($pct_tareas>=35?'trend-nt':'trend-dn') ?>"><?= $pct_tareas ?>%</div>
    <div class="metric-label">Actividad Hoy</div>
    <div class="metric-value" id="cnt-act">0</div>
    <div class="metric-sub"><?= $tareas_completadas ?>/<?= $tareas_hoy ?> tareas completadas</div>
  </div>
  <div class="metric-card" style="--mc:#4ade80;">
    <div class="metric-trend trend-nt"><?= $kpi_dash['penetracion_pct'] ?>%</div>
    <div class="metric-label">Penetración Mensual</div>
    <div class="metric-value" id="cnt-pen">0</div>
    <div class="metric-sub"><?= $kpi_dash['penetracion_clientes'] ?>/<?= $kpi_dash['penetracion_visitas'] ?> visitas</div>
  </div>
  <div class="metric-card" style="--mc:#60a5fa;">
    <div class="metric-trend trend-nt"><?= $kpi_dash['operaciones_total'] ?></div>
    <div class="metric-label">Créditos <?=date('M Y')?></div>
    <div class="metric-value" id="cnt-ops" style="color:var(--n2);">$<?= number_format($kpi_dash['operaciones_monto'],0,'.',',') ?></div>
    <div class="metric-sub"><?= $kpi_dash['operaciones_total'] ?> crédito<?= $kpi_dash['operaciones_total']!=1?'s':'' ?> desembolsado<?= $kpi_dash['operaciones_total']!=1?'s':'' ?></div>
  </div>
  <div class="metric-card" style="--mc:#f87171;">
    <div class="metric-trend b-badge-red"><?= $alertas_pendientes ?></div>
    <div class="metric-label">Alertas Sin Ver</div>
    <div class="metric-value" style="color:#f87171;" id="cnt-alerta">0</div>
    <div class="metric-sub">Pendientes de revisión</div>
  </div>
</div>

<!-- KPI GAUGES -->
<div class="section-hd">
  <h3><i class="fas fa-gauge-high"></i> KPIs del Equipo — Tacómetros en Vivo</h3>
  <div style="display:flex;gap:10px;align-items:center;">
    <div class="live-badge"><div class="live-dot"></div>EN VIVO</div>
    <a href="kpi_penetracion.php" class="all-link"><i class="fas fa-external-link-alt me-1"></i>Ver reportes</a>
  </div>
</div>

<div class="gauges-grid">
<?php
$gkpis=[
  ['k'=>'actividad',    'lbl'=>'Actividad',      'ico'=>'fa-bolt',            'c'=>'#60a5fa', 'cr'=>'96,165,250',  'v'=>$kpi_dash['actividad_pct'],     'meta'=>$kpi_dash['actividad_realizadas'].'/'.$kpi_dash['actividad_total'].' hoy',          'url'=>'kpi_penetracion.php?view=actividad'],
  ['k'=>'penetracion',  'lbl'=>'Penetración',    'ico'=>'fa-chart-pie',       'c'=>'#4ade80', 'cr'=>'74,222,128',  'v'=>$kpi_dash['penetracion_pct'],   'meta'=>$kpi_dash['penetracion_clientes'].'/'.$kpi_dash['penetracion_visitas'].' visitas', 'url'=>'kpi_penetracion.php?view=mercado'],
  ['k'=>'interes',      'lbl'=>'Interés',        'ico'=>'fa-heart-pulse',     'c'=>'#fbbf24', 'cr'=>'251,191,36',  'v'=>$kpi_dash['interes_pct'],       'meta'=>$kpi_dash['interes_si'].'/'.$kpi_dash['interes_total'].' encuestas',              'url'=>'kpi_penetracion.php?view=interes'],
  ['k'=>'prospeccion',  'lbl'=>'Prospección',    'ico'=>'fa-route',           'c'=>'#c084fc', 'cr'=>'192,132,252', 'v'=>$kpi_dash['prospeccion_pct'],   'meta'=>$kpi_dash['prospeccion_avance'].'/'.$kpi_dash['prospeccion_meta'].' meta',        'url'=>'kpi_penetracion.php?view=prospeccion'],
  ['k'=>'levantamiento','lbl'=>'Levantamientos', 'ico'=>'fa-clipboard-check', 'c'=>'#38bdf8', 'cr'=>'56,189,248',  'v'=>$kpi_dash['levantamiento_pct'], 'meta'=>$kpi_dash['levantamientos'].'/'.$kpi_dash['interesados'].' interesados',         'url'=>'kpi_penetracion.php?view=evaluacion'],
  ['k'=>'frio',         'lbl'=>'Visitas Frío',   'ico'=>'fa-snowflake',       'c'=>'#fb923c', 'cr'=>'251,146,60',  'v'=>$kpi_dash['frio_pct'],          'meta'=>$kpi_dash['frio_visitas'].' visitas frías',                                      'url'=>'kpi_penetracion.php?view=frio'],
  ['k'=>'eficiencia',   'lbl'=>'Eficiencia',     'ico'=>'fa-bolt-lightning',  'c'=>'#f472b6', 'cr'=>'244,114,182', 'v'=>$kpi_dash['eficiencia_pct'],    'meta'=>$kpi_dash['interes_si'].' con interés',                                          'url'=>'kpi_penetracion.php?view=eficiencia'],
  ['k'=>'postventa',    'lbl'=>'Post-Venta',     'ico'=>'fa-rotate',          'c'=>'#2dd4bf', 'cr'=>'45,212,191',  'v'=>$kpi_dash['postventa_pct'],     'meta'=>$ops_aprobadas.' aprobados',                                                     'url'=>'kpi_penetracion.php?view=postventa'],
  ['k'=>'recuperacion', 'lbl'=>'Recuperación',   'ico'=>'fa-shield-halved',   'c'=>'#f87171', 'cr'=>'248,113,113', 'v'=>$kpi_dash['recuperacion_pct'],  'meta'=>$kpi_dash['recuperaciones'].' gestiones',                                        'url'=>'kpi_penetracion.php?view=recuperacion'],
];
foreach($gkpis as $g):
  $v=(float)$g['v'];
  $bc=$v>=70?'gb-good':($v>=35?'gb-warn':'gb-bad');
  $bt=$v>=70?'▲ OK':($v>=35?'~ Med':'▼ Bajo');
?>
<a href="<?=htmlspecialchars($g['url'])?>" class="g-card" style="--gc:<?=$g['c']?>;--gc-rgb:<?=$g['cr']?>;"
   data-val="<?=$v?>" data-color="<?=htmlspecialchars($g['c'])?>" data-key="<?=htmlspecialchars($g['k'])?>">
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

<!-- OPERACIONES — dark card especial -->
<a href="kpi_penetracion.php?view=operaciones" class="g-card g-card-ops" style="--gc:#ffdd00;justify-content:center;">
  <div class="g-title"><i class="fas fa-hand-holding-dollar" style="color:var(--y);"></i>Créditos <?=date('M Y')?></div>
  <div class="ops-num" id="cnt-ops-big">0</div>
  <div style="font-size:11px;color:rgba(255,255,255,.55);font-weight:700;letter-spacing:.4px;text-transform:uppercase;margin-top:2px;">
    <?=$kpi_dash['operaciones_total']==1?'crédito desembolsado':'créditos desembolsados'?>
  </div>
  <div class="ops-money" style="margin-top:10px;">
    $<?=number_format($kpi_dash['operaciones_monto'],0,'.',',')?>
  </div>
  <div class="ops-lbl">Monto prestado este mes</div>
  <div class="g-track" style="background:rgba(255,255,255,.1);width:80%;margin-top:10px;">
    <div class="g-fill" style="width:<?=min(100,$kpi_dash['operaciones_total']*10)?>%;background:var(--y);"></div>
  </div>
</a>
</div><!-- /gauges-grid -->

<!-- FUNNEL FLOW -->
<div class="funnel-section">
  <div class="section-hd" style="margin-bottom:0;">
    <h3 style="color:var(--n1);"><i class="fas fa-diagram-project"></i> Flujo de Conversión del Equipo</h3>
    <span style="font-size:12px;color:var(--td);"><?= strtoupper(date('M Y')) ?></span>
  </div>
  <div class="funnel-steps">
    <?php
    $fsteps=[
      ['lbl'=>'Prospección', 'ico'=>'fas fa-route',           'c'=>'#c084fc', 'v'=>$kpi_dash['prospeccion_pct'],    'n'=>$kpi_dash['prospeccion_avance']],
      ['lbl'=>'Visitas',     'ico'=>'fas fa-map-marker-alt',  'c'=>'#60a5fa', 'v'=>$kpi_dash['penetracion_pct'],    'n'=>$kpi_dash['penetracion_visitas']],
      ['lbl'=>'Interés',     'ico'=>'fas fa-heart-pulse',     'c'=>'#fbbf24', 'v'=>$kpi_dash['interes_pct'],        'n'=>$kpi_dash['interes_si']],
      ['lbl'=>'Levantam.',   'ico'=>'fas fa-clipboard-check', 'c'=>'#38bdf8', 'v'=>$kpi_dash['levantamiento_pct'],  'n'=>$kpi_dash['levantamientos']],
      ['lbl'=>'Eficiencia',  'ico'=>'fas fa-bolt',            'c'=>'#f472b6', 'v'=>$kpi_dash['eficiencia_pct'],     'n'=>$kpi_dash['interes_si']],
      ['lbl'=>'Operaciones', 'ico'=>'fas fa-handshake',       'c'=>'#ffdd00', 'v'=>min(100,$kpi_dash['operaciones_total']*10), 'n'=>$kpi_dash['operaciones_total']],
    ];
    foreach($fsteps as $i=>$fs):
    ?>
    <div class="f-step">
      <div class="f-ico" style="color:<?=$fs['c']?>;border-color:<?=$fs['c']?>33;background:<?=$fs['c']?>12;" data-pct="<?=(int)$fs['v']?>%">
        <i class="<?=$fs['ico']?>"></i>
      </div>
      <div class="f-label"><?=htmlspecialchars($fs['lbl'])?></div>
      <div class="f-val" style="color:<?=$fs['c']?>"><?=$fs['n']?></div>
    </div>
    <?php if($i<count($fsteps)-1): ?><div class="f-connector"></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

<!-- BOTTOM 4 -->
<div class="bottom-grid">

  <!-- ÚLTIMOS CLIENTES -->
  <div class="b-card">
    <div class="b-head">
      <div class="bh-ico" style="background:rgba(18,58,109,.5);color:var(--y);"><i class="fas fa-users"></i></div>
      <h5>Últimos Clientes</h5>
      <a href="clientes.php" class="b-link">Ver todos →</a>
    </div>
    <?php if(empty($ultimos_clientes)): ?>
      <div class="empty-b"><i class="fas fa-user-slash"></i>Sin registros</div>
    <?php else: foreach($ultimos_clientes as $cl):
      $in=mb_strtoupper(mb_substr(trim($cl['nombre']??'C'),0,1));
      $ec='est-'.($cl['estado']??'prospecto'); ?>
    <a href="ver_cliente.php?id=<?=urlencode($cl['cedula']??'')?>" class="cli-row">
      <div class="cli-av"><?=htmlspecialchars($in)?></div>
      <div style="flex:1;min-width:0;">
        <div class="cli-name"><?=htmlspecialchars($cl['nombre']??'—')?></div>
        <div class="cli-sub"><?=htmlspecialchars($cl['ciudad']??'')?> · <?=htmlspecialchars($cl['asesor_nombre']??'')?></div>
      </div>
      <span class="cli-est <?=$ec?>"><?=ucfirst($cl['estado']??'prospecto')?></span>
    </a>
    <?php endforeach; endif; ?>
  </div>

  <!-- ACCESO RÁPIDO -->
  <div class="b-card">
    <div class="b-head">
      <div class="bh-ico" style="background:rgba(255,221,0,.12);color:var(--y);"><i class="fas fa-bolt"></i></div>
      <h5>Acceso Rápido</h5>
    </div>
    <div class="quick-grid">
      <a href="registro_asesor.php"    class="qb qb-y"><i class="fas fa-user-plus"></i>Crear Asesor</a>
      <a href="clientes.php"           class="qb qb-n"><i class="fas fa-address-book"></i>Clientes</a>
      <a href="operaciones.php"        class="qb qb-g"><i class="fas fa-handshake"></i>Operaciones</a>
      <a href="mapa_vivo_asesor.php"   class="qb qb-b"><i class="fas fa-map-marked-alt"></i>Mapa Vivo</a>
      <a href="alertas.php"            class="qb qb-r"><i class="fas fa-bell"></i>Alertas<?=$alertas_pendientes>0?" ($alertas_pendientes)":''?></a>
      <a href="kpi_penetracion.php"    class="qb qb-p"><i class="fas fa-chart-line"></i>KPI Report</a>
      <a href="mis_asesores.php"       class="qb qb-t"><i class="fas fa-users-cog"></i>Asesores</a>
      <a href="metas.php"              class="qb qb-o"><i class="fas fa-bullseye"></i>Metas</a>
    </div>
  </div>



  <!-- ACTIVIDAD RECIENTE -->
  <div class="b-card">
    <div class="b-head">
      <div class="bh-ico" style="background:rgba(74,222,128,.12);color:#4ade80;"><i class="fas fa-clock-rotate-left"></i></div>
      <h5>Actividad Reciente</h5>
      <span class="b-badge"><?=count($recientes)?></span>
    </div>
    <?php if(empty($recientes)): ?>
      <div class="empty-b"><i class="fas fa-inbox"></i>Sin actividad</div>
    <?php else: foreach($recientes as $r):
      $done=($r['estado']??'')==='completada';
      $tipo=ucfirst(str_replace('_',' ',$r['tipo_tarea']??'visita'));
    ?>
    <div class="act-row">
      <div class="act-ico <?=$done?'ai-ok':'ai-pe'?>"><i class="fas <?=$done?'fa-check':'fa-clock'?>"></i></div>
      <div style="flex:1;min-width:0;">
        <div class="act-name"><?=htmlspecialchars($r['cliente_nombre']??'—')?></div>
        <div class="act-sub"><?=htmlspecialchars($tipo)?> · <?=htmlspecialchars($r['asesor_nombre']??'')?></div>
      </div>
      <span class="act-date"><?=$r['fecha_programada']?date('d/m',strtotime($r['fecha_programada'])):''?></span>
    </div>
    <?php endforeach; endif; ?>

  </div>

</div><!-- /bottom-grid -->


<script>
document.addEventListener('DOMContentLoaded',function(){

  // ── ANIMATED COUNTERS ─────────────────────────────────────
  function counter(el, target, duration, prefix, suffix){
    if(!el) return;
    var start=0, step=target/(duration/16);
    var t=setInterval(function(){
      start+=step;
      if(start>=target){start=target;clearInterval(t);}
      el.textContent=prefix+Math.round(start).toLocaleString()+suffix;
    },16);
  }
  setTimeout(function(){
    counter(document.getElementById('cnt-clientes'), <?=$total_clientes?>, 1200,'','');
    counter(document.getElementById('cnt-activos'),  <?=$clientes_activos?>, 1200,'','');
    counter(document.getElementById('cnt-act'),      <?=$pct_tareas?>, 900,'','%');
    counter(document.getElementById('cnt-pen'),      <?=$kpi_dash['penetracion_pct']?>, 900,'','%');
    counter(document.getElementById('cnt-ops-big'),  <?=$kpi_dash['operaciones_total']?>, 1000,'','');
    counter(document.getElementById('cnt-alerta'),   <?=$alertas_pendientes?>, 700,'','');
    // progress bar hero
    var hf=document.getElementById('hp-fill');
    if(hf) setTimeout(function(){ hf.style.width='<?=$pct_tareas?>%'; },200);
    // g-fill bars
    document.querySelectorAll('.g-fill').forEach(function(el){
      var w=el.style.width; el.style.width='0';
      setTimeout(function(){ el.style.width=w; },300);
    });
  },400);

  // ── APEX GAUGE FACTORY ────────────────────────────────────
  function makeGauge(elId, value, color){
    var el=document.getElementById(elId);
    if(!el) return;
    var fill = value>=70? color : (value>=35? '#fbbf24' : '#f87171');
    new ApexCharts(el,{
      series:[Math.min(100,Math.max(0,value))],
      chart:{type:'radialBar',height:130,width:'100%',
        toolbar:{show:false},
        animations:{enabled:true,easing:'easeinout',speed:1200,animateGradually:{enabled:true,delay:100}}},
      plotOptions:{radialBar:{
        startAngle:-130, endAngle:130,
        track:{background:'#e2e8f0',strokeWidth:'70%',margin:3},
        dataLabels:{show:true,name:{show:false},value:{
          offsetY:6, fontSize:'17px', fontWeight:'900',
          fontFamily:'Inter,sans-serif', color:'#1a2744',
          formatter:function(v){return Math.round(v)+'%';}
        }},
        hollow:{margin:4, size:'48%', background:'transparent'}
      }},
      fill:{type:'gradient',gradient:{shade:'light',type:'horizontal',
        shadeIntensity:.15, gradientToColors:[fill], inverseColors:false,
        opacityFrom:1, opacityTo:.9, stops:[0,100]}},
      colors:[color],
      stroke:{lineCap:'round'},
      tooltip:{enabled:false},
      grid:{padding:{top:-10,bottom:-10,left:-10,right:-10}}
    }).render();
  }

  // ── RENDER GAUGES ─────────────────────────────────────────
  var gd=<?php $ga=[];foreach($gkpis as $g) $ga[]=['k'=>$g['k'],'v'=>(float)$g['v'],'c'=>$g['c']];echo json_encode($ga);?>;
  gd.forEach(function(g){ makeGauge('gc-'+g.k, g.v, g.c); });


  // ── CARD HOVER GLOW ───────────────────────────────────────
  document.querySelectorAll('.g-card').forEach(function(c){
    c.addEventListener('mouseenter',function(){
      var col=getComputedStyle(c).getPropertyValue('--gc').trim();
      if(col) c.style.boxShadow='0 0 32px '+col+'28';
    });
    c.addEventListener('mouseleave',function(){ c.style.boxShadow=''; });
  });

});
</script>
</body>
</html>

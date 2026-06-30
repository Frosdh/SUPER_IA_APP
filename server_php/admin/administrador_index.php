<?php
// ============================================================
// administrador_index.php — Dashboard Administrador Total SUPER_IA
// Acceso completo: todo lo de supervisor + todo lo de gerente
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

// ── Auth: acepta admin_logged_in, super_admin_logged_in y administrador_logged_in ──
$is_admin      = isset($_SESSION['admin_logged_in'])          && $_SESSION['admin_logged_in']          === true;
$is_super      = isset($_SESSION['super_admin_logged_in'])    && $_SESSION['super_admin_logged_in']    === true;
$is_adm        = isset($_SESSION['administrador_logged_in'])  && $_SESSION['administrador_logged_in']  === true;

if (!$is_admin && !$is_super && !$is_adm) {
    header('Location: login.php?role=administrador');
    exit;
}

$admin_nombre = $_SESSION['administrador_nombre']
    ?? $_SESSION['super_admin_nombre']
    ?? $_SESSION['admin_nombre']
    ?? 'Administrador';
$admin_email  = $_SESSION['administrador_email']
    ?? $_SESSION['super_admin_email']
    ?? $_SESSION['admin_email']
    ?? '';

// ══════════════════════════════════════════════════════════════
// MÉTRICAS PRINCIPALES
// ══════════════════════════════════════════════════════════════
$mesI = date('Y-m-01');
$mesF = date('Y-m-t');

// Contadores rápidos
$total_usuarios       = 0;
$total_asesores       = 0;
$total_supervisores   = 0;
$total_clientes       = 0;
$clientes_activos     = 0;
$tareas_hoy           = 0;
$tareas_completadas   = 0;
$tareas_postergadas   = 0;
$alertas_pendientes   = 0;
$solicitudes_pend     = 0;
$creditos_mes         = 0;
$monto_aprobado_mes   = 0.0;
$fichas_pendientes    = 0;
$encuestas_mes        = 0;

try { $total_usuarios     = (int)$pdo->query("SELECT COUNT(*) FROM usuario WHERE activo=1")->fetchColumn(); } catch(Exception $e){}
try { $total_asesores     = (int)$pdo->query("SELECT COUNT(*) FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE u.activo=1")->fetchColumn(); } catch(Exception $e){}
try { $total_supervisores = (int)$pdo->query("SELECT COUNT(*) FROM supervisor s JOIN usuario u ON u.id=s.usuario_id WHERE u.activo=1")->fetchColumn(); } catch(Exception $e){}
try {
    $r = $pdo->query("SELECT COUNT(*) as tot, SUM(estado!='descartado') as act FROM cliente_prospecto")->fetch();
    $total_clientes   = (int)($r['tot'] ?? 0);
    $clientes_activos = (int)($r['act'] ?? 0);
} catch(Exception $e){}
try {
    $r = $pdo->query("SELECT COUNT(*) as tot, SUM(estado='completada') as comp, SUM(estado='postergada') as post FROM tarea WHERE fecha_programada=CURDATE()")->fetch();
    $tareas_hoy          = (int)($r['tot']  ?? 0);
    $tareas_completadas  = (int)($r['comp'] ?? 0);
    $tareas_postergadas  = (int)($r['post'] ?? 0);
} catch(Exception $e){}
try { $alertas_pendientes = (int)$pdo->query("SELECT COUNT(*) FROM alerta_modificacion WHERE vista_supervisor=0")->fetchColumn(); } catch(Exception $e){}
try { $solicitudes_pend   = (int)$pdo->query("SELECT COUNT(*) FROM solicitud_registro WHERE estado='pendiente'")->fetchColumn(); } catch(Exception $e){}
try {
    $r = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(monto_aprobado),0) as monto FROM credito_proceso WHERE estado_credito IN ('aprobado','desembolsado') AND DATE(updated_at) BETWEEN ? AND ?");
    $r->execute([$mesI, $mesF]);
    $row = $r->fetch();
    $creditos_mes       = (int)($row['cnt']   ?? 0);
    $monto_aprobado_mes = (float)($row['monto'] ?? 0);
} catch(Exception $e){}
try { $fichas_pendientes  = (int)$pdo->query("SELECT COUNT(*) FROM ficha_producto WHERE estado_revision='pendiente'")->fetchColumn(); } catch(Exception $e){}
try {
    $r = $pdo->prepare("SELECT COUNT(*) as cnt FROM (
        SELECT t.id FROM encuesta_comercial ec JOIN tarea t ON t.id=ec.tarea_id WHERE DATE(t.fecha_realizada) BETWEEN ? AND ?
        UNION ALL
        SELECT t2.id FROM encuesta_crediticia ecr JOIN tarea t2 ON t2.id=ecr.tarea_id WHERE DATE(t2.fecha_realizada) BETWEEN ? AND ?
    ) x");
    $r->execute([$mesI,$mesF,$mesI,$mesF]);
    $encuestas_mes = (int)($r->fetch()['cnt'] ?? 0);
} catch(Exception $e){}

$pct_tareas = $tareas_hoy > 0 ? round(100 * $tareas_completadas / $tareas_hoy) : 0;

// ── Top 5 asesores del mes ─────────────────────────────────────
$top_asesores = [];
try {
    $top_asesores = $pdo->prepare("
        SELECT u.nombre, COUNT(t.id) as total,
               SUM(t.estado='completada') as comp,
               ROUND(100*SUM(t.estado='completada')/NULLIF(COUNT(t.id),0),1) as pct
        FROM asesor a
        JOIN usuario u ON u.id=a.usuario_id
        LEFT JOIN tarea t ON t.asesor_id=a.id AND t.fecha_programada BETWEEN ? AND ?
        GROUP BY a.id, u.nombre
        ORDER BY comp DESC LIMIT 5
    ");
    $top_asesores->execute([$mesI, $mesF]);
    $top_asesores = $top_asesores->fetchAll();
} catch(Exception $e){}

// ── Pipeline crédito por estado ───────────────────────────────
$pipeline = [];
try {
    $pipeline = $pdo->query("
        SELECT estado_credito, COUNT(*) as total,
               COALESCE(SUM(monto_aprobado),0) as monto
        FROM credito_proceso
        GROUP BY estado_credito
        ORDER BY FIELD(estado_credito,'prospectado','entrevista_venta','levantamiento','solicitud','analisis','aprobado','desembolsado','rechazado','recuperacion')
    ")->fetchAll();
} catch(Exception $e){}

// ── Alertas recientes ─────────────────────────────────────────
$alertas_recientes = [];
try {
    $alertas_recientes = $pdo->query("
        SELECT am.id, am.created_at, u.nombre as asesor, am.campo_modificado, am.valor_nuevo, am.vista_supervisor
        FROM alerta_modificacion am
        JOIN asesor a ON a.id=am.asesor_id
        JOIN usuario u ON u.id=a.usuario_id
        ORDER BY am.created_at DESC LIMIT 8
    ")->fetchAll();
} catch(Exception $e){}

// ── Solicitudes pendientes recientes ─────────────────────────
$solicitudes_recientes = [];
try {
    $solicitudes_recientes = $pdo->query("
        SELECT sr.id, sr.rol_solicitado, sr.created_at, u.nombre, u.email
        FROM solicitud_registro sr JOIN usuario u ON u.id=sr.usuario_id
        WHERE sr.estado='pendiente'
        ORDER BY sr.created_at DESC LIMIT 6
    ")->fetchAll();
} catch(Exception $e){}

// ── Tareas por tipo hoy ──────────────────────────────────────
$tareas_por_tipo = [];
try {
    $tareas_por_tipo = $pdo->query("
        SELECT tipo_tarea, COUNT(*) as total, SUM(estado='completada') as comp
        FROM tarea WHERE fecha_programada=CURDATE()
        GROUP BY tipo_tarea ORDER BY total DESC LIMIT 7
    ")->fetchAll();
} catch(Exception $e){}

$currentPage = 'admin_dashboard';
?>
<!DOCTYPE html>
<html lang="es" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SUPER_IA — Panel Administrador</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0b1120;--surface:#111827;--surface2:#1a2540;--surface3:#1e2d4a;
  --border:#1f3050;--text:#f0f4ff;--muted:#8a9ab8;--faint:#4a5f7a;
  --accent:#6366f1;--accent2:#818cf8;
  --purple:#8b5cf6;--cyan:#06b6d4;--green:#10b981;--amber:#f59e0b;
  --red:#ef4444;--pink:#ec4899;
  --sidebar-w:230px;
}
html,body{height:100%;font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ─── Layout ──────────────────────── */
.layout{display:flex;height:100vh;overflow:hidden}

/* ─── Sidebar ─────────────────────── */
.sidebar{
  width:var(--sidebar-w);min-width:var(--sidebar-w);
  background:linear-gradient(180deg,#080e1a 0%,#0d1630 100%);
  border-right:1px solid var(--border);
  display:flex;flex-direction:column;
  overflow-y:auto;z-index:100;
  scrollbar-width:thin;scrollbar-color:var(--border) transparent;
}
.sidebar-brand{
  padding:22px 20px 18px;
  display:flex;align-items:center;gap:10px;
  border-bottom:1px solid var(--border);
}
.brand-icon{
  width:38px;height:38px;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--purple));
  display:grid;place-items:center;font-size:18px;flex-shrink:0;
}
.brand-name{font-weight:800;font-size:16px;letter-spacing:.5px}
.brand-tag{font-size:10px;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
.sidebar-section{padding:16px 12px 4px;font-size:10px;font-weight:700;letter-spacing:1.5px;color:var(--faint);text-transform:uppercase}
.nav-link-adm{
  display:flex;align-items:center;gap:10px;
  padding:9px 14px;margin:1px 8px;border-radius:9px;
  color:var(--muted);font-size:13px;font-weight:500;
  text-decoration:none;transition:.18s ease;
  position:relative;
}
.nav-link-adm:hover{background:rgba(99,102,241,.08);color:var(--text)}
.nav-link-adm.active{background:linear-gradient(90deg,rgba(99,102,241,.18),rgba(139,92,246,.08));color:var(--accent2);border-left:2px solid var(--accent)}
.nav-link-adm .ico{width:18px;text-align:center;font-size:14px;flex-shrink:0}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700}
.sidebar-user{
  margin-top:auto;padding:16px;border-top:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
}
.avatar{
  width:36px;height:36px;border-radius:10px;
  background:linear-gradient(135deg,var(--accent),var(--purple));
  display:grid;place-items:center;font-size:14px;font-weight:700;flex-shrink:0;
}
.user-name{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.user-role{font-size:10px;color:var(--accent2)}

/* ─── Main ────────────────────────── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{
  padding:0 28px;height:58px;min-height:58px;
  display:flex;align-items:center;justify-content:space-between;
  background:rgba(8,14,26,.8);backdrop-filter:blur(10px);
  border-bottom:1px solid var(--border);
}
.topbar-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.topbar-actions{display:flex;align-items:center;gap:10px}
.btn-adm{
  padding:7px 14px;border-radius:8px;border:1px solid var(--border);
  background:transparent;color:var(--muted);font-size:12px;font-weight:600;
  cursor:pointer;transition:.15s;text-decoration:none;display:flex;align-items:center;gap:6px;
}
.btn-adm:hover{border-color:var(--accent);color:var(--accent)}
.btn-adm.danger:hover{border-color:var(--red);color:var(--red)}
.content{flex:1;overflow-y:auto;padding:24px 28px 32px;scrollbar-width:thin;scrollbar-color:var(--border) transparent}

/* ─── Cards ───────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.kpi-card{
  background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:18px 20px;position:relative;overflow:hidden;
}
.kpi-card::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--c,var(--accent));
}
.kpi-label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.kpi-val{font-size:28px;font-weight:800;line-height:1;margin-bottom:4px}
.kpi-sub{font-size:12px;color:var(--muted)}
.kpi-icon{position:absolute;right:16px;top:50%;transform:translateY(-50%);font-size:28px;opacity:.1}

.panel{background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden}
.panel-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.panel-body{padding:20px}
.panel-link{font-size:12px;color:var(--accent);text-decoration:none;font-weight:600}
.panel-link:hover{color:var(--accent2)}

/* ─── Progress bar ────────────────── */
.prog-wrap{background:rgba(255,255,255,.06);border-radius:20px;height:6px;overflow:hidden}
.prog-fill{height:100%;border-radius:20px;background:var(--c,var(--accent));transition:.4s ease}

/* ─── Table ───────────────────────── */
.adm-table{width:100%;border-collapse:collapse;font-size:13px}
.adm-table th{padding:8px 12px;color:var(--faint);font-size:10px;letter-spacing:.8px;text-transform:uppercase;font-weight:700;border-bottom:1px solid var(--border)}
.adm-table td{padding:10px 12px;border-bottom:1px solid rgba(31,48,80,.5);vertical-align:middle}
.adm-table tr:last-child td{border-bottom:none}
.adm-table tr:hover td{background:rgba(99,102,241,.04)}

/* ─── Badge / pill ────────────────── */
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700}
.pill-green {background:rgba(16,185,129,.15);color:#34d399}
.pill-amber {background:rgba(245,158,11,.15);color:#fbbf24}
.pill-red   {background:rgba(239,68,68,.15);color:#f87171}
.pill-blue  {background:rgba(99,102,241,.15);color:#818cf8}
.pill-cyan  {background:rgba(6,182,212,.15);color:#22d3ee}
.pill-muted {background:rgba(148,163,184,.1);color:#94a3b8}

/* ─── Alert dot ───────────────────── */
.alert-dot{width:8px;height:8px;border-radius:50%;background:var(--amber);display:inline-block;margin-right:6px;animation:pulse 1.8s ease infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

/* ─── Grid layout main ────────────── */
.row-grid{display:grid;gap:16px}
.g-2{grid-template-columns:1fr 1fr}
.g-3{grid-template-columns:2fr 1fr}

/* ─── Pipeline funnel ─────────────── */
.funnel-item{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.funnel-label{font-size:12px;color:var(--muted);width:140px;flex-shrink:0;text-transform:capitalize}
.funnel-bar-wrap{flex:1;background:rgba(255,255,255,.05);border-radius:4px;height:8px;overflow:hidden}
.funnel-bar{height:100%;border-radius:4px}
.funnel-count{font-size:12px;font-weight:700;color:var(--text);min-width:28px;text-align:right}

/* ─── Scrollbar ───────────────────── */
::-webkit-scrollbar{width:5px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}

/* ─── Responsive ──────────────────── */
@media(max-width:1200px){.kpi-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:768px){.sidebar{display:none}.kpi-grid{grid-template-columns:1fr}.g-2,.g-3{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">

  <!-- ─── SIDEBAR ──────────────────────────────── -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">🛡️</div>
      <div>
        <div class="brand-name">SUPER<span style="color:var(--accent2)">_IA</span></div>
        <div class="brand-tag">Administrador</div>
      </div>
    </div>

    <div class="sidebar-section">Panel Global</div>
    <a class="nav-link-adm active" href="administrador_index.php">
      <span class="ico"><i class="fa-solid fa-house"></i></span> Dashboard
    </a>
    <a class="nav-link-adm" href="usuarios.php">
      <span class="ico"><i class="fa-solid fa-users"></i></span> Usuarios
      <?php if($solicitudes_pend > 0):?><span class="nav-badge"><?=$solicitudes_pend?></span><?php endif?>
    </a>
    <a class="nav-link-adm" href="mis_supervisores.php">
      <span class="ico"><i class="fa-solid fa-user-tie"></i></span> Supervisores
    </a>
    <a class="nav-link-adm" href="mis_asesores_superIA.php">
      <span class="ico"><i class="fa-solid fa-users-gear"></i></span> Asesores
    </a>

    <div class="sidebar-section">Operaciones</div>
    <a class="nav-link-adm" href="clientes.php">
      <span class="ico"><i class="fa-solid fa-address-card"></i></span> Clientes
    </a>
    <a class="nav-link-adm" href="operaciones.php">
      <span class="ico"><i class="fa-solid fa-briefcase"></i></span> Operaciones
    </a>
    <a class="nav-link-adm" href="recuperacion_creditos.php">
      <span class="ico"><i class="fa-solid fa-rotate-left"></i></span> Recuperación
    </a>
    <a class="nav-link-adm" href="pendientes.php">
      <span class="ico"><i class="fa-solid fa-hourglass-end"></i></span> Pendientes
      <?php if($fichas_pendientes > 0):?><span class="nav-badge"><?=$fichas_pendientes?></span><?php endif?>
    </a>

    <div class="sidebar-section">Monitoreo</div>
    <a class="nav-link-adm" href="mapa_vivo_superIA.php">
      <span class="ico"><i class="fa-solid fa-map-location-dot"></i></span> Mapa Vivo
    </a>
    <a class="nav-link-adm" href="alertas.php">
      <span class="ico"><i class="fa-solid fa-bell"></i></span> Alertas
      <?php if($alertas_pendientes > 0):?><span class="nav-badge"><?=$alertas_pendientes?></span><?php endif?>
    </a>
    <a class="nav-link-adm" href="historial_rutas.php">
      <span class="ico"><i class="fa-solid fa-route"></i></span> Rutas GPS
    </a>

    <div class="sidebar-section">Análisis</div>
    <a class="nav-link-adm" href="reportes.php">
      <span class="ico"><i class="fa-solid fa-chart-bar"></i></span> Reportes KPI
    </a>
    <a class="nav-link-adm" href="mapa_calor.php">
      <span class="ico"><i class="fa-solid fa-fire"></i></span> Mapa Calor
    </a>
    <a class="nav-link-adm" href="kpi_penetracion.php">
      <span class="ico"><i class="fa-solid fa-chart-pie"></i></span> Penetración
    </a>
    <a class="nav-link-adm" href="exportar.php">
      <span class="ico"><i class="fa-solid fa-file-export"></i></span> Exportar
    </a>

    <div class="sidebar-section">Sistema</div>
    <a class="nav-link-adm" href="administrar_solicitudes_admin.php">
      <span class="ico"><i class="fa-solid fa-clipboard-check"></i></span> Solicitudes
      <?php if($solicitudes_pend > 0):?><span class="nav-badge"><?=$solicitudes_pend?></span><?php endif?>
    </a>
    <a class="nav-link-adm" href="super_admin_index.php">
      <span class="ico"><i class="fa-solid fa-crown"></i></span> Super Admin
    </a>
    <a class="nav-link-adm" href="configurar_smtp.php">
      <span class="ico"><i class="fa-solid fa-envelope-open-text"></i></span> Config SMTP
    </a>

    <div class="sidebar-user">
      <div class="avatar"><?=strtoupper(substr($admin_nombre,0,1))?></div>
      <div style="min-width:0">
        <div class="user-name"><?=htmlspecialchars($admin_nombre)?></div>
        <div class="user-role">Administrador Total</div>
      </div>
      <a href="logout.php" title="Cerrar sesión" style="margin-left:auto;color:var(--muted);font-size:14px;flex-shrink:0"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </aside>

  <!-- ─── MAIN ─────────────────────────────────── -->
  <div class="main">
    <div class="topbar">
      <div class="topbar-title">
        <span style="color:var(--accent)">🛡️</span>
        Panel Administrador
        <span style="font-size:12px;color:var(--muted);font-weight:400">— Acceso Total</span>
      </div>
      <div class="topbar-actions">
        <?php if($alertas_pendientes>0):?>
        <a class="btn-adm" href="alertas.php">
          <span class="alert-dot"></span><?=$alertas_pendientes?> alertas
        </a>
        <?php endif?>
        <?php if($solicitudes_pend>0):?>
        <a class="btn-adm" href="administrar_solicitudes_admin.php">
          <i class="fa-solid fa-user-clock"></i> <?=$solicitudes_pend?> solicitud<?=$solicitudes_pend>1?'es':''?>
        </a>
        <?php endif?>
        <a class="btn-adm" href="mapa_vivo_superIA.php"><i class="fa-solid fa-satellite-dish"></i> Mapa Vivo</a>
        <a class="btn-adm danger" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
      </div>
    </div>

    <div class="content">

      <!-- ── Saludo ─────────────────────────── -->
      <div style="margin-bottom:22px">
        <h5 style="font-weight:800;font-size:20px">Hola, <?=htmlspecialchars(explode(' ',$admin_nombre)[0])?>
          <span style="background:linear-gradient(90deg,var(--accent),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent"> 👋</span>
        </h5>
        <p style="color:var(--muted);font-size:13px;margin-top:2px">
          <?=date('l d \d\e F Y')?> · Visión completa del sistema SUPER_IA
        </p>
      </div>

      <!-- ── KPI Cards ──────────────────────── -->
      <div class="kpi-grid">
        <div class="kpi-card" style="--c:var(--accent)">
          <div class="kpi-label">Usuarios activos</div>
          <div class="kpi-val"><?=$total_usuarios?></div>
          <div class="kpi-sub"><?=$total_supervisores?> superv. · <?=$total_asesores?> asesores</div>
          <i class="fa-solid fa-users kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--green)">
          <div class="kpi-label">Clientes registrados</div>
          <div class="kpi-val"><?=$total_clientes?></div>
          <div class="kpi-sub"><?=$clientes_activos?> activos</div>
          <i class="fa-solid fa-address-card kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--cyan)">
          <div class="kpi-label">Tareas hoy</div>
          <div class="kpi-val"><?=$tareas_hoy?></div>
          <div class="kpi-sub"><?=$tareas_completadas?> completadas · <?=$pct_tareas?>%</div>
          <i class="fa-solid fa-list-check kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--amber)">
          <div class="kpi-label">Créditos aprobados (mes)</div>
          <div class="kpi-val"><?=$creditos_mes?></div>
          <div class="kpi-sub">$<?=number_format($monto_aprobado_mes,0)?> aprobados</div>
          <i class="fa-solid fa-building-columns kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--purple)">
          <div class="kpi-label">Encuestas mes</div>
          <div class="kpi-val"><?=$encuestas_mes?></div>
          <div class="kpi-sub">Comerciales + crediticias</div>
          <i class="fa-solid fa-clipboard-list kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--red)">
          <div class="kpi-label">Alertas sin revisar</div>
          <div class="kpi-val"><?=$alertas_pendientes?></div>
          <div class="kpi-sub">Requieren atención</div>
          <i class="fa-solid fa-bell kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:#f472b6">
          <div class="kpi-label">Fichas pendientes</div>
          <div class="kpi-val"><?=$fichas_pendientes?></div>
          <div class="kpi-sub">Por aprobar / revisar</div>
          <i class="fa-solid fa-file-circle-question kpi-icon"></i>
        </div>
        <div class="kpi-card" style="--c:var(--amber)">
          <div class="kpi-label">Solicitudes pendientes</div>
          <div class="kpi-val"><?=$solicitudes_pend?></div>
          <div class="kpi-sub">Nuevos registros</div>
          <i class="fa-solid fa-user-clock kpi-icon"></i>
        </div>
      </div>

      <!-- ── Barra progreso tareas hoy ─────── -->
      <div class="panel mb-4">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-list-check" style="color:var(--cyan)"></i> Progreso de tareas hoy</div>
          <span style="font-size:12px;color:var(--muted)"><?=date('d/m/Y')?></span>
        </div>
        <div class="panel-body">
          <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px">
            <span><?=$tareas_completadas?> completadas de <?=$tareas_hoy?> programadas</span>
            <strong style="color:var(--cyan)"><?=$pct_tareas?>%</strong>
          </div>
          <div class="prog-wrap">
            <div class="prog-fill" style="width:<?=$pct_tareas?>%;--c:var(--cyan)"></div>
          </div>
          <div style="display:flex;gap:16px;margin-top:12px;font-size:12px;color:var(--muted)">
            <span><span style="color:var(--green)">●</span> Completadas: <?=$tareas_completadas?></span>
            <span><span style="color:var(--amber)">●</span> Postergadas: <?=$tareas_postergadas?></span>
            <span><span style="color:var(--faint)">●</span> Pendientes: <?=max(0,$tareas_hoy-$tareas_completadas-$tareas_postergadas)?></span>
          </div>
        </div>
      </div>

      <!-- ── Fila: Top asesores + Pipeline ─── -->
      <div class="row-grid g-2 mb-4">

        <!-- Top asesores -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="fa-solid fa-ranking-star" style="color:var(--amber)"></i> Top asesores del mes</div>
            <a class="panel-link" href="mis_asesores_superIA.php">Ver todos →</a>
          </div>
          <div class="panel-body" style="padding:12px 20px">
            <?php if(empty($top_asesores)):?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">Sin datos aún</p>
            <?php else: foreach($top_asesores as $i=>$a): $c=['var(--amber)','var(--muted)','#cd7f32','var(--faint)','var(--faint)'][$i]??'var(--faint)' ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
              <span style="color:<?=$c?>;font-weight:800;font-size:14px;width:16px;text-align:center"><?=$i+1?></span>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($a['nombre'])?></div>
                <div class="prog-wrap" style="margin-top:4px">
                  <div class="prog-fill" style="width:<?=$a['pct']??0?>%;--c:<?=$c?>"></div>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:13px;font-weight:700;color:var(--text)"><?=$a['comp']?>/<?=$a['total']?></div>
                <div style="font-size:10px;color:var(--muted)"><?=$a['pct']??0?>%</div>
              </div>
            </div>
            <?php endforeach; endif ?>
          </div>
        </div>

        <!-- Pipeline crédito -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="fa-solid fa-filter" style="color:var(--purple)"></i> Pipeline de crédito</div>
            <a class="panel-link" href="operaciones.php">Ver todo →</a>
          </div>
          <div class="panel-body" style="padding:12px 20px">
            <?php if(empty($pipeline)):?>
            <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px">Sin datos aún</p>
            <?php else:
              $maxP = max(array_column($pipeline,'total'));
              $colors=['prospectado'=>'#6366f1','entrevista_venta'=>'#8b5cf6','levantamiento'=>'#06b6d4','solicitud'=>'#f59e0b','analisis'=>'#ec4899','aprobado'=>'#10b981','desembolsado'=>'#22d3ee','rechazado'=>'#ef4444','recuperacion'=>'#f97316'];
              foreach($pipeline as $p):
                $c=$colors[$p['estado_credito']]??'#64748b';
                $pct=round(100*$p['total']/max($maxP,1));
              ?>
              <div class="funnel-item">
                <span class="funnel-label"><?=str_replace('_',' ',$p['estado_credito'])?></span>
                <div class="funnel-bar-wrap">
                  <div class="funnel-bar" style="width:<?=$pct?>%;background:<?=$c?>"></div>
                </div>
                <span class="funnel-count"><?=$p['total']?></span>
              </div>
              <?php endforeach; endif ?>
          </div>
        </div>
      </div>

      <!-- ── Fila: Alertas + Solicitudes ───── -->
      <div class="row-grid g-2 mb-4">

        <!-- Alertas recientes -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><span class="alert-dot"></span> Alertas recientes</div>
            <a class="panel-link" href="alertas.php">Ver todas →</a>
          </div>
          <?php if(empty($alertas_recientes)):?>
          <div style="padding:28px;text-align:center;color:var(--muted);font-size:13px">
            <i class="fa-solid fa-check-circle" style="color:var(--green);font-size:24px;display:block;margin-bottom:8px"></i>
            Sin alertas pendientes
          </div>
          <?php else:?>
          <table class="adm-table">
            <thead><tr>
              <th>Asesor</th><th>Campo</th><th>Nuevo valor</th><th>Hora</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach($alertas_recientes as $al):?>
            <tr>
              <td style="font-weight:600"><?=htmlspecialchars($al['asesor'])?></td>
              <td style="color:var(--muted)"><?=htmlspecialchars($al['campo_modificado']??'—')?></td>
              <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($al['valor_nuevo']??'—')?></td>
              <td style="color:var(--muted);white-space:nowrap;font-size:11px"><?=date('H:i',strtotime($al['created_at']))?></td>
              <td><?=$al['vista_supervisor']?'<span class="pill pill-green">Vista</span>':'<span class="pill pill-amber">Nueva</span>'?></td>
            </tr>
            <?php endforeach?>
            </tbody>
          </table>
          <?php endif?>
        </div>

        <!-- Solicitudes pendientes -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title"><i class="fa-solid fa-user-clock" style="color:var(--accent)"></i> Solicitudes de registro</div>
            <a class="panel-link" href="administrar_solicitudes_admin.php">Gestionar →</a>
          </div>
          <?php if(empty($solicitudes_recientes)):?>
          <div style="padding:28px;text-align:center;color:var(--muted);font-size:13px">
            <i class="fa-solid fa-inbox" style="font-size:24px;display:block;margin-bottom:8px"></i>
            Sin solicitudes pendientes
          </div>
          <?php else:?>
          <table class="adm-table">
            <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach($solicitudes_recientes as $sol):?>
            <tr>
              <td style="font-weight:600"><?=htmlspecialchars($sol['nombre'])?></td>
              <td style="color:var(--muted);font-size:11px"><?=htmlspecialchars($sol['email'])?></td>
              <td><span class="pill pill-blue"><?=htmlspecialchars($sol['rol_solicitado'])?></span></td>
              <td style="color:var(--muted);font-size:11px"><?=date('d/m H:i',strtotime($sol['created_at']))?></td>
            </tr>
            <?php endforeach?>
            </tbody>
          </table>
          <?php endif?>
        </div>
      </div>

      <!-- ── Tareas por tipo hoy ─────────────── -->
      <?php if(!empty($tareas_por_tipo)):?>
      <div class="panel mb-4">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-chart-column" style="color:var(--green)"></i> Tareas por tipo — hoy</div>
        </div>
        <div class="panel-body" style="padding:16px 20px">
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px">
            <?php foreach($tareas_por_tipo as $tt):
              $pct2 = $tt['total']>0?round(100*$tt['comp']/$tt['total']):0;
              $label = str_replace(['_','prospecto nuevo','visita frio'],['  ','prospecto','en frío'],strtolower($tt['tipo_tarea']));
            ?>
            <div style="background:var(--surface2);border-radius:10px;padding:12px 14px;border:1px solid var(--border)">
              <div style="font-size:11px;color:var(--muted);margin-bottom:4px;text-transform:capitalize"><?=htmlspecialchars($label)?></div>
              <div style="font-size:20px;font-weight:800;margin-bottom:6px"><?=$tt['comp']?><span style="font-size:13px;color:var(--muted);font-weight:400">/<?=$tt['total']?></span></div>
              <div class="prog-wrap">
                <div class="prog-fill" style="width:<?=$pct2?>%;--c:var(--green)"></div>
              </div>
            </div>
            <?php endforeach?>
          </div>
        </div>
      </div>
      <?php endif?>

      <!-- ── Accesos rápidos ─────────────────── -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-bolt" style="color:var(--amber)"></i> Accesos rápidos</div>
        </div>
        <div class="panel-body" style="padding:16px 20px">
          <div style="display:flex;flex-wrap:wrap;gap:10px">
            <?php
            $links=[
              ['mapa_vivo_superIA.php','fa-satellite-dish','Mapa vivo','var(--cyan)'],
              ['alertas.php','fa-bell','Alertas','var(--red)'],
              ['operaciones.php','fa-briefcase','Operaciones','var(--purple)'],
              ['reportes.php','fa-chart-bar','Reportes','var(--accent)'],
              ['mis_asesores_superIA.php','fa-users-gear','Asesores','var(--green)'],
              ['mis_supervisores.php','fa-user-tie','Supervisores','var(--amber)'],
              ['clientes.php','fa-address-card','Clientes','var(--cyan)'],
              ['exportar.php','fa-file-export','Exportar','var(--muted)'],
              ['historial_rutas.php','fa-route','Rutas GPS','var(--pink)'],
              ['mapa_calor.php','fa-fire','Mapa calor','var(--red)'],
              ['kpi_penetracion.php','fa-chart-pie','Penetración','var(--purple)'],
              ['administrar_solicitudes_admin.php','fa-clipboard-check','Solicitudes','var(--amber)'],
            ];
            foreach($links as [$href,$icon,$label,$color]):?>
            <a href="<?=$href?>" style="
              display:flex;align-items:center;gap:8px;
              background:var(--surface2);border:1px solid var(--border);
              border-radius:9px;padding:10px 14px;
              color:var(--text);text-decoration:none;font-size:13px;font-weight:500;
              transition:.15s;
            " onmouseover="this.style.borderColor='<?=$color?>'" onmouseout="this.style.borderColor='var(--border)'">
              <i class="fa-solid <?=$icon?>" style="color:<?=$color?>"></i> <?=$label?>
            </a>
            <?php endforeach?>
          </div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->
<script>
// Auto-refresh alertas badge cada 60s
setTimeout(()=>location.reload(), 60000);
</script>
</body>
</html>

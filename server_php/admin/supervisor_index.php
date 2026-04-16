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

        // Fichas de crédito (encuesta móvil)
        $st = $pdo->prepare('SELECT COUNT(*) as cnt, SUM(fc.monto_credito) as monto
                              FROM ficha_producto fp
                              JOIN ficha_credito fc ON fc.ficha_id=fp.id
                              JOIN asesor a ON (a.id=fp.asesor_id) OR (fp.asesor_id IS NULL AND a.usuario_id = fp.usuario_id)
                              WHERE fp.producto_tipo="credito" AND a.supervisor_id=?');
        $st->execute([$supervisor_table_id]);
        $rowF = $st->fetch();
        $fichas_credito = (int)($rowF['cnt']  ?? 0);
        $monto_fichas   = (float)($rowF['monto'] ?? 0);

        // Procesos de crédito aprobados
        $st = $pdo->prepare('SELECT COUNT(*) as cnt, SUM(cp.monto_aprobado) as monto
                              FROM credito_proceso cp
                              JOIN asesor a ON a.id=cp.asesor_id
                              WHERE a.supervisor_id=? AND cp.estado_credito IN("aprobado","desembolsado")');
        $st->execute([$supervisor_table_id]);
        $rowO = $st->fetch();
        $ops_aprobadas = (int)($rowO['cnt']  ?? 0);
        $monto_ops     = (float)($rowO['monto'] ?? 0);

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
        $st = $pdo->prepare("
            SELECT am.campo_modificado, am.valor_nuevo, am.created_at, u.nombre as asesor_nombre
            FROM alerta_modificacion am
            JOIN asesor a ON a.id = am.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            WHERE am.supervisor_id = ? AND am.vista_supervisor = 0
            ORDER BY am.created_at DESC LIMIT 5
        ");
        $st->execute([$supervisor_table_id]);
        $ultimas_alertas = $st->fetchAll();
    } catch (PDOException $e) {}
}

$pct_tareas = $tareas_hoy > 0 ? round($tareas_completadas * 100 / $tareas_hoy) : 0;
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Supervisor — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow:      #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy:        #123a6d;
            --brand-navy-deep:   #0a2748;
            --brand-gray:        #6b7280;
            --brand-border:      #d7e0ea;
            --brand-card:        #ffffff;
            --brand-bg:          #f4f6f9;
            --brand-shadow:      0 16px 34px rgba(18,58,109,.08);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter','Segoe UI',sans-serif; background:linear-gradient(180deg,#f8fafc 0%,var(--brand-bg) 100%); display:flex; height:100vh; color:var(--brand-navy-deep); }

        /* ── SIDEBAR ── */
        .sidebar { width:230px; background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%); color:#fff; padding:20px 0; overflow-y:auto; position:fixed; height:100vh; left:0; top:0; z-index:100; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding:0 15px; margin-bottom:22px; }
        .sidebar-section-title { font-size:11px; text-transform:uppercase; color:rgba(255,255,255,.5); letter-spacing:.6px; padding:0 10px; margin-bottom:10px; font-weight:700; }
        .sidebar-link { display:flex; align-items:center; gap:12px; padding:11px 15px; margin-bottom:4px; border-radius:10px; color:rgba(255,255,255,.82); text-decoration:none; font-size:14px; border:1px solid transparent; transition:all .22s; }
        .sidebar-link:hover { background:rgba(255,221,0,.12); color:#fff; padding-left:20px; border-color:rgba(255,221,0,.15); }
        .sidebar-link.active { background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep)); color:var(--brand-navy-deep); font-weight:700; }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }

        /* ── MAIN ── */
        .main-content { flex:1; margin-left:230px; display:flex; flex-direction:column; overflow:hidden; }
        .navbar-custom { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 12px 28px rgba(18,58,109,.18); flex-shrink:0; }
        .navbar-custom h2 { margin:0; font-size:20px; font-weight:700; }
        .user-info { display:flex; align-items:center; gap:15px; }
        .btn-logout { background:rgba(255,221,0,.15); color:#fff; border:1px solid rgba(255,221,0,.28); padding:8px 15px; border-radius:10px; text-decoration:none; font-weight:600; }
        .btn-logout:hover { background:rgba(255,221,0,.26); color:#fff; }
        .content-area { flex:1; overflow-y:auto; padding:28px 30px; }

        /* ── WELCOME BANNER ── */
        .welcome-card { background:linear-gradient(135deg,var(--brand-navy-deep) 0%,var(--brand-navy) 55%,#2b5b99 100%); color:#fff; padding:28px 32px; border-radius:20px; margin-bottom:26px; box-shadow:0 20px 42px rgba(18,58,109,.18); position:relative; overflow:hidden; display:flex; align-items:center; justify-content:space-between; gap:20px; }
        .welcome-card::after { content:''; position:absolute; right:-50px; top:-50px; width:200px; height:200px; background:radial-gradient(circle,rgba(255,221,0,.25) 0%,transparent 70%); pointer-events:none; }
        .welcome-card h2 { margin:0 0 6px; font-size:22px; font-weight:800; }
        .welcome-card p { margin:0; opacity:.85; font-size:14px; }
        .welcome-meta { display:flex; gap:16px; flex-shrink:0; }
        .welcome-meta-item { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.18); border-radius:12px; padding:10px 18px; text-align:center; }
        .welcome-meta-item .wm-num { font-size:22px; font-weight:800; color:var(--brand-yellow); }
        .welcome-meta-item .wm-lbl { font-size:11px; opacity:.75; text-transform:uppercase; letter-spacing:.4px; }

        /* ── KPI GRID ── */
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; margin-bottom:26px; }
        .kpi-card { background:var(--brand-card); padding:20px 18px; border-radius:16px; box-shadow:var(--brand-shadow); border:1px solid var(--brand-border); position:relative; overflow:hidden; text-decoration:none; display:block; transition:transform .2s,box-shadow .2s; }
        .kpi-card:hover { transform:translateY(-3px); box-shadow:0 20px 40px rgba(18,58,109,.12); }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-yellow::before { background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep)); }
        .kpi-blue::before   { background:linear-gradient(90deg,#3b82f6,#1d4ed8); }
        .kpi-green::before  { background:linear-gradient(90deg,#10b981,#059669); }
        .kpi-red::before    { background:linear-gradient(90deg,#ef4444,#dc2626); }
        .kpi-purple::before { background:linear-gradient(90deg,#8b5cf6,#7c3aed); }
        .kpi-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; margin-bottom:12px; }
        .kpi-yellow .kpi-icon { background:rgba(255,221,0,.15); color:#c59a00; }
        .kpi-blue   .kpi-icon { background:rgba(59,130,246,.12); color:#3b82f6; }
        .kpi-green  .kpi-icon { background:rgba(16,185,129,.12); color:#10b981; }
        .kpi-red    .kpi-icon { background:rgba(239,68,68,.10);  color:#ef4444; }
        .kpi-purple .kpi-icon { background:rgba(139,92,246,.12); color:#8b5cf6; }
        .kpi-num { font-size:28px; font-weight:800; color:var(--brand-navy-deep); line-height:1; }
        .kpi-label { font-size:12px; color:var(--brand-gray); margin-top:4px; font-weight:500; }

        /* ── PROGRESS BAR ── */
        .progress-bar-wrap { background:#e5e7eb; border-radius:6px; height:6px; margin-top:8px; overflow:hidden; }
        .progress-bar-fill { height:100%; border-radius:6px; background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep)); transition:width 1s ease; }

        /* ── CARDS ── */
        .section-card { background:var(--brand-card); border-radius:16px; box-shadow:var(--brand-shadow); border:1px solid var(--brand-border); overflow:hidden; margin-bottom:22px; }
        .section-header { padding:16px 20px; border-bottom:1px solid var(--brand-border); display:flex; align-items:center; justify-content:space-between; background:#fafbfc; }
        .section-header h5 { font-size:15px; font-weight:800; margin:0; color:var(--brand-navy-deep); display:flex; align-items:center; gap:10px; }
        .sec-badge { font-size:11px; background:var(--brand-navy); color:#fff; padding:3px 9px; border-radius:10px; font-weight:700; }
        .sec-link { font-size:12px; color:var(--brand-navy); font-weight:700; text-decoration:none; }
        .sec-link:hover { text-decoration:underline; }

        /* ── ACTIVITY LIST ── */
        .act-item { display:flex; align-items:flex-start; gap:12px; padding:12px 20px; border-bottom:1px solid rgba(215,224,234,.4); }
        .act-item:last-child { border-bottom:none; }
        .act-dot { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; margin-top:2px; }
        .dot-ok    { background:#ecfdf5; color:#059669; }
        .dot-pend  { background:#fffbeb; color:#d97706; }
        .dot-alert { background:#fef2f2; color:#dc2626; }
        .dot-blue  { background:#eff6ff; color:#3b82f6; }
        .act-body { flex:1; min-width:0; }
        .act-title { font-size:13.5px; font-weight:700; color:var(--brand-navy-deep); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .act-meta  { font-size:11.5px; color:var(--brand-gray); margin-top:2px; }
        .act-date  { font-size:11px; color:#b0bac5; flex-shrink:0; white-space:nowrap; }

        /* ── CLIENTE ROWS ── */
        .cli-row { display:flex; align-items:center; gap:12px; padding:11px 20px; border-bottom:1px solid rgba(215,224,234,.4); text-decoration:none; color:inherit; transition:background .15s; }
        .cli-row:hover { background:rgba(255,221,0,.05); }
        .cli-row:last-child { border-bottom:none; }
        .cli-avatar { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex-shrink:0; }
        .cli-name { font-size:13.5px; font-weight:700; color:var(--brand-navy-deep); }
        .cli-sub  { font-size:11.5px; color:var(--brand-gray); }
        .cli-estado { font-size:11px; font-weight:700; padding:2px 8px; border-radius:6px; margin-left:auto; flex-shrink:0; }
        .est-prospecto { background:#eff6ff; color:#1d4ed8; }
        .est-cliente   { background:#ecfdf5; color:#065f46; }
        .est-pendiente { background:#fffbeb; color:#92400e; }
        .est-descartado{ background:#fef2f2; color:#991b1b; }

        /* ── QUICK LINKS ── */
        .quick-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:16px 20px; }
        .quick-btn { display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:12px; text-decoration:none; font-weight:700; font-size:13.5px; transition:.22s; }
        .quick-btn:hover { transform:translateY(-2px); }
        .q-yellow { background:linear-gradient(135deg,var(--brand-yellow),var(--brand-yellow-deep)); color:var(--brand-navy-deep); }
        .q-navy   { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; }
        .q-green  { background:linear-gradient(135deg,#059669,#10b981); color:#fff; }
        .q-blue   { background:linear-gradient(135deg,#1d4ed8,#3b82f6); color:#fff; }
        .q-red    { background:linear-gradient(135deg,#dc2626,#ef4444); color:#fff; }
        .q-light  { background:#f0f4f9; color:var(--brand-navy-deep); border:1px solid var(--brand-border); }

        .empty-msg { padding:24px; text-align:center; color:#b0bac5; font-size:13.5px; }
        .empty-msg i { display:block; font-size:24px; margin-bottom:8px; }

        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }

        @media (max-width:900px) { .welcome-meta { display:none; } .kpi-grid { grid-template-columns:repeat(2,1fr); } }
    </style>
</head>
<body>

<?php require_once '_sidebar_supervisor.php'; ?>

<!-- ══════════ MAIN ══════════ -->
<div class="main-content">

    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><i class="fas fa-shield-halved me-2" style="color:var(--brand-yellow);"></i>Super_IA — Supervisor</h2>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($supervisor_nombre) ?></strong><br>
                <small><?= htmlspecialchars($supervisor_rol) ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content-area">

        <!-- WELCOME BANNER -->
        <div class="welcome-card">
            <div>
                <h2>¡Bienvenido, <?= htmlspecialchars(explode(' ', $supervisor_nombre)[0]) ?>!</h2>
                <p>Supervisa tu equipo, revisa operaciones de crédito y monitorea clientes en tiempo real.</p>
                <?php if ($tareas_hoy > 0): ?>
                <div style="margin-top:12px;display:flex;align-items:center;gap:10px;">
                    <div style="flex:1;max-width:220px;">
                        <div style="font-size:12px;opacity:.75;margin-bottom:4px;">Tareas de hoy: <?= $tareas_completadas ?>/<?= $tareas_hoy ?> completadas</div>
                        <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $pct_tareas ?>%;"></div></div>
                    </div>
                    <span style="font-size:18px;font-weight:800;color:var(--brand-yellow);"><?= $pct_tareas ?>%</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="welcome-meta">
                <div class="welcome-meta-item">
                    <div class="wm-num"><?= $total_asesores ?></div>
                    <div class="wm-lbl">Asesores</div>
                </div>
                <div class="welcome-meta-item">
                    <div class="wm-num"><?= $total_clientes ?></div>
                    <div class="wm-lbl">Clientes</div>
                </div>
                <?php if ($alertas_pendientes > 0): ?>
                <div class="welcome-meta-item" style="border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.12);">
                    <div class="wm-num" style="color:#ef4444;"><?= $alertas_pendientes ?></div>
                    <div class="wm-lbl">Alertas</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI CARDS -->
        <div class="kpi-grid">
            <a href="mis_asesores.php" class="kpi-card kpi-yellow">
                <div class="kpi-icon"><i class="fas fa-users"></i></div>
                <div class="kpi-num"><?= $total_asesores ?></div>
                <div class="kpi-label">Asesores en mi equipo</div>
            </a>
            <a href="clientes.php" class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="fas fa-address-book"></i></div>
                <div class="kpi-num"><?= $total_clientes ?></div>
                <div class="kpi-label">Total de clientes</div>
            </a>
            <a href="clientes.php" class="kpi-card kpi-green">
                <div class="kpi-icon"><i class="fas fa-user-check"></i></div>
                <div class="kpi-num"><?= $clientes_activos ?></div>
                <div class="kpi-label">Clientes activos</div>
            </a>
            <a href="operaciones.php" class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                <div class="kpi-num"><?= $total_ops_credito ?></div>
                <div class="kpi-label">Trámites de crédito</div>
            </a>
            <a href="operaciones.php" class="kpi-card kpi-green" style="grid-column:span 1">
                <div class="kpi-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="kpi-num" style="font-size:22px;">$<?= number_format($monto_total, 0) ?></div>
                <div class="kpi-label">Monto total en créditos</div>
            </a>
            <a href="alertas.php" class="kpi-card <?= $alertas_pendientes > 0 ? 'kpi-red' : 'kpi-blue' ?>">
                <div class="kpi-icon"><i class="fas fa-bell"></i></div>
                <div class="kpi-num"><?= $alertas_pendientes ?></div>
                <div class="kpi-label">Alertas pendientes</div>
            </a>
        </div>

        <!-- GRID INFERIOR -->
        <div class="row g-3">

            <!-- Acceso Rápido -->
            <div class="col-md-5">
                <div class="section-card" style="height:100%;">
                    <div class="section-header">
                        <h5><i class="fas fa-bolt" style="color:var(--brand-yellow);"></i>Acceso Rápido</h5>
                    </div>
                    <div class="quick-grid">
                        <a href="registro_asesor.php"                  class="quick-btn q-yellow"><i class="fas fa-user-plus"></i>Crear Asesor</a>
                        <a href="clientes.php"                          class="quick-btn q-navy"><i class="fas fa-address-book"></i>Ver Clientes</a>
                        <a href="operaciones.php"                       class="quick-btn q-green"><i class="fas fa-handshake"></i>Operaciones</a>
                        <a href="mis_asesores.php"                      class="quick-btn q-blue"><i class="fas fa-users"></i>Mis Asesores</a>
                        <a href="alertas.php"                           class="quick-btn q-red"><i class="fas fa-bell"></i>Alertas <?= $alertas_pendientes > 0 ? "($alertas_pendientes)" : '' ?></a>
                        <a href="mapa_vivo_superIA.php"                 class="quick-btn q-navy"><i class="fas fa-map-marked-alt"></i>Mapa en Vivo</a>
                        <a href="reportes.php"                          class="quick-btn q-light"><i class="fas fa-chart-bar"></i>Reportes KPI</a>
                        <a href="administrar_solicitudes_asesor.php"    class="quick-btn q-light"><i class="fas fa-file-circle-check"></i>Solicitudes</a>
                    </div>
                </div>
            </div>

            <!-- Últimos clientes -->
            <div class="col-md-7">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-address-book" style="color:#3b82f6;"></i>Últimos Clientes</h5>
                        <a href="clientes.php" class="sec-link">Ver todos →</a>
                    </div>
                    <?php if (empty($ultimos_clientes)): ?>
                    <div class="empty-msg"><i class="fas fa-inbox"></i>Sin clientes registrados aún</div>
                    <?php else: ?>
                        <?php foreach ($ultimos_clientes as $cli):
                            $ini = '';
                            foreach (explode(' ', $cli['nombre']) as $p) { $ini .= strtoupper(mb_substr($p,0,1)); if (strlen($ini)>=2) break; }
                            $estClass = ['prospecto'=>'est-prospecto','cliente'=>'est-cliente','pendiente'=>'est-pendiente','descartado'=>'est-descartado'][$cli['estado']] ?? 'est-prospecto';
                        ?>
                        <a href="ver_cliente.php?id=<?= urlencode($cli['cedula'] ?? '') ?>" class="cli-row" style="text-decoration:none;">
                            <div class="cli-avatar"><?= htmlspecialchars($ini ?: '?') ?></div>
                            <div style="flex:1;min-width:0;">
                                <div class="cli-name"><?= htmlspecialchars($cli['nombre']) ?></div>
                                <div class="cli-sub"><?= htmlspecialchars($cli['asesor_nombre']) ?> · <?= htmlspecialchars($cli['ciudad'] ?? '—') ?> · <?= date('d/m/Y', strtotime($cli['created_at'])) ?></div>
                            </div>
                            <span class="cli-estado <?= $estClass ?>"><?= ucfirst($cli['estado']) ?></span>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actividad reciente (tareas) -->
            <div class="col-md-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-clock" style="color:#10b981;"></i>Actividad Reciente</h5>
                        <span class="sec-badge"><?= count($recientes) ?></span>
                    </div>
                    <?php if (empty($recientes)): ?>
                    <div class="empty-msg"><i class="fas fa-calendar-times"></i>Sin actividad registrada</div>
                    <?php else: ?>
                        <?php foreach ($recientes as $t):
                            $dotClass = $t['estado'] === 'completada' ? 'dot-ok' : ($t['estado'] === 'cancelada' ? 'dot-alert' : 'dot-pend');
                            $icon = $t['estado'] === 'completada' ? 'fa-check' : ($t['estado'] === 'cancelada' ? 'fa-times' : 'fa-clock');
                            $tipoLabel = ucfirst(str_replace('_',' ',$t['tipo_tarea']));
                        ?>
                        <div class="act-item">
                            <div class="act-dot <?= $dotClass ?>"><i class="fas <?= $icon ?>"></i></div>
                            <div class="act-body">
                                <div class="act-title"><?= htmlspecialchars($tipoLabel) ?><?= $t['cliente_nombre'] ? ' — ' . htmlspecialchars($t['cliente_nombre']) : '' ?></div>
                                <div class="act-meta">Asesor: <?= htmlspecialchars($t['asesor_nombre'] ?? '—') ?></div>
                            </div>
                            <div class="act-date"><?= $t['fecha_programada'] ? date('d/m', strtotime($t['fecha_programada'])) : '—' ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alertas recientes -->
            <div class="col-md-6">
                <div class="section-card">
                    <div class="section-header">
                        <h5><i class="fas fa-bell" style="color:#ef4444;"></i>Alertas Sin Ver</h5>
                        <a href="alertas.php" class="sec-link">Ver todas →</a>
                    </div>
                    <?php if (empty($ultimas_alertas)): ?>
                    <div class="empty-msg"><i class="fas fa-check-circle" style="color:#10b981;"></i>Sin alertas pendientes 🎉</div>
                    <?php else: ?>
                        <?php foreach ($ultimas_alertas as $al): ?>
                        <div class="act-item">
                            <div class="act-dot dot-alert"><i class="fas fa-exclamation"></i></div>
                            <div class="act-body">
                                <div class="act-title"><?= htmlspecialchars($al['campo_modificado'] ?? 'Modificación detectada') ?></div>
                                <div class="act-meta"><?= htmlspecialchars(mb_substr($al['valor_nuevo'] ?? '', 0, 80)) ?>…</div>
                                <div class="act-meta">Asesor: <?= htmlspecialchars($al['asesor_nombre'] ?? '—') ?></div>
                            </div>
                            <div class="act-date"><?= date('d/m H:i', strtotime($al['created_at'])) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /.row -->
    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</body>
</html>

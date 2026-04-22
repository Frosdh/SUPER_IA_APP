<?php
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id'];           // usuario.id (lo que guarda login)
$asesor_nombre     = $_SESSION['asesor_nombre'] ?? 'Asesor';

/* Resolver ID en tabla asesor */
$asesor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$asesor_usuario_id]);
    $asesor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

$hoy = date('Y-m-d');

/* ---------- KPIs ---------- */
$kpi = [
    'total_clientes'  => 0,
    'prospectos'      => 0,
    'tareas_hoy'      => 0,
    'tareas_completas'=> 0,
    'creditos_proceso'=> 0,
    'creditos_aprob'  => 0,
    'monto_aprobado'  => 0,
    'encuestas_hoy'   => 0,
];
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("
            SELECT
              (SELECT COUNT(*) FROM cliente_prospecto cp WHERE cp.asesor_id = :a) AS total_clientes,
              (SELECT COUNT(*) FROM cliente_prospecto cp WHERE cp.asesor_id = :a AND cp.estado='prospecto') AS prospectos,
              (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = :a AND t.fecha_programada = :hoy) AS tareas_hoy,
              (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = :a AND t.fecha_programada = :hoy AND t.estado='completada') AS tareas_completas,
              (SELECT COUNT(*) FROM credito_proceso c WHERE c.asesor_id = :a AND c.estado_credito NOT IN ('aprobado','desembolsado','rechazado')) AS creditos_proceso,
              (SELECT COUNT(*) FROM credito_proceso c WHERE c.asesor_id = :a AND c.estado_credito IN ('aprobado','desembolsado')) AS creditos_aprob,
              (SELECT COALESCE(SUM(c.monto_aprobado),0) FROM credito_proceso c WHERE c.asesor_id = :a AND c.estado_credito='desembolsado') AS monto_aprobado,
              (SELECT COUNT(*) FROM encuesta_crediticia ec
                 JOIN tarea t2 ON t2.id = ec.tarea_id
                 WHERE t2.asesor_id = :a AND DATE(ec.created_at) = :hoy) AS encuestas_hoy
        ");
        $st->execute([':a' => $asesor_table_id, ':hoy' => $hoy]);
        $kpi = array_merge($kpi, $st->fetch() ?: []);
    }
} catch (PDOException $e) { /* mantener defaults */ }

/* ---------- Tareas del día ---------- */
$tareas_dia = [];
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("
            SELECT t.*, cp.nombre AS cliente_nombre, cp.cedula, cp.telefono
            FROM tarea t
            LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE t.asesor_id = ? AND t.fecha_programada = ?
            ORDER BY (t.estado='completada') ASC, t.hora_programada ASC
            LIMIT 15
        ");
        $st->execute([$asesor_table_id, $hoy]);
        $tareas_dia = $st->fetchAll();
    }
} catch (PDOException $e) {}

$tareas_pendientes = 0;
foreach ($tareas_dia as $t) if (($t['estado'] ?? '') !== 'completada') $tareas_pendientes++;

/* ---------- Alertas pendientes (asesor: las suyas) ---------- */
$alertas_pendientes = 0;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE asesor_id = ? AND vista_supervisor = 0");
        $st->execute([$asesor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();
    }
} catch (PDOException $e) {}

/* ---------- Meta diaria (si existe) ---------- */
$meta_dia = null;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT * FROM meta_asesor_diaria WHERE asesor_id = ? AND fecha = ? LIMIT 1");
        $st->execute([$asesor_table_id, $hoy]);
        $meta_dia = $st->fetch() ?: null;
    }
} catch (PDOException $e) {}

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Panel — Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --brand-yellow:#ffdd00; --brand-yellow-deep:#f4c400;
            --brand-navy:#123a6d;   --brand-navy-deep:#0a2748;
            --brand-gray:#6b7280;   --brand-border:#d7e0ea;
            --brand-bg:#f4f6f9;
            --brand-shadow:0 16px 34px rgba(18,58,109,.08);
            --brand-shadow-sm:0 4px 12px rgba(18,58,109,.06);
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:var(--brand-bg);display:flex;min-height:100vh;color:var(--brand-navy-deep);}
        .sidebar{width:230px;background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%);color:#fff;padding:20px 0;overflow-y:auto;position:fixed;height:100vh;left:0;top:0;z-index:100;}
        .sidebar-brand{padding:0 20px 24px;font-size:18px;font-weight:800;border-bottom:1px solid rgba(255,221,0,.18);margin-bottom:20px;display:flex;align-items:center;gap:10px;}
        .sidebar-brand i{color:var(--brand-yellow);}
        .sidebar-section{padding:0 15px;margin-bottom:22px;}
        .sidebar-section-title{font-size:11px;text-transform:uppercase;color:rgba(255,255,255,.5);letter-spacing:.6px;padding:0 10px;margin-bottom:10px;font-weight:700;}
        .sidebar-link{display:flex;align-items:center;gap:12px;padding:11px 15px;margin-bottom:4px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:14px;border:1px solid transparent;transition:all .22s;position:relative;}
        .sidebar-link:hover{background:rgba(255,221,0,.12);color:#fff;padding-left:20px;border-color:rgba(255,221,0,.15);}
        .sidebar-link.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);font-weight:700;}
        .badge-nav{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:auto;}

        .main-content{flex:1;margin-left:230px;display:flex;flex-direction:column;min-width:0;}
        .navbar-custom{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 28px rgba(18,58,109,.18);position:sticky;top:0;z-index:50;}
        .navbar-custom h2{margin:0;font-size:19px;font-weight:700;display:flex;align-items:center;gap:10px;}
        .navbar-custom h2 i{color:var(--brand-yellow);}
        .user-info{display:flex;align-items:center;gap:14px;font-size:13px;}
        .btn-logout{background:rgba(255,221,0,.15);color:#fff;border:1px solid rgba(255,221,0,.28);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;}
        .btn-logout:hover{background:rgba(255,221,0,.26);color:#fff;}

        .content-area{flex:1;padding:24px 30px 40px;}

        .welcome-hero{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-radius:18px;padding:26px 30px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;gap:20px;box-shadow:var(--brand-shadow);position:relative;overflow:hidden;}
        .welcome-hero::before{content:"";position:absolute;right:-60px;top:-60px;width:240px;height:240px;background:radial-gradient(circle,rgba(255,221,0,.18),transparent 70%);}
        .welcome-hero h2{font-size:24px;font-weight:800;margin-bottom:6px;}
        .welcome-hero p{opacity:.85;font-size:14px;margin:0;}
        .welcome-hero .cta{position:relative;z-index:1;display:flex;gap:10px;flex-wrap:wrap;}
        .btn-cta{background:var(--brand-yellow);color:var(--brand-navy-deep);border:none;padding:12px 22px;border-radius:11px;font-weight:800;text-decoration:none;font-size:14px;display:inline-flex;align-items:center;gap:8px;box-shadow:0 6px 16px rgba(255,221,0,.4);}
        .btn-cta:hover{background:var(--brand-yellow-deep);color:var(--brand-navy-deep);transform:translateY(-1px);}
        .btn-cta.outline{background:transparent;color:#fff;border:1.5px solid rgba(255,255,255,.5);box-shadow:none;}
        .btn-cta.outline:hover{background:rgba(255,255,255,.1);}

        .kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px;}
        .kpi-card{background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:16px 18px;box-shadow:var(--brand-shadow-sm);display:flex;align-items:center;gap:14px;transition:.2s;}
        .kpi-card:hover{transform:translateY(-2px);box-shadow:var(--brand-shadow);}
        .kpi-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
        .ki-yellow{background:rgba(255,221,0,.18);color:#b58900;}
        .ki-blue{background:rgba(18,58,109,.10);color:var(--brand-navy);}
        .ki-green{background:rgba(16,185,129,.12);color:#059669;}
        .ki-purple{background:rgba(124,58,237,.10);color:#7c3aed;}
        .ki-red{background:rgba(239,68,68,.10);color:#dc2626;}
        .kpi-num{font-size:22px;font-weight:800;line-height:1;}
        .kpi-lbl{font-size:11.5px;color:var(--brand-gray);text-transform:uppercase;font-weight:700;letter-spacing:.3px;margin-top:4px;}

        .grid-2{display:grid;grid-template-columns:2fr 1fr;gap:18px;}
        @media (max-width:992px){ .grid-2{grid-template-columns:1fr;} }

        .panel{background:#fff;border:1px solid var(--brand-border);border-radius:16px;box-shadow:var(--brand-shadow-sm);overflow:hidden;}
        .panel-h{padding:14px 20px;border-bottom:1px solid var(--brand-border);background:#fafbfc;display:flex;align-items:center;gap:10px;}
        .panel-h h5{font-size:14.5px;font-weight:800;margin:0;flex:1;color:var(--brand-navy-deep);}
        .panel-h .h-ico{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;background:rgba(255,221,0,.18);color:#b58900;}
        .panel-h .h-action{font-size:12.5px;color:var(--brand-navy);text-decoration:none;font-weight:700;}
        .panel-h .h-action:hover{text-decoration:underline;}
        .panel-b{padding:14px 18px;}

        .task-row{display:flex;align-items:center;gap:12px;padding:11px 6px;border-bottom:1px solid #eef2f6;}
        .task-row:last-child{border-bottom:none;}
        .task-row .t-ico{width:38px;height:38px;border-radius:10px;background:rgba(18,58,109,.08);color:var(--brand-navy);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
        .task-row.done .t-ico{background:rgba(16,185,129,.12);color:#059669;}
        .task-row .t-info{flex:1;min-width:0;}
        .task-row .t-name{font-weight:700;font-size:14px;color:var(--brand-navy-deep);}
        .task-row .t-sub{font-size:12px;color:var(--brand-gray);margin-top:2px;}
        .task-row .t-action{flex-shrink:0;}
        .btn-mini{background:var(--brand-yellow);color:var(--brand-navy-deep);border:none;padding:6px 12px;border-radius:8px;font-weight:700;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
        .btn-mini:hover{background:var(--brand-yellow-deep);color:var(--brand-navy-deep);}
        .btn-mini.ghost{background:#f3f4f6;color:#374151;}
        .badge-tipo{font-size:10.5px;text-transform:uppercase;font-weight:800;letter-spacing:.3px;padding:2px 7px;border-radius:6px;background:#eef2f6;color:#4b5563;margin-left:8px;}

        .quick-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
        .qa-btn{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:14px;border-radius:12px;text-decoration:none;color:#fff;font-weight:700;font-size:13.5px;transition:.2s;}
        .qa-btn i{font-size:20px;}
        .qa-btn:hover{transform:translateY(-2px);color:#fff;}
        .qa-btn.yellow{background:linear-gradient(135deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);}
        .qa-btn.navy{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));}
        .qa-btn.green{background:linear-gradient(135deg,#059669,#10b981);}
        .qa-btn.purple{background:linear-gradient(135deg,#7c3aed,#8b5cf6);}

        .meta-card{background:linear-gradient(135deg,#fff,#f8fafc);border:1px solid var(--brand-border);border-radius:14px;padding:16px 18px;}
        .meta-card h6{font-size:12px;text-transform:uppercase;color:var(--brand-gray);font-weight:800;margin-bottom:10px;letter-spacing:.3px;}
        .meta-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:6px 0;border-bottom:1px dashed #e5e7eb;}
        .meta-row:last-child{border-bottom:none;}
        .meta-row b{color:var(--brand-navy-deep);}
        .meta-row .progress-mini{height:6px;width:80px;background:#e5e7eb;border-radius:3px;overflow:hidden;}
        .meta-row .progress-mini > div{height:100%;background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));}

        .empty{text-align:center;padding:30px 14px;color:#9ca3af;font-size:13.5px;}
        .empty i{font-size:30px;display:block;margin-bottom:8px;opacity:.6;}

        ::-webkit-scrollbar{width:8px;height:8px;}
        ::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px;}

        @media (max-width:768px){
            .sidebar{transform:translateX(-100%);}
            .main-content{margin-left:0;}
            .welcome-hero{flex-direction:column;text-align:center;}
            .quick-actions{grid-template-columns:1fr;}
            .content-area{padding:16px;}
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar_asesor.php'; ?>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-chart-bar"></i> Mi Panel — Asesor</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($asesor_nombre) ?></strong><br><small style="opacity:.7;">Asesor de campo</small></div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>

    <div class="content-area">

        <div class="welcome-hero">
            <div style="position:relative;z-index:1;">
                <h2>¡Hola, <?= htmlspecialchars(explode(' ', $asesor_nombre)[0]) ?>! 👋</h2>
                <p>Hoy es <strong><?= ucfirst(strftime('%A %d de %B', strtotime($hoy))) ?: date('d/m/Y') ?></strong>. Tienes <strong><?= $tareas_pendientes ?></strong> tarea<?= $tareas_pendientes===1?'':'s' ?> pendiente<?= $tareas_pendientes===1?'':'s' ?> en campo.</p>
            </div>
            <div class="cta">
                <a href="nueva_encuesta.php" class="btn-cta"><i class="fas fa-clipboard-list"></i> Nueva Encuesta</a>
                <a href="tareas_pendientes.php" class="btn-cta outline"><i class="fas fa-list-check"></i> Ver tareas</a>
            </div>
        </div>

        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon ki-blue"><i class="fas fa-address-book"></i></div>
                <div><div class="kpi-num"><?= (int)($kpi['total_clientes'] ?? 0) ?></div><div class="kpi-lbl">Mis clientes</div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ki-yellow"><i class="fas fa-user-plus"></i></div>
                <div><div class="kpi-num"><?= (int)($kpi['prospectos'] ?? 0) ?></div><div class="kpi-lbl">Prospectos</div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ki-green"><i class="fas fa-list-check"></i></div>
                <div><div class="kpi-num"><?= (int)($kpi['tareas_completas'] ?? 0) ?>/<?= (int)($kpi['tareas_hoy'] ?? 0) ?></div><div class="kpi-lbl">Tareas hoy</div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ki-purple"><i class="fas fa-clipboard-list"></i></div>
                <div><div class="kpi-num"><?= (int)($kpi['encuestas_hoy'] ?? 0) ?></div><div class="kpi-lbl">Encuestas hoy</div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ki-red"><i class="fas fa-handshake"></i></div>
                <div><div class="kpi-num"><?= (int)($kpi['creditos_proceso'] ?? 0) ?></div><div class="kpi-lbl">Créditos en proceso</div></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon ki-green"><i class="fas fa-dollar-sign"></i></div>
                <div><div class="kpi-num">$<?= number_format((float)($kpi['monto_aprobado'] ?? 0), 0) ?></div><div class="kpi-lbl">Desembolsado</div></div>
            </div>
        </div>

        <div class="grid-2">
            <!-- Tareas del día -->
            <div class="panel">
                <div class="panel-h">
                    <div class="h-ico"><i class="fas fa-calendar-day"></i></div>
                    <h5>Tareas y visitas de hoy</h5>
                    <a href="tareas_pendientes.php" class="h-action">Ver todas →</a>
                </div>
                <div class="panel-b">
                    <?php if (empty($tareas_dia)): ?>
                        <div class="empty"><i class="fas fa-mug-hot"></i>No tienes tareas asignadas para hoy. ¡Aprovecha para prospectar!</div>
                    <?php else: ?>
                        <?php foreach ($tareas_dia as $t):
                            $done = ($t['estado'] ?? '') === 'completada';
                            $tipoLabel = ucfirst(str_replace('_',' ', $t['tipo_tarea'] ?? '—'));
                        ?>
                            <div class="task-row <?= $done ? 'done' : '' ?>">
                                <div class="t-ico"><i class="fas <?= $done ? 'fa-check' : 'fa-clock' ?>"></i></div>
                                <div class="t-info">
                                    <div class="t-name">
                                        <?= htmlspecialchars($t['cliente_nombre'] ?? 'Cliente sin nombre') ?>
                                        <span class="badge-tipo"><?= htmlspecialchars($tipoLabel) ?></span>
                                    </div>
                                    <div class="t-sub">
                                        <?php if (!empty($t['hora_programada'])): ?><i class="far fa-clock"></i> <?= date('H:i', strtotime($t['hora_programada'])) ?> · <?php endif; ?>
                                        <?php if (!empty($t['cedula'])): ?>CI: <?= htmlspecialchars($t['cedula']) ?> · <?php endif; ?>
                                        <?php if (!empty($t['telefono'])): ?><i class="fas fa-phone"></i> <?= htmlspecialchars($t['telefono']) ?> · <?php endif; ?>
                                        <?= htmlspecialchars($t['ciudad'] ?? '') ?>
                                    </div>
                                </div>
                                <div class="t-action">
                                    <?php if ($done): ?>
                                        <a class="btn-mini ghost" href="aleta.php?id=<?= urlencode($t['cliente_prospecto_id'] ?? '') ?>"><i class="fas fa-eye"></i> Ver</a>
                                    <?php else: ?>
                                        <a class="btn-mini" href="nueva_encuesta.php?tarea_id=<?= urlencode($t['id']) ?>"><i class="fas fa-play"></i> Iniciar</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Acciones rápidas + meta del día -->
            <div>
                <div class="panel" style="margin-bottom:14px;">
                    <div class="panel-h">
                        <div class="h-ico"><i class="fas fa-bolt"></i></div>
                        <h5>Acciones rápidas</h5>
                    </div>
                    <div class="panel-b">
                        <div class="quick-actions">
                            <a href="nueva_encuesta.php" class="qa-btn yellow"><i class="fas fa-clipboard-list"></i><span>Nueva encuesta</span></a>
                            <a href="clientes.php" class="qa-btn navy"><i class="fas fa-address-book"></i><span>Mis clientes</span></a>
                            <a href="operaciones.php" class="qa-btn purple"><i class="fas fa-handshake"></i><span>Mis operaciones</span></a>
                            <a href="mapa_vivo_asesor.php" class="qa-btn green"><i class="fas fa-map-location-dot"></i><span>Mapa</span></a>
                        </div>
                    </div>
                </div>

                <?php if ($meta_dia): ?>
                <div class="meta-card">
                    <h6><i class="fas fa-bullseye me-1"></i> Mi meta de hoy</h6>
                    <?php
                    $items = [
                        'Encuestas'        => [(int)$meta_dia['meta_encuestas'],        (int)$kpi['encuestas_hoy']],
                        'Clientes nuevos'  => [(int)$meta_dia['meta_clientes_nuevos'],  null],
                        'Créditos'         => [(int)$meta_dia['meta_creditos'],         null],
                        'Cuenta ahorros'   => [(int)$meta_dia['meta_cuenta_ahorros'],   null],
                        'Cuenta corriente' => [(int)$meta_dia['meta_cuenta_corriente'], null],
                        'Inversiones'      => [(int)$meta_dia['meta_inversiones'],      null],
                    ];
                    foreach ($items as $lbl => [$meta, $hecho]):
                        if (!$meta) continue;
                        $h = $hecho ?? 0;
                        $pct = $meta > 0 ? min(100, round($h*100/$meta)) : 0;
                    ?>
                        <div class="meta-row">
                            <span><?= $lbl ?></span>
                            <span><b><?= $h ?></b> / <?= $meta ?> <span class="progress-mini"><div style="width:<?= $pct ?>%;"></div></span></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="meta-card">
                    <h6><i class="fas fa-bullseye me-1"></i> Mi meta de hoy</h6>
                    <div style="font-size:13px;color:var(--brand-gray);">Tu supervisor aún no asignó metas para hoy.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

</body>
</html>

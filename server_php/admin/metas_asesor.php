<?php
// ============================================================
// admin/metas_asesor.php — Mis Metas (Panel Asesor)
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id'];
$asesor_nombre     = $_SESSION['asesor_nombre'] ?? 'Asesor';
$hoy               = date('Y-m-d');
$mes               = (int)date('m');
$anio              = (int)date('Y');

// Resolver ID en tabla asesor
$asesor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$asesor_usuario_id]);
    $asesor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

// Meta del día actual
$meta_hoy = null;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT * FROM meta_asesor_diaria WHERE asesor_id = ? AND fecha = ? LIMIT 1");
        $st->execute([$asesor_table_id, $hoy]);
        $meta_hoy = $st->fetch() ?: null;
    }
} catch (PDOException $e) {}

// Historial de metas del mes (últimos 30 días)
$historial = [];
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("
            SELECT m.*,
                (SELECT COUNT(*) FROM encuesta_comercial ec JOIN tarea t ON t.id=ec.tarea_id
                 WHERE t.asesor_id=m.asesor_id AND DATE(ec.created_at)=m.fecha) +
                (SELECT COUNT(*) FROM encuesta_crediticia ecr JOIN tarea t ON t.id=ecr.tarea_id
                 WHERE t.asesor_id=m.asesor_id AND DATE(ecr.created_at)=m.fecha) AS avance_encuestas,
                (SELECT COUNT(*) FROM cliente_prospecto cp
                 WHERE cp.asesor_id=m.asesor_id AND DATE(cp.created_at)=m.fecha) AS avance_clientes,
                (SELECT COUNT(*) FROM credito_proceso cr
                 WHERE cr.asesor_id=m.asesor_id AND DATE(cr.created_at)=m.fecha) AS avance_creditos
            FROM meta_asesor_diaria m
            WHERE m.asesor_id = ? AND m.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY m.fecha DESC
        ");
        $st->execute([$asesor_table_id]);
        $historial = $st->fetchAll();
    }
} catch (PDOException $e) {}

// KPI acumulado del mes
$kpi_mes = ['tareas_completadas' => 0, 'encuestas' => 0, 'clientes_nuevos' => 0, 'creditos' => 0];
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id=:a AND t.estado='completada'
                 AND MONTH(t.fecha_programada)=:m AND YEAR(t.fecha_programada)=:y) AS tareas_completadas,
                (SELECT COUNT(*) FROM encuesta_crediticia ec JOIN tarea t ON t.id=ec.tarea_id
                 WHERE t.asesor_id=:a AND MONTH(ec.created_at)=:m AND YEAR(ec.created_at)=:y) +
                (SELECT COUNT(*) FROM encuesta_comercial ec2 JOIN tarea t2 ON t2.id=ec2.tarea_id
                 WHERE t2.asesor_id=:a AND MONTH(ec2.created_at)=:m AND YEAR(ec2.created_at)=:y) AS encuestas,
                (SELECT COUNT(*) FROM cliente_prospecto cp WHERE cp.asesor_id=:a
                 AND MONTH(cp.created_at)=:m AND YEAR(cp.created_at)=:y) AS clientes_nuevos,
                (SELECT COUNT(*) FROM credito_proceso cr WHERE cr.asesor_id=:a
                 AND MONTH(cr.created_at)=:m AND YEAR(cr.created_at)=:y) AS creditos
        ");
        $st->execute([':a' => $asesor_table_id, ':m' => $mes, ':y' => $anio]);
        $kpi_mes = array_merge($kpi_mes, $st->fetch() ?: []);
    }
} catch (PDOException $e) {}

$alertas_pendientes = 0;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE asesor_id=? AND vista_supervisor=0");
        $st->execute([$asesor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();
    }
} catch (PDOException $e) {}

$tareas_pendientes = 0;
$currentPage = 'metas';

$meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Metas — Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{
            --brand-yellow:#ffdd00; --brand-yellow-deep:#f4c400;
            --brand-navy:#123a6d;   --brand-navy-deep:#0a2748;
            --brand-gray:#6b7280;   --brand-border:#d7e0ea;
            --brand-bg:#f4f6f9;
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:var(--brand-bg);display:flex;min-height:100vh;color:var(--brand-navy-deep);}

        /* ── Sidebar ── */
        .sidebar{width:230px;background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%);color:#fff;padding:20px 0;overflow-y:auto;position:fixed;height:100vh;left:0;top:0;z-index:100;}
        .sidebar-brand{padding:0 20px 24px;font-size:18px;font-weight:800;border-bottom:1px solid rgba(255,221,0,.18);margin-bottom:20px;display:flex;align-items:center;gap:10px;}
        .sidebar-brand i{color:var(--brand-yellow);}
        .sidebar-section{padding:0 15px;margin-bottom:22px;}
        .sidebar-section-title{font-size:11px;text-transform:uppercase;color:rgba(255,255,255,.5);letter-spacing:.6px;padding:0 10px;margin-bottom:10px;font-weight:700;}
        .sidebar-link{display:flex;align-items:center;gap:12px;padding:11px 15px;margin-bottom:4px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:14px;border:1px solid transparent;transition:all .22s;position:relative;}
        .sidebar-link:hover{background:rgba(255,221,0,.12);color:#fff;padding-left:20px;border-color:rgba(255,221,0,.15);}
        .sidebar-link.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);font-weight:700;}
        .badge-nav{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:auto;}

        /* ── Layout ── */
        .main-content{flex:1;margin-left:230px;display:flex;flex-direction:column;min-width:0;}
        .navbar-custom{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 28px rgba(18,58,109,.18);position:sticky;top:0;z-index:50;}
        .navbar-custom h2{margin:0;font-size:19px;font-weight:700;display:flex;align-items:center;gap:10px;}
        .navbar-custom h2 i{color:var(--brand-yellow);}
        .user-info{display:flex;align-items:center;gap:14px;font-size:13px;}
        .btn-logout{background:rgba(255,221,0,.15);color:#fff;border:1px solid rgba(255,221,0,.28);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;}
        .btn-logout:hover{background:rgba(255,221,0,.26);color:#fff;}
        .content-area{flex:1;padding:24px 30px 40px;}

        /* ── Cards ── */
        .panel{background:#fff;border:1px solid var(--brand-border);border-radius:16px;box-shadow:0 4px 12px rgba(18,58,109,.06);overflow:hidden;margin-bottom:22px;}
        .panel-h{padding:14px 20px;border-bottom:1px solid var(--brand-border);background:#fafbfc;display:flex;align-items:center;gap:10px;}
        .panel-h h5{font-size:14.5px;font-weight:800;margin:0;flex:1;color:var(--brand-navy-deep);}
        .panel-h .h-ico{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;background:rgba(255,221,0,.18);color:#b58900;}
        .panel-b{padding:18px 20px;}

        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px;}
        .kpi-card{background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:18px;box-shadow:0 4px 12px rgba(18,58,109,.06);display:flex;align-items:center;gap:14px;}
        .kpi-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
        .ki-yellow{background:rgba(255,221,0,.18);color:#b58900;}
        .ki-blue{background:rgba(18,58,109,.10);color:var(--brand-navy);}
        .ki-green{background:rgba(16,185,129,.12);color:#059669;}
        .ki-purple{background:rgba(124,58,237,.10);color:#7c3aed;}
        .kpi-num{font-size:26px;font-weight:800;line-height:1;color:var(--brand-navy-deep);}
        .kpi-lbl{font-size:11.5px;color:var(--brand-gray);text-transform:uppercase;font-weight:700;letter-spacing:.3px;margin-top:4px;}

        /* ── Meta del día ── */
        .meta-hoy-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
        .meta-item{background:#f8fafc;border:1px solid var(--brand-border);border-radius:12px;padding:16px;}
        .meta-item .label{font-size:11.5px;color:var(--brand-gray);text-transform:uppercase;font-weight:700;margin-bottom:8px;}
        .meta-item .valores{display:flex;align-items:baseline;gap:6px;}
        .meta-item .actual{font-size:28px;font-weight:800;color:var(--brand-navy-deep);}
        .meta-item .separador{font-size:18px;color:#cbd5e1;}
        .meta-item .objetivo{font-size:18px;color:var(--brand-gray);}
        .meta-item .progress-bar-wrap{height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;margin-top:10px;}
        .meta-item .progress-bar-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));transition:width .4s;}
        .meta-item .progress-bar-fill.completo{background:linear-gradient(90deg,#10b981,#059669);}

        /* ── Historial ── */
        .hist-table{width:100%;border-collapse:collapse;font-size:13.5px;}
        .hist-table th{background:#f1f5f9;color:var(--brand-navy-deep);font-weight:700;font-size:11.5px;text-transform:uppercase;letter-spacing:.3px;padding:10px 12px;border-bottom:2px solid var(--brand-border);}
        .hist-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
        .hist-table tr:hover td{background:#fafbff;}
        .badge-estado{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase;}
        .badge-cumple{background:rgba(16,185,129,.12);color:#059669;}
        .badge-pendiente{background:rgba(255,221,0,.2);color:#b58900;}
        .badge-no-cumple{background:rgba(239,68,68,.1);color:#dc2626;}

        .empty{text-align:center;padding:40px;color:#9ca3af;font-size:14px;}
        .empty i{font-size:36px;display:block;margin-bottom:10px;opacity:.5;}

        @media(max-width:768px){
            .sidebar{transform:translateX(-100%);}
            .main-content{margin-left:0;}
            .content-area{padding:16px;}
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/_sidebar_asesor.php'; ?>

<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><i class="fas fa-bullseye"></i> Mis Metas</h2>
        <div class="user-info">
            <div style="text-align:right;">
                <strong><?= htmlspecialchars($asesor_nombre) ?></strong><br>
                <small style="opacity:.75;">Asesor de campo</small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i> Salir</a>
        </div>
    </div>

    <div class="content-area">

        <!-- KPIs del mes -->
        <div class="panel">
            <div class="panel-h">
                <div class="h-ico"><i class="fas fa-chart-bar"></i></div>
                <h5>Resumen del mes — <?= $meses_es[$mes] ?> <?= $anio ?></h5>
            </div>
            <div class="panel-b">
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-icon ki-green"><i class="fas fa-check-circle"></i></div>
                        <div><div class="kpi-num"><?= (int)$kpi_mes['tareas_completadas'] ?></div><div class="kpi-lbl">Tareas completadas</div></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon ki-purple"><i class="fas fa-clipboard-list"></i></div>
                        <div><div class="kpi-num"><?= (int)$kpi_mes['encuestas'] ?></div><div class="kpi-lbl">Encuestas realizadas</div></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon ki-blue"><i class="fas fa-user-plus"></i></div>
                        <div><div class="kpi-num"><?= (int)$kpi_mes['clientes_nuevos'] ?></div><div class="kpi-lbl">Clientes captados</div></div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-icon ki-yellow"><i class="fas fa-handshake"></i></div>
                        <div><div class="kpi-num"><?= (int)$kpi_mes['creditos'] ?></div><div class="kpi-lbl">Créditos iniciados</div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meta del día de hoy -->
        <div class="panel">
            <div class="panel-h">
                <div class="h-ico"><i class="fas fa-calendar-day"></i></div>
                <h5>Meta de hoy — <?= date('d/m/Y') ?></h5>
            </div>
            <div class="panel-b">
                <?php if ($meta_hoy): ?>
                    <?php
                    // Calcular avances del día
                    $av_enc = 0; $av_cli = 0; $av_cred = 0; $av_ahorro = 0; $av_cc = 0; $av_inv = 0;
                    try {
                        if ($asesor_table_id) {
                            $st = $pdo->prepare("
                                SELECT
                                  (SELECT COUNT(*) FROM encuesta_comercial ec JOIN tarea t ON t.id=ec.tarea_id WHERE t.asesor_id=:a AND DATE(ec.created_at)=:h)
                                 +(SELECT COUNT(*) FROM encuesta_crediticia ecr JOIN tarea t2 ON t2.id=ecr.tarea_id WHERE t2.asesor_id=:a AND DATE(ecr.created_at)=:h) AS enc,
                                  (SELECT COUNT(*) FROM cliente_prospecto cp WHERE cp.asesor_id=:a AND DATE(cp.created_at)=:h) AS cli,
                                  (SELECT COUNT(*) FROM credito_proceso cr WHERE cr.asesor_id=:a AND DATE(cr.created_at)=:h) AS cred
                            ");
                            $st->execute([':a' => $asesor_table_id, ':h' => $hoy]);
                            $av = $st->fetch();
                            $av_enc = (int)($av['enc'] ?? 0);
                            $av_cli = (int)($av['cli'] ?? 0);
                            $av_cred = (int)($av['cred'] ?? 0);
                        }
                    } catch (PDOException $e) {}

                    $items = [
                        ['label' => 'Encuestas',         'av' => $av_enc,  'meta' => (int)$meta_hoy['meta_encuestas'],       'ico' => 'fa-clipboard-list'],
                        ['label' => 'Clientes nuevos',   'av' => $av_cli,  'meta' => (int)$meta_hoy['meta_clientes_nuevos'], 'ico' => 'fa-user-plus'],
                        ['label' => 'Créditos',          'av' => $av_cred, 'meta' => (int)$meta_hoy['meta_creditos'],        'ico' => 'fa-handshake'],
                        ['label' => 'Ctas. Ahorros',     'av' => 0,        'meta' => (int)$meta_hoy['meta_cuenta_ahorros'],  'ico' => 'fa-piggy-bank'],
                        ['label' => 'Ctas. Corriente',   'av' => 0,        'meta' => (int)$meta_hoy['meta_cuenta_corriente'],'ico' => 'fa-credit-card'],
                        ['label' => 'Inversiones',       'av' => 0,        'meta' => (int)$meta_hoy['meta_inversiones'],     'ico' => 'fa-chart-line'],
                    ];
                    ?>
                    <div class="meta-hoy-grid">
                        <?php foreach ($items as $item): ?>
                        <?php
                        $pct = $item['meta'] > 0 ? min(100, round($item['av'] / $item['meta'] * 100)) : ($item['av'] > 0 ? 100 : 0);
                        $completo = $item['meta'] > 0 && $item['av'] >= $item['meta'];
                        ?>
                        <div class="meta-item">
                            <div class="label"><i class="fas <?= $item['ico'] ?> me-1"></i><?= $item['label'] ?></div>
                            <div class="valores">
                                <span class="actual" style="color:<?= $completo ? '#059669' : 'var(--brand-navy-deep)' ?>"><?= $item['av'] ?></span>
                                <span class="separador">/</span>
                                <span class="objetivo"><?= $item['meta'] ?></span>
                            </div>
                            <?php if ($item['meta'] > 0): ?>
                            <div class="progress-bar-wrap">
                                <div class="progress-bar-fill <?= $completo ? 'completo' : '' ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                            <div style="font-size:11px;color:var(--brand-gray);margin-top:4px;"><?= $pct ?>% completado</div>
                            <?php else: ?>
                            <div style="font-size:11px;color:#cbd5e1;margin-top:6px;">Sin meta asignada</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($meta_hoy['observaciones'])): ?>
                    <div style="margin-top:16px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:13px;color:#92400e;">
                        <i class="fas fa-note-sticky me-2"></i><strong>Nota del supervisor:</strong> <?= htmlspecialchars($meta_hoy['observaciones']) ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty"><i class="fas fa-calendar-xmark"></i>No tienes meta asignada para hoy.<br><small>Tu supervisor aún no ha configurado tus objetivos del día.</small></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Historial de metas (últimos 30 días) -->
        <div class="panel">
            <div class="panel-h">
                <div class="h-ico"><i class="fas fa-clock-rotate-left"></i></div>
                <h5>Historial — últimos 30 días</h5>
            </div>
            <div class="panel-b" style="padding:0;">
                <?php if (empty($historial)): ?>
                    <div class="empty"><i class="fas fa-inbox"></i>No hay registros de metas aún.</div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Encuestas</th>
                            <th>Clientes</th>
                            <th>Créditos</th>
                            <th>Ahorros</th>
                            <th>C. Corriente</th>
                            <th>Inversiones</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                        <?php
                        $av_e = (int)($h['avance_encuestas'] ?? 0);
                        $av_c = (int)($h['avance_clientes'] ?? 0);
                        $av_cr = (int)($h['avance_creditos'] ?? 0);
                        $meta_e = (int)$h['meta_encuestas'];
                        $meta_c = (int)$h['meta_clientes_nuevos'];
                        $meta_cr = (int)$h['meta_creditos'];
                        // Estado simple: si cumplió todas las metas con valor > 0
                        $metas_con_valor = ($meta_e > 0 ? 1 : 0) + ($meta_c > 0 ? 1 : 0) + ($meta_cr > 0 ? 1 : 0);
                        $metas_cumplidas = ($meta_e > 0 && $av_e >= $meta_e ? 1 : 0) + ($meta_c > 0 && $av_c >= $meta_c ? 1 : 0) + ($meta_cr > 0 && $av_cr >= $meta_cr ? 1 : 0);
                        if ($metas_con_valor === 0) { $badge = 'badge-pendiente'; $estado_txt = 'Sin meta'; }
                        elseif ($metas_cumplidas === $metas_con_valor) { $badge = 'badge-cumple'; $estado_txt = 'Cumplido'; }
                        elseif ($metas_cumplidas > 0) { $badge = 'badge-pendiente'; $estado_txt = 'Parcial'; }
                        else { $badge = 'badge-no-cumple'; $estado_txt = 'No cumplido'; }
                        ?>
                        <tr>
                            <td><strong><?= date('d/m/Y', strtotime($h['fecha'])) ?></strong></td>
                            <td><?= $av_e ?>/<?= $meta_e ?></td>
                            <td><?= $av_c ?>/<?= $meta_c ?></td>
                            <td><?= $av_cr ?>/<?= $meta_cr ?></td>
                            <td>–/<?= (int)$h['meta_cuenta_ahorros'] ?></td>
                            <td>–/<?= (int)$h['meta_cuenta_corriente'] ?></td>
                            <td>–/<?= (int)$h['meta_inversiones'] ?></td>
                            <td><span class="badge-estado <?= $badge ?>"><?= $estado_txt ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /content-area -->
</div><!-- /main-content -->
</body>
</html>

<?php
require_once 'db_admin.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login_selector.php'); exit;
}

$gerente_usuario_id = $_SESSION['admin_id']    ?? null;
$gerente_nombre     = $_SESSION['admin_nombre'] ?? 'Gerente';
$gerente_rol        = $_SESSION['admin_rol']    ?? 'jefe_agencia';
$supervisor_usuario_id = trim($_GET['id'] ?? '');

if (!$supervisor_usuario_id) { header('Location: mis_supervisores.php'); exit; }

$mensaje_exito = '';
$mensaje_error = '';

// ── Verificar que el supervisor pertenece a este gerente ────
try {
    $st = $pdo->prepare("
        SELECT s.id AS supervisor_table_id
        FROM supervisor s
        JOIN jefe_agencia ja ON ja.id = s.jefe_agencia_id
        WHERE s.usuario_id = ?
        LIMIT 1
    ");
    $st->execute([$supervisor_usuario_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { header('Location: mis_supervisores.php'); exit; }
    $supervisor_table_id = $row['supervisor_table_id'];
} catch (\Throwable $e) { header('Location: mis_supervisores.php'); exit; }

// ── Cargar datos ───────────────────────────────────────────
$stUsr = $pdo->prepare("SELECT * FROM usuario WHERE id = ? LIMIT 1");
$stUsr->execute([$supervisor_usuario_id]);
$usr = $stUsr->fetch(PDO::FETCH_ASSOC);
if (!$usr) { header('Location: mis_supervisores.php'); exit; }

$stSup = $pdo->prepare("SELECT * FROM supervisor WHERE id = ? LIMIT 1");
$stSup->execute([$supervisor_table_id]);
$sup = $stSup->fetch(PDO::FETCH_ASSOC);

// ── Estadísticas del supervisor ────────────────────────────
$stats = [
    'asesores'      => 0,
    'clientes'      => 0,
    'tareas_hoy'    => 0,
    'alertas_sin_ver' => 0,
];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE a.supervisor_id=? AND u.activo=1");
    $st->execute([$supervisor_table_id]);
    $stats['asesores'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM asesor a JOIN cliente_prospecto cp ON cp.asesor_id=a.id WHERE a.supervisor_id=?");
    $st->execute([$supervisor_table_id]);
    $stats['clientes'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM tarea t JOIN asesor a ON a.id=t.asesor_id WHERE a.supervisor_id=? AND t.fecha_programada=CURDATE() AND t.estado='completada'");
    $st->execute([$supervisor_table_id]);
    $stats['tareas_hoy'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id=? AND vista_supervisor=0");
    $st->execute([$supervisor_table_id]);
    $stats['alertas_sin_ver'] = (int)$st->fetchColumn();
} catch (\Throwable $e) {}

$currentPage = 'supervisores';

$nombre_supervisor = htmlspecialchars($usr['nombre'] ?? '');
$inicial = strtoupper(mb_substr($usr['nombre'] ?? 'S', 0, 1));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Perfil Supervisor — <?= $nombre_supervisor ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
<style>
.page-header{display:flex;align-items:center;gap:14px;margin-bottom:28px;padding-bottom:18px;border-bottom:2px solid #e8eef6;}
.page-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0a2748,#1e4d8c);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.page-icon i{color:#60a5fa;font-size:22px;}
.page-title{font-size:22px;font-weight:900;color:#0a2748;margin:0;}
.page-sub{font-size:13px;color:#94a3b8;margin:2px 0 0;font-weight:500;}
.back-btn{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:7px 16px;font-size:13px;font-weight:600;color:#0a2748;text-decoration:none;}
.back-btn:hover{box-shadow:0 4px 12px rgba(10,39,72,.12);color:#0a2748;}
.profile-banner{background:linear-gradient(135deg,#0a2748 0%,#1e4d8c 100%);border-radius:18px;padding:28px;display:flex;align-items:center;gap:20px;margin-bottom:22px;}
.profile-avatar{width:68px;height:68px;border-radius:50%;background:#60a5fa;color:#fff;font-size:26px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:3px solid rgba(255,255,255,.2);}
.profile-banner h2{margin:0;color:#fff;font-size:20px;font-weight:800;}
.profile-banner p{margin:3px 0 0;color:#94a3b8;font-size:13px;}
.pill-activo{background:#dcfce7;color:#166534;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;margin-top:8px;}
.pill-inactivo{background:#fee2e2;color:#991b1b;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;margin-top:8px;}
.card{background:#fff;border-radius:18px;box-shadow:0 4px 20px rgba(10,39,72,.07);padding:28px;margin-bottom:22px;}
.card-title{font-size:14px;font-weight:800;color:#0a2748;margin:0 0 16px;display:flex;align-items:center;gap:8px;padding-bottom:10px;border-bottom:1px solid #f1f5f9;}
.card-title i{color:#60a5fa;font-size:16px;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.stat-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px 16px;text-align:center;}
.stat-val{font-size:28px;font-weight:900;color:#0a2748;line-height:1;}
.stat-lbl{font-size:11px;color:#64748b;font-weight:700;margin-top:4px;text-transform:uppercase;letter-spacing:.3px;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.info-item{display:flex;flex-direction:column;gap:3px;}
.info-item label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;}
.info-item .val{font-size:15px;font-weight:600;color:#0a2748;}
.btn-sm{padding:5px 12px;border-radius:8px;font-size:.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;}
.btn-outline{background:rgba(96,165,250,.08);color:#3b82f6;border:1px solid rgba(96,165,250,.2);}
.btn-outline:hover{background:rgba(96,165,250,.2);}
@media(max-width:700px){.stats-grid{grid-template-columns:1fr 1fr;}.info-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php require_once '_sidebar_gerente.php'; ?>

<div class="main-content" style="padding:28px 32px;">

    <div class="page-header">
        <div class="page-icon"><i class="fas fa-users-gear"></i></div>
        <div style="flex:1;">
            <h1 class="page-title">Perfil del Supervisor</h1>
            <p class="page-sub">Información y estadísticas del supervisor a tu cargo</p>
        </div>
        <a href="mis_supervisores.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Volver a Mis Supervisores
        </a>
    </div>

    <div class="profile-banner">
        <div class="profile-avatar"><?= $inicial ?></div>
        <div>
            <h2><?= $nombre_supervisor ?></h2>
            <p><?= htmlspecialchars($usr['email'] ?? '') ?></p>
            <?php if ($usr['activo']): ?>
                <span class="pill-activo"><i class="fas fa-circle" style="font-size:7px;"></i> Activo</span>
            <?php else: ?>
                <span class="pill-inactivo"><i class="fas fa-circle" style="font-size:7px;"></i> Inactivo</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-chart-simple"></i> Estadísticas del equipo</div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-val" style="color:#3b82f6;"><?= $stats['asesores'] ?></div>
                <div class="stat-lbl">Asesores activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:#22c55e;"><?= $stats['clientes'] ?></div>
                <div class="stat-lbl">Clientes totales</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:#a78bfa;"><?= $stats['tareas_hoy'] ?></div>
                <div class="stat-lbl">Tareas hoy</div>
            </div>
            <div class="stat-card">
                <div class="stat-val" style="color:#ef4444;"><?= $stats['alertas_sin_ver'] ?></div>
                <div class="stat-lbl">Alertas sin ver</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-address-card"></i> Información del supervisor</div>
        <div class="info-grid">
            <div class="info-item">
                <label>Nombre completo</label>
                <div class="val"><?= htmlspecialchars($usr['nombre'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <label>Correo electrónico</label>
                <div class="val"><?= htmlspecialchars($usr['email'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <label>Teléfono</label>
                <div class="val"><?= htmlspecialchars($usr['telefono'] ?? '—') ?></div>
            </div>
            <div class="info-item">
                <label>Rol</label>
                <div class="val">Supervisor</div>
            </div>
            <div class="info-item">
                <label>Meta de asesores</label>
                <div class="val"><?= (int)($sup['meta_asesores'] ?? 0) ?></div>
            </div>
            <div class="info-item">
                <label>Estado</label>
                <div class="val"><?= $usr['activo'] ? 'Activo' : 'Inactivo' ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-title"><i class="fas fa-bolt"></i> Acciones rápidas</div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <a href="mis_asesores.php?supervisor_id=<?= urlencode($supervisor_table_id) ?>" class="btn-sm btn-outline">
                <i class="fas fa-users"></i> Ver sus asesores
            </a>
            <a href="alertas.php?supervisor_id=<?= urlencode($supervisor_table_id) ?>" class="btn-sm btn-outline">
                <i class="fas fa-bell"></i> Ver alertas
            </a>
            <a href="mapa_vivo_superIA.php?supervisor_id=<?= urlencode($supervisor_table_id) ?>" class="btn-sm btn-outline">
                <i class="fas fa-map-marked-alt"></i> Ver en mapa
            </a>
        </div>
    </div>

</div>
</body>
</html>

<?php
require_once 'db_admin.php';

// Verificar sesión de super_admin, admin, supervisor o asesor
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
    $user_id = $_SESSION['super_admin_id'];
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
    $user_id = $_SESSION['admin_id'];
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
    $user_id = $_SESSION['supervisor_id'];
    // Obtener el id real del supervisor desde la tabla supervisor (si es necesario)
    // Suponiendo que $_SESSION['supervisor_id'] es el UUID de la tabla supervisor
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    $user_id = $_SESSION['asesor_id']; // ID de la tabla asesor
} else {
    header('Location: login.php?role=admin');
    exit;
}

// ── Resolver supervisor.id real desde la sesión (usuario_id) ─────
$supervisor_table_id = null;
if ($user_role === 'supervisor') {
    try {
        $stSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $stSup->execute([$user_id]);
        $supervisor_table_id = $stSup->fetchColumn() ?: $user_id;
    } catch (PDOException $e) { $supervisor_table_id = $user_id; }
}

// ── Resolver asesor.id real para asesor (sesión guarda usuario.id) ─
$asesor_table_id = null;
if ($user_role === 'asesor') {
    try {
        $stAs = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $stAs->execute([$user_id]);
        $asesor_table_id = $stAs->fetchColumn() ?: null;
    } catch (PDOException $e) { $asesor_table_id = null; }
}

// ── CSRF token (solo para acciones POST) ────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── Migración no destructiva: estado de revisión de fichas ───
try {
    $exists = $pdo->query("SHOW TABLES LIKE 'ficha_producto'")->fetchColumn();
    if ($exists) {
        $cols = [
            'estado_revision'        => "ALTER TABLE ficha_producto ADD COLUMN estado_revision ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente' AFTER producto_tipo",
            'revision_usuario_id'    => "ALTER TABLE ficha_producto ADD COLUMN revision_usuario_id CHAR(36) DEFAULT NULL AFTER estado_revision",
            'revision_at'            => "ALTER TABLE ficha_producto ADD COLUMN revision_at DATETIME DEFAULT NULL AFTER revision_usuario_id",
            'revision_observaciones' => "ALTER TABLE ficha_producto ADD COLUMN revision_observaciones TEXT DEFAULT NULL AFTER revision_at",
        ];
        foreach ($cols as $c => $ddl) {
            $st = $pdo->prepare("SHOW COLUMNS FROM ficha_producto LIKE ?");
            $st->execute([$c]);
            if (!$st->fetch()) {
                $pdo->exec($ddl);
            }
        }
    }
} catch (PDOException $e) {
    // silencioso
}

// ── Procesar aprobación/rechazo (solo solicitudes desde ficha) ─
$mensaje_exito = '';
$mensaje_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrf_token, $token)) {
        $mensaje_error = 'Solicitud inválida.';
    } else {
        $accion    = $_POST['accion'] ?? '';
        $origen    = $_POST['origen'] ?? '';
        $idCredito = $_POST['id_credito'] ?? '';
        $obs       = trim((string)($_POST['observaciones'] ?? ''));

        if ($origen === 'ficha' && is_string($idCredito) && $idCredito !== '' && ($accion === 'aprobar' || $accion === 'rechazar')) {
            if (!in_array($user_role, ['super_admin', 'admin', 'supervisor'], true)) {
                $mensaje_error = 'No autorizado.';
            } else {
                try {
                    // Verificar acceso según rol
                    if ($user_role === 'supervisor') {
                        $stOwn = $pdo->prepare(
                            "SELECT fp.id
                             FROM ficha_producto fp
                             JOIN asesor a
                               ON (a.id = fp.asesor_id)
                               OR (a.usuario_id = fp.usuario_id)
                               OR (a.id = fp.usuario_id)
                             WHERE fp.id = ? AND fp.producto_tipo = 'credito' AND a.supervisor_id = ?
                             LIMIT 1"
                        );
                        $stOwn->execute([$idCredito, $supervisor_table_id]);
                        if (!$stOwn->fetchColumn()) {
                            throw new Exception('No tienes permiso para procesar esta solicitud.');
                        }
                    } else {
                        $stOwn = $pdo->prepare("SELECT id FROM ficha_producto WHERE id = ? AND producto_tipo = 'credito' LIMIT 1");
                        $stOwn->execute([$idCredito]);
                        if (!$stOwn->fetchColumn()) {
                            throw new Exception('Solicitud no encontrada.');
                        }
                    }

                    $nuevoEstado = ($accion === 'aprobar') ? 'aprobada' : 'rechazada';

                    $stUp = $pdo->prepare(
                        "UPDATE ficha_producto
                         SET estado_revision = ?,
                             revision_usuario_id = ?,
                             revision_at = NOW(),
                             revision_observaciones = ?
                         WHERE id = ? AND producto_tipo = 'credito' AND estado_revision = 'pendiente'"
                    );
                    $stUp->execute([$nuevoEstado, (string)$user_id, $obs, $idCredito]);

                    if ($stUp->rowCount() > 0) {
                        $mensaje_exito = ($accion === 'aprobar') ? 'Solicitud aprobada.' : 'Solicitud rechazada.';
                    } else {
                        $mensaje_error = 'No se pudo actualizar (quizá ya fue procesada).';
                    }
                } catch (Throwable $e) {
                    $mensaje_error = $e->getMessage();
                }
            }
        }
    }
}

$col_asesor = ($user_role !== 'asesor');
$operaciones = [];

// ═══════════════════════════════════════════════════════════
// FUENTE 1 — credito_proceso (procesos formales en sistema)
// ═══════════════════════════════════════════════════════════
try {
    if ($user_role === 'super_admin' || $user_role === 'admin') {
        $q = "SELECT cp.id as id_credito, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                     cp.monto_aprobado as cantidad, cp.estado_credito as estado,
                     cp.created_at as fecha_creacion, u.nombre as asesor_nombre, 'proceso' as origen
              FROM credito_proceso cp
              JOIN cliente_prospecto cl ON cp.cliente_prospecto_id = cl.id
              LEFT JOIN asesor a ON cp.asesor_id = a.id
              LEFT JOIN usuario u ON a.usuario_id = u.id
              ORDER BY cp.created_at DESC";
        $st = $pdo->query($q);
    } elseif ($user_role === 'supervisor') {
        $q = "SELECT cp.id as id_credito, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                     cp.monto_aprobado as cantidad, cp.estado_credito as estado,
                     cp.created_at as fecha_creacion, u.nombre as asesor_nombre, 'proceso' as origen
              FROM credito_proceso cp
              JOIN cliente_prospecto cl ON cp.cliente_prospecto_id = cl.id
              LEFT JOIN asesor a ON cp.asesor_id = a.id
              LEFT JOIN usuario u ON a.usuario_id = u.id
              WHERE a.supervisor_id = ?
              ORDER BY cp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$supervisor_table_id]);
    } else {
        $q = "SELECT cp.id as id_credito, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                     cp.monto_aprobado as cantidad, cp.estado_credito as estado,
                     cp.created_at as fecha_creacion, NULL as asesor_nombre, 'proceso' as origen
              FROM credito_proceso cp
              JOIN cliente_prospecto cl ON cp.cliente_prospecto_id = cl.id
              WHERE cp.asesor_id = ?
              ORDER BY cp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$asesor_table_id]);
    }
    $operaciones = array_merge($operaciones, $st->fetchAll());
} catch (PDOException $e) { /* tabla puede no existir aún */ }

// ═══════════════════════════════════════════════════════════
// FUENTE 2 — ficha_producto + ficha_credito (encuesta móvil)
// ═══════════════════════════════════════════════════════════
try {
    if ($user_role === 'super_admin' || $user_role === 'admin') {
        $q = "SELECT fp.id as id_credito,
                     COALESCE(cp.nombre, fp.cliente_nombre) as cliente_nombre,
                     COALESCE(cp.cedula, fp.cliente_cedula) as cliente_cedula,
                     fc.monto_credito as cantidad,
                     CASE
                         WHEN fp.estado_revision = 'aprobada'  THEN 'aprobado'
                         WHEN fp.estado_revision = 'rechazada' THEN 'rechazado'
                         ELSE 'solicitud_ficha'
                     END as estado,
                     fp.created_at as fecha_creacion,
                     u.nombre as asesor_nombre,
                     'ficha' as origen
              FROM ficha_producto fp
              JOIN ficha_credito fc ON fc.ficha_id = fp.id
              LEFT JOIN asesor a ON (a.id = fp.asesor_id)
                               OR (a.usuario_id = fp.usuario_id)
                               OR (a.id = fp.usuario_id)
              LEFT JOIN usuario u ON u.id = a.usuario_id
              LEFT JOIN cliente_prospecto cp ON cp.cedula = fp.cliente_cedula
              WHERE fp.producto_tipo = 'credito'
              ORDER BY fp.created_at DESC";
        $st = $pdo->query($q);
    } elseif ($user_role === 'supervisor') {
        $q = "SELECT fp.id as id_credito,
                     COALESCE(cp.nombre, fp.cliente_nombre) as cliente_nombre,
                     COALESCE(cp.cedula, fp.cliente_cedula) as cliente_cedula,
                     fc.monto_credito as cantidad,
                     CASE
                         WHEN fp.estado_revision = 'aprobada'  THEN 'aprobado'
                         WHEN fp.estado_revision = 'rechazada' THEN 'rechazado'
                         ELSE 'solicitud_ficha'
                     END as estado,
                     fp.created_at as fecha_creacion,
                     u.nombre as asesor_nombre,
                     'ficha' as origen
              FROM ficha_producto fp
              JOIN ficha_credito fc ON fc.ficha_id = fp.id
              LEFT JOIN asesor a ON (a.id = fp.asesor_id)
                               OR (a.usuario_id = fp.usuario_id)
                               OR (a.id = fp.usuario_id)
              LEFT JOIN usuario u ON u.id = a.usuario_id
              LEFT JOIN cliente_prospecto cp ON cp.cedula = fp.cliente_cedula
              WHERE fp.producto_tipo = 'credito'
                AND a.supervisor_id = ?
              ORDER BY fp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$supervisor_table_id]);
    } else {
        $q = "SELECT fp.id as id_credito,
                     COALESCE(cp.nombre, fp.cliente_nombre) as cliente_nombre,
                     COALESCE(cp.cedula, fp.cliente_cedula) as cliente_cedula,
                     fc.monto_credito as cantidad,
                     CASE
                         WHEN fp.estado_revision = 'aprobada'  THEN 'aprobado'
                         WHEN fp.estado_revision = 'rechazada' THEN 'rechazado'
                         ELSE 'solicitud_ficha'
                     END as estado,
                     fp.created_at as fecha_creacion,
                     NULL as asesor_nombre,
                     'ficha' as origen
              FROM ficha_producto fp
              JOIN ficha_credito fc ON fc.ficha_id = fp.id
              LEFT JOIN asesor a ON (a.id = fp.asesor_id)
                               OR (a.usuario_id = fp.usuario_id)
                               OR (a.id = fp.usuario_id)
              LEFT JOIN cliente_prospecto cp ON cp.cedula = fp.cliente_cedula
              WHERE fp.producto_tipo = 'credito'
                AND (fp.usuario_id = ? OR fp.asesor_id = ?)
              ORDER BY fp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$user_id, ($asesor_table_id ?: $user_id)]);
    }
    $operaciones = array_merge($operaciones, $st->fetchAll());
} catch (PDOException $e) { /* ficha_credito puede no existir aún */ }

// ── Ordenar combinado por fecha desc ──────────────────────
usort($operaciones, fn($a, $b) => strtotime($b['fecha_creacion']) - strtotime($a['fecha_creacion']));

// ── Estadísticas combinadas ───────────────────────────────
$total_ops    = count($operaciones);
$completadas  = count(array_filter($operaciones, fn($o) => in_array($o['estado'] ?? '', ['desembolsado','aprobado'])));
$monto_total  = array_sum(array_map(fn($o) => is_numeric($o['cantidad'] ?? '') ? floatval($o['cantidad']) : 0, $operaciones));
$stats = [
    'total_operaciones' => $total_ops,
    'completadas'       => $completadas,
    'monto_total'       => $monto_total,
];

$currentPage        = 'operaciones';
$alertas_pendientes = $alertas_pendientes ?? 0;
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
$is_supervisor_ui   = ($user_role === 'supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Operaciones de Crédito</title>
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
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: var(--brand-card); padding: 20px; border-radius: 16px; box-shadow: var(--brand-shadow); text-align: center; border: 1px solid var(--brand-border); }
        .stat-card .number { font-size: 32px; font-weight: 800; color: var(--brand-navy-deep); }
        .stat-card .label { color: var(--brand-gray); font-size: 13px; margin-top: 5px; }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); }
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
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #6b11ff, #7c3aed); color: #fff; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) {
            .sidebar { width: 200px; }
            .main-content { margin-left: 200px; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 180px; }
            .main-content { margin-left: 180px; }
        }
        .navbar-custom { background: linear-gradient(135deg, #6b11ff, #3182fe); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: 700; color: #1f2937; }
        .stat-card .label { color: #9ca3af; font-size: 13px; margin-top: 5px; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        .badge-completed { background: #10b981; }
        .badge-pending { background: #f59e0b; }
        .badge-prospect { background: #3b82f6; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php endif; ?>
    </style>
</head>
<body>

<?php if ($user_role === 'supervisor'): require_once '_sidebar_supervisor.php'; else: ?>
<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <?php if ($user_role === 'super_admin'): ?>
        <a href="super_admin_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php elseif ($user_role === 'admin'): ?>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php elseif ($user_role === 'supervisor'): ?>
        <a href="supervisor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php else: ?>
        <a href="asesor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php endif; ?>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
        <a href="usuarios.php" class="sidebar-link">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <?php endif; ?>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link active">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>

    <?php if ($user_role === 'supervisor'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Mi Equipo</div>
        <a href="mis_asesores.php" class="sidebar-link">
            <i class="fas fa-users"></i> Mis Asesores
        </a>
        <a href="registro_asesor.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link">
            <i class="fas fa-file-circle-check"></i> Solicitudes de Asesor
        </a>
    </div>
    <?php endif; ?>
    
    <?php if ($user_role === 'super_admin'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link">
            <i class="fas fa-file-alt"></i> Solicitudes de Admin
        </a>
    </div>
    <?php endif; ?>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Configuración</div>
        <a href="#" class="sidebar-link">
            <i class="fas fa-cog"></i> Configuración
        </a>
    </div>
</div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2>
            <?php if ($user_role === 'super_admin'): ?>
                👑 Super_IA - SuperAdministrador
            <?php elseif ($user_role === 'admin'): ?>
                🎯 Super_IA - Admin
            <?php elseif ($user_role === 'supervisor'): ?>
                👔 Super_IA - Supervisor
            <?php else: ?>
                👤 Super_IA - Asesor
            <?php endif; ?>
        </h2>
        <div class="user-info">
            <div>
                <strong>
                    <?php 
                    if ($user_role === 'super_admin') {
                        echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    } elseif ($user_role === 'admin') {
                        echo htmlspecialchars($_SESSION['admin_nombre']);
                    } elseif ($user_role === 'supervisor') {
                        echo htmlspecialchars($_SESSION['supervisor_nombre']);
                    } else {
                        echo htmlspecialchars($_SESSION['asesor_nombre']);
                    }
                    ?>
                </strong><br>
                <small>
                    <?php 
                    if ($user_role === 'super_admin') {
                        echo htmlspecialchars($_SESSION['super_admin_rol']);
                    } elseif ($user_role === 'admin') {
                        echo htmlspecialchars($_SESSION['admin_rol']);
                    } elseif ($user_role === 'supervisor') {
                        echo htmlspecialchars($_SESSION['supervisor_rol']);
                    } else {
                        echo htmlspecialchars($_SESSION['asesor_rol']);
                    }
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-handshake me-2"></i>Operaciones de Crédito</h1>
        </div>

        <?php if (!empty($mensaje_exito)): ?>
            <div class="alert alert-success" role="alert" style="border-radius:12px;">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($mensaje_exito) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($mensaje_error)): ?>
            <div class="alert alert-danger" role="alert" style="border-radius:12px;">
                <i class="fas fa-triangle-exclamation me-2"></i><?= htmlspecialchars($mensaje_error) ?>
            </div>
        <?php endif; ?>

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_operaciones']; ?></div>
                <div class="label">Total de Operaciones</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #10b981;"><?php echo $stats['completadas']; ?></div>
                <div class="label">Desembolsadas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #3182fe;">$<?php echo number_format($stats['monto_total'] ?? 0, 2); ?></div>
                <div class="label">Monto Total Aprobado</div>
            </div>
        </div>

        <!-- TABLA DE OPERACIONES -->
        <div class="table-card">
            <div class="card-header-custom">
                <h6>📊 Listado de Procesos de Crédito</h6>
            </div>
            
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Cliente</th>
                        <th>Cédula</th>
                        <?php if ($col_asesor): ?>
                        <th>Asesor Asignado</th>
                        <?php endif; ?>
                        <th>Monto Aprobado</th>
                        <th>Estado</th>
                        <th>Fecha Creación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($operaciones)): ?>
                    <tr>
                        <td colspan="<?php echo $col_asesor ? 8 : 7; ?>" class="text-center py-4">
                            <i class="fas fa-inbox me-2" style="color: #d1d5db;"></i>No hay operaciones de crédito registradas
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($operaciones as $op): ?>
                        <?php
                        $estado = strtolower($op['estado'] ?? 'prospectado');
                        $origen = $op['origen'] ?? 'proceso';
                        switch ($estado) {
                            case 'desembolsado':
                                $badgeStyle = 'background:#10b981;color:#fff;';
                                $label = '✓ Desembolsado'; break;
                            case 'aprobado':
                                $badgeStyle = 'background:#22c55e;color:#fff;';
                                $label = '✓ Aprobado'; break;
                            case 'rechazado':
                                $badgeStyle = 'background:#ef4444;color:#fff;';
                                $label = '✗ Rechazado'; break;
                            case 'solicitud_ficha':
                                $badgeStyle = 'background:#3b82f6;color:#fff;';
                                $label = '📋 Solicitud (encuesta)'; break;
                            default:
                                $badgeStyle = 'background:#f59e0b;color:#fff;';
                                $label = '⏳ ' . ucfirst(str_replace('_', ' ', $estado));
                        }
                        $monto = is_numeric($op['cantidad'] ?? '') ? number_format(floatval($op['cantidad']), 2) : '—';
                        // Link: usar cedula cuando está disponible (ambos orígenes)
                        $link = !empty($op['cliente_cedula'])
                            ? 'ver_cliente.php?cedula=' . urlencode($op['cliente_cedula'])
                            : 'ver_cliente.php?id=' . urlencode($op['id_credito'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars(substr($op['id_credito'], 0, 8)); ?>…</strong>
                                <?php if ($origen === 'ficha'): ?>
                                <br><small style="color:#3b82f6;font-size:10px;font-weight:700;">ENCUESTA MÓVIL</small>
                                <?php else: ?>
                                <br><small style="color:#9ca3af;font-size:10px;">PROCESO</small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($op['cliente_nombre'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($op['cliente_cedula'] ?? 'N/A'); ?></td>
                            <?php if ($col_asesor): ?>
                            <td><?php echo htmlspecialchars($op['asesor_nombre'] ?? 'Sin asignar'); ?></td>
                            <?php endif; ?>
                            <td><strong><?php echo $monto !== '—' ? '$' . $monto : '—'; ?></strong></td>
                            <td>
                                <span class="badge" style="<?php echo $badgeStyle; ?> padding:4px 10px;border-radius:6px;font-size:12px;">
                                    <?php echo $label; ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($op['fecha_creacion'])); ?></td>
                            <td>
                                <?php
                                // Usar cédula para ambos orígenes cuando esté disponible
                                if (!empty($op['cliente_cedula'])) {
                                    $eye_href = 'ver_cliente.php?cedula=' . urlencode($op['cliente_cedula']);
                                } else {
                                    $eye_href = 'ver_cliente.php?id=' . urlencode($op['id_credito'] ?? '');
                                }

                                $canProcesar = ($origen === 'ficha' && $estado === 'solicitud_ficha' && in_array($user_role, ['super_admin', 'admin', 'supervisor'], true));
                                ?>

                                <a href="<?= $eye_href ?>" class="btn btn-sm btn-outline-primary" title="Ver detalles del cliente">
                                    <i class="fas fa-eye"></i>
                                </a>

                                <?php if ($canProcesar): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="origen" value="ficha">
                                        <input type="hidden" name="id_credito" value="<?= htmlspecialchars((string)($op['id_credito'] ?? '')) ?>">
                                        <input type="hidden" name="accion" value="aprobar">
                                        <button type="submit" class="btn btn-sm btn-success" title="Aprobar" onclick="return confirm('¿Aprobar esta solicitud de crédito?');">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>

                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="origen" value="ficha">
                                        <input type="hidden" name="id_credito" value="<?= htmlspecialchars((string)($op['id_credito'] ?? '')) ?>">
                                        <input type="hidden" name="accion" value="rechazar">
                                        <input type="hidden" name="observaciones" value="">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Rechazar" onclick="var obs = prompt('Motivo de rechazo (opcional):'); if (obs === null) return false; this.form.observaciones.value = obs; return confirm('¿Rechazar esta solicitud de crédito?');">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
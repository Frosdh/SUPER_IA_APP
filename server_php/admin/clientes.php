<?php
require_once 'db_admin.php';

function table_exists_pdo(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function column_exists_pdo(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function cliente_es_cliente_por_aprobacion(PDO $pdo, ?string $clienteId, ?string $cedula): bool {
    // Si ya está marcado como cliente en BD, ok
    // (La regla final la decide la UI/negocio, no solo el campo estado)
    $cedula = $cedula ? trim($cedula) : '';
    $clienteId = $clienteId ? trim($clienteId) : '';

    try {
        // 1) Aprobación de fichas (cualquier producto)
        if ($cedula && table_exists_pdo($pdo, 'ficha_producto') && column_exists_pdo($pdo, 'ficha_producto', 'estado_revision')) {
            $st = $pdo->prepare("SELECT 1 FROM ficha_producto WHERE cliente_cedula = ? AND estado_revision = 'aprobada' LIMIT 1");
            $st->execute([$cedula]);
            if ($st->fetchColumn()) return true;
        }

        // 2) Crédito formal aprobado/desembolsado
        if ($clienteId && table_exists_pdo($pdo, 'credito_proceso')) {
            // Compatibilidad de nombres de columna en algunos despliegues
            $has_estado_credito = column_exists_pdo($pdo, 'credito_proceso', 'estado_credito');
            $has_estado = column_exists_pdo($pdo, 'credito_proceso', 'estado');
            $estadoCol = $has_estado_credito ? 'estado_credito' : ($has_estado ? 'estado' : null);
            if ($estadoCol) {
                $st = $pdo->prepare("SELECT 1 FROM credito_proceso WHERE cliente_prospecto_id = ? AND $estadoCol IN ('aprobado','desembolsado') LIMIT 1");
                $st->execute([$clienteId]);
                if ($st->fetchColumn()) return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

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
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    $user_id = $_SESSION['asesor_id'];
} else {
    header('Location: login.php?role=admin');
    exit;
}

// Construir query según el rol del usuario
// Maps to SUPER_IA LOGAN schema (cliente_prospecto, usuario, asesor tables)
$clientes = [];
$stats = ['total_clientes' => 0, 'clientes_activos' => 0, 'clientes_inactivos' => 0];
$col_asesor = false;
$asesor_table_id = null;
$alertas_pendientes = 0;
$tareas_pendientes = 0;


try {
    if ($user_role === 'super_admin' || $user_role === 'admin') {
        // SuperAdmin y Admin ven todos los clientes
        $query = "
            SELECT cp.id, cp.nombre, cp.cedula, cp.email, cp.telefono, cp.telefono2 as celular, cp.estado,
                   CONCAT_WS(' - ', cp.zona, cp.ciudad) as region, 
                   CASE WHEN cp.estado = 'descartado' THEN 0 ELSE 1 END as activo,
                   cp.created_at as fecha_creacion, 
                   u.nombre as asesor_nombre
            FROM cliente_prospecto cp
            LEFT JOIN asesor a ON cp.asesor_id = a.id
            LEFT JOIN usuario u ON a.usuario_id = u.id
            ORDER BY cp.created_at DESC
        ";
        $col_asesor = true;
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $clientes = $stmt->fetchAll();
    } elseif ($user_role === 'supervisor') {
        // Supervisor ve clientes de sus asesores
        // En login.php, $_SESSION['supervisor_id'] guarda usuario.id (no supervisor.id)
        $supervisor_usuario_id = $user_id;
        $stmtSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = :uid LIMIT 1');
        $stmtSup->execute([':uid' => $supervisor_usuario_id]);
        $supervisor_table_id = $stmtSup->fetchColumn();
        if (!$supervisor_table_id) {
            $clientes = [];
            $stats = ['total_clientes' => 0, 'clientes_activos' => 0, 'clientes_inactivos' => 0];
        } else {
        $query = "
            SELECT cp.id, cp.nombre, cp.cedula, cp.email, cp.telefono, cp.telefono2 as celular, cp.estado,
                   CONCAT_WS(' - ', cp.zona, cp.ciudad) as region,
                   CASE WHEN cp.estado = 'descartado' THEN 0 ELSE 1 END as activo,
                   cp.created_at as fecha_creacion, 
                   u.nombre as asesor_nombre
            FROM cliente_prospecto cp
            LEFT JOIN asesor a ON cp.asesor_id = a.id
            LEFT JOIN usuario u ON a.usuario_id = u.id
            WHERE a.supervisor_id = :supervisor_id
            ORDER BY cp.created_at DESC
        ";
        $col_asesor = true;
        $stmt = $pdo->prepare($query);
        $stmt->execute([':supervisor_id' => $supervisor_table_id]);
        $clientes = $stmt->fetchAll();
        }
    } else {
        // Asesor ve solo sus clientes
        // En login.php, $_SESSION['asesor_id'] guarda usuario.id (no asesor.id)
        $asesor_usuario_id = $user_id;
        $stmtAs = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = :uid LIMIT 1');
        $stmtAs->execute([':uid' => $asesor_usuario_id]);
        $asesor_table_id = $stmtAs->fetchColumn();
        if (!$asesor_table_id) {
            $clientes = [];
            $stats = ['total_clientes' => 0, 'clientes_activos' => 0, 'clientes_inactivos' => 0];
        } else {
        $query = "
            SELECT cp.id, cp.nombre, cp.cedula, cp.email, cp.telefono, cp.telefono2 as celular, cp.estado,
                   CONCAT_WS(' - ', cp.zona, cp.ciudad) as region,
                   CASE WHEN cp.estado = 'descartado' THEN 0 ELSE 1 END as activo,
                   cp.created_at as fecha_creacion,
                   u.nombre as asesor_nombre
            FROM cliente_prospecto cp
            LEFT JOIN asesor a ON cp.asesor_id = a.id
            LEFT JOIN usuario u ON a.usuario_id = u.id
            WHERE cp.asesor_id = :asesor_id
            ORDER BY cp.created_at DESC
        ";
        $col_asesor = true;
        $stmt = $pdo->prepare($query);
        $stmt->execute([':asesor_id' => $asesor_table_id]);
        $clientes = $stmt->fetchAll();
        }
    }

    // Estadísticas según el rol
    if ($user_role === 'super_admin' || $user_role === 'admin') {
        $stats_query = "
            SELECT 
                COUNT(*) as total_clientes,
                SUM(CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END) as clientes_activos,
                SUM(CASE WHEN cp.estado = 'descartado' THEN 1 ELSE 0 END) as clientes_inactivos
            FROM cliente_prospecto cp
        ";
        $stmt = $pdo->prepare($stats_query);
        $stmt->execute();
        $stats = $stmt->fetch();
    } elseif ($user_role === 'supervisor') {
        // En login.php, $_SESSION['supervisor_id'] guarda usuario.id
        $supervisor_usuario_id = $user_id;
        $stmtSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = :uid LIMIT 1');
        $stmtSup->execute([':uid' => $supervisor_usuario_id]);
        $supervisor_table_id = $stmtSup->fetchColumn();
        $stats_query = "
            SELECT 
                COUNT(*) as total_clientes,
                SUM(CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END) as clientes_activos,
                SUM(CASE WHEN cp.estado = 'descartado' THEN 1 ELSE 0 END) as clientes_inactivos
            FROM cliente_prospecto cp
            LEFT JOIN asesor a ON cp.asesor_id = a.id
            WHERE a.supervisor_id = :supervisor_id
        ";
        $stmt = $pdo->prepare($stats_query);
        $stmt->execute([':supervisor_id' => $supervisor_table_id ?: '']);
        $stats = $stmt->fetch();
    } else {
        // En login.php, $_SESSION['asesor_id'] guarda usuario.id
        $asesor_usuario_id = $user_id;
        $stmtAs = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = :uid LIMIT 1');
        $stmtAs->execute([':uid' => $asesor_usuario_id]);
        $asesor_table_id = $stmtAs->fetchColumn();
        $stats_query = "
            SELECT 
                COUNT(*) as total_clientes,
                SUM(CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END) as clientes_activos,
                SUM(CASE WHEN cp.estado = 'descartado' THEN 1 ELSE 0 END) as clientes_inactivos
            FROM cliente_prospecto cp
            WHERE cp.asesor_id = :asesor_id
        ";
        $stmt = $pdo->prepare($stats_query);
        $stmt->execute([':asesor_id' => $asesor_table_id ?: '']);
        $stats = $stmt->fetch();
    }

    
} catch (PDOException $e) {
    error_log("Clientes Query Error: " . $e->getMessage());
    // Provide fallback: empty data instead of fatal error
    $clientes = [];
    $stats = [
        'total_clientes' => 0,
        'clientes_activos' => 0,
        'clientes_inactivos' => 0
    ];
}

$currentPage        = 'clientes';
$alertas_pendientes = $alertas_pendientes ?? 0;
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
$is_supervisor_ui   = ($user_role === 'supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Clientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
<?php if ($user_role === 'supervisor' || $user_role === 'asesor'): ?>
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
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--brand-bg); display: flex; height: 100vh; color: var(--brand-navy-deep); }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; z-index: 100; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 22px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.5); letter-spacing: 0.6px; padding: 0 10px; margin-bottom: 10px; font-weight: 700; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 11px 15px; margin-bottom: 4px; border-radius: 10px; color: rgba(255,255,255,0.82); text-decoration: none; font-size: 14px; border: 1px solid transparent; transition: all .22s; position: relative; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .badge-nav { background:#dc2626; color:#fff; font-size:10px; font-weight:800; padding:2px 7px; border-radius:10px; margin-left:auto; }
        
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); position: sticky; top: 0; z-index: 50; }
        .navbar-custom h2 { margin: 0; font-size: 19px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .navbar-custom h2 i { color: var(--brand-yellow); }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; font-size: 13px; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 800; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: var(--brand-gray); border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: rgba(215,224,234,0.55); }
        .table tbody tr:hover { background: rgba(255,221,0,0.06); }
        ::-webkit-scrollbar { width: 6px; }
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
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        .badge-success { background: #10b981; }
        .badge-danger { background: #ef4444; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php endif; ?>
    </style>
</head>
<body>
<?php 
// ── Lógica para Sidebar Asesor ────────────────────────────────
if ($user_role === 'asesor') {
    $tareas_pendientes = 0;
    try {
        if (isset($asesor_table_id) && $asesor_table_id) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id = ? AND fecha_programada = CURRENT_DATE AND estado != 'completada'");
            $st->execute([$asesor_table_id]);
            $tareas_pendientes = (int)$st->fetchColumn();

        }
    } catch (PDOException $e) {}
}
if ($user_role === 'supervisor') {
    $navTitle = 'Mis Clientes'; $navIcon = 'fas fa-address-book'; 
    require_once '_sidebar_supervisor.php'; 
} elseif ($user_role === 'asesor') {
    $asesor_nombre = $_SESSION['asesor_nombre'] ?? 'Asesor';
    require_once '_sidebar_asesor.php';
} else {
    // Sidebar genérico para otros roles (Admin / SuperAdmin)
?>
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-chart-pie"></i><span>Super_IA</span></div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">PRINCIPAL</div>
        <a href="index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa</a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">GESTIÓN</div>
        <a href="clientes.php" class="sidebar-link active"><i class="fas fa-briefcase"></i> Clientes</a>
        <a href="operaciones.php" class="sidebar-link"><i class="fas fa-handshake"></i> Operaciones</a>
        <a href="alertas.php" class="sidebar-link"><i class="fas fa-bell"></i> Alertas</a>
    </div>
</div>
<?php } ?>


<?php if ($user_role !== 'supervisor'): ?>
<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <?php if ($user_role === 'asesor'): ?>
            <h2><i class="fas fa-address-book me-2" style="color: var(--brand-yellow);"></i> Mis Clientes — Asesor</h2>
        <?php else: ?>
            <h2><?php echo $user_role === 'super_admin' ? '👑' : '🎯'; ?> Super_IA 
                <?php 
                if ($user_role === 'super_admin') echo '- SuperAdmin';
                elseif ($user_role === 'admin') echo '- Admin';
                ?>
            </h2>
        <?php endif; ?>

        <div class="user-info">
            <div style="text-align: right;">
                <strong style="display:block;">
                    <?php 
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_nombre']);
                    else echo htmlspecialchars($_SESSION['asesor_nombre']);
                    ?>
                </strong>
                <small style="opacity:0.7;">
                    <?php 
                    if ($user_role === 'super_admin') echo 'SuperAdministrador';
                    elseif ($user_role === 'admin') echo 'Administrador';
                    else echo 'Asesor de campo';
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
<?php endif; ?>

        <div class="page-header d-flex justify-content-between align-items-end mb-4">
            <div>
                <h1 class="fw-800" style="color: var(--brand-navy-deep, #0a2748);"><i class="fas fa-address-book me-2 text-primary"></i>Directorio de Clientes</h1>
                <p class="text-muted mt-2 mb-0">Gestiona y filtra todos los clientes y prospectos de tu equipo.</p>
            </div>
        </div>
        
        <!-- KPI Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #3b82f6 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Total Clientes</div>
                        <h3 class="m-0 fw-800" style="color:var(--brand-navy-deep, #0a2748);"><?= $stats['total_clientes'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #10b981 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Activos / Clientes</div>
                        <h3 class="m-0 fw-800 text-success"><?= $stats['clientes_activos'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #ef4444 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Descartados</div>
                        <h3 class="m-0 fw-800 text-danger"><?= $stats['clientes_inactivos'] ?? 0 ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-card bg-white" style="border-radius:20px; border:1px solid #e2e8f0; box-shadow:0 10px 30px rgba(0,0,0,0.05);">
            <div class="card-header-custom p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3" style="background:#fff;">
                <div>
                    <h5 class="m-0 fw-800 d-flex align-items-center gap-2" style="color:var(--brand-navy-deep, #0a2748);">
                        <i class="fas fa-list-ul text-primary"></i> Listado Completo
                    </h5>
                    <small id="cntResultados" class="text-muted fw-semibold" style="font-size: 11px; margin-left: 28px;"><?= count($clientes) ?> clientes en total</small>
                </div>
                <div class="search-box" style="flex:1; max-width:500px;">
                    <div class="input-group shadow-sm" style="border-radius:12px; border:2px solid #f1f5f9; overflow:hidden;">
                        <span class="input-group-text bg-white border-0 text-primary"><i class="fas fa-search"></i></span>
                        <input type="text" id="searchClients" class="form-control border-0 bg-white shadow-none fw-semibold" placeholder="Buscar cliente por nombre o cédula..." style="font-size:14px; padding: 10px;">
                    </div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th class="ps-4 py-3 text-muted" style="font-size:11px; letter-spacing:0.5px;">CLIENTE</th>
                            <th class="py-3 text-muted" style="font-size:11px; letter-spacing:0.5px;">CONTACTO</th>
                            <th class="py-3 text-muted" style="font-size:11px; letter-spacing:0.5px;">ASESOR</th>
                            <th class="py-3 text-muted" style="font-size:11px; letter-spacing:0.5px;">FECHA REGISTRO</th>
                            <th class="py-3 text-muted text-center" style="font-size:11px; letter-spacing:0.5px;">ESTADO</th>
                            <th class="text-end pe-4 py-3 text-muted" style="font-size:11px; letter-spacing:0.5px;">ACCIÓN</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (empty($clientes)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5">
                                <div class="text-muted mb-3"><i class="fas fa-user-slash fa-3x opacity-25"></i></div>
                                <h6 class="fw-bold text-muted">Aún no tienes clientes o prospectos registrados</h6>
                                <p class="small text-muted">Comienza realizando una nueva encuesta en campo.</p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($clientes as $cliente): 
                            $estadoDb = strtolower((string)($cliente['estado'] ?? ''));
                            $esDescartado = ($estadoDb === 'descartado');
                            $esCliente = !$esDescartado && cliente_es_cliente_por_aprobacion($pdo, (string)($cliente['id'] ?? ''), (string)($cliente['cedula'] ?? ''));
                        ?>
                        <tr class="client-row" style="transition:all 0.2s ease;">
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="avatar-circle <?= $esDescartado ? 'bg-danger' : ($esCliente ? 'bg-success' : 'bg-warning') ?> bg-opacity-10 text-<?= $esDescartado ? 'danger' : ($esCliente ? 'success' : 'warning') ?> d-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:48px; height:48px; font-size:16px; border: 2px solid #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                                        <?= strtoupper(substr(trim($cliente['nombre'] ?? 'U'), 0, 2)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-800 text-navy client-name" style="font-size:15px;"><?php echo htmlspecialchars($cliente['nombre']); ?></div>
                                        <div class="text-muted small client-cedula fw-medium"><i class="fas fa-id-card me-1 opacity-50"></i> <?php echo htmlspecialchars($cliente['cedula'] ?? '—'); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <div class="fw-bold text-dark" style="font-size:13.5px;"><i class="fas fa-phone-alt text-primary me-2 opacity-75" style="font-size:11px;"></i> <?php echo htmlspecialchars($cliente['celular'] ?? ($cliente['telefono'] ?? '—')); ?></div>
                                    <?php if(!empty($cliente['email'])): ?>
                                        <div class="text-muted" style="font-size:11.5px;"><i class="fas fa-envelope me-2 opacity-50" style="font-size:11px;"></i> <?php echo htmlspecialchars($cliente['email']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="text-muted small fw-bold text-uppercase" style="font-size:10px; letter-spacing:0.3px;"><?php echo htmlspecialchars($cliente['region'] ?? 'Sin ubicación'); ?></div>
                                <div class="text-navy fw-bold" style="font-size:12px;"><i class="fas fa-user-tie me-1 text-primary opacity-50"></i> <?php echo htmlspecialchars($cliente['asesor_nombre'] ?? 'Propio'); ?></div>
                            </td>
                            <td>
                                <div class="text-muted small fw-medium"><i class="far fa-calendar-check me-1 opacity-50"></i> Registrado el</div>
                                <div class="text-navy fw-bold" style="font-size:13px;"><?php echo date('d M, Y', strtotime($cliente['fecha_creacion'])); ?></div>
                            </td>
                            <td class="text-center">
                                <?php
                                    if ($esDescartado) {
                                        echo '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-10 px-3 py-2 w-100" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-times-circle me-1"></i> DESCARTADO</span>';
                                    } elseif ($esCliente) {
                                        echo '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-10 px-3 py-2 w-100" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-check-circle me-1"></i> CLIENTE ACTIVO</span>';
                                    } else {
                                        echo '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-10 px-3 py-2 w-100" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-clock me-1"></i> PROSPECTO</span>';
                                    }
                                ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="ver_cliente.php?id=<?= urlencode($cliente['id'] ?? '') ?>" class="btn btn-sm shadow-sm px-3 py-2" style="border-radius:10px; font-weight:800; font-size:12px; background: var(--brand-navy); color: #fff; border:none; transition:0.2s;">
                                    DETALLES <i class="fas fa-chevron-right ms-1" style="font-size:10px;"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputBusqueda = document.getElementById('searchClients');
    const cntResultados = document.getElementById('cntResultados');

    if (inputBusqueda) {
        inputBusqueda.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('table tbody tr:not(#emptyFiltered)');
            let visibles = 0;
            
            rows.forEach(row => {
                // Si la fila es la de "No hay clientes para mostrar" (vacía de origen), la ignoramos
                if (row.querySelector('td[colspan]')) return;
                
                const name = row.querySelector('.client-name') ? row.querySelector('.client-name').textContent.toLowerCase() : '';
                const cedula = row.querySelector('.client-cedula') ? row.querySelector('.client-cedula').textContent.toLowerCase() : '';
                
                if (name.includes(filter) || cedula.includes(filter)) {
                    row.style.display = '';
                    visibles++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (cntResultados) {
                cntResultados.textContent = visibles + (visibles === 1 ? ' cliente encontrado' : ' clientes encontrados');
            }

            let emptyRow = document.getElementById('emptyFiltered');
            if (visibles === 0 && rows.length > 0) {
                if (!emptyRow) {
                    const tbody = document.querySelector('table tbody');
                    const tr = document.createElement('tr');
                    tr.id = 'emptyFiltered';
                    tr.innerHTML = `
                        <td colspan="6" class="text-center py-5">
                            <div class="text-muted mb-3"><i class="fas fa-search fa-3x opacity-25"></i></div>
                            <h6 class="fw-bold text-muted">No hay resultados para "${this.value}"</h6>
                            <p class="text-muted small">Intenta con otro nombre o número de cédula.</p>
                        </td>
                    `;
                    tbody.appendChild(tr);
                } else {
                    emptyRow.querySelector('h6').textContent = `No hay resultados para "${this.value}"`;
                }
            } else {
                if (emptyRow) emptyRow.remove();
            }
        });
    }
});
</script>
    </div>
</div>
</body>
</html>

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

try {
    if ($user_role === 'super_admin' || $user_role === 'admin') {
        // SuperAdmin y Admin ven todos los clientes
        $query = "
            SELECT cp.id, cp.nombre, cp.cedula, cp.email, cp.telefono, cp.estado,
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
            SELECT cp.id, cp.nombre, cp.cedula, cp.email, cp.telefono, cp.estado,
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
            SELECT cp.id, cp.nombre, cp.cedula, cp.email, cp.telefono, cp.estado,
                   CONCAT_WS(' - ', cp.zona, cp.ciudad) as region,
                   CASE WHEN cp.estado = 'descartado' THEN 0 ELSE 1 END as activo,
                   cp.created_at as fecha_creacion
            FROM cliente_prospecto cp
            WHERE cp.asesor_id = :asesor_id
            ORDER BY cp.created_at DESC
        ";
        $col_asesor = false;
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
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 800; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: var(--brand-gray); border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: rgba(215,224,234,0.55); }
        .table tbody tr:hover { background: rgba(255,221,0,0.06); }
        .badge-success { background: #10b981; }
        .badge-danger { background: #ef4444; }
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

<?php if ($user_role === 'supervisor'): require_once '_sidebar_supervisor.php'; else: ?>
<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <?php if ($user_role === 'supervisor'): ?>
        <a href="supervisor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php elseif ($user_role === 'super_admin'): ?>
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
        <?php else: ?>
        <a href="<?php echo ($user_role === 'supervisor') ? 'supervisor_index.php' : 'asesor_index.php'; ?>" class="sidebar-link">
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
        <a href="clientes.php" class="sidebar-link active">
            <i class="fas fa-briefcase"></i> <?php echo ($user_role === 'asesor') ? 'Mis ' : ''; ?>Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> <?php echo ($user_role === 'asesor') ? 'Mis ' : ''; ?>Operaciones
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
        <?php if ($user_role === 'supervisor'): ?>
            <h2><i class="fas fa-shield-halved me-2" style="color: var(--brand-yellow);"></i>Super_IA - Supervisor</h2>
        <?php else: ?>
            <h2><?php echo $user_role === 'super_admin' ? '👑' : '🎯'; ?> Super_IA 
                <?php 
                if ($user_role === 'super_admin') echo '- SuperAdministrador';
                elseif ($user_role === 'admin') echo '- Admin';
                elseif ($user_role === 'supervisor') echo '- Supervisor';
                else echo '- Asesor';
                ?>
            </h2>
        <?php endif; ?>
        <div class="user-info">
            <div>
                <strong>
                    <?php 
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_nombre']);
                    elseif ($user_role === 'supervisor') echo htmlspecialchars($_SESSION['supervisor_nombre']);
                    else echo htmlspecialchars($_SESSION['asesor_nombre']);
                    ?>
                </strong><br>
                <small>
                    <?php 
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_rol']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_rol']);
                    elseif ($user_role === 'supervisor') echo htmlspecialchars($_SESSION['supervisor_rol']);
                    else echo htmlspecialchars($_SESSION['asesor_rol']);
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-briefcase me-2"></i>Clientes</h1>
            <p class="text-muted mt-2">Total de clientes: <strong><?php echo count($clientes); ?></strong></p>
        </div>

        <div class="table-card">
            <div class="card-header-custom">
                <h6>💼 Listado de Clientes</h6>
            </div>
            
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Teléfono</th>
                        <?php if ($col_asesor): ?>
                        <th>Asesor Asignado</th>
                        <?php endif; ?>
                        <th>Región</th>
                        <th>Fecha Registro</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clientes)): ?>
                    <tr>
                        <td colspan="<?php echo $col_asesor ? 8 : 7; ?>" class="text-center py-4">
                            <i class="fas fa-inbox me-2" style="color: #d1d5db;"></i>No hay clientes para mostrar
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($clientes as $cliente): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cliente['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($cliente['email'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($cliente['telefono'] ?? ''); ?></td>
                        <?php if ($col_asesor): ?>
                        <td><?php echo htmlspecialchars($cliente['asesor_nombre'] ?? 'Sin asignar'); ?></td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($cliente['region'] ?: 'Sin región'); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($cliente['fecha_creacion'])); ?></td>
                        <td>
                            <?php
                                $estadoDb = strtolower((string)($cliente['estado'] ?? ''));
                                if ($estadoDb === 'descartado') {
                                    echo '<span class="badge badge-danger" style="color:white;">✗ Descartado</span>';
                                } else {
                                    $esCliente = cliente_es_cliente_por_aprobacion($pdo, (string)($cliente['id'] ?? ''), (string)($cliente['cedula'] ?? ''));
                                    if ($esCliente) {
                                        echo '<span class="badge badge-success" style="color:white;">✓ Cliente</span>';
                                    } else {
                                        echo '<span class="badge" style="background:#f59e0b;color:#111827;">Prospecto</span>';
                                    }
                                }
                            ?>
                        </td>
                        <td>
                            <a href="ver_cliente.php?id=<?= urlencode($cliente['id'] ?? '') ?>" class="btn btn-sm btn-outline-primary" title="Ver encuesta y fichas del cliente">
                                <i class="fas fa-eye"></i>
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

</body>
</html>

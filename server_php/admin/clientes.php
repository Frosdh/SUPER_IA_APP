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

// Extraer lista única de asesores para el filtro dropdown
$asesores_lista = [];
foreach ($clientes as $c) {
    $an = trim($c['asesor_nombre'] ?? '');
    if ($an && !in_array($an, $asesores_lista)) {
        $asesores_lista[] = $an;
    }
}
sort($asesores_lista);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Clientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($user_role === 'supervisor' || $user_role === 'asesor'): ?>
    <link rel="stylesheet" href="supervisor_layout.css">
<?php else: ?>
    <style>
        /* Estilos para admin/superadmin (sin sidebar supervisor) */
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
        .main-content { flex: 1; margin-left: 0; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) { .sidebar { width: 200px; } .main-content { margin-left: 0; } }
        @media (max-width: 768px) { .sidebar { width: 180px; } .main-content { margin-left: 0; } }
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
    </style>
<?php endif; ?>
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
    $navTitle = ''; $navIcon = ''; $navSubtitle = ''; 
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

        <style>
        /* ══ FILTER BAR — diseño premium ══════════════════════════ */
        .filter-bar{
            display:flex;flex-direction:column;gap:0;
            background:linear-gradient(135deg,#f8fafd 0%,#f0f5fb 100%);
            border-bottom:1px solid #e2eaf4;
            padding:0;
        }

        /* sección 1: inputs de búsqueda */
        .filter-top{
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;
            padding:16px 22px 12px;
        }

        /* sección 2: estado pills + A-Z */
        .filter-middle{
            display:flex;align-items:center;gap:10px;flex-wrap:wrap;
            padding:10px 22px 12px;
            border-top:1px solid #edf2f9;
        }

        /* sección 3: contador + tags */
        .filter-bottom{
            display:flex;align-items:center;justify-content:space-between;
            flex-wrap:wrap;gap:8px;
            padding:8px 22px 12px;
            border-top:1px dashed #edf2f9;
            background:rgba(248,250,253,.7);
        }

        /* label de sección */
        .fi-label{
            font-size:10.5px;font-weight:800;color:#94a3b8;
            text-transform:uppercase;letter-spacing:.6px;
            white-space:nowrap;display:flex;align-items:center;gap:5px;
            margin-right:2px;
        }

        /* ── Estado pills ─────────────────────────────────────── */
        .estado-pills{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
        .ep-btn{
            display:inline-flex;align-items:center;gap:6px;
            padding:6px 14px;border-radius:99px;
            font-size:12px;font-weight:700;
            border:1.5px solid transparent;
            cursor:pointer;transition:all .18s;
            white-space:nowrap;
        }
        .ep-btn i{font-size:11px;}

        /* Todos */
        .ep-all{background:#f1f5f9;border-color:#dde5f0;color:#475569;}
        .ep-all:hover{background:#e2e8f0;border-color:#c7d4e4;}
        .ep-all.active{background:linear-gradient(135deg,#0a2748,#1e4d8c);border-color:#0a2748;color:#fff;box-shadow:0 3px 10px rgba(10,39,72,.25);}

        /* Cliente activo */
        .ep-cliente{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
        .ep-cliente:hover{background:#dcfce7;border-color:#86efac;}
        .ep-cliente.active{background:linear-gradient(135deg,#15803d,#16a34a);border-color:#15803d;color:#fff;box-shadow:0 3px 10px rgba(21,128,61,.3);}

        /* Prospecto */
        .ep-prospecto{background:#fffbeb;border-color:#fde68a;color:#b45309;}
        .ep-prospecto:hover{background:#fef3c7;border-color:#fcd34d;}
        .ep-prospecto.active{background:linear-gradient(135deg,#d97706,#f59e0b);border-color:#d97706;color:#fff;box-shadow:0 3px 10px rgba(217,119,6,.3);}

        /* Descartado */
        .ep-descartado{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}
        .ep-descartado:hover{background:#fee2e2;border-color:#fca5a5;}
        .ep-descartado.active{background:linear-gradient(135deg,#b91c1c,#dc2626);border-color:#b91c1c;color:#fff;box-shadow:0 3px 10px rgba(185,28,28,.3);}

        /* grupos de input — wrapper flex para evitar solapamiento */
        .fi-group{
            flex:1;min-width:170px;max-width:240px;
            display:flex;align-items:center;
            border:1.5px solid #dde5f0;
            border-radius:12px;
            background:#fff;
            box-shadow:0 1px 3px rgba(0,0,0,.04);
            transition:border-color .18s,box-shadow .18s;
            overflow:hidden;
        }
        .fi-group:focus-within{
            border-color:#3b82f6;
            box-shadow:0 0 0 3px rgba(59,130,246,.1),0 1px 3px rgba(0,0,0,.04);
        }
        .fi-group-wide{min-width:190px;max-width:260px;}
        .fi-ico{
            flex-shrink:0;
            width:38px;
            text-align:center;
            color:#b0bec5;
            font-size:13px;
            pointer-events:none;
        }
        .fi-input{
            flex:1;
            border:none;
            outline:none;
            padding:10px 13px 10px 0;
            font-size:13px;font-weight:600;
            color:#1a2744;
            background:transparent;
            min-width:0;
        }
        .fi-input::placeholder{color:#b0bec5;font-weight:500;}
        .fi-select{
            flex:1;
            border:none;
            outline:none;
            padding:10px 30px 10px 0;
            font-size:13px;font-weight:600;
            color:#1a2744;
            background:transparent url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='7'%3E%3Cpath d='M1 1l4.5 4.5L10 1' stroke='%2394a3b8' stroke-width='1.6' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 10px center;
            appearance:none;cursor:pointer;
            min-width:0;
        }

        /* divider vertical */
        .fi-divider{width:1px;height:32px;background:#dde5f0;flex-shrink:0;margin:0 2px;}

        /* botón limpiar */
        .fi-clear-btn{
            display:flex;align-items:center;gap:7px;
            padding:9px 16px;
            border-radius:12px;
            border:1.5px solid #dde5f0;
            background:#fff;
            color:#94a3b8;
            font-size:12.5px;font-weight:700;
            cursor:pointer;
            transition:.18s;
            white-space:nowrap;
            box-shadow:0 1px 3px rgba(0,0,0,.04);
            margin-left:auto;
        }
        .fi-clear-btn:hover{border-color:#ef4444;color:#ef4444;background:#fff5f5;box-shadow:0 2px 8px rgba(239,68,68,.1);}

        /* ── A-Z strip ─────────────────────────────────────────── */
        .az-wrap{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
        .az-label{
            font-size:10.5px;font-weight:800;color:#94a3b8;
            text-transform:uppercase;letter-spacing:.6px;
            white-space:nowrap;margin-right:4px;
            display:flex;align-items:center;gap:5px;
        }
        .az-label i{font-size:11px;}

        .az-all-btn{
            height:30px;padding:0 12px;
            border-radius:8px;
            border:1.5px solid #dde5f0;
            background:#fff;
            color:#475569;
            font-size:11px;font-weight:800;
            cursor:pointer;transition:.15s;
            box-shadow:0 1px 2px rgba(0,0,0,.04);
        }
        .az-all-btn.active{
            background:linear-gradient(135deg,#0a2748 0%,#1e4d8c 100%);
            border-color:#0a2748;color:#ffdd00;
            box-shadow:0 3px 10px rgba(10,39,72,.25);
        }
        .az-all-btn:hover:not(.active){background:#f1f5f9;border-color:#c7d4e4;}

        .az-btn{
            width:30px;height:30px;
            border-radius:8px;
            border:1.5px solid #e8eef6;
            background:#fff;
            color:#64748b;
            font-size:11.5px;font-weight:800;
            cursor:pointer;transition:.15s;
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 1px 2px rgba(0,0,0,.03);
        }
        .az-btn:hover{
            background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;
            transform:translateY(-1px);box-shadow:0 3px 8px rgba(59,130,246,.15);
        }
        .az-btn.active{
            background:linear-gradient(135deg,#ffdd00 0%,#f4c400 100%);
            border-color:#e6b800;color:#0a2748;
            box-shadow:0 3px 10px rgba(255,221,0,.35);
            transform:translateY(-1px);
        }

        /* ── info row ───────────────────────────────────────────── */
        .fi-info-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
        .fi-count{
            display:flex;align-items:center;gap:6px;
            font-size:12px;font-weight:600;color:#94a3b8;
            white-space:nowrap;
        }
        .fi-count-num{
            font-size:14px;font-weight:900;color:#0a2748;
        }
        .fi-count-sep{color:#dde5f0;}

        /* tags activos */
        .fi-tags{display:flex;gap:5px;flex-wrap:wrap;}
        .fi-tag{
            display:inline-flex;align-items:center;gap:5px;
            background:linear-gradient(135deg,#eff6ff,#e0ecff);
            border:1px solid #bfdbfe;
            border-radius:8px;
            padding:3px 10px 3px 9px;
            font-size:11px;font-weight:700;color:#1d4ed8;
        }
        .fi-tag i{font-size:9px;opacity:.7;}
        .fi-tag-x{
            cursor:pointer;opacity:.5;font-size:10px;
            transition:.15s;margin-left:1px;line-height:1;
        }
        .fi-tag-x:hover{opacity:1;color:#ef4444;}
        </style>

        <div class="table-card bg-white" style="border-radius:20px; border:1px solid #e2e8f0; box-shadow:0 10px 30px rgba(0,0,0,0.05);">
            <!-- header title -->
            <div class="px-4 pt-4 pb-2 d-flex align-items-center justify-content-between">
                <h5 class="m-0 fw-800 d-flex align-items-center gap-2" style="color:#0a2748;">
                    <i class="fas fa-list-ul text-primary"></i> Listado Completo
                </h5>
            </div>

            <!-- FILTER BAR -->
            <div class="filter-bar">

                <!-- FILA 1: búsqueda de texto + asesor + limpiar -->
                <div class="filter-top">
                    <div class="fi-group">
                        <i class="fas fa-search fi-ico"></i>
                        <input type="text" id="fiNombre" class="fi-input" placeholder="Buscar por nombre…">
                    </div>
                    <div class="fi-group">
                        <i class="fas fa-id-card fi-ico"></i>
                        <input type="text" id="fiCedula" class="fi-input" placeholder="Buscar por cédula…">
                    </div>
                    <div class="fi-divider"></div>
                    <div class="fi-group fi-group-wide">
                        <i class="fas fa-user-tie fi-ico"></i>
                        <select id="fiAsesor" class="fi-select">
                            <option value="">Todos los asesores</option>
                            <?php foreach($asesores_lista as $an): ?>
                            <option value="<?=htmlspecialchars($an)?>"><?=htmlspecialchars($an)?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="fi-clear-btn" id="fiClear">
                        <i class="fas fa-rotate-left" style="font-size:11px;"></i> Limpiar
                    </button>
                </div>

                <!-- FILA 2: estado pills + A-Z -->
                <div class="filter-middle">
                    <!-- Estado pills -->
                    <div class="estado-pills">
                        <span class="fi-label"><i class="fas fa-tag"></i> Estado</span>
                        <button class="ep-btn ep-all active" data-estado="">
                            <i class="fas fa-border-all"></i> Todos
                        </button>
                        <button class="ep-btn ep-cliente" data-estado="cliente">
                            <i class="fas fa-check-circle"></i> Cliente activo
                        </button>
                        <button class="ep-btn ep-prospecto" data-estado="prospecto">
                            <i class="fas fa-clock"></i> Prospecto
                        </button>
                        <button class="ep-btn ep-descartado" data-estado="descartado">
                            <i class="fas fa-times-circle"></i> Descartado
                        </button>
                    </div>
                    <!-- A-Z -->
                    <div class="fi-divider"></div>
                    <div class="az-wrap">
                        <span class="fi-label"><i class="fas fa-sort-alpha-down"></i> A–Z</span>
                        <button class="az-all-btn active" data-letter="">TODOS</button>
                        <?php foreach(range('A','Z') as $l): ?>
                        <button class="az-btn" data-letter="<?=$l?>"><?=$l?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- FILA 3: contador + tags activos -->
                <div class="filter-bottom">
                    <div class="fi-count">
                        <i class="fas fa-users" style="font-size:11px;color:#cbd5e1;"></i>
                        Mostrando <span class="fi-count-num" id="cntResultados"><?=count($clientes)?></span>
                        <span class="fi-count-sep">de <?=count($clientes)?></span> clientes
                    </div>
                    <div class="fi-tags" id="fiTagsBox"></div>
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
                        <?php
                            $estadoKey = $esDescartado ? 'descartado' : ($esCliente ? 'cliente' : 'prospecto');
                        ?>
                        <tr class="client-row"
                            data-nombre="<?=htmlspecialchars(mb_strtolower(trim($cliente['nombre']??'')))?>"
                            data-cedula="<?=htmlspecialchars($cliente['cedula']??'')?>"
                            data-asesor="<?=htmlspecialchars(mb_strtolower(trim($cliente['asesor_nombre']??'')))?>"
                            data-estado="<?=htmlspecialchars($estadoKey)?>"
                            style="transition:all 0.2s ease;">
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

  const fiNombre  = document.getElementById('fiNombre');
  const fiCedula  = document.getElementById('fiCedula');
  const fiAsesor  = document.getElementById('fiAsesor');
  const fiClear   = document.getElementById('fiClear');
  const cntEl     = document.getElementById('cntResultados');
  const tagsBox   = document.getElementById('fiTagsBox');
  const allRows   = Array.from(document.querySelectorAll('table tbody tr.client-row'));
  const total     = allRows.length;

  let activeLetter = '';
  let activeEstado = '';   // '' | 'cliente' | 'prospecto' | 'descartado'

  /* ── aplicar todos los filtros ───────────────────────── */
  function applyFilters() {
    const fNom = (fiNombre.value || '').trim().toLowerCase();
    const fCed = (fiCedula.value || '').trim().toLowerCase();
    const fAse = (fiAsesor.value || '').trim().toLowerCase();
    const fEst = activeEstado;
    const fLet = activeLetter.toLowerCase();

    let vis = 0;
    allRows.forEach(row => {
      const nombre = row.dataset.nombre || '';
      const cedula = row.dataset.cedula || '';
      const asesor = row.dataset.asesor || '';
      const estado = row.dataset.estado || '';

      const okNom = !fNom || nombre.includes(fNom);
      const okCed = !fCed || cedula.includes(fCed);
      const okAse = !fAse || asesor === fAse;
      const okEst = !fEst || estado === fEst;
      const okLet = !fLet || nombre.startsWith(fLet);

      if (okNom && okCed && okAse && okEst && okLet) {
        row.style.display = '';
        vis++;
      } else {
        row.style.display = 'none';
      }
    });

    /* contador */
    if (cntEl) cntEl.textContent = vis;

    /* fila vacía */
    let emptyRow = document.getElementById('emptyFiltered');
    if (vis === 0 && total > 0) {
      if (!emptyRow) {
        const tbody = document.querySelector('table tbody');
        const tr = document.createElement('tr');
        tr.id = 'emptyFiltered';
        tr.innerHTML = `<td colspan="6" class="text-center py-5">
          <div class="text-muted mb-3"><i class="fas fa-filter fa-3x opacity-20"></i></div>
          <h6 class="fw-bold text-muted">Sin resultados con los filtros aplicados</h6>
          <p class="text-muted small">Prueba quitando algún filtro o cambiando la letra.</p>
        </td>`;
        tbody.appendChild(tr);
      }
    } else {
      if (emptyRow) emptyRow.remove();
    }

    renderTags(fNom, fCed, fAse, fEst, fLet);
  }

  /* ── tags de filtros activos ─────────────────────────── */
  const estadoLabel = {cliente:'Cliente activo', prospecto:'Prospecto', descartado:'Descartado'};
  function renderTags(fNom, fCed, fAse, fEst, fLet) {
    if (!tagsBox) return;
    tagsBox.innerHTML = '';
    function tag(label, clearFn) {
      const d = document.createElement('div');
      d.className = 'fi-tag';
      d.innerHTML = `<i class="fas fa-tag"></i>${label}<span class="fi-tag-x">✕</span>`;
      d.querySelector('.fi-tag-x').addEventListener('click', clearFn);
      tagsBox.appendChild(d);
    }
    if (fNom) tag(`Nombre: "${fiNombre.value}"`,  () => { fiNombre.value = ''; applyFilters(); });
    if (fCed) tag(`Cédula: "${fiCedula.value}"`,  () => { fiCedula.value = ''; applyFilters(); });
    if (fAse) tag(`Asesor: ${fiAsesor.options[fiAsesor.selectedIndex]?.text || fAse}`, () => { fiAsesor.value = ''; applyFilters(); });
    if (fEst) tag(`Estado: ${estadoLabel[fEst] || fEst}`, () => setEstado(''));
    if (fLet) tag(`Letra: ${fLet.toUpperCase()}`, () => setLetter(''));
  }

  /* ── pills de estado ─────────────────────────────────── */
  function setEstado(val) {
    activeEstado = val;
    document.querySelectorAll('.ep-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.estado === val);
    });
    applyFilters();
  }
  document.querySelectorAll('.ep-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      setEstado(btn.dataset.estado === activeEstado ? '' : btn.dataset.estado);
    });
  });

  /* ── letra A-Z ───────────────────────────────────────── */
  function setLetter(l) {
    activeLetter = l;
    document.querySelectorAll('.az-btn').forEach(b => b.classList.toggle('active', b.dataset.letter === l));
    const allBtn = document.querySelector('.az-all-btn');
    if (allBtn) allBtn.classList.toggle('active', l === '');
    applyFilters();
  }
  document.querySelectorAll('.az-btn').forEach(btn => {
    btn.addEventListener('click', () => setLetter(btn.dataset.letter === activeLetter ? '' : btn.dataset.letter));
  });
  const allBtn = document.querySelector('.az-all-btn');
  if (allBtn) allBtn.addEventListener('click', () => setLetter(''));

  /* ── limpiar todo ────────────────────────────────────── */
  fiClear.addEventListener('click', () => {
    fiNombre.value = '';
    fiCedula.value = '';
    fiAsesor.value = '';
    setEstado('');
    setLetter('');
  });

  /* ── listeners de inputs ─────────────────────────────── */
  [fiNombre, fiCedula, fiAsesor].forEach(el => {
    el.addEventListener('input', applyFilters);
    el.addEventListener('change', applyFilters);
  });

});
</script>
    </div>
</div>
</body>
</html>

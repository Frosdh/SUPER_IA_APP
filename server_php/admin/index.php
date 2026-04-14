<?php
require_once 'db_admin.php';

// Verificar sesión del admin o super_admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

// Determinar tipo de usuario
$user_type = $is_super_admin ? 'super_admin' : 'admin';
$user_id = $is_super_admin ? $_SESSION['super_admin_id'] : $_SESSION['admin_id'];
$user_nombre = $is_super_admin ? $_SESSION['super_admin_nombre'] : $_SESSION['admin_nombre'];
$user_rol = $is_super_admin ? $_SESSION['super_admin_rol'] : $_SESSION['admin_rol'];

// Total de usuarios - with error handling for schema mismatch
$totalUsuarios = 0;
$totalClientes = 0;
$totalOperaciones = 0;
$totalAlertas = 0;

try {
    $totalUsuarios = $pdo->query("SELECT COUNT(*) FROM usuario WHERE activo = 1")->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching usuarios: " . $e->getMessage());
}

try {
    $totalClientes = $pdo->query("SELECT COUNT(*) FROM cliente_prospecto WHERE estado != 'descartado'")->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching clientes: " . $e->getMessage());
}

try {
    $totalAlertas = $pdo->query("SELECT COUNT(*) FROM alerta_modificacion WHERE vista_supervisor = 0")->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching alertas: " . $e->getMessage());
}

$totalActivos = 0;
$totalPasivos = 0;
$totalOperaciones = 0;

// Últimas operaciones
$ultimasOperaciones = [];
try {
    $ultimasOperaciones = $pdo->query("
        SELECT cp.id, cp.nombre as nombre_cliente, cp.estado, cp.created_at as fecha_creacion
        FROM cliente_prospecto cp
        WHERE cp.estado IN ('cliente', 'prospecto')
        ORDER BY cp.created_at DESC LIMIT 6
    ")->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching operaciones: " . $e->getMessage());
}

// Últimas gestiones
$ultimasGestiones = $pdo->query("
    SELECT gg.id, CONCAT(c.nombre, ' ', c.apellidos) as nombre_cliente, u.nombres, u.apellidos, gg.tipo, gg.fecha, gg.resultado
    FROM gestiones_cobranza gg
    JOIN clientes c ON gg.cliente_id = c.id_cliente
    LEFT JOIN usuarios u ON gg.usuario_id = u.id_usuario
    ORDER BY gg.fecha DESC LIMIT 6
")->fetchAll();

// Clientes por región
$clientesPorRegion = $pdo->query("
    SELECT CONCAT(r.pais, ' - ', r.provincia) as region, COUNT(c.id_cliente) as total
    FROM region r LEFT JOIN clientes c ON r.id_region = c.id_region_fk
    GROUP BY r.id_region ORDER BY total DESC
")->fetchAll();

// Usuarios por rol
$usuariosPorRol = $pdo->query("
    SELECT r.nombre, COUNT(u.id_usuario) as total
    FROM roles r LEFT JOIN usuarios u ON r.id_rol = u.id_rol_fk
    GROUP BY r.id_rol, r.nombre
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Logan — Dashboard Supervisor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', 'Segoe UI', sans-serif; 
            background: #f5f7fa;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        
        /* SIDEBAR */
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
        
        .sidebar-brand {
            padding: 0 20px 30px;
            font-size: 18px;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }
        
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        
        .sidebar-section {
            padding: 0 15px;
            margin-bottom: 25px;
        }
        
        .sidebar-section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #9ca3af;
            letter-spacing: 0.5px;
            padding: 0 10px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            margin-bottom: 5px;
            border-radius: 8px;
            color: #d1d5db;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
        }
        
        .sidebar-link:hover {
            background: rgba(124, 58, 237, 0.2);
            color: #fff;
            padding-left: 20px;
        }
        
        .sidebar-link.active {
            background: linear-gradient(90deg, #6b11ff, #7c3aed);
            color: #fff;
        }
        
        .sidebar-link small {
            display: inline-block;
            background: #dc2626;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            margin-left: auto;
        }
        
        /* MAIN CONTENT */
        .main-content {
            flex: 1;
            margin-left: 230px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* NAVBAR */
        .navbar-custom {
            background: linear-gradient(135deg, #6b11ff, #3182fe);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-custom h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info small { opacity: 0.9; }
        
        .btn-logout {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-logout:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        
        /* CONTENT AREA */
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 30px;
        }
        
        .stat-card { 
            background: #fff; 
            border-radius: 14px; 
            padding: 20px 22px; 
            box-shadow: 0 4px 16px rgba(0,0,0,.06); 
            height: 100%;
        }
        .stat-card h3 { font-size: 28px; font-weight: 700; margin: 0; color: #24243e; }
        .stat-card p { color: #6c757d; margin: 4px 0 0; font-size: 13px; font-weight: 500; }
        .stat-card .trend { font-size: 12px; margin-top: 6px; }
        
        .icon-box {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #fff;
            flex-shrink: 0;
        }
        
        .bg-purple { background: linear-gradient(135deg, #6b11ff, #9b51e0); }
        .bg-blue { background: linear-gradient(135deg, #3182fe, #5b9cfc); }
        .bg-green { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .bg-orange { background: linear-gradient(135deg, #f46b45, #eea849); }
        .bg-red { background: linear-gradient(135deg, #e53935, #e35d5b); }
        .bg-teal { background: linear-gradient(135deg, #11998e, #2af598); }
        
        .chart-card { 
            background: #fff; 
            border-radius: 14px; 
            padding: 22px; 
            box-shadow: 0 4px 16px rgba(0,0,0,.06); 
        }
        .chart-card .chart-title { 
            font-size: 15px; 
            font-weight: 700; 
            color: #24243e; 
            margin-bottom: 4px; 
        }
        
        .table-card { 
            background: #fff; 
            border-radius: 14px; 
            box-shadow: 0 4px 16px rgba(0,0,0,.06); 
            overflow: hidden; 
        }
        .table-card .card-header-custom { 
            padding: 16px 20px; 
            border-bottom: 1px solid #f0f0f0; 
        }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 14px; }
        
        .table thead th {
            background: #f8f9fa;
            font-size: 11px;
            text-transform: uppercase;
            color: #6c757d;
            border: none;
            padding: 11px 14px;
        }
        .table tbody td {
            padding: 11px 14px;
            vertical-align: middle;
            border-color: #f5f5f5;
            font-size: 13px;
        }
        .table tbody tr:hover {
            background: #fafbff;
        }
        
        .live-dot {
            width: 8px;
            height: 8px;
            background: #11998e;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        .badge-sm {
            font-size: 11px;
            padding: 4px 8px;
        }
        
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> COAC Finance
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index.php" class="sidebar-link active">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa.php" class="sidebar-link">
            <i class="fas fa-globe"></i> Mapa General
        </a>
        <a href="mapa_coop.php" class="sidebar-link">
            <i class="fas fa-sitemap"></i> Cooperativas
        </a>
        <a href="mapa_familiar.php" class="sidebar-link">
            <i class="fas fa-users-line"></i> Equipo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <a href="usuarios.php" class="sidebar-link">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Administración</div>
        <a href="administrar_solicitudes_supervisor.php" class="sidebar-link">
            <i class="fas fa-file-alt"></i> Solicitudes de Supervisor
        </a>
        <a href="crear_asesor_admin.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_asesores.php" class="sidebar-link">
            <i class="fas fa-users-cog"></i> Administrar Asesores
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Configuración</div>
        <a href="#" class="sidebar-link">
            <i class="fas fa-cog"></i> Configuración
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> COAC Finance <?php echo $is_super_admin ? '- SuperAdministrador' : '- Admin'; ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($user_nombre ?? 'Usuario'); ?></strong><br>
                <small><?php echo htmlspecialchars($user_rol ?? 'Rol'); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Dashboard General</h4>
                <small class="text-muted">
                    <?php echo date('l, d \\d\\e F \\d\\e Y'); ?> · <span class="live-dot"></span> En tiempo real
                </small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i>Actualizar
            </button>
        </div>

        <!-- Tarjetas Resumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?php echo number_format($totalUsuarios); ?></h3>
                        <p>Usuarios</p>
                        <div class="trend text-info"><i class="fas fa-circle live-dot me-1"></i>Activos</div>
                    </div>
                    <div class="icon-box bg-blue"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?php echo number_format($totalClientes); ?></h3>
                        <p>Clientes</p>
                        <div class="trend text-info">Base de datos</div>
                    </div>
                    <div class="icon-box bg-purple"><i class="fas fa-briefcase"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?php echo number_format($totalOperaciones); ?></h3>
                        <p>Operaciones</p>
                        <div class="trend text-info">Créditos</div>
                    </div>
                    <div class="icon-box bg-green"><i class="fas fa-handshake"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3>$<?php echo number_format($totalActivos, 0); ?></h3>
                        <p>Activos</p>
                        <div class="trend text-success">Total</div>
                    </div>
                    <div class="icon-box bg-teal"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3>$<?php echo number_format($totalPasivos, 0); ?></h3>
                        <p>Pasivos</p>
                        <div class="trend text-warning">Total</div>
                    </div>
                    <div class="icon-box bg-orange"><i class="fas fa-arrow-down"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?php echo number_format($totalAlertas); ?></h3>
                        <p>Alertas</p>
                        <div class="trend text-danger">Pendientes</div>
                    </div>
                    <div class="icon-box bg-red"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="chart-title">Usuarios por Rol</div>
                    <canvas id="chartRoles"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-card">
                    <div class="chart-title">Clientes por Región</div>
                    <canvas id="chartRegiones"></canvas>
                </div>
            </div>
        </div>

        <!-- Tablas -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="table-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-history me-2"></i>Últimas Operaciones</h6>
                    </div>
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Cliente</th><th>Monto</th><th>Estado</th><th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimasOperaciones as $op): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($op['nombre_cliente']); ?></td>
                                <td>$<?php echo number_format($op['cantidad'], 2); ?></td>
                                <td><?php echo '<span class="badge bg-' . ($op['estado'] === 'completado' ? 'success' : 'warning') . ' badge-sm">' . ucfirst($op['estado']) . '</span>'; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($op['fecha_creacion'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="table-card">
                    <div class="card-header-custom">
                        <h6><i class="fas fa-phone-alt me-2"></i>Últimas Gestiones</h6>
                    </div>
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Cliente</th><th>Tipo</th><th>Resultado</th><th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimasGestiones as $gest): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($gest['nombre_cliente']); ?></td>
                                <td><?php echo htmlspecialchars($gest['tipo']); ?></td>
                                <td><?php echo '<span class="badge bg-' . ($gest['resultado'] === 'exitado' ? 'success' : 'warning') . ' badge-sm">' . ucfirst($gest['resultado']) . '</span>'; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($gest['fecha'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div style="text-align: center; color: #999; font-size: 12px; margin-top: 30px;">
            <p>COAC Finance © 2026 | Sistema de Monitoreo Comercial</p>
        </div>
    </div>
</div>

<script>
// Gráfico: Usuarios por Rol
const rolesData = <?php echo json_encode(array_values($usuariosPorRol)); ?>;
new Chart(document.getElementById('chartRoles'), {
    type: 'doughnut',
    data: {
        labels: rolesData.map(r => r.nombre),
        datasets: [{
            data: rolesData.map(r => r.total),
            backgroundColor: ['rgba(107, 17, 255, 0.8)', 'rgba(49, 130, 254, 0.8)', 'rgba(17, 153, 142, 0.8)', 'rgba(244, 107, 69, 0.8)'],
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 15 } } } }
});

// Gráfico: Clientes por Región
const regionesData = <?php echo json_encode(array_values($clientesPorRegion)); ?>;
new Chart(document.getElementById('chartRegiones'), {
    type: 'bar',
    data: {
        labels: regionesData.map(r => r.region),
        datasets: [{ label: 'Clientes', data: regionesData.map(r => r.total), backgroundColor: 'rgba(49, 130, 254, 0.8)', borderColor: 'rgba(49, 130, 254, 1)', borderWidth: 1, borderRadius: 6 }]
    },
    options: { responsive: true, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
});

// Activar enlace del sidebar
document.querySelectorAll('.sidebar-link').forEach(link => {
    if (link.href === window.location.href) {
        link.classList.add('active');
    }
});
</script>

</body>
</html>

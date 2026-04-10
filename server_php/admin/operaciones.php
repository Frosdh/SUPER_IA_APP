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
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    $user_id = $_SESSION['asesor_id'];
} else {
    header('Location: login.php?role=admin');
    exit;
}

// Construir query según el rol del usuario
if ($user_role === 'super_admin' || $user_role === 'admin') {
    // SuperAdmin y Admin ven todas las operaciones
    $query = "
        SELECT oc.id_opera_creditito, c.nombre, c.apellidos, oc.cantidad, oc.estado, oc.fecha_creacion, 
               u.nombres as asesor_nombre
        FROM operacion_credito oc
        JOIN clientes c ON oc.cliente_id = c.id_cliente
        LEFT JOIN usuarios u ON c.asesor_id_fk = u.id_usuario
        ORDER BY oc.fecha_creacion DESC
    ";
    $col_asesor = true;
} elseif ($user_role === 'supervisor') {
    // Supervisor ve operaciones de sus asesores
    $query = "
        SELECT oc.id_opera_creditito, c.nombre, c.apellidos, oc.cantidad, oc.estado, oc.fecha_creacion, 
               u.nombres as asesor_nombre
        FROM operacion_credito oc
        JOIN clientes c ON oc.cliente_id = c.id_cliente
        LEFT JOIN usuarios u ON c.asesor_id_fk = u.id_usuario
        WHERE c.asesor_id_fk IN (
            SELECT id_usuario FROM usuarios WHERE supervisor_id_fk = $user_id
        )
        ORDER BY oc.fecha_creacion DESC
    ";
    $col_asesor = true;
} else {
    // Asesor ve solo sus operaciones
    $query = "
        SELECT oc.id_opera_creditito, c.nombre, c.apellidos, oc.cantidad, oc.estado, oc.fecha_creacion
        FROM operacion_credito oc
        JOIN clientes c ON oc.cliente_id = c.id_cliente
        WHERE c.asesor_id_fk = $user_id
        ORDER BY oc.fecha_creacion DESC
    ";
    $col_asesor = false;
}

$operaciones = $pdo->query($query)->fetchAll();

// Estadísticas según el rol
if ($user_role === 'super_admin' || $user_role === 'admin') {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_operaciones,
            COUNT(CASE WHEN estado='completado' THEN 1 END) as completadas,
            SUM(cantidad) as monto_total
        FROM operacion_credito
    ")->fetch();
} elseif ($user_role === 'supervisor') {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_operaciones,
            COUNT(CASE WHEN estado='completado' THEN 1 END) as completadas,
            SUM(cantidad) as monto_total
        FROM operacion_credito oc
        JOIN clientes c ON oc.cliente_id = c.id_cliente
        WHERE c.asesor_id_fk IN (
            SELECT id_usuario FROM usuarios WHERE supervisor_id_fk = $user_id
        )
    ")->fetch();
} else {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_operaciones,
            COUNT(CASE WHEN estado='completado' THEN 1 END) as completadas,
            SUM(cantidad) as monto_total
        FROM operacion_credito oc
        JOIN clientes c ON oc.cliente_id = c.id_cliente
        WHERE c.asesor_id_fk = $user_id
    ")->fetch();
}

$currentPage = 'operaciones';
$is_supervisor_ui = ($user_role === 'supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Operaciones</title>
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
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php endif; ?>
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

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2>
            <?php if ($user_role === 'super_admin'): ?>
                👑 COAC Finance - SuperAdministrador
            <?php elseif ($user_role === 'admin'): ?>
                🎯 COAC Finance - Admin
            <?php elseif ($user_role === 'supervisor'): ?>
                👔 COAC Finance - Supervisor
            <?php else: ?>
                👤 COAC Finance - Asesor
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

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_operaciones']; ?></div>
                <div class="label">Total de Operaciones</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #10b981;"><?php echo $stats['completadas']; ?></div>
                <div class="label">Completadas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #3182fe;">$<?php echo number_format($stats['monto_total'], 2); ?></div>
                <div class="label">Monto Total</div>
            </div>
        </div>

        <!-- TABLA DE OPERACIONES -->
        <div class="table-card">
            <div class="card-header-custom">
                <h6>📊 Listado de Operaciones</h6>
            </div>
            
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Cliente</th>
                        <?php if ($col_asesor): ?>
                        <th>Asesor Asignado</th>
                        <?php endif; ?>
                        <th>Monto</th>
                        <th>Estado</th>
                        <th>Fecha Creación</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($operaciones)): ?>
                    <tr>
                        <td colspan="<?php echo $col_asesor ? 7 : 6; ?>" class="text-center py-4">
                            <i class="fas fa-inbox me-2" style="color: #d1d5db;"></i>No hay operaciones registradas
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($operaciones as $opera): ?>
                        <tr>
                            <td><strong>#<?php echo $opera['id_opera_creditito']; ?></strong></td>
                            <td><?php echo htmlspecialchars($opera['nombre'] . ' ' . $opera['apellidos']); ?></td>
                            <?php if ($col_asesor): ?>
                            <td><?php echo htmlspecialchars($opera['asesor_nombre'] ?? 'Sin asignar'); ?></td>
                            <?php endif; ?>
                            <td><strong>$<?php echo number_format($opera['cantidad'], 2); ?></strong></td>
                            <td>
                                <?php 
                                $estado = strtolower($opera['estado']);
                                if ($estado === 'completado') {
                                    echo '<span class="badge badge-completed" style="color: white;">✓ Completado</span>';
                                } else {
                                    echo '<span class="badge badge-pending" style="color: white;">⏳ ' . ucfirst($estado) . '</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($opera['fecha_creacion'])); ?></td>
                            <td>
                                <a href="#" class="btn btn-sm btn-outline-primary" title="Ver detalles">
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

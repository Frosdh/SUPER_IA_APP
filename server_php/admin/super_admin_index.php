<?php
require_once 'db_admin.php';

// Verificar sesión del super admin
if (!isset($_SESSION['super_admin_logged_in']) || $_SESSION['super_admin_logged_in'] !== true) {
    header('Location: login.php?role=super_admin');
    exit;
}

// Obtener estadísticas globales
try {
    // Total de usuarios por rol
    $stats_usuarios = $pdo->query("
        SELECT u.rol as nombre, COUNT(u.id) as cantidad
        FROM usuario u
        WHERE u.activo = 1
        GROUP BY u.rol
    ")->fetchAll();
    
    // Total de clientes
    $total_clientes = $pdo->query("SELECT COUNT(*) as total FROM cliente_prospecto WHERE estado != 'descartado'")->fetch()['total'];
    
    // Total de operaciones (credit processes)
    $total_operaciones = $pdo->query("SELECT COUNT(*) as total FROM credito_proceso")->fetch()['total'];
    
    // Solicitudes admin pendientes
    $solicitudes_pendientes = $pdo->query("
        SELECT COUNT(*) as total FROM solicitud_registro WHERE estado = 'pendiente'
    ")->fetch()['total'];
    
} catch (Exception $e) {
    $stats_usuarios = [];
    $total_clientes = 0;
    $total_operaciones = 0;
    $solicitudes_pendientes = 0;
}

$currentPage = 'super_admin_dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Super Administrador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .sidebar-brand i { margin-right: 10px; color: #fbbf24; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #fbbf24, #f59e0b); color: #1a0f3d; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; }
        .navbar-custom { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #1a0f3d; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(0, 0, 0, 0.1); color: #1a0f3d; border: 1px solid #1a0f3d; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(0, 0, 0, 0.2); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .badge-alert { background: #fef08a; color: #713f12; padding: 4px 8px; border-radius: 4px; font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: 700; color: #1f2937; }
        .stat-card .label { color: #9ca3af; font-size: 13px; margin-top: 5px; }
        .alert-warning { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .quick-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .action-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; text-decoration: none; color: inherit; transition: all 0.3s; }
        .action-card:hover { transform: translateY(-5px); box-shadow: 0 8px 16px rgba(0,0,0,.1); }
        .action-card i { font-size: 2rem; margin-bottom: 10px; }
        .action-card.danger { color: #ef4444; }
        .action-card.warning { color: #f59e0b; }
        .action-card.success { color: #10b981; }
        .action-card.info { color: #3182fe; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> COAC Finance
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="super_admin_index.php" class="sidebar-link active">
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
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link">
            <i class="fas fa-file-alt"></i> Solicitudes de Admin
            <span class="badge-alert" style="margin-left: auto;" id="badge-solicitudes">
                <?php echo $solicitudes_pendientes > 0 ? $solicitudes_pendientes : ''; ?>
            </span>
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
        <h2>👑 COAC Finance - Super Administrador</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($_SESSION['super_admin_nombre']); ?></strong><br>
                <small><?php echo htmlspecialchars($_SESSION['super_admin_rol']); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-crown me-2"></i>Panel de Control Super Administrador</h1>
        </div>

        <?php if ($solicitudes_pendientes > 0): ?>
        <div class="alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Atención:</strong> Tienes <strong><?php echo $solicitudes_pendientes; ?></strong> 
            solicitud(es) de creación de administrador pendiente(s) de revisar.
            <a href="administrar_solicitudes_admin.php" style="margin-left: 10px; font-weight: 600; text-decoration: underline;">
                Revisar ahora →
            </a>
        </div>
        <?php endif; ?>

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number" style="color: #3182fe;">
                    <?php echo count($stats_usuarios); ?>
                </div>
                <div class="label">Roles Activos</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #6366f1;">
                    <?php 
                    $total_usuarios = 0;
                    foreach ($stats_usuarios as $stat) $total_usuarios += $stat['cantidad'];
                    echo $total_usuarios;
                    ?>
                </div>
                <div class="label">Usuarios Totales</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #10b981;"><?php echo $total_clientes; ?></div>
                <div class="label">Clientes</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #f59e0b;"><?php echo $total_operaciones; ?></div>
                <div class="label">Operaciones</div>
            </div>
        </div>

        <!-- ACCIONES RÁPIDAS -->
        <h3 style="font-weight: 700; margin-bottom: 20px; font-size: 16px;">Acciones Rápidas</h3>
        <div class="quick-actions">
            <a href="administrar_solicitudes_admin.php" class="action-card warning">
                <i class="fas fa-file-alt"></i>
                <h4>Solicitudes Pendientes</h4>
                <p style="font-size: 14px; color: #9ca3af;">Revisar nuevas cuentas de admin</p>
            </a>
            <a href="usuarios.php" class="action-card info">
                <i class="fas fa-users"></i>
                <h4>Gestionar Usuarios</h4>
                <p style="font-size: 14px; color: #9ca3af;">Ver todos los usuarios del sistema</p>
            </a>
            <a href="clientes.php" class="action-card success">
                <i class="fas fa-briefcase"></i>
                <h4>Clientes</h4>
                <p style="font-size: 14px; color: #9ca3af;">Gestionar base de datos de clientes</p>
            </a>
            <a href="operaciones.php" class="action-card">
                <i class="fas fa-handshake" style="color: #8b5cf6;"></i>
                <h4>Operaciones</h4>
                <p style="font-size: 14px; color: #9ca3af;">Supervisar operaciones de crédito</p>
            </a>
        </div>

        <!-- DISTRIBUCIÓN DE USUARIOS -->
        <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06);">
            <h4 style="font-weight: 700; margin-bottom: 15px;">Distribución de Usuarios</h4>
            <table class="table table-hover" style="margin: 0;">
                <thead style="background: #f8f9fa;">
                    <tr>
                        <th style="border: none;">Rol</th>
                        <th style="border: none;">Cantidad</th>
                        <th style="border: none;">Porcentaje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total = 0;
                    foreach ($stats_usuarios as $stat) $total += $stat['cantidad'];
                    
                    if (!empty($stats_usuarios)):
                        foreach ($stats_usuarios as $stat):
                            $porcentaje = $total > 0 ? round(($stat['cantidad'] / $total) * 100) : 0;
                    ?>
                    <tr>
                        <td style="border: none;">
                            <i class="fas fa-tag me-2"></i>
                            <?php echo htmlspecialchars($stat['nombre']); ?>
                        </td>
                        <td style="border: none;">
                            <strong><?php echo $stat['cantidad']; ?></strong>
                        </td>
                        <td style="border: none;">
                            <div style="width: 100px; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                <div style="width: <?php echo $porcentaje; ?>%; height: 100%; background: #6366f1;"></div>
                            </div>
                            <small><?php echo $porcentaje; ?>%</small>
                        </td>
                    </tr>
                    <?php 
                        endforeach;
                    else:
                    ?>
                    <tr>
                        <td colspan="3" style="text-align: center; padding: 20px; color: #9ca3af;">
                            No hay datos de usuarios disponibles
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>

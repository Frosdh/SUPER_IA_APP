<?php
require_once 'db_admin.php';

// Verificar sesión de admin o super_admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

// Determinar variables según el rol
$user_nombre = $is_super_admin ? $_SESSION['super_admin_nombre'] : $_SESSION['admin_nombre'];
$user_rol = $is_super_admin ? $_SESSION['super_admin_rol'] : $_SESSION['admin_rol'];

$currentPage = 'mapa_calor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa de Calor - Super_IA Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-heat/0.2.0/leaflet-heat.min.js"></script>
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
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #6b11ff, #7c3aed); color: #fff; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; }
        .navbar-custom { background: linear-gradient(135deg, #6b11ff, #3182fe); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        #map { width: 100%; height: 80vh; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <?php if ($is_super_admin): ?>
        <a href="super_admin_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <?php elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <?php else: ?>
        <a href="asesor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <?php endif; ?>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php if ($is_super_admin || (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)): ?>
        <a href="mapa_calor.php" class="sidebar-link active">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php endif; ?>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <?php if ($is_super_admin || (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)): ?>
        <a href="usuarios.php" class="sidebar-link">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <?php endif; ?>
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

    <?php if ($is_super_admin): ?>
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
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> Super_IA <?php echo $is_super_admin ? '- SuperAdmin' : '- Admin'; ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($user_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($user_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-fire me-2"></i>Mapa de Calor</h1>
            <p class="text-muted mt-2">Visualización de concentración de actividad comercial</p>
        </div>

        <div id="map"></div>
    </div>
</div>

<script>
    // Inicializar mapa
    const map = L.map('map').setView([-16.3895, -63.1666], 13);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // Datos de calor
    const calorData = [
        [-16.3895, -63.1666, 0.8],
        [-16.3900, -63.1670, 0.5],
        [-16.3890, -63.1660, 0.7],
        [-16.3885, -63.1675, 0.4],
        [-16.3910, -63.1650, 0.6],
    ];

    // Crear capa de calor
    L.heatLayer(calorData, {
        radius: 25,
        blur: 15,
        maxZoom: 17
    }).addTo(map);
</script>

</body>
</html>

<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php?role=admin');
    exit;
}

// Obtener asesores/supervisores para monitoreo
$asesores = $pdo->query("
    SELECT id_usuario, nombres, apellidos, email
    FROM usuarios
    WHERE id_rol_fk IN (3, 4) AND activo = 1
    ORDER BY nombres ASC
")->fetchAll();

$currentPage = 'mapa_familiar';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa de Equipo - COAC Finance Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
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
        .content-area { flex: 1; overflow-y: auto; padding: 30px; display: flex; flex-direction: column; }
        #map { width: 100%; flex: 1; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .team-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .team-item { background: white; padding: 12px; border-radius: 8px; cursor: pointer; border: 2px solid transparent; transition: 0.3s; }
        .team-item:hover { border-color: #6b11ff; }
        .team-item.active { background: linear-gradient(135deg, #6b11ff, #7c3aed); color: white; }
        .team-item strong { font-size: 12px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
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
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_familiar.php" class="sidebar-link active">
            <i class="fas fa-users-line"></i> Mapa de Equipo
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
        <h2>🎯 COAC Finance Admin</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($_SESSION['admin_nombre']); ?></strong><br>
                <small><?php echo htmlspecialchars($_SESSION['admin_rol']); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-users-line me-2"></i>Mapa de Equipo</h1>
            <p class="text-muted mt-2">Monitoreo y seguimiento del equipo de trabajo</p>
        </div>

        <!-- LISTA DE EQUIPO -->
        <div class="team-list">
            <?php foreach ($asesores as $asesor): ?>
            <div class="team-item" onclick="seleccionarEquipo(<?php echo $asesor['id_usuario']; ?>, this)">
                <strong><?php echo htmlspecialchars($asesor['nombres']); ?></strong>
                <div style="font-size: 11px; opacity: 0.8;">
                    <i class="fas fa-envelope"></i> <?php echo substr($asesor['email'], 0, 15); ?>
                </div>
            </div>
            <?php endforeach; ?>
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

    // Datos de ejemplo del equipo
    const teamData = {
        1: { lat: -16.3895, lng: -63.1666, nombre: 'Supervisor 1' },
        2: { lat: -16.3900, lng: -63.1670, nombre: 'Asesor 1' },
        3: { lat: -16.3890, lng: -63.1660, nombre: 'Asesor 2' }
    };

    let currentMarker = null;

    function seleccionarEquipo(id, element) {
        document.querySelectorAll('.team-item').forEach(el => el.classList.remove('active'));
        element.classList.add('active');

        if (currentMarker) map.removeLayer(currentMarker);
        
        if (teamData[id]) {
            const pos = teamData[id];
            currentMarker = L.marker([pos.lat, pos.lng])
                .bindPopup(`<strong>${pos.nombre}</strong>`)
                .addTo(map);
            map.setView([pos.lat, pos.lng], 15);
        }
    }

    // Mostrar todos los puntos al inicio
    Object.entries(teamData).forEach(([id, data]) => {
        L.marker([data.lat, data.lng])
            .bindPopup(`<strong>${data.nombre}</strong>`)
            .addTo(map);
    });
</script>

</body>
</html>

        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Inter', sans-serif;
            background-color: var(--light);
        }

        #map {
            height: 100vh;
            width: 100%;
            z-index: 1;
        }

        .header-status {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: white;
            padding: 12px 24px;
            border-radius: 50px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 300px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .driver-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .driver-info h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--dark);
        }

        .driver-info p {
            margin: 0;
            font-size: 12px;
            color: #64748b;
        }

        .status-badge {
            margin-left: auto;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-offline { background: #fee2e2; color: #ef4444; }
        .status-online { background: #dcfce7; color: #22c55e; }

        .logout-btn {
            position: absolute;
            bottom: 30px;
            right: 20px;
            z-index: 1000;
            background: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            color: #ef4444;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
            background: #fffafa;
        }

        .pulse {
            width: 10px;
            height: 10px;
            background: #22c55e;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            box-shadow: 0 0 0 rgba(34, 197, 94, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        /* Leaflet Popup Styling */
        .leaflet-popup-content-wrapper {
            border-radius: 12px;
            padding: 5px;
        }
        .leaflet-popup-tip-container { display: none; }
    </style>
</head>
<body>

    <div class="header-status">
        <div class="driver-avatar"><i class="fas fa-user-tie"></i></div>
        <div class="driver-info">
            <h2><?= htmlspecialchars($conductorNombre) ?></h2>
            <p id="last-update">Buscando ubicación...</p>
        </div>
        <div id="status-tag" class="status-badge status-offline">Desconectado</div>
    </div>

    <button class="logout-btn" onclick="location.href='logout.php'">
        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
    </button>

    <div id="map"></div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <script>
        // Inicializar el mapa centrado en una posición por defecto (Ecuador por ejemplo)
        const map = L.map('map').setView([-0.1807, -78.4678], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        let driverMarker = null;
        let isFirstLoad = true;

        // Icono personalizado para el conductor
        const driverIcon = L.divIcon({
            className: 'custom-div-icon',
            html: `
                <div style="background-color: #6b11ff; width: 40px; height: 40px; border-radius: 50%; border: 3px solid white; display: flex; align-items: center; justify-content: center; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
                    <i class="fas fa-car" style="color: white; font-size: 18px;"></i>
                </div>
            `,
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });

        async function updateLocation() {
            try {
                const response = await fetch('api_ubicacion_familiar.php');
                const data = await response.json();

                if (data.status === 'success' && data.latitud && data.longitud) {
                    const pos = [data.latitud, data.longitud];
                    
                    if (!driverMarker) {
                        driverMarker = L.marker(pos, { icon: driverIcon }).addTo(map);
                        driverMarker.bindPopup(`<b>${data.nombre}</b><br>Ubicación actual`).openPopup();
                    } else {
                        driverMarker.setLatLng(pos);
                    }

                    if (isFirstLoad) {
                        map.setView(pos, 16);
                        isFirstLoad = false;
                    }

                    // Actualizar UI
                    document.getElementById('last-update').innerHTML = '<span class="pulse"></span> En vivo ahora';
                    const tag = document.getElementById('status-tag');
                    if (data.estado === 'libre' || data.estado === 'ocupado') {
                        tag.innerText = 'En Línea';
                        tag.className = 'status-badge status-online';
                    } else {
                        tag.innerText = 'Desconectado';
                        tag.className = 'status-badge status-offline';
                    }
                } else if (data.status === 'error' && data.message === 'Sesion no iniciada') {
                    window.location.href = 'login.php';
                }
            } catch (error) {
                console.error('Error al actualizar la ubicación:', error);
            }
        }

        // Actualizar cada 5 segundos
        setInterval(updateLocation, 5000);
        updateLocation(); // Ejecutar inmediatamente al cargar
    </script>

</body>
</html>

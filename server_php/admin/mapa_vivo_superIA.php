<?php
// ============================================================
// admin/mapa_vivo.php — Mapa en Tiempo Real de Asesores
// Real-time GPS tracking map for advisors
// ============================================================

require_once 'db_admin_superIA.php';
require_once '../db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;
if (!$is_supervisor) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_id = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? 'Supervisor';

// Obtener ubicaciones activas de asesores (ventana reciente)
$ubicaciones = [];
$error_msg = '';

try {
    // Consulta simplificada sin referencia a columnas inexistentes
    $query = "
        SELECT DISTINCT
            ua.id, ua.asesor_id, ua.latitud, ua.longitud, ua.timestamp,
            u.nombre as asesor_nombre,
            COALESCE(ua.precision_m, 0) as precision_m
        FROM ubicacion_asesor ua
        INNER JOIN asesor a ON a.id = ua.asesor_id
        INNER JOIN supervisor s ON s.id = a.supervisor_id
        INNER JOIN usuario su ON su.id = s.usuario_id
        INNER JOIN usuario u ON u.id = a.usuario_id
        WHERE s.usuario_id = ?
        AND ua.timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND ua.latitud IS NOT NULL 
        AND ua.longitud IS NOT NULL
        ORDER BY ua.asesor_id DESC, ua.timestamp DESC
    ";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $supervisor_id);
        if (!$stmt->execute()) {
            $error_msg = "Error en query: " . $stmt->error;
            error_log($error_msg);
        } else {
            $result = $stmt->get_result();
            
            // Obtener solo la ubicación más reciente de cada asesor
            $ubicaciones_map = [];
            while ($row = $result->fetch_assoc()) {
                $asesor_id = $row['asesor_id'];
                if (!isset($ubicaciones_map[$asesor_id])) {
                    $ubicaciones_map[$asesor_id] = $row;
                }
            }
            $ubicaciones = array_values($ubicaciones_map);
        }
        $stmt->close();
    } else {
        $error_msg = "Error preparando statement: " . $conn->error;
        error_log($error_msg);
    }
} catch (Exception $e) {
    $error_msg = "Excepción: " . $e->getMessage();
    error_log($error_msg);
}

$currentPage = 'mapa';
$totalPendientes = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Supervisor - Mapa en Vivo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet Map -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <!-- Leaflet Marker Cluster -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.4.1/MarkerCluster.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.4.1/leaflet.markercluster.min.js"></script>
    <style>
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
        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, var(--brand-bg) 100%);
            display: flex;
            height: 100vh;
            color: var(--brand-navy-deep);
        }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%);
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
            border-bottom: 1px solid rgba(255,221,0,0.18);
            margin-bottom: 20px;
        }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.58);
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
            border-radius: 10px;
            color: rgba(255,255,255,0.82);
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            font-size: 14px;
            border: 1px solid transparent;
        }
        .sidebar-link:hover {
            background: rgba(255,221,0,0.12);
            color: #fff;
            padding-left: 20px;
            border-color: rgba(255,221,0,0.15);
        }
        .sidebar-link.active {
            background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep));
            color: var(--brand-navy-deep);
            font-weight: 700;
            box-shadow: 0 10px 24px rgba(255,221,0,0.18);
        }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom {
            background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy));
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18);
        }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout {
            background: rgba(255,221,0,0.15);
            color: white;
            border: 1px solid rgba(255,221,0,0.28);
            padding: 8px 15px;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
        }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 20px; position: relative; }

        #map {
            height: calc(100vh - 170px);
            width: 100%;
            border-radius: 18px;
            box-shadow: var(--brand-shadow);
            border: 1px solid var(--brand-border);
        }
        .info-box {
            position: absolute;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
            z-index: 400;
            max-width: 300px;
        }
        .asesor-item {
            padding: 10px;
            margin-bottom: 8px;
            background: #f9f9f9;
            border-left: 3px solid #FBBF24;
            border-radius: 4px;
            font-size: 13px;
        }
        .asesor-item .name {
            font-weight: 600;
            color: #1f2937;
        }
        .asesor-item .status {
            color: #999;
            margin-top: 3px;
        }
        .online-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #10B981;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.2); }
        }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> COAC Finance
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="supervisor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link active">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestion</div>
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
</div>

<div class="main-content">
    <div class="navbar-custom">
        <div>
            <h2><i class="fas fa-map-location-dot me-2" style="color: var(--brand-yellow);"></i>COAC Finance - Supervisor</h2>
            <small style="opacity:0.85;"><span data-ubicaciones-count><?= count($ubicaciones) ?></span> asesores localizados</small>
        </div>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($supervisor_nombre) ?></strong><br>
                <small>supervisor</small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesion</a>
        </div>
    </div>

    <div class="content-area">
        <div class="d-flex align-items-center justify-content-between" style="margin-bottom: 14px;">
            <div>
                <h1 style="margin:0;font-size:28px;font-weight:800;color:var(--brand-navy-deep);">
                    <i class="fas fa-map me-2"></i>Mapa en Vivo
                </h1>
                <div style="color: var(--brand-gray); margin-top: 6px;">Monitoreo en tiempo real de asesores</div>
            </div>
            <button id="refresh-map" class="btn" style="background: rgba(255,221,0,0.18); color: var(--brand-navy-deep); border: 1px solid rgba(255,221,0,0.35); font-weight: 700; border-radius: 12px; padding: 10px 14px;">
                <i class="fas fa-sync-alt"></i> Actualizar
            </button>
        </div>

        <div id="map"></div>

        <div class="info-box">
                <div style="margin-bottom: 15px;">
                    <h6 style="margin: 0 0 10px; color: #1f2937;">
                        <i class="fas fa-users-check"></i> Asesores Activos
                    </h6>
                    <span class="badge bg-warning" style="color: #1a0f3d; font-size: 12px;">
                        <?= count($ubicaciones) ?> en línea
                    </span>
                </div>
                
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php if (count($ubicaciones) > 0): ?>
                        <?php foreach ($ubicaciones as $loc): ?>
                        <div class="asesor-item">
                            <div class="name">
                                <span class="online-dot"></span>
                                <?= htmlspecialchars($loc['asesor_nombre']) ?>
                            </div>
                            <div class="status">
                                <?php if (!empty($loc['cliente_nombre'] ?? '')): ?>
                                    <i class="fas fa-briefcase"></i> <?= htmlspecialchars($loc['cliente_nombre']) ?>
                                <?php else: ?>
                                    <i class="fas fa-location-dot"></i> En ruta
                                <?php endif; ?>
                            </div>
                            <div class="status" style="font-size: 11px;">
                                <i class="fas fa-clock"></i> 
                                <?= date('H:i', strtotime($loc['timestamp'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="text-align: center; color: #999; padding: 20px 10px;">
                            <i class="fas fa-map" style="font-size: 30px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                            <small>Sin ubicaciones disponibles</small>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="border-top: 1px solid #ddd; margin-top: 15px; padding-top: 10px; font-size: 11px; color: #999;">
                    <i class="fas fa-sync-alt"></i> Actualiza cada 10 segundos
                </div>
            </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Inicializar mapa
const map = L.map('map').setView([-0.9, -78.5], 11);

// Tile layer (OpenStreetMap)
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

// Marker cluster group
let markerGroup = new L.MarkerClusterGroup({
    maxClusterRadius: 80
});

// Colores base
const colors = {
    amarillo: '#FBBF24',
    ambar: '#F59E0B',
    verde: '#10B981',
    azul: '#3B82F6',
    rojo: '#EF4444'
};

// Datos de ubicaciones (PHP to JS)
let ubicaciones = <?= json_encode($ubicaciones) ?>;

// Función para agregar marcadores
function addMarkersToMap(locs) {
    // Limpiar marcadores anteriores
    markerGroup.clearLayers();
    
    locs.forEach((loc) => {
        const lat = parseFloat(loc.latitud);
        const lng = parseFloat(loc.longitud);
        
        if (lat && lng) {
            // Crear icono personalizado
            const icon = L.divIcon({
                html: `
                    <div style="
                        background: linear-gradient(135deg, ${colors.amarillo}, ${colors.ambar});
                        border: 3px solid white;
                        border-radius: 50%;
                        width: 40px;
                        height: 40px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-size: 20px;
                        box-shadow: 0 2px 8px rgba(0,0,0,.2);
                        z-index: 10;
                    ">
                        <i class="fas fa-user-tie"></i>
                    </div>
                `,
                iconSize: [40, 40],
                className: 'custom-icon'
            });

            // Crear marcador
            const marker = L.marker([lat, lng], {icon: icon})
                .bindPopup(`
                    <div style="min-width: 200px; font-size: 12px;">
                        <h6 style="margin: 0 0 8px; color: #1f2937; font-weight: 600;">
                            ${loc.asesor_nombre}
                        </h6>
                        <div style="padding: 4px 0;">
                            <small style="color: #666;">
                                <i class="fas fa-location-dot"></i> GPS Activo
                            </small><br>
                            ${loc.precision_m ? `<small style="color: #999;">Precisión: ${loc.precision_m} m</small><br>` : ''}
                            <small style="color: #999;">
                                <i class="fas fa-clock"></i> ${new Date(loc.timestamp).toLocaleTimeString('es-ES')}
                            </small>
                        </div>
                    </div>
                `, {
                    closeButton: true,
                    maxWidth: 250
                });

            markerGroup.addLayer(marker);
        }
    });
    
    map.addLayer(markerGroup);
    
    // Ajustar zoom a todos los marcadores
    if (locs.length > 0) {
        try {
            map.fitBounds(markerGroup.getBounds(), { padding: [50, 50] });
        } catch (e) {
            console.log('No se pueden ajustar límites');
        }
    }
}

// Función para actualizar ubicaciones mediante AJAX
function updateLocations() {
    fetch('api_super_ia.php?action=get_ubicaciones_asesores', {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data && data.data.length > 0) {
            ubicaciones = data.data;
            addMarkersToMap(ubicaciones);
            
            // Actualizar contador
            document.querySelectorAll('[data-ubicaciones-count]').forEach(el => {
                el.textContent = ubicaciones.length;
            });
        }
    })
    .catch(error => console.log('Error actualizando ubicaciones:', error));
}

// Cargar marcadores iniciales
addMarkersToMap(ubicaciones);

// Auto-actualizar cada 10 segundos (en lugar de recargar página)
setInterval(updateLocations, 10000);

// Permitir actualización manual
document.addEventListener('DOMContentLoaded', () => {
    const refreshBtn = document.getElementById('refresh-map');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', updateLocations);
    }
});
</script>


</body>
</html>

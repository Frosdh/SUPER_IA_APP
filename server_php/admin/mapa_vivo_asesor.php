<?php
require_once 'db_admin.php';
require_once '../db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_asesor = isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true;
if (!$is_asesor) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_id = $_SESSION['asesor_id'] ?? null;
$asesor_nombre = $_SESSION['asesor_nombre'] ?? 'Asesor';

$currentPage = 'mapa_vivo';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Ubicación en Vivo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
        :root {
            --amarillo: #FBBF24;
            --amarillo-hover: #F59E0B;
            --azul-marino: #1e3a5f;
            --azul-claro: #2c5aa0;
            --gris: #6b7280;
            --gris-claro: #e5e7eb;
            --gris-muy-claro: #f3f4f6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--gris-muy-claro); display: flex; height: 100vh; }
        
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--azul-marino) 0%, #0f1f35 100%);
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
            border-bottom: 1px solid rgba(251, 191, 36, 0.2); 
            margin-bottom: 20px; 
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sidebar-brand i { color: var(--amarillo); font-size: 24px; }
        
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.6); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        
        .sidebar-link { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 12px 15px; 
            margin-bottom: 5px; 
            border-radius: 8px; 
            color: rgba(255,255,255,0.8); 
            cursor: pointer; 
            transition: all 0.3s ease; 
            text-decoration: none; 
            font-size: 14px; 
        }
        
        .sidebar-link:hover { 
            background: rgba(251, 191, 36, 0.15); 
            color: #fff; 
            padding-left: 20px; 
        }
        
        .sidebar-link.active { 
            background: linear-gradient(90deg, var(--amarillo), var(--amarillo-hover)); 
            color: var(--azul-marino); 
            font-weight: 600;
        }
        
        .main-content { 
            flex: 1; 
            margin-left: 230px; 
            display: flex; 
            flex-direction: column; 
            overflow: hidden; 
            min-width: 0;
        }
        
        .navbar-custom { 
            background: linear-gradient(135deg, var(--amarillo) 0%, var(--amarillo-hover) 100%); 
            color: var(--azul-marino); 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); 
        }
        
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { 
            background: rgba(30, 58, 95, 0.2); 
            color: var(--azul-marino); 
            border: 1px solid var(--azul-marino); 
            padding: 8px 15px; 
            border-radius: 6px; 
            cursor: pointer; 
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-logout:hover { 
            background: rgba(30, 58, 95, 0.3); 
            transform: translateY(-2px);
        }
        
        .content-area { flex: 1; overflow: hidden; padding: 20px; display: flex; flex-direction: column; }
        
        #map {
            height: 100%;
            width: 100%;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }
        
        .info-panel {
            position: absolute;
            bottom: 20px;
            left: 250px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            z-index: 400;
            max-width: 350px;
            border-left: 4px solid var(--amarillo);
        }
        
        .info-panel h6 { 
            margin: 0 0 15px; 
            color: var(--azul-marino); 
            font-weight: 700; 
            font-size: 14px;
        }
        
        .info-item { 
            padding: 8px 0; 
            font-size: 13px; 
            color: var(--gris);
            border-bottom: 1px solid var(--gris-claro);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-item:last-child { border-bottom: none; }
        .info-item strong { color: var(--azul-marino); }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            background: linear-gradient(135deg, var(--amarillo), var(--amarillo-hover));
            color: var(--azul-marino);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .sidebar { width: 0; }
            .main-content { margin-left: 0; }
            .info-panel { left: 20px; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-map-location-dot"></i>
        <span>Mi Mapa</span>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Navegación</div>
        <a href="asesor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_asesor.php" class="sidebar-link active">
            <i class="fas fa-map"></i> Mi Ubicación
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Mis Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> Mis Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2 style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-location-dot"></i> Mi Ubicación en Vivo
        </h2>
        <div class="user-info">
            <div style="text-align: right;">
                <strong><?php echo htmlspecialchars($asesor_nombre); ?></strong><br>
                <small style="color: rgba(30, 58, 95, 0.7);">Asesor</small>
            </div>
            <a href="logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i> Salir
            </a>
        </div>
    </div>
    
    <!-- MAP CONTAINER -->
    <div class="content-area">
        <div id="map"></div>
        
        <!-- INFO PANEL -->
        <div class="info-panel">
            <h6><i class="fas fa-user-tie"></i> Tu Información</h6>
            <div class="info-item">
                <strong>Asesor:</strong>
                <span><?php echo htmlspecialchars($asesor_nombre); ?></span>
            </div>
            <div class="info-item">
                <strong>Estado:</strong>
                <span class="status-badge"><i class="fas fa-circle"></i> En Línea</span>
            </div>
            <div class="info-item">
                <strong>Servidor:</strong>
                <span id="server-status" style="font-size:12px; color: var(--gris);">
                    <i class="fas fa-spinner fa-spin"></i> Esperando GPS...
                </span>
            </div>
            <div class="info-item">
                <strong>Ubicación:</strong>
                <span id="ubicacion-actual">Cargando...</span>
            </div>
            <div class="info-item">
                <strong>Precisión:</strong>
                <span id="precision-actual">--</span>
            </div>
            <div class="info-item">
                <strong>Actualizado:</strong>
                <span id="timestamp-actual">Ahora</span>
            </div>
            <div class="info-item">
                <strong>Últ. envío:</strong>
                <span id="ultimo-envio" style="font-size:12px; color: var(--gris);">--</span>
            </div>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--gris-claro); text-align: center;">
                <button id="btn-centrar" class="btn btn-sm" style="background: linear-gradient(135deg, var(--amarillo), var(--amarillo-hover)); color: var(--azul-marino); border: none; font-weight: 600;">
                    <i class="fas fa-crosshairs"></i> Centrar Ubicación
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Colores
const colors = {
    amarillo: '#FBBF24',
    azul_marino: '#1e3a5f',
    gris: '#6b7280'
};

// Inicializar mapa
const map = L.map('map').setView([-0.9, -78.5], 13);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap',
    maxZoom: 19
}).addTo(map);

let miMarcador = null;
let circuloAccuracia = null;

// ── Envío al servidor ──────────────────────────────────────
let lastServerSend = 0;
const SERVER_INTERVAL = 15000; // enviar cada 15 segundos

function sendLocationToServer(lat, lng, precision) {
    const now = Date.now();
    if (now - lastServerSend < SERVER_INTERVAL) return; // throttle
    lastServerSend = now;

    const formData = new FormData();
    formData.append('latitud',    lat);
    formData.append('longitud',   lng);
    formData.append('precision_m', precision);

    document.getElementById('server-status').innerHTML =
        '<i class="fas fa-sync fa-spin" style="color:#FBBF24;"></i> Enviando...';

    fetch('update_location_asesor.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('server-status');
        const hora = document.getElementById('ultimo-envio');
        if (data.status === 'success') {
            el.innerHTML = '<i class="fas fa-check-circle" style="color:#10B981;"></i> Transmitiendo';
            hora.textContent = new Date().toLocaleTimeString('es-ES');
        } else {
            el.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#EF4444;"></i> Error BD';
            console.warn('Error servidor:', data.message);
        }
    })
    .catch(err => {
        document.getElementById('server-status').innerHTML =
            '<i class="fas fa-wifi" style="color:#EF4444;"></i> Sin conexión';
        console.error('Error enviando ubicación:', err);
    });
}
// ── Fin envío al servidor ──────────────────────────────────

// Función para actualizar ubicación
function actualizarUbicacion() {
    if (navigator.geolocation) {
        navigator.geolocation.watchPosition(
            (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const precision = Math.round(position.coords.accuracy);

                // ── Enviar al servidor cada 15 segundos ──
                sendLocationToServer(lat, lng, precision);

                // Crear o actualizar marcador
                if (miMarcador) {
                    miMarcador.setLatLng([lat, lng]);
                } else {
                    const icon = L.divIcon({
                        html: `
                            <div style="
                                width: 50px;
                                height: 50px;
                                background: linear-gradient(135deg, ${colors.amarillo}, #F59E0B);
                                border: 3px solid white;
                                border-radius: 50%;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                color: ${colors.azul_marino};
                                font-size: 24px;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                                animation: pulse 2s infinite;
                            ">
                                <i class="fas fa-location-dot" style="font-size: 20px;"></i>
                            </div>
                        `,
                        iconSize: [50, 50],
                        className: 'custom-marker'
                    });

                    miMarcador = L.marker([lat, lng], { icon: icon }).addTo(map);
                }

                // Mostrar círculo de precisión
                if (circuloAccuracia) {
                    map.removeLayer(circuloAccuracia);
                }

                circuloAccuracia = L.circle([lat, lng], {
                    radius: precision,
                    color: colors.amarillo,
                    weight: 2,
                    opacity: 0.3,
                    fill: true,
                    fillColor: colors.amarillo,
                    fillOpacity: 0.1
                }).addTo(map);

                // Actualizar panel de información
                document.getElementById('ubicacion-actual').textContent = lat.toFixed(4) + ', ' + lng.toFixed(4);
                document.getElementById('precision-actual').textContent = precision + ' m';
                document.getElementById('timestamp-actual').textContent = new Date().toLocaleTimeString('es-ES');

                // Centrar mapa solo la primera vez
                if (!miMarcador._firstCenter) {
                    map.setView([lat, lng], 15);
                    miMarcador._firstCenter = true;
                }
            },
            (error) => {
                console.error('Error de geolocalización:', error);
                document.getElementById('ubicacion-actual').textContent = '❌ No permitida';
                document.getElementById('server-status').innerHTML =
                    '<i class="fas fa-ban" style="color:#EF4444;"></i> GPS denegado';
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
    } else {
        alert('Geolocalización no soportada en este navegador');
    }
}

// Botón centrar
document.getElementById('btn-centrar').addEventListener('click', () => {
    if (miMarcador) {
        const latLng = miMarcador.getLatLng();
        map.setView(latLng, 16);
    }
});

// Iniciar tracking
actualizarUbicacion();

// Añadir animación CSS
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
`;
document.head.appendChild(style);
</script>

</body>
</html>

<?php
require_once 'db_admin.php';

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuperAdmin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$isAdmin && !$isSuperAdmin) {
    header('Location: login.php?role=admin');
    exit;
}

// Obtener estadísticas de regiones
$regionStats = $pdo->query("
    SELECT r.id_region, r.nombre, COUNT(c.id_cliente) as total_clientes
    FROM region r
    LEFT JOIN clientes c ON r.id_region = c.id_region_fk
    GROUP BY r.id_region, r.nombre
    ORDER BY total_clientes DESC
")->fetchAll();

$currentPage = 'mapa';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa General - Super_IA Admin</title>
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
        .stats-sidebar { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .stat-item { background: white; padding: 12px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .stat-item .number { font-size: 20px; font-weight: 700; color: #6b11ff; }
        .stat-item .label { font-size: 12px; color: #9ca3af; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa.php" class="sidebar-link active">
            <i class="fas fa-globe"></i> Mapa General
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
        <h2>🎯 Super_IA Admin</h2>
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
            <h1><i class="fas fa-globe me-2"></i>Mapa General</h1>
            <p class="text-muted mt-2">Distribución geográfica de clientes y operaciones</p>
        </div>

        <!-- ESTADÍSTICAS POR REGIÓN -->
        <div class="stats-sidebar">
            <?php foreach ($regionStats as $stat): ?>
            <div class="stat-item">
                <div class="number"><?php echo $stat['total_clientes']; ?></div>
                <div class="label"><?php echo htmlspecialchars($stat['nombre']); ?></div>
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

    // Datos de clientes por región
    const regionData = [
        { lat: -16.3895, lng: -63.1666, region: 'Centro', clientes: 45 },
        { lat: -16.3900, lng: -63.1670, region: 'Norte', clientes: 32 },
        { lat: -16.3890, lng: -63.1660, region: 'Sur', clientes: 28 }
    ];

    regionData.forEach(region => {
        L.marker([region.lat, region.lng])
            .bindPopup(`<strong>${region.region}</strong><br>Clientes: ${region.clientes}`)
            .addTo(map);

        // Agregar círculos para visualizar densidad
        L.circle([region.lat, region.lng], {
            color: '#6b11ff',
            fillColor: '#7c3aed',
            fillOpacity: 0.1,
            radius: region.clientes * 20
        }).addTo(map);
    });
</script>

</body>
</html>
    <link rel="stylesheet" href="admin.css">

    <style>

        /* ── Layout del mapa ── */
        .map-wrapper { display:flex; flex-direction:column; height:100vh; padding:20px; gap:14px; }

        /* ── Encabezado ── */
        .map-header { display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .map-header h4 { font-weight:700; margin:0; color:#24243e; }
        .map-header small { color:#6c757d; }

        /* ── Stats top ── */
        .stats-row { display:flex; gap:12px; flex-shrink:0; }
        .stat-pill { background:#fff; border-radius:10px; padding:10px 18px; display:flex; align-items:center; gap:10px;
                     box-shadow:0 2px 10px rgba(0,0,0,.06); font-size:13px; }
        .stat-pill .dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .dot-libre   { background:#11998e; animation:pulse 1.5s infinite; }
        .dot-ocupado { background:#f46b45; animation:pulse 1.5s infinite; }
        .dot-total   { background:#6b11ff; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
        .stat-pill strong { font-size:20px; font-weight:700; color:#24243e; line-height:1; }
        .stat-pill span   { color:#6c757d; font-size:11px; }

        /* ── Mapa ── */
        #mapa { width:100%; height:calc(100vh - 220px); min-height:400px; border-radius:14px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,.10); }

        /* ── Barra de estado ── */
        .status-bar { background:#fff; border-radius:10px; padding:8px 16px; font-size:12px;
                      color:#6c757d; display:flex; align-items:center; gap:16px; flex-shrink:0;
                      box-shadow:0 2px 8px rgba(0,0,0,.05); }
        .status-bar .live-dot { width:7px; height:7px; background:#11998e; border-radius:50%;
                                display:inline-block; animation:pulse 1.5s infinite; margin-right:4px; }

        /* ── Popup personalizado ── */
        .leaflet-popup-content-wrapper { border-radius:12px !important; box-shadow:0 8px 24px rgba(0,0,0,.15) !important; padding:0 !important; }
        .leaflet-popup-content { margin:0 !important; width:220px !important; }
        .popup-card { padding:14px 16px; }
        .popup-card .popup-name { font-weight:700; font-size:14px; color:#24243e; margin-bottom:4px; }
        .popup-card .popup-estado { display:inline-flex; align-items:center; gap:5px; font-size:11px;
                                    font-weight:600; padding:2px 8px; border-radius:20px; margin-bottom:8px; }
        .estado-libre   { background:#d4f5ee; color:#0a7564; }
        .estado-ocupado { background:#ffe4d5; color:#c44a0d; }
        .popup-card .popup-row { font-size:12px; color:#555; display:flex; gap:6px; align-items:center; margin-bottom:3px; }
        .popup-card .popup-row i { color:#9b51e0; width:14px; text-align:center; flex-shrink:0; }
        .popup-card hr { border-color:#f0f0f0; margin:8px 0; }
        .popup-card .stars { color:#ffc107; font-size:12px; }

        /* ── Leyenda ── */
        .map-legend { position:absolute; bottom:30px; right:10px; z-index:1000; background:#fff;
                      border-radius:10px; padding:10px 14px; box-shadow:0 2px 12px rgba(0,0,0,.12);
                      font-size:12px; }
        .legend-item { display:flex; align-items:center; gap:7px; margin-bottom:5px; }
        .legend-item:last-child { margin-bottom:0; }
        .legend-dot { width:12px; height:12px; border-radius:50%; border:2px solid rgba(0,0,0,.2); flex-shrink:0; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- ── CONTENIDO ── -->
    <div class="col-md-10">
        <div class="map-wrapper">

            <!-- Encabezado -->
            <div class="map-header">
                <div>
                    <h4><i class="fas fa-map-marked-alt me-2" style="color:#6b11ff"></i>Mapa en Tiempo Real</h4>
                    <small>Posición actualizada de todos los conductores activos</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="status-bar" id="lastUpdate">
                        <span class="live-dot"></span>
                        Actualizando...
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="cargarConductores()">
                        <i class="fas fa-sync-alt me-1"></i>Refrescar
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-pill">
                    <div class="dot dot-libre"></div>
                    <div>
                        <strong id="cntLibre">0</strong><br>
                        <span>Disponibles</span>
                    </div>
                </div>
                <div class="stat-pill">
                    <div class="dot dot-ocupado"></div>
                    <div>
                        <strong id="cntOcupado">0</strong><br>
                        <span>En viaje</span>
                    </div>
                </div>
                <div class="stat-pill">
                    <div class="dot dot-total"></div>
                    <div>
                        <strong id="cntTotal">0</strong><br>
                        <span>Total activos</span>
                    </div>
                </div>
            </div>

            <!-- Mapa -->
            <div style="position:relative; flex:1; min-height:0;">
                <div id="mapa"></div>

                <!-- Leyenda sobre el mapa -->
                <div class="map-legend">
                    <div style="font-weight:700; font-size:12px; margin-bottom:7px; color:#24243e;">
                        <i class="fas fa-circle-info me-1" style="color:#6b11ff"></i>Leyenda
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background:#11998e;"></div>
                        <span>Disponible (libre)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background:#f46b45;"></div>
                        <span>En viaje (ocupado)</span>
                    </div>
                </div>
            </div>

            <!-- Barra de estado inferior -->
            <div class="status-bar">
                <span><span class="live-dot"></span> Actualización automática cada 10 segundos</span>
                <span>·</span>
                <span>Mapa: <strong>OpenStreetMap</strong></span>
                <span>·</span>
                <span id="footerUpdate" class="text-muted">Cargando...</span>
            </div>

        </div>
    </div>

</div>
</div>

<!-- Leaflet JS -->
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Inicializar mapa centrado en Cuenca, Ecuador ─────────────
const mapa = L.map('mapa', { zoomControl: true });
mapa.setView([-2.9001285, -79.0058965], 13);
// Forzar recalculo del tamaño después de que el DOM esté listo
setTimeout(() => { mapa.invalidateSize(); }, 300);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19,
}).addTo(mapa);

// ── Almacén de marcadores (por id de conductor) ──────────────
let marcadores = {};

// ── Crear icono personalizado SVG ────────────────────────────
function crearIcono(estado) {
    const color    = estado === 'libre' ? '#11998e' : '#f46b45';
    const colorBg  = estado === 'libre' ? '#d4f5ee' : '#ffe4d5';
    const svg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="44" viewBox="0 0 36 44">
          <filter id="sombra" x="-30%" y="-20%" width="160%" height="160%">
            <feDropShadow dx="0" dy="2" stdDeviation="2" flood-color="rgba(0,0,0,0.3)"/>
          </filter>
          <path d="M18 0 C8.06 0 0 8.06 0 18 C0 31.5 18 44 18 44 C18 44 36 31.5 36 18 C36 8.06 27.94 0 18 0Z"
                fill="${color}" filter="url(#sombra)"/>
          <circle cx="18" cy="17" r="11" fill="${colorBg}"/>
          <text x="18" y="22" text-anchor="middle" font-size="13" font-family="Arial" fill="${color}">🚗</text>
        </svg>`;
    return L.divIcon({
        html: svg,
        iconSize:   [36, 44],
        iconAnchor: [18, 44],
        popupAnchor:[0, -44],
        className: '',
    });
}

// ── Construir contenido del popup ────────────────────────────
function buildPopup(c) {
    const estClass = c.estado === 'libre' ? 'estado-libre' : 'estado-ocupado';
    const estLabel = c.estado === 'libre' ? '● Disponible' : '● En viaje';
    const vehiculo = (c.marca && c.modelo) ? `${c.marca} ${c.modelo} · ${c.color ?? ''} · ${c.placa ?? ''}` : 'Sin vehículo';
    const categoria = c.categoria ?? '—';
    const stars = generarEstrellas(parseFloat(c.calificacion_promedio ?? 5));

    return `
    <div class="popup-card">
        <div class="popup-name"><i class="fas fa-user-circle me-1" style="color:#6b11ff"></i>${escHtml(c.nombre)}</div>
        <div class="popup-estado ${estClass}">${estLabel}</div>
        <hr>
        <div class="popup-row"><i class="fas fa-phone"></i> ${escHtml(c.telefono)}</div>
        <div class="popup-row"><i class="fas fa-car"></i> ${escHtml(vehiculo)}</div>
        <div class="popup-row"><i class="fas fa-layer-group"></i> ${escHtml(categoria)}</div>
        <div class="popup-row"><i class="fas fa-star"></i> ${stars} ${parseFloat(c.calificacion_promedio ?? 5).toFixed(1)}</div>
    </div>`;
}

function generarEstrellas(val) {
    let html = '';
    for (let i = 1; i <= 5; i++) {
        html += `<i class="${i <= Math.round(val) ? 'fas' : 'far'} fa-star" style="color:#ffc107;font-size:11px"></i>`;
    }
    return `<span>${html}</span>`;
}

function escHtml(str) {
    if (!str) return '—';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Cargar conductores desde la API ─────────────────────────
function cargarConductores() {
    fetch('api_conductores_mapa.php')
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'ok') return;

            const idsActuales = new Set(data.conductores.map(c => String(c.id)));

            // Eliminar marcadores de conductores que ya no están activos
            for (const id in marcadores) {
                if (!idsActuales.has(id)) {
                    mapa.removeLayer(marcadores[id]);
                    delete marcadores[id];
                }
            }

            // Actualizar o crear marcadores
            data.conductores.forEach(c => {
                const lat = parseFloat(c.latitud);
                const lng = parseFloat(c.longitud);
                if (isNaN(lat) || isNaN(lng)) return;

                const id = String(c.id);
                const icono  = crearIcono(c.estado);
                const popup  = buildPopup(c);

                if (marcadores[id]) {
                    // Mover marcador existente y actualizar popup
                    marcadores[id].setLatLng([lat, lng]);
                    marcadores[id].setIcon(icono);
                    marcadores[id].getPopup().setContent(popup);
                } else {
                    // Crear marcador nuevo
                    marcadores[id] = L.marker([lat, lng], { icon: icono })
                        .bindPopup(popup, { maxWidth: 240 })
                        .addTo(mapa);
                }
            });

            // Actualizar contadores
            const libre   = data.totales.libre   ?? 0;
            const ocupado = data.totales.ocupado ?? 0;
            document.getElementById('cntLibre').textContent   = libre;
            document.getElementById('cntOcupado').textContent = ocupado;
            document.getElementById('cntTotal').textContent   = libre + ocupado;

            const hora = data.timestamp;
            document.getElementById('lastUpdate').innerHTML =
                `<span class="live-dot"></span> Última actualización: <strong>${hora}</strong>`;
            document.getElementById('footerUpdate').textContent =
                `Última actualización: ${hora} · ${libre + ocupado} conductor${libre + ocupado !== 1 ? 'es' : ''} en línea`;

            // Si no hay conductores, mostrar mensaje
            if (data.conductores.length === 0) {
                document.getElementById('lastUpdate').innerHTML =
                    `<span style="color:#f46b45">⚠</span> Sin conductores activos · ${hora}`;
            }
        })
        .catch(() => {
            document.getElementById('lastUpdate').innerHTML =
                `<span style="color:#e53935">✕</span> Error al conectar con el servidor`;
        });
}

// ── Carga inicial y auto-refresco cada 10 s ──────────────────
cargarConductores();
setInterval(cargarConductores, 10000);
</script>
</body>
</html>

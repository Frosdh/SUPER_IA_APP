<?php
// ============================================================
// admin/mapa.php — Mapa en tiempo real de conductores activos
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Conteo para el badge de pendientes en sidebar
$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0")->fetchColumn();

$currentPage = 'mapa';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Mapa en Tiempo Real</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Admin CSS -->
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

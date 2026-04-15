<?php
// ============================================================
// admin/mapa_vivo_superIA.php — Mapa en Tiempo Real de Asesores
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

$supervisor_id    = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? 'Supervisor';

// ── Contador de alertas pendientes ───────────────────────────
$totalAlertasPendientes = 0;
try {
    // Resolver el id real de la tabla supervisor (supervisor.id, no usuario.id)
    $stSup = $conn->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    if ($stSup) {
        $stSup->bind_param('s', $supervisor_id);
        $stSup->execute();
        $rowSup = $stSup->get_result()->fetch_assoc();
        $sup_table_id = $rowSup ? $rowSup['id'] : null;
        $stSup->close();

        if ($sup_table_id) {
            $stAl = $conn->prepare(
                'SELECT COUNT(*) AS cnt FROM alerta_modificacion
                 WHERE supervisor_id = ? AND vista_supervisor = 0'
            );
            if ($stAl) {
                $stAl->bind_param('s', $sup_table_id);
                $stAl->execute();
                $rowAl = $stAl->get_result()->fetch_assoc();
                $totalAlertasPendientes = (int)($rowAl['cnt'] ?? 0);
                $stAl->close();
            }
        }
    }
} catch (\Throwable $eAl) { /* Tabla puede no existir aún */ }

// ── Carga inicial de ubicaciones ─────────────────────────────
$ubicaciones = [];
$error_msg   = '';

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS asesor_presencia (
            asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
            estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "ALTER TABLE asesor_presencia
         MODIFY asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"
    );

    $query = "
        SELECT DISTINCT
            ua.asesor_id, ua.latitud, ua.longitud, ua.timestamp,
            COALESCE(ua.precision_m, 0) AS precision_m,
            u.nombre AS asesor_nombre
        FROM ubicacion_asesor ua
        INNER JOIN asesor     a  ON a.id        = ua.asesor_id
        INNER JOIN supervisor s  ON s.id        = a.supervisor_id
        INNER JOIN usuario    u  ON u.id        = a.usuario_id
        LEFT  JOIN asesor_presencia ap ON ap.asesor_id = ua.asesor_id
        WHERE s.usuario_id = ?
          AND ua.timestamp >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND ua.latitud  IS NOT NULL
          AND ua.longitud IS NOT NULL
          AND COALESCE(ap.estado, 'conectado') != 'desconectado'
        ORDER BY ua.asesor_id DESC, ua.timestamp DESC
    ";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('s', $supervisor_id);
        if (!$stmt->execute()) {
            $error_msg = 'Error en query: ' . $stmt->error;
            error_log($error_msg);
        } else {
            $result = $stmt->get_result();
            $map    = [];
            while ($row = $result->fetch_assoc()) {
                $aid = $row['asesor_id'];
                if (!isset($map[$aid])) {
                    $map[$aid] = $row;
                }
            }
            $ubicaciones = array_values($map);
        }
        $stmt->close();
    } else {
        $error_msg = 'Error preparando statement: ' . $conn->error;
        error_log($error_msg);
    }
} catch (Exception $e) {
    $error_msg = 'Excepción: ' . $e->getMessage();
    error_log($error_msg);
}

$currentPage        = 'mapa';
$alertas_pendientes = $totalAlertasPendientes; // alias para el sidebar compartido
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
$totalPendientes    = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA – Mapa en Vivo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.4.1/MarkerCluster.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.4.1/MarkerCluster.Default.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.4.1/leaflet.markercluster.min.js"></script>
    <style>
        :root {
            --brand-yellow:      #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy:        #123a6d;
            --brand-navy-deep:   #0a2748;
            --brand-gray:        #6b7280;
            --brand-border:      #d7e0ea;
            --brand-bg:          #f4f6f9;
            --brand-shadow:      0 16px 34px rgba(18,58,109,.08);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter','Segoe UI',sans-serif;
            background: linear-gradient(180deg,#f8fafc 0%,var(--brand-bg) 100%);
            display:flex; height:100vh; color:var(--brand-navy-deep);
        }
        /* ── Sidebar ── */
        .sidebar {
            width:230px; background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%);
            color:#fff; padding:20px 0; overflow-y:auto;
            position:fixed; height:100vh; left:0; top:0;
        }
        .sidebar-brand {
            padding:0 20px 30px; font-size:18px; font-weight:800;
            border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px;
        }
        .sidebar-brand i { margin-right:10px; color:var(--brand-yellow); }
        .sidebar-section { padding:0 15px; margin-bottom:25px; }
        .sidebar-section-title {
            font-size:11px; text-transform:uppercase; color:rgba(255,255,255,.58);
            letter-spacing:.5px; padding:0 10px; margin-bottom:10px; font-weight:600;
        }
        .sidebar-link {
            display:flex; align-items:center; gap:12px;
            padding:12px 15px; margin-bottom:5px; border-radius:10px;
            color:rgba(255,255,255,.82); cursor:pointer; transition:all .25s;
            text-decoration:none; font-size:14px; border:1px solid transparent;
        }
        .sidebar-link:hover { background:rgba(255,221,0,.12); color:#fff; padding-left:20px; border-color:rgba(255,221,0,.15); }
        .sidebar-link.active {
            background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));
            color:var(--brand-navy-deep); font-weight:700;
            box-shadow:0 10px 24px rgba(255,221,0,.18);
        }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }
        /* ── Layout ── */
        .main-content { flex:1; margin-left:230px; display:flex; flex-direction:column; overflow:hidden; min-width:0; }
        .navbar-custom {
            background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));
            color:#fff; padding:15px 30px;
            display:flex; justify-content:space-between; align-items:center;
            box-shadow:0 12px 28px rgba(18,58,109,.18);
        }
        .navbar-custom h2 { margin:0; font-size:20px; font-weight:700; }
        .user-info { display:flex; align-items:center; gap:15px; }
        .btn-logout {
            background:rgba(255,221,0,.15); color:#fff;
            border:1px solid rgba(255,221,0,.28); padding:8px 15px;
            border-radius:10px; cursor:pointer; text-decoration:none; font-weight:600;
        }
        .btn-logout:hover { background:rgba(255,221,0,.24); color:#fff; }
        .content-area { flex:1; overflow-y:auto; padding:20px; position:relative; }

        /* ── Mapa ── */
        #map {
            height: calc(100vh - 170px);
            width:100%; border-radius:18px;
            box-shadow:var(--brand-shadow); border:1px solid var(--brand-border);
        }

        /* ── Panel lateral ── */
        .info-box {
            position:absolute; top:84px; right:36px;
            background:#fff; padding:15px 20px; border-radius:12px;
            box-shadow:0 4px 16px rgba(0,0,0,.12); z-index:400; max-width:280px;
            pointer-events:auto;
        }
        .asesor-item {
            padding:10px; margin-bottom:8px;
            background:#f9f9f9; border-left:3px solid #FBBF24;
            border-radius:6px; font-size:13px; cursor:pointer;
        }
        .asesor-item:hover { background:#fff3cd; }
        .asesor-item .name { font-weight:600; color:#1f2937; }
        .asesor-item .status { color:#999; margin-top:3px; }
        .online-dot {
            display:inline-block; width:8px; height:8px;
            background:#10B981; border-radius:50%; margin-right:5px;
            animation:pulse 2s infinite;
        }

        /* ── Marcador ── */
        .advisor-marker { background:transparent; border:none; }
        .advisor-pin {
            width:40px; height:40px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-size:17px; font-weight:700;
            border:3px solid #fff;
            background:linear-gradient(135deg,#10B981,#0EA5E9);
            box-shadow:0 6px 20px rgba(16,185,129,.45);
        }
        .advisor-pin.stale { background:linear-gradient(135deg,#9CA3AF,#6B7280); box-shadow:none; }

        /* ── Popup ── */
        .advisor-popup { min-width:200px; font-family:'Inter',sans-serif; }
        .advisor-popup .popup-name { font-weight:700; font-size:14px; color:#1f2937; margin-bottom:6px; }
        .advisor-popup .popup-row { font-size:12px; color:#555; margin-bottom:3px; }

        /* ── Toast / Badge ── */
        #refresh-toast {
            position:fixed; bottom:24px; right:24px;
            background:#0a2748; color:#fff; padding:8px 16px;
            border-radius:20px; font-size:12px; opacity:0;
            transition:opacity .4s; z-index:9999; pointer-events:none;
        }
        #refresh-toast.show { opacity:1; }

        /* ── Leyenda rutas ── */
        .leyenda-rutas {
            position:absolute; bottom:36px; left:250px;
            background:#fff; padding:12px 16px; border-radius:12px;
            box-shadow:0 4px 16px rgba(0,0,0,.12); z-index:400;
            min-width:200px; max-width:280px; max-height:260px;
            overflow-y:auto; pointer-events:auto;
        }
        .leyenda-rutas h6 { font-size:13px; font-weight:700; margin-bottom:8px; color:#1f2937; }
        .leyenda-item { display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px; color:#374151; }
        .leyenda-color { width:32px; height:4px; border-radius:2px; flex-shrink:0; }
        .leyenda-dot   { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

        /* Marcador tarea completada */
        .task-marker-pin {
            width:28px; height:28px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-size:11px; font-weight:800;
            border:2.5px solid #fff;
            box-shadow:0 3px 10px rgba(0,0,0,.3);
        }

        @keyframes pulse {
            0%,100% { opacity:1; transform:scale(1); }
            50%      { opacity:.6; transform:scale(1.25); }
        }
    </style>
</head>
<body>

<?php require_once '_sidebar_supervisor.php'; ?>

<!-- Main -->
<div class="main-content">
    <div class="navbar-custom">
        <div>
            <h2>
                <i class="fas fa-map-location-dot me-2" style="color:var(--brand-yellow);"></i>
                Super_IA – Supervisor
            </h2>
            <small style="opacity:.85;">
                <span id="count-label"><?= count($ubicaciones) ?></span> asesores localizados
            </small>
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
        <div class="d-flex align-items-center justify-content-between" style="margin-bottom:14px;">
            <div>
                <h1 style="margin:0;font-size:28px;font-weight:800;color:var(--brand-navy-deep);">
                    <i class="fas fa-map me-2"></i>Mapa en Vivo
                </h1>
                <div style="color:var(--brand-gray);margin-top:6px;">Monitoreo en tiempo real de asesores</div>
            </div>
            <button id="refresh-btn" class="btn"
                style="background:rgba(255,221,0,.18);color:var(--brand-navy-deep);
                       border:1px solid rgba(255,221,0,.35);font-weight:700;
                       border-radius:12px;padding:10px 14px;">
                <i class="fas fa-sync-alt"></i> Actualizar
            </button>
        </div>

        <div id="map"></div>

        <!-- Leyenda de rutas -->
        <div class="leyenda-rutas" id="leyenda-rutas" style="display:none;">
            <h6><i class="fas fa-route me-1" style="color:#3B82F6;"></i>Rutas de Hoy</h6>
            <div id="leyenda-items">
                <small style="color:#9CA3AF;">Sin rutas registradas aún</small>
            </div>
        </div>

        <!-- Panel de asesores -->
        <div class="info-box">
            <div style="margin-bottom:12px;">
                <h6 style="margin:0 0 8px;color:#1f2937;font-size:14px;font-weight:700;">
                    <i class="fas fa-users-check"></i> Asesores Activos
                </h6>
                <span id="badge-count"
                      class="badge"
                      style="background:<?= count($ubicaciones) > 0 ? '#FBBF24' : '#9CA3AF' ?>;
                             color:#1a0f3d;font-size:12px;">
                    <?= count($ubicaciones) ?> en línea
                </span>
            </div>

            <div id="asesores-list" style="max-height:380px;overflow-y:auto;">
                <?php if (count($ubicaciones) > 0): ?>
                    <?php foreach ($ubicaciones as $loc): ?>
                    <div class="asesor-item"
                         data-lat="<?= htmlspecialchars($loc['latitud']) ?>"
                         data-lng="<?= htmlspecialchars($loc['longitud']) ?>"
                         data-id="<?= htmlspecialchars($loc['asesor_id']) ?>">
                        <div class="name">
                            <span class="online-dot"></span>
                            <?= htmlspecialchars($loc['asesor_nombre']) ?>
                        </div>
                        <div class="status"><i class="fas fa-location-dot"></i> En ruta</div>
                        <div class="status" style="font-size:11px;">
                            <i class="fas fa-clock"></i> <?= date('H:i', strtotime($loc['timestamp'])) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align:center;color:#999;padding:20px 10px;">
                        <i class="fas fa-map" style="font-size:30px;opacity:.3;display:block;margin-bottom:10px;"></i>
                        <small>Sin asesores en línea</small>
                    </div>
                <?php endif; ?>
            </div>

            <div style="border-top:1px solid #ddd;margin-top:12px;padding-top:10px;font-size:11px;color:#999;">
                <i class="fas fa-sync-alt"></i> Actualiza cada 10 segundos &nbsp;|&nbsp;
                <span id="last-update">--:--:--</span>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="refresh-toast">✓ Mapa actualizado</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Mapa Leaflet ────────────────────────────────────────────────
const map = L.map('map').setView([-1.65, -78.65], 7);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
}).addTo(map);

let markerGroup = (typeof L.MarkerClusterGroup === 'function')
    ? new L.MarkerClusterGroup({ maxClusterRadius: 80 })
    : L.featureGroup();
map.addLayer(markerGroup);

// Guardar marcadores por asesor_id para centrar desde el panel
const markerById = {};

// ── Helpers ───────────────────────────────────────────────────
function parseCoord(v) {
    if (typeof v === 'number') return v;
    return parseFloat(String(v).replace(',', '.').trim());
}

function fmtHour(ts) {
    const d = new Date(ts);
    return isNaN(d.getTime()) ? '--:--' : d.toLocaleTimeString('es-ES', { hour:'2-digit', minute:'2-digit' });
}

function minutesAgo(ts) {
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 60000);
    if (diff < 1)  return 'hace unos segundos';
    if (diff === 1) return 'hace 1 min';
    return `hace ${diff} min`;
}

function showToast() {
    const t = document.getElementById('refresh-toast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2000);
}

// ── Renderizar marcadores en el mapa ──────────────────────────
// fitView=true  → mueve el mapa para mostrar todos (solo carga inicial / refresh manual)
// fitView=false → solo actualiza posiciones, NO mueve la vista
function renderMap(locs, fitView = true) {
    if (!Array.isArray(locs)) return;

    // ── Eliminar marcadores de asesores que ya no están en línea ──
    const newIds = new Set(locs.map(l => l.asesor_id));
    Object.keys(markerById).forEach(id => {
        if (!newIds.has(id)) {
            markerGroup.removeLayer(markerById[id]);
            delete markerById[id];
        }
    });

    if (locs.length === 0) return;

    const bounds = [];

    locs.forEach(loc => {
        const lat = parseCoord(loc.latitud);
        const lng = parseCoord(loc.longitud);
        if (!isFinite(lat) || !isFinite(lng)) return;

        const nombre  = loc.asesor_nombre || 'Asesor';
        const tsLabel = minutesAgo(loc.timestamp);
        const hora    = fmtHour(loc.timestamp);
        const precM   = parseFloat(loc.precision_m || 0).toFixed(0);

        // Stale: última actualización > 1 min (el servicio envía cada 15 s)
        const stale = (Date.now() - new Date(loc.timestamp).getTime()) > 60 * 1000;
        const pinClass = stale ? 'advisor-pin stale' : 'advisor-pin';

        const icon = L.divIcon({
            html: `<div class="${pinClass}" title="${nombre}"><i class="fas fa-user"></i></div>`,
            iconSize:    [40, 40],
            iconAnchor:  [20, 20],
            popupAnchor: [0, -22],
            className: 'advisor-marker'
        });

        const popupHtml = `
            <div class="advisor-popup">
                <div class="popup-name"><i class="fas fa-user-tie me-1"></i>${nombre}</div>
                <div class="popup-row"><i class="fas fa-location-dot me-1" style="color:#10B981;"></i>
                    ${lat.toFixed(6)}, ${lng.toFixed(6)}
                </div>
                <div class="popup-row"><i class="fas fa-clock me-1" style="color:#F59E0B;"></i>
                    ${hora} (${tsLabel})
                </div>
                <div class="popup-row"><i class="fas fa-bullseye me-1" style="color:#6B7280;"></i>
                    Precisión: ±${precM} m
                </div>
                ${stale ? '<div class="popup-row" style="color:#EF4444;"><i class="fas fa-exclamation-triangle me-1"></i>Sin actualización reciente</div>' : ''}
            </div>`;

        if (markerById[loc.asesor_id]) {
            // ── ACTUALIZAR marcador existente sin removerlo del mapa ──
            // Esto mantiene abiertos los popups y no mueve la vista
            const m = markerById[loc.asesor_id];
            m.setLatLng([lat, lng]);
            m.setIcon(icon);
            m.setPopupContent(popupHtml);
        } else {
            // ── NUEVO asesor: agregar marcador ──
            const marker = L.marker([lat, lng], { icon }).bindPopup(popupHtml);
            markerGroup.addLayer(marker);
            markerById[loc.asesor_id] = marker;
        }

        bounds.push([lat, lng]);
    });

    // ── Solo mover la vista cuando se pide explícitamente (carga inicial / botón manual) ──
    if (fitView && bounds.length > 0) {
        if (bounds.length === 1) {
            map.setView(bounds[0], 14);
        } else {
            map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
        }
    }
}

// ── Renderizar lista del panel lateral ───────────────────────
function renderList(locs) {
    const listEl = document.getElementById('asesores-list');
    const badge  = document.getElementById('badge-count');
    const countL = document.getElementById('count-label');
    if (!listEl) return;

    const n = Array.isArray(locs) ? locs.length : 0;
    if (badge) {
        badge.textContent = n + ' en línea';
        badge.style.background = n > 0 ? '#FBBF24' : '#9CA3AF';
    }
    if (countL) countL.textContent = n;

    if (n === 0) {
        listEl.innerHTML = `
            <div style="text-align:center;color:#999;padding:20px 10px;">
                <i class="fas fa-map" style="font-size:30px;opacity:.3;display:block;margin-bottom:10px;"></i>
                <small>Sin asesores en línea</small>
            </div>`;
        return;
    }

    listEl.innerHTML = locs.map(loc => {
        const lat = parseCoord(loc.latitud);
        const lng = parseCoord(loc.longitud);
        return `
        <div class="asesor-item"
             onclick="flyToAsesor('${loc.asesor_id}', ${lat}, ${lng})"
             title="Click para centrar en el mapa">
            <div class="name">
                <span class="online-dot"></span>
                ${loc.asesor_nombre || 'Asesor'}
            </div>
            <div class="status"><i class="fas fa-location-dot"></i> En ruta</div>
            <div class="status" style="font-size:11px;">
                <i class="fas fa-clock"></i> ${fmtHour(loc.timestamp)}
            </div>
        </div>`;
    }).join('');
}

// ── Centrar mapa al hacer click en un asesor ─────────────────
function flyToAsesor(id, lat, lng) {
    if (!isFinite(lat) || !isFinite(lng)) return;
    map.flyTo([lat, lng], 15, { duration: 1.2 });
    const m = markerById[id];
    if (m) setTimeout(() => m.openPopup(), 1300);
}

// ── Actualización AJAX (auto-refresh cada 10 s) ──────────────
// fitView = false → los marcadores se mueven, la vista del mapa NO cambia
function updateLocations(fitView = false) {
    fetch('api_ubicaciones_mapa.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                renderMap(data.ubicaciones, fitView);   // ← pasa fitView
                renderList(data.ubicaciones);
                const upd = document.getElementById('last-update');
                if (upd) upd.textContent = data.ts || '--:--:--';
                showToast();
            }
        })
        .catch(err => console.warn('[mapa] fetch error:', err));
}

// ── Render inicial con datos del servidor ─────────────────────
const ubicacionesIniciales = <?= json_encode($ubicaciones) ?>;
renderMap(ubicacionesIniciales, true);   // true → centrar al cargar por primera vez
renderList(ubicacionesIniciales);

// Timestamp inicial
const upd = document.getElementById('last-update');
if (upd) upd.textContent = new Date().toLocaleTimeString('es-ES');

// ── Auto-refresh cada 10 segundos ─────────────────────────────
// Los refrescos automáticos NO mueven la vista (fitView=false)
const REFRESH_INTERVAL = 10000; // ms
let refreshTimer = setInterval(() => updateLocations(false), REFRESH_INTERVAL);

// ── Botón manual ──────────────────────────────────────────────
// Al presionar el botón sí se centra el mapa (el usuario lo pidió explícitamente)
document.getElementById('refresh-btn').addEventListener('click', () => {
    clearInterval(refreshTimer);
    updateLocations(true);   // true → recentrar al botón manual
    cargarRutas();
    refreshTimer = setInterval(() => updateLocations(false), REFRESH_INTERVAL);
});

// ══════════════════════════════════════════════════════════════
//  SISTEMA DE RUTAS POR SEGMENTO
// ══════════════════════════════════════════════════════════════
const rutaLayers    = {};   // segmento_id → { polyline, markers[] }
const rutaGroup     = L.featureGroup().addTo(map);

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}

function renderRutas(segmentos) {
    // Limpiar capas anteriores
    rutaGroup.clearLayers();
    Object.keys(rutaLayers).forEach(k => delete rutaLayers[k]);

    const leyendaEl = document.getElementById('leyenda-items');
    const leyendaBox = document.getElementById('leyenda-rutas');
    const leyendaItems = {};

    if (!segmentos || segmentos.length === 0) {
        leyendaBox.style.display = 'none';
        return;
    }
    leyendaBox.style.display = 'block';

    segmentos.forEach(seg => {
        const color  = seg.color || '#3B82F6';
        const puntos = seg.puntos || [];
        const nombre = seg.asesor_nombre || 'Asesor';
        const num    = seg.numero;

        // ── Polilínea de la ruta ──
        if (puntos.length >= 2) {
            const latlngs = puntos.map(p => [p.lat, p.lng]);
            const poly = L.polyline(latlngs, {
                color:     color,
                weight:    4,
                opacity:   0.82,
                dashArray: seg.estado === 'activo' ? '8 5' : null,
                lineJoin:  'round',
                lineCap:   'round',
            }).addTo(rutaGroup);

            const horaInicio = seg.inicio_at ? seg.inicio_at.substring(11,16) : '--:--';
            const horaFin    = seg.fin_at    ? seg.fin_at.substring(11,16)    : 'activo';
            poly.bindPopup(`
                <div style="font-family:'Inter',sans-serif;min-width:180px;">
                    <div style="font-weight:700;font-size:13px;color:#1f2937;margin-bottom:5px;">
                        <span style="display:inline-block;width:12px;height:12px;
                              border-radius:50%;background:${color};margin-right:5px;"></span>
                        ${nombre} — Segmento ${num}
                    </div>
                    <div style="font-size:12px;color:#555;margin-bottom:2px;">
                        <i class="fas fa-clock"></i> ${horaInicio} → ${horaFin}
                    </div>
                    <div style="font-size:12px;color:#555;">
                        <i class="fas fa-map-pin"></i> ${puntos.length} puntos GPS
                    </div>
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;">
                        Estado: <b style="color:${seg.estado==='activo'?'#10B981':'#6B7280'}">${seg.estado}</b>
                    </div>
                </div>`
            );
            rutaLayers[seg.segmento_id] = { polyline: poly, markers: [] };
        }

        // ── Marcador de inicio (primer punto del segmento = login o tarea previa) ──
        if (seg.inicio_lat !== null && seg.inicio_lng !== null && num === 1) {
            const startIcon = L.divIcon({
                html: `<div style="width:16px;height:16px;border-radius:50%;
                             background:${color};border:3px solid #fff;
                             box-shadow:0 2px 8px rgba(0,0,0,.3);"
                             title="Inicio sesión ${nombre}"></div>`,
                iconSize:[16,16], iconAnchor:[8,8], className:'',
            });
            const sm = L.marker([seg.inicio_lat, seg.inicio_lng], { icon: startIcon })
                .addTo(rutaGroup)
                .bindPopup(`<div style="font-family:'Inter',sans-serif;font-size:12px;">
                    <b>${nombre}</b><br>
                    <i class="fas fa-sign-in-alt"></i> Inicio sesión<br>
                    <small>${seg.inicio_at ? seg.inicio_at.substring(11,16) : ''}</small>
                </div>`);
            if (rutaLayers[seg.segmento_id]) rutaLayers[seg.segmento_id].markers.push(sm);
        }

        // ── Marcador de tarea completada (fin del segmento) ──
        if (seg.fin_lat !== null && seg.fin_lng !== null && seg.estado !== 'activo') {
            const tareaLabel = seg.tarea_tipo
                ? seg.tarea_tipo.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
                : 'Tarea';
            const taskIcon = L.divIcon({
                html: `<div class="task-marker-pin" style="background:${color};" title="${tareaLabel}">
                           ${num}
                       </div>`,
                iconSize:[28,28], iconAnchor:[14,14], popupAnchor:[0,-16], className:'',
            });
            const tm = L.marker([seg.fin_lat, seg.fin_lng], { icon: taskIcon })
                .addTo(rutaGroup)
                .bindPopup(`<div style="font-family:'Inter',sans-serif;font-size:12px;min-width:160px;">
                    <div style="font-weight:700;color:#1f2937;margin-bottom:4px;">
                        <span style="display:inline-block;width:10px;height:10px;
                              border-radius:50%;background:${color};margin-right:4px;"></span>
                        Segmento ${num} completado
                    </div>
                    ${seg.tarea_tipo ? `<div><i class="fas fa-tasks"></i> ${tareaLabel}</div>` : ''}
                    ${seg.cliente_nombre ? `<div><i class="fas fa-user"></i> ${seg.cliente_nombre}</div>` : ''}
                    <div style="color:#6B7280;font-size:11px;margin-top:3px;">
                        ${seg.fin_at ? seg.fin_at.substring(11,16) : ''}
                    </div>
                </div>`);
            if (rutaLayers[seg.segmento_id]) rutaLayers[seg.segmento_id].markers.push(tm);
        }

        // ── Punto final si es logout ──
        if (seg.fin_lat !== null && seg.fin_lng !== null && seg.estado === 'cerrado_logout') {
            const logoutIcon = L.divIcon({
                html: `<div style="width:14px;height:14px;border-radius:50%;
                             background:#EF4444;border:3px solid #fff;
                             box-shadow:0 2px 8px rgba(239,68,68,.4);"
                             title="Cierre sesión"></div>`,
                iconSize:[14,14], iconAnchor:[7,7], className:'',
            });
            L.marker([seg.fin_lat, seg.fin_lng], { icon: logoutIcon })
                .addTo(rutaGroup)
                .bindPopup(`<div style="font-family:'Inter',sans-serif;font-size:12px;">
                    <b style="color:#EF4444;"><i class="fas fa-sign-out-alt"></i> Fin de sesión</b><br>
                    <b>${nombre}</b><br>
                    <small>${seg.fin_at ? seg.fin_at.substring(11,16) : ''}</small>
                </div>`);
        }

        // ── Leyenda ──
        const key = `${seg.asesor_id}`;
        if (!leyendaItems[key]) {
            leyendaItems[key] = {
                nombre, segmentos: [], color,
            };
        }
        leyendaItems[key].segmentos.push({ num, color, estado: seg.estado, cliente: seg.cliente_nombre });
    });

    // Renderizar leyenda
    let leyendaHtml = '';
    Object.values(leyendaItems).forEach(item => {
        leyendaHtml += `<div style="margin-bottom:10px;">
            <div style="font-weight:700;font-size:12px;color:#1f2937;margin-bottom:4px;">
                <i class="fas fa-user-tie"></i> ${item.nombre}
            </div>`;
        item.segmentos.forEach(s => {
            const estadoIcon = s.estado === 'activo' ? '🟢' : (s.estado === 'completado' ? '✓' : '🔴');
            leyendaHtml += `<div class="leyenda-item">
                <div class="leyenda-color" style="background:${s.color};"></div>
                <span>${estadoIcon} Seg. ${s.num}${s.cliente ? ' · ' + s.cliente.split(' ')[0] : ''}</span>
            </div>`;
        });
        leyendaHtml += `</div>`;
    });
    leyendaEl.innerHTML = leyendaHtml || '<small style="color:#9CA3AF;">Sin rutas hoy</small>';
}

function cargarRutas() {
    fetch('api_rutas_supervisor.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                renderRutas(data.segmentos);
            }
        })
        .catch(err => console.warn('[rutas] fetch error:', err));
}

// Carga inicial de rutas
cargarRutas();

// Refrescar rutas cada 20 s (los puntos se acumulan en BD)
setInterval(cargarRutas, 20000);
</script>
</body>
</html>

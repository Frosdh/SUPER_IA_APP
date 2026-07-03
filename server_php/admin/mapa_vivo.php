<?php
require_once 'db_admin.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    header('Location: mapa_vivo_superIA.php');
    exit;
}

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

$user_nombre = $is_super_admin ? $_SESSION['super_admin_nombre'] : $_SESSION['admin_nombre'];
$user_rol = $is_super_admin ? $_SESSION['super_admin_rol'] : $_SESSION['admin_rol'];
$currentPage = 'mapa_vivo';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa en Vivo - Super_IA Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-bg: #f4f6f9;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--brand-bg); display: flex; height: 100vh; }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: sticky;
            height: 100vh;
            top: 0;
            flex-shrink: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 24px rgba(18, 58, 109, 0.16); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.14); color: white; border: 1px solid rgba(255,221,0,0.24); padding: 8px 15px; border-radius: 10px; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        #map { width: 100%; height: 72vh; border-radius: 18px; box-shadow: 0 18px 36px rgba(18, 58, 109, 0.12); }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }

        .map-toolbar {
            display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px;
            background: #fff; border-radius: 14px; padding: 14px 18px; margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(18, 58, 109, 0.08);
        }
        .map-toolbar .field { display: flex; flex-direction: column; gap: 5px; min-width: 200px; }
        .map-toolbar label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--brand-navy-deep); }
        .map-toolbar select {
            padding: 9px 12px; border-radius: 9px; border: 1.5px solid #E2E8F0;
            font-size: 13.5px; font-family: 'Inter', sans-serif; color: #0D1929; background: #fff;
        }
        .map-toolbar .status { margin-left: auto; font-size: 12.5px; color: #64748B; display: flex; align-items: center; gap: 8px; }
        .map-toolbar .dot { width: 9px; height: 9px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .map-legend {
            display: flex; flex-wrap: wrap; gap: 10px 18px; background: #fff; border-radius: 14px;
            padding: 12px 18px; margin-top: 14px; box-shadow: 0 8px 20px rgba(18, 58, 109, 0.08);
            font-size: 12.5px; color: #334155;
        }
        .map-legend .item { display: flex; align-items: center; gap: 7px; }
        .map-legend .swatch { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .map-empty {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: rgba(255,255,255,.95); padding: 14px 22px; border-radius: 12px;
            font-size: 13.5px; color: #64748B; box-shadow: 0 8px 20px rgba(0,0,0,.1); z-index: 500;
            pointer-events: none;
        }
        .map-wrap { position: relative; }
    </style>
</head>
<body>
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
        <?php else: ?>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <?php endif; ?>
        <a href="mapa_vivo.php" class="sidebar-link active">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php if ($is_super_admin || $is_admin): ?>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php endif; ?>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestion</div>
        <?php if ($is_super_admin || $is_admin): ?>
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
</div>
<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-map-location-dot me-2" style="color: #ffdd00;"></i>Super_IA <?php echo $is_super_admin ? '- SuperAdmin' : '- Admin'; ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($user_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($user_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesion</a>
        </div>
    </div>
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-map me-2"></i>Mapa en Vivo</h1>
            <p class="text-muted mt-2">Ubicación en tiempo real de los asesores en campo, agrupados por equipo</p>
        </div>

        <div class="map-toolbar">
            <div class="field">
                <label>Gerente</label>
                <select id="filtro-gerente">
                    <option value="">Todos los gerentes</option>
                </select>
            </div>
            <div class="field">
                <label>Supervisor</label>
                <select id="filtro-supervisor">
                    <option value="">Todos los supervisores</option>
                </select>
            </div>
            <div class="status">
                <span class="dot" id="status-dot"></span>
                <span id="status-text">Cargando…</span>
            </div>
        </div>

        <div class="map-wrap">
            <div id="map"></div>
        </div>
        <div class="map-legend" id="map-legend"></div>
    </div>
</div>
<script>
const map = L.map('map').setView([-16.3895, -63.1666], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

let markers = [];
let emptyMsgEl = null;
let allSupervisores = []; // [{id, nombre, jefe_agencia_id}]
let firstLoad = true;

const selGerente = document.getElementById('filtro-gerente');
const selSupervisor = document.getElementById('filtro-supervisor');
const statusDot = document.getElementById('status-dot');
const statusText = document.getElementById('status-text');
const legendEl = document.getElementById('map-legend');

function poblarSupervisores(gerenteId) {
    const actual = selSupervisor.value;
    selSupervisor.innerHTML = '<option value="">Todos los supervisores</option>';
    allSupervisores
        .filter(s => !gerenteId || String(s.jefe_agencia_id) === String(gerenteId))
        .forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.nombre;
            selSupervisor.appendChild(opt);
        });
    // Conserva la selección previa si sigue siendo válida
    if ([...selSupervisor.options].some(o => o.value === actual)) {
        selSupervisor.value = actual;
    }
}

function limpiarMarcadores() {
    markers.forEach(m => map.removeLayer(m));
    markers = [];
    if (emptyMsgEl) { emptyMsgEl.remove(); emptyMsgEl = null; }
}

function mostrarVacio() {
    if (emptyMsgEl) return;
    emptyMsgEl = document.createElement('div');
    emptyMsgEl.className = 'map-empty';
    emptyMsgEl.textContent = 'No hay asesores en línea con este filtro en este momento.';
    document.querySelector('.map-wrap').appendChild(emptyMsgEl);
}

function actualizarLeyenda(ubicaciones) {
    const equipos = new Map();
    ubicaciones.forEach(u => {
        if (!equipos.has(u.supervisor_id)) {
            equipos.set(u.supervisor_id, { nombre: u.supervisor_nombre, color: u.color });
        }
    });
    legendEl.innerHTML = '';
    if (equipos.size === 0) {
        legendEl.innerHTML = '<span style="color:#94a3b8;">Sin asesores conectados para mostrar leyenda.</span>';
        return;
    }
    equipos.forEach(eq => {
        const item = document.createElement('div');
        item.className = 'item';
        item.innerHTML = `<span class="swatch" style="background:${eq.color}"></span> Equipo de ${eq.nombre}`;
        legendEl.appendChild(item);
    });
}

async function cargarUbicaciones() {
    const params = new URLSearchParams();
    if (selGerente.value) params.set('gerente_id', selGerente.value);
    if (selSupervisor.value) params.set('supervisor_id', selSupervisor.value);

    try {
        const resp = await fetch('api_mapa_vivo_admin.php?' + params.toString(), { credentials: 'same-origin' });
        const data = await resp.json();

        if (data.status !== 'ok') {
            statusDot.style.background = '#ef4444';
            statusText.textContent = 'Error al actualizar: ' + (data.error || 'desconocido');
            return;
        }

        if (firstLoad) {
            allSupervisores = data.supervisores || [];
            selGerente.innerHTML = '<option value="">Todos los gerentes</option>' +
                (data.gerentes || []).map(g => `<option value="${g.id}">${g.nombre}</option>`).join('');
            poblarSupervisores('');
            firstLoad = false;
        }

        limpiarMarcadores();
        const ubicaciones = data.ubicaciones || [];

        if (ubicaciones.length === 0) {
            mostrarVacio();
        } else {
            ubicaciones.forEach(u => {
                const marker = L.circleMarker([parseFloat(u.latitud), parseFloat(u.longitud)], {
                    radius: 9,
                    color: '#fff',
                    weight: 2,
                    fillColor: u.color,
                    fillOpacity: 0.95
                }).addTo(map);
                marker.bindPopup(
                    `<strong>${u.asesor_nombre}</strong><br>` +
                    `Supervisor: ${u.supervisor_nombre}<br>` +
                    `Gerente: ${u.gerente_nombre}<br>` +
                    `<small>Última actualización: ${u.timestamp}</small>`
                );
                markers.push(marker);
            });
        }

        actualizarLeyenda(ubicaciones);
        statusDot.style.background = '#22c55e';
        statusText.textContent = `Actualizado ${data.ts} · ${data.total} asesor(es) en línea`;
    } catch (err) {
        statusDot.style.background = '#ef4444';
        statusText.textContent = 'Sin conexión con el servidor';
    }
}

selGerente.addEventListener('change', () => {
    poblarSupervisores(selGerente.value);
    cargarUbicaciones();
});
selSupervisor.addEventListener('change', cargarUbicaciones);

cargarUbicaciones();
setInterval(cargarUbicaciones, 15000);

map.whenReady(() => {
    setTimeout(() => map.invalidateSize(), 300);
});
window.addEventListener('load', () => setTimeout(() => map.invalidateSize(), 350));
window.addEventListener('resize', () => map.invalidateSize());
</script>
</body>
</html>

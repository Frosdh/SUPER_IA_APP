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
    <script src="js/cooperativa_buscador.js"></script>
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
        .map-wrap { position: relative; flex: 1; min-width: 0; }
        .map-row { display: flex; gap: 16px; align-items: stretch; }

        /* ── Panel de asesores (derecha) ── */
        .panel-asesores {
            width: 270px; flex-shrink: 0; background: #fff; border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,.10); display: flex; flex-direction: column;
            overflow: hidden; border: 1px solid #d7e0ea; max-height: 72vh;
        }
        .panel-header {
            background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy));
            color: #fff; padding: 14px 16px; display: flex;
            justify-content: space-between; align-items: center; flex-shrink: 0;
        }
        .panel-header span:first-child { font-weight: 700; font-size: 14px; }
        .panel-online-badge {
            background: #10B981; color: #fff; font-size: 11px;
            padding: 3px 9px; border-radius: 20px; font-weight: 700;
        }
        .panel-body { flex: 1; overflow-y: auto; padding: 8px; }
        .panel-section-title {
            font-size: 10px; font-weight: 700; letter-spacing: .6px;
            text-transform: uppercase; padding: 8px 8px 4px;
            display: flex; align-items: center; gap: 6px;
        }
        .panel-section-title.online-title  { color: #059669; }
        .panel-section-title.offline-title { color: #9CA3AF; margin-top: 6px; }
        .panel-section-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
        .asesor-item {
            padding: 9px 10px; margin-bottom: 5px; border-radius: 10px;
            cursor: pointer; transition: all .18s; font-size: 13px;
            border: 1px solid transparent;
        }
        .asesor-item.item-online  { background: #f0fdf4; border-color: #bbf7d0; }
        .asesor-item.item-online:hover  { background: #dcfce7; border-color: #86efac; }
        .asesor-item.item-offline { background: #f9fafb; border-color: #e5e7eb; }
        .asesor-item.item-offline:hover { background: #f3f4f6; border-color: #d1d5db; }
        .asesor-item.selected {
            box-shadow: 0 0 0 2px var(--brand-yellow-deep);
            border-color: var(--brand-yellow-deep) !important;
        }
        .asesor-item .item-name {
            font-weight: 700; color: #1f2937; display: flex;
            align-items: center; gap: 6px; margin-bottom: 3px;
        }
        .asesor-item .item-meta { color: #6b7280; font-size: 11px; }
        .online-dot {
            display: inline-block; width: 8px; height: 8px;
            background: #10B981; border-radius: 50%; flex-shrink: 0;
        }
        .offline-dot {
            display: inline-block; width: 8px; height: 8px;
            background: #9CA3AF; border-radius: 50%; flex-shrink: 0;
        }
        .panel-empty { text-align: center; color: #9CA3AF; font-size: 12px; padding: 10px 8px; }
        .panel-footer {
            background: #f8f9fa; border-top: 1px solid #e5e7eb;
            padding: 8px 12px; font-size: 11px; color: #9CA3AF;
            flex-shrink: 0; text-align: center;
        }

        /* ── Combobox de búsqueda "Banco / Cooperativa" (misma lógica que
           registro_admin/supervisor/asesor.php, vía js/cooperativa_buscador.js,
           re-estilado para el tema claro de este panel) ── */
        .coop-buscador-wrap { position: relative; }
        .coop-buscador-clear {
            position: absolute; right: 9px; top: 34px;
            border: none; background: transparent; color: #94a3b8;
            cursor: pointer; font-size: 13px; padding: 4px; display: none;
        }
        .coop-buscador-clear:hover { color: #ef4444; }
        .coop-buscador-clear.show { display: block; }
        .coop-buscador-list {
            display: none; position: absolute; top: 100%; left: 0; right: 0;
            z-index: 60; max-height: 260px; overflow-y: auto;
            background: #fff; border: 1.5px solid #E2E8F0; border-radius: 10px;
            margin-top: 6px; box-shadow: 0 12px 28px rgba(18,58,109,.16);
        }
        .coop-buscador-item { padding: 9px 14px; font-size: 13.5px; color: #0D1929; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .coop-buscador-item:last-child { border-bottom: none; }
        .coop-buscador-item:hover { background: rgba(255,221,0,.16); }
        .coop-buscador-empty { padding: 10px 14px; font-size: 12.5px; color: #94a3b8; font-style: italic; }
    </style>
</head>
<body>
<?php if ($is_super_admin): ?>
    <?php $currentPage = 'mapa'; require_once '_sidebar_super_admin.php'; ?>
<?php else: ?>
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> Super_IA
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link active">
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
        <div class="sidebar-section-title">Gestion</div>
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
</div>
<?php endif; ?>
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
            <div class="field coop-buscador-wrap">
                <label for="filtro-banco-buscar">Banco / Cooperativa</label>
                <input type="text" class="form-control" id="filtro-banco-buscar"
                       placeholder="Escribe para buscar…" autocomplete="off"
                       style="padding:9px 30px 9px 12px;border-radius:9px;border:1.5px solid #E2E8F0;font-size:13.5px;font-family:'Inter',sans-serif;color:#0D1929;background:#fff;">
                <input type="hidden" id="filtro-banco">
                <button type="button" class="coop-buscador-clear" id="filtro-banco-clear" title="Quitar filtro">
                    <i class="fas fa-times-circle"></i>
                </button>
                <div id="filtro-banco-lista" class="coop-buscador-list"></div>
            </div>
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
            <div class="field">
                <label>Asesor</label>
                <select id="filtro-asesor">
                    <option value="">Todos los asesores</option>
                </select>
            </div>
            <div class="status">
                <span class="dot" id="status-dot"></span>
                <span id="status-text">Cargando…</span>
            </div>
        </div>

        <div class="map-row">
            <div class="map-wrap">
                <div id="map"></div>
            </div>

            <div class="panel-asesores">
                <div class="panel-header">
                    <span><i class="fas fa-users me-2"></i>Asesores</span>
                    <span class="panel-online-badge" id="panel-badge">0 en línea</span>
                </div>
                <div class="panel-body" id="panel-body">
                    <div class="panel-section-title online-title">
                        <span class="panel-section-dot" style="background:#10B981;"></span>
                        EN LÍNEA (<span id="count-online">0</span>)
                    </div>
                    <div id="panel-online">
                        <div class="panel-empty">Sin asesores en línea</div>
                    </div>

                    <div class="panel-section-title offline-title">
                        <span class="panel-section-dot" style="background:#9CA3AF;"></span>
                        DESCONECTADOS (<span id="count-offline">0</span>)
                    </div>
                    <div id="panel-offline">
                        <div class="panel-empty">Sin asesores desconectados</div>
                    </div>
                </div>
                <div class="panel-footer">
                    <i class="fas fa-sync-alt"></i> Actualiza cada 15s
                </div>
            </div>
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

const markerById = {};   // asesor_id -> L.circleMarker (solo los que tienen coordenadas)
let emptyMsgEl = null;
let allGerentes = [];     // [{id, nombre, banco_id}]
let allSupervisores = []; // [{id, nombre, jefe_agencia_id}]
let allAsesores = [];     // [{id, nombre, supervisor_id}]
let firstLoad = true;
let selectedAsesorId = null;

const hiddenBanco = document.getElementById('filtro-banco');
const bancoBuscarInput = document.getElementById('filtro-banco-buscar');
const bancoClearBtn = document.getElementById('filtro-banco-clear');
const selGerente = document.getElementById('filtro-gerente');
const selSupervisor = document.getElementById('filtro-supervisor');
const selAsesor = document.getElementById('filtro-asesor');
const statusDot = document.getElementById('status-dot');
const statusText = document.getElementById('status-text');
const legendEl = document.getElementById('map-legend');
const panelOnlineEl = document.getElementById('panel-online');
const panelOfflineEl = document.getElementById('panel-offline');
const panelBadgeEl = document.getElementById('panel-badge');
const countOnlineEl = document.getElementById('count-online');
const countOfflineEl = document.getElementById('count-offline');

function fmtHora(ts) {
    if (!ts) return '--:--';
    const d = new Date(String(ts).replace(' ', 'T'));
    return isNaN(d.getTime()) ? '--:--' : d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
}

function tiempoDesde(ts) {
    if (!ts) return 'Sin datos';
    const diff = Math.floor((Date.now() - new Date(String(ts).replace(' ', 'T')).getTime()) / 60000);
    if (diff < 1) return 'Hace un momento';
    if (diff < 60) return `Hace ${diff} min`;
    const hrs = Math.floor(diff / 60);
    if (hrs < 24) return `Hace ${hrs}h`;
    return `Hace ${Math.floor(hrs / 24)}d`;
}

// Cascada banco -> gerente -> supervisor -> asesor. Cada nivel ahora
// también sabe a qué banco pertenece (banco_id viene en los 3 arreglos),
// así que elegir un banco filtra gerentes, supervisores Y asesores de una
// vez, sin obligar a pasar primero por el gerente.
function poblarGerentes(bancoId) {
    const actual = selGerente.value;
    selGerente.innerHTML = '<option value="">Todos los gerentes</option>';
    allGerentes
        .filter(g => !bancoId || String(g.banco_id) === String(bancoId))
        .forEach(g => {
            const opt = document.createElement('option');
            opt.value = g.id;
            opt.textContent = g.nombre;
            selGerente.appendChild(opt);
        });
    if ([...selGerente.options].some(o => o.value === actual)) {
        selGerente.value = actual;
    } else {
        selGerente.value = '';
    }
}

function poblarSupervisores(bancoId, gerenteId) {
    const actual = selSupervisor.value;
    selSupervisor.innerHTML = '<option value="">Todos los supervisores</option>';
    allSupervisores
        .filter(s => (!bancoId   || String(s.banco_id)         === String(bancoId))
                  && (!gerenteId || String(s.jefe_agencia_id)   === String(gerenteId)))
        .forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.nombre;
            selSupervisor.appendChild(opt);
        });
    // Conserva la selección previa si sigue siendo válida
    if ([...selSupervisor.options].some(o => o.value === actual)) {
        selSupervisor.value = actual;
    } else {
        selSupervisor.value = '';
    }
}

function poblarAsesores(bancoId, gerenteId, supervisorId) {
    const actual = selAsesor.value;
    selAsesor.innerHTML = '<option value="">Todos los asesores</option>';
    allAsesores
        .filter(a => (!bancoId      || String(a.banco_id)      === String(bancoId))
                  && (!gerenteId    || String(a.gerente_id)     === String(gerenteId))
                  && (!supervisorId || String(a.supervisor_id)  === String(supervisorId)))
        .forEach(a => {
            const opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.nombre;
            selAsesor.appendChild(opt);
        });
    if ([...selAsesor.options].some(o => o.value === actual)) {
        selAsesor.value = actual;
    } else {
        selAsesor.value = '';
    }
}

// Recalcula los 3 combos dependientes a partir del banco/gerente/supervisor
// actualmente seleccionados. Se llama cada vez que cambia cualquier filtro.
function refrescarFiltros() {
    const bancoId = hiddenBanco.value;
    poblarGerentes(bancoId);
    poblarSupervisores(bancoId, selGerente.value);
    poblarAsesores(bancoId, selGerente.value, selSupervisor.value);
}

function limpiarMarcadores() {
    Object.values(markerById).forEach(m => map.removeLayer(m));
    Object.keys(markerById).forEach(k => delete markerById[k]);
    if (emptyMsgEl) { emptyMsgEl.remove(); emptyMsgEl = null; }
}

function mostrarVacio(mensaje) {
    if (!emptyMsgEl) {
        emptyMsgEl = document.createElement('div');
        emptyMsgEl.className = 'map-empty';
        document.querySelector('.map-wrap').appendChild(emptyMsgEl);
    }
    emptyMsgEl.textContent = mensaje || 'No hay asesores con este filtro.';
}

function ocultarVacio() {
    if (emptyMsgEl) { emptyMsgEl.remove(); emptyMsgEl = null; }
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
        legendEl.innerHTML = '<span style="color:#94a3b8;">Sin asesores para mostrar leyenda.</span>';
        return;
    }
    equipos.forEach(eq => {
        const item = document.createElement('div');
        item.className = 'item';
        item.innerHTML = `<span class="swatch" style="background:${eq.color}"></span> Equipo de ${eq.nombre}`;
        legendEl.appendChild(item);
    });
}

// ── Centrar el mapa en un asesor desde el panel lateral ─────────
function verAsesor(asesorId, lat, lng, hasLoc) {
    selectedAsesorId = asesorId;
    document.querySelectorAll('.asesor-item.selected').forEach(el => el.classList.remove('selected'));
    const el = document.getElementById(`item-${asesorId}`);
    if (el) el.classList.add('selected');

    if (hasLoc && isFinite(lat) && isFinite(lng)) {
        map.flyTo([lat, lng], 15, { duration: 1 });
        const marker = markerById[asesorId];
        if (marker) setTimeout(() => marker.openPopup(), 500);
    }
}

// ── Panel lateral: asesores en línea / desconectados ─────────────
function renderPanel(ubicaciones) {
    const online  = ubicaciones.filter(u => u.online);
    const offline = ubicaciones.filter(u => !u.online);

    if (countOnlineEl)  countOnlineEl.textContent  = online.length;
    if (countOfflineEl) countOfflineEl.textContent = offline.length;
    if (panelBadgeEl) {
        panelBadgeEl.textContent = online.length + ' en línea';
        panelBadgeEl.style.background = online.length > 0 ? '#10B981' : '#9CA3AF';
    }

    const makeItem = (u) => {
        const hasLoc = u.latitud !== null && u.longitud !== null;
        const latJS  = hasLoc ? u.latitud : 'null';
        const lngJS  = hasLoc ? u.longitud : 'null';
        const meta   = u.online
            ? `<i class="fas fa-location-dot"></i> En línea &nbsp;<i class="fas fa-clock"></i> ${fmtHora(u.ultima_vez)}`
            : `<i class="fas fa-clock"></i> ${tiempoDesde(u.ultima_vez)}`;
        return `<div class="asesor-item ${u.online ? 'item-online' : 'item-offline'}" id="item-${u.asesor_id}"
                     onclick="verAsesor('${u.asesor_id}',${latJS},${lngJS},${hasLoc})"
                     title="${u.banco_nombre || ''} · ${u.supervisor_nombre || ''}">
            <div class="item-name">
                <span class="${u.online ? 'online-dot' : 'offline-dot'}"></span>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${u.asesor_nombre}</span>
            </div>
            <div class="item-meta">${meta}</div>
        </div>`;
    };

    panelOnlineEl.innerHTML  = online.length  > 0 ? online.map(makeItem).join('')  : '<div class="panel-empty">Sin asesores en línea</div>';
    panelOfflineEl.innerHTML = offline.length > 0 ? offline.map(makeItem).join('') : '<div class="panel-empty">Sin asesores desconectados</div>';

    if (selectedAsesorId) {
        const el = document.getElementById(`item-${selectedAsesorId}`);
        if (el) el.classList.add('selected');
    }
}

async function cargarUbicaciones(fitView = false) {
    const params = new URLSearchParams();
    if (hiddenBanco.value) params.set('banco_id', hiddenBanco.value);
    if (selGerente.value) params.set('gerente_id', selGerente.value);
    if (selSupervisor.value) params.set('supervisor_id', selSupervisor.value);
    if (selAsesor.value) params.set('asesor_id', selAsesor.value);

    try {
        const resp = await fetch('api_mapa_vivo_admin.php?' + params.toString(), { credentials: 'same-origin', cache: 'no-store' });
        const data = await resp.json();

        if (data.status !== 'ok') {
            statusDot.style.background = '#ef4444';
            statusText.textContent = 'Error al actualizar: ' + (data.error || 'desconocido');
            return;
        }

        if (firstLoad) {
            allGerentes = data.gerentes || [];
            allSupervisores = data.supervisores || [];
            allAsesores = data.asesores || [];

            // Combobox de búsqueda por texto para Banco/Cooperativa, igual
            // que en registro_admin/supervisor/asesor.php.
            initCooperativaBuscador({
                inputId:  'filtro-banco-buscar',
                hiddenId: 'filtro-banco',
                listId:   'filtro-banco-lista',
                data: (data.bancos || []).map(b => ({ id: String(b.id), nombre: b.nombre })),
                onSelect: function () {
                    bancoClearBtn.classList.add('show');
                    refrescarFiltros();
                    cargarUbicaciones(true);
                }
            });

            refrescarFiltros();
            firstLoad = false;
            fitView = true;
        }

        limpiarMarcadores();
        // Ahora la API devuelve TODOS los asesores que calzan con el filtro
        // (en línea + desconectados), cada uno con su última ubicación
        // conocida — igual que hace el mapa en vivo del supervisor.
        const ubicaciones = data.ubicaciones || [];

        if (ubicaciones.length === 0) {
            mostrarVacio('No hay asesores registrados con este filtro.');
        } else {
            ocultarVacio();
            const bounds = [];
            let sinUbicacion = true;

            ubicaciones.forEach(u => {
                if (u.latitud === null || u.longitud === null) return;
                sinUbicacion = false;

                const lat = parseFloat(u.latitud);
                const lng = parseFloat(u.longitud);
                const marker = L.circleMarker([lat, lng], {
                    radius: u.online ? 9 : 7,
                    color: '#fff',
                    weight: 2,
                    fillColor: u.online ? u.color : '#9CA3AF',
                    fillOpacity: u.online ? 0.95 : 0.6
                }).addTo(map);

                marker.bindPopup(
                    `<strong>${u.asesor_nombre}</strong>` +
                    `${u.online ? ' <span style="color:#10B981;">● En línea</span>' : ' <span style="color:#9CA3AF;">● Desconectado</span>'}<br>` +
                    `Supervisor: ${u.supervisor_nombre || '—'}<br>` +
                    `Gerente: ${u.gerente_nombre || '—'}<br>` +
                    `Banco/Cooperativa: ${u.banco_nombre || '—'}<br>` +
                    `<small>${u.online ? 'Actualizado' : 'Última ubicación conocida'}: ${fmtHora(u.ultima_vez)} (${tiempoDesde(u.ultima_vez)})</small>`
                );

                markerById[u.asesor_id] = marker;
                bounds.push([lat, lng]);
            });

            if (sinUbicacion) {
                mostrarVacio('Los asesores de este filtro aún no registran ninguna ubicación GPS.');
            } else if (fitView && bounds.length > 0) {
                if (bounds.length === 1) map.setView(bounds[0], 14);
                else map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
            }
        }

        actualizarLeyenda(ubicaciones);
        renderPanel(ubicaciones);

        statusDot.style.background = '#22c55e';
        statusText.textContent = `Actualizado ${data.ts} · ${data.total} en línea de ${data.total_asesores} asesor(es)`;
    } catch (err) {
        statusDot.style.background = '#ef4444';
        statusText.textContent = 'Sin conexión con el servidor';
    }
}

// Botón "×" para quitar el filtro de banco (el combobox de búsqueda solo
// permite escoger de la lista, así que hace falta un control explícito
// para volver a "Todos los bancos").
bancoClearBtn.addEventListener('click', () => {
    bancoBuscarInput.value = '';
    hiddenBanco.value = '';
    bancoClearBtn.classList.remove('show');
    refrescarFiltros();
    cargarUbicaciones(true);
});
bancoBuscarInput.addEventListener('input', () => {
    // El buscador ya vacía el hidden en cada tecleo hasta que se elija una
    // opción real de la lista; solo actualizamos la visibilidad del botón.
    bancoClearBtn.classList.toggle('show', !!hiddenBanco.value);
});

selGerente.addEventListener('change', () => {
    poblarSupervisores(hiddenBanco.value, selGerente.value);
    poblarAsesores(hiddenBanco.value, selGerente.value, selSupervisor.value);
    cargarUbicaciones(true);
});
selSupervisor.addEventListener('change', () => {
    poblarAsesores(hiddenBanco.value, selGerente.value, selSupervisor.value);
    cargarUbicaciones(true);
});
selAsesor.addEventListener('change', () => cargarUbicaciones(true));

cargarUbicaciones(true);
setInterval(() => cargarUbicaciones(false), 15000);

map.whenReady(() => {
    setTimeout(() => map.invalidateSize(), 300);
});
window.addEventListener('load', () => setTimeout(() => map.invalidateSize(), 350));
window.addEventListener('resize', () => map.invalidateSize());
</script>
</body>
</html>

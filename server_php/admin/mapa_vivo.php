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
            /* Leaflet usa z-index internos de hasta 700 (panes) y 1000
               (controles); si no superamos eso, el mapa tapa esta lista. */
            z-index: 5000; max-height: 260px; overflow-y: auto;
            background: #fff; border: 1.5px solid #E2E8F0; border-radius: 10px;
            margin-top: 6px; box-shadow: 0 12px 28px rgba(18,58,109,.16);
        }
        .coop-buscador-item { padding: 9px 14px; font-size: 13.5px; color: #0D1929; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .coop-buscador-item:last-child { border-bottom: none; }
        .coop-buscador-item:hover { background: rgba(255,221,0,.16); }
        .coop-buscador-empty { padding: 10px 14px; font-size: 12.5px; color: #94a3b8; font-style: italic; }

        /* ── Selector de fecha + botón Buscar (historial de ruta) ── */
        .field-fecha input[type="date"] {
            padding: 9px 12px; border-radius: 9px; border: 1.5px solid #E2E8F0;
            font-size: 13.5px; font-family: 'Inter', sans-serif; color: #0D1929; background: #fff;
        }
        .btn-buscar-fecha {
            background: rgba(14,165,233,.14); color: var(--brand-navy-deep);
            border: 1px solid rgba(14,165,233,.28); font-weight: 800;
            border-radius: 9px; padding: 9px 14px; align-self: flex-end;
            cursor: pointer; font-size: 13.5px;
        }
        .btn-buscar-fecha:hover { background: rgba(14,165,233,.24); }

        /* ── Caja flotante "Clientes encuestados" sobre el mapa
           (misma lógica que mapa_vivo_superIA.php) ── */
        .leyenda-rutas {
            position: absolute; top: 20px; right: 16px; left: auto; bottom: auto;
            background: #fff; padding: 12px 16px; border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,.12); z-index: 450;
            min-width: 240px; max-width: 320px; max-height: 320px;
            overflow-y: auto; pointer-events: auto;
        }
        .leyenda-rutas h6 { font-size: 13px; font-weight: 700; margin-bottom: 8px; color: #1f2937; }
        .cliente-item {
            padding: 8px 10px; border-radius: 10px;
            border: 1px solid #e5e7eb; background: #f9fafb;
            cursor: pointer; transition: all .15s; margin-bottom: 6px;
        }
        .cliente-item:hover { background: #f3f4f6; border-color: #d1d5db; }
        .cliente-item.disabled { cursor: not-allowed; opacity: .65; }
        .cliente-item.disabled:hover { background: #f9fafb; border-color: #e5e7eb; }
        .cliente-item.activo {
            background: #fefce8 !important; border-color: #f4c400 !important;
            box-shadow: 0 0 0 2px rgba(244,196,0,.4);
        }
        .cliente-name { font-weight: 800; color: #111827; font-size: 12.5px; margin-bottom: 3px; display: flex; align-items: center; }
        .cliente-meta { color: #6b7280; font-size: 11px; }
        .task-marker-pin {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 11px; font-weight: 800;
            border: 2.5px solid #fff; box-shadow: 0 3px 10px rgba(0,0,0,.3);
        }
        .advisor-marker { background: transparent; border: none; }
        .advisor-popup { min-width: 200px; font-family: 'Inter', sans-serif; }
        .advisor-popup .popup-name { font-weight: 700; font-size: 14px; color: #1f2937; margin-bottom: 6px; }
        .advisor-popup .popup-row { font-size: 12px; color: #555; margin-bottom: 3px; }
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
            <div class="field field-fecha">
                <label for="fecha-ruta">Fecha (historial de ruta)</label>
                <input type="date" id="fecha-ruta" value="<?= date('Y-m-d') ?>">
            </div>
            <button type="button" id="buscar-fecha-btn" class="btn-buscar-fecha">
                <i class="fas fa-magnifying-glass"></i> Buscar ruta
            </button>
            <div class="status">
                <span class="dot" id="status-dot"></span>
                <span id="status-text">Cargando…</span>
            </div>
        </div>

        <div class="map-row">
            <div class="map-wrap">
                <div id="map"></div>

                <!-- Clientes encuestados por el asesor seleccionado, en la
                     fecha elegida arriba (historial de ruta GPS). -->
                <div class="leyenda-rutas" id="box-clientes" style="display:none;">
                    <h6><i class="fas fa-clipboard-check me-1" style="color:#10B981;"></i>Clientes encuestados</h6>
                    <div id="clientes-items">
                        <small style="color:#9CA3AF;">Seleccione un asesor</small>
                    </div>
                </div>
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

// ── Historial de ruta GPS por día (mismo patrón que el Mapa en Vivo del
// supervisor: al elegir un asesor + fecha, se dibujan sus segmentos de
// ruta y los clientes que encuestó ese día) ──
const rutaGroup = L.featureGroup().addTo(map);
const rutaLayers = {};          // segmento_id -> { polyline, markers[] }
const segmentoPorTareaId = {};  // tarea_id -> segmento_id
let segmentoAislado = null;
let clienteMarker = null;
let tramoLayer = null;

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
// También dispara la carga del historial de ruta GPS + clientes
// encuestados para la fecha seleccionada en el picker de arriba.
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

    cargarRutaYClientes(asesorId);
}

function getSelectedFecha() {
    const el = document.getElementById('fecha-ruta');
    const v = el ? String(el.value || '').trim() : '';
    return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : null;
}

function formatFechaES(fecha) {
    if (!fecha) return '';
    const d = new Date(fecha + 'T00:00:00');
    return isNaN(d.getTime()) ? '' : d.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' });
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

// ══════════════════════════════════════════════════════════
//  aislarSegmento / restaurarTodaRuta
//  Click en un cliente encuestado → resalta solo su tramo de ruta
// ══════════════════════════════════════════════════════════
function aislarSegmento(segId) {
    segmentoAislado = segId;
    Object.entries(rutaLayers).forEach(([id, layer]) => {
        if (!layer.polyline) return;
        if (id === segId) {
            layer.polyline.setStyle({ opacity: 1, weight: 6 });
            layer.markers.forEach(m => { try { m.setOpacity(1); } catch (e) {} });
        } else {
            layer.polyline.setStyle({ opacity: 0.1, weight: 2 });
            layer.markers.forEach(m => { try { m.setOpacity(0.15); } catch (e) {} });
        }
    });
    const poly = rutaLayers[segId]?.polyline;
    if (poly) {
        try {
            const bounds = poly.getBounds();
            if (bounds.isValid()) map.fitBounds(bounds, { padding: [60, 60], maxZoom: 17 });
        } catch (e) {}
    }
    const btn = document.getElementById('btn-ver-toda-ruta');
    if (btn) btn.style.display = 'block';
}

function restaurarTodaRuta() {
    segmentoAislado = null;
    Object.values(rutaLayers).forEach(layer => {
        if (!layer.polyline) return;
        layer.polyline.setStyle({ opacity: 0.85, weight: 4 });
        layer.markers.forEach(m => { try { m.setOpacity(1); } catch (e) {} });
    });
    const btn = document.getElementById('btn-ver-toda-ruta');
    if (btn) btn.style.display = 'none';
    document.querySelectorAll('.cliente-item.activo').forEach(el => el.classList.remove('activo'));
    if (clienteMarker) { map.removeLayer(clienteMarker); clienteMarker = null; }
    if (tramoLayer) { map.removeLayer(tramoLayer); tramoLayer = null; }

    const allPts = Object.values(rutaLayers).flatMap(l => {
        try { return l.polyline.getLatLngs().flat().map(ll => [ll.lat, ll.lng]); } catch (e) { return []; }
    });
    if (allPts.length > 1) {
        try { map.fitBounds(allPts, { padding: [60, 60], maxZoom: 16 }); } catch (e) {}
    }
}

function dibujarLineaEstimada(aLat, aLng, bLat, bLng) {
    const latlngs = [[aLat, aLng], [bLat, bLng]];
    tramoLayer = L.polyline(latlngs, {
        color: '#2563EB', weight: 4, opacity: 0.75, dashArray: '4 10',
        lineJoin: 'round', lineCap: 'round'
    }).addTo(map);
    try {
        const bounds = L.latLngBounds(latlngs);
        map.fitBounds(bounds, { padding: [60, 60], maxZoom: 17 });
    } catch (e) {}
}

// ── Dibuja la trayectoria GPS entre dos datetimes ─────────
function dibujarTramoGps(asesorId, desde, hasta, lat, lng, nombre, anchorLat = null, anchorLng = null) {
    if (tramoLayer) { map.removeLayer(tramoLayer); tramoLayer = null; }

    const qs = new URLSearchParams({ asesor_id: asesorId, desde, hasta });
    fetch(`api_tramo_gps.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'ok') {
                if (isFinite(anchorLat) && isFinite(anchorLng)) {
                    dibujarLineaEstimada(anchorLat, anchorLng, lat, lng);
                } else {
                    map.flyTo([lat, lng], 16, { duration: 1.2 });
                    setTimeout(() => clienteMarker?.openPopup(), 900);
                }
                return;
            }

            const puntos = data.puntos || [];
            if (puntos.length >= 2) {
                const latlngs = puntos.map(p => [p.lat, p.lng]);
                tramoLayer = L.polyline(latlngs, {
                    color: '#2563EB', weight: 5, opacity: 0.92,
                    lineJoin: 'round', lineCap: 'round'
                }).addTo(map);
                const bounds = L.latLngBounds(latlngs);
                bounds.extend([lat, lng]);
                map.fitBounds(bounds, { padding: [60, 60], maxZoom: 17 });
            } else if (puntos.length === 1) {
                const p0 = puntos[0];
                const latlngs = [[p0.lat, p0.lng], [lat, lng]];
                tramoLayer = L.polyline(latlngs, {
                    color: '#2563EB', weight: 4, opacity: 0.85, dashArray: '6 6',
                    lineJoin: 'round', lineCap: 'round'
                }).addTo(map);
                map.fitBounds(L.latLngBounds(latlngs), { padding: [60, 60], maxZoom: 17 });
            } else if (isFinite(anchorLat) && isFinite(anchorLng)) {
                dibujarLineaEstimada(anchorLat, anchorLng, lat, lng);
            } else {
                map.flyTo([lat, lng], 16, { duration: 1.2 });
            }
            setTimeout(() => clienteMarker?.openPopup(), 600);
        })
        .catch(err => {
            console.warn('[tramo_gps]', err);
            if (isFinite(anchorLat) && isFinite(anchorLng)) {
                dibujarLineaEstimada(anchorLat, anchorLng, lat, lng);
            } else {
                map.flyTo([lat, lng], 16, { duration: 1.2 });
            }
            setTimeout(() => clienteMarker?.openPopup(), 1300);
        });
}

function renderClientes(clientes, fecha, sessionStart, asesorId, sessionStartLat = null, sessionStartLng = null) {
    const box = document.getElementById('box-clientes');
    const list = document.getElementById('clientes-items');
    if (!box || !list) return;

    box.style.display = 'block';
    const h6 = box.querySelector('h6');
    if (h6) {
        const fechaStr = formatFechaES(fecha);
        h6.innerHTML = `<i class="fas fa-clipboard-check me-1" style="color:#10B981;"></i>Clientes encuestados${fechaStr ? ' · ' + fechaStr : ''}`;
    }

    if (!Array.isArray(clientes) || clientes.length === 0) {
        list.innerHTML = '<small style="color:#9CA3AF;">Sin encuestas en esta fecha</small>';
        return;
    }

    const datetimes = [sessionStart || (fecha + ' 00:00:00')];
    clientes.forEach(c => datetimes.push(c.fecha_hora || (c.fecha + ' ' + (c.hora || '23:59:59'))));

    const btnReset = `<button id="btn-ver-toda-ruta"
        onclick="restaurarTodaRuta()"
        style="display:none;width:100%;margin-bottom:8px;padding:7px 10px;
               background:linear-gradient(135deg,#f4c400,#ffdd00);color:#0a2748;
               border:none;border-radius:9px;font-size:12px;font-weight:800;
               cursor:pointer;text-align:center;">
        <i class="fas fa-expand-arrows-alt me-1"></i> Ver toda la ruta
    </button>`;

    list.innerHTML = btnReset + clientes.map((c, idx) => {
        const nombre = escapeHtml(c.cliente_nombre || 'Cliente');
        const hora = escapeHtml(c.hora || '');
        const tipo = escapeHtml((c.tipo_tarea || '').replace('_', ' '));
        const tareaId = escapeHtml(c.tarea_id || '');
        const fechaHora = c.fecha_hora || '';
        const hasLoc = c.latitud !== null && c.longitud !== null
            && isFinite(Number(c.latitud)) && isFinite(Number(c.longitud))
            && !(Math.abs(Number(c.latitud)) < 1e-8 && Math.abs(Number(c.longitud)) < 1e-8);
        const num = idx + 1;

        return `<div class="cliente-item ${hasLoc ? '' : 'disabled'}"
                    data-lat="${hasLoc ? c.latitud : ''}"
                    data-lng="${hasLoc ? c.longitud : ''}"
                    data-tarea="${tareaId}"
                    data-nombre="${nombre}"
                    data-idx="${idx}"
                    data-fecha-hora="${escapeHtml(fechaHora)}">
            <div class="cliente-name">
                <span style="display:inline-flex;align-items:center;justify-content:center;
                      width:18px;height:18px;border-radius:50%;background:#0a2748;
                      color:#ffdd00;font-size:10px;font-weight:800;margin-right:6px;flex-shrink:0;">
                    ${num}
                </span>
                ${nombre}
            </div>
            <div class="cliente-meta">
                <i class="fas fa-clock"></i> ${hora || '--:--'}
                ${tipo ? ' · ' + tipo : ''}
                ${hasLoc ? '' : ' <span style="color:#EF4444;">· Sin GPS</span>'}
            </div>
        </div>`;
    }).join('');

    list.querySelectorAll('.cliente-item:not(.disabled)').forEach(el => {
        el.addEventListener('click', () => {
            const lat = parseFloat(el.getAttribute('data-lat'));
            const lng = parseFloat(el.getAttribute('data-lng'));
            const nombreC = el.getAttribute('data-nombre') || 'Cliente';
            const tareaId = el.getAttribute('data-tarea') || '';
            const idx = parseInt(el.getAttribute('data-idx'), 10);
            if (!isFinite(lat) || !isFinite(lng)) return;

            list.querySelectorAll('.cliente-item').forEach(e => e.classList.remove('activo'));
            el.classList.add('activo');

            const btnR = document.getElementById('btn-ver-toda-ruta');
            if (btnR) btnR.style.display = 'block';

            if (clienteMarker) { map.removeLayer(clienteMarker); clienteMarker = null; }
            if (tramoLayer) { map.removeLayer(tramoLayer); tramoLayer = null; }

            const icon = L.divIcon({
                html: `<div style="width:38px;height:38px;border-radius:50%;
                             display:flex;align-items:center;justify-content:center;
                             background:linear-gradient(135deg,#f4c400,#ffdd00);
                             border:3px solid #fff;color:#0a2748;font-size:16px;
                             box-shadow:0 4px 14px rgba(244,196,0,.5);">
                           <i class="fas fa-flag-checkered"></i>
                       </div>`,
                iconSize: [38, 38], iconAnchor: [19, 19], popupAnchor: [0, -20],
                className: 'advisor-marker'
            });
            clienteMarker = L.marker([lat, lng], { icon }).addTo(map)
                .bindPopup(`<div class="advisor-popup">
                    <div class="popup-name"><i class="fas fa-user me-1"></i>${escapeHtml(nombreC)}</div>
                    <div class="popup-row"><i class="fas fa-location-dot me-1" style="color:#10B981;"></i>
                        ${lat.toFixed(6)}, ${lng.toFixed(6)}</div>
                </div>`);

            const curAsesorId = asesorId || selectedAsesorId;
            const desde = datetimes[idx];
            const hasta = datetimes[idx + 1];

            if (curAsesorId && desde && hasta) {
                const segId = tareaId ? segmentoPorTareaId[String(tareaId)] : null;
                if (segId && rutaLayers[segId] && rutaLayers[segId].polyline) {
                    aislarSegmento(segId);
                    setTimeout(() => clienteMarker?.openPopup(), 400);
                } else {
                    Object.values(rutaLayers).forEach(layer => {
                        if (layer.polyline) layer.polyline.setStyle({ opacity: 0.12, weight: 2 });
                    });
                    const anchorLat = (idx === 0) ? parseFloat(sessionStartLat) : null;
                    const anchorLng = (idx === 0) ? parseFloat(sessionStartLng) : null;
                    dibujarTramoGps(curAsesorId, desde, hasta, lat, lng, nombreC, anchorLat, anchorLng);
                }
            } else {
                map.flyTo([lat, lng], 16, { duration: 1.1 });
                setTimeout(() => clienteMarker?.openPopup(), 1200);
            }
        });
    });
}

function cargarClientes(asesorId, fecha) {
    const box = document.getElementById('box-clientes');
    const list = document.getElementById('clientes-items');
    if (box) box.style.display = 'block';
    if (list) list.innerHTML = '<small style="color:#9CA3AF;">Cargando clientes…</small>';

    const qs = new URLSearchParams({ asesor_id: asesorId });
    if (fecha) qs.set('fecha', fecha);

    fetch(`api_clientes_encuestados.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                renderClientes(
                    data.clientes || [], data.fecha || fecha, data.session_start || null,
                    asesorId, data.session_start_lat ?? null, data.session_start_lng ?? null
                );
            } else {
                throw new Error(data.message || 'Error');
            }
        })
        .catch(err => {
            console.warn('[clientes_encuestados]', err);
            if (list) list.innerHTML = '<small style="color:#EF4444;">No se pudo cargar clientes</small>';
        });
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r},${g},${b},${alpha})`;
}

// ══════════════════════════════════════════════════════════
//  renderRutas — dibuja los segmentos de ruta GPS del día en el mapa
// ══════════════════════════════════════════════════════════
function renderRutas(segmentos) {
    segmentoAislado = null;
    const btnReset = document.getElementById('btn-ver-toda-ruta');
    if (btnReset) btnReset.style.display = 'none';
    if (clienteMarker) { map.removeLayer(clienteMarker); clienteMarker = null; }
    if (tramoLayer) { map.removeLayer(tramoLayer); tramoLayer = null; }

    rutaGroup.clearLayers();
    Object.keys(rutaLayers).forEach(k => delete rutaLayers[k]);
    Object.keys(segmentoPorTareaId).forEach(k => delete segmentoPorTareaId[k]);

    if (!segmentos || segmentos.length === 0) return;

    const isValidCoord = (lat, lng) => {
        if (lat === null || lng === null) return false;
        if (!isFinite(Number(lat)) || !isFinite(Number(lng))) return false;
        return !(Math.abs(Number(lat)) < 1e-8 && Math.abs(Number(lng)) < 1e-8);
    };

    segmentos.forEach(seg => {
        const color = seg.color || '#3B82F6';
        const puntos = seg.puntos || [];
        const nombre = seg.asesor_nombre || 'Asesor';
        const num = seg.numero;

        if (seg.tarea_id) segmentoPorTareaId[String(seg.tarea_id)] = seg.segmento_id;

        let latlngs = [];
        let isFallbackLine = false;
        if (puntos.length >= 2) {
            latlngs = puntos.map(p => [p.lat, p.lng]).filter(([lat, lng]) => isValidCoord(lat, lng));
        }
        if (latlngs.length < 2) {
            const aOk = isValidCoord(seg.inicio_lat, seg.inicio_lng);
            const bOk = isValidCoord(seg.fin_lat, seg.fin_lng);
            if (aOk && bOk) {
                const a = [Number(seg.inicio_lat), Number(seg.inicio_lng)];
                const b = [Number(seg.fin_lat), Number(seg.fin_lng)];
                if (!(a[0] === b[0] && a[1] === b[1])) {
                    latlngs = [a, b];
                    isFallbackLine = true;
                }
            }
        }

        if (latlngs.length >= 2) {
            const poly = L.polyline(latlngs, {
                color, weight: isFallbackLine ? 3 : 4, opacity: isFallbackLine ? 0.55 : 0.85,
                dashArray: isFallbackLine ? '2 8' : (seg.estado === 'activo' ? '8 5' : null),
                lineJoin: 'round', lineCap: 'round'
            }).addTo(rutaGroup);

            const horaInicio = seg.inicio_at ? seg.inicio_at.substring(11, 16) : '--:--';
            const horaFin = seg.fin_at ? seg.fin_at.substring(11, 16) : 'activo';
            const ptsTxt = isFallbackLine ? 'sin GPS (línea estimada)' : `${puntos.length} pts GPS`;

            poly.bindPopup(`
                <div style="font-family:'Inter',sans-serif;min-width:180px;">
                    <div style="font-weight:700;font-size:13px;color:#1f2937;margin-bottom:5px;">
                        <span style="display:inline-block;width:12px;height:12px;
                              border-radius:50%;background:${color};margin-right:5px;"></span>
                        ${escapeHtml(nombre)} — Seg. ${num}
                    </div>
                    <div style="font-size:12px;color:#555;margin-bottom:2px;">
                        <i class="fas fa-clock"></i> ${horaInicio} → ${horaFin}
                    </div>
                    <div style="font-size:12px;color:#555;">
                        <i class="fas fa-map-pin"></i> ${ptsTxt}
                    </div>
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;">
                        Estado: <b style="color:${seg.estado === 'activo' ? '#10B981' : '#6B7280'}">${seg.estado}</b>
                    </div>
                </div>`);
            rutaLayers[seg.segmento_id] = { polyline: poly, markers: [] };
        }

        if (seg.inicio_lat !== null && seg.inicio_lng !== null && num === 1) {
            const startIcon = L.divIcon({
                html: `<div style="width:14px;height:14px;border-radius:50%;
                             background:${color};border:3px solid #fff;
                             box-shadow:0 2px 8px rgba(0,0,0,.3);"></div>`,
                iconSize: [14, 14], iconAnchor: [7, 7], className: ''
            });
            const sm = L.marker([seg.inicio_lat, seg.inicio_lng], { icon: startIcon }).addTo(rutaGroup)
                .bindPopup(`<div style="font-family:'Inter',sans-serif;font-size:12px;">
                    <b>${escapeHtml(nombre)}</b><br>
                    <i class="fas fa-sign-in-alt"></i> Inicio sesión<br>
                    <small>${seg.inicio_at ? seg.inicio_at.substring(11, 16) : ''}</small>
                </div>`);
            if (rutaLayers[seg.segmento_id]) rutaLayers[seg.segmento_id].markers.push(sm);
        }

        if (seg.fin_lat !== null && seg.fin_lng !== null && seg.estado !== 'activo') {
            const tareaLabel = seg.tarea_tipo
                ? seg.tarea_tipo.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
                : 'Tarea';
            const taskIcon = L.divIcon({
                html: `<div class="task-marker-pin" style="background:${color};" title="${tareaLabel}">
                           ${num}
                       </div>`,
                iconSize: [28, 28], iconAnchor: [14, 14], popupAnchor: [0, -16], className: ''
            });
            const tm = L.marker([seg.fin_lat, seg.fin_lng], { icon: taskIcon }).addTo(rutaGroup)
                .bindPopup(`<div style="font-family:'Inter',sans-serif;font-size:12px;min-width:160px;">
                    <div style="font-weight:700;color:#1f2937;margin-bottom:4px;">
                        <span style="display:inline-block;width:10px;height:10px;
                              border-radius:50%;background:${color};margin-right:4px;"></span>
                        Seg. ${num} completado
                    </div>
                    ${seg.tarea_tipo ? `<div><i class="fas fa-tasks"></i> ${tareaLabel}</div>` : ''}
                    ${seg.cliente_nombre ? `<div><i class="fas fa-user"></i> ${escapeHtml(seg.cliente_nombre)}</div>` : ''}
                    <div style="color:#6B7280;font-size:11px;margin-top:3px;">
                        ${seg.fin_at ? seg.fin_at.substring(11, 16) : ''}
                    </div>
                </div>`);
            if (rutaLayers[seg.segmento_id]) rutaLayers[seg.segmento_id].markers.push(tm);
        }
    });
}

// ════════════════════════════════════════════════════════════
//  cargarRutaYClientes — siempre muestra TODOS los segmentos del día
//  seleccionado (o del día más reciente si no hay fecha en el picker).
// ════════════════════════════════════════════════════════════
function cargarRutaYClientes(asesorId) {
    const fechaSel = getSelectedFecha();

    rutaGroup.clearLayers();
    Object.keys(rutaLayers).forEach(k => delete rutaLayers[k]);
    Object.keys(segmentoPorTareaId).forEach(k => delete segmentoPorTareaId[k]);
    if (tramoLayer) { map.removeLayer(tramoLayer); tramoLayer = null; }

    const box = document.getElementById('box-clientes');
    const list = document.getElementById('clientes-items');
    if (box) box.style.display = 'block';
    if (list) list.innerHTML = '<small style="color:#9CA3AF;">Cargando clientes…</small>';

    const qs = new URLSearchParams({ asesor_id: asesorId, solo_ultimo: '0' });
    if (fechaSel) qs.set('fecha', fechaSel);

    fetch(`api_ultima_ruta.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok' && data.segmentos?.length > 0) {
                renderRutas(data.segmentos);
                const puntos = data.segmentos.flatMap(s => {
                    const pts = (s.puntos || [])
                        .map(p => [p.lat, p.lng])
                        .filter(([lat, lng]) => isFinite(Number(lat)) && isFinite(Number(lng))
                            && !(Math.abs(Number(lat)) < 1e-8 && Math.abs(Number(lng)) < 1e-8));
                    if (pts.length > 0) return pts;
                    const out = [];
                    const aOk = s.inicio_lat !== null && s.inicio_lng !== null && isFinite(Number(s.inicio_lat)) && isFinite(Number(s.inicio_lng))
                        && !(Math.abs(Number(s.inicio_lat)) < 1e-8 && Math.abs(Number(s.inicio_lng)) < 1e-8);
                    const bOk = s.fin_lat !== null && s.fin_lng !== null && isFinite(Number(s.fin_lat)) && isFinite(Number(s.fin_lng))
                        && !(Math.abs(Number(s.fin_lat)) < 1e-8 && Math.abs(Number(s.fin_lng)) < 1e-8);
                    if (aOk) out.push([Number(s.inicio_lat), Number(s.inicio_lng)]);
                    if (bOk) out.push([Number(s.fin_lat), Number(s.fin_lng)]);
                    return out;
                });
                if (puntos.length > 1) {
                    try { map.fitBounds(puntos, { padding: [60, 60], maxZoom: 16 }); } catch (e) {}
                } else if (puntos.length === 1) {
                    map.setView(puntos[0], 15);
                }
            } else {
                rutaGroup.clearLayers();
                Object.keys(rutaLayers).forEach(k => delete rutaLayers[k]);
                if (list) list.innerHTML = '<small style="color:#9CA3AF;">Sin rutas ni encuestas en esta fecha</small>';
            }

            const fechaParaClientes = data.fecha || fechaSel || null;
            if (fechaParaClientes) {
                cargarClientes(asesorId, fechaParaClientes);
            } else if (list) {
                list.innerHTML = '<small style="color:#9CA3AF;">Sin encuestas registradas</small>';
            }
        })
        .catch(err => {
            console.warn('[cargarRutaYClientes]', err);
            if (list) list.innerHTML = '<small style="color:#EF4444;">Error al cargar datos</small>';
        });
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

// ── Botón "Buscar ruta" / cambio de fecha: recarga el historial del
// asesor actualmente seleccionado para la nueva fecha. ──
document.getElementById('buscar-fecha-btn')?.addEventListener('click', () => {
    if (selectedAsesorId) {
        cargarRutaYClientes(selectedAsesorId);
    } else {
        const list = document.getElementById('clientes-items');
        const box = document.getElementById('box-clientes');
        if (box) box.style.display = 'block';
        if (list) list.innerHTML = '<small style="color:#9CA3AF;">Seleccione un asesor en el panel para ver su ruta de este día</small>';
    }
});
document.getElementById('fecha-ruta')?.addEventListener('change', () => {
    document.getElementById('buscar-fecha-btn')?.click();
});

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

<?php
// ============================================================
// admin/mapa_calor.php — Mapa de calor de demanda de viajes
// Muestra zonas de mayor solicitud usando origen_lat/origen_lng
// de la tabla viajes, con filtros por fecha y categoría.
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login_selector.php');
    exit;
}

// ── Filtros ────────────────────────────────────────────────
$rango    = $_GET['rango']      ?? '30';   // días hacia atrás
$catId    = (int)($_GET['cat'] ?? 0);      // 0 = todas
$tipo     = $_GET['tipo']       ?? 'origen'; // origen | destino

$fechaDesde = date('Y-m-d', strtotime("-{$rango} days"));

// ── Categorías para el filtro ──────────────────────────────
$categorias = $pdo->query("SELECT id, nombre FROM categorias ORDER BY id")->fetchAll();

// ── Datos para el mapa de calor ───────────────────────────
$latCol = $tipo === 'destino' ? 'destino_lat' : 'origen_lat';
$lngCol = $tipo === 'destino' ? 'destino_lng' : 'origen_lng';

$sql = "SELECT {$latCol} AS lat, {$lngCol} AS lng, COUNT(*) AS peso
        FROM viajes
        WHERE {$latCol} IS NOT NULL AND {$lngCol} IS NOT NULL
          AND fecha_pedido >= ?
          AND estado != 'cancelado'";
$params = [$fechaDesde];

if ($catId > 0) {
    $sql .= " AND categoria_id = ?";
    $params[] = $catId;
}

$sql .= " GROUP BY ROUND({$latCol}, 4), ROUND({$lngCol}, 4)
          ORDER BY peso DESC
          LIMIT 2000";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$puntos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Estadísticas ──────────────────────────────────────────
$totalViajes = array_sum(array_column($puntos, 'peso'));
$totalZonas  = count($puntos);
$maxPeso     = $puntos[0]['peso'] ?? 1;

// Zona más demandada (texto)
$zonaMas = null;
if (!empty($puntos)) {
    $sqlZona = "SELECT origen_texto FROM viajes
                WHERE ROUND({$latCol},4) = ROUND(?,4)
                  AND ROUND({$lngCol},4) = ROUND(?,4)
                  AND origen_texto IS NOT NULL
                LIMIT 1";
    $stmtZ = $pdo->prepare($sqlZona);
    $stmtZ->execute([$puntos[0]['lat'], $puntos[0]['lng']]);
    $zonaMas = $stmtZ->fetchColumn();
}

// Conteo para badge sidebar
$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0")->fetchColumn();
$currentPage = 'mapa_calor';

// Centrar mapa en la zona con más puntos
$centerLat = !empty($puntos) ? floatval($puntos[0]['lat']) : -2.9001285;
$centerLng = !empty($puntos) ? floatval($puntos[0]['lng']) : -79.0058965;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Mapa de Calor</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="admin.css">

    <style>
        body { background:#f0f2f5; }

        .page-wrapper { display:flex; flex-direction:column; height:100vh; padding:20px; gap:14px; overflow:hidden; }

        /* ── Header ── */
        .heatmap-header { display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .heatmap-header h4 { font-weight:700; margin:0; color:#24243e; }

        /* ── Tarjetas de stats ── */
        .stats-row { display:flex; gap:12px; flex-shrink:0; flex-wrap:wrap; }
        .stat-card {
            background:#fff;
            border-radius:12px;
            padding:14px 20px;
            display:flex;
            align-items:center;
            gap:14px;
            box-shadow:0 2px 12px rgba(0,0,0,.07);
            flex:1;
            min-width:180px;
        }
        .stat-icon {
            width:44px; height:44px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size:18px; flex-shrink:0;
        }
        .stat-card .label { font-size:11px; color:#6c757d; text-transform:uppercase; letter-spacing:.5px; }
        .stat-card .value { font-size:22px; font-weight:800; color:#24243e; line-height:1.1; }
        .stat-card .sub   { font-size:11px; color:#adb5bd; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;}

        /* ── Filtros ── */
        .filter-bar {
            background:#fff;
            border-radius:12px;
            padding:14px 20px;
            display:flex;
            gap:12px;
            align-items:center;
            flex-wrap:wrap;
            box-shadow:0 2px 12px rgba(0,0,0,.07);
            flex-shrink:0;
        }
        .filter-bar label { font-size:12px; font-weight:600; color:#6c757d; margin-bottom:3px; }
        .filter-bar select, .filter-bar .btn-apply {
            font-size:13px; border-radius:8px; border:1px solid #dee2e6;
            padding:6px 12px; background:#fff; color:#24243e;
        }
        .btn-apply {
            background: linear-gradient(135deg,#6b11ff,#2575fc) !important;
            color:#fff !important; border:none !important; font-weight:600;
            cursor:pointer;
        }
        .btn-apply:hover { opacity:.9; }

        /* ── Mapa ── */
        .map-container {
            flex:1;
            border-radius:16px;
            overflow:hidden;
            box-shadow:0 4px 24px rgba(0,0,0,.12);
            min-height:0;
        }
        #heatmap { width:100%; height:100%; }

        /* ── Leyenda ── */
        .legend-box {
            background:rgba(255,255,255,.95);
            border-radius:10px;
            padding:10px 14px;
            box-shadow:0 2px 12px rgba(0,0,0,.15);
        }
        .legend-title { font-size:11px; font-weight:700; color:#24243e; margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }
        .legend-gradient {
            height:10px;
            border-radius:5px;
            background: linear-gradient(to right, #00f, #0ff, #0f0, #ff0, #f00);
            margin-bottom:4px;
        }
        .legend-labels { display:flex; justify-content:space-between; font-size:10px; color:#6c757d; }

        /* ── Tooltip del mapa ── */
        .leaflet-tooltip-zone {
            background:rgba(36,36,62,.9);
            color:#fff; border:none;
            border-radius:8px; padding:6px 10px;
            font-size:12px; font-weight:600;
        }

        @media (max-width:768px) {
            .stats-row { gap:8px; }
            .stat-card { min-width:140px; padding:10px 14px; }
        }
    </style>
</head>
<body>
<div class="d-flex" style="min-height:100vh;">

    <?php include '_sidebar.php'; ?>

    <main class="flex-grow-1" style="overflow:hidden;">
        <div class="page-wrapper">

            <!-- Header -->
            <div class="heatmap-header">
                <div>
                    <h4><i class="fas fa-fire me-2" style="color:#f46b45;"></i>Mapa de Calor de Demanda</h4>
                    <small class="text-muted">Zonas con mayor solicitud de viajes · últimos <?= $rango ?> días</small>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-light text-dark border" style="font-size:12px;">
                        <i class="fas fa-clock me-1" style="color:#6b11ff;"></i>
                        Actualizado: <?= date('H:i') ?>
                    </span>
                    <a href="mapa_calor.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-sync-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(107,17,255,.1);">
                        <i class="fas fa-fire" style="color:#6b11ff;"></i>
                    </div>
                    <div>
                        <div class="label">Solicitudes</div>
                        <div class="value"><?= number_format($totalViajes) ?></div>
                        <div class="sub">en los últimos <?= $rango ?> días</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(244,107,69,.1);">
                        <i class="fas fa-map-pin" style="color:#f46b45;"></i>
                    </div>
                    <div>
                        <div class="label">Zonas activas</div>
                        <div class="value"><?= number_format($totalZonas) ?></div>
                        <div class="sub">puntos de demanda</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:rgba(17,153,142,.1);">
                        <i class="fas fa-trophy" style="color:#11998e;"></i>
                    </div>
                    <div>
                        <div class="label">Pico máximo</div>
                        <div class="value"><?= $maxPeso ?></div>
                        <div class="sub">solicitudes en 1 zona</div>
                    </div>
                </div>
                <?php if ($zonaMas): ?>
                <div class="stat-card" style="flex:2;">
                    <div class="stat-icon" style="background:rgba(255,193,7,.1);">
                        <i class="fas fa-star" style="color:#ffc107;"></i>
                    </div>
                    <div style="min-width:0;">
                        <div class="label">Zona más demandada</div>
                        <div class="value" style="font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;">
                            <?= htmlspecialchars($zonaMas) ?>
                        </div>
                        <div class="sub"><?= $maxPeso ?> solicitudes</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Filtros -->
            <form method="GET" action="mapa_calor.php">
                <div class="filter-bar">
                    <div>
                        <label>Período</label>
                        <select name="rango" onchange="this.form.submit()">
                            <option value="7"  <?= $rango=='7'  ?'selected':'' ?>>Última semana</option>
                            <option value="15" <?= $rango=='15' ?'selected':'' ?>>Últimos 15 días</option>
                            <option value="30" <?= $rango=='30' ?'selected':'' ?>>Último mes</option>
                            <option value="90" <?= $rango=='90' ?'selected':'' ?>>Últimos 3 meses</option>
                            <option value="365"<?= $rango=='365'?'selected':'' ?>>Último año</option>
                        </select>
                    </div>
                    <div>
                        <label>Categoría</label>
                        <select name="cat" onchange="this.form.submit()">
                            <option value="0">Todas</option>
                            <?php foreach ($categorias as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $catId==$c['id']?'selected':'' ?>>
                                <?= htmlspecialchars($c['nombre']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Mostrar puntos de</label>
                        <select name="tipo" onchange="this.form.submit()">
                            <option value="origen"  <?= $tipo=='origen'  ?'selected':'' ?>>Recogida (origen)</option>
                            <option value="destino" <?= $tipo=='destino' ?'selected':'' ?>>Destino</option>
                        </select>
                    </div>
                    <div class="ms-auto d-flex gap-2 align-items-end">
                        <a href="mapa_calor.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times me-1"></i>Limpiar
                        </a>
                        <button type="submit" class="btn-apply btn btn-sm">
                            <i class="fas fa-search me-1"></i>Aplicar
                        </button>
                    </div>
                </div>
            </form>

            <!-- Mapa -->
            <div class="map-container">
                <div id="heatmap"></div>
            </div>

        </div>
    </main>
</div>

<!-- Leaflet JS -->
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<!-- Leaflet.heat plugin -->
<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ── Datos del servidor → JavaScript ──────────────────────
const puntosRaw = <?= json_encode(array_map(function($p) {
    return [floatval($p['lat']), floatval($p['lng']), intval($p['peso'])];
}, $puntos)) ?>;

const maxPeso = <?= max(1, $maxPeso) ?>;
const centerLat = <?= $centerLat ?>;
const centerLng = <?= $centerLng ?>;

// ── Inicializar mapa ──────────────────────────────────────
const map = L.map('heatmap', {
    center: [centerLat, centerLng],
    zoom: 13,
    zoomControl: true,
});

// Tile layer OpenStreetMap oscuro (estilo más visual para calor)
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap contributors © CARTO',
    subdomains: 'abcd',
    maxZoom: 19
}).addTo(map);

// ── Capa de calor ─────────────────────────────────────────
const heatLayer = L.heatLayer(puntosRaw, {
    radius:    28,
    blur:      22,
    maxZoom:   17,
    max:       maxPeso,
    gradient: {
        0.0: '#0000ff',   // azul — baja demanda
        0.3: '#00ccff',
        0.5: '#00ff88',   // verde
        0.7: '#ffff00',   // amarillo
        0.85:'#ff8800',   // naranja
        1.0: '#ff0000'    // rojo — alta demanda
    }
}).addTo(map);

// ── Leyenda ───────────────────────────────────────────────
const legend = L.control({ position: 'bottomright' });
legend.onAdd = function() {
    const div = L.DomUtil.create('div', 'legend-box');
    div.innerHTML = `
        <div class="legend-title">🔥 Intensidad de demanda</div>
        <div class="legend-gradient"></div>
        <div class="legend-labels">
            <span>Baja</span>
            <span>Media</span>
            <span>Alta</span>
        </div>
        <div style="font-size:10px;color:#6c757d;margin-top:6px;text-align:center;">
            ${puntosRaw.length} zonas · ${puntosRaw.reduce((a,p)=>a+p[2],0)} solicitudes
        </div>
    `;
    return div;
};
legend.addTo(map);

// ── Marcadores de top 5 zonas (círculos) ─────────────────
const top5 = [...puntosRaw].sort((a,b) => b[2]-a[2]).slice(0, 5);
top5.forEach((p, i) => {
    const radios = [18, 14, 12, 10, 9];
    const circle = L.circleMarker([p[0], p[1]], {
        radius:      radios[i] || 9,
        color:       '#fff',
        weight:      2,
        fillColor:   i === 0 ? '#ff0000' : i <= 1 ? '#ff8800' : '#ffff00',
        fillOpacity: 0.85,
    });

    circle.bindTooltip(
        `<b>#${i+1} Top zona</b><br>${p[2]} solicitudes`,
        { className: 'leaflet-tooltip-zone', permanent: false }
    );
    circle.addTo(map);
});

// ── Botón para centrar en zona más caliente ───────────────
const btnCenter = L.control({ position: 'topright' });
btnCenter.onAdd = function() {
    const btn = L.DomUtil.create('button');
    btn.innerHTML = '<i class="fas fa-crosshairs"></i> Zona más activa';
    btn.style.cssText = `
        background: linear-gradient(135deg,#6b11ff,#2575fc);
        color:#fff; border:none; border-radius:8px;
        padding:8px 14px; font-size:12px; font-weight:700;
        cursor:pointer; box-shadow:0 2px 10px rgba(107,17,255,.4);
    `;
    btn.onclick = () => {
        if (puntosRaw.length > 0) {
            map.setView([puntosRaw[0][0], puntosRaw[0][1]], 15);
        }
    };
    return btn;
};
btnCenter.addTo(map);
</script>
</body>
</html>

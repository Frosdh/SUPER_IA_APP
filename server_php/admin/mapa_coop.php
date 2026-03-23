<?php
// ============================================================
// admin/mapa_coop.php — Mapa filtrado por cooperativa
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['secretary_logged_in']) || $_SESSION['secretary_logged_in'] !== true) {
    header('Location: login_selector.php');
    exit;
}

$coopId = $_SESSION['cooperativa_id'];
$stmt = $pdo->prepare("SELECT nombre FROM cooperativas WHERE id = ?");
$stmt->execute([$coopId]);
$coopName = $stmt->fetchColumn() ?? 'Mi Cooperativa';

$currentPage = 'mapa';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove — Mapa <?= htmlspecialchars($coopName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="admin.css">
    <style>
        .map-wrapper { display:flex; flex-direction:column; height:100vh; padding:20px; gap:14px; background: var(--bg); color: var(--text); }
        .map-header { display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .stats-row { display:flex; gap:12px; flex-shrink:0; }
        .stat-pill { background:var(--card); border-radius:10px; padding:10px 18px; display:flex; align-items:center; gap:10px; box-shadow:var(--shadow-sm); font-size:13px; color: var(--text); }
        .dot { width:10px; height:10px; border-radius:50%; }
        .dot-libre { background:var(--accent); }
        .dot-ocupado { background:var(--warning); }
        #mapa { width:100%; height:calc(100vh - 220px); border-radius:14px; box-shadow:var(--shadow); }
        .status-bar { background:var(--card); border-radius:10px; padding:8px 16px; font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:16px; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php 
        // Sidebar personalizado para secretaria (puedes incluir el mismo si maneja el rol)
        include '_sidebar.php'; 
        ?>
        <div class="col-md-10">
            <div class="map-wrapper">
                <div class="map-header">
                    <div>
                        <h4 class="fw-bold mb-0">Mapa de Flota: <?= htmlspecialchars($coopName) ?></h4>
                        <small class="text-muted">Ubicación en vivo de tus conductores</small>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" onclick="cargarConductores()">
                        <i class="fas fa-sync-alt me-1"></i>Refrescar
                    </button>
                </div>

                <div class="stats-row">
                    <div class="stat-pill">
                        <div class="dot dot-libre"></div>
                        <div><strong id="cntLibre">0</strong><br><span>Libres</span></div>
                    </div>
                    <div class="stat-pill">
                        <div class="dot dot-ocupado"></div>
                        <div><strong id="cntOcupado">0</strong><br><span>Ocupados</span></div>
                    </div>
                </div>

                <div id="mapa"></div>

                <div class="status-bar">
                    <span><i class="fas fa-satellite-dish me-2"></i> Actualizando cada 10 seg.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const mapa = L.map('mapa').setView([-2.9001285, -79.0058965], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(mapa);
    let marcadores = {};

    function cargarConductores() {
        fetch('api_coop_mapa.php')
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'ok') return;
                
                // Limpiar viejos
                const ids = data.conductores.map(c => String(c.id));
                Object.keys(marcadores).forEach(id => {
                    if (!ids.includes(id)) { mapa.removeLayer(marcadores[id]); delete marcadores[id]; }
                });

                data.conductores.forEach(c => {
                    const lat = parseFloat(c.latitud), lng = parseFloat(c.longitud);
                    if (marcadores[c.id]) {
                        marcadores[c.id].setLatLng([lat, lng]);
                    } else {
                        marcadores[c.id] = L.marker([lat, lng]).addTo(mapa)
                            .bindPopup(`<b>${c.nombre}</b><br>${c.estado}`);
                    }
                });

                document.getElementById('cntLibre').innerText = data.totales.libre;
                document.getElementById('cntOcupado').innerText = data.totales.ocupado;
            });
    }

    cargarConductores();
    setInterval(cargarConductores, 10000);
</script>
</body>
</html>

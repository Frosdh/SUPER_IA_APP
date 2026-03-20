<?php
// ============================================================
// admin/historial_rutas.php
// Visualización de rutas históricas de conductores
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Obtener lista de conductores para el selector
$conductores = $pdo->query("SELECT id, nombre, telefono FROM conductores ORDER BY nombre ASC")->fetchAll();

$currentPage = 'historial_rutas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Historial de Rutas</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="admin.css">

    <style>
        .page-wrapper { display:flex; flex-direction:column; height:100vh; padding:20px; gap:14px; }
        #mapa_historial { width:100%; height:calc(100vh - 250px); min-height:400px; border-radius:14px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,.10); }
        .controls-card { background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,.06); }
        .point-count-pill { background:#6b11ff; color:#fff; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <div class="col-md-10">
        <div class="page-wrapper">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="fas fa-route me-2" style="color:#6b11ff"></i>Historial de Rutas</h4>
                    <small>Consulta el trayecto seguido por los conductores en una fecha específica</small>
                </div>
                <div id="stats_container" style="display:none">
                    <span class="point-count-pill" id="point_count">0 puntos encontrados</span>
                </div>
            </div>

            <!-- Controles -->
            <div class="controls-card">
                <form id="filterForm" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Conductor</label>
                        <select id="conductor_id" class="form-select" required>
                            <option value="">Selecciona un conductor...</option>
                            <?php foreach ($conductores as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?> (<?= $c['telefono'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Fecha</label>
                        <input type="date" id="fecha" class="form-select" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Ver Recorrido
                        </button>
                    </div>
                    <div class="col-md-2" id="gpx_container" style="display:none">
                        <button type="button" class="btn btn-success w-100" onclick="exportarGPX()">
                            <i class="fas fa-file-download me-2"></i>GPX
                        </button>
                    </div>
                </form>
            </div>

            <!-- Mapa -->
            <div id="mapa_historial"></div>

            <div id="no_data_alert" class="alert alert-info" style="display:none">
                <i class="fas fa-info-circle me-2"></i>No se encontraron registros de ubicación para este conductor en la fecha seleccionada.
            </div>
        </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const mapa = L.map('mapa_historial').setView([-2.9001285, -79.0058965], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(mapa);

    let polyline = null;
    let markers = [];
    let currentData = [];

    document.getElementById('filterForm').onsubmit = function(e) {
        e.preventDefault();
        const cid = document.getElementById('conductor_id').value;
        const fecha = document.getElementById('fecha').value;
        
        cargarRuta(cid, fecha);
    };

    function cargarRuta(cid, fecha) {
        // Limpiar mapa previo
        if (polyline) mapa.removeLayer(polyline);
        markers.forEach(m => mapa.removeLayer(m));
        markers = [];
        document.getElementById('no_data_alert').style.display = 'none';
        document.getElementById('gpx_container').style.display = 'none';
        document.getElementById('stats_container').style.display = 'none';

        fetch(`api_ruta_historica.php?conductor_id=${cid}&fecha=${fecha}`)
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'ok' || data.puntos.length === 0) {
                    document.getElementById('no_data_alert').style.display = 'block';
                    return;
                }

                currentData = data.puntos;
                const latlngs = data.puntos.map(p => [parseFloat(p.latitud), parseFloat(p.longitud)]);
                
                // Dibujar línea
                polyline = L.polyline(latlngs, {
                    color: '#6b11ff',
                    weight: 5,
                    opacity: 0.7,
                    smoothFactor: 1
                }).addTo(mapa);

                // Marcador de inicio (Verde)
                const startMarker = L.circleMarker(latlngs[0], {
                    radius: 8, color: '#11998e', fillColor: '#11998e', fillOpacity: 1
                }).bindPopup('Inicio del día: ' + data.puntos[0].fecha_registro).addTo(mapa);
                markers.push(startMarker);

                // Marcador de fin (Rojo)
                const endMarker = L.circleMarker(latlngs[latlngs.length - 1], {
                    radius: 8, color: '#f46b45', fillColor: '#f46b45', fillOpacity: 1
                }).bindPopup('Último punto: ' + data.puntos[data.puntos.length -1].fecha_registro).addTo(mapa);
                markers.push(endMarker);

                // Ajustar vista
                mapa.fitBounds(polyline.getBounds(), { padding: [50, 50] });

                document.getElementById('stats_container').style.display = 'block';
                document.getElementById('point_count').textContent = data.puntos.length + ' puntos registrados';
                document.getElementById('gpx_container').style.display = 'block';
            });
    }

    function exportarGPX() {
        if (currentData.length === 0) return;
        
        let gpx = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>\n';
        gpx += '<gpx version="1.1" creator="GeoMoveAdmin" xmlns="http://www.topografix.com/GPX/1/1">\n';
        gpx += '  <trk>\n    <name>Recorrido ' + document.getElementById('fecha').value + '</name>\n    <trkseg>\n';
        
        currentData.forEach(p => {
            gpx += `      <trkpt lat="${p.latitud}" lon="${p.longitud}">\n        <time>${p.fecha_registro.replace(' ', 'T')}Z</time>\n      </trkpt>\n`;
        });
        
        gpx += '    </trkseg>\n  </trk>\n</gpx>';

        const blob = new Blob([gpx], { type: 'application/gpx+xml' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ruta_${document.getElementById('conductor_id').value}_${document.getElementById('fecha').value}.gpx`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
</script>
</body>
</html>

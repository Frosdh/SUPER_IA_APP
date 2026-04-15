<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['secretary_logged_in']) && !isset($_SESSION['super_admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSecretary = isset($_SESSION['secretary_logged_in']) && $_SESSION['secretary_logged_in'] === true;
$isSuperAdmin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
$coopId = $_SESSION['cooperativa_id'] ?? 0;

// Obtener lista de conductores de la tabla viajes
if ($isAdmin || $isSuperAdmin) {
    $conductores = $pdo->query("
        SELECT DISTINCT 
            id_conductor as id, 
            nombre_conductor as nombre 
        FROM viajes 
        ORDER BY nombre_conductor ASC
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            id_conductor as id, 
            nombre_conductor as nombre
        FROM viajes 
        WHERE cooperativa_id = ? 
        ORDER BY nombre_conductor ASC
    ");
    $stmt->execute([$coopId]);
    $conductores = $stmt->fetchAll();
}

$currentPage = 'historial_rutas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Historial de Rutas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
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
        .sidebar-brand i { margin-right: 10px; color: #fbbf24; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #fbbf24, #f59e0b); color: #1a0f3d; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #1a0f3d; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(0, 0, 0, 0.1); color: #1a0f3d; border: 1px solid #1a0f3d; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(0, 0, 0, 0.2); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .controls-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 20px; }
        #mapa_historial { width: 100%; height: 500px; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,.1); margin-bottom: 20px; }
        .alert-info { background: #dbeafe; border: 1px solid #93c5fd; color: #1e40af; padding: 12px 16px; border-radius: 8px; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="super_admin_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link active">
            <i class="fas fa-history"></i> Historial de Viajes
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
        <h2>👑 Super_IA - Historial de Rutas</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($_SESSION['super_admin_nombre'] ?? $_SESSION['nombre'] ?? 'Usuario'); ?></strong><br>
                <small><?php echo htmlspecialchars($_SESSION['super_admin_rol'] ?? $_SESSION['rol'] ?? 'Administrador'); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-map-marked-alt me-2"></i>Historial de Rutas GPS</h1>
        </div>

        <!-- Controles -->
        <div class="controls-card">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Conductor</label>
                    <select id="conductor_id" class="form-select" required>
                        <option value="">Selecciona un conductor...</option>
                        <?php foreach ($conductores as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Fecha</label>
                    <input type="date" id="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Ver Recorrido
                    </button>
                </div>
                <div class="col-md-2" id="gpx_container" style="display:none">
                    <button type="button" class="btn btn-success w-100" onclick="exportarGPX()">
                        <i class="fas fa-download me-2"></i>GPX
                    </button>
                </div>
            </form>
        </div>

        <!-- Mapa -->
        <div id="mapa_historial"></div>

        <div id="no_data_alert" class="alert-info" style="display:none">
            <i class="fas fa-info-circle me-2"></i>No se encontraron registros de ubicación para este conductor en la fecha seleccionada.
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
                    color: '#f59e0b',
                    weight: 4,
                    opacity: 0.8,
                    smoothFactor: 1
                }).addTo(mapa);

                // Marcador de inicio (Verde)
                const startMarker = L.circleMarker(latlngs[0], {
                    radius: 8, color: '#10b981', fillColor: '#10b981', fillOpacity: 1
                }).bindPopup('Inicio del recorrido: ' + data.puntos[0].fecha_registro).addTo(mapa);
                markers.push(startMarker);

                // Marcador de fin (Rojo)
                const endMarker = L.circleMarker(latlngs[latlngs.length - 1], {
                    radius: 8, color: '#ef4444', fillColor: '#ef4444', fillOpacity: 1
                }).bindPopup('Último punto: ' + data.puntos[data.puntos.length -1].fecha_registro).addTo(mapa);
                markers.push(endMarker);

                // Ajustar vista
                mapa.fitBounds(polyline.getBounds(), { padding: [50, 50] });
                document.getElementById('gpx_container').style.display = 'block';
            });
    }

    function exportarGPX() {
        if (currentData.length === 0) return;
        
        let gpx = '<' + '?xml version="1.0" encoding="UTF-8"?' + '>\n';
        gpx += '<gpx version="1.1" creator="Super_IAAdmin" xmlns="http://www.topografix.com/GPX/1/1">\n';
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

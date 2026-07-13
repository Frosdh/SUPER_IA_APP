<?php
require_once 'db_admin.php';

// Verificar sesión de admin o super_admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

// Determinar variables según el rol
$user_nombre = $is_super_admin ? $_SESSION['super_admin_nombre'] : $_SESSION['admin_nombre'];
$user_rol = $is_super_admin ? $_SESSION['super_admin_rol'] : $_SESSION['admin_rol'];

$currentPage = 'mapa_calor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapa de Calor - Super_IA Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet-heat/0.2.0/leaflet-heat.min.js"></script>
    <style>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
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
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 24px rgba(18, 58, 109, 0.16); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        #map { width: 100%; height: 74vh; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .heat-toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 18px;
            background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,.06);
        }
        .heat-toolbar .chk { display: flex; align-items: center; gap: 7px; font-size: 13.5px; color: #374151; }
        .heat-toolbar .chk input { width: 15px; height: 15px; }
        .heat-toolbar .total { margin-left: auto; font-size: 13px; color: #6b7280; font-weight: 600; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<?php if ($is_super_admin): ?>
    <?php $currentPage = 'mapa_calor'; require_once '_sidebar_super_admin.php'; ?>
<?php else: ?>
<!-- SIDEBAR ADMIN (gerente/jefe_agencia) -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> Super_IA
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link active">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
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
</div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> Super_IA <?php echo $is_super_admin ? '- SuperAdmin' : '- Admin'; ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($user_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($user_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-fire me-2"></i>Mapa de Calor</h1>
            <p class="text-muted mt-2">Concentración real de encuestas realizadas (comercial, crediticia y de negocio), georeferenciadas por cliente</p>
        </div>

        <div class="heat-toolbar">
            <label class="chk"><input type="checkbox" value="comercial" checked> Encuesta comercial</label>
            <label class="chk"><input type="checkbox" value="crediticia" checked> Encuesta crediticia</label>
            <label class="chk"><input type="checkbox" value="negocio" checked> Encuesta de negocio</label>
            <span class="total" id="heat-total">Cargando…</span>
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

    let heatLayer = null;
    const checks = document.querySelectorAll('.heat-toolbar input[type="checkbox"]');
    const totalEl = document.getElementById('heat-total');

    function tiposSeleccionados() {
        return [...checks].filter(c => c.checked).map(c => c.value);
    }

    async function cargarCalor() {
        const tipos = tiposSeleccionados();
        totalEl.textContent = 'Cargando…';

        if (tipos.length === 0) {
            if (heatLayer) { map.removeLayer(heatLayer); heatLayer = null; }
            totalEl.textContent = 'Selecciona al menos un tipo de encuesta';
            return;
        }

        try {
            const resp = await fetch('api_mapa_calor_encuestas.php?tipos=' + tipos.join(','), { credentials: 'same-origin' });
            const data = await resp.json();

            if (data.status !== 'ok') {
                totalEl.textContent = 'Error: ' + (data.error || 'desconocido');
                return;
            }

            if (heatLayer) map.removeLayer(heatLayer);
            heatLayer = L.heatLayer(data.puntos, {
                radius: 22,
                blur: 18,
                maxZoom: 17
            }).addTo(map);

            const c = data.conteo || {};
            totalEl.textContent = `${data.total} encuesta(s) georeferenciada(s) ` +
                `(comercial: ${c.comercial || 0}, crediticia: ${c.crediticia || 0}, negocio: ${c.negocio || 0})`;

            if (data.puntos.length > 0) {
                map.fitBounds(data.puntos.map(p => [p[0], p[1]]), { maxZoom: 14, padding: [20, 20] });
            }
        } catch (err) {
            totalEl.textContent = 'Sin conexión con el servidor';
        }
    }

    checks.forEach(c => c.addEventListener('change', cargarCalor));
    cargarCalor();
</script>

</body>
</html>

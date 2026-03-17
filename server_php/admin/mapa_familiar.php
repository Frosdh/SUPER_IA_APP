<?php
require_once 'db_admin.php';

if (!isset($_SESSION['family_logged_in']) || $_SESSION['family_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$conductorNombre = $_SESSION['conductor_nombre'] ?? 'Conductor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove — Seguimiento en Tiempo Real</title>
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #6b11ff;
            --secondary: #3182fe;
            --dark: #1e293b;
            --light: #f8fafc;
        }

        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Inter', sans-serif;
            background-color: var(--light);
        }

        #map {
            height: 100vh;
            width: 100%;
            z-index: 1;
        }

        .header-status {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            background: white;
            padding: 12px 24px;
            border-radius: 50px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 300px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .driver-avatar {
            width: 40px;
            height: 40px;
            background: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .driver-info h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--dark);
        }

        .driver-info p {
            margin: 0;
            font-size: 12px;
            color: #64748b;
        }

        .status-badge {
            margin-left: auto;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-offline { background: #fee2e2; color: #ef4444; }
        .status-online { background: #dcfce7; color: #22c55e; }

        .logout-btn {
            position: absolute;
            bottom: 30px;
            right: 20px;
            z-index: 1000;
            background: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            color: #ef4444;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
            background: #fffafa;
        }

        .pulse {
            width: 10px;
            height: 10px;
            background: #22c55e;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            box-shadow: 0 0 0 rgba(34, 197, 94, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        /* Leaflet Popup Styling */
        .leaflet-popup-content-wrapper {
            border-radius: 12px;
            padding: 5px;
        }
        .leaflet-popup-tip-container { display: none; }
    </style>
</head>
<body>

    <div class="header-status">
        <div class="driver-avatar"><i class="fas fa-user-tie"></i></div>
        <div class="driver-info">
            <h2><?= htmlspecialchars($conductorNombre) ?></h2>
            <p id="last-update">Buscando ubicación...</p>
        </div>
        <div id="status-tag" class="status-badge status-offline">Desconectado</div>
    </div>

    <button class="logout-btn" onclick="location.href='logout.php'">
        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
    </button>

    <div id="map"></div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

    <script>
        // Inicializar el mapa centrado en una posición por defecto (Ecuador por ejemplo)
        const map = L.map('map').setView([-0.1807, -78.4678], 15);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        let driverMarker = null;
        let isFirstLoad = true;

        // Icono personalizado para el conductor
        const driverIcon = L.divIcon({
            className: 'custom-div-icon',
            html: `
                <div style="background-color: #6b11ff; width: 40px; height: 40px; border-radius: 50%; border: 3px solid white; display: flex; align-items: center; justify-content: center; box-shadow: 0 5px 15px rgba(0,0,0,0.3);">
                    <i class="fas fa-car" style="color: white; font-size: 18px;"></i>
                </div>
            `,
            iconSize: [40, 40],
            iconAnchor: [20, 20]
        });

        async function updateLocation() {
            try {
                const response = await fetch('api_ubicacion_familiar.php');
                const data = await response.json();

                if (data.status === 'success' && data.latitud && data.longitud) {
                    const pos = [data.latitud, data.longitud];
                    
                    if (!driverMarker) {
                        driverMarker = L.marker(pos, { icon: driverIcon }).addTo(map);
                        driverMarker.bindPopup(`<b>${data.nombre}</b><br>Ubicación actual`).openPopup();
                    } else {
                        driverMarker.setLatLng(pos);
                    }

                    if (isFirstLoad) {
                        map.setView(pos, 16);
                        isFirstLoad = false;
                    }

                    // Actualizar UI
                    document.getElementById('last-update').innerHTML = '<span class="pulse"></span> En vivo ahora';
                    const tag = document.getElementById('status-tag');
                    if (data.estado === 'libre' || data.estado === 'ocupado') {
                        tag.innerText = 'En Línea';
                        tag.className = 'status-badge status-online';
                    } else {
                        tag.innerText = 'Desconectado';
                        tag.className = 'status-badge status-offline';
                    }
                } else if (data.status === 'error' && data.message === 'Sesion no iniciada') {
                    window.location.href = 'login.php';
                }
            } catch (error) {
                console.error('Error al actualizar la ubicación:', error);
            }
        }

        // Actualizar cada 5 segundos
        setInterval(updateLocation, 5000);
        updateLocation(); // Ejecutar inmediatamente al cargar
    </script>

</body>
</html>

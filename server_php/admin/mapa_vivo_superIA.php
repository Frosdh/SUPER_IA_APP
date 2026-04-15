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

// ── Cargar TODOS los asesores (online + offline) para el panel ──
$todos_asesores = [];
try {
    $sqlTodos = "
        SELECT
            a.id            AS asesor_id,
            u.nombre        AS asesor_nombre,
            COALESCE(ap.estado, 'desconectado') AS estado,
            ua.latitud,
            ua.longitud,
            ua.timestamp    AS ultima_vez
        FROM asesor a
        JOIN supervisor s  ON s.id  = a.supervisor_id
        JOIN usuario    u  ON u.id  = a.usuario_id
        LEFT JOIN asesor_presencia ap ON ap.asesor_id = a.id
        LEFT JOIN (
            SELECT ua1.asesor_id, ua1.latitud, ua1.longitud, ua1.timestamp
            FROM ubicacion_asesor ua1
            INNER JOIN (
                SELECT asesor_id, MAX(timestamp) AS max_ts
                FROM ubicacion_asesor
                GROUP BY asesor_id
            ) latest ON latest.asesor_id = ua1.asesor_id
                    AND latest.max_ts    = ua1.timestamp
        ) ua ON ua.asesor_id = a.id
        WHERE s.usuario_id = ?
        ORDER BY
            CASE WHEN COALESCE(ap.estado, 'desconectado') = 'conectado' THEN 0 ELSE 1 END,
            u.nombre ASC
    ";
    $stTodos = $conn->prepare($sqlTodos);
    if ($stTodos) {
        $stTodos->bind_param('s', $supervisor_id);
        $stTodos->execute();
        $resTodos = $stTodos->get_result();
        $now = time();
        while ($row = $resTodos->fetch_assoc()) {
            $online = ($row['estado'] === 'conectado');
            if (!$online && $row['ultima_vez']) {
                $diff = $now - strtotime($row['ultima_vez']);
                if ($diff <= 120) $online = true;
            }
            $todos_asesores[] = [
                'asesor_id'  => $row['asesor_id'],
                'nombre'     => $row['asesor_nombre'],
                'online'     => $online,
                'latitud'    => $row['latitud']  !== null ? (float)$row['latitud']  : null,
                'longitud'   => $row['longitud'] !== null ? (float)$row['longitud'] : null,
                'ultima_vez' => $row['ultima_vez'],
            ];
        }
        $stTodos->close();
    }
} catch (\Throwable $eTodos) { /* no bloquear la página */ }

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
        .content-area { flex:1; display:flex; flex-direction:column; padding:20px; overflow:hidden; }
        .map-row      { flex:1; display:flex; gap:16px; overflow:hidden; min-height:0; }
        .map-container { flex:1; position:relative; min-width:0; height:100%; }

        /* ── Mapa ── */
        #map {
            height: 100%; width:100%; border-radius:18px;
            box-shadow:var(--brand-shadow); border:1px solid var(--brand-border);
        }

        /* ── Panel de asesores (derecha) ── */
        .panel-asesores {
            width:270px; flex-shrink:0; background:#fff; border-radius:16px;
            box-shadow:0 4px 20px rgba(0,0,0,.10); display:flex; flex-direction:column;
            overflow:hidden; border:1px solid var(--brand-border);
        }
        .panel-header {
            background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));
            color:#fff; padding:14px 16px; display:flex;
            justify-content:space-between; align-items:center; flex-shrink:0;
        }
        .panel-header span:first-child { font-weight:700; font-size:14px; }
        .panel-online-badge {
            background:#10B981; color:#fff; font-size:11px;
            padding:3px 9px; border-radius:20px; font-weight:700;
        }
        .panel-body { flex:1; overflow-y:auto; padding:8px; }
        .panel-section-title {
            font-size:10px; font-weight:700; letter-spacing:.6px;
            text-transform:uppercase; padding:8px 8px 4px;
            display:flex; align-items:center; gap:6px;
        }
        .panel-section-title.online-title  { color:#059669; }
        .panel-section-title.offline-title { color:#9CA3AF; margin-top:6px; }
        .panel-section-dot {
            width:7px; height:7px; border-radius:50%; flex-shrink:0;
        }
        .asesor-item {
            padding:9px 10px; margin-bottom:5px; border-radius:10px;
            cursor:pointer; transition:all .18s; font-size:13px;
            border:1px solid transparent;
        }
        .asesor-item.item-online {
            background:#f0fdf4; border-color:#bbf7d0;
        }
        .asesor-item.item-online:hover  { background:#dcfce7; border-color:#86efac; }
        .asesor-item.item-offline {
            background:#f9fafb; border-color:#e5e7eb;
        }
        .asesor-item.item-offline:hover { background:#f3f4f6; border-color:#d1d5db; }
        .asesor-item.selected {
            box-shadow:0 0 0 2px var(--brand-yellow-deep);
            border-color:var(--brand-yellow-deep) !important;
        }
        .asesor-item .item-name {
            font-weight:700; color:#1f2937; display:flex;
            align-items:center; justify-content:space-between; gap:8px; margin-bottom:3px;
        }
        .item-name-left { display:flex; align-items:center; gap:6px; min-width:0; }
        .encuesta-pill {
            font-size:10px; font-weight:800; line-height:1;
            padding:3px 8px; border-radius:999px;
            background:rgba(16,185,129,.14); color:#065F46;
            border:1px solid rgba(16,185,129,.28);
            flex-shrink:0;
        }
        .encuesta-pill.empty {
            background:rgba(156,163,175,.12); color:#6B7280;
            border-color:rgba(156,163,175,.25);
        }
        .btn-toggle-clientes {
            border:none; background:transparent; padding:2px 4px;
            color:#6B7280; cursor:pointer; border-radius:8px;
        }
        .btn-toggle-clientes:hover { background:rgba(17,24,39,.06); color:#111827; }
        .asesor-clientes {
            margin-top:6px; padding:8px 8px;
            border-top:1px dashed rgba(17,24,39,.14);
            color:#6B7280; font-size:11px;
        }
        .asesor-cliente {
            display:flex; justify-content:space-between; gap:8px;
            padding:4px 0;
        }
        .asesor-cliente .nombre { font-weight:700; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .asesor-cliente .hora { color:#9CA3AF; flex-shrink:0; }
        .asesor-item .item-meta { color:#6b7280; font-size:11px; }
        .online-dot {
            display:inline-block; width:8px; height:8px;
            background:#10B981; border-radius:50%; flex-shrink:0;
            animation:pulse 2s infinite;
        }
        .offline-dot {
            display:inline-block; width:8px; height:8px;
            background:#9CA3AF; border-radius:50%; flex-shrink:0;
        }
        .panel-empty {
            text-align:center; color:#9CA3AF; font-size:12px;
            padding:10px 8px;
        }
        .panel-footer {
            background:#f8f9fa; border-top:1px solid #e5e7eb;
            padding:8px 12px; font-size:11px; color:#9CA3AF;
            flex-shrink:0; text-align:center;
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

        /* ── Leyenda rutas (dentro del .map-container) ── */
        .leyenda-rutas {
            position:absolute; bottom:20px; left:16px;
            background:#fff; padding:12px 16px; border-radius:12px;
            box-shadow:0 4px 16px rgba(0,0,0,.12); z-index:400;
            min-width:200px; max-width:260px; max-height:220px;
            overflow-y:auto; pointer-events:auto;
        }
        .leyenda-rutas h6 { font-size:13px; font-weight:700; margin-bottom:8px; color:#1f2937; }
        .leyenda-item { display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px; color:#374151; }
        .leyenda-color { width:32px; height:4px; border-radius:2px; flex-shrink:0; }
        .leyenda-dot   { width:10px; height:10px; border-radius:50%; flex-shrink:0; }

        /* ── Lista de clientes encuestados ── */
        .cliente-item {
            padding:8px 10px; border-radius:10px;
            border:1px solid #e5e7eb; background:#f9fafb;
            cursor:pointer; transition:all .15s;
            margin-bottom:6px;
        }
        .cliente-item:hover { background:#f3f4f6; border-color:#d1d5db; }
        .cliente-item.disabled { cursor:not-allowed; opacity:.65; }
        .cliente-item.disabled:hover { background:#f9fafb; border-color:#e5e7eb; }
        .cliente-name { font-weight:800; color:#111827; font-size:12.5px; margin-bottom:3px; }
        .cliente-meta { color:#6b7280; font-size:11px; }

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
                <span id="count-label"><?= count($ubicaciones) ?></span> asesores en línea
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
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex align-items-center gap-2"
                     style="background:rgba(255,221,0,.14);border:1px solid rgba(255,221,0,.28);
                            border-radius:12px;padding:8px 10px;">
                    <i class="fas fa-calendar-day" style="color:var(--brand-navy-deep);"></i>
                    <input type="date" id="fecha-ruta" value="<?= date('Y-m-d') ?>"
                           class="form-control form-control-sm"
                           style="width:160px;border-radius:10px;border:1px solid rgba(18,58,109,.18);" />
                </div>

                <button id="buscar-fecha-btn" class="btn"
                        style="background:rgba(14,165,233,.14);color:var(--brand-navy-deep);
                               border:1px solid rgba(14,165,233,.28);font-weight:800;
                               border-radius:12px;padding:10px 14px;">
                    <i class="fas fa-magnifying-glass"></i> Buscar
                </button>

                <button id="refresh-btn" class="btn"
                    style="background:rgba(255,221,0,.18);color:var(--brand-navy-deep);
                           border:1px solid rgba(255,221,0,.35);font-weight:700;
                           border-radius:12px;padding:10px 14px;">
                    <i class="fas fa-sync-alt"></i> Actualizar
                </button>
            </div>
        </div>

        <!-- Fila: mapa + panel de asesores -->
        <div class="map-row">

        <!-- Contenedor del mapa + leyenda -->
        <div class="map-container">
            <div id="map"></div>

            <!-- Leyenda de rutas (dentro del contenedor del mapa) -->
            <div class="leyenda-rutas" id="leyenda-rutas" style="display:none;">
                <h6><i class="fas fa-route me-1" style="color:#3B82F6;"></i>Rutas de Hoy</h6>
                <div id="leyenda-items">
                    <small style="color:#9CA3AF;">Sin rutas registradas aún</small>
                </div>
            </div>

            <!-- Clientes encuestados (por asesor + fecha) -->
            <div class="leyenda-rutas" id="box-clientes"
                 style="display:none; top:20px; right:16px; left:auto; bottom:auto; min-width:240px; max-width:320px;">
                <h6><i class="fas fa-clipboard-check me-1" style="color:#10B981;"></i>Clientes encuestados</h6>
                <div id="clientes-items">
                    <small style="color:#9CA3AF;">Seleccione un asesor</small>
                </div>
            </div>
        </div>

        <!-- Panel de todos los asesores (derecha) -->
        <div class="panel-asesores">
            <div class="panel-header">
                <span><i class="fas fa-users me-2"></i>Asesores</span>
                <span class="panel-online-badge" id="panel-badge">
                    <?= count(array_filter($todos_asesores, fn($a) => $a['online'])) ?> en línea
                </span>
            </div>

            <div class="panel-body" id="panel-body">
                <!-- Sección: En línea -->
                <div class="panel-section-title online-title">
                    <span class="panel-section-dot" style="background:#10B981;"></span>
                    EN LÍNEA (<span id="count-online">0</span>)
                </div>
                <div id="panel-online">
                    <?php
                    $activos = array_filter($todos_asesores, fn($a) => $a['online']);
                    if (empty($activos)): ?>
                    <div class="panel-empty">Sin asesores en línea</div>
                    <?php else: foreach ($activos as $a): ?>
                    <div class="asesor-item item-online"
                         onclick="verAsesor('<?= htmlspecialchars($a['asesor_id']) ?>',
                                  <?= $a['latitud'] ?? 'null' ?>,
                                  <?= $a['longitud'] ?? 'null' ?>, true)">
                        <div class="item-name">
                            <span class="online-dot"></span>
                            <?= htmlspecialchars($a['nombre']) ?>
                        </div>
                        <div class="item-meta">
                            <i class="fas fa-location-dot"></i> En línea &nbsp;
                            <i class="fas fa-clock"></i> <?= $a['ultima_vez'] ? date('H:i', strtotime($a['ultima_vez'])) : '--:--' ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Sección: Desconectados -->
                <div class="panel-section-title offline-title">
                    <span class="panel-section-dot" style="background:#9CA3AF;"></span>
                    DESCONECTADOS (<span id="count-offline">0</span>)
                </div>
                <div id="panel-offline">
                    <?php
                    $inactivos = array_filter($todos_asesores, fn($a) => !$a['online']);
                    if (empty($inactivos)): ?>
                    <div class="panel-empty">Sin asesores desconectados</div>
                    <?php else: foreach ($inactivos as $a): ?>
                    <div class="asesor-item item-offline"
                         onclick="verAsesor('<?= htmlspecialchars($a['asesor_id']) ?>',
                                  <?= $a['latitud'] ?? 'null' ?>,
                                  <?= $a['longitud'] ?? 'null' ?>, <?= ($a['latitud'] !== null) ? 'true' : 'false' ?>)">
                        <div class="item-name">
                            <span class="offline-dot"></span>
                            <?= htmlspecialchars($a['nombre']) ?>
                        </div>
                        <div class="item-meta">
                            <?php if ($a['ultima_vez']): ?>
                            <i class="fas fa-clock"></i>
                            <?php
                            $diff = time() - strtotime($a['ultima_vez']);
                            if ($diff < 60) echo 'Hace un momento';
                            elseif ($diff < 3600) echo 'Hace ' . floor($diff/60) . ' min';
                            elseif ($diff < 86400) echo 'Hace ' . floor($diff/3600) . 'h';
                            else echo 'Hace ' . floor($diff/86400) . 'd';
                            ?>
                            <?php else: ?>
                            <i class="fas fa-question-circle"></i> Sin datos
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="panel-footer">
                <i class="fas fa-sync-alt"></i> Actualiza cada 10s &nbsp;|&nbsp;
                <span id="last-update">--:--:--</span>
            </div>
        </div><!-- /.panel-asesores -->

        </div><!-- /.map-row -->
    </div><!-- /.content-area -->
</div><!-- /.main-content -->

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

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[c]));
}

function getSelectedFecha() {
    const el = document.getElementById('fecha-ruta');
    const v = el ? String(el.value || '').trim() : '';
    return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : null;
}

function formatFechaES(fecha) {
    if (!fecha) return '';
    const d = new Date(fecha + 'T00:00:00');
    return isNaN(d.getTime()) ? '' : d.toLocaleDateString('es-ES', { weekday:'short', day:'numeric', month:'short' });
}

function cargarResumenEncuestas() {
    const fecha = getSelectedFecha();
    const qs = new URLSearchParams();
    if (fecha) qs.set('fecha', fecha);

    fetch(`api_encuestas_resumen.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'ok' || typeof data.por_asesor !== 'object') return;
            const mapCnt = data.por_asesor || {};

            document.querySelectorAll('.encuesta-pill[id^="encuestas-"]').forEach(el => {
                const id = el.id.replace('encuestas-', '');
                const cnt = parseInt(mapCnt[id] ?? 0, 10);
                if (cnt > 0) {
                    el.textContent = String(cnt);
                    el.classList.remove('empty');
                } else {
                    el.textContent = '0';
                    el.classList.add('empty');
                }
            });
        })
        .catch(err => console.warn('[encuestas_resumen]', err));
}

// ── Clientes encuestados por asesor (desplegable en el panel) ──
const clientesAsesorCache = {};     // key: "asesorId|fecha" → clientes[]
const clientesAsesorExpanded = {};  // asesorId → true/false

function setChevron(asesorId, expanded) {
    const ic = document.getElementById(`chev-${asesorId}`);
    if (!ic) return;
    ic.classList.remove('fa-chevron-down', 'fa-chevron-up');
    ic.classList.add(expanded ? 'fa-chevron-up' : 'fa-chevron-down');
}

function renderClientesAsesorInline(asesorId, clientes) {
    const box = document.getElementById(`clientes-asesor-${asesorId}`);
    if (!box) return;

    if (!Array.isArray(clientes) || clientes.length === 0) {
        box.innerHTML = '<small style="color:#9CA3AF;">Sin encuestas</small>';
        return;
    }

    box.innerHTML = clientes.slice(0, 8).map(c => {
        const nombre = escapeHtml(c.cliente_nombre || 'Cliente');
        const hora = escapeHtml(c.hora || '');
        return `<div class="asesor-cliente">
            <div class="nombre" title="${nombre}">${nombre}</div>
            <div class="hora">${hora || '--:--'}</div>
        </div>`;
    }).join('') + (clientes.length > 8 ? `<div style="margin-top:6px;color:#9CA3AF;">+${clientes.length - 8} más…</div>` : '');
}

function fetchClientesAsesor(asesorId, fecha) {
    const qs = new URLSearchParams({ asesor_id: asesorId });
    if (fecha) qs.set('fecha', fecha);
    return fetch(`api_clientes_encuestados.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json());
}

function toggleClientesAsesor(asesorId) {
    const box = document.getElementById(`clientes-asesor-${asesorId}`);
    if (!box) return;

    const fecha = getSelectedFecha();
    const key = `${asesorId}|${fecha || ''}`;

    const isHidden = box.style.display === 'none' || box.style.display === '';
    if (isHidden) {
        clientesAsesorExpanded[asesorId] = true;
        box.style.display = 'block';
        setChevron(asesorId, true);

        if (clientesAsesorCache[key]) {
            renderClientesAsesorInline(asesorId, clientesAsesorCache[key]);
            return;
        }

        box.innerHTML = '<small style="color:#9CA3AF;">Cargando…</small>';
        fetchClientesAsesor(asesorId, fecha)
            .then(data => {
                if (data.status === 'ok') {
                    clientesAsesorCache[key] = data.clientes || [];
                    renderClientesAsesorInline(asesorId, clientesAsesorCache[key]);
                } else {
                    box.innerHTML = '<small style="color:#EF4444;">No se pudo cargar</small>';
                }
            })
            .catch(err => {
                console.warn('[clientes_panel]', err);
                box.innerHTML = '<small style="color:#EF4444;">No se pudo cargar</small>';
            });
    } else {
        clientesAsesorExpanded[asesorId] = false;
        box.style.display = 'none';
        setChevron(asesorId, false);
    }
}

function refreshClientesAsesorExpandidos() {
    Object.keys(clientesAsesorExpanded).forEach(asesorId => {
        if (!clientesAsesorExpanded[asesorId]) return;
        const box = document.getElementById(`clientes-asesor-${asesorId}`);
        if (!box) return;
        box.style.display = 'block';
        setChevron(asesorId, true);

        const fecha = getSelectedFecha();
        const key = `${asesorId}|${fecha || ''}`;
        if (clientesAsesorCache[key]) {
            renderClientesAsesorInline(asesorId, clientesAsesorCache[key]);
            return;
        }

        box.innerHTML = '<small style="color:#9CA3AF;">Cargando…</small>';
        fetchClientesAsesor(asesorId, fecha)
            .then(data => {
                if (data.status === 'ok') {
                    clientesAsesorCache[key] = data.clientes || [];
                    renderClientesAsesorInline(asesorId, clientesAsesorCache[key]);
                } else {
                    box.innerHTML = '<small style="color:#EF4444;">No se pudo cargar</small>';
                }
            })
            .catch(err => {
                console.warn('[clientes_panel][refresh]', err);
                box.innerHTML = '<small style="color:#EF4444;">No se pudo cargar</small>';
            });
    });
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

// ── Helpers de tiempo ────────────────────────────────────────
function formatTimeSince(ts) {
    if (!ts) return '';
    const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 60000);
    if (diff < 1)  return 'hace un momento';
    if (diff < 60) return `hace ${diff} min`;
    const hrs = Math.floor(diff / 60);
    if (hrs < 24)  return `hace ${hrs}h`;
    return `hace ${Math.floor(hrs / 24)}d`;
}

// ── Renderizar panel de asesores ─────────────────────────────
function renderPanel(asesores) {
    const onlineEl   = document.getElementById('panel-online');
    const offlineEl  = document.getElementById('panel-offline');
    const cntOnline  = document.getElementById('count-online');
    const cntOffline = document.getElementById('count-offline');
    const badge      = document.getElementById('panel-badge');
    const countL     = document.getElementById('count-label');
    if (!onlineEl || !offlineEl) return;

    const online  = asesores.filter(a => a.online);
    const offline = asesores.filter(a => !a.online);

    if (cntOnline)  cntOnline.textContent  = online.length;
    if (cntOffline) cntOffline.textContent = offline.length;
    if (badge) {
        badge.textContent  = online.length + ' en línea';
        badge.style.background = online.length > 0 ? '#10B981' : '#9CA3AF';
    }
    if (countL) countL.textContent = online.length;

    const makeItem = (a) => {
        const lat    = a.latitud  !== null ? parseCoord(a.latitud)  : null;
        const lng    = a.longitud !== null ? parseCoord(a.longitud) : null;
        const hasLoc = lat !== null && lng !== null;
        const latJS  = hasLoc ? lat : 'null';
        const lngJS  = hasLoc ? lng : 'null';
        const tiempoStr = a.online
            ? `<i class="fas fa-location-dot"></i> En línea &nbsp;<i class="fas fa-clock"></i> ${a.ultima_vez ? fmtHour(a.ultima_vez) : '--:--'}`
            : (a.ultima_vez
                ? `<i class="fas fa-clock"></i> ${formatTimeSince(a.ultima_vez)}`
                : '<i class="fas fa-question-circle"></i> Sin datos');

        return `<div class="asesor-item ${a.online ? 'item-online' : 'item-offline'}" id="item-${a.asesor_id}"
                     onclick="verAsesor('${a.asesor_id}',${latJS},${lngJS},${hasLoc})"
                     title="Ver última ruta">
            <div class="item-name">
                <div class="item-name-left">
                    <span class="${a.online ? 'online-dot' : 'offline-dot'}"></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${a.nombre || 'Asesor'}</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span class="encuesta-pill empty" id="encuestas-${a.asesor_id}" title="Clientes encuestados (fecha seleccionada)">0</span>
                    <button type="button" class="btn-toggle-clientes" title="Ver clientes encuestados"
                            onclick="event.stopPropagation();toggleClientesAsesor('${a.asesor_id}')">
                        <i class="fas fa-chevron-down" id="chev-${a.asesor_id}"></i>
                    </button>
                </div>
            </div>
            <div class="item-meta">${tiempoStr}</div>
            <div class="asesor-clientes" id="clientes-asesor-${a.asesor_id}" style="display:none;">
                <small style="color:#9CA3AF;">Cargando…</small>
            </div>
        </div>`;
    };

    onlineEl.innerHTML  = online.length  > 0
        ? online.map(makeItem).join('')
        : '<div class="panel-empty">Sin asesores en línea</div>';
    offlineEl.innerHTML = offline.length > 0
        ? offline.map(makeItem).join('')
        : '<div class="panel-empty">Sin asesores desconectados</div>';

    // Re-marcar el asesor seleccionado
    if (selectedAsesorId) {
        const el = document.getElementById(`item-${selectedAsesorId}`);
        if (el) el.classList.add('selected');
    }

    // Mantener desplegados (y recargar si cambia la fecha)
    refreshClientesAsesorExpandidos();
}

// ── Marcadores auxiliares ──────────────────────────────────
let lastSeenMarker   = null; // última ubicación para asesores offline
let selectedAsesorId = null;
let clienteMarker    = null; // marcador al hacer click en un cliente encuestado

function renderClientes(clientes, fecha) {
    const box  = document.getElementById('box-clientes');
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

    list.innerHTML = clientes.map((c) => {
        const nombre  = escapeHtml(c.cliente_nombre || 'Cliente');
        const hora    = escapeHtml(c.hora || '');
        const tipo    = escapeHtml((c.tipo_tarea || '').replace('_', ' '));
        const tareaId = escapeHtml(c.tarea_id || '');
        const hasLoc  = c.latitud !== null && c.longitud !== null && isFinite(c.latitud) && isFinite(c.longitud);

        return `<div class="cliente-item ${hasLoc ? '' : 'disabled'}"
                    data-lat="${hasLoc ? c.latitud : ''}"
                    data-lng="${hasLoc ? c.longitud : ''}"
                    data-tarea="${tareaId}"
                    data-nombre="${nombre}">
            <div class="cliente-name">${nombre}</div>
            <div class="cliente-meta"><i class="fas fa-clock"></i> ${hora || '--:--'} ${tipo ? '· ' + tipo : ''}</div>
        </div>`;
    }).join('');

    list.querySelectorAll('.cliente-item').forEach(el => {
        if (el.classList.contains('disabled')) return;
        el.addEventListener('click', () => {
            const lat = parseFloat(el.getAttribute('data-lat'));
            const lng = parseFloat(el.getAttribute('data-lng'));
            const nombre = el.getAttribute('data-nombre') || 'Cliente';
            const tareaId = el.getAttribute('data-tarea') || '';
            if (!isFinite(lat) || !isFinite(lng)) return;

            // Quitar marcador previo
            if (clienteMarker) { map.removeLayer(clienteMarker); clienteMarker = null; }

            const icon = L.divIcon({
                html: `<div style="width:36px;height:36px;border-radius:50%;
                             display:flex;align-items:center;justify-content:center;
                             background:linear-gradient(135deg,#0EA5E9,#3B82F6);
                             border:3px solid #fff;color:#fff;font-size:16px;
                             box-shadow:0 4px 14px rgba(59,130,246,.35);">
                           <i class="fas fa-flag"></i>
                       </div>`,
                iconSize:[36,36], iconAnchor:[18,18], popupAnchor:[0,-18],
                className:'advisor-marker'
            });

            clienteMarker = L.marker([lat, lng], { icon })
                .addTo(map)
                .bindPopup(`<div class="advisor-popup">
                    <div class="popup-name"><i class="fas fa-user me-1"></i>${escapeHtml(nombre)}</div>
                    <div class="popup-row"><i class="fas fa-location-dot me-1" style="color:#10B981;"></i>${lat.toFixed(6)}, ${lng.toFixed(6)}</div>
                </div>`);

            map.flyTo([lat, lng], 16, { duration: 1.1 });
            setTimeout(() => clienteMarker?.openPopup(), 1200);

            const segId = tareaId ? segmentoPorTareaId[String(tareaId)] : null;
            const poly  = segId && rutaLayers[segId] ? rutaLayers[segId].polyline : null;
            if (poly) {
                try {
                    map.fitBounds(poly.getBounds(), { padding: [60, 60], maxZoom: 16 });
                    poly.openPopup();
                } catch (e) {}
            }
        });
    });
}

function cargarClientes(asesorId, fecha) {
    const box  = document.getElementById('box-clientes');
    const list = document.getElementById('clientes-items');
    if (box) box.style.display = 'block';
    if (list) list.innerHTML = '<small style="color:#9CA3AF;">Cargando clientes…</small>';

    const qs = new URLSearchParams({ asesor_id: asesorId });
    if (fecha) qs.set('fecha', fecha);

    fetch(`api_clientes_encuestados.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                renderClientes(data.clientes || [], data.fecha || fecha);
            } else {
                throw new Error(data.message || 'Error');
            }
        })
        .catch(err => {
            console.warn('[clientes_encuestados]', err);
            if (list) list.innerHTML = '<small style="color:#EF4444;">No se pudo cargar clientes</small>';
        });
}

// ── Mapa de segmento_id → { polyline, markers[] } ─────────
const rutaLayers        = {};
const rutaGroup         = L.featureGroup().addTo(map);
const segmentoPorTareaId = {}; // tarea_id → segmento_id

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1,3),16);
    const g = parseInt(hex.slice(3,5),16);
    const b = parseInt(hex.slice(5,7),16);
    return `rgba(${r},${g},${b},${alpha})`;
}

// ══════════════════════════════════════════════════════════
//  renderRutas — dibuja segmentos en el mapa + leyenda
// ══════════════════════════════════════════════════════════
function renderRutas(segmentos) {
    rutaGroup.clearLayers();
    Object.keys(rutaLayers).forEach(k => delete rutaLayers[k]);
    Object.keys(segmentoPorTareaId).forEach(k => delete segmentoPorTareaId[k]);

    const leyendaEl  = document.getElementById('leyenda-items');
    const leyendaBox = document.getElementById('leyenda-rutas');
    const leyendaItems = {};

    if (!segmentos || segmentos.length === 0) {
        if (leyendaBox) leyendaBox.style.display = 'none';
        return;
    }
    if (leyendaBox) leyendaBox.style.display = 'block';

    segmentos.forEach(seg => {
        const color  = seg.color || '#3B82F6';
        const puntos = seg.puntos || [];
        const nombre = seg.asesor_nombre || 'Asesor';
        const num    = seg.numero;

        // ── Registrar mapeo tarea→segmento para click en cliente ──
        if (seg.tarea_id) segmentoPorTareaId[String(seg.tarea_id)] = seg.segmento_id;

        // ── Polilínea de la ruta ──
        if (puntos.length >= 2) {
            const latlngs = puntos.map(p => [p.lat, p.lng]);
            const poly = L.polyline(latlngs, {
                color, weight: 4, opacity: 0.85,
                dashArray: seg.estado === 'activo' ? '8 5' : null,
                lineJoin: 'round', lineCap: 'round',
            }).addTo(rutaGroup);

            const horaInicio = seg.inicio_at ? seg.inicio_at.substring(11,16) : '--:--';
            const horaFin    = seg.fin_at    ? seg.fin_at.substring(11,16)    : 'activo';
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
                        <i class="fas fa-map-pin"></i> ${puntos.length} pts GPS
                    </div>
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;">
                        Estado: <b style="color:${seg.estado==='activo'?'#10B981':'#6B7280'}">${seg.estado}</b>
                    </div>
                </div>`);
            rutaLayers[seg.segmento_id] = { polyline: poly, markers: [] };
        }

        // ── Marcador de inicio de sesión (primer segmento) ──
        if (seg.inicio_lat !== null && seg.inicio_lng !== null && num === 1) {
            const startIcon = L.divIcon({
                html: `<div style="width:14px;height:14px;border-radius:50%;
                             background:${color};border:3px solid #fff;
                             box-shadow:0 2px 8px rgba(0,0,0,.3);"></div>`,
                iconSize:[14,14], iconAnchor:[7,7], className:'',
            });
            const sm = L.marker([seg.inicio_lat, seg.inicio_lng], { icon: startIcon })
                .addTo(rutaGroup)
                .bindPopup(`<div style="font-family:'Inter',sans-serif;font-size:12px;">
                    <b>${escapeHtml(nombre)}</b><br>
                    <i class="fas fa-sign-in-alt"></i> Inicio sesión<br>
                    <small>${seg.inicio_at ? seg.inicio_at.substring(11,16) : ''}</small>
                </div>`);
            if (rutaLayers[seg.segmento_id]) rutaLayers[seg.segmento_id].markers.push(sm);
        }

        // ── Marcador de tarea completada (fin del segmento) ──
        if (seg.fin_lat !== null && seg.fin_lng !== null && seg.estado !== 'activo') {
            const tareaLabel = seg.tarea_tipo
                ? seg.tarea_tipo.replace('_',' ').replace(/\b\w/g, l => l.toUpperCase())
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
                        Seg. ${num} completado
                    </div>
                    ${seg.tarea_tipo ? `<div><i class="fas fa-tasks"></i> ${tareaLabel}</div>` : ''}
                    ${seg.cliente_nombre ? `<div><i class="fas fa-user"></i> ${escapeHtml(seg.cliente_nombre)}</div>` : ''}
                    <div style="color:#6B7280;font-size:11px;margin-top:3px;">
                        ${seg.fin_at ? seg.fin_at.substring(11,16) : ''}
                    </div>
                </div>`);
            if (rutaLayers[seg.segmento_id]) rutaLayers[seg.segmento_id].markers.push(tm);
        }

        // ── Leyenda ──
        const key = String(seg.asesor_id);
        if (!leyendaItems[key]) leyendaItems[key] = { nombre, segmentos: [], color };
        leyendaItems[key].segmentos.push({ num, color, estado: seg.estado, cliente: seg.cliente_nombre });
    });

    // Renderizar leyenda
    let leyendaHtml = '';
    Object.values(leyendaItems).forEach(item => {
        leyendaHtml += `<div style="margin-bottom:10px;">
            <div style="font-weight:700;font-size:12px;color:#1f2937;margin-bottom:4px;">
                <i class="fas fa-user-tie"></i> ${escapeHtml(item.nombre)}
            </div>`;
        item.segmentos.forEach(s => {
            const estadoIcon = s.estado === 'activo' ? '🟢' : (s.estado === 'completado' ? '✓' : '🔴');
            leyendaHtml += `<div class="leyenda-item">
                <div class="leyenda-color" style="background:${s.color};"></div>
                <span>${estadoIcon} Seg. ${s.num}${s.cliente ? ' · ' + s.cliente.split(' ')[0] : ''}</span>
            </div>`;
        });
        leyendaHtml += '</div>';
    });
    if (leyendaEl) leyendaEl.innerHTML = leyendaHtml || '<small style="color:#9CA3AF;">Sin rutas</small>';
}

// ════════════════════════════════════════════════════════════
//  cargarRutaYClientes
//  Sin fecha seleccionada → muestra SÓLO el último segmento
//  Con fecha seleccionada → muestra TODOS los segmentos del día
// ════════════════════════════════════════════════════════════
function cargarRutaYClientes(asesorId) {
    const fechaSel = getSelectedFecha();
    const esBusqueda = !!fechaSel; // ¿viene de buscar por fecha?

    // Limpiar rutas anteriores
    rutaGroup.clearLayers();
    Object.keys(rutaLayers).forEach(k => delete rutaLayers[k]);
    Object.keys(segmentoPorTareaId).forEach(k => delete segmentoPorTareaId[k]);
    const leyendaBox = document.getElementById('leyenda-rutas');
    if (leyendaBox) leyendaBox.style.display = 'none';

    // Mostrar loader en el box de clientes
    const box  = document.getElementById('box-clientes');
    const list = document.getElementById('clientes-items');
    if (box)  box.style.display = 'block';
    if (list) list.innerHTML = '<small style="color:#9CA3AF;">Cargando clientes…</small>';

    // Parámetros: sin fecha → solo_ultimo=1; con fecha → solo_ultimo=0 (todos los segmentos)
    const qs = new URLSearchParams({ asesor_id: asesorId });
    if (fechaSel) {
        qs.set('fecha', fechaSel);
        qs.set('solo_ultimo', '0'); // búsqueda por fecha → todos los segmentos
    } else {
        qs.set('solo_ultimo', '1'); // sin fecha → solo el último segmento
    }

    fetch(`api_ultima_ruta.php?${qs.toString()}`, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok' && data.segmentos?.length > 0) {
                renderRutas(data.segmentos);

                // Actualizar título de la leyenda
                const h6 = leyendaBox?.querySelector('h6');
                if (h6) {
                    const fechaStr = formatFechaES(data.fecha);
                    const modoStr  = esBusqueda ? 'Rutas del día' : 'Última ruta';
                    h6.innerHTML = `<i class="fas fa-route me-1" style="color:#3B82F6;"></i>${modoStr}${fechaStr ? ' · ' + fechaStr : ''}`;
                }

                // Ajustar la vista del mapa para ver la ruta
                const puntos = data.segmentos.flatMap(s => (s.puntos || []).map(p => [p.lat, p.lng]));
                if (puntos.length > 1) {
                    try { map.fitBounds(puntos, { padding:[60,60], maxZoom:16 }); } catch(e){}
                } else if (puntos.length === 1) {
                    map.setView(puntos[0], 15);
                }
            } else {
                // Sin rutas para esta fecha/asesor
                const h6 = leyendaBox?.querySelector('h6');
                if (h6) h6.innerHTML = `<i class="fas fa-route me-1" style="color:#9CA3AF;"></i>Sin rutas registradas`;
                if (list) list.innerHTML = '<small style="color:#9CA3AF;">Sin encuestas en esta fecha</small>';
            }

            // Cargar también los clientes encuestados del día
            const fechaParaClientes = fechaSel || data.fecha || null;
            if (fechaParaClientes) {
                cargarClientes(asesorId, fechaParaClientes);
            } else {
                if (list) list.innerHTML = '<small style="color:#9CA3AF;">Sin encuestas registradas</small>';
            }
        })
        .catch(err => {
            console.warn('[cargarRutaYClientes]', err);
            if (list) list.innerHTML = '<small style="color:#EF4444;">Error al cargar datos</small>';
        });
}

// ── Marcador "última ubicación" para asesores offline ────────
let lastSeenMarker2  = null; // alias para no colisionar con la variable de arriba

// ── Ver asesor: centra mapa + muestra ruta + clientes ────────
function verAsesor(asesorId, lat, lng, hasLoc) {
    // Marcar seleccionado en el panel
    document.querySelectorAll('.asesor-item').forEach(el => el.classList.remove('selected'));
    const selEl = document.getElementById(`item-${asesorId}`);
    if (selEl) selEl.classList.add('selected');
    selectedAsesorId = asesorId;

    // Quitar pin de "última ubicación" si había uno
    if (lastSeenMarker)  { map.removeLayer(lastSeenMarker);  lastSeenMarker  = null; }
    if (lastSeenMarker2) { map.removeLayer(lastSeenMarker2); lastSeenMarker2 = null; }
    if (clienteMarker)   { map.removeLayer(clienteMarker);   clienteMarker   = null; }

    // Centrar mapa
    if (hasLoc && lat !== null && lng !== null && isFinite(lat) && isFinite(lng)) {
        if (markerById[asesorId]) {
            // Online: ir al marcador en vivo
            map.flyTo([lat, lng], 15, { duration: 1.2 });
            setTimeout(() => { if (markerById[asesorId]) markerById[asesorId].openPopup(); }, 1300);
        } else {
            // Offline: pin gris en última posición conocida
            const grayIcon = L.divIcon({
                html: `<div style="width:40px;height:40px;border-radius:50%;
                             display:flex;align-items:center;justify-content:center;
                             background:linear-gradient(135deg,#9CA3AF,#6B7280);
                             border:3px solid #fff;color:#fff;font-size:17px;
                             box-shadow:0 4px 12px rgba(0,0,0,.3);">
                           <i class="fas fa-user"></i></div>`,
                iconSize:[40,40], iconAnchor:[20,20], popupAnchor:[0,-22], className:'advisor-marker'
            });
            lastSeenMarker = L.marker([lat, lng], { icon: grayIcon })
                .addTo(map)
                .bindPopup(`<div class="advisor-popup">
                    <div class="popup-name" style="color:#6B7280;">
                        <i class="fas fa-user-tie me-1"></i>Última ubicación conocida
                    </div>
                    <div class="popup-row" style="color:#EF4444;">
                        <i class="fas fa-wifi me-1"></i>Desconectado
                    </div>
                </div>`);
            map.flyTo([lat, lng], 15, { duration: 1.2 });
            setTimeout(() => lastSeenMarker?.openPopup(), 1300);
        }
    }

    // Cargar ruta + clientes
    cargarRutaYClientes(asesorId);
}

// ── Actualización AJAX de marcadores ─────────────────────────
function updateLocations(fitView = false) {
    fetch('api_ubicaciones_mapa.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                renderMap(data.ubicaciones, fitView);
                showToast();
            }
        })
        .catch(err => console.warn('[mapa]', err));
}

// ── Actualizar el panel de asesores (online + offline) ────────
function cargarPanel() {
    fetch('api_asesores_panel.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                renderPanel(data.asesores);
                const upd = document.getElementById('last-update');
                if (upd) upd.textContent = data.ts || '--:--:--';
            }
        })
        .catch(err => console.warn('[panel]', err));
}

// ── Render inicial ─────────────────────────────────────────────
const ubicacionesIniciales    = <?= json_encode($ubicaciones) ?>;
const todosAsesoresIniciales  = <?= json_encode($todos_asesores) ?>;

renderMap(ubicacionesIniciales, true);
renderPanel(todosAsesoresIniciales);
cargarResumenEncuestas();

const upd = document.getElementById('last-update');
if (upd) upd.textContent = new Date().toLocaleTimeString('es-ES');

// ── Auto-refresh cada 10 segundos ─────────────────────────────
const REFRESH_INTERVAL = 10000;
let refreshTimer = setInterval(() => {
    updateLocations(false);
    cargarPanel();
    cargarResumenEncuestas();
    // Si hay asesor seleccionado → recargar su última ubicación offline si aplica
    if (selectedAsesorId && !markerById[selectedAsesorId]) {
        // Asesor desconectado y aún seleccionado: no relanzar toda la carga,
        // solo actualizar el panel (ya hecho arriba)
    }
}, REFRESH_INTERVAL);

// ── Botón Actualizar ──────────────────────────────────────────
document.getElementById('refresh-btn')?.addEventListener('click', () => {
    clearInterval(refreshTimer);
    updateLocations(true);
    cargarPanel();
    cargarResumenEncuestas();
    if (selectedAsesorId) cargarRutaYClientes(selectedAsesorId);
    refreshTimer = setInterval(() => {
        updateLocations(false);
        cargarPanel();
        cargarResumenEncuestas();
    }, REFRESH_INTERVAL);
});

// ── Botón Buscar por fecha ────────────────────────────────────
document.getElementById('buscar-fecha-btn')?.addEventListener('click', () => {
    const fecha = getSelectedFecha();
    if (!fecha) return;

    // Invalidar cache de clientes inline para la nueva fecha
    Object.keys(clientesAsesorCache).forEach(k => {
        if (!k.includes(fecha)) delete clientesAsesorCache[k];
    });

    cargarResumenEncuestas(); // actualiza contadores del panel

    if (selectedAsesorId) {
        cargarRutaYClientes(selectedAsesorId); // recarga ruta + clientes para la fecha
    }
});

// ── Búsqueda al presionar Enter en el date picker ────────────
document.getElementById('fecha-ruta')?.addEventListener('change', () => {
    document.getElementById('buscar-fecha-btn')?.click();
});
</script>
</body>
</html>
<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ── Tarjetas resumen ───────────────────────────────────────────
$totalPendientes  = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0")->fetchColumn();
$totalConductores = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 1")->fetchColumn();
$totalUsuarios    = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();
$viajesHoy        = $pdo->query("SELECT COUNT(*) FROM viajes WHERE DATE(fecha_pedido) = CURDATE()")->fetchColumn();
$viajesTotal      = $pdo->query("SELECT COUNT(*) FROM viajes WHERE estado = 'terminado'")->fetchColumn();
$ingresosTotal    = $pdo->query("SELECT COALESCE(SUM(tarifa_total),0) FROM viajes WHERE estado = 'terminado'")->fetchColumn();
$ingresosHoy      = $pdo->query("SELECT COALESCE(SUM(tarifa_total),0) FROM viajes WHERE estado='terminado' AND DATE(fecha_pedido)=CURDATE()")->fetchColumn();
$viajesActivos    = $pdo->query("SELECT COUNT(*) FROM viajes WHERE estado IN ('pedido','aceptado','en_camino','iniciado')")->fetchColumn();

// ── Gráfico 1: Viajes por día (últimos 14 días) ────────────────
$stmtDias = $pdo->query("
    SELECT DATE(fecha_pedido) AS dia,
           COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN estado='terminado' THEN tarifa_total END), 0) AS ingresos
    FROM viajes
    WHERE fecha_pedido >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY dia
    ORDER BY dia ASC
");
$datosDias = $stmtDias->fetchAll();

$labelesDias  = [];
$serieViajes  = [];
$serieIngresos = [];

// Rellenar días sin datos con 0
for ($i = 13; $i >= 0; $i--) {
    $fecha = date('Y-m-d', strtotime("-$i days"));
    $labelesDias[] = date('d/m', strtotime($fecha));
    $serieViajes[]  = 0;
    $serieIngresos[] = 0;
}
foreach ($datosDias as $row) {
    $idx = array_search(date('d/m', strtotime($row['dia'])), $labelesDias);
    if ($idx !== false) {
        $serieViajes[$idx]   = (int)$row['total'];
        $serieIngresos[$idx] = (float)$row['ingresos'];
    }
}

// ── Gráfico 2: Estados de viajes (dona) ───────────────────────
$stmtEstados = $pdo->query("
    SELECT estado, COUNT(*) AS total
    FROM viajes
    GROUP BY estado
");
$estados = $stmtEstados->fetchAll();
$estadoLabels  = array_column($estados, 'estado');
$estadoTotales = array_map('intval', array_column($estados, 'total'));

// ── Gráfico 3: Top 5 conductores por viajes completados ───────
$stmtTop = $pdo->query("
    SELECT c.nombre,
           COUNT(v.id) AS viajes,
           COALESCE(SUM(v.tarifa_total), 0) AS ingresos,
           COALESCE(c.calificacion_promedio, 5) AS calificacion
    FROM conductores c
    INNER JOIN viajes v ON v.conductor_id = c.id AND v.estado = 'terminado'
    GROUP BY c.id
    ORDER BY viajes DESC
    LIMIT 5
");
$topConductores = $stmtTop->fetchAll();

// ── Últimos 6 viajes ──────────────────────────────────────────
$stmtRecientes = $pdo->query("
    SELECT v.id, v.origen_texto, v.destino_texto, v.tarifa_total, v.estado,
           v.fecha_pedido, u.nombre AS pasajero, c.nombre AS conductor
    FROM viajes v
    LEFT JOIN usuarios   u ON u.id = v.usuario_id
    LEFT JOIN conductores c ON c.id = v.conductor_id
    ORDER BY v.fecha_pedido DESC
    LIMIT 6
");
$viajesRecientes = $stmtRecientes->fetchAll();

function estadoBadge($e) {
    return match($e) {
        'terminado' => '<span class="badge bg-success">Completado</span>',
        'cancelado' => '<span class="badge bg-danger">Cancelado</span>',
        'en_camino' => '<span class="badge bg-info text-dark">En camino</span>',
        'iniciado'  => '<span class="badge bg-primary">En curso</span>',
        'aceptado'  => '<span class="badge bg-warning text-dark">Aceptado</span>',
        default     => '<span class="badge bg-secondary">Pedido</span>',
    };
}

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ── Tarjetas stat ── */
        .stat-card { background:#fff; border-radius:14px; padding:20px 22px; box-shadow:0 4px 16px rgba(0,0,0,.06); height:100%; }
        .stat-card h3 { font-size:28px; font-weight:700; margin:0; color:#24243e; }
        .stat-card p  { color:#6c757d; margin:4px 0 0; font-size:13px; font-weight:500; }
        .stat-card .trend { font-size:12px; margin-top:6px; }
        .icon-box { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .bg-purple { background:linear-gradient(135deg,#6b11ff,#9b51e0); }
        .bg-blue   { background:linear-gradient(135deg,#3182fe,#5b9cfc); }
        .bg-green  { background:linear-gradient(135deg,#11998e,#38ef7d); }
        .bg-orange { background:linear-gradient(135deg,#f46b45,#eea849); }
        .bg-red    { background:linear-gradient(135deg,#e53935,#e35d5b); }
        .bg-teal   { background:linear-gradient(135deg,#11998e,#2af598); }

        /* ── Tarjetas de gráfico ── */
        .chart-card { background:#fff; border-radius:14px; padding:22px; box-shadow:0 4px 16px rgba(0,0,0,.06); }
        .chart-card .chart-title { font-size:15px; font-weight:700; color:#24243e; margin-bottom:4px; }
        .chart-card .chart-sub   { font-size:12px; color:#6c757d; margin-bottom:16px; }

        /* ── Tabla ── */
        .table-card { background:#fff; border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,.06); overflow:hidden; }
        .table-card .card-header-custom { padding:16px 20px; border-bottom:1px solid #f0f0f0; }
        .table-card .card-header-custom h6 { font-weight:700; margin:0; }
        .table thead th { background:#f8f9fa; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; border:none; padding:11px 14px; }
        .table tbody td { padding:11px 14px; vertical-align:middle; border-color:#f5f5f5; font-size:13px; }
        .table tbody tr:hover { background:#fafbff; }

        /* ── Live badge ── */
        .live-dot { width:8px; height:8px; background:#11998e; border-radius:50%; display:inline-block; animation:pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }

        /* ── Estrellitas ── */
        .stars { color:#ffc107; font-size:12px; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- ── CONTENIDO ── -->
    <div class="col-md-10 content">

        <!-- Encabezado -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0">Dashboard General</h4>
                <small class="text-muted">
                    <?= date('l, d \d\e F \d\e Y') ?> &nbsp;·&nbsp;
                    <span class="live-dot"></span> En tiempo real
                </small>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-1"></i>Actualizar
            </button>
        </div>

        <!-- ── FILA 1: Tarjetas resumen ── -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?= number_format($viajesActivos) ?></h3>
                        <p>Viajes activos</p>
                        <div class="trend text-success"><i class="fas fa-circle live-dot me-1"></i>En vivo</div>
                    </div>
                    <div class="icon-box bg-green"><i class="fas fa-car-side"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?= number_format($viajesHoy) ?></h3>
                        <p>Viajes hoy</p>
                        <div class="trend text-muted">Total del día</div>
                    </div>
                    <div class="icon-box bg-blue"><i class="fas fa-route"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3>$<?= number_format($ingresosHoy, 0) ?></h3>
                        <p>Ingresos hoy</p>
                        <div class="trend text-muted">Viajes completados</div>
                    </div>
                    <div class="icon-box bg-orange"><i class="fas fa-dollar-sign"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?= number_format($totalConductores) ?></h3>
                        <p>Conductores</p>
                        <div class="trend text-warning">
                            <?php if ($totalPendientes > 0): ?>
                                <i class="fas fa-exclamation-circle me-1"></i><?= $totalPendientes ?> pendientes
                            <?php else: ?>
                                <i class="fas fa-check-circle me-1 text-success"></i>Al día
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="icon-box bg-purple"><i class="fas fa-id-badge"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3><?= number_format($totalUsuarios) ?></h3>
                        <p>Pasajeros</p>
                        <div class="trend text-muted">Cuentas activas</div>
                    </div>
                    <div class="icon-box bg-teal"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card d-flex justify-content-between align-items-start">
                    <div>
                        <h3>$<?= number_format($ingresosTotal, 0) ?></h3>
                        <p>Ingresos totales</p>
                        <div class="trend text-muted"><?= number_format($viajesTotal) ?> viajes</div>
                    </div>
                    <div class="icon-box bg-red"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
        </div>

        <!-- ── FILA 2: Gráficos ── -->
        <div class="row g-3 mb-4">

            <!-- Gráfico de línea: Viajes e ingresos últimos 14 días -->
            <div class="col-md-8">
                <div class="chart-card" style="height:300px">
                    <div class="chart-title">Viajes e Ingresos — Últimos 14 días</div>
                    <div class="chart-sub">Número de viajes solicitados y monto recaudado por día</div>
                    <canvas id="chartLinea" style="max-height:210px"></canvas>
                </div>
            </div>

            <!-- Gráfico de dona: Estados de viajes -->
            <div class="col-md-4">
                <div class="chart-card" style="height:300px">
                    <div class="chart-title">Distribución de Viajes</div>
                    <div class="chart-sub">Por estado actual en la plataforma</div>
                    <canvas id="chartDona" style="max-height:210px"></canvas>
                </div>
            </div>
        </div>

        <!-- ── FILA 3: Top conductores + Viajes recientes ── -->
        <div class="row g-3">

            <!-- Top 5 conductores -->
            <div class="col-md-5">
                <div class="table-card h-100">
                    <div class="card-header-custom d-flex justify-content-between align-items-center">
                        <h6><i class="fas fa-trophy text-warning me-2"></i>Top 5 Conductores</h6>
                        <small class="text-muted">Por viajes completados</small>
                    </div>
                    <?php if (empty($topConductores)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-car fa-2x mb-2 opacity-25"></i>
                        <p class="small mb-0">Aún no hay viajes completados.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Conductor</th>
                                    <th class="text-center">Viajes</th>
                                    <th class="text-center">Ingresos</th>
                                    <th class="text-center">Calif.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topConductores as $i => $c): ?>
                                <tr>
                                    <td>
                                        <?php if ($i === 0): ?>
                                            <i class="fas fa-trophy text-warning"></i>
                                        <?php elseif ($i === 1): ?>
                                            <i class="fas fa-medal" style="color:#aaa"></i>
                                        <?php elseif ($i === 2): ?>
                                            <i class="fas fa-medal" style="color:#cd7f32"></i>
                                        <?php else: ?>
                                            <span class="text-muted"><?= $i+1 ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold"><?= htmlspecialchars($c['nombre']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark"><?= $c['viajes'] ?></span>
                                    </td>
                                    <td class="text-center text-success fw-semibold">
                                        $<?= number_format($c['ingresos'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="stars">
                                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                                <i class="fa<?= $s <= round($c['calificacion']) ? 's' : 'r' ?> fa-star"></i>
                                            <?php endfor; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Viajes recientes -->
            <div class="col-md-7">
                <div class="table-card h-100">
                    <div class="card-header-custom d-flex justify-content-between align-items-center">
                        <h6><i class="fas fa-clock text-info me-2"></i>Viajes Recientes</h6>
                        <a href="viajes.php" class="btn btn-sm btn-outline-primary">Ver todos</a>
                    </div>
                    <?php if (empty($viajesRecientes)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-route fa-2x mb-2 opacity-25"></i>
                        <p class="small mb-0">No hay viajes registrados aún.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Ruta</th>
                                    <th>Pasajero</th>
                                    <th class="text-center">Tarifa</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center">Hora</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viajesRecientes as $v): ?>
                                <tr>
                                    <td>
                                        <div class="small fw-semibold text-truncate" style="max-width:160px"
                                             title="<?= htmlspecialchars($v['origen_texto']) ?>">
                                            <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                            <?= htmlspecialchars($v['origen_texto']) ?>
                                        </div>
                                        <div class="small text-muted text-truncate" style="max-width:160px"
                                             title="<?= htmlspecialchars($v['destino_texto']) ?>">
                                            <i class="fas fa-flag-checkered text-success me-1"></i>
                                            <?= htmlspecialchars($v['destino_texto']) ?>
                                        </div>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($v['pasajero'] ?? '—') ?></td>
                                    <td class="text-center fw-semibold text-success">
                                        $<?= number_format($v['tarifa_total'], 2) ?>
                                    </td>
                                    <td class="text-center"><?= estadoBadge($v['estado']) ?></td>
                                    <td class="text-center small text-muted">
                                        <?= date('H:i', strtotime($v['fecha_pedido'])) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /fila 3 -->

    </div><!-- /content -->
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Datos desde PHP ──────────────────────────────────────────
const labeles    = <?= json_encode(array_values($labelesDias)) ?>;
const serieViajes    = <?= json_encode(array_values($serieViajes)) ?>;
const serieIngresos  = <?= json_encode(array_values($serieIngresos)) ?>;
const estadoLabels   = <?= json_encode($estadoLabels) ?>;
const estadoTotales  = <?= json_encode($estadoTotales) ?>;

// ── Colores de estado ────────────────────────────────────────
const colorMap = {
    terminado: '#11998e', cancelado: '#e53935', pedido: '#9b51e0',
    aceptado: '#f46b45', en_camino: '#3182fe', iniciado: '#eea849'
};
const donaColores = estadoLabels.map(e => colorMap[e] || '#ccc');

// ── Gráfico de línea ─────────────────────────────────────────
new Chart(document.getElementById('chartLinea'), {
    type: 'line',
    data: {
        labels: labeles,
        datasets: [
            {
                label: 'Viajes',
                data: serieViajes,
                borderColor: '#6b11ff',
                backgroundColor: 'rgba(107,17,255,0.08)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#6b11ff',
                tension: 0.4,
                fill: true,
                yAxisID: 'y',
            },
            {
                label: 'Ingresos ($)',
                data: serieIngresos,
                borderColor: '#11998e',
                backgroundColor: 'rgba(17,153,142,0.07)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#11998e',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'top', labels: { font: { size: 12 } } } },
        scales: {
            y:  { type: 'linear', position: 'left',  beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f0f0f0' } },
            y1: { type: 'linear', position: 'right', beginAtZero: true, ticks: { callback: v => '$' + v, font: { size: 11 } }, grid: { drawOnChartArea: false } },
            x:  { ticks: { font: { size: 11 } }, grid: { color: '#f5f5f5' } }
        }
    }
});

// ── Gráfico de dona ──────────────────────────────────────────
new Chart(document.getElementById('chartDona'), {
    type: 'doughnut',
    data: {
        labels: estadoLabels,
        datasets: [{
            data: estadoTotales,
            backgroundColor: donaColores,
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { font: { size: 11 }, padding: 12 }
            }
        },
        cutout: '65%'
    }
});
</script>
</body>
</html>

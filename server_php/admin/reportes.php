<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/db_admin.php';

// ── Rango de fechas ──────────────────────────────────────────────────
$rango  = $_GET['rango']  ?? 'mes';
$desde  = $_GET['desde']  ?? '';
$hasta  = $_GET['hasta']  ?? '';
$f_canton = $_GET['canton'] ?? '';
$f_coop   = $_GET['coop_id'] ?? '';
$f_cat    = $_GET['cat_id']   ?? '';

switch ($rango) {
    case 'hoy':
        $fechaDesde = date('Y-m-d');
        $fechaHasta = date('Y-m-d');
        break;
    case 'semana':
        $fechaDesde = date('Y-m-d', strtotime('monday this week'));
        $fechaHasta = date('Y-m-d');
        break;
    case 'mes':
        $fechaDesde = date('Y-m-01');
        $fechaHasta = date('Y-m-d');
        break;
    case 'año':
        $fechaDesde = date('Y-01-01');
        $fechaHasta = date('Y-m-d');
        break;
    case 'personalizado':
        $fechaDesde = $desde ?: date('Y-m-01');
        $fechaHasta = $hasta ?: date('Y-m-d');
        break;
    default:
        $fechaDesde = date('Y-m-01');
        $fechaHasta = date('Y-m-d');
}

$fechaDesdeSQL = $fechaDesde . ' 00:00:00';
$fechaHastaSQL = $fechaHasta . ' 23:59:59';

$whereBase = "WHERE v.fecha_pedido BETWEEN ? AND ?";
$paramsBase = [$fechaDesdeSQL, $fechaHastaSQL];

if ($f_canton !== '') {
    $whereBase .= " AND (SELECT canton FROM conductores WHERE id = v.conductor_id) = ?";
    $paramsBase[] = $f_canton;
}
if ($f_coop !== '') {
    $whereBase .= " AND (SELECT cooperativa_id FROM conductores WHERE id = v.conductor_id) = ?";
    $paramsBase[] = $f_coop;
}
if ($f_cat !== '') {
    $whereBase .= " AND v.categoria_id = ?";
    $paramsBase[] = $f_cat;
}

// ── Totales del período ──────────────────────────────────────────────
$totales = $pdo->prepare("
    SELECT
        COUNT(*) AS total_viajes,
        SUM(CASE WHEN estado='terminado' THEN 1 ELSE 0 END) AS completados,
        SUM(CASE WHEN estado='cancelado' THEN 1 ELSE 0 END) AS cancelados,
        COALESCE(SUM(CASE WHEN estado='terminado' THEN tarifa_total ELSE 0 END), 0) AS ingresos,
        COUNT(DISTINCT conductor_id) AS conductores_activos,
        COUNT(DISTINCT v.usuario_id)   AS pasajeros_activos
    FROM viajes v
    $whereBase
");
$totales->execute($paramsBase);
$t = $totales->fetch();

// ── Nuevos usuarios en el período ────────────────────────────────────
$nuevosUsuarios = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE creado_en BETWEEN ? AND ?");
$nuevosUsuarios->execute([$fechaDesdeSQL, $fechaHastaSQL]);
$nUsuarios = $nuevosUsuarios->fetchColumn();

// ── Viajes e ingresos por día ─────────────────────────────────────────
$porDia = $pdo->prepare("
    SELECT DATE(v.fecha_pedido) AS dia,
           COUNT(*) AS viajes,
           COALESCE(SUM(CASE WHEN v.estado='terminado' THEN v.tarifa_total ELSE 0 END),0) AS ingresos
    FROM viajes v
    $whereBase
    GROUP BY dia
    ORDER BY dia
");
$porDia->execute($paramsBase);
$diasData = $porDia->fetchAll();

$labels    = array_column($diasData, 'dia');
$viajesD   = array_column($diasData, 'viajes');
$ingresosD = array_column($diasData, 'ingresos');

// ── Top 10 conductores ───────────────────────────────────────────────
$topConductores = $pdo->prepare("
    SELECT c.nombre, c.telefono,
           COUNT(*) AS viajes,
           COALESCE(SUM(v.tarifa_total),0) AS total
    FROM viajes v
    JOIN conductores c ON c.id = v.conductor_id
    $whereBase AND v.estado='terminado'
    GROUP BY v.conductor_id
    ORDER BY total DESC
    LIMIT 10
");
$topConductores->execute($paramsBase);
$conductoresTop = $topConductores->fetchAll();

// ── Top 10 pasajeros ─────────────────────────────────────────────────
$topPasajeros = $pdo->prepare("
    SELECT u.nombre, u.telefono,
           COUNT(*) AS viajes,
           COALESCE(SUM(v.tarifa_total),0) AS total
    FROM viajes v
    JOIN usuarios u ON u.id = v.usuario_id
    $whereBase AND v.estado='terminado'
    GROUP BY v.usuario_id
    ORDER BY total DESC
    LIMIT 10
");
$topPasajeros->execute($paramsBase);
$pasajerosTop = $topPasajeros->fetchAll();

// ── Viajes recientes (últimos 50) ────────────────────────────────────
$viajes = $pdo->prepare("
    SELECT v.id, v.origen_texto, v.destino_texto,
           v.tarifa_total, v.estado, v.fecha_pedido,
           u.nombre AS pasajero, u.telefono AS tel_pasajero,
           c.nombre AS conductor
    FROM viajes v
    LEFT JOIN usuarios    u ON u.id = v.usuario_id
    LEFT JOIN conductores c ON c.id = v.conductor_id
    $whereBase
    ORDER BY v.fecha_pedido DESC
    LIMIT 50
");
$viajes->execute($paramsBase);
$viajesLista = $viajes->fetchAll();

// ── Viajes por estado (donut) ────────────────────────────────────────
$estados = $pdo->prepare("
    SELECT v.estado, COUNT(*) AS total
    FROM viajes v
    $whereBase
    GROUP BY v.estado
");
$estados->execute($paramsBase);
$estadosData   = $estados->fetchAll();
$estadosLabels = array_column($estadosData, 'estado');
$estadosCounts = array_column($estadosData, 'total');

// ── Comisión empresa ─────────────────────────────────────────────────
$cfg = $pdo->query("SELECT valor FROM configuracion WHERE clave='comision_empresa'")->fetch();
$comision = floatval($cfg['valor'] ?? 20);
$ingresoEmpresa = $t['ingresos'] * ($comision / 100);

$currentPage = 'reportes';
try {
    $totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado=0")->fetchColumn();
} catch (Exception $e) {
    $totalPendientes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reportes – Fuber Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="admin.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
* { box-sizing: border-box; }
.stat-card { border-radius:16px; padding:22px; color:#fff; transition:.2s; }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 20px rgba(0,0,0,.15); }
.stat-icon { font-size:2rem; opacity:.7; }
.btn-rango { border-radius:20px; padding:6px 16px; font-size:13px; }
.btn-rango.active { background:#6b11ff; color:#fff; border-color:#6b11ff; }
.badge-estado { padding:4px 10px; border-radius:10px; font-size:12px; font-weight:600; }
.badge-terminado  { background:#d1f5e0; color:#1a7a45; }
.badge-cancelado  { background:#fde8e8; color:#c0392b; }
.badge-en_camino  { background:#dceeff; color:#1a5fa8; }
.badge-iniciado   { background:#fff3cd; color:#856404; }
.badge-pedido     { background:#f0f0f0; color:#555; }
.badge-aceptado   { background:#e8f4fd; color:#1a5fa8; }
@media print {
    .sidebar, .no-print { display:none !important; }
    .col-md-2 { display:none; }
    .col-md-10 { width:100%; max-width:100%; flex:0 0 100%; }
    .content { padding:0; }
}
</style>
</head>
<body>
<div class="container-fluid">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- Contenido -->
    <div class="col-md-10 content">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h4 class="fw-bold mb-0"><i class="fas fa-chart-bar me-2 text-primary"></i>Reportes y Estadísticas</h4>
                <small class="text-muted">Período: <?= date('d/m/Y', strtotime($fechaDesde)) ?> – <?= date('d/m/Y', strtotime($fechaHasta)) ?></small>
            </div>
            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-print me-1"></i> Imprimir / PDF
                </button>
                <a href="exportar.php?tipo=viajes&desde=<?= $fechaDesde ?>&hasta=<?= $fechaHasta ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel me-1"></i> Exportar Viajes
                </a>
                <a href="exportar.php?tipo=conductores&desde=<?= $fechaDesde ?>&hasta=<?= $fechaHasta ?>" class="btn btn-info btn-sm text-white">
                    <i class="fas fa-file-excel me-1"></i> Exportar Conductores
                </a>
            </div>
        </div>

        <!-- Filtros -->
        <div class="card border-0 shadow-sm mb-4 no-print">
            <div class="card-body py-3">
                <form method="GET">
                    <div class="row g-2 align-items-center">
                        <div class="col-auto">
                            <span class="fw-semibold small"><i class="fas fa-calendar me-1 text-muted"></i>Período:</span>
                            <?php 
                            $rangos = ['hoy'=>'Hoy','semana'=>'Semana','mes'=>'Mes','anio'=>'Año'];
                            foreach($rangos as $k=>$v): 
                                $active = ($rango==$k) ? 'active' : '';
                            ?>
                                <a href="?rango=<?= $k ?>&canton=<?= $f_canton ?>&coop_id=<?= $f_coop ?>" class="btn btn-outline-secondary btn-rango <?= $active ?>" style="padding:4px 10px; font-size:12px;"><?= $v ?></a>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-auto d-flex align-items-center gap-1 ms-2">
                            <input type="hidden" name="rango" value="personalizado">
                            <input type="date" name="desde" value="<?= $fechaDesde ?>" class="form-control form-control-sm" style="width:135px">
                            <span>–</span>
                            <input type="date" name="hasta" value="<?= $fechaHasta ?>" class="form-control form-control-sm" style="width:135px">
                        </div>
                        <div class="col-auto">
                           <select name="canton" class="form-select form-select-sm" style="width:140px">
                               <option value="">Cantón: Todos</option>
                               <?php
                               $cantones = $pdo->query("SELECT DISTINCT canton FROM conductores WHERE canton IS NOT NULL")->fetchAll();
                               foreach($cantones as $c) {
                                   $sel = ($f_canton == $c['canton']) ? 'selected' : '';
                                   echo "<option value='{$c['canton']}' $sel>{$c['canton']}</option>";
                               }
                               ?>
                           </select>
                        </div>
                        <div class="col-auto">
                           <select name="coop_id" class="form-select form-select-sm" style="width:150px">
                               <option value="">Coop: Todas</option>
                               <?php
                               $coops = $pdo->query("SELECT id, nombre FROM cooperativas")->fetchAll();
                               foreach($coops as $co) {
                                   $sel = ($f_coop == $co['id']) ? 'selected' : '';
                                   echo "<option value='{$co['id']}' $sel>{$co['nombre']}</option>";
                               }
                               ?>
                           </select>
                        </div>
                        <div class="col-auto">
                           <select name="cat_id" class="form-select form-select-sm" style="width:140px">
                               <option value="">Categoría: Todas</option>
                               <?php
                               $cats = $pdo->query("SELECT id, nombre FROM categorias")->fetchAll();
                               foreach($cats as $ca) {
                                   $sel = ($f_cat == $ca['id']) ? 'selected' : '';
                                   echo "<option value='{$ca['id']}' $sel>{$ca['nombre']}</option>";
                               }
                               ?>
                           </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm px-3">Aplicar</button>
                            <a href="reportes.php" class="btn btn-outline-secondary btn-sm px-3 ms-1">Limpiar</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tarjetas -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-6">
                <div class="stat-card" style="background:linear-gradient(135deg,#6b11ff,#9b5de5)">
                    <div class="stat-icon mb-2"><i class="fas fa-route"></i></div>
                    <div class="fs-3 fw-bold"><?= number_format($t['total_viajes']) ?></div>
                    <div class="small opacity-75">Total Viajes</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card" style="background:linear-gradient(135deg,#11998e,#38ef7d)">
                    <div class="stat-icon mb-2"><i class="fas fa-check-circle"></i></div>
                    <div class="fs-3 fw-bold"><?= number_format($t['completados']) ?></div>
                    <div class="small opacity-75">Terminados</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card" style="background:linear-gradient(135deg,#f7971e,#ffd200)">
                    <div class="stat-icon mb-2"><i class="fas fa-dollar-sign"></i></div>
                    <div class="fs-3 fw-bold">$<?= number_format($t['ingresos'],2) ?></div>
                    <div class="small opacity-75">Ingresos Totales</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card" style="background:linear-gradient(135deg,#1a73e8,#4fc3f7)">
                    <div class="stat-icon mb-2"><i class="fas fa-building"></i></div>
                    <div class="fs-3 fw-bold">$<?= number_format($ingresoEmpresa,2) ?></div>
                    <div class="small opacity-75">Comisión (<?= $comision ?>%)</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card" style="background:linear-gradient(135deg,#c471ed,#f64f59)">
                    <div class="stat-icon mb-2"><i class="fas fa-car"></i></div>
                    <div class="fs-3 fw-bold"><?= number_format($t['conductores_activos']) ?></div>
                    <div class="small opacity-75">Conductores</div>
                </div>
            </div>
            <div class="col-md-2 col-6">
                <div class="stat-card" style="background:linear-gradient(135deg,#0f2027,#2c5364)">
                    <div class="stat-icon mb-2"><i class="fas fa-users"></i></div>
                    <div class="fs-3 fw-bold"><?= number_format($nUsuarios) ?></div>
                    <div class="small opacity-75">Nuevos Usuarios</div>
                </div>
            </div>
        </div>

        <!-- Gráficas -->
        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Viajes e Ingresos por Día</h6>
                        <canvas id="lineChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3"><i class="fas fa-chart-pie me-2 text-warning"></i>Viajes por Estado</h6>
                        <canvas id="donutChart" height="180"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Conductores y Pasajeros -->
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Top 10 Conductores</h6>
                            <a href="exportar.php?tipo=conductores&desde=<?= $fechaDesde ?>&hasta=<?= $fechaHasta ?>" class="btn btn-outline-success btn-sm no-print">
                                <i class="fas fa-download me-1"></i>Excel
                            </a>
                        </div>
                        <table class="table table-sm table-hover">
                            <thead class="table-dark">
                                <tr><th>#</th><th>Conductor</th><th class="text-center">Viajes</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($conductoresTop as $i => $c): ?>
                            <tr>
                                <td><?php echo $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1)); ?></td>
                                <td><?= htmlspecialchars($c['nombre']) ?></td>
                                <td class="text-center"><?= $c['viajes'] ?></td>
                                <td class="text-end fw-semibold text-success">$<?= number_format($c['total'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($conductoresTop)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Sin datos en este período</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0"><i class="fas fa-user-check me-2 text-info"></i>Top 10 Pasajeros</h6>
                            <a href="exportar.php?tipo=pasajeros&desde=<?= $fechaDesde ?>&hasta=<?= $fechaHasta ?>" class="btn btn-outline-info btn-sm no-print">
                                <i class="fas fa-download me-1"></i>Excel
                            </a>
                        </div>
                        <table class="table table-sm table-hover">
                            <thead class="table-dark">
                                <tr><th>#</th><th>Pasajero</th><th class="text-center">Viajes</th><th class="text-end">Total</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pasajerosTop as $i => $p): ?>
                            <tr>
                                <td><?= $i+1 ?></td>
                                <td><?= htmlspecialchars($p['nombre']) ?><br><small class="text-muted"><?= $p['telefono'] ?></small></td>
                                <td class="text-center"><?= $p['viajes'] ?></td>
                                <td class="text-end fw-semibold text-success">$<?= number_format($p['total'],2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($pasajerosTop)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Sin datos en este período</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabla detalle viajes -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0"><i class="fas fa-list me-2 text-secondary"></i>Detalle de Viajes
                        <span class="badge bg-secondary ms-1"><?= count($viajesLista) ?></span>
                    </h6>
                    <a href="exportar.php?tipo=viajes&desde=<?= $fechaDesde ?>&hasta=<?= $fechaHasta ?>" class="btn btn-success btn-sm no-print">
                        <i class="fas fa-file-excel me-1"></i> Exportar Excel
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-dark">
                            <tr><th>Fecha</th><th>Pasajero</th><th>Conductor</th><th>Origen</th><th>Destino</th><th class="text-end">Tarifa</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($viajesLista as $v): ?>
                        <tr>
                            <td><small><?= date('d/m/y H:i', strtotime($v['fecha_pedido'])) ?></small></td>
                            <td><?= htmlspecialchars($v['pasajero'] ?? '–') ?></td>
                            <td><?= htmlspecialchars($v['conductor'] ?? '–') ?></td>
                            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <small title="<?= htmlspecialchars($v['origen_texto']??'') ?>"><?= htmlspecialchars(substr($v['origen_texto']??'–',0,28)) ?></small>
                            </td>
                            <td style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <small title="<?= htmlspecialchars($v['destino_texto']??'') ?>"><?= htmlspecialchars(substr($v['destino_texto']??'–',0,28)) ?></small>
                            </td>
                            <td class="text-end fw-semibold">$<?= number_format($v['tarifa_total']??0,2) ?></td>
                            <td><span class="badge-estado badge-<?= $v['estado'] ?>"><?= ucfirst(str_replace('_',' ',$v['estado'])) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($viajesLista)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No hay viajes en este período</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($viajesLista)==50): ?>
                <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle me-1"></i>Mostrando los últimos 50 viajes. Exporta a Excel para ver todos.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<script>
const labels    = <?= json_encode($labels) ?>;
const viajesD   = <?= json_encode(array_map('intval',   $viajesD)) ?>;
const ingresosD = <?= json_encode(array_map('floatval', $ingresosD)) ?>;

new Chart(document.getElementById('lineChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            { label:'Viajes', data:viajesD, backgroundColor:'rgba(107,17,255,0.7)', borderRadius:5, yAxisID:'y' },
            { label:'Ingresos ($)', data:ingresosD, type:'line', borderColor:'#f7971e', backgroundColor:'rgba(247,151,30,0.1)', tension:0.4, fill:true, yAxisID:'y2', pointRadius:4 }
        ]
    },
    options: {
        responsive:true,
        interaction:{ mode:'index', intersect:false },
        plugins:{ legend:{ position:'bottom' } },
        scales:{
            y:  { beginAtZero:true, grid:{ color:'#f0f0f0' }, ticks:{ precision:0 } },
            y2: { beginAtZero:true, position:'right', grid:{ display:false }, ticks:{ callback: v=>'$'+v.toFixed(0) } }
        }
    }
});

const estLabels = <?= json_encode($estadosLabels) ?>;
const estCounts = <?= json_encode(array_map('intval', $estadosCounts)) ?>;
const colorMap  = { terminado:'#28a745', cancelado:'#dc3545', en_camino:'#1a73e8', iniciado:'#ffc107', pedido:'#6c757d', aceptado:'#17a2b8' };
new Chart(document.getElementById('donutChart'), {
    type:'doughnut',
    data:{
        labels: estLabels.map(l=>l.charAt(0).toUpperCase()+l.slice(1).replace('_',' ')),
        datasets:[{ data:estCounts, backgroundColor:estLabels.map(l=>colorMap[l]||'#aaa'), borderWidth:2 }]
    },
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12 } } }, cutout:'65%' }
});
</script>
</body>
</html>

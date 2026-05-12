<?php
// ============================================================
// admin/kpis.php — Reportes de KPIs con Gráficas (Supervisor)
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php';   // PDO ($pdo)

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_usuario_id = $_SESSION['supervisor_id'];
$supervisor_nombre     = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol        = $_SESSION['supervisor_rol'] ?? 'Supervisor';

// ── Resolver supervisor.id real ──────────────────────────────
$supervisor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$supervisor_usuario_id]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

// ── Rango de fechas (Mes Actual por defecto) ──────────────────
// ── Filtros de Tiempo ────────────────────────────────────────
$mes_actual  = $_GET['mes']  ?? date('m');
$anio_actual = $_GET['anio'] ?? date('Y');

$fecha_inicio_mes = "$anio_actual-" . str_pad($mes_actual, 2, '0', STR_PAD_LEFT) . "-01";
$fecha_fin_mes    = date('Y-m-t', strtotime($fecha_inicio_mes));

// Para la gráfica: si el mes es el actual, mostrar hasta hoy. Si es un mes pasado, mostrar todo el mes.
$dia_max_grafica = ($mes_actual == date('m') && $anio_actual == date('Y')) ? (int)date('d') : (int)date('t', strtotime($fecha_inicio_mes));

// ── Asesores del equipo ──────────────────────────────────────
$asesores = [];
if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a 
                         JOIN usuario u ON u.id = a.usuario_id 
                         WHERE a.supervisor_id = ? AND u.activo = 1');
    $st->execute([$supervisor_table_id]);
    $asesores = $st->fetchAll();
}

$asesor_filtro = $_GET['asesor_id'] ?? '';
$asesor_ids_equipo = array_map(fn($a) => (string)$a['id'], $asesores);

// ── Lógica de Cálculo de KPIs (Prospección) ──────────────────
$kpi_prospeccion = [
    'numero' => 0,
    'meta' => 0,
    'cumplimiento' => 0,
    'promedio_dia' => 0,
    'media_equipo' => 0,
    'comparativa' => 'IGUAL'
];

if ($supervisor_table_id && !empty($asesor_ids_equipo)) {
    // 1. Total Prospectos del Equipo (Media Equipo)
    $ph = implode(',', array_fill(0, count($asesor_ids_equipo), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM cliente_prospecto 
                         WHERE asesor_id IN ($ph) AND DATE(created_at) BETWEEN ? AND ?");
    $st->execute(array_merge($asesor_ids_equipo, [$fecha_inicio_mes, $fecha_fin_mes]));
    $total_equipo = (int)$st->fetchColumn();
    $kpi_prospeccion['media_equipo'] = round($total_equipo / count($asesor_ids_equipo), 2);

    // 2. Datos del Asesor Específico (o promedio si no hay filtro)
    $target_asesor_id = $asesor_filtro ?: null;
    
    if ($target_asesor_id) {
        // Número real
        $st = $pdo->prepare("SELECT COUNT(*) FROM cliente_prospecto 
                             WHERE asesor_id = ? AND DATE(created_at) BETWEEN ? AND ?");
        $st->execute([$target_asesor_id, $fecha_inicio_mes, $fecha_fin_mes]);
        $kpi_prospeccion['numero'] = (int)$st->fetchColumn();

        // Meta
        $st = $pdo->prepare("SELECT SUM(meta_clientes_nuevos) FROM meta_asesor_diaria 
                             WHERE asesor_id = ? AND fecha BETWEEN ? AND ?");
        $st->execute([$target_asesor_id, $fecha_inicio_mes, $fecha_fin_mes]);
        $kpi_prospeccion['meta'] = (int)$st->fetchColumn() ?: 100; // 100 como default si no hay meta
    } else {
        $kpi_prospeccion['numero'] = $total_equipo;
        $kpi_prospeccion['meta'] = count($asesor_ids_equipo) * 100; 
    }

    // Cálculos finales
    if ($kpi_prospeccion['meta'] > 0) {
        $kpi_prospeccion['cumplimiento'] = round(($kpi_prospeccion['numero'] / $kpi_prospeccion['meta']) * 100);
    }
    
    $dias_pasados = (int)date('d');
    $kpi_prospeccion['promedio_dia'] = round($kpi_prospeccion['numero'] / $dias_pasados, 2);

    // Comparativa
    $diff = $kpi_prospeccion['numero'] - $kpi_prospeccion['media_equipo'];
    if ($diff > 0.5) $kpi_prospeccion['comparativa'] = 'SUPERIOR';
    elseif ($diff < -0.5) $kpi_prospeccion['comparativa'] = 'INFERIOR';
    else $kpi_prospeccion['comparativa'] = 'IGUAL';
}

// ── Datos para Gráfica (Prospectos por día) ──────────────────
$grafica_labels = [];
$grafica_data = [];
if ($supervisor_table_id) {
    $target_ids = $asesor_filtro ? [$asesor_filtro] : $asesor_ids_equipo;
    if (!empty($target_ids)) {
        $ph = implode(',', array_fill(0, count($target_ids), '?'));
        $st = $pdo->prepare("SELECT DATE(created_at) as dia, COUNT(*) as cant 
                             FROM cliente_prospecto 
                             WHERE asesor_id IN ($ph) AND DATE(created_at) BETWEEN ? AND ?
                             GROUP BY dia ORDER BY dia ASC");
        $st->execute(array_merge($target_ids, [$fecha_inicio_mes, $fecha_fin_mes]));
        $raw_grafica = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        
        for ($i = 1; $i <= $dia_max_grafica; $i++) {
            $d = "$anio_actual-" . str_pad($mes_actual, 2, '0', STR_PAD_LEFT) . "-" . str_pad($i, 2, '0', STR_PAD_LEFT);
            $grafica_labels[] = date('d M', strtotime($d));
            $grafica_data[] = $raw_grafica[$d] ?? 0;
        }
    }
}

$currentPage = 'reportes';
$navTitle = 'Reportes KPIs';
$navIcon = 'fas fa-chart-line';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes KPIs — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .kpi-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: var(--brand-shadow); }
        .kpi-table th { background: #f8fafc; padding: 12px 20px; font-size: 12px; color: var(--brand-gray); text-transform: uppercase; text-align: left; border-bottom: 2px solid #e2e8f0; }
        .kpi-table td { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        .kpi-header-blue { background: #dbeafe; color: #1e40af; font-weight: 800; text-align: center; padding: 10px; border-radius: 8px 8px 0 0; }
        .comp-superior { color: #059669; font-weight: 800; }
        .comp-inferior { color: #dc2626; font-weight: 800; }
        .comp-igual { color: #d97706; font-weight: 800; }
        .chart-container { background: #fff; border-radius: 16px; padding: 25px; box-shadow: var(--brand-shadow); border: 1px solid var(--brand-border); }
    </style>
</head>
<body>

<?php require_once '_sidebar_supervisor.php'; ?>

<div class="container-fluid p-4">
    <!-- FILTROS -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="section-card p-3 d-flex align-items-center justify-content-between">
                <h4 class="m-0 fw-800 text-navy"><i class="fas fa-filter me-2 text-primary"></i>Filtros de Reporte</h4>
                <form method="get" class="d-flex gap-2 align-items-end">
                    <div>
                        <label class="small fw-bold text-muted mb-1">Mes</label>
                        <select name="mes" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php 
                            $meses_nombres = [1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'];
                            foreach ($meses_nombres as $num => $nom): ?>
                                <option value="<?= $num ?>" <?= $mes_actual == $num ? 'selected' : '' ?>><?= $nom ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small fw-bold text-muted mb-1">Año</label>
                        <select name="anio" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php for ($y = date('Y'); $y >= 2024; $y--): ?>
                                <option value="<?= $y ?>" <?= $anio_actual == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="small fw-bold text-muted mb-1">Seleccionar Asesor</label>
                        <select name="asesor_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">— Todo el Equipo —</option>
                            <?php foreach ($asesores as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $asesor_filtro == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i></button>
                </form>
            </div>
        </div>
    </div>

    <!-- KPI CHART UNIFICADO -->
    <div class="row justify-content-center">
        <div class="col-lg-11">
            <div class="section-card p-0 overflow-hidden" style="min-height: 550px;">
                <div class="d-flex align-items-center justify-content-between px-4 py-3 border-bottom bg-light">
                    <h5 class="m-0 fw-800 text-navy">
                        <i class="fas fa-chart-line me-2 text-primary"></i> Evolución de Prospección — <?= $meses_nombres[(int)$mes_actual] ?> <?= $anio_actual ?>
                    </h5>
                    <!-- Opciones de Agrupación (Visual solamente por ahora, o implementar lógica) -->
                    <div class="btn-group btn-group-sm shadow-sm">
                        <button type="button" class="btn btn-white border fw-bold active">Día</button>
                        <button type="button" class="btn btn-white border fw-bold disabled" title="Próximamente">Semana</button>
                        <button type="button" class="btn btn-white border fw-bold disabled" title="Próximamente">Mes</button>
                    </div>
                </div>

                <div class="p-4 bg-white">
                    <div style="height: 450px; width: 100%;">
                        <canvas id="prospeccionChart"></canvas>
                    </div>
                </div>
                
                <!-- MINI ESTADÍSTICAS INFERIORES -->
                <div class="row g-0 border-top bg-light">
                    <div class="col-md-3 p-3 border-end text-center">
                        <div class="small text-muted mb-1">TOTAL PERIODO</div>
                        <div class="fs-4 fw-800 text-navy"><?= $kpi_prospeccion['numero'] ?></div>
                    </div>
                    <div class="col-md-3 p-3 border-end text-center">
                        <div class="small text-muted mb-1">PROMEDIO DIARIO</div>
                        <div class="fs-4 fw-800 text-primary"><?= $kpi_prospeccion['promedio_dia'] ?></div>
                    </div>
                    <div class="col-md-3 p-3 border-end text-center">
                        <div class="small text-muted mb-1">CUMPLIMIENTO META</div>
                        <div class="fs-4 fw-800 text-success"><?= $kpi_prospeccion['cumplimiento'] ?>%</div>
                    </div>
                    <div class="col-md-3 p-3 text-center">
                        <div class="small text-muted mb-1">VS MEDIA EQUIPO</div>
                        <div class="fs-4 fw-800 <?= 'comp-' . strtolower($kpi_prospeccion['comparativa']) ?>"><?= $kpi_prospeccion['comparativa'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const ctx = document.getElementById('prospeccionChart').getContext('2d');
window.myChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($grafica_labels) ?>,
        datasets: [{
            label: 'Prospectos registrados',
            data: <?= json_encode($grafica_data) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            fill: true,
            tension: 0.35,
            borderWidth: 5,
            pointBackgroundColor: '#fff',
            pointBorderColor: '#3b82f6',
            pointRadius: 6,
            pointHoverRadius: 9,
            pointBorderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1e293b',
                padding: 15,
                titleFont: { size: 15, weight: 'bold' },
                bodyFont: { size: 14 },
                cornerRadius: 10,
                displayColors: false
            }
        },
        scales: {
            y: { 
                beginAtZero: true, 
                grid: { color: '#f1f5f9', drawBorder: false }, 
                ticks: { precision: 0, font: { size: 12, weight: 'bold' }, color: '#64748b' } 
            },
            x: { 
                grid: { display: false },
                ticks: { font: { size: 11, weight: 'bold' }, color: '#64748b' }
            }
        }
    }
});
</script>

</body>
</html>

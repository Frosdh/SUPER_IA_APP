<?php
/**
 * metas_asesor.php — Panel de Metas y Seguimiento para el Asesor (Web)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id']; // usuario.id
$asesor_nombre     = $_SESSION['asesor_nombre'] ?? 'Asesor';

// 1. Resolver asesor.id real
$asesor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$asesor_usuario_id]);
    $asesor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

if (!$asesor_table_id) {
    die("Error: No se encontró el registro de asesor vinculado a este usuario.");
}

$hoy = date('Y-m-d');
$fecha_filtro = $_GET['fecha'] ?? $hoy;

// 2. Cargar Meta del día seleccionado
$meta = null;
$avance = [
    'encuestas' => 0, 'clientes' => 0, 'creditos' => 0,
    'c_ahorro' => 0, 'c_corriente' => 0, 'inversiones' => 0,
    'visitas' => 0
];

try {
    // Intentar con la vista v_meta_asesor_avance
    $sql = "SELECT m.*, 
                   v.avance_encuestas, v.avance_clientes_nuevos, v.avance_creditos,
                   v.avance_cuenta_ahorros, v.avance_cuenta_corriente, v.avance_inversiones,
                   v.avance_visitas
            FROM meta_asesor_diaria m
            LEFT JOIN v_meta_asesor_avance v ON v.meta_id = m.id
            WHERE m.asesor_id = ? AND m.fecha = ? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$asesor_table_id, $fecha_filtro]);
    $meta = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($meta) {
        $avance['encuestas']   = (int)($meta['avance_encuestas'] ?? 0);
        $avance['clientes']    = (int)($meta['avance_clientes_nuevos'] ?? 0);
        $avance['creditos']    = (int)($meta['avance_creditos'] ?? 0);
        $avance['c_ahorro']    = (int)($meta['avance_cuenta_ahorros'] ?? 0);
        $avance['c_corriente'] = (int)($meta['avance_cuenta_corriente'] ?? 0);
        $avance['inversiones'] = (int)($meta['avance_inversiones'] ?? 0);
        $avance['visitas']     = (int)($meta['avance_visitas'] ?? 0);
    }
} catch (PDOException $e) {
    // Fallback si la vista no existe
    try {
        $st = $pdo->prepare("SELECT * FROM meta_asesor_diaria WHERE asesor_id = ? AND fecha = ? LIMIT 1");
        $st->execute([$asesor_table_id, $fecha_filtro]);
        $meta = $st->fetch(PDO::FETCH_ASSOC);
        
        // Aquí se podrían inyectar conteos manuales si fuera necesario, 
        // pero asesor_index.php ya tiene algunos KPIs. Para metas_asesor.php 
        // lo ideal es que la vista esté instalada.
    } catch (PDOException $e2) {}
}

// 3. Cargar Tareas del rango seleccionado (o del día del filtro)
$tareas_desde = $_GET['t_desde'] ?? $fecha_filtro;
$tareas_hasta = $_GET['t_hasta'] ?? $fecha_filtro;

$tareas_completadas = [];
$tareas_incompletas = [];
$tareas_programadas = [];

try {
    // Programadas
    $stP = $pdo->prepare("
        SELECT t.*, cp.nombre AS cliente_nombre 
        FROM tarea t 
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE t.asesor_id = ? 
          AND t.fecha_programada BETWEEN ? AND ?
          AND t.estado NOT IN ('completada','cancelada')
        ORDER BY t.fecha_programada ASC, t.hora_programada ASC
    ");
    $stP->execute([$asesor_table_id, $tareas_desde, $tareas_hasta]);
    $tareas_programadas = $stP->fetchAll();

    // Completadas
    $stC = $pdo->prepare("
        SELECT t.*, cp.nombre AS cliente_nombre 
        FROM tarea t 
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE t.asesor_id = ? 
          AND t.fecha_realizada BETWEEN ? AND ?
          AND t.estado = 'completada'
        ORDER BY t.fecha_realizada DESC, t.hora_realizada DESC
    ");
    $stC->execute([$asesor_table_id, $tareas_desde, $tareas_hasta]);
    $tareas_completadas = $stC->fetchAll();

} catch (PDOException $e) {}

$currentPage = 'metas';

function get_tipo_label($tipo) {
    $map = [
        'nueva_cita_campo' => 'Cita Campo',
        'nueva_cita_oficina' => 'Cita Oficina',
        'levantamiento' => 'Levantamiento',
        'documentos_pendientes' => 'Documentos'
    ];
    return $map[$tipo] ?? ucfirst(str_replace('_',' ',(string)$tipo));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Metas — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
    <style>
        .meta-progress-card {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--brand-shadow);
            border: 1px solid var(--brand-border);
            height: 100%;
            transition: transform 0.3s ease;
        }
        .meta-progress-card:hover { transform: translateY(-5px); }
        .meta-icon-box {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
        }
        .progress-label { font-size: 13px; font-weight: 700; color: var(--brand-gray); text-transform: uppercase; letter-spacing: 0.5px; }
        .progress-value { font-size: 24px; font-weight: 800; color: var(--brand-navy-deep); margin: 5px 0; }
        
        .task-list-container {
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid var(--brand-border);
            box-shadow: var(--brand-shadow-sm);
        }
        .task-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f4f8;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .task-item:last-child { border-bottom: none; }
        .task-status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    </style>
</head>
<body>

<?php require_once '_sidebar_asesor.php'; ?>

<div class="main-content">
    <div class="navbar-custom">
        <div class="nav-title-group">
            <h2><i class="fas fa-bullseye me-2" style="color:var(--brand-yellow);"></i> Mis Metas y Objetivos</h2>
            <small class="navbar-subtitle">Seguimiento de desempeño diario</small>
        </div>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($asesor_nombre) ?></strong><br>
                <small>Asesor de campo</small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
        <!-- Filtros -->
        <div class="section-card mb-4">
            <div class="section-body py-3">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted">Consultar metas del día:</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-calendar-alt text-primary"></i></span>
                            <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>" class="form-control border-start-0" onchange="this.form.submit()">
                        </div>
                    </div>
                    <div class="col-md-8 text-md-end">
                        <span class="badge-premium <?= $fecha_filtro === $hoy ? 'badge-success-soft' : 'badge-navy-soft' ?> px-3 py-2">
                            <i class="fas fa-info-circle me-1"></i> 
                            <?= $fecha_filtro === $hoy ? 'Mostrando objetivos de HOY' : 'Consultando histórico: ' . date('d/m/Y', strtotime($fecha_filtro)) ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!$meta): ?>
            <div class="welcome-card mb-4" style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%);">
                <div>
                    <h2>Sin metas asignadas</h2>
                    <p>Tu supervisor aún no ha establecido objetivos específicos para el día <?= date('d/m/Y', strtotime($fecha_filtro)) ?>. ¡Sigue prospectando!</p>
                </div>
                <i class="fas fa-mug-hot fa-3x opacity-25"></i>
            </div>
        <?php else: ?>
            <!-- Grid de Metas -->
            <div class="row g-4 mb-5">
                <?php
                $goal_items = [
                    ['Encuestas', 'fa-poll', 'ki-yellow', $avance['encuestas'], (int)$meta['meta_encuestas']],
                    ['Clientes Nuevos', 'fa-user-plus', 'ki-blue', $avance['clientes'], (int)$meta['meta_clientes_nuevos']],
                    ['Créditos', 'fa-hand-holding-usd', 'ki-green', $avance['creditos'], (int)$meta['meta_creditos']],
                    ['C. Ahorros', 'fa-piggy-bank', 'ki-purple', $avance['c_ahorro'], (int)$meta['meta_cuenta_ahorros']],
                    ['C. Corriente', 'fa-wallet', 'ki-navy', $avance['c_corriente'], (int)$meta['meta_cuenta_corriente']],
                    ['Inversiones', 'fa-chart-line', 'ki-red', $avance['inversiones'], (int)$meta['meta_inversiones']],
                    ['Visitas', 'fa-walking', 'ki-yellow', $avance['visitas'], (int)$meta['meta_visitas']],
                ];
                
                foreach ($goal_items as [$label, $icon, $colorClass, $cur, $target]):
                    if ($target <= 0) continue;
                    $pct = min(100, round($cur * 100 / $target));
                ?>
                <div class="col-md-4">
                    <div class="meta-progress-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="meta-icon-box <?= $colorClass ?> bg-opacity-10">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <span class="badge-premium <?= $cur >= $target ? 'badge-success-soft' : 'badge-warning-soft' ?>">
                                <?= $cur >= $target ? 'Completado' : 'En progreso' ?>
                            </span>
                        </div>
                        <div class="progress-label"><?= $label ?></div>
                        <div class="progress-value"><?= $cur ?> / <?= $target ?></div>
                        
                        <div class="progress-container mt-3" style="height:10px;">
                            <div class="progress-bar-fill" style="width: <?= $pct ?>%; background: <?= $cur >= $target ? 'var(--brand-success)' : 'var(--brand-yellow)' ?>;"></div>
                        </div>
                        <div class="text-end mt-2">
                            <small class="fw-bold text-muted"><?= $pct ?>% logrado</small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($meta['observaciones'])): ?>
                <div class="alert alert-info border-0 shadow-sm mb-5" style="border-radius:15px; background: #eff6ff;">
                    <h6 class="fw-800"><i class="fas fa-comment-dots me-2"></i>Notas del Supervisor:</h6>
                    <p class="mb-0 italic"><?= nl2br(htmlspecialchars($meta['observaciones'])) ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Listado de Tareas -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-calendar-check text-primary"></i> Tareas Realizadas</h5>
                        <span class="badge-premium badge-success-soft"><?= count($tareas_completadas) ?></span>
                    </div>
                    <div class="section-body p-0">
                        <?php if (empty($tareas_completadas)): ?>
                            <div class="p-5 text-center text-muted small">No hay tareas completadas registradas.</div>
                        <?php else: ?>
                            <div class="task-list-container">
                                <?php foreach ($tareas_completadas as $t): ?>
                                <div class="task-item">
                                    <div class="task-status-dot bg-success"></div>
                                    <div style="flex:1;">
                                        <div class="fw-bold text-navy"><?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin nombre') ?></div>
                                        <small class="text-muted"><?= get_tipo_label($t['tipo_tarea']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-800" style="font-size:13px;"><?= date('H:i', strtotime($t['hora_realizada'])) ?></div>
                                        <small class="text-muted" style="font-size:10px;"><?= date('d/m', strtotime($t['fecha_realizada'])) ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="section-card h-100">
                    <div class="section-header">
                        <h5><i class="fas fa-clock text-warning"></i> Pendientes / Programadas</h5>
                        <span class="badge-premium badge-warning-soft"><?= count($tareas_programadas) ?></span>
                    </div>
                    <div class="section-body p-0">
                        <?php if (empty($tareas_programadas)): ?>
                            <div class="p-5 text-center text-muted small">No hay tareas pendientes para este periodo.</div>
                        <?php else: ?>
                            <div class="task-list-container">
                                <?php foreach ($tareas_programadas as $t): ?>
                                <div class="task-item">
                                    <div class="task-status-dot bg-warning"></div>
                                    <div style="flex:1;">
                                        <div class="fw-bold text-navy"><?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin nombre') ?></div>
                                        <small class="text-muted"><?= get_tipo_label($t['tipo_tarea']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-800" style="font-size:13px;"><?= $t['hora_programada'] ?: '--:--' ?></div>
                                        <small class="text-muted" style="font-size:10px;"><?= date('d/m', strtotime($t['fecha_programada'])) ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>

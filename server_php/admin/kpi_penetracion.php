<?php
// ============================================================
// admin/kpi_penetracion.php — Centro de Inteligencia KPI (v13 - Vector Interés Pro)
// ============================================================
if (session_status() === PHP_SESSION_NONE)
    session_start();
date_default_timezone_set('America/Guayaquil');

require_once 'db_admin.php';   // PDO ($pdo)

// Verificar rol (Supervisor o Gerente)
$es_gerente = (isset($_SESSION['gerente_logged_in']) && $_SESSION['gerente_logged_in'] === true);
$es_supervisor = (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true);

if (!$es_gerente && !$es_supervisor) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['gerente_id'] ?? $_SESSION['supervisor_id'];
$user_nombre = $_SESSION['gerente_nombre'] ?? $_SESSION['supervisor_nombre'] ?? 'Usuario';
$user_rol = $_SESSION['rol'] ?? 'Supervisor';

// ── Subdivisiones Principales ───────────────────────────────
$view = $_GET['view'] ?? 'mercado';

// ── Filtros Multitemporales ──────────────────────────────────
$frecuencia = $_GET['frecuencia'] ?? 'mensual';
$anio_actual = $_GET['anio'] ?? date('Y');
$mes_actual = $_GET['mes'] ?? date('m');
$trim_actual = $_GET['trimestre'] ?? ceil(date('m') / 3);
$sem_actual = $_GET['semana'] ?? 1;
$dia_actual = $_GET['dia'] ?? date('Y-m-d');
$asesor_filtro = $_GET['asesor_id'] ?? '';

// ── Cálculo de Rangos de Fecha ──────────────────────────────
$fecha_inicio = "$anio_actual-01-01";
$fecha_fin = "$anio_actual-12-31";

if ($frecuencia === 'diario') {
    $fecha_inicio = $dia_actual;
    $fecha_fin = $dia_actual;
} elseif ($frecuencia === 'mensual') {
    $fecha_inicio = "$anio_actual-" . str_pad($mes_actual, 2, '0', STR_PAD_LEFT) . "-01";
    $fecha_fin = date('Y-m-t', strtotime($fecha_inicio));
} elseif ($frecuencia === 'trimestral') {
    $start_month = ($trim_actual - 1) * 3 + 1;
    $end_month = $trim_actual * 3;
    $fecha_inicio = "$anio_actual-" . str_pad($start_month, 2, '0', STR_PAD_LEFT) . "-01";
    $fecha_fin = date('Y-m-t', strtotime("$anio_actual-" . str_pad($end_month, 2, '0', STR_PAD_LEFT) . "-01"));
} elseif ($frecuencia === 'semanal') {
    $base_mes = "$anio_actual-" . str_pad($mes_actual, 2, '0', STR_PAD_LEFT) . "-01";
    $day_offset = ($sem_actual - 1) * 7;
    $fecha_inicio = date('Y-m-d', strtotime("$base_mes + $day_offset days"));
    $fecha_fin = date('Y-m-d', strtotime("$fecha_inicio + 6 days"));
}

// ── Resolver Asesores ──────────────────────────────────────
$asesores = [];
if ($es_gerente) {
    $st = $pdo->query('SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE u.activo = 1 ORDER BY u.nombre');
} else {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id JOIN supervisor s ON s.id = a.supervisor_id WHERE s.usuario_id = ? AND u.activo = 1 ORDER BY u.nombre');
    $st->execute([$user_id]);
}
$asesores = $st->fetchAll(PDO::FETCH_ASSOC);
$target_ids = $asesor_filtro ? [$asesor_filtro] : array_map(fn($a) => $a['id'], $asesores);
$ph = !empty($target_ids) ? implode(',', array_fill(0, count($target_ids), '?')) : '0';

// ── DATA FETCHING ────────────────────────────────────────────
// ── DATA FETCHING ────────────────────────────────────────────
$data = [
    'mercado' => [
        'cobertura' => ['total' => 0, 'valor' => 0, 'pct' => 0],
        'tipo_cuenta_enc' => ['total' => 0, 'ahorro' => 0, 'ahorro_pct' => 0, 'corriente' => 0, 'corriente_pct' => 0],
        'tipo_cuenta_cli' => ['total' => 0, 'ahorro' => 0, 'ahorro_pct' => 0, 'corriente' => 0, 'corriente_pct' => 0],
        'participacion' => ['total' => 0, 'nosotros' => 0, 'nosotros_pct' => 0, 'competencia' => 0, 'competencia_pct' => 0]
    ],
    'interes' => [
        'general' => ['total' => 0, 'si' => 0, 'no' => 0, 'si_pct' => 0, 'no_pct' => 0],
        'productos' => ['ahorro' => 0, 'credito' => 0, 'inversion' => 0, 'ahorro_pct' => 0, 'credito_pct' => 0, 'inversion_pct' => 0],
        'destinos' => [],
        'destinos_base_si' => 0
    ]
];

if (!empty($target_ids)) {
    $params = array_merge($target_ids, [$fecha_inicio, $fecha_fin]);

    if ($view === 'mercado') {
        // G1: Cobertura
        $sql1 = "SELECT COUNT(t.id) as total_visitas, SUM(CASE WHEN (ec.p2_es_cliente = 1 OR cp.estado = 'cliente') THEN 1 ELSE 0 END) as es_cliente FROM tarea t LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)";
        $st1 = $pdo->prepare($sql1);
        $st1->execute($params);
        $res1 = $st1->fetch(PDO::FETCH_ASSOC);
        $visitas = (int) $res1['total_visitas'];
        $clientes_inst = (int) $res1['es_cliente'];
        $data['mercado']['cobertura'] = ['total' => $visitas, 'valor' => $clientes_inst, 'pct' => ($visitas > 0) ? round(($clientes_inst / $visitas) * 100, 1) : 0];

        // G2: Tipo Cuenta Encuestados
        $sql2 = "SELECT COUNT(ec.id) as total, SUM(CASE WHEN ec.mantiene_cuenta_ahorro = 1 THEN 1 ELSE 0 END) as ahorro, SUM(CASE WHEN ec.mantiene_cuenta_corriente = 1 THEN 1 ELSE 0 END) as corriente FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND ec.id IS NOT NULL";
        $st2 = $pdo->prepare($sql2);
        $st2->execute($params);
        $res2 = $st2->fetch(PDO::FETCH_ASSOC);
        $total_e = (int) $res2['total'];
        $data['mercado']['tipo_cuenta_enc'] = ['total' => $total_e, 'ahorro' => (int) $res2['ahorro'], 'ahorro_pct' => ($total_e > 0) ? round(($res2['ahorro'] / $total_e) * 100, 1) : 0, 'corriente' => (int) $res2['corriente'], 'corriente_pct' => ($total_e > 0) ? round(($res2['corriente'] / $total_e) * 100, 1) : 0];

        // G3: Tipo Cuenta Clientes
        $sql3 = "SELECT COUNT(ec.id) as total, SUM(CASE WHEN ec.mantiene_cuenta_ahorro = 1 THEN 1 ELSE 0 END) as ahorro, SUM(CASE WHEN ec.mantiene_cuenta_corriente = 1 THEN 1 ELSE 0 END) as corriente FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND (cp.estado = 'cliente' OR ec.p2_es_cliente = 1)";
        $st3 = $pdo->prepare($sql3);
        $st3->execute($params);
        $res3 = $st3->fetch(PDO::FETCH_ASSOC);
        $total_c = (int) $res3['total'];
        $data['mercado']['tipo_cuenta_cli'] = ['total' => $total_c, 'ahorro' => (int) $res3['ahorro'], 'ahorro_pct' => ($total_c > 0) ? round(($res3['ahorro'] / $total_c) * 100, 1) : 0, 'corriente' => (int) $res3['corriente'], 'corriente_pct' => ($total_c > 0) ? round(($res3['corriente'] / $total_c) * 100, 1) : 0];

        // G4: Participación
        $sql4 = "SELECT SUM(CASE WHEN (ec.p2_es_cliente = 1 OR cp.estado = 'cliente') THEN 1 ELSE 0 END) as nosotros, SUM(CASE WHEN (ec.tiene_inversiones = 1 OR ec.tiene_operaciones_crediticias = 1) AND (ec.p2_es_cliente = 0 OR ec.p2_es_cliente IS NULL) THEN 1 ELSE 0 END) as competencia, COUNT(t.id) as base FROM tarea t LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)";
        $st4 = $pdo->prepare($sql4);
        $st4->execute($params);
        $res4 = $st4->fetch(PDO::FETCH_ASSOC);
        $base = (int) $res4['base'];
        $data['mercado']['participacion'] = ['total' => $base, 'nosotros' => (int) $res4['nosotros'], 'nosotros_pct' => ($base > 0) ? round(($res4['nosotros'] / $base) * 100, 1) : 0, 'competencia' => (int) $res4['competencia'], 'competencia_pct' => ($base > 0) ? round(($res4['competencia'] / $base) * 100, 1) : 0];
    } else {
        // VISTA INTERÉS
        // 1. Interés General (SI/NO)
        $sqlIG = "SELECT COUNT(*) as total, 
                  SUM(CASE WHEN ec.interes_conocer_productos = 1 THEN 1 ELSE 0 END) as si, 
                  SUM(CASE WHEN ec.interes_conocer_productos = 0 THEN 1 ELSE 0 END) as no 
                  FROM encuesta_comercial ec 
                  JOIN tarea t ON t.id = ec.tarea_id 
                  WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)";
        $stIG = $pdo->prepare($sqlIG);
        $stIG->execute($params);
        $resIG = $stIG->fetch(PDO::FETCH_ASSOC);
        $total_ig = (int) $resIG['total'];

        // Sumar también los "SÍ" implícitos de las fichas de producto (Móvil)
        $hasFicha = $pdo->query("SHOW TABLES LIKE 'ficha_credito'")->fetch();
        $si_fichas = 0;
        if ($hasFicha) {
            $sqlSF = "SELECT COUNT(*) FROM ficha_producto fp WHERE fp.asesor_id IN ($ph) AND (DATE(fp.created_at) BETWEEN ? AND ?) AND fp.producto_tipo = 'credito'";
            $stSF = $pdo->prepare($sqlSF);
            $stSF->execute($params);
            $si_fichas = (int) $stSF->fetchColumn();
        }

        $total_si_global = (int) $resIG['si'] + $si_fichas;
        $total_final = $total_ig + $si_fichas;

        $data['interes']['general'] = [
            'total' => $total_final,
            'si' => $total_si_global,
            'no' => (int) $resIG['no'],
            'si_pct' => ($total_final > 0) ? round(($total_si_global / $total_final) * 100, 1) : 0,
            'no_pct' => ($total_final > 0) ? round(($resIG['no'] / $total_final) * 100, 1) : 0
        ];

        // 2. Por Producto
        $sqlIP = "SELECT SUM(CASE WHEN ec.interes_ahorro=1 THEN 1 ELSE 0 END) as ahorro, 
                         SUM(CASE WHEN ec.interes_credito=1 THEN 1 ELSE 0 END) as credito, 
                         SUM(CASE WHEN ec.interes_inversion=1 THEN 1 ELSE 0 END) as inversion 
                  FROM encuesta_comercial ec 
                  JOIN tarea t ON t.id = ec.tarea_id 
                  WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) 
                  AND ec.interes_conocer_productos = 1";
        $stIP = $pdo->prepare($sqlIP);
        $stIP->execute($params);
        $resIP = $stIP->fetch(PDO::FETCH_ASSOC);

        // El crédito del móvil cuenta como interés en Crédito
        $credito_total = (int) $resIP['credito'] + $si_fichas;

        $data['interes']['productos'] = [
            'ahorro' => (int) $resIP['ahorro'],
            'credito' => $credito_total,
            'inversion' => (int) $resIP['inversion'],
            'ahorro_pct' => ($total_si_global > 0) ? round(($resIP['ahorro'] / $total_si_global) * 100, 1) : 0,
            'credito_pct' => ($total_si_global > 0) ? round(($credito_total / $total_si_global) * 100, 1) : 0,
            'inversion_pct' => ($total_si_global > 0) ? round(($resIP['inversion'] / $total_si_global) * 100, 1) : 0
        ];

        // 3. Destino de Crédito (desde ficha_credito)
        $data['interes']['destinos'] = [];
        $data['interes']['destinos_base_si'] = $total_si_global;

        // Query: obtener todos los destinos
        $sqlDC = "SELECT 
            CASE fc.destino_credito
                WHEN 'cap_trabajo' THEN 'Capital Trabajo'
                WHEN 'capital_trabajo' THEN 'Capital Trabajo'
                WHEN 'activos_fijos' THEN 'Activos Fijos'
                WHEN 'pago_deudas' THEN 'Pago de Deudas'
                WHEN 'consolidacion' THEN 'Consolidación Deudas'
                WHEN 'consolidacion_deudas' THEN 'Consolidación Deudas'
                WHEN 'vehiculo' THEN 'Compra Vehículo'
                WHEN 'compra_vehiculo' THEN 'Compra Vehículo'
                WHEN 'vivienda_comp' THEN 'Compra Vivienda'
                WHEN 'compra_vivienda' THEN 'Compra Vivienda'
                WHEN 'arreglos' THEN 'Reparación Vivienda'
                WHEN 'arreglos_vivienda' THEN 'Reparación Vivienda'
                WHEN 'educacion' THEN 'Educación'
                WHEN 'gastos_educacion' THEN 'Educación'
                WHEN 'viajes' THEN 'Viajes'
                ELSE 'Otros'
            END as label,
            COUNT(*) as cant
        FROM ficha_credito fc
        JOIN ficha_producto fp ON fp.id = fc.ficha_id
        WHERE fc.destino_credito IS NOT NULL 
        AND fc.destino_credito != ''
        AND DATE(fp.created_at) BETWEEN ? AND ?";

        $paramsDC = [$fecha_inicio, $fecha_fin];

        // Filtrar por asesor solo si está específicamente seleccionado
        if ($asesor_filtro) {
            $sqlDC .= " AND fp.asesor_id = ?";
            $paramsDC[] = $asesor_filtro;
        } elseif (!empty($target_ids) && !$es_gerente) {
            // Si es supervisor sin filtro de asesor, mostrar de todos sus asesores
            $ph_supervisados = implode(',', array_fill(0, count($target_ids), '?'));
            $sqlDC .= " AND fp.asesor_id IN ($ph_supervisados)";
            $paramsDC = array_merge($paramsDC, $target_ids);
        }
        // Si es gerente sin filtro, muestra TODOS los datos globales

        $sqlDC .= " GROUP BY fc.destino_credito ORDER BY cant DESC";

        try {
            $stDC = $pdo->prepare($sqlDC);
            $stDC->execute($paramsDC);

            // Inyectar el Total SI como primer registro para referencia 100%
            $data['interes']['destinos'] = ['Total Interesados (SI)' => $total_si_global];
            $data['interes']['destinos_base_si'] = $total_si_global;

            while ($r = $stDC->fetch(PDO::FETCH_ASSOC)) {
                $label = $r['label'];
                if (!isset($data['interes']['destinos'][$label]))
                    $data['interes']['destinos'][$label] = 0;
                $data['interes']['destinos'][$label] += (int) $r['cant'];
            }
        } catch (\Throwable $e) {
            error_log("Error en KPI Destinos: " . $e->getMessage());
        }
    }
}

$currentPage = 'reportes_penetracion';
$navTitle = 'Análisis de Interés y Mercado';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>KPI Dash — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        .kpi-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .kpi-tab {
            padding: 10px 22px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
            border: 1px solid #d1d5db;
            color: #4b5563;
            background: #fff;
        }

        .kpi-tab.active {
            background: #123a6d;
            color: #fff;
            border-color: #123a6d;
        }

        .segment-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.02);
            padding: 22px;
            margin-bottom: 22px;
            border: 1px solid #f1f5f9;
            position: relative;
        }

        .sec-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            border-bottom: 1px solid #f8fafc;
            padding-bottom: 10px;
        }

        .sec-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: #1e293b;
            text-transform: uppercase;
        }

        .filter-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            margin-bottom: 5px;
            text-transform: uppercase;
            display: block;
        }

        .form-select-sm,
        .form-control-sm {
            font-size: 11px !important;
            border-radius: 10px !important;
            height: 32px !important;
        }

        .view-toggle {
            display: flex;
            background: #f8fafc;
            border-radius: 10px;
            padding: 3px;
            border: 1px solid #f1f5f9;
        }

        .view-btn {
            border: none;
            background: transparent;
            padding: 4px 12px;
            border-radius: 7px;
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
        }

        .view-btn.active {
            background: #fff;
            color: #123a6d;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .kpi-table {
            width: 100%;
        }

        .kpi-table th {
            color: #94a3b8;
            font-size: 9px;
            text-transform: uppercase;
            padding: 8px;
        }

        .kpi-table td {
            padding: 12px 8px;
            border-bottom: 1px solid #f8fafc;
            color: #334155;
            font-weight: 700;
            font-size: 12.5px;
        }

        .pct-badge {
            background: #f0f9ff;
            color: #0369a1;
            padding: 3px 8px;
            border-radius: 5px;
            font-weight: 800;
            font-size: 11px;
        }

        .chart-view {
            display: none;
            height: 230px;
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .chart-view.active {
            display: flex;
        }

        .table-view {
            display: none;
        }

        .table-view.active {
            display: block;
        }

        .ia-sidebar {
            background: #0f172a;
            color: #fff;
            border-radius: 20px;
            padding: 22px;
            position: sticky;
            top: 90px;
        }

        .insight-pill {
            background: rgba(255, 255, 255, 0.04);
            padding: 14px;
            border-radius: 14px;
            margin-bottom: 10px;
            border-left: 4px solid #ffdd00;
            font-size: 11.5px;
        }
    </style>
</head>

<body>

    <?php require_once $es_gerente ? '_sidebar.php' : '_sidebar_supervisor.php'; ?>

    <div class="main-content">
        <div class="navbar-custom">
            <div class="nav-title-group">
                <h2><i class="fas fa-brain me-2" style="color:#ffdd00;"></i> Dashboard de Inteligencia</h2>
                <div class="navbar-subtitle">Segmentación y Vectores de Interés (Vectorizado x100)</div>
            </div>
            <div class="user-info text-white">
                <div class="text-end me-3">
                    <div class="fw-bold"><?= htmlspecialchars($user_nombre) ?></div>
                    <small class="opacity-75"><?= $user_rol ?></small>
                </div>
                <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt"></i></a>
            </div>
        </div>

        <div class="content-area">
            <!-- FILTROS -->
            <div class="segment-card mb-4">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="view" value="<?= $view ?>">
                    <div class="col-md-2">
                        <span class="filter-label">Frecuencia</span>
                        <select name="frecuencia" class="form-select form-select-sm shadow-none"
                            onchange="this.form.submit()">
                            <option value="diario" <?= $frecuencia === 'diario' ? 'selected' : '' ?>>DIARIO</option>
                            <option value="semanal" <?= $frecuencia === 'semanal' ? 'selected' : '' ?>>SEMANAL</option>
                            <option value="mensual" <?= $frecuencia === 'mensual' ? 'selected' : '' ?>>MENSUAL</option>
                            <option value="trimestral" <?= $frecuencia === 'trimestral' ? 'selected' : '' ?>>TRIMESTRAL
                            </option>
                            <option value="anual" <?= $frecuencia === 'anual' ? 'selected' : '' ?>>ANUAL</option>
                        </select>
                    </div>
                    <?php if ($frecuencia === 'diario'): ?>
                        <div class="col-md-2"><span class="filter-label">Fecha</span><input type="date" name="dia"
                                class="form-control form-control-sm" value="<?= $dia_actual ?>"
                                onchange="this.form.submit()"></div>
                    <?php else: ?>
                        <div class="col-md-1"><span class="filter-label">Año</span><select name="anio"
                                class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="2025" <?= $anio_actual == '2025' ? 'selected' : '' ?>>2025</option>
                                <option value="2026" <?= $anio_actual == '2026' ? 'selected' : '' ?>>2026</option>
                            </select></div>
                    <?php endif; ?>
                    <?php if (!in_array($frecuencia, ['anual', 'diario'])): ?>
                        <div class="col-md-1"><span class="filter-label">Periodo</span>
                            <?php if ($frecuencia === 'trimestral'): ?>
                                <select name="trimestre" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="1" <?= $trim_actual == 1 ? 'selected' : '' ?>>Q1</option>
                                    <option value="2" <?= $trim_actual == 2 ? 'selected' : '' ?>>Q2</option>
                                    <option value="3" <?= $trim_actual == 3 ? 'selected' : '' ?>>Q3</option>
                                    <option value="4" <?= $trim_actual == 4 ? 'selected' : '' ?>>Q4</option>
                                </select>
                            <?php else: ?>
                                <select name="mes" class="form-select form-select-sm"
                                    onchange="this.form.submit()"><?php foreach ([1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'] as $m => $n): ?>
                                        <option value="<?= $m ?>" <?= $mes_actual == $m ? 'selected' : '' ?>><?= $n ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($frecuencia === 'semanal'): ?>
                        <div class="col-md-1"><span class="filter-label">Semana</span><select name="semana"
                                class="form-select form-select-sm"
                                onchange="this.form.submit()"><?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?= $i ?>" <?= $sem_actual == $i ? 'selected' : '' ?>>W<?= $i ?></option>
                                <?php endfor; ?>
                            </select></div>
                    <?php endif; ?>
                    <div class="col-md-3"><span class="filter-label">Asesor</span><select name="asesor_id"
                            class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">— Consolidado —</option><?php foreach ($asesores as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $asesor_filtro == $a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['nombre']) ?></option><?php endforeach; ?>
                        </select></div>
                </form>
            </div>

            <div class="kpi-tabs">
                <a href="?view=mercado&frecuencia=<?= $frecuencia ?>&anio=<?= $anio_actual ?>&mes=<?= $mes_actual ?>&trimestre=<?= $trim_actual ?>&semana=<?= $sem_actual ?>&dia=<?= $dia_actual ?>&asesor_id=<?= $asesor_filtro ?>"
                    class="kpi-tab <?= $view === 'mercado' ? 'active' : '' ?>">PENETRACIÓN</a>
                <a href="?view=interes&frecuencia=<?= $frecuencia ?>&anio=<?= $anio_actual ?>&mes=<?= $mes_actual ?>&trimestre=<?= $trim_actual ?>&semana=<?= $sem_actual ?>&dia=<?= $dia_actual ?>&asesor_id=<?= $asesor_filtro ?>"
                    class="kpi-tab <?= $view === 'interes' ? 'active' : '' ?>">INTERÉS</a>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <?php if ($view === 'mercado'): ?>
                        <!-- LOS 4 BLOQUES DE PENETRACIÓN (G1-G4) -->
                        <div class="segment-card" id="segment-g1">
                            <div class="sec-header">
                                <div class="sec-title">1. Cobertura Institucional</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('g1', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('g1', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Variable</th>
                                            <th class="text-center">Visitas</th>
                                            <th class="text-end">Cobertura</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Clientes de la Institución</td>
                                            <td class="text-center"><?= $data['mercado']['cobertura']['valor'] ?></td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['mercado']['cobertura']['pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Total Visitas Realizadas</td>
                                            <td class="text-center"><?= $data['mercado']['cobertura']['total'] ?></td>
                                            <td class="text-end">100%</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-g1" style="width: 240px;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-g2">
                            <div class="sec-header">
                                <div class="sec-title">2. Interés Prospectos (Nuevos)</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('g2', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('g2', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Cuenta</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-end">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Interés Ahorro</td>
                                            <td class="text-center"><?= $data['mercado']['tipo_cuenta_enc']['ahorro'] ?>
                                            </td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['mercado']['tipo_cuenta_enc']['ahorro_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Interés Corriente</td>
                                            <td class="text-center"><?= $data['mercado']['tipo_cuenta_enc']['corriente'] ?>
                                            </td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['mercado']['tipo_cuenta_enc']['corriente_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-g2" style="width: 100%;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-g3">
                            <div class="sec-header">
                                <div class="sec-title">3. Tenencia Productos (Clientes)</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('g3', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('g3', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-center">Casos</th>
                                            <th class="text-end">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Ahorro Existente</td>
                                            <td class="text-center"><?= $data['mercado']['tipo_cuenta_cli']['ahorro'] ?>
                                            </td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['mercado']['tipo_cuenta_cli']['ahorro_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Corriente Existente</td>
                                            <td class="text-center"><?= $data['mercado']['tipo_cuenta_cli']['corriente'] ?>
                                            </td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['mercado']['tipo_cuenta_cli']['corriente_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-g3" style="width: 100%;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-g4">
                            <div class="sec-header">
                                <div class="sec-title">4. Cuota de Mercado</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('g4', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('g4', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Institución</th>
                                            <th class="text-center">Cant.</th>
                                            <th class="text-end">Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Nosotros</td>
                                            <td class="text-center"><?= $data['mercado']['participacion']['nosotros'] ?>
                                            </td>
                                            <td class="text-end"><span class="pct-badge"
                                                    style="background:#e0f2fe; color:#0369a1;"><?= $data['mercado']['participacion']['nosotros_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Competencia</td>
                                            <td class="text-center"><?= $data['mercado']['participacion']['competencia'] ?>
                                            </td>
                                            <td class="text-end"><span class="pct-badge"
                                                    style="background:#fee2e2; color:#b91c1c;"><?= $data['mercado']['participacion']['competencia_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-g4" style="width: 100%;"></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- NUEVOS BLOQUES DE INTERÉS (I1-I3) -->
                        <div class="segment-card" id="segment-i1">
                            <div class="sec-header">
                                <div class="sec-title">1. Disposición Comercial (SI/NO)</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('i1', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('i1', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Respuesta</th>
                                            <th class="text-center">Casos</th>
                                            <th class="text-end">% s/Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Interesados en conocer</td>
                                            <td class="text-center"><?= $data['interes']['general']['si'] ?></td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['interes']['general']['si_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Sin Interés actual</td>
                                            <td class="text-center"><?= $data['interes']['general']['no'] ?></td>
                                            <td class="text-end"><span class="pct-badge"
                                                    style="background:#f1f5f9; color:#64748b;"><?= $data['interes']['general']['no_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-i1" style="width: 240px;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-i2">
                            <div class="sec-header">
                                <div class="sec-title">2. Preferencia de Productos</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('i2', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('i2', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Línea</th>
                                            <th class="text-center">Interés</th>
                                            <th class="text-end">% s/SI</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Ahorro</td>
                                            <td class="text-center"><?= $data['interes']['productos']['ahorro'] ?></td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['interes']['productos']['ahorro_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Crédito</td>
                                            <td class="text-center"><?= $data['interes']['productos']['credito'] ?></td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['interes']['productos']['credito_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td>Inversión</td>
                                            <td class="text-center"><?= $data['interes']['productos']['inversion'] ?></td>
                                            <td class="text-end"><span
                                                    class="pct-badge"><?= $data['interes']['productos']['inversion_pct'] ?>%</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-i2" style="width: 100%;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-i3">
                            <div class="sec-header">
                                <div class="sec-title">3. Destino del Crédito (Estratégico)</div>
                                <div class="view-toggle"><button class="view-btn active"
                                        onclick="toggleView('i3', 'table')">TABLA</button><button class="view-btn"
                                        onclick="toggleView('i3', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Uso del Capital</th>
                                            <th class="text-center">Casos</th>
                                            <th class="text-end">% s/SI</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($data['interes']['destinos'])):
                                            foreach ($data['interes']['destinos'] as $label => $val): ?>
                                                <tr>
                                                    <td><?= $label ?></td>
                                                    <td class="text-center"><?= $val ?></td>
                                                    <td class="text-end"><span
                                                            class="pct-badge"><?= $data['interes']['destinos_base_si'] > 0 ? round(($val / $data['interes']['destinos_base_si']) * 100, 1) : 0 ?>%</span>
                                                    </td>
                                                </tr><?php endforeach; else: ?>
                                            <tr>
                                                <td colspan="3" class="text-center opacity-50">Sin datos en el periodo</td>
                                            </tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-view">
                                <div id="chart-i3" style="width: 100%;"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-lg-4">
                    <div class="ia-sidebar shadow-lg">
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <div class="bg-warning rounded-circle p-2"><i class="fas fa-brain text-dark"></i></div>
                            <h6 class="m-0 fw-bold">IA Vector Intelligence</h6>
                        </div>
                        <div class="insights-list">
                            <div class="insight-pill">Frecuencia: <strong><?= strtoupper($frecuencia) ?></strong></div>
                            <div class="insight-pill">Rango: <?= date('d/m', strtotime($fecha_inicio)) ?> al
                                <?= date('d/m', strtotime($fecha_fin)) ?></div>
                            <?php if ($view === 'interes' && $data['interes']['general']['si_pct'] > 50): ?>
                                <div class="insight-pill" style="border-left-color: #10b981;">Oportunidad: Alta receptividad
                                    comercial detectada.</div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleView(id, view) {
            const card = document.getElementById('segment-' + id);
            card.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
            card.querySelector(`.view-btn[onclick*="${view}"]`).classList.add('active');
            card.querySelector('.table-view').classList.toggle('active', view === 'table');
            card.querySelector('.chart-view').classList.toggle('active', view === 'chart');
            if (view === 'chart') window.dispatchEvent(new Event('resize'));
        }
        const commonOpt = { chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' }, dataLabels: { enabled: false }, legend: { position: 'bottom', fontSize: '11px' } };
        const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#0ea5e9', '#f97316', '#84cc16', '#14b8a6'];

        <?php if ($view === 'mercado'): ?>
            try {
                new ApexCharts(document.querySelector("#chart-g1"), { ...commonOpt, series: [<?= (int) ($data['mercado']['cobertura']['valor'] ?? 0) ?>, <?= max(0, (int) ($data['mercado']['cobertura']['total'] ?? 0) - (int) ($data['mercado']['cobertura']['valor'] ?? 0)) ?>], chart: { type: 'donut', height: 230 }, labels: ['Clientes', 'Prospectos'], colors: ['#3b82f6', '#cbd5e1'], plotOptions: { pie: { donut: { size: '75%', labels: { show: true, total: { show: true, label: 'Cobertura', formatter: () => '<?= $data['mercado']['cobertura']['pct'] ?? 0 ?>%' } } } } } }).render();
                new ApexCharts(document.querySelector("#chart-g2"), { ...commonOpt, series: [{ name: 'Interés', data: [<?= (int) ($data['mercado']['tipo_cuenta_enc']['ahorro'] ?? 0) ?>, <?= (int) ($data['mercado']['tipo_cuenta_enc']['corriente'] ?? 0) ?>] }], chart: { type: 'bar', height: 230 }, xaxis: { categories: ['Ahorro', 'Corriente'] }, colors: [palette[0], palette[1]], plotOptions: { bar: { distributed: true, borderRadius: 8 } } }).render();
                new ApexCharts(document.querySelector("#chart-g3"), { ...commonOpt, series: [{ name: 'Tenencia', data: [<?= (int) ($data['mercado']['tipo_cuenta_cli']['ahorro'] ?? 0) ?>, <?= (int) ($data['mercado']['tipo_cuenta_cli']['corriente'] ?? 0) ?>] }], chart: { type: 'bar', height: 230 }, xaxis: { categories: ['Ahorro', 'Corriente'] }, colors: [palette[0], palette[1]], plotOptions: { bar: { distributed: true, borderRadius: 8 } } }).render();
                new ApexCharts(document.querySelector("#chart-g4"), { ...commonOpt, series: [<?= (int) ($data['mercado']['participacion']['nosotros'] ?? 0) ?>, <?= (int) ($data['mercado']['participacion']['competencia'] ?? 0) ?>], chart: { type: 'pie', height: 230 }, labels: ['Nosotros', 'Competencia'], colors: [palette[0], palette[3]] }).render();
            } catch (e) { console.error(e); }
        <?php else: ?>
            try {
                new ApexCharts(document.querySelector("#chart-i1"), { ...commonOpt, series: [<?= (int) ($data['interes']['general']['si'] ?? 0) ?>, <?= (int) ($data['interes']['general']['no'] ?? 0) ?>], chart: { type: 'donut', height: 230 }, labels: ['Interesados (SI)', 'Sin Interés (NO)'], colors: [palette[0], '#cbd5e1'] }).render();
                new ApexCharts(document.querySelector("#chart-i2"), { ...commonOpt, series: [{ name: 'Vectores', data: [<?= (int) ($data['interes']['productos']['ahorro'] ?? 0) ?>, <?= (int) ($data['interes']['productos']['credito'] ?? 0) ?>, <?= (int) ($data['interes']['productos']['inversion'] ?? 0) ?>] }], chart: { type: 'bar', height: 230 }, xaxis: { categories: ['Ahorro', 'Crédito', 'Inversión'] }, colors: [palette[1], palette[2], palette[0]], plotOptions: { bar: { distributed: true, borderRadius: 8 } } }).render();

                const dLabels = <?= json_encode(array_keys($data['interes']['destinos'] ?? [])) ?>;
                const dValues = <?= json_encode(array_values($data['interes']['destinos'] ?? [])) ?>;
                new ApexCharts(document.querySelector("#chart-i3"), { ...commonOpt, series: [{ name: 'Casos', data: dValues }], chart: { type: 'bar', height: 280 }, xaxis: { categories: dLabels }, colors: palette, plotOptions: { bar: { distributed: true, horizontal: true, borderRadius: 6 } } }).render();
            } catch (e) { console.error(e); }
        <?php endif; ?>
    </script>
</body>

</html>
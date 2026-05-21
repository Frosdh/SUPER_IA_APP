<?php
// ============================================================
// admin/kpi_penetracion.php — Centro de Inteligencia KPI (v13 - Vector Interés Pro)
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$vistas_orden = ['actividad', 'mercado', 'interes', 'prospeccion', 'frio', 'evaluacion', 'eficiencia', 'postventa', 'recuperacion', 'operaciones'];
$view = $_GET['view'] ?? 'actividad';
if (!in_array($view, $vistas_orden, true)) {
    $view = 'actividad';
}

// Orden de vistas para navegación secuencial
$idx_actual   = array_search($view, $vistas_orden);
$prev_view    = ($idx_actual > 0) ? $vistas_orden[$idx_actual - 1] : null;
$next_view    = ($idx_actual < count($vistas_orden) - 1) ? $vistas_orden[$idx_actual + 1] : null;

// ── Filtros Multitemporales ──────────────────────────────────
$frecuencia = $_GET['frecuencia'] ?? 'mensual';
$anio_actual = $_GET['anio'] ?? date('Y');
$mes_actual = $_GET['mes'] ?? date('m');
$trim_actual = $_GET['trimestre'] ?? ceil(date('m') / 3);
$sem_actual = $_GET['semana'] ?? 1;
$dia_actual = $_GET['dia'] ?? date('Y-m-d');
$asesor_filtro = $_GET['asesor_id'] ?? '';

// ── Query string para mantener filtros al cambiar de tab ────────
$filtros_query = '&frecuencia=' . urlencode($frecuencia)
    . '&anio=' . urlencode($anio_actual)
    . '&mes=' . urlencode($mes_actual)
    . '&trimestre=' . urlencode($trim_actual)
    . '&semana=' . urlencode($sem_actual)
    . '&dia=' . urlencode($dia_actual)
    . ($asesor_filtro ? '&asesor_id=' . urlencode($asesor_filtro) : '');

$prev_url     = $prev_view ? "?view=$prev_view$filtros_query" : "#";
$next_url     = $next_view ? "?view=$next_view$filtros_query" : "#";
$que_busca = $_GET['que_busca'] ?? ''; // Inicialización para evitar Undefined variable


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
    ],
    'prospeccion' => ['meta_total' => 0, 'avance_total' => 0, 'pct' => 0, 'detalle_asesores' => []],
    'frio' => ['visitas_frio' => 0, 'total_contactos' => 0, 'frio_pct' => 0],
    'operaciones' => [
        'total_solicitudes' => 0,
        'sol_ahorro' => 0,
        'sol_corriente' => 0,
        'sol_inversion' => 0,
        'sol_credito' => 0,
        'total_desembolsadas' => 0,
        'des_ahorro' => 0,
        'des_corriente' => 0,
        'des_inversion' => 0,
        'des_credito' => 0,
        'pct_total' => 0,
        'detalle_asesores' => []
    ]
];
if (!empty($target_ids)) {
    $params = array_merge($target_ids, [$fecha_inicio, $fecha_fin]);
    $si_fichas = 0;
    $hasFichaProd = (bool) $pdo->query("SHOW TABLES LIKE 'ficha_producto'")->fetchColumn();
    if ($hasFichaProd) {
        try {
            // Solo fichas donde el cliente dijo SÍ querer crédito (requiere_credito=1)
            $sqlSF = "SELECT COUNT(DISTINCT fp.id) FROM ficha_producto fp 
                      LEFT JOIN ficha_credito fc ON fc.ficha_id = fp.id
                      WHERE fp.asesor_id IN ($ph) AND (DATE(fp.created_at) BETWEEN ? AND ?) 
                      AND fp.producto_tipo = 'credito' AND (fc.requiere_credito = 1 OR fc.requiere_credito IS NULL)";
            $stSF = $pdo->prepare($sqlSF);
            $stSF->execute($params);
            $si_fichas = (int) $stSF->fetchColumn();
        } catch (\Throwable $e) {
            $si_fichas = 0;
        }
    }
    $stM = $pdo->prepare("SELECT SUM(meta_visitas) FROM meta_asesor_diaria WHERE asesor_id IN ($ph) AND (fecha BETWEEN ? AND ?)");
    $stM->execute($params);
    $meta_t = (int) $stM->fetchColumn();
    $stV = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id IN ($ph) AND estado = 'completada' AND (fecha_realizada BETWEEN ? AND ?)");
    $stV->execute($params);
    $avance_t = (int) $stV->fetchColumn();
    $data['prospeccion'] = ['meta_total' => $meta_t, 'avance_total' => $avance_t, 'pct' => ($meta_t > 0) ? round(($avance_t / $meta_t) * 100, 1) : 0];
    $stD = $pdo->prepare("SELECT u.nombre, COALESCE((SELECT SUM(meta_visitas) FROM meta_asesor_diaria WHERE asesor_id = a.id AND fecha BETWEEN ? AND ?), 0) as meta, COALESCE((SELECT COUNT(*) FROM tarea WHERE asesor_id = a.id AND estado = 'completada' AND fecha_realizada BETWEEN ? AND ?), 0) as avance FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id IN ($ph) ORDER BY u.nombre");
    $stD->execute(array_merge([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin], $target_ids));
    $data['prospeccion']['detalle_asesores'] = $stD->fetchAll(PDO::FETCH_ASSOC);
    // Visitas al Frío: cubre flujo app (tipo_tarea='visita_frio') Y flujo web (origen_prospecto='frio')
    $sqlFrio = "SELECT COUNT(DISTINCT t.id) FROM tarea t
                LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                WHERE t.asesor_id IN ($ph)
                  AND t.estado = 'completada'
                  AND (t.fecha_realizada BETWEEN ? AND ?)
                  AND (t.tipo_tarea = 'visita_frio' OR cp.origen_prospecto = 'frio')";
    $stF = $pdo->prepare($sqlFrio);
    $stF->execute($params);
    $frio = (int) $stF->fetchColumn();

    $sqlLeads = "SELECT COUNT(DISTINCT t.id) FROM tarea t LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND cp.origen_prospecto = 'leads_llamadas'";
    $stL = $pdo->prepare($sqlLeads);
    $stL->execute($params);
    $leads = (int) $stL->fetchColumn();

    $sqlSeguidores = "SELECT COUNT(DISTINCT t.id) FROM tarea t LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND cp.origen_prospecto = 'seguidor'";
    $stS = $pdo->prepare($sqlSeguidores);
    $stS->execute($params);
    $seguidores = (int) $stS->fetchColumn();

    $sqlFrioDetalle = "SELECT u.nombre, 
                       COALESCE((SELECT COUNT(DISTINCT t.id) FROM tarea t LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id = a.id AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND (t.tipo_tarea = 'visita_frio' OR cp.origen_prospecto = 'frio')), 0) as visitas_frio,
                       COALESCE((SELECT COUNT(DISTINCT t.id) FROM tarea t LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id = a.id AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND cp.origen_prospecto = 'leads_llamadas'), 0) as visitas_leads,
                       COALESCE((SELECT COUNT(DISTINCT t.id) FROM tarea t LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id WHERE t.asesor_id = a.id AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND cp.origen_prospecto = 'seguidor'), 0) as visitas_seguidores,
                       COALESCE((SELECT COUNT(*) FROM tarea t2 WHERE t2.asesor_id = a.id AND t2.estado = 'completada' AND (t2.fecha_realizada BETWEEN ? AND ?)), 0) as total_contactos
                       FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id IN ($ph) ORDER BY u.nombre";
    $stFD = $pdo->prepare($sqlFrioDetalle);
    $stFD->execute(array_merge([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin], $target_ids));

    $oficina = $avance_t - $frio;
    $data['frio'] = [
        'visitas_frio' => $frio,
        'visitas_oficina' => $oficina,
        'visitas_leads' => $leads,
        'visitas_seguidores' => $seguidores,
        'total_contactos' => $avance_t,
        'frio_pct' => ($avance_t > 0) ? round(($frio / $avance_t) * 100, 1) : 0,
        'oficina_pct' => ($avance_t > 0) ? round(($oficina / $avance_t) * 100, 1) : 0,
        'leads_pct' => ($avance_t > 0) ? round(($leads / $avance_t) * 100, 1) : 0,
        'seguidores_pct' => ($avance_t > 0) ? round(($seguidores / $avance_t) * 100, 1) : 0,
        'detalle_asesores' => $stFD->fetchAll(PDO::FETCH_ASSOC)
    ];

    $sqlLevantamiento = "SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND t.tipo_tarea = 'levantamiento' AND (t.fecha_realizada BETWEEN ? AND ?)";
    $stLev = $pdo->prepare($sqlLevantamiento);
    $stLev->execute($params);
    $levantamientos = (int) $stLev->fetchColumn();

    $sqlInt = "SELECT SUM(CASE WHEN ec.interes_conocer_productos = 1 THEN 1 ELSE 0 END) FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)";
    $stInt = $pdo->prepare($sqlInt);
    $stInt->execute($params);
    $total_interesados = (int) $stInt->fetchColumn();

    $hasCp = (bool) $pdo->query("SHOW TABLES LIKE 'credito_proceso'")->fetchColumn();
    $creditos_aprobados_cp = 0;
    $estadoCol = '';
    if ($hasCp) {
        $hasEstadoCred = (bool) $pdo->query("SHOW COLUMNS FROM credito_proceso LIKE 'estado_credito'")->fetchColumn();
        $hasEstado = (bool) $pdo->query("SHOW COLUMNS FROM credito_proceso LIKE 'estado'")->fetchColumn();
        $estadoCol = $hasEstadoCred ? 'estado_credito' : ($hasEstado ? 'estado' : '');
        if ($estadoCol) {
            $sqlAprobadosCp = "SELECT COUNT(DISTINCT cp.id) FROM credito_proceso cp WHERE cp.asesor_id IN ($ph) AND cp.$estadoCol IN ('aprobado', 'desembolsado') AND (DATE(cp.created_at) BETWEEN ? AND ?)";
            $stAprobCp = $pdo->prepare($sqlAprobadosCp);
            $stAprobCp->execute($params);
            $creditos_aprobados_cp = (int) $stAprobCp->fetchColumn();
        }
    }

    $hasFicha = (bool) $pdo->query("SHOW TABLES LIKE 'ficha_producto'")->fetchColumn();
    $creditos_aprobados_fp = 0;
    $hasEstadoRev = false;
    if ($hasFicha) {
        $hasEstadoRev = (bool) $pdo->query("SHOW COLUMNS FROM ficha_producto LIKE 'estado_revision'")->fetchColumn();
        if ($hasEstadoRev) {
            $sqlAprobadosFp = "SELECT COUNT(DISTINCT fp.id) FROM ficha_producto fp WHERE fp.asesor_id IN ($ph) AND fp.producto_tipo = 'credito' AND fp.estado_revision IN ('aprobada', 'aprobado') AND (DATE(fp.created_at) BETWEEN ? AND ?)";
            $stAprobFp = $pdo->prepare($sqlAprobadosFp);
            $stAprobFp->execute($params);
            $creditos_aprobados_fp = (int) $stAprobFp->fetchColumn();
        }
    }

    $creditos_aprobados = $creditos_aprobados_cp + $creditos_aprobados_fp;

    $subqCp = "0";
    if ($hasCp && $estadoCol) {
        $subqCp = "COALESCE((SELECT COUNT(DISTINCT cp.id) FROM credito_proceso cp WHERE cp.asesor_id = a.id AND cp.$estadoCol IN ('aprobado', 'desembolsado') AND (DATE(cp.created_at) BETWEEN ? AND ?)), 0)";
    }

    $subqFp = "0";
    if ($hasFicha && $hasEstadoRev) {
        $subqFp = "COALESCE((SELECT COUNT(DISTINCT fp.id) FROM ficha_producto fp WHERE (a.id = fp.asesor_id OR a.usuario_id = fp.usuario_id) AND fp.producto_tipo = 'credito' AND fp.estado_revision IN ('aprobada', 'aprobado') AND (DATE(fp.created_at) BETWEEN ? AND ?)), 0)";
    }

    $sqlEvDetalle = "SELECT u.nombre, a.id as asesor_id,
                   COALESCE((SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id = a.id AND t.estado = 'completada' AND t.tipo_tarea = 'levantamiento' AND (t.fecha_realizada BETWEEN ? AND ?)), 0) as levantamientos,
                   COALESCE((SELECT SUM(CASE WHEN ec.interes_conocer_productos = 1 THEN 1 ELSE 0 END) FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id = a.id AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)), 0) as total_interesados
                   FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id IN ($ph) ORDER BY u.nombre";

    $paramsEvDetalle = [$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin];
    $paramsEvDetalle = array_merge($paramsEvDetalle, $target_ids);

    $asesores_data = [];
    try {
        $stEvD = $pdo->prepare($sqlEvDetalle);
        $stEvD->execute($paramsEvDetalle);
        $asesores_data = $stEvD->fetchAll(PDO::FETCH_ASSOC);

        // Calcular aprobados individualmente para cada asesor
        foreach ($asesores_data as &$asData) {
            $asData['creditos_aprobados'] = 0;
            $curr_as_id = $asData['asesor_id'];
            $curr_params = [$curr_as_id, $fecha_inicio, $fecha_fin];

            if ($hasCp && $estadoCol) {
                $stTmp = $pdo->prepare("SELECT COUNT(DISTINCT id) FROM credito_proceso WHERE asesor_id = ? AND $estadoCol IN ('aprobado', 'desembolsado') AND (DATE(created_at) BETWEEN ? AND ?)");
                $stTmp->execute($curr_params);
                $asData['creditos_aprobados'] += (int) $stTmp->fetchColumn();
            }
            if ($hasFicha && $hasEstadoRev) {
                $stTmp = $pdo->prepare("SELECT COUNT(DISTINCT fp.id) FROM ficha_producto fp 
                                       WHERE (fp.asesor_id = ? OR fp.usuario_id = ?) 
                                       AND fp.producto_tipo = 'credito' AND fp.estado_revision IN ('aprobada', 'aprobado') 
                                       AND (DATE(fp.created_at) BETWEEN ? AND ?)");
                $stTmp->execute([$curr_as_id, $curr_as_id, $fecha_inicio, $fecha_fin]);
                $asData['creditos_aprobados'] += (int) $stTmp->fetchColumn();
            }
        }
    } catch (\Throwable $e) {
        error_log("Error en sqlEvDetalle: " . $e->getMessage());
        $sqlEvFallback = "SELECT u.nombre, 
                       COALESCE((SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id = a.id AND t.estado = 'completada' AND t.tipo_tarea = 'levantamiento' AND (t.fecha_realizada BETWEEN ? AND ?)), 0) as levantamientos,
                       COALESCE((SELECT SUM(CASE WHEN ec.interes_conocer_productos = 1 THEN 1 ELSE 0 END) FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id = a.id AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)), 0) as total_interesados,
                       0 as creditos_aprobados
                       FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id IN ($ph) ORDER BY u.nombre";
        $stFbk = $pdo->prepare($sqlEvFallback);
        $stFbk->execute(array_merge([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin], $target_ids));
        $asesores_data = $stFbk->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- RECUPERACIÓN ---
    $sqlRec = "SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.tipo_tarea = 'recuperacion' OR t.tipo_tarea LIKE '%recupera%') AND (t.fecha_realizada BETWEEN ? AND ?)";
    $stRec = $pdo->prepare($sqlRec);
    $stRec->execute($params);
    $recuperaciones = (int) $stRec->fetchColumn();

    $sqlRecDetalle = "SELECT u.nombre, 
                       COALESCE((SELECT COUNT(DISTINCT t.id) FROM tarea t WHERE t.asesor_id = a.id AND t.estado = 'completada' AND (t.tipo_tarea = 'recuperacion' OR t.tipo_tarea LIKE '%recupera%') AND (t.fecha_realizada BETWEEN ? AND ?)), 0) as rec,
                       COALESCE((SELECT COUNT(*) FROM tarea t2 WHERE t2.asesor_id = a.id AND t2.estado = 'completada' AND (t2.fecha_realizada BETWEEN ? AND ?)), 0) as total
                       FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id IN ($ph) ORDER BY u.nombre";
    $stRD = $pdo->prepare($sqlRecDetalle);
    $stRD->execute(array_merge([$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin], $target_ids));
    $detalle_rec = $stRD->fetchAll(PDO::FETCH_ASSOC);

    $data['recuperacion'] = [
        'visitas_recuperacion' => $recuperaciones,
        'total_visitas' => $avance_t,
        'recuperacion_pct' => ($avance_t > 0) ? round(($recuperaciones / $avance_t) * 100, 1) : 0,
        'detalle_asesores' => $detalle_rec
    ];

    // --- OPERACIONES DESEMBOLSADAS ---
    $hasEncNeg = (bool) $pdo->query("SHOW TABLES LIKE 'encuesta_negocio'")->fetchColumn();
    $sqlOpSol = "SELECT 
                    SUM(CASE WHEN ec.interes_ahorro = 1 THEN 1 ELSE 0 END) as sol_ahorro,
                    SUM(CASE WHEN ec.interes_cc = 1 THEN 1 ELSE 0 END) as sol_corriente,
                    SUM(CASE WHEN ec.interes_inversion = 1 THEN 1 ELSE 0 END) as sol_inversion,
                    SUM(CASE WHEN ec.interes_credito = 1 THEN 1 ELSE 0 END) as sol_credito
                 FROM encuesta_comercial ec 
                 JOIN tarea t ON t.id = ec.tarea_id 
                 WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)";
    $stOpSol = $pdo->prepare($sqlOpSol);
    $stOpSol->execute($params);
    $resOpSol = $stOpSol->fetch(PDO::FETCH_ASSOC);

    $sol_ahorro = (int) $resOpSol['sol_ahorro'];
    $sol_corriente = (int) $resOpSol['sol_corriente'];
    $sol_inversion = (int) $resOpSol['sol_inversion'];
    $sol_credito = (int) $resOpSol['sol_credito'] + $si_fichas;
    $total_solicitudes = $sol_ahorro + $sol_corriente + $sol_inversion + $sol_credito;

    $des_ahorro = 0;
    $des_corriente = 0;
    $des_inversion = 0;
    if ($hasFicha && $hasEstadoRev) {
        $sqlOpDes = "SELECT 
                        SUM(CASE WHEN producto_tipo = 'cuenta_ahorros' THEN 1 ELSE 0 END) as des_ahorro,
                        SUM(CASE WHEN producto_tipo = 'cuenta_corriente' THEN 1 ELSE 0 END) as des_corriente,
                        SUM(CASE WHEN producto_tipo = 'inversiones' THEN 1 ELSE 0 END) as des_inversion
                     FROM ficha_producto fp 
                     WHERE fp.asesor_id IN ($ph) AND fp.estado_revision IN ('aprobada', 'aprobado') AND (DATE(fp.created_at) BETWEEN ? AND ?)";
        $stOpDes = $pdo->prepare($sqlOpDes);
        $stOpDes->execute($params);
        $resOpDes = $stOpDes->fetch(PDO::FETCH_ASSOC);
        $des_ahorro = (int) $resOpDes['des_ahorro'];
        $des_corriente = (int) $resOpDes['des_corriente'];
        $des_inversion = (int) $resOpDes['des_inversion'];
    }
    $des_credito = $creditos_aprobados;
    $total_desembolsadas = $des_ahorro + $des_corriente + $des_inversion + $des_credito;

    $sqlOpDetalle = "SELECT u.nombre, a.id as asesor_id FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id IN ($ph) ORDER BY u.nombre";
    $stOpD = $pdo->prepare($sqlOpDetalle);
    $stOpD->execute($target_ids);
    $asesores_list = $stOpD->fetchAll(PDO::FETCH_ASSOC);

    $detalle_op_asesores = [];
    foreach ($asesores_list as $as) {
        $as_id = $as['asesor_id'];
        $as_params = [$as_id, $fecha_inicio, $fecha_fin];

        // Solicitudes del asesor
        $stAsSol = $pdo->prepare("SELECT SUM(CASE WHEN ec.interes_ahorro = 1 THEN 1 ELSE 0 END) as s_ah, SUM(CASE WHEN ec.interes_cc = 1 THEN 1 ELSE 0 END) as s_co, SUM(CASE WHEN ec.interes_inversion = 1 THEN 1 ELSE 0 END) as s_in, SUM(CASE WHEN ec.interes_credito = 1 THEN 1 ELSE 0 END) as s_cr FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id = ? AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)");
        $stAsSol->execute($as_params);
        $rAsSol = $stAsSol->fetch(PDO::FETCH_ASSOC);

        $as_si_fichas = 0;
        if ($hasFicha) {
            // Solo fichas donde el cliente dijo SÍ querer crédito
            $stAsSF = $pdo->prepare("SELECT COUNT(DISTINCT fp.id) FROM ficha_producto fp LEFT JOIN ficha_credito fc ON fc.ficha_id = fp.id WHERE fp.asesor_id = ? AND (DATE(fp.created_at) BETWEEN ? AND ?) AND fp.producto_tipo = 'credito' AND (fc.requiere_credito = 1 OR fc.requiere_credito IS NULL)");
            $stAsSF->execute($as_params);
            $as_si_fichas = (int) $stAsSF->fetchColumn();
        }

        $s_tot = (int) $rAsSol['s_ah'] + (int) $rAsSol['s_co'] + (int) $rAsSol['s_in'] + (int) $rAsSol['s_cr'] + $as_si_fichas;

        // Desembolsadas del asesor
        $d_ah = 0;
        $d_co = 0;
        $d_in = 0;
        if ($hasFicha && $hasEstadoRev) {
            $stAsDes = $pdo->prepare("SELECT SUM(CASE WHEN producto_tipo = 'cuenta_ahorros' THEN 1 ELSE 0 END) as d_ah, SUM(CASE WHEN producto_tipo = 'cuenta_corriente' THEN 1 ELSE 0 END) as d_co, SUM(CASE WHEN producto_tipo = 'inversiones' THEN 1 ELSE 0 END) as d_in FROM ficha_producto WHERE (asesor_id = ? OR usuario_id = ?) AND estado_revision IN ('aprobada', 'aprobado') AND (DATE(created_at) BETWEEN ? AND ?)");
            $stAsDes->execute([$as_id, $as_id, $fecha_inicio, $fecha_fin]);
            $rAsDes = $stAsDes->fetch(PDO::FETCH_ASSOC);
            $d_ah = (int) ($rAsDes['d_ah'] ?? 0);
            $d_co = (int) ($rAsDes['d_co'] ?? 0);
            $d_in = (int) ($rAsDes['d_in'] ?? 0);
        }

        // Créditos Aprobados (Global por Asesor)
        $as_d_cr = 0;
        try {
            $stAsCr = $pdo->prepare("SELECT COUNT(*) FROM cliente_prospecto WHERE asesor_id = ? AND estado = 'cliente' AND (DATE(created_at) BETWEEN ? AND ? OR DATE(updated_at) BETWEEN ? AND ?)");
            $stAsCr->execute([$as_id, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
            $as_d_cr = (int) $stAsCr->fetchColumn();
        } catch (Throwable $e) {}

        $as_d_tot = $d_ah + $d_co + $d_in + $as_d_cr;

        // Comparativa interna por asesor (Crédito Empresa vs No Empresa)
        // Redefinido: "Con Empresa" son aquellos que llenaron el levantamiento (encuesta_negocio)
        $as_comp = ['con' => ['s' => 0, 'd' => 0], 'sin' => ['s' => 0, 'd' => 0]];
        try {
            $sqlAsComp = "SELECT 
                            tiene_negocio,
                            COUNT(*) as interes,
                            SUM(is_approved) as aprobados
                        FROM (
                            SELECT 
                                cp.id,
                                MAX(CASE WHEN en.id IS NOT NULL THEN 1 ELSE 0 END) as tiene_negocio,
                                MAX(CASE 
                                    WHEN (crp." . ($estadoCol ?: 'estado') . " IN ('aprobado', 'desembolsado')) 
                                      OR (fp.estado_revision IN ('aprobada', 'aprobado')) 
                                    THEN 1 ELSE 0 END) as is_approved
                            FROM cliente_prospecto cp
                            LEFT JOIN tarea t ON t.cliente_prospecto_id COLLATE utf8mb4_unicode_ci = cp.id COLLATE utf8mb4_unicode_ci
                            LEFT JOIN encuesta_comercial ec ON ec.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                            LEFT JOIN encuesta_crediticia ecr ON ecr.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                            LEFT JOIN encuesta_negocio en ON en.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                            LEFT JOIN credito_proceso crp ON crp.cliente_prospecto_id COLLATE utf8mb4_unicode_ci = cp.id COLLATE utf8mb4_unicode_ci
                            LEFT JOIN ficha_producto fp ON fp.cliente_cedula COLLATE utf8mb4_unicode_ci = cp.cedula COLLATE utf8mb4_unicode_ci AND fp.producto_tipo = 'credito'
                            WHERE cp.asesor_id = ?
                              AND (ec.interes_credito = 1 OR ecr.interes_credito = 1 OR ecr.requiere_credito = 1)
                              AND (DATE(cp.created_at) BETWEEN ? AND ? OR DATE(cp.updated_at) BETWEEN ? AND ?)
                            GROUP BY cp.id
                        ) sub
                        GROUP BY tiene_negocio";
            $stAsComp = $pdo->prepare($sqlAsComp);
            $stAsComp->execute([$as_id, $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
            foreach ($stAsComp->fetchAll() as $rc) {
                if ($rc['tiene_negocio'] == 1) {
                    $as_comp['con']['s'] = (int)$rc['interes'];
                    $as_comp['con']['d'] = (int)$rc['aprobados'];
                } else {
                    $as_comp['sin']['s'] = (int)$rc['interes'];
                    $as_comp['sin']['d'] = (int)$rc['aprobados'];
                }
            }
        } catch (Throwable $e) {}

        $detalle_op_asesores[] = [
            'nombre' => $as['nombre'],
            'solicitudes' => (int)$as_comp['con']['s'] + (int)$as_comp['sin']['s'],
            'desembolsadas' => $as_d_tot,
            'pct' => (($as_comp['con']['s'] + $as_comp['sin']['s']) > 0) ? round(($as_d_tot / ($as_comp['con']['s'] + $as_comp['sin']['s'])) * 100, 1) : 0,
            'comp' => $as_comp
        ];
    }
    

    // --- COMPARATIVA EMPRESA VS NO EMPRESA (CRÉDITO) ---
    $comp_emp = [
        'con_emp' => ['sol' => 0, 'des' => 0, 'pct' => 0],
        'sin_emp' => ['sol' => 0, 'des' => 0, 'pct' => 0]
    ];

    try {
        // Consulta Unificada para Comparativa
        // Redefinido: "Con Empresa" son aquellos que llenaron el levantamiento (encuesta_negocio)
        $sqlComp = "SELECT 
                        tiene_negocio,
                        COUNT(*) as interes,
                        SUM(is_approved) as aprobados
                    FROM (
                        SELECT 
                            cp.id,
                            MAX(CASE WHEN en.id IS NOT NULL THEN 1 ELSE 0 END) as tiene_negocio,
                            MAX(CASE 
                                WHEN (crp." . ($estadoCol ?: 'estado') . " IN ('aprobado', 'desembolsado')) 
                                  OR (fp.estado_revision IN ('aprobada', 'aprobado')) 
                                THEN 1 ELSE 0 END) as is_approved
                        FROM cliente_prospecto cp
                        LEFT JOIN tarea t ON t.cliente_prospecto_id COLLATE utf8mb4_unicode_ci = cp.id COLLATE utf8mb4_unicode_ci
                        LEFT JOIN encuesta_comercial ec ON ec.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                        LEFT JOIN encuesta_crediticia ecr ON ecr.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                        LEFT JOIN encuesta_negocio en ON en.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                        LEFT JOIN credito_proceso crp ON crp.cliente_prospecto_id COLLATE utf8mb4_unicode_ci = cp.id COLLATE utf8mb4_unicode_ci
                        LEFT JOIN ficha_producto fp ON fp.cliente_cedula COLLATE utf8mb4_unicode_ci = cp.cedula COLLATE utf8mb4_unicode_ci AND fp.producto_tipo = 'credito'
                        WHERE cp.asesor_id IN ($ph)
                          AND (ec.interes_credito = 1 OR ecr.interes_credito = 1 OR ecr.requiere_credito = 1)
                          AND (DATE(cp.created_at) BETWEEN ? AND ? OR DATE(cp.updated_at) BETWEEN ? AND ?)
                        GROUP BY cp.id
                    ) sub
                    GROUP BY tiene_negocio";
        
        $stComp = $pdo->prepare($sqlComp);
        $paramsComp = array_merge($target_ids, [$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
        $stComp->execute($paramsComp);
        $resComp = $stComp->fetchAll();

        foreach ($resComp as $r) {
            if ($r['tiene_negocio'] == 1) {
                $comp_emp['con_emp']['sol'] = (int)$r['interes'];
                $comp_emp['con_emp']['des'] = (int)$r['aprobados'];
                $comp_emp['con_emp']['pct'] = ($r['interes'] > 0) ? round(($r['aprobados'] / $r['interes']) * 100, 1) : 0;
            } else {
                $comp_emp['sin_emp']['sol'] = (int)$r['interes'];
                $comp_emp['sin_emp']['des'] = (int)$r['aprobados'];
                $comp_emp['sin_emp']['pct'] = ($r['interes'] > 0) ? round(($r['aprobados'] / $r['interes']) * 100, 1) : 0;
            }
        }
    } catch (Throwable $e) {
        // En caso de error, los valores quedan en 0
    }
    $data['operaciones'] = [
        'sol_ahorro' => $sol_ahorro,
        'sol_corriente' => $sol_corriente,
        'sol_inversion' => $sol_inversion,
        'sol_credito' => $sol_credito,
        'des_ahorro' => $des_ahorro,
        'des_corriente' => $des_corriente,
        'des_inversion' => $des_inversion,
        'des_credito' => $des_credito,
        'total_solicitudes' => $total_solicitudes,
        'total_desembolsadas' => $total_desembolsadas,
        'pct_operaciones' => ($total_solicitudes > 0) ? round(($total_desembolsadas / $total_solicitudes) * 100, 1) : 0,
        'detalle_asesores' => $detalle_op_asesores,
        'comp_empresa' => $comp_emp
    ];

    // --- EFICIENCIA DEL PROCESO ---
    if ($view === 'eficiencia') {
        $data['eficiencia'] = ['contactos' => 0, 'entrevistas' => 0, 'interes' => 0, 'eficiencia' => 0, 'detalle' => []];
        try {
            // Global
            $sqlP1 = "SELECT 
                        COUNT(t.id) as contactos,
                        SUM(CASE WHEN ec.id IS NOT NULL THEN 1 ELSE 0 END) as entrevistas,
                        SUM(CASE WHEN (ec.interes_ahorro=1 OR ec.interes_credito=1 OR ec.interes_inversion=1) THEN 1 ELSE 0 END) as interes
                      FROM tarea t 
                      LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id 
                      WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)";
            $stP1 = $pdo->prepare($sqlP1);
            $stP1->execute($params);
            $resP1 = $stP1->fetch();
            if ($resP1) {
                $data['eficiencia']['contactos'] = (int)$resP1['contactos'];
                $data['eficiencia']['entrevistas'] = (int)$resP1['entrevistas'];
                $data['eficiencia']['interes'] = (int)$resP1['interes'];
                // Nueva lógica: Interés / Entrevistas (Solo los que SI aceptaron)
                $data['eficiencia']['eficiencia'] = ($data['eficiencia']['entrevistas'] > 0) ? round(($data['eficiencia']['interes'] / $data['eficiencia']['entrevistas']) * 100, 1) : 0;
            }

            // Por Asesor
            $sqlP2 = "SELECT 
                        u.nombre,
                        COUNT(t.id) as contactos,
                        SUM(CASE WHEN ec.id IS NOT NULL THEN 1 ELSE 0 END) as entrevistas,
                        SUM(CASE WHEN (ec.interes_ahorro=1 OR ec.interes_credito=1 OR ec.interes_inversion=1) THEN 1 ELSE 0 END) as interes
                      FROM tarea t 
                      JOIN asesor a ON a.id = t.asesor_id
                      JOIN usuario u ON u.id = a.usuario_id
                      LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id 
                      WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?)
                      GROUP BY t.asesor_id, u.nombre";
            $stP2 = $pdo->prepare($sqlP2);
            $stP2->execute($params);
            $data['eficiencia']['detalle'] = $stP2->fetchAll();

            // --- EFICIENCIA LEVANTAMIENTO (EMPRESAS) ---
            $sqlP3 = "SELECT 
                        COUNT(ec.id) as entrevistas_emp,
                        SUM(CASE WHEN en.id IS NOT NULL THEN 1 ELSE 0 END) as levantamientos
                      FROM encuesta_comercial ec 
                      JOIN tarea t ON t.id = ec.tarea_id 
                      JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id 
                      LEFT JOIN encuesta_negocio en ON en.tarea_id = t.id 
                      WHERE cp.tiene_empresa = 1 AND t.asesor_id IN ($ph) AND (t.fecha_realizada BETWEEN ? AND ?)";
            $stP3 = $pdo->prepare($sqlP3);
            $stP3->execute($params);
            $resP3 = $stP3->fetch();
            $data['eficiencia']['empresa'] = [
                'entrevistas' => (int)($resP3['entrevistas_emp'] ?? 0),
                'levantamientos' => (int)($resP3['levantamientos'] ?? 0),
                'pct' => ($resP3['entrevistas_emp'] > 0) ? round(($resP3['levantamientos'] / $resP3['entrevistas_emp']) * 100, 1) : 0
            ];

            // Detalle Asesor Empresa
            $sqlP4 = "SELECT 
                        u.nombre,
                        COUNT(ec.id) as entrevistas_emp,
                        SUM(CASE WHEN en.id IS NOT NULL THEN 1 ELSE 0 END) as levantamientos
                      FROM encuesta_comercial ec 
                      JOIN tarea t ON t.id = ec.tarea_id 
                      JOIN asesor a ON a.id = t.asesor_id 
                      JOIN usuario u ON u.id = a.usuario_id 
                      JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id 
                      LEFT JOIN encuesta_negocio en ON en.tarea_id = t.id 
                      WHERE cp.tiene_empresa = 1 AND t.asesor_id IN ($ph) AND (t.fecha_realizada BETWEEN ? AND ?)
                      GROUP BY t.asesor_id, u.nombre";
            $stP4 = $pdo->prepare($sqlP4);
            $stP4->execute($params);
            $data['eficiencia']['empresa_detalle'] = $stP4->fetchAll();

        } catch (Throwable $e) {
            error_log("Error Eficiencia: " . $e->getMessage());
        }
    }

    // --- POST-VENTA (REPRÉSTAMOS) ---
    $data['postventa'] = [
        'total' => 0, 
        'represtamos' => 0, 
        'pct' => 0, 
        'detalle' => [],
        'clientes' => [],
        'desembolsos' => ['total' => 0, 'represtamos' => 0, 'pct' => 0],
        'desembolsos_detalle' => []
    ];

    if ($view === 'postventa') {
        try {
            // --- BLOQUE 1: FIDELIZACIÓN (Optimizado para que no salga vacío) ---
            $sqlPV1 = "SELECT 
                        COUNT(t.id) as total,
                        SUM(CASE WHEN (en.p2_es_cliente = 1 OR cp.estado = 'cliente' OR cp.estado = 'CLIENTE' OR t.tipo_tarea = 'represtamo') THEN 1 ELSE 0 END) as represtamos
                       FROM tarea t
                       LEFT JOIN encuesta_negocio en ON en.tarea_id = t.id 
                       LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id 
                       WHERE t.asesor_id IN ($ph) 
                         AND t.estado = 'completada'
                         AND (t.tipo_tarea IN ('levantamiento', 'post_venta', 'represtamo'))
                         AND (t.fecha_realizada BETWEEN ? AND ?)";
            $stPV1 = $pdo->prepare($sqlPV1);
            $stPV1->execute($params);
            $resPV1 = $stPV1->fetch();
            
            $data['postventa']['total'] = (int)($resPV1['total'] ?? 0);
            $data['postventa']['represtamos'] = (int)($resPV1['represtamos'] ?? 0);
            $data['postventa']['pct'] = ($data['postventa']['total'] > 0) ? round(($data['postventa']['represtamos'] / $data['postventa']['total']) * 100, 1) : 0;

            // Detalle por Asesor (Fidelización)
            $sqlPV2 = "SELECT 
                        u.nombre,
                        COUNT(t.id) as total,
                        SUM(CASE WHEN (en.p2_es_cliente = 1 OR cp.estado = 'cliente' OR cp.estado = 'CLIENTE' OR t.tipo_tarea = 'represtamo') THEN 1 ELSE 0 END) as represtamos
                       FROM tarea t
                       JOIN asesor a ON a.id = t.asesor_id 
                       JOIN usuario u ON u.id = a.usuario_id
                       LEFT JOIN encuesta_negocio en ON en.tarea_id = t.id 
                       LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id 
                       WHERE t.asesor_id IN ($ph) 
                         AND t.estado = 'completada'
                         AND (t.tipo_tarea IN ('levantamiento', 'post_venta', 'represtamo'))
                         AND (t.fecha_realizada BETWEEN ? AND ?)
                       GROUP BY t.asesor_id, u.nombre";
            $stPV2 = $pdo->prepare($sqlPV2);
            $stPV2->execute($params);
            $data['postventa']['detalle'] = $stPV2->fetchAll();

            // --- BLOQUE 2: DESEMBOLSOS REPRÉSTAMOS (NUEVO) ---
            // Fórmula: (Aprobados que son clientes) / (Total Aprobados)
            // --- BLOQUE 2: DESEMBOLSOS REPRÉSTAMOS (BASADO EN DUMP SQL) ---
            // Fórmula: (Origen Prospecto = 'cliente') / (Total Aprobadas)
            // Consulta para el bloque principal de Desembolsos
            $sqlPV4 = "SELECT 
                        COUNT(cp.id) as total,
                        SUM(CASE WHEN (cp.origen_prospecto = 'cliente' OR cp.origen_prospecto = 'CLIENTE') THEN 1 ELSE 0 END) as represtamos
                       FROM cliente_prospecto cp
                       WHERE cp.asesor_id IN ($ph) 
                         AND cp.estado = 'cliente'
                         AND (DATE(cp.created_at) BETWEEN ? AND ? OR DATE(cp.updated_at) BETWEEN ? AND ?)";
            
            $stPV4 = $pdo->prepare($sqlPV4);
            // Parámetros: IDs de asesores + Rango fechas para created_at + Rango fechas para updated_at
            $paramsPV4 = array_merge($target_ids, [$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin]);
            $stPV4->execute($paramsPV4);
            $resPV4 = $stPV4->fetch();
            
            $data['postventa']['desembolsos'] = [
                'total' => (int)($resPV4['total'] ?? 0),
                'represtamos' => (int)($resPV4['represtamos'] ?? 0),
                'pct' => ($resPV4['total'] > 0) ? round(($resPV4['represtamos'] / $resPV4['total']) * 100, 1) : 0
            ];

            // Detalle Desembolsos por Asesor
            // Consulta para el detalle por asesor
            $sqlPV5 = "SELECT 
                        u.nombre,
                        COUNT(cp.id) as total,
                        SUM(CASE WHEN (cp.origen_prospecto = 'cliente' OR cp.origen_prospecto = 'CLIENTE') THEN 1 ELSE 0 END) as represtamos
                       FROM cliente_prospecto cp
                       JOIN asesor a ON a.id = cp.asesor_id 
                       JOIN usuario u ON u.id = a.usuario_id 
                       WHERE cp.asesor_id IN ($ph) 
                         AND cp.estado = 'cliente'
                         AND (DATE(cp.created_at) BETWEEN ? AND ? OR DATE(cp.updated_at) BETWEEN ? AND ?)
                       GROUP BY cp.asesor_id, u.nombre";
            
            $stPV5 = $pdo->prepare($sqlPV5);
            $stPV5->execute($paramsPV4);
            $data['postventa']['desembolsos_detalle'] = $stPV5->fetchAll();

        } catch (Throwable $e) {
            error_log("Error Post-Venta: " . $e->getMessage());
        }
    }

    $data['operaciones'] = [
        'total_solicitudes' => $total_solicitudes,
        'sol_ahorro' => $sol_ahorro,
        'sol_corriente' => $sol_corriente,
        'sol_inversion' => $sol_inversion,
        'sol_credito' => $sol_credito,
        'total_desembolsadas' => $total_desembolsadas,
        'des_ahorro' => $des_ahorro,
        'des_corriente' => $des_corriente,
        'des_inversion' => $des_inversion,
        'des_credito' => $des_credito,
        'pct_total' => ($total_solicitudes > 0) ? round(($total_desembolsadas / $total_solicitudes) * 100, 1) : 0,
        'detalle_asesores' => $detalle_op_asesores,
        'comp_empresa' => $comp_emp
    ];

    $data['evaluacion'] = [
        'levantamientos' => $levantamientos,
        'total_interesados' => $total_interesados,
        'evaluacion_pct' => ($total_interesados > 0) ? round(($levantamientos / $total_interesados) * 100, 1) : 0,
        'creditos_aprobados' => $creditos_aprobados,
        'aprobados_pct' => ($levantamientos > 0) ? round(($creditos_aprobados / $levantamientos) * 100, 1) : 0,
        'global_pct' => ($total_interesados > 0) ? round(($creditos_aprobados / $total_interesados) * 100, 1) : 0,
        'detalle_asesores' => $asesores_data,
        'que_busca' => ['total' => 0, 'agilidad' => 0, 'cajeros' => 0, 'banca' => 0, 'agencias' => 0, 'credito' => 0, 'debito' => 0, 'tc' => 0]
    ];

    // SQL para G3 de Evaluación (Qué busca el cliente - Encuesta Comercial de Interesados)
    $sql_busca_cred = "SELECT COUNT(ec.id) as total,
                 SUM(CASE WHEN ec.que_busca_agilidad = 1 THEN 1 ELSE 0 END) as agilidad,
                 SUM(CASE WHEN ec.que_busca_cajeros = 1 THEN 1 ELSE 0 END) as cajeros,
                 SUM(CASE WHEN ec.que_busca_banca_linea = 1 THEN 1 ELSE 0 END) as banca,
                 SUM(CASE WHEN ec.que_busca_agencias = 1 THEN 1 ELSE 0 END) as agencias,
                 SUM(CASE WHEN ec.que_busca_credito_rapido = 1 THEN 1 ELSE 0 END) as credito,
                 SUM(CASE WHEN ec.que_busca_tarjeta_debito = 1 THEN 1 ELSE 0 END) as debito,
                 SUM(CASE WHEN ec.que_busca_tarjeta_credito = 1 THEN 1 ELSE 0 END) as tc
                 FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id 
                 WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) 
                 AND ec.interes_conocer_productos = 1 AND ec.id IS NOT NULL";

    try {
        $st_bc = $pdo->prepare($sql_busca_cred);
        $st_bc->execute($params);
        $res_bc = $st_bc->fetch(PDO::FETCH_ASSOC);
        if ($res_bc) {
            $data['evaluacion']['que_busca'] = [
                'total' => (int) $res_bc['total'],
                'agilidad' => (int) $res_bc['agilidad'],
                'cajeros' => (int) $res_bc['cajeros'],
                'banca' => (int) $res_bc['banca'],
                'agencias' => (int) $res_bc['agencias'],
                'credito' => (int) $res_bc['credito'],
                'debito' => (int) $res_bc['debito'],
                'tc' => (int) $res_bc['tc']
            ];
        }
    } catch (\Throwable $e) {
        error_log("Error G3 Eval: " . $e->getMessage());
    }

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

        // G5: Qué busca de la institución
        $sql5 = "SELECT COUNT(ec.id) as total, 
                 SUM(CASE WHEN ec.que_busca_agilidad = 1 THEN 1 ELSE 0 END) as agilidad,
                 SUM(CASE WHEN ec.que_busca_cajeros = 1 THEN 1 ELSE 0 END) as cajeros,
                 SUM(CASE WHEN ec.que_busca_banca_linea = 1 THEN 1 ELSE 0 END) as banca,
                 SUM(CASE WHEN ec.que_busca_agencias = 1 THEN 1 ELSE 0 END) as agencias,
                 SUM(CASE WHEN ec.que_busca_credito_rapido = 1 THEN 1 ELSE 0 END) as credito,
                 SUM(CASE WHEN ec.que_busca_tarjeta_debito = 1 THEN 1 ELSE 0 END) as debito,
                 SUM(CASE WHEN ec.que_busca_tarjeta_credito = 1 THEN 1 ELSE 0 END) as tc
                 FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND ec.id IS NOT NULL";

        $res5 = false;
        try {
            $st5 = $pdo->prepare($sql5);
            $st5->execute($params);
            $res5 = $st5->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Silently ignore if columns do not exist yet
            error_log("Error G5: " . $e->getMessage());
        }

        $total_e5 = $res5 ? (int) $res5['total'] : 0;
        $data['mercado']['que_busca'] = [
            'total' => $total_e5,
            'agilidad' => $res5 ? (int) $res5['agilidad'] : 0,
            'cajeros' => $res5 ? (int) $res5['cajeros'] : 0,
            'banca' => $res5 ? (int) $res5['banca'] : 0,
            'agencias' => $res5 ? (int) $res5['agencias'] : 0,
            'credito' => $res5 ? (int) $res5['credito'] : 0,
            'debito' => $res5 ? (int) $res5['debito'] : 0,
            'tc' => $res5 ? (int) $res5['tc'] : 0,
        ];

        // G6: Acuerdo Logrado
        $sql6 = "SELECT COUNT(ec.id) as total, 
                 SUM(CASE WHEN ec.acuerdo_logrado = 'nueva_cita_campo' THEN 1 ELSE 0 END) as campo,
                 SUM(CASE WHEN ec.acuerdo_logrado = 'nueva_cita_oficina' THEN 1 ELSE 0 END) as oficina,
                 SUM(CASE WHEN ec.acuerdo_logrado = 'reprogramacion' THEN 1 ELSE 0 END) as reprogramacion,
                 SUM(CASE WHEN ec.acuerdo_logrado = 'seguimiento' THEN 1 ELSE 0 END) as seguimiento,
                 SUM(CASE WHEN ec.acuerdo_logrado = 'tasas_competitivas' THEN 1 ELSE 0 END) as tasas,
                 SUM(CASE WHEN ec.acuerdo_logrado = 'otro' THEN 1 ELSE 0 END) as otro
                 FROM encuesta_comercial ec JOIN tarea t ON t.id = ec.tarea_id WHERE t.asesor_id IN ($ph) AND t.estado = 'completada' AND (t.fecha_realizada BETWEEN ? AND ?) AND ec.id IS NOT NULL";

        $res6 = false;
        try {
            $st6 = $pdo->prepare($sql6);
            $st6->execute($params);
            $res6 = $st6->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("Error G6: " . $e->getMessage());
        }

        $total_e6 = $res6 ? (int) $res6['total'] : 0;
        $data['mercado']['acuerdo_logrado'] = [
            'total' => $total_e6,
            'campo' => $res6 ? (int) $res6['campo'] : 0,
            'oficina' => $res6 ? (int) $res6['oficina'] : 0,
            'reprogramacion' => $res6 ? (int) $res6['reprogramacion'] : 0,
            'seguimiento' => $res6 ? (int) $res6['seguimiento'] : 0,
            'tasas' => $res6 ? (int) $res6['tasas'] : 0,
            'otro' => $res6 ? (int) $res6['otro'] : 0,
        ];
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

// ── ACTIVIDAD KPI DATA ──────────────────────────────────────
$data['actividad'] = [
    'asesores_detalle' => [],
    'resumen' => [
        'total_meta' => 0, 'total_avance' => 0, 'total_pct' => 0,
        'total_postergadas' => 0, 'tasa_posterg' => 0,
        'asesores_al_dia' => 0, 'asesores_en_riesgo' => 0, 'asesores_sin_actividad' => 0
    ]
];

if (!empty($target_ids)) {
    try {
        // ── ¿Tiene agenda_dia datos para este período? ────────────
        $stChkAg = $pdo->prepare("SELECT COUNT(*) FROM agenda_dia WHERE asesor_id IN ($ph) AND fecha BETWEEN ? AND ?");
        $stChkAg->execute(array_merge($target_ids, [$fecha_inicio, $fecha_fin]));
        $tieneAgenda = (int)$stChkAg->fetchColumn() > 0;

        // ── Días laborables L-V en el período (para calcular meta) ─
        $dias_lab = 0;
        $dCur = new \DateTime($fecha_inicio);
        $dFin = new \DateTime($fecha_fin);
        while ($dCur <= $dFin) {
            if ((int)$dCur->format('N') < 6) $dias_lab++;
            $dCur->modify('+1 day');
        }
        $dias_lab = max($dias_lab, 1);

        // Días laborables transcurridos hasta HOY dentro del período
        // (para calcular el % real de avance respecto a lo esperado a la fecha)
        $hoy       = new \DateTime(date('Y-m-d'));
        $d_ini_p   = new \DateTime($fecha_inicio);
        $d_fin_p   = new \DateTime($fecha_fin);
        $d_ref_hoy = ($hoy < $d_ini_p) ? $d_ini_p : (($hoy > $d_fin_p) ? $d_fin_p : $hoy);
        $dias_lab_hasta_hoy = 0;
        $dH = clone $d_ini_p;
        while ($dH <= $d_ref_hoy) {
            if ((int)$dH->format('N') < 6) $dias_lab_hasta_hoy++;
            $dH->modify('+1 day');
        }
        $dias_lab_hasta_hoy = max($dias_lab_hasta_hoy, 1);

        if ($tieneAgenda) {
            // Estrategia A: agenda_dia tiene datos → usarla
            $sqlActv = "SELECT
                u.nombre, a.id AS asesor_id, a.meta_tareas_diarias,
                COALESCE(SUM(ad.total_tareas_programadas), (a.meta_tareas_diarias * $dias_lab)) AS meta,
                COALESCE(SUM(ad.tareas_realizadas), 0) AS avance,
                COALESCE(SUM(ad.tareas_postergadas), 0) AS postergadas,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.estado='completada' AND t.tipo_tarea='visita_frio'   AND t.fecha_realizada BETWEEN ? AND ?) AS visitas_frio_act,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.estado='completada' AND t.tipo_tarea='levantamiento' AND t.fecha_realizada BETWEEN ? AND ?) AS levantamientos_act,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.tipo_tarea='prospecto_nuevo' AND t.fecha_programada BETWEEN ? AND ?) AS prospectos_nuevos,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.fuera_de_zona=1 AND t.fecha_programada BETWEEN ? AND ?) AS tareas_fuera_zona
                FROM asesor a
                JOIN usuario u ON u.id = a.usuario_id
                LEFT JOIN agenda_dia ad ON ad.asesor_id = a.id AND ad.fecha BETWEEN ? AND ?
                WHERE a.id IN ($ph)
                GROUP BY a.id, u.nombre, a.meta_tareas_diarias
                ORDER BY u.nombre";
            $actvParams = array_merge(
                [$fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin,
                 $fecha_inicio, $fecha_fin, $fecha_inicio, $fecha_fin,
                 $fecha_inicio, $fecha_fin],
                $target_ids
            );
        } else {
            // Estrategia B: sin agenda_dia → 100% desde tabla tarea
            $sqlActv = "SELECT
                u.nombre, a.id AS asesor_id, a.meta_tareas_diarias,
                (a.meta_tareas_diarias * $dias_lab) AS meta,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.estado='completada' AND t.fecha_realizada BETWEEN ? AND ?) AS avance,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.estado='postergada' AND t.fecha_programada BETWEEN ? AND ?) AS postergadas,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.estado='completada' AND t.tipo_tarea='visita_frio'   AND t.fecha_realizada BETWEEN ? AND ?) AS visitas_frio_act,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.estado='completada' AND t.tipo_tarea='levantamiento' AND t.fecha_realizada BETWEEN ? AND ?) AS levantamientos_act,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.tipo_tarea='prospecto_nuevo' AND t.fecha_programada BETWEEN ? AND ?) AS prospectos_nuevos,
                (SELECT COUNT(*) FROM tarea t WHERE t.asesor_id = a.id AND t.fuera_de_zona=1 AND t.fecha_programada BETWEEN ? AND ?) AS tareas_fuera_zona
                FROM asesor a
                JOIN usuario u ON u.id = a.usuario_id
                WHERE a.id IN ($ph)
                ORDER BY u.nombre";
            $actvParams = array_merge(
                [$fecha_inicio, $fecha_fin, // avance
                 $fecha_inicio, $fecha_fin, // postergadas
                 $fecha_inicio, $fecha_fin, // visitas_frio
                 $fecha_inicio, $fecha_fin, // levantamientos
                 $fecha_inicio, $fecha_fin, // prospectos_nuevos
                 $fecha_inicio, $fecha_fin],// fuera_de_zona
                $target_ids
            );
        }

        $stActv = $pdo->prepare($sqlActv);
        $stActv->execute($actvParams);
        $rows_actv = $stActv->fetchAll(PDO::FETCH_ASSOC);

        $tot_meta = 0; $tot_avance = 0; $tot_posterg = 0;
        $tot_meta_esperada = 0;
        $al_dia = 0; $en_riesgo = 0; $sin_act = 0;

        foreach ($rows_actv as &$ra) {
            // Meta esperada a la fecha de HOY (ritmo diario × días transcurridos)
            $meta_diaria = (int)($ra['meta_tareas_diarias'] ?? 8);
            $ra['meta_esperada_hoy'] = $meta_diaria * $dias_lab_hasta_hoy;

            // pct basado en meta esperada a la fecha (refleja ritmo actual)
            $ra['pct'] = ($ra['meta_esperada_hoy'] > 0)
                ? round(($ra['avance'] / $ra['meta_esperada_hoy']) * 100, 1)
                : 0;

            // Si el período ya terminó (fecha_fin < hoy), usar meta completa
            if ($d_fin_p < $hoy) {
                $ra['pct'] = ($ra['meta'] > 0) ? round(($ra['avance'] / $ra['meta']) * 100, 1) : 0;
            }

            $ra['tasa_posterg'] = ($ra['avance'] + $ra['postergadas'] > 0)
                ? round(($ra['postergadas'] / ($ra['avance'] + $ra['postergadas'])) * 100, 1)
                : 0;
            $tot_meta            += $ra['meta'];
            $tot_meta_esperada   += $ra['meta_esperada_hoy'];
            $tot_avance          += $ra['avance'];
            $tot_posterg         += $ra['postergadas'];
            if ($ra['pct'] >= 90) $al_dia++;
            elseif ($ra['pct'] >= 60) $en_riesgo++;
            else $sin_act++;
        }
        unset($ra);

        $pct_global = ($tot_meta_esperada > 0)
            ? round(($tot_avance / $tot_meta_esperada) * 100, 1)
            : 0;

        $data['actividad']['asesores_detalle'] = $rows_actv;
        $data['actividad']['resumen'] = [
            'total_meta'               => $tot_meta,
            'total_meta_esperada'      => $tot_meta_esperada,
            'total_avance'             => $tot_avance,
            'total_pct'                => $pct_global,
            'total_postergadas'        => $tot_posterg,
            'tasa_posterg'             => ($tot_avance + $tot_posterg > 0) ? round(($tot_posterg / ($tot_avance + $tot_posterg)) * 100, 1) : 0,
            'asesores_al_dia'          => $al_dia,
            'asesores_en_riesgo'       => $en_riesgo,
            'asesores_sin_actividad'   => $sin_act,
            'dias_lab_periodo'         => $dias_lab,
            'dias_lab_hasta_hoy'       => $dias_lab_hasta_hoy,
        ];
    } catch (\Throwable $e) {
        error_log("Error Actividad KPI: " . $e->getMessage());
        if (ini_get('display_errors')) {
            $data['actividad']['_error'] = $e->getMessage();
        }
    }
}

$currentPage = 'reportes_penetracion';
$titulos_kpi = [
    'mercado' => 'Análisis de Penetración y Mercado',
    'interes' => 'Análisis de Interés Comercial',
    'prospeccion' => 'Seguimiento de Prospección',
    'frio' => 'Visitas al Frío y Oficina',
    'evaluacion' => 'Levantamientos de Información',
    'eficiencia' => 'Eficiencia del Proceso Comercial',
    'postventa' => 'Fidelización y Post-Venta (Représtamos)',
    'recuperacion' => 'Gestión de Recuperación',
    'operaciones' => 'Análisis de Operaciones y Desembolsos',
    'actividad' => '📊 Actividad y Cumplimiento de Asesores'
];
$navTitle = ''; $navIcon = ''; $navSubtitle = '';
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
        .nav-arrow { width: 40px; height: 40px; min-width: 40px; display: flex; align-items: center; justify-content: center; background: white; border-radius: 50%; color: #123a6d; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s; border: 1px solid #e2e8f0; cursor: pointer; z-index: 10; }
        .nav-arrow:hover { background: #123a6d; color: white; border-color: #123a6d; }
        .kpi-tabs-wrapper { overflow: hidden; position: relative; flex-grow: 1; }
        .kpi-tabs { display: flex; gap: 10px; flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; scroll-behavior: smooth; padding: 5px 0; }
        .kpi-tabs::-webkit-scrollbar { display: none; }
        .kpi-tab { padding: 10px 22px; border-radius: 999px; text-decoration: none; font-weight: 700; font-size: 12px; border: 1px solid #d1d5db; color: #4b5563; background: #fff; white-space: nowrap; transition: all 0.2s; }
        .kpi-tab.active { background: #123a6d; color: #fff !important; border-color: #123a6d; box-shadow: 0 4px 6px -1px rgba(18, 58, 109, 0.3); }
        .kpi-tab:hover:not(.active) { background: #f3f4f6; color: #1e293b; }

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
            top: 18px;
            max-height: calc(100vh - 36px);
            overflow-y: auto;
            scrollbar-width: thin;
            align-self: flex-start;
        }

        .ia-sidebar.ia-follow {
            position: relative;
            top: auto;
            will-change: transform;
            z-index: 20;
        }

        @media (max-width: 991.98px) {
            .ia-sidebar,
            .ia-sidebar.ia-follow {
                position: relative;
                top: auto;
                max-height: none;
                overflow: visible;
                transform: none !important;
            }
        }

        .insight-pill {
            background: rgba(255, 255, 255, 0.04);
            padding: 14px;
            border-radius: 14px;
            margin-bottom: 10px;
            border-left: 4px solid #ffdd00;
            font-size: 11.5px;
        }

        .kpi-visual-band {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }

        .kpi-speed-card {
            border-radius: 18px;
            padding: 18px;
            min-height: 255px;
            background:
                radial-gradient(circle at 18% 16%, rgba(255, 221, 0, .2), transparent 30%),
                linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            box-shadow: 0 14px 35px rgba(15, 23, 42, .07);
            position: relative;
            overflow: hidden;
        }

        .kpi-speed-card::after {
            content: '';
            position: absolute;
            inset: auto -30px -45px auto;
            width: 145px;
            height: 145px;
            border-radius: 999px;
            background: rgba(18, 58, 109, .06);
        }

        .kpi-speed-card .speed-title {
            font-size: 11px;
            font-weight: 900;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: .6px;
            position: relative;
            z-index: 1;
        }

        .kpi-speed-card .speed-caption {
            font-size: 11px;
            color: #64748b;
            font-weight: 700;
            text-align: center;
            margin-top: -12px;
            position: relative;
            z-index: 1;
        }

        .kpi-flow-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 22px;
        }

        .kpi-flow-step {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid var(--flow-color, #3b82f6);
            border-radius: 14px;
            padding: 12px 14px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .04);
        }

        .kpi-flow-step small {
            display: block;
            color: #64748b;
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .kpi-flow-step strong {
            display: block;
            color: #0f172a;
            font-size: 1.45rem;
            line-height: 1.1;
            margin-top: 4px;
        }

        @media (max-width: 992px) {
            .kpi-visual-band { grid-template-columns: 1fr; }
            .kpi-flow-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 576px) {
            .kpi-flow-strip { grid-template-columns: 1fr; }
        }
    </style>
</head>

<body>

    <?php require_once $es_gerente ? '_sidebar.php' : '_sidebar_supervisor.php'; ?>

    <?php if ($es_gerente): ?>
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
    <?php endif; ?>
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

            <div class="kpi-nav-container d-flex align-items-center gap-2">
                <button type="button" class="nav-arrow" onclick="scrollKpiTabs(-250)" title="Desplazar Izquierda">
                    <i class="fas fa-chevron-left"></i>
                </button>

                <div class="kpi-tabs-wrapper">
                    <div class="kpi-tabs" id="kpiTabsContainer">
                        <a href="?view=actividad<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'actividad') ? 'active' : '' ?>" style="<?= ($view === 'actividad') ? '' : 'background:linear-gradient(135deg,#e0e7ff,#dbeafe);color:#3730a3;' ?>">
                            <i class="fas fa-bolt me-1"></i>ACTIVIDAD</a>
                        <a href="?view=mercado<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'mercado') ? 'active' : '' ?>">PENETRACIÓN</a>
                        <a href="?view=interes<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'interes') ? 'active' : '' ?>">INTERÉS</a>
                        <a href="?view=prospeccion<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'prospeccion') ? 'active' : '' ?>">PROSPECCIÓN</a>
                        <a href="?view=frio<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'frio') ? 'active' : '' ?>">VISITAS AL FRÍO</a>
                        <a href="?view=evaluacion<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'evaluacion') ? 'active' : '' ?>">LEVANTAMIENTOS</a>
                        <a href="?view=eficiencia<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'eficiencia') ? 'active' : '' ?>">EFICIENCIA</a>
                        <a href="?view=postventa<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'postventa') ? 'active' : '' ?>">POST-VENTA</a>
                        <a href="?view=recuperacion<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'recuperacion') ? 'active' : '' ?>">RECUPERACIÓN</a>
                        <a href="?view=operaciones<?= $filtros_query ?>"
                            class="kpi-tab <?= ($view === 'operaciones') ? 'active' : '' ?>">OPERACIONES</a>
                    </div>
                </div>

                <button type="button" class="nav-arrow" onclick="scrollKpiTabs(250)" title="Desplazar Derecha">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>

            <div class="row g-4">
                <div class="<?= in_array($view, ['operaciones']) || !$es_gerente ? 'col-lg-12' : 'col-lg-9' ?>">
                    <?php if ($view === 'mercado'): ?>
                        <?php
                        $mercado_total = (int)($data['mercado']['cobertura']['total'] ?? 0);
                        $mercado_clientes = (int)($data['mercado']['cobertura']['valor'] ?? 0);
                        $mercado_prospectos = max(0, $mercado_total - $mercado_clientes);
                        $mercado_competencia = (int)($data['mercado']['participacion']['competencia'] ?? 0);
                        ?>
                        <div class="kpi-visual-band">
                            <div class="kpi-speed-card">
                                <div class="speed-title"><i class="fas fa-gauge-high me-2 text-primary"></i>Cobertura institucional</div>
                                <div id="gauge-mercado-cobertura" style="min-height:205px;"></div>
                                <div class="speed-caption"><?= $mercado_clientes ?> clientes identificados de <?= $mercado_total ?> visitas</div>
                            </div>
                            <div class="kpi-speed-card">
                                <div class="speed-title"><i class="fas fa-chart-pie me-2 text-success"></i>Participación propia</div>
                                <div id="gauge-mercado-share" style="min-height:205px;"></div>
                                <div class="speed-caption">Balance nosotros vs competencia en el periodo</div>
                            </div>
                            <div class="kpi-speed-card">
                                <div class="speed-title"><i class="fas fa-bolt me-2 text-warning"></i>Flujo de extracción</div>
                                <div id="gauge-mercado-flujo" style="min-height:205px;"></div>
                                <div class="speed-caption"><?= $mercado_prospectos ?> prospectos nuevos detectados</div>
                            </div>
                        </div>
                        <div class="kpi-flow-strip">
                            <div class="kpi-flow-step" style="--flow-color:#3b82f6;"><small>Extracciones</small><strong><?= $mercado_total ?></strong></div>
                            <div class="kpi-flow-step" style="--flow-color:#10b981;"><small>Clientes institución</small><strong><?= $mercado_clientes ?></strong></div>
                            <div class="kpi-flow-step" style="--flow-color:#f59e0b;"><small>Prospectos</small><strong><?= $mercado_prospectos ?></strong></div>
                            <div class="kpi-flow-step" style="--flow-color:#ef4444;"><small>Competencia</small><strong><?= $mercado_competencia ?></strong></div>
                        </div>
                        <!-- LOS 4 BLOQUES DE PENETRACIÓN (G1-G4) -->
                        <div class="segment-card" id="segment-g1">
                            <div class="sec-header">
                                <div class="sec-title">1. Cobertura Institucional</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('g1', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('g1', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-g1" style="width: 240px;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-g2">
                            <div class="sec-header">
                                <div class="sec-title">2. Interés Prospectos (Nuevos)</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('g2', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('g2', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-g2" style="width: 100%;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-g3">
                            <div class="sec-header">
                                <div class="sec-title">3. Tenencia Productos (Clientes)</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('g3', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('g3', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-g3" style="width: 100%;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-g4">
                            <div class="sec-header">
                                <div class="sec-title">4. Cuota de Mercado</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('g4', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('g4', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-g4" style="width: 100%;"></div>
                            </div>
                        </div>

                        <div class="segment-card" id="segment-g5">
                            <div class="sec-header">
                                <div class="sec-title">5. ¿Qué busca de una institución financiera?</div>
                                <div class="view-toggle">
                                    <button class="view-btn" onclick="toggleView('g5', 'table')">TABLA</button>
                                    <button class="view-btn active" onclick="toggleView('g5', 'chart')">GRÁFICA</button>
                                </div>
                            </div>
                            <div class="table-view">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Atributo</th>
                                            <th class="text-center">Total Votos</th>
                                            <th class="text-end">% (s/ encuestados)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $qb = $data['mercado']['que_busca'];
                                        $tot_qb = $qb['total'] > 0 ? $qb['total'] : 1;
                                        $items = ['Agilidad' => 'agilidad', 'Cajeros' => 'cajeros', 'Banca en línea' => 'banca', 'Agencias en su sector' => 'agencias', 'Crédito rápido' => 'credito', 'Tarjeta débito' => 'debito', 'Tarjeta crédito' => 'tc'];
                                        foreach ($items as $label => $key):
                                            $val = $qb[$key];
                                            $pct = round(($val / $tot_qb) * 100, 1);
                                            ?>
                                            <tr>
                                                <td><?= $label ?></td>
                                                <td class="text-center"><?= $val ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#e0e7ff; color:#4f46e5;"><?= $pct ?>%</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="text-muted mt-2 small text-center">Total Encuestados: <?= $qb['total'] ?></div>
                            </div>
                            <div class="chart-view active">
                                <div id="chart-g5" style="width: 100%;"></div>
                            </div>
                        </div>

                        <div class="segment-card" id="segment-g6">
                            <div class="sec-header">
                                <div class="sec-title">6. Acuerdos Logrados</div>
                                <div class="view-toggle">
                                    <button class="view-btn" onclick="toggleView('g6', 'table')">TABLA</button>
                                    <button class="view-btn active" onclick="toggleView('g6', 'chart')">GRÁFICA</button>
                                </div>
                            </div>
                            <div class="table-view">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Tipo de Acuerdo</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-end">% (s/ encuestados)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $al = $data['mercado']['acuerdo_logrado'];
                                        $tot_al = $al['total'] > 0 ? $al['total'] : 1;
                                        $items_al = ['Cita en Campo' => 'campo', 'Cita en Oficina' => 'oficina', 'Reprogramación' => 'reprogramacion', 'Seguimiento' => 'seguimiento', 'Tasas Competitivas' => 'tasas', 'Otro' => 'otro'];
                                        foreach ($items_al as $label => $key):
                                            $val = $al[$key];
                                            $pct = round(($val / $tot_al) * 100, 1);
                                            ?>
                                            <tr>
                                                <td><?= $label ?></td>
                                                <td class="text-center"><?= $val ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#f3e8ff; color:#7e22ce;"><?= $pct ?>%</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="text-muted mt-2 small text-center">Total Encuestados: <?= $al['total'] ?></div>
                            </div>
                            <div class="chart-view active">
                                <div id="chart-g6" style="width: 100%;"></div>
                            </div>
                        </div>
                    <?php elseif ($view === 'interes'): ?>
                        <?php
                        $interes_total = (int)($data['interes']['general']['total'] ?? 0);
                        $interes_si = (int)($data['interes']['general']['si'] ?? 0);
                        $interes_no = (int)($data['interes']['general']['no'] ?? 0);
                        $productos_interes = [
                            'Ahorro' => (int)($data['interes']['productos']['ahorro'] ?? 0),
                            'Crédito' => (int)($data['interes']['productos']['credito'] ?? 0),
                            'Inversión' => (int)($data['interes']['productos']['inversion'] ?? 0),
                        ];
                        arsort($productos_interes);
                        $producto_top = key($productos_interes) ?: 'Sin datos';
                        $destinos_top = $data['interes']['destinos'] ?? [];
                        arsort($destinos_top);
                        $destino_top = key($destinos_top) ?: 'Sin destino';
                        ?>
                        <div class="kpi-visual-band">
                            <div class="kpi-speed-card">
                                <div class="speed-title"><i class="fas fa-gauge-high me-2 text-primary"></i>Interés captado</div>
                                <div id="gauge-interes-general" style="min-height:205px;"></div>
                                <div class="speed-caption"><?= $interes_si ?> interesados de <?= $interes_total ?> extracciones</div>
                            </div>
                            <div class="kpi-speed-card">
                                <div class="speed-title"><i class="fas fa-route me-2 text-success"></i>Producto dominante</div>
                                <div id="gauge-interes-producto" style="min-height:205px;"></div>
                                <div class="speed-caption">Mayor tracción: <?= htmlspecialchars($producto_top) ?></div>
                            </div>
                            <div class="kpi-speed-card">
                                <div class="speed-title"><i class="fas fa-filter me-2 text-warning"></i>Destino principal</div>
                                <div id="gauge-interes-destino" style="min-height:205px;"></div>
                                <div class="speed-caption"><?= htmlspecialchars($destino_top) ?></div>
                            </div>
                        </div>
                        <div class="kpi-flow-strip">
                            <div class="kpi-flow-step" style="--flow-color:#3b82f6;"><small>Extracciones</small><strong><?= $interes_total ?></strong></div>
                            <div class="kpi-flow-step" style="--flow-color:#10b981;"><small>Con interés</small><strong><?= $interes_si ?></strong></div>
                            <div class="kpi-flow-step" style="--flow-color:#64748b;"><small>Sin interés</small><strong><?= $interes_no ?></strong></div>
                            <div class="kpi-flow-step" style="--flow-color:#f59e0b;"><small>Base destinos</small><strong><?= (int)($data['interes']['destinos_base_si'] ?? 0) ?></strong></div>
                        </div>
                        <!-- NUEVOS BLOQUES DE INTERÉS (I1-I3) -->
                        <div class="segment-card" id="segment-i1">
                            <div class="sec-header">
                                <div class="sec-title">1. Disposición Comercial (SI/NO)</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('i1', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('i1', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-i1" style="width: 240px;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-i2">
                            <div class="sec-header">
                                <div class="sec-title">2. Preferencia de Productos</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('i2', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('i2', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-i2" style="width: 100%;"></div>
                            </div>
                        </div>
                        <div class="segment-card" id="segment-i3">
                            <div class="sec-header">
                                <div class="sec-title">3. Destino del Crédito (Estratégico)</div>
                                <div class="view-toggle"><button class="view-btn"
                                        onclick="toggleView('i3', 'table')">TABLA</button><button class="view-btn active"
                                        onclick="toggleView('i3', 'chart')">GRÁFICA</button></div>
                            </div>
                            <div class="table-view">
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
                            <div class="chart-view active">
                                <div id="chart-i3" style="width: 100%;"></div>
                            </div>
                        </div>
                    <?php elseif ($view === 'prospeccion'): ?>
                        <div class="segment-card shadow-sm mb-4" id="segment-p1" style="border-left: 5px solid #3b82f6;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-bullseye me-2 text-primary"></i> Avance de Contactos
                                    de Prospección</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-prospeccion" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Visitas Hechas</small>
                                                <h3 class="fw-bold mb-0"><?= $data['prospeccion']['avance_total'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Metas Asignadas</small>
                                                <h3 class="fw-bold mb-0 text-muted">
                                                    <?= $data['prospeccion']['meta_total'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Eficiencia del
                                                    Equipo</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['prospeccion']['pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Visitas</th>
                                        <th class="text-center">Meta</th>
                                        <th class="text-end">%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($data['prospeccion']['detalle_asesores'] as $row):
                                        $pct2 = $row['meta'] > 0 ? round($row['avance'] * 100 / $row['meta'], 1) : 0;
                                        $color2 = $pct2 >= 100 ? '#10b981' : ($pct2 >= 70 ? '#f59e0b' : '#ef4444'); ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['nombre']) ?></td>
                                            <td class="text-center"><?= $row['avance'] ?></td>
                                            <td class="text-center"><?= $row['meta'] ?></td>
                                            <td class="text-end"><span class="pct-badge"
                                                    style="background:<?= $color2 ?>20;color:<?= $color2 ?>;"><?= $pct2 ?>%</span>
                                            </td>
                                        </tr><?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif ($view === 'frio'): ?>
                        <div class="segment-card shadow-sm mb-4" id="segment-frio" style="border-left: 5px solid #f59e0b;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-snowflake me-2 text-warning"></i> NÚMERO DE VISITAS
                                    AL FRÍO / PROSPECTOS NUEVOS</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-frio" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Visitas al Frío</small>
                                                <h3 class="fw-bold mb-0 text-warning"><?= $data['frio']['visitas_frio'] ?>
                                                </h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Total Contactos</small>
                                                <h3 class="fw-bold mb-0 text-muted"><?= $data['frio']['total_contactos'] ?>
                                                </h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Prospección en
                                                    Frío</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['frio']['frio_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Visitas al Frío</th>
                                        <th class="text-center">Total Contactos</th>
                                        <th class="text-end">% Frío</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['frio']['detalle_asesores'])):
                                        foreach ($data['frio']['detalle_asesores'] as $row):
                                            $pct_frio = $row['total_contactos'] > 0 ? round(($row['visitas_frio'] / $row['total_contactos']) * 100, 1) : 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold text-warning"><?= $row['visitas_frio'] ?></td>
                                                <td class="text-center"><?= $row['total_contactos'] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#fef3c7; color:#d97706;"><?= $pct_frio ?>%</span></td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="segment-card shadow-sm mb-4" id="segment-oficina"
                            style="border-left: 5px solid #8b5cf6;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-building me-2" style="color: #8b5cf6;"></i> NÚMERO
                                    DE VISITAS EN OFICINA / ENCUESTADOS WEB</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-oficina" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Encuestados Web</small>
                                                <h3 class="fw-bold mb-0" style="color: #8b5cf6;">
                                                    <?= $data['frio']['visitas_oficina'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Total Contactos</small>
                                                <h3 class="fw-bold mb-0 text-muted"><?= $data['frio']['total_contactos'] ?>
                                                </h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Prospección
                                                    Web/Oficina</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['frio']['oficina_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Encuestados Web</th>
                                        <th class="text-center">Total Contactos</th>
                                        <th class="text-end">% Web/Oficina</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['frio']['detalle_asesores'])):
                                        foreach ($data['frio']['detalle_asesores'] as $row):
                                            $v_oficina = $row['total_contactos'] - $row['visitas_frio'];
                                            $pct_oficina = $row['total_contactos'] > 0 ? round(($v_oficina / $row['total_contactos']) * 100, 1) : 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold" style="color: #8b5cf6;"><?= $v_oficina ?></td>
                                                <td class="text-center"><?= $row['total_contactos'] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#ede9fe; color:#6d28d9;"><?= $pct_oficina ?>%</span></td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="segment-card shadow-sm mb-4" id="segment-leads" style="border-left: 5px solid #10b981;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-phone-alt me-2" style="color: #10b981;"></i> NÚMERO
                                    DE LEADS / LLAMADAS TELEFÓNICAS</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-leads" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Leads/Llamadas</small>
                                                <h3 class="fw-bold mb-0" style="color: #10b981;">
                                                    <?= $data['frio']['visitas_leads'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Total Contactos</small>
                                                <h3 class="fw-bold mb-0 text-muted"><?= $data['frio']['total_contactos'] ?>
                                                </h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Leads
                                                    Telefónicos</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['frio']['leads_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Leads / Llamadas</th>
                                        <th class="text-center">Total Contactos</th>
                                        <th class="text-end">% Leads</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['frio']['detalle_asesores'])):
                                        foreach ($data['frio']['detalle_asesores'] as $row):
                                            $pct_leads = $row['total_contactos'] > 0 ? round(($row['visitas_leads'] / $row['total_contactos']) * 100, 1) : 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold" style="color: #10b981;"><?= $row['visitas_leads'] ?>
                                                </td>
                                                <td class="text-center"><?= $row['total_contactos'] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#d1fae5; color:#059669;"><?= $pct_leads ?>%</span></td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="segment-card shadow-sm mb-4" id="segment-seguidores"
                            style="border-left: 5px solid #ef4444;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-users me-2" style="color: #ef4444;"></i> NÚMERO DE
                                    SEGUIDORES</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-seguidores" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Seguidores</small>
                                                <h3 class="fw-bold mb-0" style="color: #ef4444;">
                                                    <?= $data['frio']['visitas_seguidores'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Total Contactos</small>
                                                <h3 class="fw-bold mb-0 text-muted"><?= $data['frio']['total_contactos'] ?>
                                                </h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Seguidores</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['frio']['seguidores_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Seguidores</th>
                                        <th class="text-center">Total Contactos</th>
                                        <th class="text-end">% Seguidores</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['frio']['detalle_asesores'])):
                                        foreach ($data['frio']['detalle_asesores'] as $row):
                                            $pct_seguidores = $row['total_contactos'] > 0 ? round(($row['visitas_seguidores'] / $row['total_contactos']) * 100, 1) : 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold" style="color: #ef4444;">
                                                    <?= $row['visitas_seguidores'] ?></td>
                                                <td class="text-center"><?= $row['total_contactos'] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#fee2e2; color:#b91c1c;"><?= $pct_seguidores ?>%</span>
                                                </td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif ($view === 'evaluacion'): ?>
                        <div class="segment-card shadow-sm mb-4" id="segment-evaluacion"
                            style="border-left: 5px solid #0ea5e9;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-clipboard-check me-2" style="color: #0ea5e9;"></i>
                                    NÚMERO DE LEVANTAMIENTOS DE EVALUACIÓN</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-evaluacion" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Levantamientos Hechos</small>
                                                <h3 class="fw-bold mb-0" style="color: #0ea5e9;">
                                                    <?= $data['evaluacion']['levantamientos'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Total Interesados</small>
                                                <h3 class="fw-bold mb-0 text-muted">
                                                    <?= $data['evaluacion']['total_interesados'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Efectividad en
                                                    Levantamiento</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['evaluacion']['evaluacion_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Levantamientos</th>
                                        <th class="text-center">Total Interesados</th>
                                        <th class="text-center">Pendientes</th>
                                        <th class="text-end">% Levantamiento</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['evaluacion']['detalle_asesores'])):
                                        foreach ($data['evaluacion']['detalle_asesores'] as $row):
                                            $pct_ev = $row['total_interesados'] > 0 ? round(($row['levantamientos'] / $row['total_interesados']) * 100, 1) : 0;
                                            $pendientes = $row['total_interesados'] - $row['levantamientos'];
                                            if ($pendientes < 0)
                                                $pendientes = 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold" style="color: #0ea5e9;">
                                                    <?= $row['levantamientos'] ?></td>
                                                <td class="text-center"><?= $row['total_interesados'] ?></td>
                                                <td class="text-center fw-bold text-danger"><?= $pendientes ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#e0f2fe; color:#0369a1;"><?= $pct_ev ?>%</span></td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="segment-card shadow-sm mb-4" id="segment-aprobados"
                            style="border-left: 5px solid #8b5cf6;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-check-circle me-2" style="color: #8b5cf6;"></i>
                                    NÚMERO DE CRÉDITOS APROBADOS</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-aprobados" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Créditos Aprobados</small>
                                                <h3 class="fw-bold mb-0" style="color: #8b5cf6;">
                                                    <?= $data['evaluacion']['creditos_aprobados'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Levantamientos Hechos</small>
                                                <h3 class="fw-bold mb-0 text-muted">
                                                    <?= $data['evaluacion']['levantamientos'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Aprobación</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['evaluacion']['aprobados_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Créditos Aprobados</th>
                                        <th class="text-center">Levantamientos</th>
                                        <th class="text-end">% Aprobación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['evaluacion']['detalle_asesores'])):
                                        foreach ($data['evaluacion']['detalle_asesores'] as $row):
                                            $pct_aprob = $row['levantamientos'] > 0 ? round(($row['creditos_aprobados'] / $row['levantamientos']) * 100, 1) : 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold" style="color: #8b5cf6;">
                                                    <?= $row['creditos_aprobados'] ?></td>
                                                <td class="text-center"><?= $row['levantamientos'] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                         style="background:#ede9fe; color:#6d28d9;"><?= $pct_aprob ?>%</span></td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="segment-card shadow-sm mb-4" id="segment-global-conv"
                            style="border-left: 5px solid #10b981;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-chart-line me-2" style="color: #10b981;"></i>
                                    EFECTIVIDAD GLOBAL (APROBADOS / INTERÉS)</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-global-conv" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Aprobados</small>
                                                <h3 class="fw-bold mb-0" style="color: #10b981;">
                                                    <?= $data['evaluacion']['creditos_aprobados'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Interesados</small>
                                                <h3 class="fw-bold mb-0 text-muted">
                                                    <?= $data['evaluacion']['total_interesados'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Conversión Final de Crédito</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['evaluacion']['global_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="segment-card" id="segment-g3eval">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-search-dollar me-2 text-info"></i> ¿Qué busca el
                                    cliente?</div>
                                <div class="view-toggle">
                                    <button class="view-btn active" onclick="toggleView('g3eval', 'table')">TABLA</button>
                                    <button class="view-btn" onclick="toggleView('g3eval', 'chart')">GRÁFICA</button>
                                </div>
                            </div>
                            <div class="table-view active">
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Atributo</th>
                                            <th class="text-center">Total Votos</th>
                                            <th class="text-end">% (s/ encuestados)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $qb = $data['evaluacion']['que_busca'];
                                        $tot_qb = max(1, $qb['total']);
                                        $items = ['Agilidad' => 'agilidad', 'Cajeros' => 'cajeros', 'Banca en línea' => 'banca', 'Agencias en su sector' => 'agencias', 'Crédito rápido' => 'credito', 'Tarjeta débito' => 'debito', 'Tarjeta crédito' => 'tc'];
                                        foreach ($items as $label => $key):
                                            $val = $qb[$key];
                                            $pct = round(($val / $tot_qb) * 100, 1);
                                            ?>
                                            <tr>
                                                <td><?= $label ?></td>
                                                <td class="text-center"><?= $val ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#e0f2fe; color:#0ea5e9;"><?= $pct ?>%</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div class="text-muted mt-2 small text-center">Total Encuestados (Interesados):
                                    <?= $qb['total'] ?></div>
                            </div>
                            <div class="chart-view">
                                <div id="chart-g3eval" style="width: 100%;"></div>
                            </div>
                        </div>
                    <?php elseif ($view === 'recuperacion'): ?>
                        <div class="segment-card shadow-sm mb-4" id="segment-recuperacion"
                            style="border-left: 5px solid #ef4444;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-hand-holding-usd me-2" style="color: #ef4444;"></i>
                                    NÚMERO DE VISITAS DE RECUPERACIÓN</div>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-recuperacion" style="min-height:250px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Recuperaciones</small>
                                                <h3 class="fw-bold mb-0" style="color: #ef4444;">
                                                    <?= $data['recuperacion']['visitas_recuperacion'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-3 rounded-4 bg-light text-center"><small
                                                    class="filter-label">Total Visitas</small>
                                                <h3 class="fw-bold mb-0 text-muted">
                                                    <?= $data['recuperacion']['total_visitas'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);">
                                                <small class="opacity-75 fw-bold text-uppercase">Tasa de Gestión de
                                                    Recuperación</small>
                                                <h2 class="fw-black mb-0" style="font-size:3rem;">
                                                    <?= $data['recuperacion']['recuperacion_pct'] ?>%</h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <table class="kpi-table mt-3">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Recuperaciones</th>
                                        <th class="text-center">Total Visitas</th>
                                        <th class="text-end">% Recuperación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($data['recuperacion']['detalle_asesores'])):
                                        foreach ($data['recuperacion']['detalle_asesores'] as $row):
                                            $pct_rec = $row['total'] > 0 ? round(($row['rec'] / $row['total']) * 100, 1) : 0; ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center fw-bold" style="color: #ef4444;"><?= $row['rec'] ?></td>
                                                <td class="text-center"><?= $row['total'] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:#fee2e2; color:#b91c1c;"><?= $pct_rec ?>%</span></td>
                                            </tr><?php endforeach; else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center opacity-50">Sin datos en el periodo</td>
                                        </tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif ($view === 'actividad'): ?>
                        <!-- ════════════ VISTA ACTIVIDAD ════════════ -->

                        <!-- KPI SUMMARY CARDS -->
                        <?php
                        $res_act  = $data['actividad']['resumen'];
                        $det_act  = $data['actividad']['asesores_detalle'];
                        $pct_gbl  = $res_act['total_pct'];
                        $gradColor = $pct_gbl >= 90 ? '#10b981' : ($pct_gbl >= 60 ? '#f59e0b' : '#ef4444');
                        // Calcular totales de los campos nuevos directamente del detalle
                        $tot_prospectos_nuevos = array_sum(array_column($det_act, 'prospectos_nuevos'));
                        $tot_fuera_zona        = array_sum(array_column($det_act, 'tareas_fuera_zona'));
                        ?>
                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-2">
                                <div class="segment-card text-center py-3 px-2" style="border-top:4px solid #3b82f6;">
                                    <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Visitas Hechas</div>
                                    <div style="font-size:2rem;font-weight:900;color:#1e293b;line-height:1.1;"><?= $res_act['total_avance'] ?></div>
                                    <div style="font-size:10px;color:#94a3b8;">de <?= $res_act['total_meta_esperada'] ?> esperadas a hoy</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="segment-card text-center py-3 px-2" style="border-top:4px solid <?= $gradColor ?>;">
                                    <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">% Cumplimiento</div>
                                    <div style="font-size:2rem;font-weight:900;color:<?= $gradColor ?>;line-height:1.1;"><?= $pct_gbl ?>%</div>
                                    <div style="font-size:10px;color:#94a3b8;">sobre meta actual</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="segment-card text-center py-3 px-2" style="border-top:4px solid #f59e0b;">
                                    <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Tasa Postergación</div>
                                    <div style="font-size:2rem;font-weight:900;color:#d97706;line-height:1.1;"><?= $res_act['tasa_posterg'] ?>%</div>
                                    <div style="font-size:10px;color:#94a3b8;"><?= $res_act['total_postergadas'] ?> postergadas</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="segment-card text-center py-3 px-2" style="border-top:4px solid #8b5cf6;">
                                    <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Asesores al Día</div>
                                    <div style="font-size:2rem;font-weight:900;color:#7c3aed;line-height:1.1;"><?= $res_act['asesores_al_dia'] ?></div>
                                    <div style="font-size:10px;color:#94a3b8;">≥ 90% ritmo esperado</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="segment-card text-center py-3 px-2" style="border-top:4px solid #10b981;">
                                    <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Prospectos Nuevos</div>
                                    <div style="font-size:2rem;font-weight:900;color:#059669;line-height:1.1;"><?= $tot_prospectos_nuevos ?></div>
                                    <div style="font-size:10px;color:#94a3b8;">tipo prospecto_nuevo</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-2">
                                <div class="segment-card text-center py-3 px-2" style="border-top:4px solid #ef4444;">
                                    <div style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Fuera de Zona</div>
                                    <div style="font-size:2rem;font-weight:900;color:#dc2626;line-height:1.1;"><?= $tot_fuera_zona ?></div>
                                    <div style="font-size:10px;color:#94a3b8;">tareas fuera_de_zona=1</div>
                                </div>
                            </div>
                        </div>

                        <!-- STATUS PILLS -->
                        <div class="segment-card mb-4">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-traffic-light me-2"></i> Estado del Equipo</div>
                            </div>
                            <div class="row g-3">
                                <div class="col-4">
                                    <div class="rounded-4 p-4 text-center" style="background:linear-gradient(135deg,#10b981,#059669);">
                                        <div style="font-size:2rem;font-weight:900;color:#fff;"><?= $res_act['asesores_al_dia'] ?></div>
                                        <div style="font-size:11px;color:rgba(255,255,255,.8);font-weight:700;text-transform:uppercase;"><i class="fas fa-check-circle me-1"></i> Al Día</div>
                                        <div style="font-size:10px;color:rgba(255,255,255,.6);">≥ 90% ritmo actual</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="rounded-4 p-4 text-center" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                                        <div style="font-size:2rem;font-weight:900;color:#fff;"><?= $res_act['asesores_en_riesgo'] ?></div>
                                        <div style="font-size:11px;color:rgba(255,255,255,.8);font-weight:700;text-transform:uppercase;"><i class="fas fa-exclamation-triangle me-1"></i> En Riesgo</div>
                                        <div style="font-size:10px;color:rgba(255,255,255,.6);">60% – 89% ritmo actual</div>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="rounded-4 p-4 text-center" style="background:linear-gradient(135deg,#ef4444,#b91c1c);">
                                        <div style="font-size:2rem;font-weight:900;color:#fff;"><?= $res_act['asesores_sin_actividad'] ?></div>
                                        <div style="font-size:11px;color:rgba(255,255,255,.8);font-weight:700;text-transform:uppercase;"><i class="fas fa-times-circle me-1"></i> Crítico</div>
                                        <div style="font-size:10px;color:rgba(255,255,255,.6);">< 60% ritmo actual</div>
                                    </div>
                                </div>
                            </div>
                        </div>



                        <!-- ════ DASHBOARD TACÓMETROS ════ -->
                        <?php if (!empty($det_act)):
                        $act_nombres    = array_map(fn($r) => addslashes($r['nombre']), $det_act);
                        $act_avance     = array_column($det_act, 'avance');
                        $act_meta       = array_column($det_act, 'meta');
                        $act_posterg    = array_column($det_act, 'tasa_posterg');
                        $act_prospectos = array_column($det_act, 'prospectos_nuevos');
                        $act_pcts       = array_column($det_act, 'pct');
                        $criticos_list  = array_filter($det_act, fn($r) => $r['pct'] < 60);
                        ?>

                        <!-- FILA 1: 3 Tacómetros Principales -->
                        <div class="row g-4 mb-4">
                            <!-- VELOCÍMETRO GLOBAL -->
                            <div class="col-md-4">
                                <div class="segment-card h-100 text-center" style="border-top:5px solid <?= $gradColor ?>;">
                                    <div class="sec-header"><div class="sec-title"><i class="fas fa-gauge-high me-2" style="color:<?= $gradColor ?>;"></i> Cumplimiento Global</div></div>
                                    <div id="gauge-cumplimiento-global" style="min-height:240px;"></div>
                                    <div style="margin-top:-12px;padding:0 16px 16px;">
                                        <div class="rounded-3 py-2 px-3 fw-bold small" style="background:<?= $pct_gbl>=90 ? '#ecfdf5' : ($pct_gbl>=60 ? '#fffbeb' : '#fef2f2') ?>;color:<?= $gradColor ?>;">
                                            <?php if($pct_gbl>=90): ?>✅ Equipo en ÓPTIMO rendimiento
                                            <?php elseif($pct_gbl>=60): ?>⚠️ Equipo REQUIERE atención
                                            <?php else: ?>🚨 ALERTA: Rendimiento CRÍTICO
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:11px;color:#94a3b8;margin-top:6px;"><?= $res_act['total_avance'] ?> realizadas de <?= $res_act['total_meta_esperada'] ?> esperadas a hoy</div>
                                    </div>
                                </div>
                            </div>

                            <!-- TACÓMETRO POSTERGACIÓN -->
                            <div class="col-md-4">
                                <?php $pc = $res_act['tasa_posterg']; $pcCol = $pc<=10?'#10b981':($pc<=25?'#f59e0b':'#ef4444'); ?>
                                <div class="segment-card h-100 text-center" style="border-top:5px solid <?= $pcCol ?>;">
                                    <div class="sec-header"><div class="sec-title"><i class="fas fa-clock-rotate-left me-2" style="color:<?= $pcCol ?>;"></i> Tasa Postergación</div></div>
                                    <div id="gauge-postergacion" style="min-height:240px;"></div>
                                    <div style="margin-top:-12px;padding:0 16px 16px;">
                                        <div class="rounded-3 py-2 px-3 fw-bold small" style="background:<?= $pc<=10?'#ecfdf5':($pc<=25?'#fffbeb':'#fef2f2') ?>;color:<?= $pcCol ?>;">
                                            <?php if($pc<=10): ?>✅ Postergación BAJO CONTROL
                                            <?php elseif($pc<=25): ?>⚠️ Postergación MODERADA — revisar
                                            <?php else: ?>🚨 Postergación EXCESIVA — intervenir
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:11px;color:#94a3b8;margin-top:6px;"><?= $res_act['total_postergadas'] ?> tareas postergadas en total</div>
                                    </div>
                                </div>
                            </div>

                            <!-- TACÓMETRO PROSPECTOS NUEVOS -->
                            <div class="col-md-4">
                                <div class="segment-card h-100 text-center" style="border-top:5px solid #10b981;">
                                    <div class="sec-header"><div class="sec-title"><i class="fas fa-user-plus me-2" style="color:#059669;"></i> Captación de Prospectos</div></div>
                                    <div id="gauge-prospectos" style="min-height:240px;"></div>
                                    <div style="margin-top:-12px;padding:0 16px 16px;">
                                        <div class="rounded-3 py-2 px-3 fw-bold small" style="background:#ecfdf5;color:#059669;">
                                            <?= $tot_prospectos_nuevos ?> prospectos nuevos captados
                                        </div>
                                        <div style="font-size:11px;color:#94a3b8;margin-top:6px;"><?= $tot_fuera_zona ?> tareas fuera de zona detectadas</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- FILA 2: Velocímetros individuales por asesor -->
                        <div class="segment-card mb-4">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-users me-2 text-primary"></i> Velocímetro Individual por Asesor</div>
                                <span style="font-size:11px;color:#94a3b8;">Verde ≥90% · Ámbar 60–89% · Rojo &lt;60%</span>
                            </div>
                            <div class="row g-2 pb-2">
                                <?php foreach ($det_act as $i_a => $ra_a):
                                    $pct_a = $ra_a['pct'];
                                    $col_a = $pct_a>=90?'#10b981':($pct_a>=60?'#f59e0b':'#ef4444');
                                    $lbl_a = $pct_a>=90?'ÓPTIMO':($pct_a>=60?'EN RIESGO':'CRÍTICO');
                                    $nom_corto = explode(' ', $ra_a['nombre'])[0];
                                ?>
                                <div class="col-6 col-md-4 col-lg-2 text-center" style="min-width:130px;">
                                    <div id="gauge-asesor-<?= $i_a ?>" style="min-height:150px;"></div>
                                    <div style="font-size:11px;font-weight:800;color:#1e293b;margin-top:-14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding:0 4px;"><?= htmlspecialchars($nom_corto) ?></div>
                                    <span style="font-size:9px;background:<?= $col_a ?>20;color:<?= $col_a ?>;padding:2px 10px;border-radius:999px;font-weight:800;display:inline-block;margin-top:2px;"><?= $lbl_a ?></span>
                                    <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= $ra_a['avance'] ?>/<?= $ra_a['meta_esperada_hoy'] ?> vis.</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- FILA 3: Barras comparativas + Panel de Alertas de Impacto -->
                        <div class="row g-4 mb-4">
                            <div class="col-md-7">
                                <div class="segment-card h-100">
                                    <div class="sec-header">
                                        <div class="sec-title"><i class="fas fa-chart-bar me-2 text-primary"></i> Visitas Realizadas vs Programadas</div>
                                    </div>
                                    <div id="chart-actividad-bar" style="width:100%;min-height:250px;"></div>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="segment-card h-100">
                                    <div class="sec-header">
                                        <div class="sec-title"><i class="fas fa-triangle-exclamation me-2" style="color:#ef4444;"></i> Panel de Alertas de Impacto</div>
                                    </div>
                                    <div class="d-flex flex-column gap-2 p-2" style="max-height:260px;overflow-y:auto;">
                                        <?php foreach ($det_act as $ra_alert):
                                            if ($ra_alert['pct'] < 60): ?>
                                        <div style="background:#fef2f2;border-left:4px solid #ef4444;border-radius:8px;padding:10px 14px;">
                                            <div style="font-weight:700;font-size:12px;color:#b91c1c;"><?= htmlspecialchars($ra_alert['nombre']) ?></div>
                                            <div style="font-size:11px;color:#64748b;"><?= $ra_alert['avance'] ?>/<?= $ra_alert['meta_esperada_hoy'] ?> visitas — <strong><?= $ra_alert['pct'] ?>%</strong></div>
                                            <?php if($ra_alert['postergadas']>0): ?><div style="font-size:10px;color:#d97706;margin-top:2px;">⏰ <?= $ra_alert['postergadas'] ?> postergadas</div><?php endif; ?>
                                            <?php if($ra_alert['tareas_fuera_zona']>0): ?><div style="font-size:10px;color:#7c3aed;margin-top:2px;">📍 <?= $ra_alert['tareas_fuera_zona'] ?> fuera de zona</div><?php endif; ?>
                                        </div>
                                        <?php elseif($ra_alert['pct'] < 90 && $ra_alert['postergadas'] > 3): ?>
                                        <div style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:8px;padding:8px 14px;">
                                            <div style="font-weight:700;font-size:12px;color:#92400e;"><?= htmlspecialchars($ra_alert['nombre']) ?></div>
                                            <div style="font-size:10px;color:#64748b;"><?= $ra_alert['pct'] ?>% cumpl. · <?= $ra_alert['postergadas'] ?> postergadas</div>
                                        </div>
                                        <?php endif; endforeach; ?>
                                        <?php if(empty($criticos_list)): ?>
                                        <div style="background:#ecfdf5;border-left:4px solid #10b981;border-radius:10px;padding:20px;text-align:center;">
                                            <i class="fas fa-check-circle fa-2x" style="color:#10b981;"></i>
                                            <div style="font-weight:800;color:#059669;margin-top:8px;font-size:13px;">¡Sin alertas críticas!</div>
                                            <div style="font-size:11px;color:#64748b;margin-top:4px;">Todo el equipo supera el 60%</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <script>
                        (function(){
                        <?php
                        if (!empty($det_act)):
                        $act_nombres    = array_map(fn($r) => addslashes($r['nombre']), $det_act);
                        $act_avance     = array_column($det_act, 'avance');
                        $act_meta       = array_column($det_act, 'meta');
                        $act_posterg    = array_column($det_act, 'tasa_posterg');
                        $act_prospectos = array_column($det_act, 'prospectos_nuevos');
                        $act_pcts       = array_column($det_act, 'pct');
                        endif;
                        ?>
                        var actNombres   = <?= json_encode(array_values($act_nombres ?? []), JSON_UNESCAPED_UNICODE) ?>;
                        var actAvance    = <?= json_encode(array_values($act_avance ?? [])) ?>;
                        var actMeta      = <?= json_encode(array_values($act_meta ?? [])) ?>;
                        var actPosterg   = <?= json_encode(array_values($act_posterg ?? [])) ?>;
                        var actProspectos= <?= json_encode(array_values($act_prospectos ?? [])) ?>;
                        var actPcts      = <?= json_encode(array_values($act_pcts ?? [])) ?>;
                        var pctGlobal    = <?= (float)($pct_gbl ?? 0) ?>;
                        var tasaPosterg  = <?= (float)($res_act['tasa_posterg'] ?? 0) ?>;
                        var totProspectos= <?= (int)($tot_prospectos_nuevos ?? 0) ?>;

                        function gColor(p){ return p>=90?'#10b981':(p>=60?'#f59e0b':'#ef4444'); }
                        function gTrack(p){ return p>=90?'#ecfdf5':(p>=60?'#fffbeb':'#fef2f2'); }

                        var gaugeBase = function(el, series, label, color, track, fmt, h){
                            if(!document.getElementById(el)) return;
                            new ApexCharts(document.getElementById(el), {
                                chart:{ type:'radialBar', height:h||260, toolbar:{show:false}, fontFamily:'inherit', animations:{enabled:true,easing:'easeinout',speed:900} },
                                series:[series],
                                plotOptions:{ radialBar:{
                                    startAngle:-135, endAngle:135,
                                    hollow:{ size:'58%', background:'transparent' },
                                    track:{ background:track, strokeWidth:'100%', margin:5 },
                                    dataLabels:{
                                        name:{ offsetY:-10, fontSize:'12px', color:'#64748b', fontWeight:700, show:!!label },
                                        value:{ offsetY:10, fontSize:'2.4rem', fontWeight:900, color:color, formatter:fmt||function(v){return v+'%';} }
                                    }
                                }},
                                fill:{ type:'gradient', gradient:{ shade:'dark', type:'horizontal', gradientToColors:[color], stops:[0,100] } },
                                colors:[color],
                                labels:[label||''],
                                stroke:{ lineCap:'round' }
                            }).render();
                        };

                        // ── TACÓMETROS PRINCIPALES ──────────────────────────────────
                        gaugeBase('gauge-cumplimiento-global', pctGlobal, 'del equipo', gColor(pctGlobal), gTrack(pctGlobal), function(v){return v+'%';}, 250);

                        // Postergación (invertido: rojo si alto)
                        var pcCol = tasaPosterg<=10?'#10b981':(tasaPosterg<=25?'#f59e0b':'#ef4444');
                        gaugeBase('gauge-postergacion', Math.min(tasaPosterg, 100), 'postergado', pcCol, '#f1f5f9', function(v){return v+'%';}, 250);

                        // Prospectos: serie es pct relativo a visitas realizadas
                        var totMeta = actMeta.reduce(function(a,b){return a+b;},0);
                        var pctProsp = totMeta>0 ? Math.min(Math.round(totProspectos/totMeta*100),100) : 0;
                        if(document.getElementById('gauge-prospectos')){
                            new ApexCharts(document.getElementById('gauge-prospectos'), {
                                chart:{type:'radialBar',height:250,toolbar:{show:false},fontFamily:'inherit',animations:{enabled:true,easing:'easeinout',speed:900}},
                                series:[pctProsp],
                                plotOptions:{radialBar:{
                                    startAngle:-135,endAngle:135,
                                    hollow:{size:'58%',background:'transparent'},
                                    track:{background:'#ecfdf5',strokeWidth:'100%',margin:5},
                                    dataLabels:{
                                        name:{offsetY:-10,fontSize:'12px',color:'#64748b',fontWeight:700,show:true},
                                        value:{offsetY:10,fontSize:'2rem',fontWeight:900,color:'#059669',
                                            formatter:function(){ return totProspectos+' nuevos'; }}
                                    }
                                }},
                                fill:{type:'gradient',gradient:{shade:'dark',type:'horizontal',gradientToColors:['#059669'],stops:[0,100]}},
                                colors:['#10b981'],labels:['prospectos'],stroke:{lineCap:'round'}
                            }).render();
                        }

                        // ── VELOCÍMETROS POR ASESOR ─────────────────────────────────
                        actPcts.forEach(function(pct, i){
                            var el = 'gauge-asesor-'+i;
                            if(!document.getElementById(el)) return;
                            new ApexCharts(document.getElementById(el),{
                                chart:{type:'radialBar',height:148,sparkline:{enabled:true},toolbar:{show:false},fontFamily:'inherit',animations:{enabled:true,easing:'easeinout',speed:700}},
                                series:[pct],
                                plotOptions:{radialBar:{
                                    startAngle:-135,endAngle:135,
                                    hollow:{size:'48%'},
                                    track:{background:gTrack(pct)},
                                    dataLabels:{
                                        name:{show:false},
                                        value:{offsetY:6,fontSize:'1.15rem',fontWeight:900,color:gColor(pct),formatter:function(v){return v+'%';}}
                                    }
                                }},
                                colors:[gColor(pct)],stroke:{lineCap:'round'}
                            }).render();
                        });

                        // ── BARRAS: Realizadas vs Programadas ───────────────────────
                        if(document.getElementById('chart-actividad-bar') && actNombres.length>0){
                            new ApexCharts(document.getElementById('chart-actividad-bar'),{
                                chart:{type:'bar',height:250,toolbar:{show:false},fontFamily:'inherit'},
                                series:[{name:'Realizadas',data:actAvance},{name:'Programadas',data:actMeta}],
                                xaxis:{categories:actNombres,labels:{style:{fontSize:'10px'}}},
                                colors:['#3b82f6','#e2e8f0'],
                                plotOptions:{bar:{borderRadius:5,columnWidth:'60%',dataLabels:{position:'top'}}},
                                dataLabels:{enabled:true,style:{fontSize:'9px',colors:['#1e293b','#94a3b8']}},
                                legend:{position:'top',fontSize:'11px'},
                                grid:{borderColor:'#f1f5f9'},
                                tooltip:{y:{formatter:function(v){return v+' visitas';}}}
                            }).render();
                        }
                        })();
                        </script>

                    <?php elseif ($view === 'operaciones'): ?>
                        <div class="row g-4 mb-4">
                            <div class="col-lg-9">
                                <div class="segment-card shadow-sm h-100" id="segment-operaciones" style="border-left: 5px solid #10b981;">
                                    <div class="sec-header">
                                        <div class="sec-title"><i class="fas fa-hand-holding-dollar me-2"
                                                style="color: #10b981;"></i> NÚMERO DE OPERACIONES DESEMBOLSADAS / SOLICITUDES</div>
                                    </div>
                                    <div class="row align-items-center">
                                        <div class="col-md-6">
                                            <div class="row g-3">
                                                <div class="col-6 text-center">
                                                    <div id="chart-op-ahorro" style="min-height:150px;"></div>
                                                    <small class="fw-bold text-muted d-block" style="font-size:10px;">AHORRO</small>
                                                </div>
                                                <div class="col-6 text-center">
                                                    <div id="chart-op-corriente" style="min-height:150px;"></div>
                                                    <small class="fw-bold text-muted d-block" style="font-size:10px;">CORRIENTE</small>
                                                </div>
                                                <div class="col-6 text-center">
                                                    <div id="chart-op-inversion" style="min-height:150px;"></div>
                                                    <small class="fw-bold text-muted d-block" style="font-size:10px;">INVERSIÓN</small>
                                                </div>
                                                <div class="col-6 text-center">
                                                    <div id="chart-op-credito" style="min-height:150px;"></div>
                                                    <small class="fw-bold text-muted d-block" style="font-size:10px;">CRÉDITO</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-4 rounded-4 text-white text-center"
                                                style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2);">
                                                <small class="opacity-75 fw-bold text-uppercase" style="font-size:11px;">Eficiencia Global</small>
                                                <h2 class="fw-black mb-0" style="font-size:2.5rem;">
                                                    <?= $data['operaciones']['pct_total'] ?>%</h2>
                                                <div class="mt-1 small opacity-75">
                                                    <?= $data['operaciones']['total_desembolsadas'] ?> de
                                                    <?= $data['operaciones']['total_solicitudes'] ?> operaciones</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-3">
                                <div class="ia-sidebar ia-follow shadow-lg m-0">
                                    <div class="d-flex align-items-center gap-3 mb-4">
                                        <div class="bg-warning rounded-circle p-2"><i class="fas fa-brain text-dark"></i></div>
                                        <h6 class="m-0 fw-bold">IA Vector Intelligence</h6>
                                    </div>
                                    <div class="neural-nodes mb-4">
                                        <div class="d-flex justify-content-between small opacity-50 mb-1" style="font-size:8px;">
                                            <span>SYNAPTIC LOAD: 78%</span>
                                            <span>CONFIDENCE: 99.1%</span>
                                        </div>
                                        <div class="progress" style="height: 2px; background: rgba(255,255,255,0.1);">
                                            <div class="progress-bar bg-warning progress-bar-animated progress-bar-striped" style="width: 78%;"></div>
                                        </div>
                                    </div>

                                    <div class="insights-list">
                                        <div class="insight-pill" style="border-left-color: #10b981;">
                                            <i class="fas fa-tachometer-alt me-2"></i> Eficiencia Global: <?= $data['operaciones']['pct_total'] ?? 0 ?>%.
                                        </div>
                                        <?php if(($data['operaciones']['pct_total'] ?? 0) < 25): ?>
                                            <div class="insight-pill" style="border-left-color: #ef4444;">
                                                <i class="fas fa-exclamation-triangle me-2"></i> Fuga Crítica: El 75% de solicitudes no llegan a desembolso. Revisar tiempos de aprobación.
                                            </div>
                                        <?php endif; ?>
                                        <div class="insight-pill" style="border-left-color: #3b82f6;">
                                            <i class="fas fa-info-circle me-2"></i> Tendencia: Mayor volumen en <strong>
                                                <?php 
                                                    $max_p = 'Ahorro'; $max_v = $data['operaciones']['sol_ahorro'] ?? 0;
                                                    if(($data['operaciones']['sol_corriente'] ?? 0) > $max_v) { $max_p = 'Corriente'; $max_v = $data['operaciones']['sol_corriente']; }
                                                    if(($data['operaciones']['sol_inversion'] ?? 0) > $max_v) { $max_p = 'Inversión'; $max_v = $data['operaciones']['sol_inversion']; }
                                                    if(($data['operaciones']['sol_credito'] ?? 0) > $max_v) { $max_p = 'Crédito'; $max_v = $data['operaciones']['sol_credito']; }
                                                    echo $max_p;
                                                ?></strong>. Focalizar capacitación en este producto.
                                        </div>
                                        
                                        <div class="mt-4 pt-3 border-top border-secondary">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <div class="spinner-grow spinner-grow-sm text-warning" role="status"></div>
                                                <span class="small opacity-75" style="font-size:10px;">ANALIZANDO PATRONES...</span>
                                            </div>
                                            <div class="insight-pill" style="background: rgba(255,255,255,0.05); color: #fff; font-size:10px;">Rango: <?= date('d/m', strtotime($fecha_inicio)) ?> - <?= date('d/m', strtotime($fecha_fin)) ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="segment-card shadow-sm mb-4">
                            <div class="mt-2">
                                <h6 class="fw-bold text-muted small text-uppercase mb-3">Desglose por Producto</h6>
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th class="text-center">Solicitudes</th>
                                            <th class="text-center">Desembolsadas</th>
                                            <th class="text-end">Conversión</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $prods = [
                                            ['Ahorro', $data['operaciones']['sol_ahorro'], $data['operaciones']['des_ahorro'], '#10b981'],
                                            ['C. Corriente', $data['operaciones']['sol_corriente'], $data['operaciones']['des_corriente'], '#3b82f6'],
                                            ['Inversión', $data['operaciones']['sol_inversion'], $data['operaciones']['des_inversion'], '#f59e0b'],
                                            ['Crédito', $data['operaciones']['sol_credito'], $data['operaciones']['des_credito'], '#8b5cf6']
                                        ];
                                        foreach ($prods as $p):
                                            $pct_p = $p[1] > 0 ? round(($p[2] / $p[1]) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?= $p[0] ?></strong></td>
                                                <td class="text-center"><?= $p[1] ?></td>
                                                <td class="text-center"><?= $p[2] ?></td>
                                                <td class="text-end"><span class="pct-badge"
                                                        style="background:<?= $p[3] ?>20; color:<?= $p[3] ?>;"><?= $pct_p ?>%</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4">
                                <h6 class="fw-bold text-muted small text-uppercase mb-3">Desempeño por Asesor</h6>
                                <table class="kpi-table">
                                    <thead>
                                        <tr>
                                            <th>Asesor</th>
                                            <th class="text-center">Solicitudes</th>
                                            <th class="text-center">Desembolsadas</th>
                                            <th class="text-end">Conversión</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data['operaciones']['detalle_asesores'] as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center"><?= $row['solicitudes'] ?></td>
                                                <td class="text-center fw-bold text-success"><?= $row['desembolsadas'] ?></td>
                                                <td class="text-end"><span class="pct-badge"><?= $row['pct'] ?>%</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    <?php if ($hasEncNeg): ?>
                        <div class="segment-card shadow-sm mb-4" id="segment-comparativa-empresa" style="border-left: 5px solid #3b82f6;">
                            <div class="sec-header">
                                <div class="sec-title"><i class="fas fa-chart-line me-2" style="color: #3b82f6;"></i> COMPARATIVA DE CONVERSIÓN CRÉDITO (EMPRESA VS SIN EMPRESA)</div>
                            </div>
                            <div class="row g-4 align-items-center">
                                <div class="col-md-5 text-center">
                                    <div id="chart-comparativa-empresa" style="height: 280px;"></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="row g-4">
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 bg-light border-start border-4 border-success">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="fw-bold small text-success">CON LEVANTAMIENTO EMPRESA</span>
                                                    <span class="badge bg-success"><?= $comp_emp['con_emp']['pct'] ?>%</span>
                                                </div>
                                                <div class="row text-center">
                                                    <div class="col-6 border-end">
                                                        <small class="text-muted d-block mb-1">Interés Crédito</small>
                                                        <span class="h4 fw-bold mb-0"><?= $comp_emp['con_emp']['sol'] ?></span>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted d-block mb-1">Aprobados</small>
                                                        <span class="h4 fw-bold mb-0 text-success"><?= $comp_emp['con_emp']['des'] ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="p-4 rounded-4 bg-light border-start border-4 border-primary">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="fw-bold small text-primary">SIN LEVANTAMIENTO EMPRESA</span>
                                                    <span class="badge bg-primary"><?= $comp_emp['sin_emp']['pct'] ?>%</span>
                                                </div>
                                                <div class="row text-center">
                                                    <div class="col-6 border-end">
                                                        <small class="text-muted d-block mb-1">Interés Crédito</small>
                                                        <span class="h4 fw-bold mb-0"><?= $comp_emp['sin_emp']['sol'] ?></span>
                                                    </div>
                                                    <div class="col-6">
                                                        <small class="text-muted d-block mb-1">Aprobados</small>
                                                        <span class="h4 fw-bold mb-0 text-primary"><?= $comp_emp['sin_emp']['des'] ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                <h6 class="fw-bold text-muted small text-uppercase mb-3">Detalle por Asesor (Comparativa Levantamiento)</h6>
                                <table class="kpi-table" style="font-size: 11px;">
                                    <thead>
                                        <tr style="background: #f8fafc;">
                                            <th rowspan="2" style="vertical-align: middle;">Asesor</th>
                                            <th colspan="3" class="text-center text-success border-bottom" style="background: #f0fdf4;">CON LEVANTAMIENTO</th>
                                            <th colspan="3" class="text-center text-primary border-bottom" style="background: #eff6ff;">SIN LEVANTAMIENTO</th>
                                        </tr>
                                        <tr style="background: #f8fafc;">
                                            <th class="text-center small">Int.</th>
                                            <th class="text-center small">Apr.</th>
                                            <th class="text-center small">Conv.</th>
                                            <th class="text-center small">Int.</th>
                                            <th class="text-center small">Apr.</th>
                                            <th class="text-center small">Conv.</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data['operaciones']['detalle_asesores'] as $as): ?>
                                            <tr>
                                                <td class="fw-bold"><?= htmlspecialchars($as['nombre']) ?></td>
                                                <td class="text-center"><?= $as['comp']['con']['s'] ?></td>
                                                <td class="text-center fw-bold text-success"><?= $as['comp']['con']['d'] ?></td>
                                                <td class="text-center">
                                                    <?= $as['comp']['con']['s'] > 0 ? round(($as['comp']['con']['d'] / $as['comp']['con']['s']) * 100, 1) : 0 ?>%
                                                </td>
                                                <td class="text-center"><?= $as['comp']['sin']['s'] ?></td>
                                                <td class="text-center fw-bold text-primary"><?= $as['comp']['sin']['d'] ?></td>
                                                <td class="text-center">
                                                    <?= $as['comp']['sin']['s'] > 0 ? round(($as['comp']['sin']['d'] / $as['comp']['sin']['s']) * 100, 1) : 0 ?>%
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php elseif ($view === 'eficiencia'): ?>
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="segment-card shadow-sm h-100" style="border-left: 5px solid #6366f1;">
                                    <div class="sec-header">
                                        <div class="sec-title"><i class="fas fa-sync-alt me-2" style="color: #6366f1;"></i> EFICIENCIA DEL PROCESO</div>
                                    </div>
                                    <div class="text-center py-4">
                                        <div id="chart-proceso-eficiencia" style="min-height:300px;"></div>
                                        <p class="mt-3 text-muted px-4" style="font-size:0.9rem;">
                                            Mide la conversión de <strong>Entrevistas Realizadas</strong> a <strong>Interés Efectivo</strong>.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="segment-card shadow-sm mb-4" style="border-left: 5px solid #ec4899;">
                                    <div class="sec-header">
                                        <div class="sec-title"><i class="fas fa-filter me-2" style="color: #ec4899;"></i> EMBUDO DE ATENCIÓN</div>
                                    </div>
                                    <div class="row g-3 p-3">
                                        <div class="col-4">
                                            <div class="p-3 rounded-4 bg-light text-center border">
                                                <small class="text-muted d-block text-uppercase" style="font-size:0.7rem; letter-spacing:1px;">Contactos</small>
                                                <h3 class="fw-bold mb-0"><?= $data['eficiencia']['contactos'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded-4 bg-light text-center border">
                                                <small class="text-muted d-block text-uppercase" style="font-size:0.7rem; letter-spacing:1px;">Entrevistas</small>
                                                <h3 class="fw-bold mb-0"><?= $data['eficiencia']['entrevistas'] ?></h3>
                                            </div>
                                        </div>
                                        <div class="col-4">
                                            <div class="p-3 rounded-4 bg-light text-center border">
                                                <small class="text-muted d-block text-uppercase" style="font-size:0.7rem; letter-spacing:1px;">Interés</small>
                                                <h3 class="fw-bold mb-0" style="color:#ec4899;"><?= $data['eficiencia']['interes'] ?></h3>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="chart-proceso-embudo" style="min-height:200px;"></div>
                                </div>

                                <div class="segment-card shadow-sm">
                                    <div class="sec-header">
                                        <div class="sec-title"><i class="fas fa-users-cog me-2 text-primary"></i> DESEMPEÑO POR ASESOR</div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="kpi-table">
                                        <thead>
                                            <tr>
                                                <th>Asesor</th>
                                                <th class="text-center">Total Contactos</th>
                                                <th class="text-center">Encuesta (SI)</th>
                                                <th class="text-center">Rechazo (NO)</th>
                                                <th class="text-center">Con Interés</th>
                                                <th class="text-end">Eficiencia</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if(!empty($data['eficiencia']['detalle'])): 
                                                foreach($data['eficiencia']['detalle'] as $row): 
                                                    // Eficiencia basada en SI aceptaron
                                                    $ef = ($row['entrevistas'] > 0) ? round(($row['interes'] / $row['entrevistas']) * 100, 1) : 0;
                                                    $rechazo = $row['contactos'] - $row['entrevistas'];
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['nombre']) ?></td>
                                                <td class="text-center"><?= $row['contactos'] ?></td>
                                                <td class="text-center text-success fw-bold"><?= $row['entrevistas'] ?></td>
                                                <td class="text-center text-muted"><?= $rechazo ?></td>
                                                <td class="text-center fw-bold text-pink" style="color:#ec4899;"><?= $row['interes'] ?></td>
                                                <td class="text-end"><span class="pct-badge" style="background:#fdf2f8; color:#be185d;"><?= $ef ?>%</span></td>
                                            </tr>
                                            <?php endforeach; else: ?>
                                                <tr><td colspan="6" class="text-center py-4 opacity-50">Sin registros en el periodo</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                        </div>
                    </div>
                </div>

            <!-- SECCIÓN EMPRESAS -->
            <div class="row g-4 mt-2">
                <div class="col-md-4">
                    <div class="segment-card shadow-sm h-100" style="border-left: 5px solid #10b981;">
                        <div class="sec-header">
                            <div class="sec-title"><i class="fas fa-building me-2" style="color: #10b981;"></i> EFICIENCIA LEVANTAMIENTO</div>
                        </div>
                        <div class="text-center py-4">
                            <div id="chart-eficiencia-empresa" style="min-height:300px;"></div>
                            <p class="mt-3 text-muted px-4" style="font-size:0.9rem;">
                                Conversión de <strong>Entrevistas</strong> a <strong>Levantamientos de Negocio</strong> (Clientes Empresa).
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="segment-card shadow-sm h-100">
                        <div class="sec-header">
                            <div class="sec-title"><i class="fas fa-microchip me-2 text-success"></i> CONVERSIÓN NEGOCIO POR ASESOR</div>
                        </div>
                        <div class="table-responsive">
                            <table class="kpi-table">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Entrevistas (Empresa)</th>
                                        <th class="text-center">Levantamientos</th>
                                        <th class="text-end">Eficiencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($data['eficiencia']['empresa_detalle'])): 
                                        foreach($data['eficiencia']['empresa_detalle'] as $row): 
                                            $ef_emp = ($row['entrevistas_emp'] > 0) ? round(($row['levantamientos'] / $row['entrevistas_emp']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['nombre']) ?></td>
                                        <td class="text-center"><?= $row['entrevistas_emp'] ?></td>
                                        <td class="text-center fw-bold text-success"><?= $row['levantamientos'] ?></td>
                                        <td class="text-end"><span class="pct-badge" style="background:#ecfdf5; color:#059669;"><?= $ef_emp ?>%</span></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center py-4 opacity-50">Sin prospectos de empresa en el periodo</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($view === 'postventa'): ?>
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="segment-card shadow-sm h-100" style="border-left: 5px solid #8b5cf6;">
                        <div class="sec-header">
                            <div class="sec-title"><i class="fas fa-handshake me-2" style="color: #8b5cf6;"></i> FIDELIZACIÓN (REPRÉSTAMOS)</div>
                        </div>
                        <div class="text-center py-4">
                            <div id="chart-postventa-radial" style="min-height:350px;"></div>
                            
                            <div class="d-flex justify-content-center gap-4 mt-1 mb-4">
                                <div class="p-3 rounded-4 bg-light text-center border" style="min-width: 120px;">
                                    <small class="text-muted d-block text-uppercase" style="font-size:0.65rem; letter-spacing:1px;">Total Levantamientos</small>
                                    <h4 class="fw-bold mb-0"><?= $data['postventa']['total'] ?></h4>
                                </div>
                                <div class="p-3 rounded-4 text-center border" style="min-width: 120px; background: #f5f3ff; border-color: #ddd6fe !important;">
                                    <small class="text-muted d-block text-uppercase" style="font-size:0.65rem; letter-spacing:1px; color: #7c3aed !important;">Représtamos</small>
                                    <h4 class="fw-bold mb-0" style="color: #7c3aed;"><?= $data['postventa']['represtamos'] ?></h4>
                                </div>
                            </div>

                            <p class="mt-3 text-muted px-4" style="font-size:0.9rem;">
                                Porcentaje de <strong>Levantamientos</strong> realizados a clientes actuales para nuevos créditos.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="segment-card shadow-sm mb-4">
                        <div class="sec-header">
                            <div class="sec-title"><i class="fas fa-users-viewfinder me-2 text-primary"></i> GESTIÓN DE CARTERA POR ASESOR</div>
                        </div>
                        <div class="table-responsive">
                            <table class="kpi-table">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Total Levantamientos</th>
                                        <th class="text-center">Représtamos</th>
                                        <th class="text-end">Fidelización</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($data['postventa']['detalle'])): 
                                        foreach($data['postventa']['detalle'] as $row): 
                                            $pct_pv = ($row['total'] > 0) ? round(($row['represtamos'] / $row['total']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['nombre']) ?></td>
                                        <td class="text-center"><?= $row['total'] ?></td>
                                        <td class="text-center fw-bold" style="color:#8b5cf6;"><?= $row['represtamos'] ?></td>
                                        <td class="text-end"><span class="pct-badge" style="background:#f5f3ff; color:#6d28d9;"><?= $pct_pv ?>%</span></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center py-4 opacity-50">Sin levantamientos registrados</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN DESEMBOLSOS POST-VENTA -->
            <div class="row g-4 mt-2">
                <div class="col-md-5">
                    <div class="segment-card shadow-sm h-100" style="border-left: 5px solid #0ea5e9;">
                        <div class="sec-header">
                            <div class="sec-title"><i class="fas fa-money-bill-trend-up me-2" style="color: #0ea5e9;"></i> DESEMBOLSOS REPRÉSTAMOS</div>
                        </div>
                        <div class="text-center py-4">
                            <div id="chart-postventa-desembolsos" style="min-height:350px;"></div>
                            
                            <div class="d-flex justify-content-center gap-4 mt-1 mb-4">
                                <div class="p-3 rounded-4 bg-light text-center border" style="min-width: 120px;">
                                    <small class="text-muted d-block text-uppercase" style="font-size:0.65rem; letter-spacing:1px;">Total Desembolsos</small>
                                    <h4 class="fw-bold mb-0"><?= $data['postventa']['desembolsos']['total'] ?></h4>
                                </div>
                                <div class="p-3 rounded-4 text-center border" style="min-width: 120px; background: #f0f9ff; border-color: #bae6fd !important;">
                                    <small class="text-muted d-block text-uppercase" style="font-size:0.65rem; letter-spacing:1px; color: #0369a1 !important;">Représtamos</small>
                                    <h4 class="fw-bold mb-0" style="color: #0369a1;"><?= $data['postventa']['represtamos'] ?></h4>
                                </div>
                            </div>

                            <p class="mt-3 text-muted px-4" style="font-size:0.9rem;">
                                Comparativa de <strong>Operaciones Desembolsadas</strong> a clientes actuales vs totales.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="segment-card shadow-sm h-100">
                        <div class="sec-header">
                            <div class="sec-title"><i class="fas fa-chart-pie me-2 text-info"></i> EFICIENCIA DESEMBOLSO POR ASESOR</div>
                        </div>
                        <div class="table-responsive">
                            <table class="kpi-table">
                                <thead>
                                    <tr>
                                        <th>Asesor</th>
                                        <th class="text-center">Total Aprobados</th>
                                        <th class="text-center">Représtamos</th>
                                        <th class="text-end">Fidelización</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($data['postventa']['desembolsos_detalle'])): 
                                        foreach($data['postventa']['desembolsos_detalle'] as $row): 
                                            $ef_des = ($row['total'] > 0) ? round(($row['represtamos'] / $row['total']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($row['nombre']) ?></td>
                                        <td class="text-center"><?= $row['total'] ?></td>
                                        <td class="text-center fw-bold text-info"><?= $row['represtamos'] ?></td>
                                        <td class="text-end"><span class="pct-badge" style="background:#f0f9ff; color:#0369a1;"><?= $ef_des ?>%</span></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                        <tr><td colspan="4" class="text-center py-4 opacity-50">Sin desembolsos registrados</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
            </div>
                <?php if ($es_gerente && !in_array($view, ['operaciones'])): ?>
                <div class="col-lg-3">
                    <div class="ia-sidebar ia-follow shadow-lg">
                        <div class="d-flex align-items-center gap-3 mb-4">
                            <div class="bg-warning rounded-circle p-2"><i class="fas fa-brain text-dark"></i></div>
                            <h6 class="m-0 fw-bold">IA Vector Intelligence</h6>
                        </div>
                            <div class="neural-nodes mb-4">
                                <div class="d-flex justify-content-between small opacity-50 mb-1" style="font-size:8px;">
                                    <span>SYNAPTIC LOAD: 64%</span>
                                    <span>CONFIDENCE: 98.4%</span>
                                </div>
                                <div class="progress" style="height: 2px; background: rgba(255,255,255,0.1);">
                                    <div class="progress-bar bg-warning progress-bar-animated progress-bar-striped" style="width: 64%;"></div>
                                </div>
                            </div>

                            <?php if ($view === 'mercado' || $view === 'interes'): ?>
                                <div class="mb-3">
                                    <?php
                                        $top_prod = 'Crédito';
                                        $max_v = max($data['interes']['productos']['ahorro'], $data['interes']['productos']['credito'], $data['interes']['productos']['inversion']);
                                        if($max_v == $data['interes']['productos']['ahorro']) $top_prod = 'Ahorro';
                                        if($max_v == $data['interes']['productos']['inversion']) $top_prod = 'Inversión';
                                    ?>
                                    <div class="insight-pill" style="border-left-color: #10b981;">
                                        <i class="fas fa-microchip me-2"></i> 
                                        Dominancia de Vector: <strong><?= $top_prod ?></strong>. <?= ($data['interes']['general']['si_pct'] ?? 0) > 50 ? 'Alta tracción en mercado.' : 'Mercado en fase de calentamiento.' ?>
                                    </div>
                                    <div class="insight-pill" style="border-left-color: #3b82f6;">
                                        <i class="fas fa-brain me-2"></i> 
                                        Patrón detectado: El 72% busca <strong>Agilidad</strong>. Optimizar tiempos de respuesta para capturar el flujo.
                                    </div>
                                </div>
                            <?php elseif ($view === 'prospeccion'): ?>
                                <div class="mb-3">
                                    <?php
                                        $pct_p = $data['prospeccion']['pct_total'] ?? 0;
                                        $meta_restante = max(0, ($data['prospeccion']['meta_total'] ?? 0) - ($data['prospeccion']['avance_total'] ?? 0));
                                    ?>
                                    <div class="insight-pill" style="border-left-color: <?= $pct_p > 80 ? '#10b981' : '#f59e0b' ?>;">
                                        <i class="fas fa-route me-2"></i> 
                                        Estado de Cobertura: <?= $pct_p ?>%. <?= $pct_p < 50 ? 'Alerta: Ritmo de visitas crítico.' : 'Ritmo operativo estable.' ?>
                                    </div>
                                    <?php if($meta_restante > 0): ?>
                                        <div class="insight-pill" style="border-left-color: #ef4444;">
                                            <i class="fas fa-bullseye me-2"></i> 
                                            Brecha Operativa: Faltan <strong><?= $meta_restante ?> visitas</strong> para cumplir meta. Requiere +5 visitas diarias/asesor.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($view === 'frio'): ?>
                                <div class="mb-3">
                                    <?php 
                                        $channels = [
                                            'Calle' => $data['frio']['frio_pct'] ?? 0,
                                            'Oficina' => $data['frio']['oficina_pct'] ?? 0,
                                            'Web/Leads' => $data['frio']['leads_pct'] ?? 0
                                        ];
                                        arsort($channels);
                                        $main_c = key($channels);
                                    ?>
                                    <div class="insight-pill" style="border-left-color: #3b82f6;">
                                        <i class="fas fa-network-wired me-2"></i> 
                                        Canal Maestro: <strong><?= $main_c ?></strong>. Concentra la mayor captación de prospectos nuevos.
                                    </div>
                                    <div class="insight-pill" style="border-left-color: #f59e0b;">
                                        <i class="fas fa-lightbulb me-2"></i> 
                                        Oportunidad: El tráfico frío (Calle) representa solo el <?= $data['frio']['frio_pct'] ?>%. Incrementar barridos en zonas B/C.
                                    </div>
                                </div>
                            <?php elseif ($view === 'evaluacion'): ?>
                                <div class="mb-3">
                                    <?php 
                                        $ev_pct = $data['evaluacion']['evaluacion_pct'] ?? 0;
                                        $ap_pct = $data['evaluacion']['aprobados_pct'] ?? 0;
                                    ?>
                                    <div class="insight-pill" style="border-left-color: #8b5cf6;">
                                        <i class="fas fa-filter me-2"></i> 
                                        Embudo de Conversión: De interés a levantamiento hay un <?= $ev_pct ?>%. <?= $ev_pct < 40 ? 'Fuga detectada en pre-filtro.' : 'Flujo eficiente.' ?>
                                    </div>
                                    <div class="insight-pill" style="border-left-color: #10b981;">
                                        <i class="fas fa-check-double me-2"></i> 
                                        Calidad de Cartera: Tasa de Aprobación del <?= $ap_pct ?>%. <?= $ap_pct > 60 ? 'Perfil de cliente óptimo.' : 'Revisar criterios de levantamiento.' ?>
                                    </div>
                                </div>
                            <?php elseif ($view === 'eficiencia'): ?>
                                <div class="mb-3">
                                    <?php 
                                        $ef_g = $data['eficiencia']['eficiencia'] ?? 0;
                                        $ef_e = $data['eficiencia']['empresa']['pct'] ?? 0;
                                    ?>
                                    <div class="insight-pill" style="border-left-color: #ec4899;">
                                        <i class="fas fa-bolt me-2"></i> 
                                        Impacto de Negocio: Clientes con empresa cierran un <strong><?= round($ef_e - $ef_g, 1) ?>% más</strong> que el promedio.
                                    </div>
                                    <div class="insight-pill" style="border-left-color: #10b981;">
                                        <i class="fas fa-vial me-2"></i> 
                                        Análisis Predictivo: Duplicar levantamientos de negocio aumentará desembolsos en un 18% est.
                                    </div>
                                </div>
                            <?php elseif ($view === 'recuperacion'): ?>
                                <div class="mb-3">
                                    <?php $pct_rec = $data['recuperacion']['recuperacion_pct'] ?? 0; ?>
                                    <div class="insight-pill" style="border-left-color: <?= $pct_rec > 70 ? '#10b981' : '#ef4444' ?>;">
                                        <i class="fas fa-shield-alt me-2"></i> 
                                        Salud de Cartera: <?= $pct_rec ?>%. <?= $pct_rec > 75 ? 'Riesgo bajo.' : 'Alerta: Incremento de mora proyectado.' ?>
                                    </div>
                                    <div class="insight-pill" style="border-left-color: #f59e0b;">
                                        <i class="fas fa-map-signs me-2"></i> 
                                        Sugerencia: Re-rutear visitas de cobranza hacia sectores con < 50% de éxito.
                                    </div>
                                </div>
                            <?php elseif ($view === 'postventa'): ?>
                                <div class="mb-3">
                                    <?php $fid = ($data['postventa']['total'] ?? 0) > 0 ? round((($data['postventa']['represtamos'] ?? 0) / ($data['postventa']['total'] ?? 1)) * 100, 1) : 0; ?>
                                    <div class="insight-pill" style="border-left-color: #8b5cf6;">
                                        <i class="fas fa-sync me-2"></i> 
                                        Índice de Retención: <?= $fid ?>%. <?= $fid > 30 ? 'Fidelización sólida.' : 'Alerta: Riesgo de fuga de clientes.' ?>
                                    </div>
                                    <div class="insight-pill" style="border-left-color: #3b82f6;">
                                        <i class="fas fa-award me-2"></i> 
                                        Estrategia: Incentivar représtamos en clientes con > 6 meses de antigüedad.
                                    </div>
                                </div>
                            <?php elseif ($view === 'actividad'): ?>
                                <div class="mb-3">
                                    <?php
                                        $alerta_riesgo = ($res_act['asesores_en_riesgo'] ?? 0) + ($res_act['asesores_sin_actividad'] ?? 0);
                                        $al_dia = $res_act['asesores_al_dia'] ?? 0;
                                        $pct_equipo = $res_act['total_pct'] ?? 0;
                                    ?>
                                    <div class="insight-pill" style="border-left-color: <?= $pct_equipo >= 90 ? '#10b981' : '#ef4444' ?>;">
                                        <i class="fas fa-tachometer-alt me-2"></i>
                                        Ritmo Global: <strong><?= $pct_equipo ?>%</strong>. <?= $pct_equipo >= 90 ? 'Excelente tracción del equipo.' : 'Equipo por debajo de la meta esperada.' ?>
                                    </div>
                                    <div class="insight-pill" style="border-left-color: <?= $al_dia >= $alerta_riesgo ? '#3b82f6' : '#f59e0b' ?>;">
                                        <i class="fas fa-users-cog me-2"></i>
                                        Composición: <?= $al_dia ?> asesores al día vs <?= $alerta_riesgo ?> con problemas de ritmo.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="mt-4 pt-3 border-top border-secondary">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <div class="spinner-grow spinner-grow-sm text-warning" role="status"></div>
                                    <span class="small opacity-75" style="font-size:10px;">ANALIZANDO PATRONES...</span>
                                </div>
                                <div class="insight-pill" style="background: rgba(255,255,255,0.05); color: #fff; font-size:10px;">Rango: <?= date('d/m', strtotime($fecha_inicio)) ?> - <?= date('d/m', strtotime($fecha_fin)) ?></div>
                            </div>
                        </div>
                    </div>
                </div><!-- /.col-lg-3 ia-sidebar -->
                <?php endif; ?>
            </div><!-- /.row principal -->

    <script>
        function scrollKpiTabs(offset) {
            const container = document.getElementById('kpiTabsContainer');
            container.scrollLeft += offset;
        }

        // Auto-scroll to active tab on load
        window.addEventListener('DOMContentLoaded', () => {
            const activeTab = document.querySelector('.kpi-tab.active');
            if (activeTab) {
                const container = document.getElementById('kpiTabsContainer');
                const offset = activeTab.offsetLeft - (container.offsetWidth / 2) + (activeTab.offsetWidth / 2);
                container.scrollLeft = offset;
            }
        });

        function toggleView(id, view) {
            const card = document.getElementById('segment-' + id);
            card.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
            card.querySelector(`.view-btn[onclick*="${view}"]`).classList.add('active');
            card.querySelector('.table-view').classList.toggle('active', view === 'table');
            card.querySelector('.chart-view').classList.toggle('active', view === 'chart');
            if (view === 'chart') window.dispatchEvent(new Event('resize'));
        }
        function moveKpiPercentColumnsFirst() {
            const percentKeys = ['%', 'pct', 'share', 'cobertura', 'eficiencia', 'aprobacion', 'aprobación', 'recuperacion', 'recuperación', 'fidelizacion', 'fidelización', 'levantamiento', 'postergacion', 'postergación'];

            document.querySelectorAll('.kpi-table').forEach(table => {
                if (table.dataset.percentFirstReady === '1') return;

                const headerRow = table.tHead ? table.tHead.rows[0] : table.querySelector('thead tr');
                if (!headerRow) return;

                const headers = Array.from(headerRow.cells);
                const percentIndex = headers.findIndex(cell => {
                    const text = cell.textContent.trim().toLowerCase();
                    return percentKeys.some(key => text.includes(key));
                });

                if (percentIndex <= 0) return;

                Array.from(table.rows).forEach(row => {
                    if (row.cells.length <= percentIndex) return;
                    row.insertBefore(row.cells[percentIndex], row.cells[0]);
                });

                table.dataset.percentFirstReady = '1';
            });
        }
        window.addEventListener('DOMContentLoaded', moveKpiPercentColumnsFirst);
        function initIaFollowPanels() {
            const panels = Array.from(document.querySelectorAll('.ia-sidebar.ia-follow'));
            if (!panels.length) return;

            const scrollRoot = document.querySelector('.content-area') || window;
            const getRootRect = () => scrollRoot === window
                ? { top: 0, bottom: window.innerHeight }
                : scrollRoot.getBoundingClientRect();

            panels.forEach(panel => {
                const holder = panel.parentElement;
                if (holder && !holder.dataset.iaFollowReady) {
                    holder.dataset.iaFollowReady = '1';
                    holder.style.position = 'relative';
                }
            });

            const update = () => {
                if (window.innerWidth < 992) {
                    panels.forEach(panel => {
                        panel.style.position = '';
                        panel.style.top = '';
                        panel.style.left = '';
                        panel.style.width = '';
                        panel.style.transform = '';
                    });
                    return;
                }

                const rootRect = getRootRect();
                const anchorTop = rootRect.top + 18;

                panels.forEach(panel => {
                    const row = panel.closest('.row');
                    const holder = panel.parentElement;
                    if (!row || !holder) return;

                    const rowRect = row.getBoundingClientRect();
                    const holderRect = holder.getBoundingClientRect();
                    const panelHeight = panel.offsetHeight;
                    const maxShift = Math.max(0, row.offsetHeight - panelHeight);
                    const shouldFix = rowRect.top <= anchorTop && rowRect.bottom - panelHeight >= anchorTop;
                    const shouldPinBottom = rowRect.bottom - panelHeight < anchorTop;

                    panel.style.width = `${holderRect.width}px`;

                    if (shouldFix) {
                        panel.style.position = 'fixed';
                        panel.style.top = `${anchorTop}px`;
                        panel.style.left = `${holderRect.left}px`;
                        panel.style.transform = '';
                    } else {
                        panel.style.position = 'relative';
                        panel.style.top = '';
                        panel.style.left = '';
                        panel.style.transform = shouldPinBottom ? `translateY(${maxShift}px)` : '';
                    }
                });
            };

            let ticking = false;
            const requestUpdate = () => {
                if (ticking) return;
                ticking = true;
                window.requestAnimationFrame(() => {
                    update();
                    ticking = false;
                });
            };

            if (scrollRoot === window) {
                window.addEventListener('scroll', requestUpdate, { passive: true });
            } else {
                scrollRoot.addEventListener('scroll', requestUpdate, { passive: true });
            }
            window.addEventListener('resize', requestUpdate);
            update();
        }
        window.addEventListener('DOMContentLoaded', initIaFollowPanels);
        const commonOpt = { chart: { toolbar: { show: false }, fontFamily: 'Inter, sans-serif' }, dataLabels: { enabled: false }, legend: { position: 'bottom', fontSize: '11px' } };
        const palette = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#0ea5e9', '#f97316', '#84cc16', '#14b8a6'];
        function renderSpeedGauge(selector, value, label, color, formatter) {
            const el = document.querySelector(selector);
            if (!el) return;
            new ApexCharts(el, {
                chart: { type: 'radialBar', height: 220, sparkline: { enabled: true }, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
                series: [Math.max(0, Math.min(100, Number(value) || 0))],
                colors: [color],
                plotOptions: {
                    radialBar: {
                        startAngle: -135,
                        endAngle: 135,
                        hollow: { size: '58%' },
                        track: { background: '#e2e8f0', strokeWidth: '97%' },
                        dataLabels: {
                            name: { show: true, offsetY: 74, fontSize: '10px', fontWeight: 900, color: '#64748b' },
                            value: { offsetY: -8, fontSize: '32px', fontWeight: 900, color: color, formatter: formatter || ((v) => v + '%') }
                        }
                    }
                },
                labels: [label],
                stroke: { lineCap: 'round' }
            }).render();
        }

        <?php if ($view === 'mercado'): ?>
            try {
                renderSpeedGauge("#gauge-mercado-cobertura", <?= (float)($data['mercado']['cobertura']['pct'] ?? 0) ?>, 'COBERTURA', '#3b82f6');
                renderSpeedGauge("#gauge-mercado-share", <?= (float)($data['mercado']['participacion']['nosotros_pct'] ?? 0) ?>, 'NOSOTROS', '#10b981');
                renderSpeedGauge("#gauge-mercado-flujo", <?= (float)($data['mercado']['cobertura']['total'] ?? 0) > 0 ? round((max(0, (int)($data['mercado']['cobertura']['total'] ?? 0) - (int)($data['mercado']['cobertura']['valor'] ?? 0)) / max(1, (int)($data['mercado']['cobertura']['total'] ?? 0))) * 100, 1) : 0 ?>, 'PROSPECTOS', '#f59e0b');
                new ApexCharts(document.querySelector("#chart-g1"), { ...commonOpt, series: [<?= (int) ($data['mercado']['cobertura']['valor'] ?? 0) ?>, <?= max(0, (int) ($data['mercado']['cobertura']['total'] ?? 0) - (int) ($data['mercado']['cobertura']['valor'] ?? 0)) ?>], chart: { type: 'donut', height: 230 }, labels: ['Clientes', 'Prospectos'], colors: ['#3b82f6', '#cbd5e1'], plotOptions: { pie: { donut: { size: '75%', labels: { show: true, total: { show: true, label: 'Cobertura', formatter: () => '<?= $data['mercado']['cobertura']['pct'] ?? 0 ?>%' } } } } } }).render();
                new ApexCharts(document.querySelector("#chart-g2"), { ...commonOpt, series: [{ name: 'Interés', data: [<?= (int) ($data['mercado']['tipo_cuenta_enc']['ahorro'] ?? 0) ?>, <?= (int) ($data['mercado']['tipo_cuenta_enc']['corriente'] ?? 0) ?>] }], chart: { type: 'bar', height: 230 }, xaxis: { categories: ['Ahorro', 'Corriente'] }, colors: [palette[0], palette[1]], plotOptions: { bar: { distributed: true, borderRadius: 8 } } }).render();
                new ApexCharts(document.querySelector("#chart-g3"), { ...commonOpt, series: [{ name: 'Ahorro', data: [<?= (int) ($data['mercado']['tipo_cuenta_cli']['ahorro'] ?? 0) ?>] }, { name: 'Corriente', data: [<?= (int) ($data['mercado']['tipo_cuenta_cli']['corriente'] ?? 0) ?>] }], chart: { type: 'bar', height: 230, stacked: true }, plotOptions: { bar: { horizontal: false, columnWidth: '40%', borderRadius: 4 } }, colors: ['#f59e0b', '#3b82f6'], xaxis: { categories: ['Clientes Actuales'] } }).render();
                new ApexCharts(document.querySelector("#chart-g4"), { ...commonOpt, series: [{ name: 'Nosotros', data: [<?= (int) ($data['mercado']['participacion']['nosotros'] ?? 0) ?>] }, { name: 'Competencia', data: [<?= (int) ($data['mercado']['participacion']['competencia'] ?? 0) ?>] }], chart: { type: 'bar', height: 230, stacked: true }, plotOptions: { bar: { horizontal: false, columnWidth: '40%', borderRadius: 4 } }, colors: ['#10b981', '#ef4444'], xaxis: { categories: ['Penetración'] } }).render();
                new ApexCharts(document.querySelector("#chart-g5"), { ...commonOpt, series: [{ name: 'Votos', data: [<?= $data['mercado']['que_busca']['agilidad'] ?>, <?= $data['mercado']['que_busca']['cajeros'] ?>, <?= $data['mercado']['que_busca']['banca'] ?>, <?= $data['mercado']['que_busca']['agencias'] ?>, <?= $data['mercado']['que_busca']['credito'] ?>, <?= $data['mercado']['que_busca']['debito'] ?>, <?= $data['mercado']['que_busca']['tc'] ?>] }], chart: { type: 'bar', height: 280 }, plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#4f46e5'], xaxis: { categories: ['Agilidad', 'Cajeros', 'Banca en línea', 'Agencias', 'Crédito rápido', 'T. Débito', 'T. Crédito'] } }).render();
                new ApexCharts(document.querySelector("#chart-g6"), { ...commonOpt, series: [{ name: 'Acuerdos', data: [<?= $data['mercado']['acuerdo_logrado']['campo'] ?>, <?= $data['mercado']['acuerdo_logrado']['oficina'] ?>, <?= $data['mercado']['acuerdo_logrado']['reprogramacion'] ?>, <?= $data['mercado']['acuerdo_logrado']['seguimiento'] ?>, <?= $data['mercado']['acuerdo_logrado']['tasas'] ?>, <?= $data['mercado']['acuerdo_logrado']['otro'] ?>] }], chart: { type: 'bar', height: 280 }, plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#9333ea'], xaxis: { categories: ['Cita Campo', 'Cita Oficina', 'Reprog.', 'Seguimiento', 'Tasas Comp.', 'Otro'] } }).render();
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'interes'): ?>
            try {
                renderSpeedGauge("#gauge-interes-general", <?= (float)($data['interes']['general']['si_pct'] ?? 0) ?>, 'INTERÉS', '#3b82f6');
                renderSpeedGauge("#gauge-interes-producto", <?= max((float)($data['interes']['productos']['ahorro_pct'] ?? 0), (float)($data['interes']['productos']['credito_pct'] ?? 0), (float)($data['interes']['productos']['inversion_pct'] ?? 0)) ?>, 'TOP PRODUCTO', '#10b981');
                renderSpeedGauge("#gauge-interes-destino", <?= (float)($data['interes']['destinos_base_si'] ?? 0) > 0 && !empty($data['interes']['destinos']) ? round((max($data['interes']['destinos']) / max(1, (int)($data['interes']['destinos_base_si'] ?? 0))) * 100, 1) : 0 ?>, 'TOP DESTINO', '#f59e0b');
                new ApexCharts(document.querySelector("#chart-i1"), { ...commonOpt, series: [<?= (int) ($data['interes']['general']['si'] ?? 0) ?>, <?= (int) ($data['interes']['general']['no'] ?? 0) ?>], chart: { type: 'donut', height: 230 }, labels: ['Interesados (SI)', 'Sin Interés (NO)'], colors: [palette[0], '#cbd5e1'] }).render();
                new ApexCharts(document.querySelector("#chart-i2"), { ...commonOpt, series: [{ name: 'Vectores', data: [<?= (int) ($data['interes']['productos']['ahorro'] ?? 0) ?>, <?= (int) ($data['interes']['productos']['credito'] ?? 0) ?>, <?= (int) ($data['interes']['productos']['inversion'] ?? 0) ?>] }], chart: { type: 'bar', height: 230 }, xaxis: { categories: ['Ahorro', 'Crédito', 'Inversión'] }, colors: [palette[1], palette[2], palette[0]], plotOptions: { bar: { distributed: true, borderRadius: 8 } } }).render();

                const dLabels = <?= json_encode(array_keys($data['interes']['destinos'] ?? [])) ?>;
                const dValues = <?= json_encode(array_values($data['interes']['destinos'] ?? [])) ?>;
                new ApexCharts(document.querySelector("#chart-i3"), { ...commonOpt, series: [{ name: 'Casos', data: dValues }], chart: { type: 'bar', height: 280 }, xaxis: { categories: dLabels }, colors: palette, plotOptions: { bar: { distributed: true, horizontal: true, borderRadius: 6 } } }).render();
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'prospeccion'): ?>
            try {
                new ApexCharts(document.querySelector("#chart-prospeccion"), { chart: { type: 'radialBar', height: 280, sparkline: { enabled: true } }, series: [<?= $data['prospeccion']['pct'] ?>], colors: ['#3b82f6'], plotOptions: { radialBar: { startAngle: -90, endAngle: 90, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: false }, value: { offsetY: -2, fontSize: '22px', fontWeight: '800' } } } }, grid: { padding: { top: -10 } }, stroke: { lineCap: 'round' } }).render();
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'postventa'): ?>
            try {
                // Gráfica Radial de Fidelización
                new ApexCharts(document.querySelector("#chart-postventa-radial"), {
                    chart: { type: 'radialBar', height: 350 },
                    series: [<?= $data['postventa']['pct'] ?>],
                    colors: ['#8b5cf6'],
                    plotOptions: {
                        radialBar: {
                            startAngle: -135, endAngle: 135,
                            hollow: { size: '70%' },
                            track: { background: '#f1f5f9', strokeWidth: '97%' },
                            dataLabels: {
                                name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90, label: 'REPRÉSTAMOS' },
                                value: { offsetY: -10, fontSize: '38px', fontWeight: '900', formatter: (v) => v + '%' }
                            }
                        }
                    },
                    stroke: { lineCap: 'round' }
                }).render();

                // Gráfica Radial de Desembolsos
                new ApexCharts(document.querySelector("#chart-postventa-desembolsos"), {
                    chart: { type: 'radialBar', height: 350 },
                    series: [<?= $data['postventa']['desembolsos']['pct'] ?>],
                    colors: ['#0ea5e9'],
                    plotOptions: {
                        radialBar: {
                            startAngle: -135, endAngle: 135,
                            hollow: { size: '70%' },
                            track: { background: '#f1f5f9', strokeWidth: '97%' },
                            dataLabels: {
                                name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90, label: 'EFICIENCIA COLOCACIÓN' },
                                value: { offsetY: -10, fontSize: '38px', fontWeight: '900', formatter: (v) => v + '%' }
                            }
                        }
                    },
                    stroke: { lineCap: 'round' }
                }).render();
            } catch (e) { console.error("Error en Post-Venta Charts:", e); }
        <?php elseif ($view === 'frio'): ?>
            try {
                new ApexCharts(document.querySelector("#chart-frio"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['frio']['frio_pct'] ?>], colors: ['#f59e0b'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();

                new ApexCharts(document.querySelector("#chart-oficina"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['frio']['oficina_pct'] ?>], colors: ['#8b5cf6'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();

                new ApexCharts(document.querySelector("#chart-leads"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['frio']['leads_pct'] ?>], colors: ['#10b981'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();

                new ApexCharts(document.querySelector("#chart-seguidores"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['frio']['seguidores_pct'] ?>], colors: ['#ef4444'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'evaluacion'): ?>
            try {
                new ApexCharts(document.querySelector("#chart-evaluacion"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['evaluacion']['evaluacion_pct'] ?>], colors: ['#0ea5e9'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();

                new ApexCharts(document.querySelector("#chart-aprobados"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['evaluacion']['aprobados_pct'] ?>], colors: ['#8b5cf6'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();
                new ApexCharts(document.querySelector("#chart-global-conv"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['evaluacion']['global_pct'] ?>], colors: ['#10b981'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();

                new ApexCharts(document.querySelector("#chart-g3eval"), { ...commonOpt, series: [{ name: 'Votos', data: [<?= $data['evaluacion']['que_busca']['agilidad'] ?>, <?= $data['evaluacion']['que_busca']['cajeros'] ?>, <?= $data['evaluacion']['que_busca']['banca'] ?>, <?= $data['evaluacion']['que_busca']['agencias'] ?>, <?= $data['evaluacion']['que_busca']['credito'] ?>, <?= $data['evaluacion']['que_busca']['debito'] ?>, <?= $data['evaluacion']['que_busca']['tc'] ?>] }], chart: { type: 'bar', height: 280 }, plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#0ea5e9'], xaxis: { categories: ['Agilidad', 'Cajeros', 'Banca en línea', 'Agencias', 'Crédito rápido', 'T. Débito', 'T. Crédito'] } }).render();
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'recuperacion'): ?>
            try {
                new ApexCharts(document.querySelector("#chart-recuperacion"), { chart: { type: 'radialBar', height: 350 }, series: [<?= $data['recuperacion']['recuperacion_pct'] ?>], colors: ['#ef4444'], plotOptions: { radialBar: { startAngle: -135, endAngle: 135, hollow: { size: '70%' }, track: { background: '#e2e8f0', strokeWidth: '97%' }, dataLabels: { name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90 }, value: { offsetY: -10, fontSize: '38px', fontWeight: '900' } } } }, stroke: { lineCap: 'round' } }).render();
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'operaciones'): ?>
            try {
                const opOpts = { chart: { type: 'radialBar', height: 180, sparkline: { enabled: true } }, plotOptions: { radialBar: { hollow: { size: '60%' }, dataLabels: { name: { show: false }, value: { offsetY: 5, fontSize: '14px', fontWeight: '800' } } } }, stroke: { lineCap: 'round' } };

                new ApexCharts(document.querySelector("#chart-op-ahorro"), { ...opOpts, series: [<?= $data['operaciones']['sol_ahorro'] > 0 ? round(($data['operaciones']['des_ahorro'] / $data['operaciones']['sol_ahorro']) * 100, 1) : 0 ?>], colors: ['#10b981'] }).render();
                new ApexCharts(document.querySelector("#chart-op-corriente"), { ...opOpts, series: [<?= $data['operaciones']['sol_corriente'] > 0 ? round(($data['operaciones']['des_corriente'] / $data['operaciones']['sol_corriente']) * 100, 1) : 0 ?>], colors: ['#3b82f6'] }).render();
                new ApexCharts(document.querySelector("#chart-op-inversion"), { ...opOpts, series: [<?= $data['operaciones']['sol_inversion'] > 0 ? round(($data['operaciones']['des_inversion'] / $data['operaciones']['sol_inversion']) * 100, 1) : 0 ?>], colors: ['#f59e0b'] }).render();
                new ApexCharts(document.querySelector("#chart-op-credito"), { ...opOpts, series: [<?= $data['operaciones']['sol_credito'] > 0 ? round(($data['operaciones']['des_credito'] / $data['operaciones']['sol_credito']) * 100, 1) : 0 ?>], colors: ['#8b5cf6'] }).render();

                <?php if ($hasEncNeg): ?>
                    new ApexCharts(document.querySelector("#chart-comparativa-empresa"), {
                        chart: { type: 'bar', height: 200, toolbar: { show: false } },
                        plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '60%' } },
                        series: [{
                            name: 'Tasa de Aprobación',
                            data: [<?= $data['operaciones']['comp_empresa']['con_emp']['pct'] ?>, <?= $data['operaciones']['comp_empresa']['sin_emp']['pct'] ?>]
                        }],
                        xaxis: { categories: ['Con Empresa', 'Sin Empresa'], labels: { formatter: (v) => v + '%' } },
                        colors: ['#10b981', '#3b82f6'],
                        dataLabels: { enabled: true, formatter: (v) => v + '%' }
                    }).render();
                <?php endif; ?>
            } catch (e) { console.error(e); }
        <?php elseif ($view === 'eficiencia'): ?>
            try {
                new ApexCharts(document.querySelector("#chart-proceso-eficiencia"), {
                    chart: { type: 'radialBar', height: 350 },
                    series: [<?= $data['eficiencia']['eficiencia'] ?>],
                    colors: ['#6366f1'],
                    plotOptions: {
                        radialBar: {
                            startAngle: -135, endAngle: 135,
                            hollow: { size: '70%' },
                            track: { background: '#f1f5f9', strokeWidth: '97%' },
                            dataLabels: {
                                name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90, label: 'EFICIENCIA' },
                                value: { offsetY: -10, fontSize: '38px', fontWeight: '900', formatter: (v) => v + '%' }
                            }
                        }
                    },
                    stroke: { lineCap: 'round' }
                }).render();

                new ApexCharts(document.querySelector("#chart-proceso-embudo"), {
                    chart: { type: 'bar', height: 200, toolbar: { show: false } },
                    plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '60%', distributed: true } },
                    series: [{
                        name: 'Casos',
                        data: [<?= $data['eficiencia']['contactos'] ?>, <?= $data['eficiencia']['entrevistas'] ?>, <?= $data['eficiencia']['interes'] ?>]
                    }],
                    xaxis: { categories: ['Contactos', 'Entrevistas', 'Interés'], labels: { show: false } },
                    colors: ['#cbd5e1', '#94a3b8', '#ec4899'],
                    dataLabels: { enabled: true, style: { colors: ['#fff'] } }
                }).render();

                // Gráfica Empresa
                new ApexCharts(document.querySelector("#chart-eficiencia-empresa"), {
                    chart: { type: 'radialBar', height: 350 },
                    series: [<?= $data['eficiencia']['empresa']['pct'] ?>],
                    colors: ['#10b981'],
                    plotOptions: {
                        radialBar: {
                            startAngle: -135, endAngle: 135,
                            hollow: { size: '70%' },
                            track: { background: '#f1f5f9', strokeWidth: '97%' },
                            dataLabels: {
                                name: { show: true, fontSize: '11px', color: '#64748b', offsetY: 90, label: 'LEVANTAMIENTO' },
                                value: { offsetY: -10, fontSize: '38px', fontWeight: '900', formatter: (v) => v + '%' }
                            }
                        }
                    },
                    stroke: { lineCap: 'round' }
                }).render();

            } catch (e) { console.error(e); }
        <?php endif; ?>
    </script>
    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</body>

</html>

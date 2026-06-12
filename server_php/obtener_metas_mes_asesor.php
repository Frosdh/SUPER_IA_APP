<?php
// ============================================================
// obtener_metas_mes_asesor.php
// Devuelve el resumen MENSUAL de metas asignadas y avance del asesor.
// Suma las metas diarias (meta_asesor_diaria) y sus avances
// (v_meta_asesor_avance) dentro del mes solicitado, para mostrar
// en móvil "Metas del mes" con % de cumplimiento, igual que
// "Metas del día" pero a nivel mensual.
// Usa mysqli ($conn) provisto por db_config.php
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/db_config.php';

function resp($ok, $data = [], $msg = '') {
    echo json_encode(array_merge(
        ['status' => $ok ? 'success' : 'error', 'message' => $msg],
        $data
    ));
    exit;
}

if (!$conn || $conn->connect_errno) {
    resp(false, [], 'DB no disponible');
}
$conn->set_charset('utf8mb4');

try {
    $asesor_id  = $_POST['asesor_id']  ?? $_GET['asesor_id']  ?? '';
    $usuario_id = $_POST['usuario_id'] ?? $_GET['usuario_id'] ?? '';
    $mes        = $_POST['mes']        ?? $_GET['mes']        ?? date('Y-m'); // YYYY-MM

    if (!$asesor_id && !$usuario_id) {
        resp(false, [], 'Falta asesor_id o usuario_id');
    }

    // Resolver asesor_id desde usuario_id si hace falta
    if (!$asesor_id && $usuario_id) {
        $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->bind_param('s', $usuario_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $asesor_id = $row['id'] ?? '';
        $st->close();
    }

    if (!$asesor_id) {
        resp(false, [], 'Asesor no encontrado');
    }

    // Validar formato de mes (YYYY-MM); si viene mal, usar el mes actual
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }
    $fecha_inicio = $mes . '-01';
    $fecha_fin    = date('Y-m-t', strtotime($fecha_inicio)); // último día del mes

    // Si la tabla no existe aún, no lanzar excepción (para UX móvil)
    $chk = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' LIMIT 1");
    $existeTabla = $chk ? (bool)($chk->fetch_row()[0] ?? false) : false;
    if (!$existeTabla) {
        resp(true, [
            'tiene_meta' => false,
            'meta'       => null,
            'mes'        => $mes,
            'mensaje_ui' => 'El módulo de metas no está instalado en la base de datos. Pide al administrador ejecutar el script crear_tabla_metas_asesor.sql.'
        ]);
    }

    // Campos de metas/avances que se suman para el mes
    $campos = ['encuestas','clientes_nuevos','creditos','cuenta_ahorros','cuenta_corriente',
               'inversiones','visitas','monto_creditos_aprobados','cuentas_ahorro_abiertas',
               'inversiones_aprobadas'];

    // Detectar dinámicamente qué columnas meta_* existen en meta_asesor_diaria
    // y qué columnas avance_* existen en v_meta_asesor_avance, para no romper
    // la consulta si todavía no se han migrado las columnas nuevas.
    $colsMeta = [];
    $rcm = $conn->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria'");
    if ($rcm) {
        while ($row = $rcm->fetch_assoc()) { $colsMeta[$row['COLUMN_NAME']] = true; }
    }

    $colsAvance = [];
    $existeVista = false;
    $rca = $conn->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'v_meta_asesor_avance'");
    if ($rca) {
        while ($row = $rca->fetch_assoc()) { $colsAvance[$row['COLUMN_NAME']] = true; $existeVista = true; }
    }

    $selectMeta = [];
    foreach ($campos as $c) {
        if (isset($colsMeta["meta_$c"])) {
            $selectMeta[] = "COALESCE(SUM(m.meta_$c),0) AS meta_$c";
        }
    }

    $selectAv = [];
    if ($existeVista) {
        foreach ($campos as $c) {
            if (isset($colsAvance["avance_$c"])) {
                $selectAv[] = "COALESCE(SUM(v.avance_$c),0) AS avance_$c";
            }
        }
    }

    $datos = null;

    if (!empty($selectMeta)) {
        if (!empty($selectAv)) {
            $sql = "SELECT " . implode(', ', array_merge($selectMeta, $selectAv)) . ",
                           COUNT(*) AS dias_asignados,
                           MAX(m.observaciones) AS observaciones
                    FROM meta_asesor_diaria m
                    LEFT JOIN v_meta_asesor_avance v ON v.meta_id = m.id
                    WHERE m.asesor_id = ? AND m.fecha BETWEEN ? AND ?";
        } else {
            // Sin vista de avances (o sin columnas avance_* compatibles): solo metas
            $sql = "SELECT " . implode(', ', $selectMeta) . ",
                           COUNT(*) AS dias_asignados,
                           MAX(m.observaciones) AS observaciones
                    FROM meta_asesor_diaria m
                    WHERE m.asesor_id = ? AND m.fecha BETWEEN ? AND ?";
        }

        $st = null;
        try {
            $st = $conn->prepare($sql);
        } catch (Throwable $e) {
            $st = false;
        }

        if ($st) {
            $st->bind_param('sss', $asesor_id, $fecha_inicio, $fecha_fin);
            $st->execute();
            $res = $st->get_result();
            $datos = $res ? $res->fetch_assoc() : null;
            $st->close();
        }
    }

    // Completar con 0 los campos meta_*/avance_* que no existan o no se hayan consultado
    if ($datos) {
        foreach ($campos as $c) {
            if (!isset($datos["meta_$c"]))   { $datos["meta_$c"] = 0; }
            if (!isset($datos["avance_$c"])) { $datos["avance_$c"] = 0; }
        }
    }

    $diasAsignados = (int)($datos['dias_asignados'] ?? 0);

    if (!$datos || $diasAsignados === 0) {
        resp(true, [
            'tiene_meta' => false,
            'meta'       => null,
            'mes'        => $mes,
            'mensaje_ui' => 'El supervisor aún no te asignó metas para este mes.'
        ]);
    }

    // Normalizar numéricos
    foreach ($campos as $c) {
        $datos["meta_$c"]   = (int)($datos["meta_$c"] ?? 0);
        $datos["avance_$c"] = (int)($datos["avance_$c"] ?? 0);
    }

    // Progreso global (%) del mes
    $totalMeta = 0; $totalAv = 0;
    foreach ($campos as $c) {
        if ($datos["meta_$c"] > 0) {
            $totalMeta += $datos["meta_$c"];
            $totalAv   += min($datos["avance_$c"], $datos["meta_$c"]);
        }
    }
    $pct = $totalMeta > 0 ? round($totalAv * 100 / $totalMeta) : 0;
    $cumplido = $totalMeta > 0 && $totalAv >= $totalMeta;

    // ¿El mes consultado ya terminó (es anterior al mes actual)?
    $mesActual  = date('Y-m');
    $mesCerrado = $mes < $mesActual;

    if ($cumplido) {
        $estado = 'completado';
    } elseif ($mesCerrado) {
        $estado = 'no_cumplido';
    } else {
        $estado = 'pendiente';
    }

    $labels = [
        'encuestas'        => ['label' => 'Encuestas',          'icon' => 'poll'],
        'clientes_nuevos'  => ['label' => 'Clientes nuevos',    'icon' => 'user-plus'],
        'creditos'         => ['label' => 'Créditos',           'icon' => 'hand-holding-usd'],
        'cuenta_ahorros'   => ['label' => 'Cuentas de ahorro',  'icon' => 'piggy-bank'],
        'cuenta_corriente' => ['label' => 'Cuentas corrientes', 'icon' => 'wallet'],
        'inversiones'      => ['label' => 'Inversiones',        'icon' => 'chart-line'],
        'visitas'          => ['label' => 'Visitas',            'icon' => 'walking'],
        'monto_creditos_aprobados'   => ['label' => 'Monto créditos aprobados (mes)', 'icon' => 'dollar-sign', 'monto' => true],
        'cuentas_ahorro_abiertas'    => ['label' => 'Cuentas de ahorro abiertas', 'icon' => 'coins'],
        'inversiones_aprobadas'      => ['label' => 'Inversiones aprobadas',      'icon' => 'chart-pie'],
    ];

    $items = [];
    foreach ($labels as $k => $info) {
        $m = $datos["meta_$k"]; $a = $datos["avance_$k"];
        $items[] = [
            'clave'    => $k,
            'label'    => $info['label'],
            'icon'     => $info['icon'],
            'meta'     => $m,
            'avance'   => $a,
            'es_monto' => !empty($info['monto']),
            'cumplido' => ($m > 0 && $a >= $m),
            'pct'      => $m > 0 ? min(100, (int)round($a * 100 / $m)) : 0,
        ];
    }

    resp(true, [
        'tiene_meta' => true,
        'meta' => [
            'mes'            => $mes,
            'fecha_inicio'   => $fecha_inicio,
            'fecha_fin'      => $fecha_fin,
            'estado'         => $estado,
            'dias_asignados' => $diasAsignados,
            'observaciones'  => $datos['observaciones'] ?? '',
            'pct_total'      => $pct,
            'items'          => $items,
        ]
    ]);

} catch (Throwable $e) {
    resp(false, [], $e->getMessage());
}

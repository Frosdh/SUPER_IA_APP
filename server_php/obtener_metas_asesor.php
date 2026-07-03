<?php
// ============================================================
// obtener_metas_asesor.php
// Devuelve la meta diaria del asesor con avance en tiempo real.
// Aplica cierre lógico a las 18:00 (no cumplido) si no se cumplió.
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
    $fecha      = $_POST['fecha']      ?? $_GET['fecha']      ?? date('Y-m-d');

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

    // Si la tabla no existe aún, no lanzar excepción (para UX móvil)
    $chk = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' LIMIT 1");
    $existeTabla = $chk ? (bool)($chk->fetch_row()[0] ?? false) : false;
    if (!$existeTabla) {
        resp(true, [
            'tiene_meta' => false,
            'meta'       => null,
            'fecha'      => $fecha,
            'mensaje_ui' => 'El módulo de metas no está instalado en la base de datos. Pide al administrador ejecutar el script crear_tabla_metas_asesor.sql.'
        ]);
    }

    // Cargar meta del día directamente de meta_asesor_diaria.
    // NOTA: ya NO dependemos de la vista v_meta_asesor_avance: si esa vista
    // fallaba por cualquier motivo (columna renombrada, colación, etc.), todo
    // el bloque de avances se devolvía en 0 sin avisar — por eso "se veía vacío"
    // aunque el asesor sí hubiera hecho encuestas. Ahora cada avance se calcula
    // con su propia consulta directa, así un problema en una métrica no apaga
    // las demás.
    $meta = null;
    $sql2 = "SELECT *, id AS meta_id FROM meta_asesor_diaria WHERE asesor_id = ? AND fecha = ? LIMIT 1";
    $st2 = $conn->prepare($sql2);
    if (!$st2) {
        resp(true, [
            'tiene_meta' => false,
            'meta'       => null,
            'fecha'      => $fecha,
            'mensaje_ui' => 'Ocurrió un error leyendo las metas. Pide al administrador que revise la tabla meta_asesor_diaria.'
        ]);
    }
    $st2->bind_param('ss', $asesor_id, $fecha);
    $st2->execute();
    $res2 = $st2->get_result();
    $meta = $res2 ? $res2->fetch_assoc() : null;
    $st2->close();

    if ($meta) {
        // Helper: ejecuta una consulta de avance de forma aislada, con sus
        // propios parámetros. Si falla, SOLO esa métrica queda en 0 (y se
        // loguea el error real) — así un problema puntual no apaga las demás.
        $avance = function (string $sql, array $params, string $etiqueta) use ($conn) {
            try {
                $st = $conn->prepare($sql);
                if (!$st) throw new Exception($conn->error);
                $st->bind_param(str_repeat('s', count($params)), ...$params);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                return (int)($row['n'] ?? 0);
            } catch (Throwable $e) {
                error_log("[obtener_metas_asesor][avance_$etiqueta] " . $e->getMessage());
                return 0;
            }
        };

        $meta['avance_encuestas'] = $avance(
            "SELECT
                (SELECT COUNT(*) FROM encuesta_comercial ec
                    INNER JOIN tarea t ON t.id = ec.tarea_id
                    WHERE t.asesor_id = ? AND IFNULL(t.fecha_realizada, t.fecha_programada) = ?)
              + (SELECT COUNT(*) FROM encuesta_crediticia ecr
                    INNER JOIN tarea t2 ON t2.id = ecr.tarea_id
                    WHERE t2.asesor_id = ? AND IFNULL(t2.fecha_realizada, t2.fecha_programada) = ?) AS n",
            [$asesor_id, $fecha, $asesor_id, $fecha],
            'encuestas'
        );

        $meta['avance_clientes_nuevos'] = $avance(
            "SELECT COUNT(*) AS n FROM cliente_prospecto cp
             WHERE cp.asesor_id = ? AND cp.estado IN ('prospecto','cliente','pendiente')
               AND CAST(cp.created_at AS DATE) = ?",
            [$asesor_id, $fecha],
            'clientes_nuevos'
        );

        $meta['avance_creditos'] = $avance(
            "SELECT COUNT(*) AS n FROM credito_proceso cr
             WHERE cr.asesor_id = ? AND CAST(cr.created_at AS DATE) = ?",
            [$asesor_id, $fecha],
            'creditos'
        );

        $meta['avance_visitas'] = $avance(
            "SELECT COUNT(*) AS n FROM tarea t
             WHERE t.asesor_id = ? AND t.estado = 'completada'
               AND IFNULL(t.fecha_realizada, t.fecha_programada) = ?",
            [$asesor_id, $fecha],
            'visitas'
        );

        $meta['avance_monto_creditos_aprobados'] = $avance(
            "SELECT COALESCE(SUM(cr.monto_aprobado),0) AS n FROM credito_proceso cr
             WHERE cr.asesor_id = ? AND cr.estado_credito IN ('aprobado','desembolsado')
               AND cr.updated_at IS NOT NULL AND CAST(cr.updated_at AS DATE) = ?",
            [$asesor_id, $fecha],
            'monto_creditos_aprobados'
        );

        // Interés en cuenta de ahorro / corriente / inversión detectado en las encuestas del día
        $meta['avance_cuenta_ahorros'] = $avance(
            "SELECT COUNT(*) AS n FROM encuesta_comercial ec INNER JOIN tarea t ON t.id = ec.tarea_id
             WHERE t.asesor_id = ? AND IFNULL(t.fecha_realizada, t.fecha_programada) = ? AND ec.interes_ahorro = 1",
            [$asesor_id, $fecha],
            'cuenta_ahorros'
        );
        $meta['avance_cuenta_corriente'] = $avance(
            "SELECT COUNT(*) AS n FROM encuesta_comercial ec INNER JOIN tarea t ON t.id = ec.tarea_id
             WHERE t.asesor_id = ? AND IFNULL(t.fecha_realizada, t.fecha_programada) = ? AND ec.interes_cc = 1",
            [$asesor_id, $fecha],
            'cuenta_corriente'
        );
        $meta['avance_inversiones'] = $avance(
            "SELECT COUNT(*) AS n FROM encuesta_comercial ec INNER JOIN tarea t ON t.id = ec.tarea_id
             WHERE t.asesor_id = ? AND IFNULL(t.fecha_realizada, t.fecha_programada) = ? AND ec.interes_inversion = 1",
            [$asesor_id, $fecha],
            'inversiones'
        );

        // Productos realmente aprobados (ficha_producto), si la tabla existe
        $meta['avance_cuentas_ahorro_abiertas'] = $avance(
            "SELECT COUNT(*) AS n FROM ficha_producto fp
             WHERE fp.producto_tipo = 'cuenta_ahorros' AND fp.estado_revision = 'aprobada'
               AND fp.revision_at IS NOT NULL
               AND (fp.asesor_id = ? OR fp.usuario_id = (SELECT usuario_id FROM asesor WHERE id = ?))
               AND CAST(fp.revision_at AS DATE) = ?",
            [$asesor_id, $asesor_id, $fecha],
            'cuentas_ahorro_abiertas'
        );
        $meta['avance_inversiones_aprobadas'] = $avance(
            "SELECT COUNT(*) AS n FROM ficha_producto fp
             WHERE fp.producto_tipo = 'inversiones' AND fp.estado_revision = 'aprobada'
               AND fp.revision_at IS NOT NULL
               AND (fp.asesor_id = ? OR fp.usuario_id = (SELECT usuario_id FROM asesor WHERE id = ?))
               AND CAST(fp.revision_at AS DATE) = ?",
            [$asesor_id, $asesor_id, $fecha],
            'inversiones_aprobadas'
        );
    }

    if (!$meta) {
        resp(true, [
            'tiene_meta' => false,
            'meta'       => null,
            'fecha'      => $fecha,
            'mensaje_ui' => 'El supervisor aún no te asignó metas para hoy.'
        ]);
    }

    // Normalizar numéricos
    $ints = ['meta_encuestas','meta_clientes_nuevos','meta_creditos',
             'meta_cuenta_ahorros','meta_cuenta_corriente','meta_inversiones',
             'meta_visitas',
             'meta_monto_creditos_aprobados','meta_cuentas_ahorro_abiertas',
             'meta_inversiones_aprobadas',
             'avance_encuestas','avance_clientes_nuevos','avance_creditos',
             'avance_cuenta_ahorros','avance_cuenta_corriente','avance_inversiones',
             'avance_visitas',
             'avance_monto_creditos_aprobados','avance_cuentas_ahorro_abiertas',
             'avance_inversiones_aprobadas'];
    foreach ($ints as $k) { $meta[$k] = (int)($meta[$k] ?? 0); }

    // ── Evaluar estado automáticamente ───────────────────────
    $hoy        = date('Y-m-d');
    $horaActual = (int)date('H');
    $debeCerrar = false;

    if ($meta['estado'] === 'pendiente') {
        if ($fecha < $hoy) {
            $debeCerrar = true;
        } elseif ($fecha === $hoy && $horaActual >= 18) {
            $debeCerrar = true;
        }
    }

    // ¿Cumplió todas las metas >0?
    $cumplio = true;
    $pares = [
        ['meta_encuestas','avance_encuestas'],
        ['meta_clientes_nuevos','avance_clientes_nuevos'],
        ['meta_creditos','avance_creditos'],
        ['meta_cuenta_ahorros','avance_cuenta_ahorros'],
        ['meta_cuenta_corriente','avance_cuenta_corriente'],
        ['meta_inversiones','avance_inversiones'],
        ['meta_visitas','avance_visitas'],
        ['meta_monto_creditos_aprobados','avance_monto_creditos_aprobados'],
        ['meta_cuentas_ahorro_abiertas','avance_cuentas_ahorro_abiertas'],
        ['meta_inversiones_aprobadas','avance_inversiones_aprobadas'],
    ];
    foreach ($pares as [$mk, $ak]) {
        if ($meta[$mk] > 0 && $meta[$ak] < $meta[$mk]) { $cumplio = false; break; }
    }

    if ($meta['estado'] === 'pendiente' && $cumplio) {
        $u = $conn->prepare('UPDATE meta_asesor_diaria SET estado="completado", cerrado_at=NOW() WHERE id=?');
        $u->bind_param('s', $meta['meta_id']);
        $u->execute(); $u->close();
        $meta['estado'] = 'completado';
    } elseif ($debeCerrar && !$cumplio) {
        $u = $conn->prepare('UPDATE meta_asesor_diaria SET estado="no_cumplido", cerrado_at=NOW() WHERE id=?');
        $u->bind_param('s', $meta['meta_id']);
        $u->execute(); $u->close();
        $meta['estado'] = 'no_cumplido';
    }

    // Progreso global (%)
    $totalMeta = 0; $totalAv = 0;
    foreach ($pares as [$mk, $ak]) {
        if ($meta[$mk] > 0) {
            $totalMeta += $meta[$mk];
            $totalAv   += min($meta[$ak], $meta[$mk]);
        }
    }
    $pct = $totalMeta > 0 ? round($totalAv * 100 / $totalMeta) : 0;

    $labels = [
        'encuestas'        => ['label' => 'Encuestas',          'icon' => 'poll'],
        'clientes_nuevos'  => ['label' => 'Clientes nuevos',    'icon' => 'user-plus'],
        'creditos'         => ['label' => 'Créditos',           'icon' => 'hand-holding-usd'],
        'cuenta_ahorros'   => ['label' => 'Cuentas de ahorro',  'icon' => 'piggy-bank'],
        'cuenta_corriente' => ['label' => 'Cuentas corrientes', 'icon' => 'wallet'],
        'inversiones'      => ['label' => 'Inversiones',        'icon' => 'chart-line'],
        'visitas'          => ['label' => 'Visitas',            'icon' => 'walking'],
        'monto_creditos_aprobados'   => ['label' => 'Monto créditos aprobados (día)', 'icon' => 'dollar-sign', 'monto' => true],
        'cuentas_ahorro_abiertas'    => ['label' => 'Cuentas de ahorro abiertas', 'icon' => 'coins'],
        'inversiones_aprobadas'      => ['label' => 'Inversiones aprobadas',      'icon' => 'chart-pie'],
    ];
    $items = [];
    foreach ($labels as $k => $info) {
        $m = $meta["meta_$k"]; $a = $meta["avance_$k"];
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
            'meta_id'       => $meta['meta_id'],
            'fecha'         => $meta['fecha'],
            'estado'        => $meta['estado'],
            'observaciones' => $meta['observaciones'],
            'pct_total'     => $pct,
            'items'         => $items,
        ]
    ]);

} catch (Throwable $e) {
    resp(false, [], $e->getMessage());
}

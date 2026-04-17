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

    // Cargar meta del día (con fallback si la vista no existe)
    $meta = null;
    $sql = "SELECT m.id AS meta_id, m.asesor_id, m.fecha, m.estado, m.observaciones,
                   m.meta_encuestas, m.meta_clientes_nuevos, m.meta_creditos,
                   m.meta_cuenta_ahorros, m.meta_cuenta_corriente, m.meta_inversiones,
                   v.avance_encuestas, v.avance_clientes_nuevos, v.avance_creditos,
                   v.avance_cuenta_ahorros, v.avance_cuenta_corriente, v.avance_inversiones
            FROM meta_asesor_diaria m
            LEFT JOIN v_meta_asesor_avance v ON v.meta_id = m.id
            WHERE m.asesor_id = ? AND m.fecha = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('ss', $asesor_id, $fecha);
        $st->execute();
        $res = $st->get_result();
        $meta = $res ? $res->fetch_assoc() : null;
        $st->close();
    } else {
        // Fallback: sin avances (avances se devuelven como 0)
        $sql2 = "SELECT m.id AS meta_id, m.asesor_id, m.fecha, m.estado, m.observaciones,
                        m.meta_encuestas, m.meta_clientes_nuevos, m.meta_creditos,
                        m.meta_cuenta_ahorros, m.meta_cuenta_corriente, m.meta_inversiones
                 FROM meta_asesor_diaria m
                 WHERE m.asesor_id = ? AND m.fecha = ?
                 LIMIT 1";
        $st2 = $conn->prepare($sql2);
        if (!$st2) {
            throw new Exception('Error preparando consulta de metas: ' . $conn->error);
        }
        $st2->bind_param('ss', $asesor_id, $fecha);
        $st2->execute();
        $res2 = $st2->get_result();
        $meta = $res2 ? $res2->fetch_assoc() : null;
        $st2->close();
        if ($meta) {
            $meta['avance_encuestas'] = 0;
            $meta['avance_clientes_nuevos'] = 0;
            $meta['avance_creditos'] = 0;
            $meta['avance_cuenta_ahorros'] = 0;
            $meta['avance_cuenta_corriente'] = 0;
            $meta['avance_inversiones'] = 0;
        }
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
             'avance_encuestas','avance_clientes_nuevos','avance_creditos',
             'avance_cuenta_ahorros','avance_cuenta_corriente','avance_inversiones'];
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

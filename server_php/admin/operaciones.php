<?php
require_once 'db_admin.php';

function uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function table_exists_pdo(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function column_exists_pdo(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

/**
 * Promueve a CLIENTE cuando se aprueba una solicitud.
 * - Si existe en cliente_prospecto por cédula, actualiza estado='cliente'.
 * - Si no existe, intenta crearlo con campos mínimos disponibles.
 * No bloquea el flujo si la tabla/columnas no existen.
 */
function promover_a_cliente(PDO $pdo, ?string $cedula, ?string $nombre, ?string $asesorId): void {
    if (!$cedula) return;
    if (!table_exists_pdo($pdo, 'cliente_prospecto')) return;

    try {
        $st = $pdo->prepare('SELECT id, estado FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
        $st->execute([$cedula]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (($row['estado'] ?? '') !== 'cliente') {
                $upd = $pdo->prepare("UPDATE cliente_prospecto SET estado='cliente' WHERE id = ?");
                $upd->execute([(string)$row['id']]);
            }
            return;
        }

        // Insert mínimo (solo columnas que existan)
        $id = uuid4();
        $cols = ['id', 'cedula', 'estado'];
        $vals = [$id, $cedula, 'cliente'];

        if (column_exists_pdo($pdo, 'cliente_prospecto', 'nombre')) {
            $cols[] = 'nombre';
            $vals[] = ($nombre ?? '');
        }
        if ($asesorId && column_exists_pdo($pdo, 'cliente_prospecto', 'asesor_id')) {
            $cols[] = 'asesor_id';
            $vals[] = $asesorId;
        }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(', ', $cols);
        $ins = $pdo->prepare("INSERT INTO cliente_prospecto ($colList) VALUES ($ph)");
        $ins->execute($vals);
    } catch (Throwable $ignored) {
        // Silencioso: no impedir aprobación de ficha
    }
}

// Verificar sesión de super_admin, admin, supervisor o asesor
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
    $user_id = $_SESSION['super_admin_id'];
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
    $user_id = $_SESSION['admin_id'];
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
    $user_id = $_SESSION['supervisor_id'];
    // Obtener el id real del supervisor desde la tabla supervisor (si es necesario)
    // Suponiendo que $_SESSION['supervisor_id'] es el UUID de la tabla supervisor
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    $user_id = $_SESSION['asesor_id']; // ID de la tabla asesor
} else {
    header('Location: login.php?role=admin');
    exit;
}

// ── Resolver supervisor.id real desde la sesión (usuario_id) ─────
$supervisor_table_id = null;
if ($user_role === 'supervisor') {
    try {
        $stSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $stSup->execute([$user_id]);
        $supervisor_table_id = $stSup->fetchColumn() ?: $user_id;
    } catch (PDOException $e) { $supervisor_table_id = $user_id; }
}

// ── Resolver asesor.id real para asesor (sesión guarda usuario.id) ─
$asesor_table_id = null;
if ($user_role === 'asesor') {
    try {
        $stAs = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $stAs->execute([$user_id]);
        $asesor_table_id = $stAs->fetchColumn() ?: null;
    } catch (PDOException $e) { $asesor_table_id = null; }
}

// ── CSRF token (solo para acciones POST) ────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── Sub-sección: tipo de operación (crédito / inversiones / cuentas) ──
$tipos_map = [
    'credito' => [
        'label'       => 'Crédito',
        'titulo'      => 'Operaciones de Crédito',
        'tabla'       => 'ficha_credito',
        'alias'       => 'fc',
        'monto_col'   => 'monto_credito',
        'icon'        => 'fa-hand-holding-usd',
        'usa_proceso' => true,
    ],
    'inversiones' => [
        'label'       => 'Inversiones',
        'titulo'      => 'Operaciones de Inversiones',
        'tabla'       => 'ficha_inversiones',
        'alias'       => 'fi',
        'monto_col'   => 'monto_inversion',
        'icon'        => 'fa-chart-line',
        'usa_proceso' => false,
    ],
    'cuenta_ahorros' => [
        'label'       => 'Cuenta de Ahorros',
        'titulo'      => 'Solicitudes de Cuenta de Ahorros',
        'tabla'       => 'ficha_cuenta_ahorros',
        'alias'       => 'fa',
        'monto_col'   => 'monto_inicial',
        'icon'        => 'fa-piggy-bank',
        'usa_proceso' => false,
    ],
    'cuenta_corriente' => [
        'label'       => 'Cuenta Corriente',
        'titulo'      => 'Solicitudes de Cuenta Corriente',
        'tabla'       => 'ficha_cuenta_corriente',
        'alias'       => 'fcc',
        'monto_col'   => 'monto_deposito_prom',
        'icon'        => 'fa-wallet',
        'usa_proceso' => false,
    ],
];

$tipo = $_GET['tipo'] ?? $_POST['tipo'] ?? 'credito';
if (!isset($tipos_map[$tipo])) $tipo = 'credito';
$tipo_info = $tipos_map[$tipo];

// ── Migración no destructiva: estado de revisión de fichas ───
try {
    $exists = $pdo->query("SHOW TABLES LIKE 'ficha_producto'")->fetchColumn();
    if ($exists) {
        $cols = [
            'estado_revision'        => "ALTER TABLE ficha_producto ADD COLUMN estado_revision ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente' AFTER producto_tipo",
            'revision_usuario_id'    => "ALTER TABLE ficha_producto ADD COLUMN revision_usuario_id CHAR(36) DEFAULT NULL AFTER estado_revision",
            'revision_at'            => "ALTER TABLE ficha_producto ADD COLUMN revision_at DATETIME DEFAULT NULL AFTER revision_usuario_id",
            'revision_observaciones' => "ALTER TABLE ficha_producto ADD COLUMN revision_observaciones TEXT DEFAULT NULL AFTER revision_at",
        ];
        foreach ($cols as $c => $ddl) {
            $st = $pdo->prepare("SHOW COLUMNS FROM ficha_producto LIKE ?");
            $st->execute([$c]);
            if (!$st->fetch()) {
                $pdo->exec($ddl);
            }
        }
    }
} catch (PDOException $e) {
    // silencioso
}

// ── Procesar aprobación/rechazo (solo solicitudes desde ficha) ─
$mensaje_exito = '';
$mensaje_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrf_token, $token)) {
        $mensaje_error = 'Solicitud inválida.';
    } else {
        $accion    = $_POST['accion'] ?? '';
        $origen    = $_POST['origen'] ?? '';
        $idFicha   = $_POST['id_ficha'] ?? '';
        $tipoPost  = $_POST['tipo'] ?? $tipo;
        if (!isset($tipos_map[$tipoPost])) $tipoPost = $tipo;
        $obs       = trim((string)($_POST['observaciones'] ?? ''));

        if ($origen === 'ficha' && is_string($idFicha) && $idFicha !== '' && ($accion === 'aprobar' || $accion === 'rechazar')) {
            if (!in_array($user_role, ['super_admin', 'admin', 'supervisor'], true)) {
                $mensaje_error = 'No autorizado.';
            } else {
                try {
                    // Verificar acceso según rol
                    if ($user_role === 'supervisor') {
                        $stOwn = $pdo->prepare(
                            "SELECT fp.id
                             FROM ficha_producto fp
                             WHERE fp.id = ? AND fp.producto_tipo = ?
                               AND (
                                 fp.asesor_id  COLLATE utf8mb4_unicode_ci IN (SELECT id         FROM asesor WHERE supervisor_id IN (?,?))
                                 OR fp.usuario_id COLLATE utf8mb4_unicode_ci IN (SELECT usuario_id FROM asesor WHERE supervisor_id IN (?,?))
                               )
                             LIMIT 1"
                        );
                        $stOwn->execute([$idFicha, $tipoPost,
                                         $supervisor_table_id, $user_id,
                                         $supervisor_table_id, $user_id]);
                        if (!$stOwn->fetchColumn()) {
                            throw new Exception('No tienes permiso para procesar esta solicitud.');
                        }
                    } else {
                        $stOwn = $pdo->prepare("SELECT id FROM ficha_producto WHERE id = ? AND producto_tipo = ? LIMIT 1");
                        $stOwn->execute([$idFicha, $tipoPost]);
                        if (!$stOwn->fetchColumn()) {
                            throw new Exception('Solicitud no encontrada.');
                        }
                    }

                    $nuevoEstado = ($accion === 'aprobar') ? 'aprobada' : 'rechazada';

                    $stUp = $pdo->prepare(
                        "UPDATE ficha_producto
                         SET estado_revision = ?,
                             revision_usuario_id = ?,
                             revision_at = NOW(),
                             revision_observaciones = ?
                         WHERE id = ? AND producto_tipo = ? AND estado_revision = 'pendiente'"
                    );
                    $stUp->execute([$nuevoEstado, (string)$user_id, $obs, $idFicha, $tipoPost]);

                    if ($stUp->rowCount() > 0) {
                        if ($accion === 'aprobar') {
                            // Promover prospecto a cliente al aprobar cualquier producto
                            try {
                                $stF = $pdo->prepare('SELECT cliente_cedula, cliente_nombre, asesor_id, usuario_id FROM ficha_producto WHERE id = ? AND producto_tipo = ? LIMIT 1');
                                $stF->execute([$idFicha, $tipoPost]);
                                $f = $stF->fetch(PDO::FETCH_ASSOC) ?: [];

                                $ced = isset($f['cliente_cedula']) ? (string)$f['cliente_cedula'] : null;
                                $nom = isset($f['cliente_nombre']) ? (string)$f['cliente_nombre'] : null;
                                $ases = isset($f['asesor_id']) ? (string)$f['asesor_id'] : null;

                                // Si no hay asesor_id directo, intentar resolver por usuario_id
                                if ((!$ases || $ases === '') && !empty($f['usuario_id'])) {
                                    try {
                                        $stA = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
                                        $stA->execute([(string)$f['usuario_id']]);
                                        $ases = (string)($stA->fetchColumn() ?: '');
                                    } catch (Throwable $e) {
                                        $ases = $ases ?: null;
                                    }
                                }

                                promover_a_cliente($pdo, $ced ?: null, $nom ?: null, $ases ?: null);
                            } catch (Throwable $ignored) {}
                        }
                        $mensaje_exito = ($accion === 'aprobar') ? 'Solicitud aprobada.' : 'Solicitud rechazada.';
                    } else {
                        $mensaje_error = 'No se pudo actualizar (quizá ya fue procesada).';
                    }
                } catch (Throwable $e) {
                    $mensaje_error = $e->getMessage();
                }
            }
        }
    }
}

$col_asesor = ($user_role !== 'asesor');
$operaciones = [];

// ═══════════════════════════════════════════════════════════
// FUENTE 1 — credito_proceso (procesos formales en sistema)
// ═══════════════════════════════════════════════════════════
try {
    if (!$tipo_info['usa_proceso']) {
        throw new Exception('skip');
    }
    if ($user_role === 'super_admin' || $user_role === 'admin') {
        $q = "SELECT cp.id as id_credito, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                     cp.monto_aprobado as cantidad, cp.estado_credito as estado,
                     cp.created_at as fecha_creacion, u.nombre as asesor_nombre, 'proceso' as origen
              FROM credito_proceso cp
              JOIN cliente_prospecto cl ON cp.cliente_prospecto_id = cl.id
              LEFT JOIN asesor a ON cp.asesor_id = a.id
              LEFT JOIN usuario u ON a.usuario_id = u.id
              ORDER BY cp.created_at DESC";
        $st = $pdo->query($q);
    } elseif ($user_role === 'supervisor') {
        $q = "SELECT cp.id as id_credito, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                     cp.monto_aprobado as cantidad, cp.estado_credito as estado,
                     cp.created_at as fecha_creacion, u.nombre as asesor_nombre, 'proceso' as origen
              FROM credito_proceso cp
              JOIN cliente_prospecto cl ON cp.cliente_prospecto_id = cl.id
              LEFT JOIN asesor a ON cp.asesor_id = a.id
              LEFT JOIN usuario u ON a.usuario_id = u.id
              WHERE a.supervisor_id = ?
              ORDER BY cp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$supervisor_table_id]);
    } else {
        $q = "SELECT cp.id as id_credito, cl.nombre as cliente_nombre, cl.cedula as cliente_cedula,
                     cp.monto_aprobado as cantidad, cp.estado_credito as estado,
                     cp.created_at as fecha_creacion, NULL as asesor_nombre, 'proceso' as origen
              FROM credito_proceso cp
              JOIN cliente_prospecto cl ON cp.cliente_prospecto_id = cl.id
              WHERE cp.asesor_id = ?
              ORDER BY cp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$asesor_table_id]);
    }
    $operaciones = array_merge($operaciones, $st->fetchAll());
} catch (Throwable $e) { /* tabla puede no existir aún o no aplica */ }

// ═══════════════════════════════════════════════════════════
// FUENTE 2 — ficha_producto + ficha_* (solicitudes desde app)
// ═══════════════════════════════════════════════════════════
// Columna estado_revision: intentar crearla si no existe (silencioso)
try {
    if ($pdo->query("SHOW TABLES LIKE 'ficha_producto'")->fetchColumn()) {
        $chk = $pdo->query("SHOW COLUMNS FROM ficha_producto LIKE 'estado_revision'")->fetchColumn();
        if (!$chk) {
            $pdo->exec("ALTER TABLE ficha_producto ADD COLUMN estado_revision VARCHAR(20) NOT NULL DEFAULT 'pendiente'");
        }
    }
} catch (Throwable $ignored) {}

$fuente2_error = '';
try {
    // Tabla específica según tipo
    $detail_table = $tipo_info['tabla'];
    $detail_alias = $tipo_info['alias'];
    $amount_col   = $tipo_info['monto_col'];

    // Si falta alguna tabla requerida, no rompemos la página
    $has_fp = $pdo->query("SHOW TABLES LIKE 'ficha_producto'")->fetchColumn();
    $has_detail = $pdo->query("SHOW TABLES LIKE '$detail_table'")->fetchColumn();
    if (!$has_fp || !$has_detail) {
        $st = null;
    } else {
    // Selector de estado (compatible con y sin columna estado_revision)
    $estado_case = "CASE
                         WHEN fp.estado_revision = 'aprobada'  THEN 'aprobado'
                         WHEN fp.estado_revision = 'rechazada' THEN 'rechazado'
                         ELSE 'solicitud_ficha'
                     END";

     $select_base = "SELECT fp.id as id_ficha,
                     COALESCE(cp.nombre, fp.cliente_nombre) as cliente_nombre,
                     COALESCE(cp.cedula, fp.cliente_cedula) as cliente_cedula,
                            $detail_alias.$amount_col as cantidad,
                     $estado_case as estado,
                     fp.created_at as fecha_creacion,
                     u.nombre as asesor_nombre,
                     'ficha' as origen
              FROM ficha_producto fp
                  JOIN $detail_table $detail_alias ON $detail_alias.ficha_id COLLATE utf8mb4_unicode_ci = fp.id COLLATE utf8mb4_unicode_ci
              LEFT JOIN asesor    a  ON (
                    a.id        = fp.asesor_id  COLLATE utf8mb4_unicode_ci
                 OR a.usuario_id = fp.usuario_id COLLATE utf8mb4_unicode_ci
                 OR a.id        = fp.usuario_id COLLATE utf8mb4_unicode_ci
              )
              LEFT JOIN usuario   u  ON u.id = a.usuario_id
              LEFT JOIN cliente_prospecto cp ON cp.cedula = fp.cliente_cedula COLLATE utf8mb4_unicode_ci";

    if ($user_role === 'super_admin' || $user_role === 'admin') {
        $q  = "$select_base WHERE fp.producto_tipo = ? ORDER BY fp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute([$tipo]);

    } elseif ($user_role === 'supervisor') {
        $sid = $supervisor_table_id;
        $uid = $user_id;

        // ── Paso 1: todos los asesores bajo este supervisor ──
        $stA = $pdo->prepare(
            "SELECT id, usuario_id FROM asesor WHERE supervisor_id IN (?, ?)"
        );
        $stA->execute([$sid, $uid]);
        $asesores_rows = $stA->fetchAll();

        $asesor_ids  = array_column($asesores_rows, 'id');
        $usuario_ids = array_column($asesores_rows, 'usuario_id');

        if (empty($asesor_ids) && empty($usuario_ids)) {
            $st = null;
        } else {
            $all_ids      = array_unique(array_merge($asesor_ids, $usuario_ids));
            $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
            // COLLATE utf8mb4_unicode_ci resuelve el mismatch de colaciones
            // entre ficha_producto (general_ci) y asesor (unicode_ci)
            $q  = "$select_base
                                     WHERE fp.producto_tipo = ?
                     AND (
                       fp.asesor_id  COLLATE utf8mb4_unicode_ci IN ($placeholders)
                       OR fp.usuario_id COLLATE utf8mb4_unicode_ci IN ($placeholders)
                     )
                   ORDER BY fp.created_at DESC";
            $st = $pdo->prepare($q);
                        $st->execute(array_merge([$tipo], $all_ids, $all_ids));
        }

    } else {
        // Asesor: ve sus propias fichas
        $allIds = array_unique(array_filter([$user_id, $asesor_table_id]));
        if (empty($allIds)) {
            $st = null;
        } else {
            $ph = implode(',', array_fill(0, count($allIds), '?'));
            $q  = "$select_base
                                     WHERE fp.producto_tipo = ?
                     AND (
                       fp.asesor_id  COLLATE utf8mb4_unicode_ci IN ($ph)
                       OR fp.usuario_id COLLATE utf8mb4_unicode_ci IN ($ph)
                     )
                   ORDER BY fp.created_at DESC";
            $st = $pdo->prepare($q);
                        $st->execute(array_merge([$tipo], $allIds, $allIds));
        }
    }
    }

    if ($st !== null) {
        $operaciones = array_merge($operaciones, $st->fetchAll());
    }

} catch (PDOException $e) {
    $fuente2_error = $e->getMessage(); // guardamos para mostrarlo en pantalla
}

// ── Ordenar combinado por fecha desc ──────────────────────
usort($operaciones, fn($a, $b) => strtotime($b['fecha_creacion']) - strtotime($a['fecha_creacion']));

// ── Estadísticas combinadas ───────────────────────────────
$total_ops    = count($operaciones);
$completadas  = count(array_filter($operaciones, fn($o) => in_array($o['estado'] ?? '', ['desembolsado','aprobado'])));
$monto_total  = array_sum(array_map(fn($o) => is_numeric($o['cantidad'] ?? '') ? floatval($o['cantidad']) : 0, $operaciones));
$stats = [
    'total_operaciones' => $total_ops,
    'completadas'       => $completadas,
    'monto_total'       => $monto_total,
];

$currentPage        = 'operaciones';
$alertas_pendientes = $alertas_pendientes ?? 0;
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
$is_supervisor_ui   = ($user_role === 'supervisor');
$page_title         = $tipo_info['titulo'];
$table_title        = 'Solicitudes de ' . $tipo_info['label'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - <?= htmlspecialchars($page_title) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
<?php if ($is_supervisor_ui): ?>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-gray: #6b7280;
            --brand-border: #d7e0ea;
            --brand-card: #ffffff;
            --brand-bg: #f4f6f9;
            --brand-shadow: 0 16px 34px rgba(18, 58, 109, 0.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: linear-gradient(180deg, #f8fafc 0%, var(--brand-bg) 100%); display: flex; height: 100vh; color: var(--brand-navy-deep); }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: var(--brand-card); padding: 20px; border-radius: 16px; box-shadow: var(--brand-shadow); text-align: center; border: 1px solid var(--brand-border); }
        .stat-card .number { font-size: 32px; font-weight: 800; color: var(--brand-navy-deep); }
        .stat-card .label { color: var(--brand-gray); font-size: 13px; margin-top: 5px; }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); }
        .table-card h6 { font-weight: 800; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: var(--brand-gray); border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: rgba(215,224,234,0.55); }
        .table tbody tr:hover { background: rgba(255,221,0,0.06); }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php else: ?>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #6b11ff, #7c3aed); color: #fff; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) {
            .sidebar { width: 200px; }
            .main-content { margin-left: 200px; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 180px; }
            .main-content { margin-left: 180px; }
        }
        .navbar-custom { background: linear-gradient(135deg, #6b11ff, #3182fe); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: 700; color: #1f2937; }
        .stat-card .label { color: #9ca3af; font-size: 13px; margin-top: 5px; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        .badge-completed { background: #10b981; }
        .badge-pending { background: #f59e0b; }
        .badge-prospect { background: #3b82f6; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php endif; ?>
    </style>
</head>
<body>

<?php if ($user_role === 'supervisor'): require_once '_sidebar_supervisor.php'; else: ?>
<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <?php if ($user_role === 'super_admin'): ?>
        <a href="super_admin_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php elseif ($user_role === 'admin'): ?>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php elseif ($user_role === 'supervisor'): ?>
        <a href="supervisor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php else: ?>
        <a href="asesor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php endif; ?>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
        <a href="usuarios.php" class="sidebar-link">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <?php endif; ?>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link active">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>

    <?php if ($user_role === 'supervisor'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Mi Equipo</div>
        <a href="mis_asesores.php" class="sidebar-link">
            <i class="fas fa-users"></i> Mis Asesores
        </a>
        <a href="registro_asesor.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link">
            <i class="fas fa-file-circle-check"></i> Solicitudes de Asesor
        </a>
    </div>
    <?php endif; ?>
    
    <?php if ($user_role === 'super_admin'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link">
            <i class="fas fa-file-alt"></i> Solicitudes de Admin
        </a>
    </div>
    <?php endif; ?>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Configuración</div>
        <a href="#" class="sidebar-link">
            <i class="fas fa-cog"></i> Configuración
        </a>
    </div>
</div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2>
            <?php if ($user_role === 'super_admin'): ?>
                👑 Super_IA - SuperAdministrador
            <?php elseif ($user_role === 'admin'): ?>
                🎯 Super_IA - Admin
            <?php elseif ($user_role === 'supervisor'): ?>
                👔 Super_IA - Supervisor
            <?php else: ?>
                👤 Super_IA - Asesor
            <?php endif; ?>
        </h2>
        <div class="user-info">
            <div>
                <strong>
                    <?php 
                    if ($user_role === 'super_admin') {
                        echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    } elseif ($user_role === 'admin') {
                        echo htmlspecialchars($_SESSION['admin_nombre']);
                    } elseif ($user_role === 'supervisor') {
                        echo htmlspecialchars($_SESSION['supervisor_nombre']);
                    } else {
                        echo htmlspecialchars($_SESSION['asesor_nombre']);
                    }
                    ?>
                </strong><br>
                <small>
                    <?php 
                    if ($user_role === 'super_admin') {
                        echo htmlspecialchars($_SESSION['super_admin_rol']);
                    } elseif ($user_role === 'admin') {
                        echo htmlspecialchars($_SESSION['admin_rol']);
                    } elseif ($user_role === 'supervisor') {
                        echo htmlspecialchars($_SESSION['supervisor_rol']);
                    } else {
                        echo htmlspecialchars($_SESSION['asesor_rol']);
                    }
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-handshake me-2"></i><?= htmlspecialchars($page_title) ?></h1>

            <div class="mt-3 d-flex gap-2 flex-wrap">
                <?php foreach ($tipos_map as $k => $info): ?>
                    <a
                        href="operaciones.php?tipo=<?= urlencode($k) ?>"
                        class="btn btn-sm <?= ($k === $tipo) ? 'btn-primary' : 'btn-outline-primary' ?>"
                        style="border-radius:999px;"
                    >
                        <i class="fas <?= htmlspecialchars($info['icon']) ?> me-1"></i><?= htmlspecialchars($info['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($mensaje_exito)): ?>
            <div class="alert alert-success" role="alert" style="border-radius:12px;">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($mensaje_exito) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($mensaje_error)): ?>
            <div class="alert alert-danger" role="alert" style="border-radius:12px;">
                <i class="fas fa-triangle-exclamation me-2"></i><?= htmlspecialchars($mensaje_error) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($fuente2_error)): ?>
            <div class="alert alert-warning" role="alert" style="border-radius:12px;">
                No se pudieron cargar algunas solicitudes desde la app. Actualiza la página o contacta al administrador.
            </div>
        <?php endif; ?>

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?= $stats['total_operaciones'] ?></div>
                <div class="label">Total Solicitudes</div>
            </div>
            <div class="stat-card">
                <div class="number"><?= $stats['completadas'] ?></div>
                <div class="label">Aprobadas / Desembolsadas</div>
            </div>
            <div class="stat-card">
                <div class="number">$<?= number_format($stats['monto_total'], 2) ?></div>
                <div class="label">Monto Total</div>
            </div>
        </div>

        <!-- TABLA DE OPERACIONES -->
        <div class="table-card">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h6><i class="fas fa-list me-2"></i><?= htmlspecialchars($table_title) ?></h6>
                <span class="badge bg-secondary"><?= $total_ops ?> registros</span>
            </div>
            <?php if (empty($operaciones)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-inbox fa-3x mb-3 d-block" style="opacity:.3"></i>
                <p class="mb-0">No hay solicitudes registradas aún.</p>
                <?php if ($user_role === 'supervisor'): ?>
                <small>Las fichas que llenen sus asesores desde la app aparecerán aquí.</small>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cliente</th>
                            <th>Cédula</th>
                            <?php if ($col_asesor): ?><th>Asesor</th><?php endif; ?>
                            <th>Monto</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th>Origen</th>
                            <?php if (in_array($user_role, ['super_admin','admin','supervisor'])): ?><th>Acción</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($operaciones as $i => $op): ?>
                        <?php
                            $estado  = $op['estado'] ?? 'desconocido';
                            $origen  = $op['origen'] ?? '';
                            switch ($estado) {
                                case 'aprobado':
                                case 'desembolsado':   $badgeCls = 'bg-success';   $label = 'Aprobado';        break;
                                case 'rechazado':      $badgeCls = 'bg-danger';    $label = 'Rechazado';       break;
                                case 'solicitud_ficha':$badgeCls = 'bg-warning text-dark'; $label = 'Pendiente'; break;
                                default:               $badgeCls = 'bg-secondary'; $label = ucfirst($estado);
                            }
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($op['cliente_nombre'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($op['cliente_cedula'] ?? '—') ?></td>
                            <?php if ($col_asesor): ?><td><?= htmlspecialchars($op['asesor_nombre'] ?? '—') ?></td><?php endif; ?>
                            <td>$<?= is_numeric($op['cantidad'] ?? '') ? number_format(floatval($op['cantidad']), 2) : '—' ?></td>
                            <td><span class="badge <?= $badgeCls ?>"><?= $label ?></span></td>
                            <td><?= isset($op['fecha_creacion']) ? date('d/m/Y H:i', strtotime($op['fecha_creacion'])) : '—' ?></td>
                            <td>
                                <?php if ($origen === 'ficha'): ?>
                                    <span class="badge bg-info text-dark"><i class="fas fa-mobile-alt me-1"></i>App</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="fas fa-desktop me-1"></i>Sistema</span>
                                <?php endif; ?>
                            </td>
                            <?php if (in_array($user_role, ['super_admin','admin','supervisor'])): ?>
                            <td>
                                <?php if ($origen === 'ficha' && $estado === 'solicitud_ficha'): ?>
                                <form method="POST" class="d-flex gap-1" style="flex-wrap:nowrap">
                                    <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="tipo"       value="<?= htmlspecialchars($tipo) ?>">
                                    <input type="hidden" name="id_ficha"   value="<?= htmlspecialchars($op['id_ficha'] ?? ($op['id_credito'] ?? '')) ?>">
                                    <input type="hidden" name="origen"      value="ficha">
                                    <button type="submit" name="accion" value="aprobar"
                                        class="btn btn-sm btn-success"
                                        onclick="return confirm('¿Aprobar esta solicitud?')"
                                        title="Aprobar">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="submit" name="accion" value="rechazar"
                                        class="btn btn-sm btn-danger"
                                        onclick="return confirm('¿Rechazar esta solicitud?')"
                                        title="Rechazar">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content-area -->
</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
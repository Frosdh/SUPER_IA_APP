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

// ── Filtro por Banco/Cooperativa (solo admin/super_admin) ───
$banco_id_filtro = '';
$bancos_lista = [];
if ($user_role === 'super_admin' || $user_role === 'admin') {
    $banco_id_filtro = trim((string)($_GET['banco_id'] ?? ''));
    try {
        $bancos_lista = $pdo->query("SELECT id, nombre FROM unidad_bancaria WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
    } catch (Throwable $e) { $bancos_lista = []; }
}

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
            if (!in_array($user_role, ['super_admin', 'supervisor'], true)) {
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
                    // Antes de actualizar a 'aprobada', verificar reglas especiales para crédito
                    if ($accion === 'aprobar' && $tipoPost === 'credito') {
                        try {
                            $stF = $pdo->prepare('SELECT fc.tiene_empresa as tiene_empresa_fc, fp.cliente_cedula FROM ficha_credito fc JOIN ficha_producto fp ON fc.ficha_id = fp.id WHERE fp.id = ? LIMIT 1');
                            $stF->execute([$idFicha]);
                            $finfo = $stF->fetch(PDO::FETCH_ASSOC) ?: null;
                            
                            $cedula = $finfo['cliente_cedula'] ?? null;
                            $tieneEmpresa = false;

                            // 1. Verificar si en la ficha se marcó que tiene empresa
                            if ($finfo && !empty($finfo['tiene_empresa_fc']) && (int)$finfo['tiene_empresa_fc'] === 1) {
                                $tieneEmpresa = true;
                            }

                            // 2. Buscar cliente_prospecto.id y verificar su flag 'tiene_empresa'
                            $clienteId = null;
                            if ($cedula) {
                                $stC = $pdo->prepare('SELECT id, tiene_empresa FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
                                $stC->execute([ (string)$cedula ]);
                                $cp_row = $stC->fetch(PDO::FETCH_ASSOC);
                                if ($cp_row) {
                                    $clienteId = $cp_row['id'];
                                    if ((int)($cp_row['tiene_empresa'] ?? 0) === 1) {
                                        $tieneEmpresa = true;
                                    }
                                }
                            }

                            if ($tieneEmpresa) {
                                $tieneEncuestaNeg = false;
                                if ($clienteId !== null) {
                                    // Verificar que exista al menos un registro de encuesta_negocio con datos financieros
                                    $stEN = $pdo->prepare('SELECT 1 FROM encuesta_negocio en 
                                                         JOIN tarea t ON en.tarea_id COLLATE utf8mb4_unicode_ci = t.id COLLATE utf8mb4_unicode_ci
                                                         WHERE t.cliente_prospecto_id COLLATE utf8mb4_unicode_ci = ? 
                                                           AND (
                                                               (en.venta_lv IS NOT NULL AND en.venta_lv > 0) OR 
                                                               (en.costos_ventas IS NOT NULL AND en.costos_ventas > 0) OR 
                                                               (en.gastos_negocio IS NOT NULL AND en.gastos_negocio > 0)
                                                           )
                                                         LIMIT 1');
                                    $stEN->execute([$clienteId]);
                                    if ($stEN->fetchColumn()) $tieneEncuestaNeg = true;
                                }

                                if (!$tieneEncuestaNeg) {
                                    throw new Exception('No se puede aprobar: El prospecto tiene empresa pero falta completar el Levantamiento de Negocio (encuesta financiera). El asesor debe completar esta información desde la app.');
                                }
                            }
                        } catch (Throwable $eCheck) {
                            throw $eCheck; // bubble up to outer catch to set mensaje_error
                        }
                    }

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
              LEFT JOIN supervisor   sup ON sup.id = a.supervisor_id
              LEFT JOIN jefe_agencia ja  ON ja.id  = sup.jefe_agencia_id
              LEFT JOIN agencia      ag  ON ag.id  = ja.agencia_id"
              . ($banco_id_filtro !== '' ? " WHERE ag.unidad_bancaria_id = ?" : "") .
              " ORDER BY cp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute($banco_id_filtro !== '' ? [$banco_id_filtro] : []);
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
        $select_base_banco = "$select_base
              LEFT JOIN supervisor   sup ON sup.id = a.supervisor_id
              LEFT JOIN jefe_agencia ja  ON ja.id  = sup.jefe_agencia_id
              LEFT JOIN agencia      ag  ON ag.id  = ja.agencia_id";
        $q  = "$select_base_banco WHERE fp.producto_tipo = ?"
              . ($banco_id_filtro !== '' ? " AND ag.unidad_bancaria_id = ?" : "") .
              " ORDER BY fp.created_at DESC";
        $st = $pdo->prepare($q);
        $st->execute($banco_id_filtro !== '' ? [$tipo, $banco_id_filtro] : [$tipo]);

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

// ── Enriquecer operaciones con datos de estado de cliente y crédito ────
function obtener_estado_cliente_credito(PDO $pdo, $cedula) {
    $resultado = [
        'tipo_cliente' => 'prospecto',  // 'cliente' o 'prospecto'
        'estado_credito' => 'pendiente' // 'aprobado', 'rechazado', 'pendiente'
    ];
    
    if (!$cedula) return $resultado;
    
    try {
        // 1. Obtener tipo de cliente (cliente o prospecto)
        $st = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
        $st->execute([$cedula]);
        $cp = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($cp) {
            $clienteId = $cp['id'];
            
            // Verificar si es cliente por aprobación de fichas o créditos
            $st2 = $pdo->prepare("SELECT 1 FROM ficha_producto WHERE cliente_cedula = ? AND estado_revision = 'aprobada' LIMIT 1");
            $st2->execute([$cedula]);
            if ($st2->fetchColumn()) {
                $resultado['tipo_cliente'] = 'cliente';
            } else {
                $st3 = $pdo->prepare("SELECT 1 FROM credito_proceso WHERE cliente_prospecto_id = ? AND estado_credito IN ('aprobado','desembolsado') LIMIT 1");
                $st3->execute([$clienteId]);
                if ($st3->fetchColumn()) {
                    $resultado['tipo_cliente'] = 'cliente';
                }
            }
            
            // 2. Obtener estado más reciente del crédito
            $st4 = $pdo->prepare(
                "SELECT COALESCE(
                    (SELECT MAX(CASE 
                        WHEN estado_revision = 'aprobada' THEN 'aprobado'
                        WHEN estado_revision = 'rechazada' THEN 'rechazado'
                        ELSE 'pendiente' END)
                     FROM ficha_producto WHERE cliente_cedula = ?),
                    (SELECT MAX(CASE 
                        WHEN estado_credito IN ('aprobado','desembolsado') THEN 'aprobado'
                        WHEN estado_credito IN ('rechazado','negado') THEN 'rechazado'
                        ELSE 'pendiente' END)
                     FROM credito_proceso WHERE cliente_prospecto_id = ?),
                    'pendiente') as estado"
            );
            $st4->execute([$cedula, $clienteId]);
            $estadoRow = $st4->fetch(PDO::FETCH_ASSOC);
            if ($estadoRow && $estadoRow['estado']) {
                $resultado['estado_credito'] = $estadoRow['estado'];
            }
        }
    } catch (Throwable $e) {
        // Sin cambios, retorna valores por defecto
    }
    
    return $resultado;
}

foreach ($operaciones as &$op) {
    $cedula = $op['cliente_cedula'] ?? null;
    $datos = obtener_estado_cliente_credito($pdo, $cedula);
    $op['tipo_cliente'] = $datos['tipo_cliente'];
    $op['estado_credito'] = $datos['estado_credito'];
}
unset($op);

// ── Ordenar combinado por fecha desc ──────────────────────
usort($operaciones, fn($a, $b) => strtotime($b['fecha_creacion']) - strtotime($a['fecha_creacion']));

// ── Estadísticas combinadas ───────────────────────────────
$total_ops    = count($operaciones);
$aprobadas    = count(array_filter($operaciones, fn($o) => ($o['estado'] ?? '') === 'aprobado'));
$completadas  = count(array_filter($operaciones, fn($o) => in_array($o['estado'] ?? '', ['desembolsado','aprobado'])));
$rechazadas   = count(array_filter($operaciones, fn($o) => ($o['estado'] ?? '') === 'rechazado'));
$pendientes   = count(array_filter($operaciones, fn($o) => !in_array($o['estado'] ?? '', ['desembolsado','aprobado','rechazado'])));
$monto_total  = array_sum(array_map(fn($o) => is_numeric($o['cantidad'] ?? '') ? floatval($o['cantidad']) : 0, $operaciones));
$stats = [
    'total_operaciones' => $total_ops,
    'aprobadas'         => $aprobadas,
    'completadas'       => $completadas,
    'rechazadas'        => $rechazadas,
    'pendientes'        => $pendientes,
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
<?php if ($user_role === 'supervisor' || $user_role === 'asesor'): ?>
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
<?php else: ?>
    <style>
        /* Estilos para admin/superadmin (sin sidebar supervisor) — navy unificado con el resto del panel */
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: sticky; height: 100vh; top: 0; flex-shrink: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 24px rgba(18, 58, 109, 0.16); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-kpi-row { display: flex; gap: 14px; margin-bottom: 28px; flex-wrap: wrap; }
        .stats-kpi-row > div { flex: 1 1 160px; }
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
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
<?php endif; ?>
</head>
<body>

<?php 
// ── Lógica para Sidebar Asesor ────────────────────────────────
if ($user_role === 'asesor') {
    $tareas_pendientes = 0;
    try {
        if (isset($asesor_table_id) && $asesor_table_id) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id = ? AND fecha_programada = CURRENT_DATE AND estado != 'completada'");
            $st->execute([$asesor_table_id]);
            $tareas_pendientes = (int)$st->fetchColumn();

        }
    } catch (PDOException $e) {}
}

if ($user_role === 'supervisor') {
    $navTitle = ''; $navIcon = ''; $navSubtitle = '';
    require_once '_sidebar_supervisor.php';
} elseif ($user_role === 'asesor') {
    $asesor_nombre = $_SESSION['asesor_nombre'] ?? 'Asesor';
    require_once '_sidebar_asesor.php';
} elseif ($user_role === 'admin') {
    $currentPage = 'operaciones';
    require_once '_sidebar_gerente.php';
} else {
    // Sidebar único de SuperAdmin (mismo set de enlaces que mapa_vivo.php / usuarios.php)
?>
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-crown"></i><span>Super_IA</span></div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="super_admin_index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <a href="mapa_calor.php" class="sidebar-link"><i class="fas fa-fire"></i> Mapa de Calor</a>
        <a href="historial_rutas.php" class="sidebar-link"><i class="fas fa-history"></i> Historial de Viajes</a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestion</div>
        <a href="usuarios.php" class="sidebar-link"><i class="fas fa-users"></i> Usuarios</a>
        <a href="clientes.php" class="sidebar-link"><i class="fas fa-briefcase"></i> Clientes</a>
        <a href="operaciones.php" class="sidebar-link active"><i class="fas fa-handshake"></i> Operaciones</a>
        <a href="metas.php" class="sidebar-link"><i class="fas fa-bullseye"></i> Metas</a>
        <a href="alertas.php" class="sidebar-link"><i class="fas fa-bell"></i> Alertas</a>
    </div>
</div>
<?php } ?>


<?php if ($user_role !== 'supervisor'): ?>
<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <?php if ($user_role === 'asesor'): ?>
            <h2><i class="fas fa-handshake me-2" style="color: var(--brand-yellow);"></i> Mis Operaciones — Asesor</h2>
        <?php else: ?>
            <h2><?php echo $user_role === 'super_admin' ? '👑' : '🎯'; ?> Super_IA 
                <?php 
                if ($user_role === 'super_admin') echo '- SuperAdmin';
                elseif ($user_role === 'admin') echo '- Admin';
                ?>
            </h2>
        <?php endif; ?>

        <div class="user-info">
            <div style="text-align: right;">
                <strong style="display:block;">
                    <?php 
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_nombre']);
                    else echo htmlspecialchars($_SESSION['asesor_nombre']);
                    ?>
                </strong>
                <small style="opacity:0.7;">
                    <?php 
                    if ($user_role === 'super_admin') echo 'SuperAdministrador';
                    elseif ($user_role === 'admin') echo 'Administrador';
                    else echo 'Asesor de campo';
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">
<?php endif; ?>

        <style>
        .tipo-tabs-wrap{
            display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;
        }
        .tipo-tab{
            display:inline-flex;align-items:center;gap:9px;
            padding:10px 22px;border-radius:14px;
            font-size:13px;font-weight:700;
            text-decoration:none;
            border:2px solid transparent;
            transition:all .2s;
            box-shadow:0 2px 6px rgba(0,0,0,.06);
        }
        .tipo-tab i{font-size:14px;}
        .tipo-tab-inactive{
            background:#fff;
            border-color:#dde5f0;
            color:#64748b;
        }
        .tipo-tab-inactive:hover{
            background:#f0f5ff;
            border-color:#93c5fd;
            color:#1e40af;
            transform:translateY(-2px);
            box-shadow:0 4px 14px rgba(59,130,246,.15);
        }
        .tipo-tab-active{
            background:linear-gradient(135deg,#0a2748 0%,#1e4d8c 100%);
            border-color:#0a2748;
            color:#ffdd00;
            box-shadow:0 4px 16px rgba(10,39,72,.28);
            transform:translateY(-1px);
        }
        </style>

        <div class="page-header">
            <h1><i class="fas fa-handshake me-2"></i><?= htmlspecialchars($page_title) ?></h1>

            <div class="tipo-tabs-wrap">
                <?php foreach ($tipos_map as $k => $info):
                    $isActive = ($k === $tipo);
                    $tabHref = 'operaciones.php?tipo=' . urlencode($k) . ($banco_id_filtro !== '' ? '&banco_id=' . urlencode($banco_id_filtro) : '');
                ?>
                    <a
                        href="<?= htmlspecialchars($tabHref) ?>"
                        class="tipo-tab <?= $isActive ? 'tipo-tab-active' : 'tipo-tab-inactive' ?>"
                    >
                        <i class="fas <?= htmlspecialchars($info['icon']) ?>"></i>
                        <?= htmlspecialchars($info['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
            <div class="banco-filter-wrap" style="margin-top:14px;">
                <label for="filtroBancoOperaciones" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--brand-navy-deep,#0a2748);display:block;margin-bottom:5px;">
                    Banco / Cooperativa
                </label>
                <select id="filtroBancoOperaciones" onchange="if(this.value){window.location.href='operaciones.php?tipo=<?= urlencode($tipo) ?>&banco_id='+encodeURIComponent(this.value);}else{window.location.href='operaciones.php?tipo=<?= urlencode($tipo) ?>';}"
                        style="padding:9px 12px;border-radius:9px;border:1.5px solid #E2E8F0;font-size:13.5px;font-family:'Inter',sans-serif;color:#0D1929;background:#fff;min-width:240px;">
                    <option value="">Todos los bancos</option>
                    <?php foreach ($bancos_lista as $b): ?>
                        <option value="<?= htmlspecialchars($b['id']) ?>" <?= ($banco_id_filtro === $b['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
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
        <div class="row g-3 mb-4" style="margin-top: 24px;">
            <div class="col-md">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #3b82f6 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Total Solicitudes</div>
                        <h3 class="m-0 fw-800" style="color:#0a2748;"><?= $stats['total_operaciones'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #10b981 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Aprobadas</div>
                        <h3 class="m-0 fw-800 text-success"><?= $stats['aprobadas'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #ef4444 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Rechazadas</div>
                        <h3 class="m-0 fw-800 text-danger"><?= $stats['rechazadas'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #f59e0b !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Pendientes</div>
                        <h3 class="m-0 fw-800" style="color:#b45309;"><?= $stats['pendientes'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md">
                <div class="card border-0 shadow-sm h-100" style="border-radius:16px; border-left:4px solid #0ea5e9 !important;">
                    <div class="card-body p-4">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Monto Total</div>
                        <h3 class="m-0 fw-800" style="color:#0369a1;">$<?= number_format($stats['monto_total'], 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLA DE OPERACIONES -->
        <div class="table-card">
            <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h6 class="m-0 fw-800" style="color:var(--brand-navy-deep, #0a2748);"><i class="fas fa-list me-2"></i><?= htmlspecialchars($table_title) ?></h6>
                    <small id="cntResultados" class="text-muted fw-semibold" style="font-size: 11px;"><?= $total_ops ?> registros en total</small>
                </div>
            </div>

            <!-- FILTER BAR MEJORADA -->
            <style>
            .filter-bar{
                display:flex;flex-direction:column;gap:0;
                background:linear-gradient(135deg,#f8fafd 0%,#f0f5fb 100%);
                border-bottom:1px solid #e2eaf4;
                padding:0;
            }
            /* FILA 1 — búsquedas: campos ocupan todo el ancho disponible */
            .filter-top{
                display:grid;
                grid-template-columns: 1fr 1fr auto;
                align-items:center;
                gap:14px;
                padding:18px 28px 16px;
                border-bottom:1px solid #edf2f9;
            }
            /* FILA 2 — pills Cliente | Crédito en columnas separadas */
            .filter-pills-row{
                display:grid;
                grid-template-columns:1fr 1fr;
                gap:0;
                padding:0;
                border-bottom:1px solid #edf2f9;
            }
            .filter-pill-group{
                display:flex;align-items:center;gap:10px;flex-wrap:wrap;
                padding:14px 28px;
            }
            .filter-pill-group:first-child{
                border-right:1px solid #edf2f9;
            }
            /* FILA 3 — A-Z en toda la fila */
            .filter-az-row{
                display:flex;align-items:center;gap:6px;flex-wrap:wrap;
                padding:12px 28px 14px;
                border-bottom:1px solid #edf2f9;
                background:rgba(248,250,253,.6);
            }
            /* FILA 4 — contador */
            .filter-bottom{
                display:flex;align-items:center;justify-content:flex-end;
                flex-wrap:wrap;gap:8px;
                padding:10px 28px;
                background:rgba(248,250,253,.7);
            }
            .fi-label{
                font-size:10.5px;font-weight:800;color:#94a3b8;
                text-transform:uppercase;letter-spacing:.6px;
                white-space:nowrap;display:flex;align-items:center;gap:5px;
                margin-right:4px;
            }
            .estado-pills{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
            .ep-btn{
                display:inline-flex;align-items:center;gap:6px;
                padding:7px 16px;border-radius:99px;
                font-size:12px;font-weight:700;
                border:1.5px solid transparent;
                cursor:pointer;transition:all .18s;
                white-space:nowrap;
            }
            .ep-btn i{font-size:11px;}
            .ep-all{background:#f1f5f9;border-color:#dde5f0;color:#475569;}
            .ep-all:hover{background:#e2e8f0;border-color:#c7d4e4;}
            .ep-all.active{background:linear-gradient(135deg,#0a2748,#1e4d8c);border-color:#0a2748;color:#fff;box-shadow:0 3px 10px rgba(10,39,72,.25);}
            .ep-cliente{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
            .ep-cliente:hover{background:#dcfce7;border-color:#86efac;}
            .ep-cliente.active{background:linear-gradient(135deg,#15803d,#16a34a);border-color:#15803d;color:#fff;box-shadow:0 3px 10px rgba(21,128,61,.3);}
            .ep-prospecto{background:#fffbeb;border-color:#fde68a;color:#b45309;}
            .ep-prospecto:hover{background:#fef3c7;border-color:#fcd34d;}
            .ep-prospecto.active{background:linear-gradient(135deg,#d97706,#f59e0b);border-color:#d97706;color:#fff;box-shadow:0 3px 10px rgba(217,119,6,.3);}
            .ep-aprobado{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
            .ep-aprobado:hover{background:#dcfce7;border-color:#86efac;}
            .ep-aprobado.active{background:linear-gradient(135deg,#15803d,#16a34a);border-color:#15803d;color:#fff;box-shadow:0 3px 10px rgba(21,128,61,.3);}
            .ep-rechazado{background:#fef2f2;border-color:#fecaca;color:#b91c1c;}
            .ep-rechazado:hover{background:#fee2e2;border-color:#fca5a5;}
            .ep-rechazado.active{background:linear-gradient(135deg,#b91c1c,#dc2626);border-color:#b91c1c;color:#fff;box-shadow:0 3px 10px rgba(185,28,28,.3);}
            .ep-pendiente{background:#fef3c7;border-color:#fde68a;color:#b45309;}
            .ep-pendiente:hover{background:#fef3c7;border-color:#fcd34d;}
            .ep-pendiente.active{background:linear-gradient(135deg,#d97706,#f59e0b);border-color:#d97706;color:#fff;box-shadow:0 3px 10px rgba(217,119,6,.3);}
            .fi-group{
                display:flex;align-items:center;
                border:1.5px solid #dde5f0;
                border-radius:12px;
                background:#fff;
                box-shadow:0 1px 3px rgba(0,0,0,.04);
                transition:border-color .18s,box-shadow .18s;
                overflow:hidden;
            }
            .fi-group:focus-within{
                border-color:#3b82f6;
                box-shadow:0 0 0 3px rgba(59,130,246,.1),0 1px 3px rgba(0,0,0,.04);
            }
            .fi-ico{
                flex-shrink:0;
                width:42px;
                text-align:center;
                color:#b0bec5;
                font-size:13px;
                pointer-events:none;
            }
            .fi-input{
                flex:1;
                border:none;
                outline:none;
                padding:11px 14px 11px 0;
                font-size:13px;font-weight:600;
                color:#1a2744;
                background:transparent;
                min-width:0;
            }
            .fi-input::placeholder{color:#b0bec5;font-weight:500;}
            .fi-divider{width:1px;height:32px;background:#dde5f0;flex-shrink:0;margin:0 4px;}
            .fi-clear-btn{
                display:flex;align-items:center;gap:7px;
                padding:11px 20px;
                border-radius:12px;
                border:1.5px solid #dde5f0;
                background:#fff;
                color:#94a3b8;
                font-size:12.5px;font-weight:700;
                cursor:pointer;
                transition:.18s;
                white-space:nowrap;
                box-shadow:0 1px 3px rgba(0,0,0,.04);
            }
            .fi-clear-btn:hover{border-color:#ef4444;color:#ef4444;background:#fff5f5;box-shadow:0 2px 8px rgba(239,68,68,.1);}
            .az-all-btn{
                height:30px;padding:0 12px;
                border-radius:8px;
                border:1.5px solid #dde5f0;
                background:#fff;
                color:#475569;
                font-size:11px;font-weight:800;
                cursor:pointer;transition:.15s;
                box-shadow:0 1px 2px rgba(0,0,0,.04);
            }
            .az-all-btn.active{
                background:linear-gradient(135deg,#0a2748 0%,#1e4d8c 100%);
                border-color:#0a2748;color:#ffdd00;
                box-shadow:0 3px 10px rgba(10,39,72,.25);
            }
            .az-btn{
                width:30px;height:30px;
                border-radius:8px;
                border:1.5px solid #e8eef6;
                background:#fff;
                color:#64748b;
                font-size:11.5px;font-weight:800;
                cursor:pointer;transition:.15s;
                display:flex;align-items:center;justify-content:center;
                box-shadow:0 1px 2px rgba(0,0,0,.03);
            }
            .az-btn:hover{
                background:#eff6ff;border-color:#93c5fd;color:#1d4ed8;
                transform:translateY(-1px);box-shadow:0 3px 8px rgba(59,130,246,.15);
            }
            .az-btn.active{
                background:linear-gradient(135deg,#ffdd00 0%,#f4c400 100%);
                border-color:#e6b800;color:#0a2748;
                box-shadow:0 3px 10px rgba(255,221,0,.35);
                transform:translateY(-1px);
            }
            .fi-count{
                display:flex;align-items:center;gap:6px;
                font-size:12px;font-weight:600;color:#94a3b8;
                white-space:nowrap;
            }
            .fi-count-num{
                font-size:14px;font-weight:900;color:#0a2748;
            }
            @media(max-width:768px){
                .filter-top{grid-template-columns:1fr;}
                .filter-pills-row{grid-template-columns:1fr;}
                .filter-pill-group:first-child{border-right:none;border-bottom:1px solid #edf2f9;}
            }
            </style>

            <div class="filter-bar">
                <!-- FILA 1: búsqueda de texto + limpiar — distribuidos en todo el ancho -->
                <div class="filter-top">
                    <div class="fi-group">
                        <i class="fas fa-search fi-ico"></i>
                        <input type="text" id="fiNombre" class="fi-input" placeholder="Buscar por nombre…">
                    </div>
                    <div class="fi-group">
                        <i class="fas fa-id-card fi-ico"></i>
                        <input type="text" id="fiCedula" class="fi-input" placeholder="Buscar por cédula…">
                    </div>
                    <button class="fi-clear-btn" id="fiClear">
                        <i class="fas fa-rotate-left" style="font-size:11px;"></i> Limpiar
                    </button>
                </div>

                <!-- FILA 2: Cliente y Crédito en dos columnas separadas -->
                <div class="filter-pills-row">
                    <div class="filter-pill-group">
                        <span class="fi-label"><i class="fas fa-user-check"></i> Cliente</span>
                        <button class="ep-btn ep-all active" data-filter-cliente="">
                            <i class="fas fa-border-all"></i> Todos
                        </button>
                        <button class="ep-btn ep-cliente" data-filter-cliente="cliente">
                            <i class="fas fa-check-circle"></i> Cliente
                        </button>
                        <button class="ep-btn ep-prospecto" data-filter-cliente="prospecto">
                            <i class="fas fa-clock"></i> Prospecto
                        </button>
                    </div>
                    <div class="filter-pill-group">
                        <span class="fi-label"><i class="fas fa-file-check"></i> Crédito</span>
                        <button class="ep-btn ep-all active" data-filter-credito="">
                            <i class="fas fa-border-all"></i> Todos
                        </button>
                        <button class="ep-btn ep-aprobado" data-filter-credito="aprobado">
                            <i class="fas fa-check-circle"></i> Aprobado
                        </button>
                        <button class="ep-btn ep-rechazado" data-filter-credito="rechazado">
                            <i class="fas fa-times-circle"></i> Rechazado
                        </button>
                        <button class="ep-btn ep-pendiente" data-filter-credito="pendiente">
                            <i class="fas fa-hourglass-half"></i> Pendiente
                        </button>
                    </div>
                </div>

                <!-- FILA 3: A-Z en toda la fila -->
                <div class="filter-az-row">
                    <span class="fi-label"><i class="fas fa-sort-alpha-down"></i> A–Z</span>
                    <button class="az-all-btn active" data-letter="">TODOS</button>
                    <?php foreach(range('A','Z') as $l): ?>
                    <button class="az-btn" data-letter="<?=$l?>"><?=$l?></button>
                    <?php endforeach; ?>
                </div>

                <!-- FILA 4: contador -->
                <div class="filter-bottom">
                    <div class="fi-count">
                        <i class="fas fa-file-invoice-dollar" style="font-size:11px;color:#cbd5e1;"></i>
                        Mostrando <span class="fi-count-num" id="cntMostrados"><?=$total_ops?></span>
                        <span style="color:#dde5f0;">de <?=$total_ops?></span> operaciones
                    </div>
                </div>
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
                            <th>Tipo Cliente</th>
                            <th>Crédito</th>
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
                            $tipoCliente = $op['tipo_cliente'] ?? 'prospecto';
                            $estadoCredito = $op['estado_credito'] ?? 'pendiente';
                            
                            switch ($estado) {
                                case 'aprobado':
                                case 'desembolsado':   $badgeCls = 'bg-success';   $label = 'Aprobado';        break;
                                case 'rechazado':      $badgeCls = 'bg-danger';    $label = 'Rechazado';       break;
                                case 'solicitud_ficha':$badgeCls = 'bg-warning text-dark'; $label = 'Pendiente'; break;
                                default:               $badgeCls = 'bg-secondary'; $label = ucfirst($estado);
                            }
                        ?>
                        <tr class="ops-row"
                            data-nombre="<?=htmlspecialchars(mb_strtolower(trim($op['cliente_nombre']??'')))?>"
                            data-cedula="<?=htmlspecialchars($op['cliente_cedula']??'')?>"
                            data-cliente="<?=htmlspecialchars($tipoCliente)?>"
                            data-credito="<?=htmlspecialchars($estadoCredito)?>">
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($op['cliente_nombre'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($op['cliente_cedula'] ?? '—') ?></td>
                            <?php if ($col_asesor): ?><td><?= htmlspecialchars($op['asesor_nombre'] ?? '—') ?></td><?php endif; ?>
                            <td>$<?= is_numeric($op['cantidad'] ?? '') ? number_format(floatval($op['cantidad']), 2) : '—' ?></td>
                            <td><span class="badge <?= $badgeCls ?>"><?= $label ?></span></td>
                            <td>
                                <?php if ($tipoCliente === 'cliente'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-10" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-check-circle me-1"></i> Cliente</span>
                                <?php else: ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-10" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-clock me-1"></i> Prospecto</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                    if ($estadoCredito === 'aprobado') {
                                        echo '<span class="badge bg-success bg-opacity-10 text-success" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-check me-1"></i>Aprobado</span>';
                                    } elseif ($estadoCredito === 'rechazado') {
                                        echo '<span class="badge bg-danger bg-opacity-10 text-danger" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-times me-1"></i>Rechazado</span>';
                                    } else {
                                        echo '<span class="badge bg-warning bg-opacity-10 text-warning" style="border-radius:10px; font-weight:800; font-size:11px;"><i class="fas fa-hourglass-half me-1"></i>Pendiente</span>';
                                    }
                                ?>
                            </td>
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
                                <?php if ($origen === 'ficha' && $estado === 'solicitud_ficha' && $user_role !== 'admin'): ?>
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

    </div> <!-- .content-area -->
</div> <!-- .main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fiNombre = document.getElementById('fiNombre');
    const fiCedula = document.getElementById('fiCedula');
    const fiClear = document.getElementById('fiClear');
    const cntMostrados = document.getElementById('cntMostrados');
    const allRows = Array.from(document.querySelectorAll('table tbody tr.ops-row'));
    const total = allRows.length;

    let activeLetter = '';
    let activeCliente = '';    // '' | 'cliente' | 'prospecto'
    let activeCredito = '';    // '' | 'aprobado' | 'rechazado' | 'pendiente'

    /* ── aplicar todos los filtros ───────────────────────── */
    function applyFilters() {
        const fNom = (fiNombre.value || '').trim().toLowerCase();
        const fCed = (fiCedula.value || '').trim().toLowerCase();
        const fLet = activeLetter.toLowerCase();
        const fCli = activeCliente;
        const fCre = activeCredito;

        let vis = 0;
        allRows.forEach(row => {
            const nombre = row.dataset.nombre || '';
            const cedula = row.dataset.cedula || '';
            const cliente = row.dataset.cliente || '';
            const credito = row.dataset.credito || '';

            const okNom = !fNom || nombre.includes(fNom);
            const okCed = !fCed || cedula.includes(fCed);
            const okLet = !fLet || nombre.startsWith(fLet);
            const okCli = !fCli || cliente === fCli;
            const okCre = !fCre || credito === fCre;

            if (okNom && okCed && okLet && okCli && okCre) {
                row.style.display = '';
                vis++;
            } else {
                row.style.display = 'none';
            }
        });

        if (cntMostrados) cntMostrados.textContent = vis;

        let emptyRow = document.getElementById('emptyFiltered');
        if (vis === 0 && total > 0) {
            if (!emptyRow) {
                const tbody = document.querySelector('table tbody');
                const tr = document.createElement('tr');
                tr.id = 'emptyFiltered';
                tr.innerHTML = `<td colspan="10" class="text-center py-5">
                    <div class="text-muted mb-3"><i class="fas fa-filter fa-3x opacity-20"></i></div>
                    <h6 class="fw-bold text-muted">Sin resultados con los filtros aplicados</h6>
                    <p class="text-muted small">Prueba quitando algún filtro o cambiando la letra.</p>
                </td>`;
                tbody.appendChild(tr);
            }
        } else {
            if (emptyRow) emptyRow.remove();
        }
    }

    /* ── pills de tipo cliente ─────────────────────────────── */
    function setCliente(val) {
        activeCliente = val;
        document.querySelectorAll('[data-filter-cliente]').forEach(b => {
            b.classList.toggle('active', b.dataset.filterCliente === val);
        });
        applyFilters();
    }
    document.querySelectorAll('[data-filter-cliente]').forEach(btn => {
        btn.addEventListener('click', () => {
            setCliente(btn.dataset.filterCliente === activeCliente ? '' : btn.dataset.filterCliente);
        });
    });

    /* ── pills de estado de crédito ──────────────────────── */
    function setCredito(val) {
        activeCredito = val;
        document.querySelectorAll('[data-filter-credito]').forEach(b => {
            b.classList.toggle('active', b.dataset.filterCredito === val);
        });
        applyFilters();
    }
    document.querySelectorAll('[data-filter-credito]').forEach(btn => {
        btn.addEventListener('click', () => {
            setCredito(btn.dataset.filterCredito === activeCredito ? '' : btn.dataset.filterCredito);
        });
    });

    /* ── letra A-Z ───────────────────────────────────────── */
    function setLetter(l) {
        activeLetter = l;
        document.querySelectorAll('.az-btn').forEach(b => b.classList.toggle('active', b.dataset.letter === l));
        const allBtn = document.querySelector('.az-all-btn');
        if (allBtn) allBtn.classList.toggle('active', l === '');
        applyFilters();
    }
    document.querySelectorAll('.az-btn').forEach(btn => {
        btn.addEventListener('click', () => setLetter(btn.dataset.letter === activeLetter ? '' : btn.dataset.letter));
    });
    const allBtn = document.querySelector('.az-all-btn');
    if (allBtn) allBtn.addEventListener('click', () => setLetter(''));

    /* ── limpiar todo ────────────────────────────────────── */
    if (fiClear) {
        fiClear.addEventListener('click', () => {
            fiNombre.value = '';
            fiCedula.value = '';
            setCliente('');
            setCredito('');
            setLetter('');
        });
    }

    /* ── listeners de inputs ─────────────────────────────── */
    [fiNombre, fiCedula].forEach(el => {
        if (el) {
            el.addEventListener('input', applyFilters);
            el.addEventListener('change', applyFilters);
        }
    });
});
</script>
</body>
</html>
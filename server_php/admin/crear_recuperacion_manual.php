<?php
// admin/crear_recuperacion_manual.php
// Crea un cliente NUEVO (que no está en la base) a partir de una encuesta
// básica de recuperación, y le genera de inmediato una tarea de recuperación.
// La tarea se crea con estado='programada', igual que cualquier otra tarea
// de recuperación: aparece en la Lista de Recuperaciones del supervisor como
// "Programada" / "—" y en la lista de tareas pendientes del asesor en la app
// móvil, para que el asesor la seleccione y la gestione cuando le toque. La
// revisión (Pendiente/Aprobada/Rechazada) se genera más adelante, cuando el
// asesor la marque como completada y el supervisor la revise.
//
// Parámetros JSON:
//   nombre            — nombres (requerido)
//   apellidos         — apellidos (opcional)
//   cedula            — cédula (opcional)
//   correo / email    — correo electrónico (opcional)
//   cuenta            — cuenta/producto que tenía (texto libre, opcional)
//   monto_credito     — monto del crédito (numérico, opcional)
//   fecha_creacion    — fecha en que se creó la cuenta/crédito (YYYY-MM-DD, opcional)
//   meses_mora        — int, meses en mora (opcional)
//   asesor_id         — asesor destino (opcional)
//   distribuir_equipo — true → si no se indicó asesor_id, usa el primer asesor del
//                        equipo del supervisor. IMPORTANTE: esta encuesta es de UN
//                        solo cliente, así que SIEMPRE se crea UNA sola tarea
//                        (nunca una por cada asesor del equipo).
//   fecha_programada  — YYYY-MM-DD (default hoy)
//   mensaje           — observaciones adicionales

if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth: supervisor o gerente ─────────────────────────────────────
$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if ((!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) && !$is_admin_gerente) {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado']); exit;
}

// ── Resolver supervisor.id (igual que en recuperacion_creditos.php) ─
$supervisor_table_id = null;
try {
    $sess_sup = $_SESSION['supervisor_id'] ?? null;
    if ($sess_sup) {
        $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$sess_sup]);
        $supervisor_table_id = $st->fetchColumn() ?: null;
        if (!$supervisor_table_id) {
            $st = $pdo->prepare('SELECT id FROM supervisor WHERE id = ? LIMIT 1');
            $st->execute([$sess_sup]);
            $supervisor_table_id = $st->fetchColumn() ?: null;
        }
    }
} catch (Throwable $_) {}

// ── Helpers ──────────────────────────────────────────────────────
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$get_cols = function (string $table) use ($pdo): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return $cache[$table] = [];
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) $cols[$r['Field']] = true;
        }
        return $cache[$table] = $cols;
    } catch (Throwable $_) { return $cache[$table] = []; }
};

// ── Leer payload ─────────────────────────────────────────────────
$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$nombre    = trim((string)($payload['nombre'] ?? ''));
$apellidos = trim((string)($payload['apellidos'] ?? ''));
$cedula    = trim((string)($payload['cedula'] ?? ''));
$correo    = trim((string)($payload['correo'] ?? $payload['email'] ?? ''));
$cuenta    = trim((string)($payload['cuenta'] ?? ''));
$montoRaw  = $payload['monto_credito'] ?? null;
$monto     = ($montoRaw !== null && $montoRaw !== '') ? (float)$montoRaw : null;
$fechaCreacion = trim((string)($payload['fecha_creacion'] ?? ''));
$meses_mora = isset($payload['meses_mora']) && $payload['meses_mora'] !== '' ? (int)$payload['meses_mora'] : null;

$distribuir       = !empty($payload['distribuir_equipo']) && $payload['distribuir_equipo'];
$asesor_override  = !empty($payload['asesor_id']) ? (string)$payload['asesor_id'] : null;
$fecha_prog       = !empty($payload['fecha_programada']) ? trim($payload['fecha_programada']) : date('Y-m-d');
$mensaje_extra    = trim((string)($payload['mensaje'] ?? ''));

if ($nombre === '') {
    echo json_encode(['status' => 'error', 'message' => 'El nombre es requerido']); exit;
}

$nombre_full = trim($nombre . ' ' . $apellidos);

// ── Resolver asesor.id a partir de id de asesor o de usuario ───────
$resolverAsesorId = function (string $rawId) use ($pdo): ?string {
    if (!$rawId) return null;
    $s = $pdo->prepare('SELECT id FROM asesor WHERE id = ? LIMIT 1');
    $s->execute([$rawId]);
    $found = $s->fetchColumn();
    if ($found) return (string)$found;
    $s2 = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $s2->execute([$rawId]);
    $found2 = $s2->fetchColumn();
    return $found2 ? (string)$found2 : null;
};

// ── Determinar UN único asesor destino ──────────────────────────
// IMPORTANTE: esta encuesta registra UN solo cliente nuevo, así que se crea
// UNA sola tarea de recuperación (nunca una copia por cada asesor del
// equipo). Además, la app móvil (obtener_tareas_recuperacion_asesor.php)
// solo muestra tareas con asesor_id asignado (no soporta "pool"), así que
// siempre intentamos resolver al menos un asesor real.
$asesorResuelto = null;

if ($asesor_override) {
    $asesorResuelto = $resolverAsesorId($asesor_override) ?: $asesor_override;
}

if (!$asesorResuelto && $distribuir && $supervisor_table_id) {
    try {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE supervisor_id = ? LIMIT 1');
        $st->execute([$supervisor_table_id]);
        $asesorResuelto = $st->fetchColumn() ?: null;
    } catch (Throwable $_) {}
}

// Último recurso: asignar al primer asesor disponible para que la tarea
// no quede "huérfana" sin asesor_id (no sería visible en móvil).
if (!$asesorResuelto) {
    try {
        $asesorResuelto = $pdo->query('SELECT id FROM asesor LIMIT 1')->fetchColumn() ?: null;
    } catch (Throwable $_) {}
}

$asesores_destino = [$asesorResuelto];

// Asesor "principal" para el registro del cliente/crédito
$asesor_principal = $asesorResuelto;

try {
    $pdo->beginTransaction();

    // ── 1) cliente_prospecto: buscar por cédula o crear nuevo ──────
    $cliente_id = null;
    if ($cedula !== '') {
        $st = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
        $st->execute([$cedula]);
        $cliente_id = $st->fetchColumn() ?: null;
    }

    $cols_cp = $get_cols('cliente_prospecto');

    if ($cliente_id) {
        // Cliente ya existe: completar datos vacíos sin sobrescribir lo que ya tenga
        $upd_cols = [];
        $upd_vals = [];
        $maybe = [
            'email'     => $correo,
            'asesor_id' => $asesor_principal,
        ];
        foreach ($maybe as $col => $val) {
            if ($val !== null && $val !== '' && isset($cols_cp[$col])) {
                $upd_cols[] = "`$col` = COALESCE(NULLIF(`$col`,''), ?)";
                $upd_vals[] = $val;
            }
        }
        if (!empty($upd_cols)) {
            $upd_vals[] = $cliente_id;
            $pdo->prepare('UPDATE cliente_prospecto SET ' . implode(', ', $upd_cols) . ' WHERE id = ?')
                ->execute($upd_vals);
        }
    } else {
        $cliente_id = uuid4();
        $cols = ['id'];
        $vals = [$cliente_id];

        $candidatos = [
            'nombre'          => $nombre_full,
            'cedula'          => $cedula !== '' ? $cedula : null,
            'email'           => $correo !== '' ? $correo : null,
            'asesor_id'       => $asesor_principal,
            'estado'          => 'cliente',
            'origen_prospecto'=> 'cliente',
        ];
        // Apellidos como columna separada si existe en el esquema
        if ($apellidos !== '' && isset($cols_cp['apellido'])) $candidatos['apellido'] = $apellidos;
        if ($apellidos !== '' && isset($cols_cp['apellidos'])) $candidatos['apellidos'] = $apellidos;

        foreach ($candidatos as $col => $val) {
            if ($val === null) continue;
            if (!isset($cols_cp[$col])) continue;
            if (in_array($col, $cols, true)) continue;
            $cols[] = $col;
            $vals[] = $val;
        }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        try {
            $pdo->prepare("INSERT INTO cliente_prospecto ($colList) VALUES ($ph)")->execute($vals);
        } catch (Throwable $eIns) {
            // Fallback mínimo: solo columnas básicas garantizadas
            $colsMin = ['id', 'nombre'];
            $valsMin = [$cliente_id, $nombre_full];
            if ($cedula !== '' && isset($cols_cp['cedula'])) { $colsMin[] = 'cedula'; $valsMin[] = $cedula; }
            $phMin = implode(',', array_fill(0, count($colsMin), '?'));
            $colListMin = implode(', ', array_map(fn($c) => "`$c`", $colsMin));
            $pdo->prepare("INSERT INTO cliente_prospecto ($colListMin) VALUES ($phMin)")->execute($valsMin);
        }
    }

    // ── 2) credito_proceso: registrar el crédito en recuperación ───
    $cols_crp = $get_cols('credito_proceso');
    if (!empty($cols_crp)) {
        $creditoId = uuid4();
        $cols = ['id', 'cliente_prospecto_id'];
        $vals = [$creditoId, $cliente_id];

        $candidatosCp = [
            'asesor_id'      => $asesor_principal,
            'estado_credito' => 'recuperacion',
            'monto_aprobado' => $monto,
            'monto_credito'  => $monto,
            'producto'       => $cuenta !== '' ? $cuenta : null,
            'tipo_producto'  => $cuenta !== '' ? $cuenta : null,
            'observaciones'  => $mensaje_extra !== '' ? $mensaje_extra : null,
        ];
        foreach ($candidatosCp as $col => $val) {
            if ($val === null) continue;
            if (!isset($cols_crp[$col])) continue;
            if (in_array($col, $cols, true)) continue;
            $cols[] = $col;
            $vals[] = $val;
        }
        if (isset($cols_crp['created_at']) && !in_array('created_at', $cols, true)) {
            $cols[] = 'created_at';
            // Si el usuario indicó cuándo se creó originalmente la cuenta/crédito, usar esa fecha
            $vals[] = $fechaCreacion !== '' ? ($fechaCreacion . ' 00:00:00') : date('Y-m-d H:i:s');
        }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        try {
            $pdo->prepare("INSERT INTO credito_proceso ($colList) VALUES ($ph)")->execute($vals);
        } catch (Throwable $_) {
            // No es crítico si falla: el cliente y la tarea ya quedan registrados
        }
    }

    // ── 3) tarea(s) de recuperación ─────────────────────────────────
    $obsParts = ['Recuperación de cliente nuevo (no estaba en la base).'];
    if ($cuenta !== '')        $obsParts[] = "Cuenta/Producto: $cuenta.";
    if ($monto !== null)       $obsParts[] = 'Monto del crédito: $' . number_format($monto, 2) . '.';
    if ($fechaCreacion !== '') $obsParts[] = "Fecha en que se creó: $fechaCreacion.";
    if ($meses_mora !== null)  $obsParts[] = "Meses en mora: $meses_mora.";
    if ($correo !== '')        $obsParts[] = "Correo: $correo.";
    if ($mensaje_extra !== '') $obsParts[] = $mensaje_extra;
    $obs = implode(' ', $obsParts);

    // La tarea se crea como "Programada": queda en la Lista de Recuperaciones
    // del supervisor (Estado: Programada, Revisión: —) y en la lista de
    // tareas pendientes del asesor en la app móvil, igual que cualquier otra
    // tarea de recuperación, para que el asesor la seleccione y la gestione.
    $created = [];
    foreach ($asesores_destino as $aid) {
        $tareaId = uuid4();

        $colsT = ['id', 'asesor_id', 'cliente_prospecto_id', 'tipo_tarea', 'estado', 'fecha_programada', 'observaciones', 'created_at'];
        $valsT = [$tareaId, $aid, $cliente_id, 'recuperacion', 'programada', $fecha_prog, $obs, date('Y-m-d H:i:s')];

        $ph      = implode(',', array_fill(0, count($colsT), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $colsT));
        $pdo->prepare("INSERT INTO tarea ($colList) VALUES ($ph)")->execute($valsT);
        $created[] = $tareaId;
    }

    $pdo->commit();

    echo json_encode([
        'status'     => 'success',
        'cliente_id' => $cliente_id,
        'total'      => count($created),
        'created'    => $created,
        'message'    => 'Cliente registrado y tarea de recuperación creada (Programada para el asesor)',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}

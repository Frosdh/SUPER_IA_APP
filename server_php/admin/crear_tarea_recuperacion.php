<?php
// admin/crear_tarea_recuperacion.php
// Crea tareas de recuperación.
// Parámetros JSON:
//   credito_id / credito_ids  — IDs de credito_proceso o ficha_producto
//   fuente                    — 'ficha' | 'proceso' (para credito_id individual; default 'proceso')
//   fuente_map                — { credito_id: 'ficha'|'proceso', ... } (para credito_ids bulk)
//   asesor_id                 — asesor destino (opcional; se usa el original si vacío)
//   distribuir_equipo         — true → crea la tarea para TODOS los asesores del supervisor
//   meses_mora                — int, meses en mora
//   fecha_programada          — YYYY-MM-DD (default hoy)
//   mensaje                   — observaciones
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    echo json_encode(['status'=>'error','message'=>'Acceso denegado']); exit;
}

$supervisor_usuario_id = $_SESSION['supervisor_id'];
// Resolver supervisor.id de forma robusta: la sesión puede contener usuario_id o supervisor.id
$supervisor_table_id = null;
try {
    $sess_sup = $_SESSION['supervisor_id'] ?? null;
    if ($sess_sup) {
        // Primero intentar como usuario_id
        $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$sess_sup]);
        $supervisor_table_id = $st->fetchColumn() ?: null;
        // Si no lo encontramos, intentar como supervisor.id directamente
        if (!$supervisor_table_id) {
            $st = $pdo->prepare('SELECT id FROM supervisor WHERE id = ? LIMIT 1');
            $st->execute([$sess_sup]);
            $supervisor_table_id = $st->fetchColumn() ?: null;
        }
    }
} catch (Throwable $_) {}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// IDs de créditos
$creditos = [];
if (!empty($payload['credito_ids'])) {
    $creditos = is_array($payload['credito_ids'])
        ? $payload['credito_ids']
        : array_filter(array_map('trim', explode(',', (string)$payload['credito_ids'])));
} elseif (!empty($payload['credito_id'])) {
    $creditos = [(string)$payload['credito_id']];
}
if (empty($creditos)) {
    echo json_encode(['status'=>'error','message'=>'credito_id(s) requerido']); exit;
}

// fuente: 'ficha' o 'proceso' — puede venir como string único (modal) o como mapa id→fuente (bulk)
$fuente_single  = !empty($payload['fuente'])     ? (string)$payload['fuente']    : 'proceso';
$fuente_map     = !empty($payload['fuente_map']) && is_array($payload['fuente_map'])
                  ? $payload['fuente_map']
                  : [];

$distribuir    = !empty($payload['distribuir_equipo']) && $payload['distribuir_equipo'];
$asesor_override = !empty($payload['asesor_id']) ? (string)$payload['asesor_id'] : null;
$meses_mora    = isset($payload['meses_mora']) ? (int)$payload['meses_mora'] : null;
$fecha_prog    = !empty($payload['fecha_programada']) ? trim($payload['fecha_programada']) : date('Y-m-d');
$mensaje_base  = trim((string)($payload['mensaje'] ?? 'Recuperación: contactar cliente por cuotas pendientes'));

// Si distribuir_equipo: obtener todos los asesores del supervisor
$asesores_equipo = [];
if ($distribuir && $supervisor_table_id) {
    try {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE supervisor_id = ?');
        $st->execute([$supervisor_table_id]);
        $asesores_equipo = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $_) {}
}

// Si pidieron distribuir pero no encontramos supervisores/asesores, devolver error claro
if ($distribuir && empty($asesores_equipo)) {
    echo json_encode(['status'=>'error','message'=>'No se encontraron asesores del equipo para distribuir (verifique sesión de supervisor)']);
    exit;
}

$created = [];
$errors  = [];

foreach ($creditos as $cid) {
    $cid = (string)$cid;
    // Determinar fuente para este id
    $fuente_cid = $fuente_map[$cid] ?? $fuente_single;

    try {
        $cliente_id      = null;
        $asesor_original = null; // será siempre asesor.id (no usuario_id)

        // Función inline para resolver asesor.id a partir de un valor que puede ser
        // asesor.id o asesor.usuario_id (app a veces guarda el usuario_id directamente)
        $resolverAsesorId = function(string $rawId) use ($pdo): ?string {
            if (!$rawId) return null;
            // Intentar como asesor.id directo
            $s = $pdo->prepare('SELECT id FROM asesor WHERE id = ? LIMIT 1');
            $s->execute([$rawId]);
            $found = $s->fetchColumn();
            if ($found) return (string)$found;
            // Fallback: el rawId puede ser usuario_id
            $s2 = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
            $s2->execute([$rawId]);
            $found2 = $s2->fetchColumn();
            return $found2 ? (string)$found2 : null;
        };

        if ($fuente_cid === 'ficha') {
            // Buscar en ficha_producto → obtener cliente_cedula y asesor_id
            $st = $pdo->prepare('SELECT cliente_cedula, asesor_id, usuario_id FROM ficha_producto WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $fp = $st->fetch();
            if ($fp) {
                // Resolver asesor.id (puede venir como asesor_id o usuario_id en la ficha)
                $rawAsesor = $fp['asesor_id'] ?: $fp['usuario_id'];
                $asesor_original = $resolverAsesorId((string)($rawAsesor ?? ''));
                // Resolver cliente_prospecto.id por cedula
                $stCp = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
                $stCp->execute([$fp['cliente_cedula']]);
                $cpRow = $stCp->fetch();
                if ($cpRow) {
                    $cliente_id = $cpRow['id'];
                } else {
                    $errors[] = "Cliente de ficha $cid no encontrado en cliente_prospecto (cédula: {$fp['cliente_cedula']})";
                    continue;
                }
            } else {
                // Fallback: intentar en credito_proceso
                $st2 = $pdo->prepare('SELECT cliente_prospecto_id, asesor_id FROM credito_proceso WHERE id = ? LIMIT 1');
                $st2->execute([$cid]);
                $r2 = $st2->fetch();
                if (!$r2) { $errors[] = "Crédito (ficha) $cid no encontrado"; continue; }
                $cliente_id      = $r2['cliente_prospecto_id'];
                $asesor_original = $resolverAsesorId((string)($r2['asesor_id'] ?? ''));
            }
        } else {
            // fuente = proceso (credito_proceso)
            $st = $pdo->prepare('SELECT cliente_prospecto_id, asesor_id FROM credito_proceso WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $r = $st->fetch();
            if (!$r) {
                // Fallback: intentar en ficha_producto por si hubo confusión de fuente
                $stFb = $pdo->prepare('SELECT cliente_cedula, asesor_id, usuario_id FROM ficha_producto WHERE id = ? LIMIT 1');
                $stFb->execute([$cid]);
                $fb = $stFb->fetch();
                if ($fb) {
                    $rawAsesor = $fb['asesor_id'] ?: $fb['usuario_id'];
                    $asesor_original = $resolverAsesorId((string)($rawAsesor ?? ''));
                    $stCp = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
                    $stCp->execute([$fb['cliente_cedula']]);
                    $cpRow = $stCp->fetch();
                    if (!$cpRow) { $errors[] = "Cliente de crédito $cid no encontrado"; continue; }
                    $cliente_id = $cpRow['id'];
                } else {
                    $errors[] = "Crédito (proceso) $cid no encontrado";
                    continue;
                }
            } else {
                $cliente_id      = $r['cliente_prospecto_id'];
                $asesor_original = $resolverAsesorId((string)($r['asesor_id'] ?? ''));
            }
        }

        if (!$cliente_id) {
            $errors[] = "No se pudo resolver cliente para crédito $cid";
            continue;
        }

        // Determinar lista de asesores destino
        $asesores_destino = [];
        if ($distribuir) {
            $asesores_destino = $asesores_equipo; // ya validados arriba
        } elseif ($asesor_override) {
            $asesores_destino = [$asesor_override];
        } elseif ($asesor_original) {
            $asesores_destino = [$asesor_original];
        } else {
            // Sin asesor → tarea de pool (asesor_id NULL)
            $asesores_destino = [null];
        }

        // Construir mensaje con meses en mora si aplica
        $obs = $mensaje_base;
        if ($meses_mora !== null) {
            $obs = "Meses en mora: $meses_mora. " . $obs;
        }

        // Insertar tarea por cada asesor destino
        foreach ($asesores_destino as $aid) {
            $tareaId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );

            $stIns = $pdo->prepare(
                'INSERT INTO tarea
                 (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, observaciones, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stIns->execute([
                $tareaId,
                $aid,
                $cliente_id,
                'recuperacion',
                'programada',   // ENUM válido: programada|en_proceso|completada|postergada
                $fecha_prog,
                $obs,
            ]);
            $created[] = $tareaId;
        }

    } catch (Throwable $e) {
        $errors[] = "Error en crédito $cid: " . $e->getMessage();
    }
}

echo json_encode([
    'status'  => empty($errors) ? 'success' : (empty($created) ? 'error' : 'partial'),
    'total'   => count($created),
    'created' => $created,
    'errors'  => $errors,
    'message' => empty($errors)
        ? count($created) . ' tarea(s) creadas correctamente'
        : implode('; ', $errors),
]);

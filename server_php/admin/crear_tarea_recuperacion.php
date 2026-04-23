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
                    $errors[] = "Crédito $cid no encontrado";
                    continue;
                }
            } else {
                $cliente_id      = $r['cliente_prospecto_id'];
                $asesor_original = $resolverAsesorId((string)($r['asesor_id'] ?? ''));
            }
        }

        // Armar la observación con meses en mora
        $obs = $mensaje_base;
        if ($meses_mora !== null) {
            $obs .= " — Meses en mora: $meses_mora";
        }
        $obs .= " (credito_ref:$cid)";

        // Determinar lista de asesores destino (ya resueltos como asesor.id)
        if ($distribuir && !empty($asesores_equipo)) {
            $destinos = $asesores_equipo; // ya son asesor.id (SELECT id FROM asesor)
        } elseif ($asesor_override) {
            // El override viene del front: puede ser asesor.id o usuario_id → resolver
            $resolved = $resolverAsesorId($asesor_override);
            $destinos = $resolved ? [$resolved] : [$asesor_override];
        } elseif ($asesor_original) {
            $destinos = [$asesor_original];
        } else {
            $errors[] = "Crédito $cid sin asesor asignado";
            continue;
        }

        foreach ($destinos as $asesor_id) {
            // Generar UUID v4 compatible
            $tarea_id = sprintf('%08x-%04x-4%03x-%04x-%012x',
                mt_rand(0, 0xffffffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff),
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffffffffffff)
            );

            // Insertar tarea de recuperación
            $ins = $pdo->prepare(
                "INSERT INTO tarea
                   (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                    fecha_programada, observaciones, created_at)
                 VALUES (?, ?, ?, 'recuperacion', 'programada', ?, ?, NOW())"
            );
            $ins->execute([$tarea_id, $asesor_id, $cliente_id, $fecha_prog, $obs]);

            // ── Insertar en agenda_detalle para que aparezca en la agenda móvil del asesor ──
            // Buscar o crear el agenda_dia del asesor para esa fecha
            $agenda_dia_id = null;
            try {
                $stAD = $pdo->prepare(
                    "SELECT id FROM agenda_detalle
                     WHERE tarea_id = ? LIMIT 1"
                );
                $stAD->execute([$tarea_id]);
                $existeAD = $stAD->fetchColumn();

                if (!$existeAD) {
                    // Insertar agenda_detalle directamente (la app leerá las tareas de recuperación)
                    $agenda_det_id = sprintf('%08x-%04x-4%03x-%04x-%012x',
                        mt_rand(0, 0xffffffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff),
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffffffffffff)
                    );
                    // agenda_dia_id: buscar si ya existe un registro de agenda para este asesor y fecha
                    // Como agenda_detalle solo requiere agenda_dia_id (que puede ser null en algunos esquemas),
                    // usamos el tarea_id como referencia directa.
                    $insAD = $pdo->prepare(
                        "INSERT INTO agenda_detalle
                           (id, agenda_dia_id, tarea_id, tipo, completado, postergado)
                         VALUES (?, NULL, ?, 'recuperacion', 0, 0)"
                    );
                    $insAD->execute([$agenda_det_id, $tarea_id]);
                }
            } catch (Throwable $eAD) {
                // No bloquear si falla agenda_detalle; la tarea ya fue creada
                error_log('[crear_tarea_rec agenda_detalle] ' . $eAD->getMessage());
            }

            $created[] = ['credito_id'=>$cid, 'tarea_id'=>$tarea_id, 'asesor_id'=>$asesor_id];
        }
    } catch (Throwable $e) {
        $errors[] = "Error en crédito $cid: " . $e->getMessage();
    }
}

if (empty($created)) {
    echo json_encode(['status'=>'error','message'=>'No se crearon tareas','errors'=>$errors]);
} else {
    echo json_encode(['status'=>'success','created'=>$created,'errors'=>$errors,'total'=>count($created)]);
}
?>

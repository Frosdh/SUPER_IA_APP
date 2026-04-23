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
// Resolver supervisor.id
$supervisor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$supervisor_usuario_id]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
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

$created = [];
$errors  = [];

foreach ($creditos as $cid) {
    $cid = (string)$cid;
    // Determinar fuente para este id
    $fuente_cid = $fuente_map[$cid] ?? $fuente_single;

    try {
        $cliente_id      = null;
        $asesor_original = null;

        if ($fuente_cid === 'ficha') {
            // Buscar en ficha_producto → obtener cliente_cedula y asesor_id → resolver cliente_prospecto.id
            $st = $pdo->prepare('SELECT cliente_cedula, asesor_id FROM ficha_producto WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $fp = $st->fetch();
            if ($fp) {
                $asesor_original = $fp['asesor_id'];
                // Resolver cliente_prospecto.id por cedula
                $stCp = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
                $stCp->execute([$fp['cliente_cedula']]);
                $cpRow = $stCp->fetch();
                if ($cpRow) {
                    $cliente_id = $cpRow['id'];
                } else {
                    // Si no existe en cliente_prospecto, intentar obtener asesor desde la ficha y continuar sin cliente_id
                    $errors[] = "Cliente de ficha $cid no encontrado en cliente_prospecto (cédula: {$fp['cliente_cedula']})";
                    continue;
                }
            } else {
                // Fallback: intentar en credito_proceso por si el id corresponde allí
                $st2 = $pdo->prepare('SELECT cliente_prospecto_id, asesor_id FROM credito_proceso WHERE id = ? LIMIT 1');
                $st2->execute([$cid]);
                $r2 = $st2->fetch();
                if (!$r2) { $errors[] = "Crédito (ficha) $cid no encontrado"; continue; }
                $cliente_id      = $r2['cliente_prospecto_id'];
                $asesor_original = $r2['asesor_id'];
            }
        } else {
            // fuente = proceso
            $st = $pdo->prepare('SELECT cliente_prospecto_id, asesor_id FROM credito_proceso WHERE id = ? LIMIT 1');
            $st->execute([$cid]);
            $r = $st->fetch();
            if (!$r) {
                // Fallback: intentar en ficha_producto por si hubo confusión de fuente
                $stFb = $pdo->prepare('SELECT cliente_cedula, asesor_id FROM ficha_producto WHERE id = ? LIMIT 1');
                $stFb->execute([$cid]);
                $fb = $stFb->fetch();
                if ($fb) {
                    $asesor_original = $fb['asesor_id'];
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
                $asesor_original = $r['asesor_id'];
            }
        }

        // Armar la observación con meses en mora
        $obs = $mensaje_base;
        if ($meses_mora !== null) {
            $obs .= " — Meses en mora: $meses_mora";
        }
        $obs .= " (credito_ref:$cid)";

        // Determinar lista de asesores destino
        if ($distribuir && !empty($asesores_equipo)) {
            $destinos = $asesores_equipo;
        } elseif ($asesor_override) {
            $destinos = [$asesor_override];
        } elseif ($asesor_original) {
            $destinos = [$asesor_original];
        } else {
            $errors[] = "Crédito $cid sin asesor asignado";
            continue;
        }

        foreach ($destinos as $asesor_id) {
            $tarea_id = bin2hex(random_bytes(16));
            $ins = $pdo->prepare(
                "INSERT INTO tarea (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, observaciones)
                 VALUES (?, ?, ?, 'recuperacion', 'programada', ?, ?)"
            );
            $ins->execute([$tarea_id, $asesor_id, $cliente_id, $fecha_prog, $obs]);
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

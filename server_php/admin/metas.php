<?php
// ============================================================
// admin/metas.php — Asignación de Metas Diarias al Asesor (Supervisor)
// Nota: este archivo debe contener UNA sola página.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php';   // PDO ($pdo)

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    header('Location: login.php?role=supervisor');
    exit;
}


$supervisor_usuario_id = $_SESSION['supervisor_id'];
$supervisor_nombre     = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol        = $_SESSION['supervisor_rol'] ?? 'Supervisor';

// Resolver supervisor.id
$supervisor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$supervisor_usuario_id]);
    $supervisor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

$flash = null;

// Sidebar vars
$currentPage = 'metas';
$alertas_pendientes = 0;

// ── Validar instalación de tablas/vistas de metas ───────────
$metas_instaladas = true;
try {
    $chk = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' LIMIT 1");
    $chk->execute();
    $metas_instaladas = (bool)$chk->fetchColumn();
} catch (PDOException $e) {
    // si no se puede consultar information_schema, intentaremos igual y capturaremos error
    $metas_instaladas = true;
}

if (!$metas_instaladas) {
    $dbName = '';
    try {
        $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $dbName = '';
    }

    $flash = [
        'type' => 'error',
        'msg'  => "Falta crear la tabla <b>meta_asesor_diaria</b> en la base <b>" . htmlspecialchars($dbName ?: 'corporat_base_super_ia') . "</b>. " .
                 "Ejecuta el script <b>server_php/crear_tabla_metas_asesor.sql</b> en phpMyAdmin (pestaña SQL / Importar)."
    ];
}

// ── Guardar meta ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $supervisor_table_id && $metas_instaladas) {
    $asesor_id = $_POST['asesor_id'] ?? '';
    $fecha     = $_POST['fecha'] ?? date('Y-m-d');
    $m_enc     = (int)($_POST['meta_encuestas'] ?? 0);
    $m_cli     = (int)($_POST['meta_clientes_nuevos'] ?? 0);
    $m_cre     = (int)($_POST['meta_creditos'] ?? 0);
    $m_cah     = (int)($_POST['meta_cuenta_ahorros'] ?? 0);
    $m_cco     = (int)($_POST['meta_cuenta_corriente'] ?? 0);
    $m_inv     = (int)($_POST['meta_inversiones'] ?? 0);
    $obs       = trim($_POST['observaciones'] ?? '');

    if ($asesor_id) {
        try {
            // Compatibilidad: algunas instalaciones tienen supervisor_id (NOT NULL)
            $has_supervisor_id = false;
            try {
                $stCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' AND column_name = 'supervisor_id' LIMIT 1");
                $stCol->execute();
                $has_supervisor_id = (bool)$stCol->fetchColumn();
            } catch (PDOException $e) {
                try {
                    $has_supervisor_id = (bool)$pdo->query("SHOW COLUMNS FROM meta_asesor_diaria LIKE 'supervisor_id'")->fetchColumn();
                } catch (PDOException $e2) {
                    $has_supervisor_id = false;
                }
            }

            
            // Compatibilidad: algunas instalaciones no tienen actualizado_at
            $has_actualizado_at = false;
            try {
                $stCol = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'meta_asesor_diaria' AND column_name = 'actualizado_at' LIMIT 1");
                $stCol->execute();
                $has_actualizado_at = (bool)$stCol->fetchColumn();
            } catch (PDOException $e) {
                // Fallback si information_schema no está disponible
                try {
                    $has_actualizado_at = (bool)$pdo->query("SHOW COLUMNS FROM meta_asesor_diaria LIKE 'actualizado_at'")->fetchColumn();
                } catch (PDOException $e2) {
                    $has_actualizado_at = false;
                }
            }

            if (!$has_actualizado_at) {
                // Intento no destructivo de agregar la columna (si el hosting lo permite)
                try {
                    $pdo->exec("ALTER TABLE meta_asesor_diaria ADD COLUMN actualizado_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                    $has_actualizado_at = true;
                } catch (PDOException $e) {
                    $has_actualizado_at = false;
                }
            }

            // Algunas instalaciones agregaron meta_asesor_diaria.supervisor_id como NOT NULL.
            // Aun así, el filtrado se mantiene por asesor.supervisor_id.

            $cols = [
                'asesor_id', 'fecha',
                'meta_encuestas', 'meta_clientes_nuevos', 'meta_creditos',
                'meta_cuenta_ahorros', 'meta_cuenta_corriente', 'meta_inversiones',
                'observaciones'
            ];
            $vals = [
                $asesor_id, $fecha,
                $m_enc, $m_cli, $m_cre, $m_cah, $m_cco, $m_inv, $obs
            ];
            if ($has_supervisor_id) {
                $cols[] = 'supervisor_id';
                $vals[] = (string)$supervisor_table_id;
            }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colList = implode(', ', $cols);

            $sql = "INSERT INTO meta_asesor_diaria ($colList)
                    VALUES ($placeholders)
                    ON DUPLICATE KEY UPDATE
                      estado = IF(estado IN (\"completado\",\"no_cumplido\"), estado, \"pendiente\"),
                      meta_encuestas = VALUES(meta_encuestas),
                      meta_clientes_nuevos = VALUES(meta_clientes_nuevos),
                      meta_creditos = VALUES(meta_creditos),
                      meta_cuenta_ahorros = VALUES(meta_cuenta_ahorros),
                      meta_cuenta_corriente = VALUES(meta_cuenta_corriente),
                      meta_inversiones = VALUES(meta_inversiones),
                      observaciones = VALUES(observaciones)";

            if ($has_supervisor_id) {
                $sql .= ", supervisor_id = VALUES(supervisor_id)";
            }
            if ($has_actualizado_at) {
                $sql .= ", actualizado_at = CURRENT_TIMESTAMP";
            }

            $st = $pdo->prepare($sql);
            $st->execute($vals);
            $flash = ['type' => 'success', 'msg' => 'Meta asignada correctamente'];
        } catch (PDOException $e) {
            $flash = ['type' => 'error', 'msg' => 'Error: ' . $e->getMessage()];
        }
    } else {
        $flash = ['type' => 'error', 'msg' => 'Debe seleccionar un asesor'];
    }
}

// ── Cargar asesores del supervisor ───────────────────────────
$asesores = [];
if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a
                         JOIN usuario u ON u.id = a.usuario_id
                         WHERE a.supervisor_id = ? AND u.activo = 1
                         ORDER BY u.nombre');
    $st->execute([$supervisor_table_id]);
    $asesores = $st->fetchAll();
}

// ── Metas del día actual con avance ──────────────────────────
$fecha_filtro = $_GET['fecha'] ?? date('Y-m-d');
$metas_hoy = [];
if ($supervisor_table_id && $metas_instaladas) {
    // Intentar con la vista de avances; si no existe, usar avances 0.
    $sql = "SELECT m.*, u.nombre AS asesor_nombre,
                   v.avance_encuestas, v.avance_clientes_nuevos, v.avance_creditos,
                   v.avance_cuenta_ahorros, v.avance_cuenta_corriente, v.avance_inversiones
            FROM meta_asesor_diaria m
            JOIN asesor a ON a.id = m.asesor_id
            JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN v_meta_asesor_avance v ON v.meta_id = m.id
            WHERE a.supervisor_id = ? AND m.fecha = ?
            ORDER BY u.nombre";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$supervisor_table_id, $fecha_filtro]);
        $metas_hoy = $st->fetchAll();
    } catch (PDOException $e) {
        // Fallback sin la vista
        try {
            $sql2 = "SELECT m.*, u.nombre AS asesor_nombre,
                            0 AS avance_encuestas, 0 AS avance_clientes_nuevos, 0 AS avance_creditos,
                            0 AS avance_cuenta_ahorros, 0 AS avance_cuenta_corriente, 0 AS avance_inversiones
                     FROM meta_asesor_diaria m
                     JOIN asesor a ON a.id = m.asesor_id
                     JOIN usuario u ON u.id = a.usuario_id
                     WHERE a.supervisor_id = ? AND m.fecha = ?
                     ORDER BY u.nombre";
            $st2 = $pdo->prepare($sql2);
            $st2->execute([$supervisor_table_id, $fecha_filtro]);
            $metas_hoy = $st2->fetchAll();
        } catch (PDOException $e2) {
            $metas_hoy = [];
        }
    }

    // Auto-actualiza estado: completado si ya cumplió, no_cumplido si ya pasaron las 18:00
    // (asegura consistencia incluso si EVENT SCHEDULER está desactivado)
    if (!empty($metas_hoy)) {
        $hoy = date('Y-m-d');
        $horaActual = (int)date('H');

        $uSt = $pdo->prepare('UPDATE meta_asesor_diaria SET estado = ?, cerrado_at = NOW() WHERE id = ?');

        foreach ($metas_hoy as &$m) {
            if (($m['estado'] ?? '') !== 'pendiente') continue;

            $debeCerrar = false;
            if ($fecha_filtro < $hoy) {
                $debeCerrar = true;
            } elseif ($fecha_filtro === $hoy && $horaActual >= 18) {
                $debeCerrar = true;
            }

            $pares = [
                ['meta_encuestas','avance_encuestas'],
                ['meta_clientes_nuevos','avance_clientes_nuevos'],
                ['meta_creditos','avance_creditos'],
                ['meta_cuenta_ahorros','avance_cuenta_ahorros'],
                ['meta_cuenta_corriente','avance_cuenta_corriente'],
                ['meta_inversiones','avance_inversiones'],
            ];
            $cumplio = true;
            foreach ($pares as [$mk, $ak]) {
                $meta = (int)($m[$mk] ?? 0);
                $av   = (int)($m[$ak] ?? 0);
                if ($meta > 0 && $av < $meta) { $cumplio = false; break; }
            }

            if ($cumplio) {
                try { $uSt->execute(['completado', $m['id']]); } catch (PDOException $e) {}
                $m['estado'] = 'completado';
            } elseif ($debeCerrar) {
                try { $uSt->execute(['no_cumplido', $m['id']]); } catch (PDOException $e) {}
                $m['estado'] = 'no_cumplido';
            }
        }
        unset($m);
    }
}

// ── Filtros para el listado de tareas del equipo ─────────────
$tareas_asesor_filtro = trim($_GET['t_asesor'] ?? '');
$tareas_desde         = trim($_GET['t_desde'] ?? '');
$tareas_hasta         = trim($_GET['t_hasta'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tareas_desde)) {
    $tareas_desde = date('Y-m-d', strtotime('-7 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tareas_hasta)) {
    $tareas_hasta = date('Y-m-d');
}
if ($tareas_desde > $tareas_hasta) {
    // swap si el usuario invirtió el rango
    [$tareas_desde, $tareas_hasta] = [$tareas_hasta, $tareas_desde];
}

// Validar que el asesor filtrado pertenezca al supervisor
$asesor_ids_equipo = array_map(fn($a) => (string)$a['id'], $asesores);
if ($tareas_asesor_filtro !== '' && !in_array($tareas_asesor_filtro, $asesor_ids_equipo, true)) {
    $tareas_asesor_filtro = '';
}

// ── Cargar tareas del equipo (completadas + incompletas + programadas) ─
$tareas_completadas = [];
$tareas_incompletas = [];
$tareas_programadas = [];

if ($supervisor_table_id && !empty($asesor_ids_equipo)) {
    // Asegurar que existan las columnas de trazabilidad (no destructivo)
    try {
        $has_pospuesta = (bool)$pdo->query("SHOW COLUMNS FROM tarea LIKE 'pospuesta_de_dia'")->fetchColumn();
        if (!$has_pospuesta) {
            try {
                $pdo->exec("ALTER TABLE tarea ADD COLUMN pospuesta_de_dia DATE DEFAULT NULL");
            } catch (PDOException $e) { /* ignorar si el hosting bloquea ALTER */ }
        }
    } catch (PDOException $e) { /* ignorar */ }

    try {
        $ph = implode(',', array_fill(0, count($asesor_ids_equipo), '?'));

        // --- Completadas en el rango ---
        $sqlC = "SELECT t.id, t.tipo_tarea, t.estado,
                        t.fecha_programada, t.hora_programada,
                        t.fecha_realizada, t.hora_realizada,
                        t.seleccionada_dia, t.observaciones,
                        u.nombre AS asesor_nombre,
                        cp.nombre AS cliente_nombre,
                        cp.ciudad AS cliente_ciudad
                 FROM tarea t
                 JOIN asesor a ON a.id = t.asesor_id
                 JOIN usuario u ON u.id = a.usuario_id
                 LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                 WHERE a.supervisor_id = ?
                   AND t.asesor_id IN ($ph)
                   AND t.estado = 'completada'
                   AND t.fecha_realizada BETWEEN ? AND ?";
        $paramsC = array_merge([$supervisor_table_id], $asesor_ids_equipo, [$tareas_desde, $tareas_hasta]);
        if ($tareas_asesor_filtro !== '') {
            $sqlC .= " AND t.asesor_id = ?";
            $paramsC[] = $tareas_asesor_filtro;
        }
        $sqlC .= " ORDER BY t.fecha_realizada DESC, t.hora_realizada DESC LIMIT 300";

        $stC = $pdo->prepare($sqlC);
        $stC->execute($paramsC);
        $tareas_completadas = $stC->fetchAll();

        // --- Incompletas: tareas NO completadas cuyo día efectivo (el día
        // en que realmente se esperaba hacerla) haya caído dentro del
        // rango pedido.
        //
        // Día efectivo:
        //   - Si fue pospuesta: el día original (pospuesta_de_dia)
        //   - Si no: la fecha_programada
        //
        // Regla de cuándo contar como incompleta:
        //   - Si la tarea fue POSPUESTA: cuenta inmediatamente como
        //     incompleta del día original — el solo hecho de haberla
        //     pospuesto ya indica que no se hará ese día.
        //   - Si NO fue pospuesta: solo cuenta si el día ya pasó
        //     (o si es hoy después de las 18:00), porque todavía
        //     podría cumplirse en el transcurso del día. ---
        $sqlI = "SELECT t.id, t.tipo_tarea, t.estado,
                        t.fecha_programada, t.hora_programada,
                        t.fecha_realizada, t.hora_realizada,
                        t.seleccionada_dia, t.seleccionada_at,
                        t.pospuesta_de_dia, t.observaciones,
                        u.nombre AS asesor_nombre,
                        cp.nombre AS cliente_nombre,
                        cp.ciudad AS cliente_ciudad
                 FROM tarea t
                 JOIN asesor a ON a.id = t.asesor_id
                 JOIN usuario u ON u.id = a.usuario_id
                 LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                 WHERE a.supervisor_id = ?
                   AND t.asesor_id IN ($ph)
                   AND t.estado <> 'completada'
                   AND (
                        -- Caso pospuesta: cuenta inmediatamente contra el día original
                        (t.pospuesta_de_dia IS NOT NULL
                           AND t.pospuesta_de_dia BETWEEN ? AND ?)
                     OR
                        -- Caso no pospuesta: fecha_programada ya tiene que haber pasado
                        -- (o ya haber terminado la jornada si es hoy)
                        (t.pospuesta_de_dia IS NULL
                           AND t.fecha_programada BETWEEN ? AND ?
                           AND (
                                t.fecha_programada < CURDATE()
                             OR (t.fecha_programada = CURDATE() AND HOUR(NOW()) >= 18)
                           ))
                   )";
        $paramsI = array_merge(
            [$supervisor_table_id],
            $asesor_ids_equipo,
            [$tareas_desde, $tareas_hasta, $tareas_desde, $tareas_hasta]
        );
        if ($tareas_asesor_filtro !== '') {
            $sqlI .= " AND t.asesor_id = ?";
            $paramsI[] = $tareas_asesor_filtro;
        }
        $sqlI .= " ORDER BY COALESCE(t.pospuesta_de_dia, t.fecha_programada) DESC,
                            t.hora_programada DESC
                   LIMIT 300";

        $stI = $pdo->prepare($sqlI);
        $stI->execute($paramsI);
        $tareas_incompletas = $stI->fetchAll();

        // --- Programadas: tareas NO completadas cuya fecha_programada
        // cae en el rango Y aún no ha terminado la jornada de ese día.
        // Incluye las pospuestas que fueron reasignadas a otro día
        // (porque su fecha_programada es ahora el día nuevo). ---
        $sqlP = "SELECT t.id, t.tipo_tarea, t.estado,
                        t.fecha_programada, t.hora_programada,
                        t.seleccionada_dia, t.pospuesta_de_dia,
                        t.observaciones,
                        u.nombre AS asesor_nombre,
                        cp.nombre AS cliente_nombre,
                        cp.ciudad AS cliente_ciudad
                 FROM tarea t
                 JOIN asesor a ON a.id = t.asesor_id
                 JOIN usuario u ON u.id = a.usuario_id
                 LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                 WHERE a.supervisor_id = ?
                   AND t.asesor_id IN ($ph)
                   AND t.estado NOT IN ('completada','cancelada')
                   AND t.fecha_programada BETWEEN ? AND ?
                   AND (
                        t.fecha_programada > CURDATE()
                     OR (t.fecha_programada = CURDATE() AND HOUR(NOW()) < 18)
                   )";
        $paramsP = array_merge(
            [$supervisor_table_id],
            $asesor_ids_equipo,
            [$tareas_desde, $tareas_hasta]
        );
        if ($tareas_asesor_filtro !== '') {
            $sqlP .= " AND t.asesor_id = ?";
            $paramsP[] = $tareas_asesor_filtro;
        }
        $sqlP .= " ORDER BY t.fecha_programada ASC, t.hora_programada ASC LIMIT 300";

        $stP = $pdo->prepare($sqlP);
        $stP->execute($paramsP);
        $tareas_programadas = $stP->fetchAll();
    } catch (PDOException $e) {
        // Fallback silencioso si la tabla aún no tiene las columnas nuevas
        $tareas_completadas = [];
        $tareas_incompletas = [];
        $tareas_programadas = [];
    }
}

// Alertas pendientes (para badge del sidebar)
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id=? AND vista_supervisor=0');
        $st->execute([$supervisor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();
    } catch (PDOException $e) {
        $alertas_pendientes = 0;
    }
}

// Helper para nombre legible del tipo de tarea
function metas_tipo_tarea_label($tipo) {
    switch ($tipo) {
        case 'nueva_cita_campo':      return 'Nueva cita en campo';
        case 'nueva_cita_oficina':    return 'Nueva cita en oficina';
        case 'documentos_pendientes': return 'Recolectar documentación';
        case 'levantamiento':         return 'Levantamiento';
        default: return ucfirst(str_replace('_', ' ', (string)$tipo));
    }
}

// Helper para etiqueta + clase visual de estado.
// Para el supervisor, una tarea pospuesta cuenta como INCOMPLETA — aunque
// el asesor la vea como "pospuesta" desde la app, aquí se muestra así para
// que el supervisor vea claramente que no se hizo el día original.
function metas_estado_tarea_badge($estado, $seleccionada_dia, $fecha_programada, $pospuesta_de_dia = null) {
    $hoy = date('Y-m-d');
    if ($estado === 'completada') return ['Completada', 'est-completado'];
    if ($estado === 'cancelada')  return ['Cancelada',  'est-no_cumplido'];

    // Si la tarea tiene registro de haber sido pospuesta → INCOMPLETA
    if (!empty($pospuesta_de_dia)) {
        return ['Incompleta', 'est-no_cumplido'];
    }

    // Caso legacy (sin pospuesta_de_dia registrado): la tarea está en
    // proceso pero con seleccionada_dia distinta a hoy → INCOMPLETA
    if ($estado === 'en_proceso' && $seleccionada_dia && $seleccionada_dia !== $hoy) {
        return ['Incompleta', 'est-no_cumplido'];
    }
    if ($estado === 'en_proceso') return ['En proceso', 'est-pendiente'];
    if ($estado === 'postergada') return ['Postergada', 'est-pendiente'];
    if ($estado === 'programada') return ['Programada', 'est-pendiente'];
    if ($estado === 'pendiente')  return ['Pendiente',  'est-pendiente'];
    return [$estado ?: '—', 'est-pendiente'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metas del Equipo — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="supervisor_layout.css">
</head>
<body>

<?php $navTitle = 'Metas del Equipo'; $navIcon = 'fas fa-bullseye'; ?>
<?php require_once '_sidebar_supervisor.php'; ?>

<div class="main-content">
  <div class="content-area">

        <?php if ($flash): ?>
            <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
        <?php endif; ?>

        <!-- FORMULARIO ASIGNAR META -->
        <div class="card-block" style="max-width:900px;margin:0 auto 28px;">
            <h3 style="font-size:1.3rem;font-weight:800;margin-bottom:18px;color:#123a6d;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-plus-circle" style="color:#10b981;"></i> Asignar Meta Diaria a un Asesor
            </h3>
            <form method="post" action="metas.php">
                <div class="form-grid" style="margin-bottom:18px;">
                    <div>
                        <label>Asesor</label>
                        <select name="asesor_id" required>
                            <option value="">-- Selecciona --</option>
                            <?php foreach ($asesores as $a): ?>
                                <option value="<?= htmlspecialchars($a['id']) ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>" required>
                    </div>
                    <div>
                        <label><i class="fas fa-poll"></i> Encuestas</label>
                        <input type="number" name="meta_encuestas" min="0" value="0">
                    </div>
                    <div>
                        <label><i class="fas fa-user-plus"></i> Clientes nuevos</label>
                        <input type="number" name="meta_clientes_nuevos" min="0" value="0">
                    </div>
                    <div>
                        <label><i class="fas fa-hand-holding-usd"></i> Créditos</label>
                        <input type="number" name="meta_creditos" min="0" value="0">
                    </div>
                    <div>
                        <label><i class="fas fa-piggy-bank"></i> Cuentas ahorros</label>
                        <input type="number" name="meta_cuenta_ahorros" min="0" value="0">
                    </div>
                    <div>
                        <label><i class="fas fa-wallet"></i> Cuentas corrientes</label>
                        <input type="number" name="meta_cuenta_corriente" min="0" value="0">
                    </div>
                    <div>
                        <label><i class="fas fa-chart-line"></i> Inversiones</label>
                        <input type="number" name="meta_inversiones" min="0" value="0">
                    </div>
                </div>
                <div style="margin-bottom:14px;">
                    <label>Observaciones (opcional)</label>
                    <textarea name="observaciones" rows="2" placeholder="Notas para el asesor..."></textarea>
                </div>
                <div style="display:flex;justify-content:center;">
                    <button type="submit" class="btn-save" style="min-width:160px;">
                        <i class="fas fa-save"></i> Guardar Meta
                    </button>
                </div>
            </form>
        </div>


        <!-- METAS ACTUALES -->
        <div class="card-block" style="max-width:1100px;margin:0 auto 28px;">
            <h3 style="font-size:1.2rem;font-weight:800;margin-bottom:18px;color:#123a6d;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-list-check" style="color:#3b82f6;"></i> Metas Asignadas
            </h3>
            <form method="get" class="meta-filter-form" style="display:flex;justify-content:flex-end;align-items:end;gap:16px;padding:0 0 18px 0;">
                <div class="filter-group" style="display:flex;flex-direction:column;min-width:200px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.9rem;color:#475569;font-weight:600;">
                        <i class="fas fa-calendar"></i> Ver fecha
                    </label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>" onchange="this.form.submit()" style="padding:10px 12px;border:1px solid #d2d6dc;border-radius:8px;background:#ffffff;font-size:0.95rem;">
                </div>
            </form>
            <?php if (empty($metas_hoy)): ?>
                <div class="empty-state" style="text-align:center;padding:40px 20px;color:var(--brand-gray);">
                    <i class="fas fa-inbox" style="font-size:3rem;margin-bottom:16px;opacity:0.5;"></i>
                    <p style="margin:0;font-size:1rem;">No hay metas asignadas para esta fecha.</p>
                </div>
            <?php else: ?>
                <div class="table-container" style="overflow-x:auto;border-radius:12px;border:1px solid #e2e8f0;background:#ffffff;">
                    <table style="margin:0;border-radius:12px;min-width:680px;width:100%;">
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th>Encuestas</th>
                                <th>Clientes</th>
                                <th>Créditos</th>
                                <th>C. Ahorro</th>
                                <th>C. Corriente</th>
                                <th>Inversiones</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($metas_hoy as $m): ?>
                            <?php
                            $estClass = 'est-' . $m['estado'];
                            $estLabel = [
                                'pendiente' => 'Pendiente',
                                'completado' => 'Completado',
                                'no_cumplido' => 'No cumplido'
                            ][$m['estado']] ?? $m['estado'];
                            $fmt = function($av, $meta) {
                                $av = (int)$av; $meta = (int)$meta;
                                $ok = $meta > 0 && $av >= $meta;
                                $color = $ok ? '#10b981' : ($meta == 0 ? '#6b7280' : '#d97706');
                                return "<span style='color:$color'><b>$av</b>/$meta</span>";
                            };
                            ?>
                            <tr>
                                <td><b><?= htmlspecialchars($m['asesor_nombre']) ?></b></td>
                                <td class="avance"><?= $fmt($m['avance_encuestas'], $m['meta_encuestas']) ?></td>
                                <td class="avance"><?= $fmt($m['avance_clientes_nuevos'], $m['meta_clientes_nuevos']) ?></td>
                                <td class="avance"><?= $fmt($m['avance_creditos'], $m['meta_creditos']) ?></td>
                                <td class="avance"><?= $fmt($m['avance_cuenta_ahorros'], $m['meta_cuenta_ahorros']) ?></td>
                                <td class="avance"><?= $fmt($m['avance_cuenta_corriente'], $m['meta_cuenta_corriente']) ?></td>
                                <td class="avance"><?= $fmt($m['avance_inversiones'], $m['meta_inversiones']) ?></td>
                                <td><span class="est-badge <?= $estClass ?>"><?= $estLabel ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── TAREAS DEL EQUIPO (seleccionadas por los asesores) ── -->
        <div class="card-block">
            <h3><i class="fas fa-tasks" style="color:#8b5cf6;"></i> Tareas del Equipo</h3>

            <form method="get" class="filter-bar">
                <!-- conservar filtro de fecha de metas -->
                <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>">
                <div style="min-width:200px;">
                    <label>Asesor</label>
                    <select name="t_asesor" onchange="this.form.submit()">
                        <option value="">— Todos mis asesores —</option>
                        <?php foreach ($asesores as $a): ?>
                            <option value="<?= htmlspecialchars($a['id']) ?>" <?= $tareas_asesor_filtro === (string)$a['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Desde</label>
                    <input type="date" name="t_desde" value="<?= htmlspecialchars($tareas_desde) ?>" onchange="this.form.submit()">
                </div>
                <div>
                    <label>Hasta</label>
                    <input type="date" name="t_hasta" value="<?= htmlspecialchars($tareas_hasta) ?>" onchange="this.form.submit()">
                </div>
                <div>
                    <button type="submit" class="btn-save" style="margin-top:0;padding:10px 18px;">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                </div>
            </form>

            <!-- Tareas Incompletas / Pospuestas -->
            <h4 style="margin:18px 0 10px;font-size:15px;font-weight:800;color:var(--brand-navy-deep);">
                <i class="fas fa-hourglass-half" style="color:#d97706;"></i>
                Tareas incompletas / pospuestas
                <span style="font-weight:500;color:var(--brand-gray);font-size:12px;">
                    (<?= count($tareas_incompletas) ?>)
                </span>
            </h4>
            <?php if (empty($tareas_incompletas)): ?>
                <p style="color:var(--brand-gray);padding:14px;text-align:center;">
                    <i class="fas fa-check-double"></i>
                    No hay tareas incompletas en este rango.
                </p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Día original</th>
                                <th>Reprogramada</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tareas_incompletas as $t): ?>
                            <?php
                            [$estLabel, $estClass] = metas_estado_tarea_badge(
                                $t['estado'] ?? '',
                                $t['seleccionada_dia'] ?? '',
                                $t['fecha_programada'] ?? '',
                                $t['pospuesta_de_dia'] ?? null
                            );
                            $tipoTxt    = metas_tipo_tarea_label($t['tipo_tarea'] ?? '');
                            $fechaProg  = trim(($t['fecha_programada'] ?? '') . ' ' . ($t['hora_programada'] ?? ''));
                            $selDia     = $t['seleccionada_dia']  ?? '';
                            $pospDia    = $t['pospuesta_de_dia']  ?? '';

                            // Día original: el registro explícito si existe,
                            // si no, el seleccionada_dia o el fecha_programada.
                            $diaOriginal = $pospDia !== '' ? $pospDia
                                         : ($selDia !== '' ? $selDia
                                         : ($t['fecha_programada'] ?? ''));

                            // Reprogramada: si está pospuesta, mostramos la
                            // fecha nueva (fecha_programada o seleccionada_dia).
                            $reprog = '';
                            if ($pospDia !== '') {
                                $reprog = $t['fecha_programada'] ?? '';
                                if ($reprog === '' || $reprog === $pospDia) {
                                    $reprog = $selDia;
                                }
                            }
                            ?>
                            <tr>
                                <td><b><?= htmlspecialchars($t['asesor_nombre'] ?? '') ?></b></td>
                                <td>
                                    <?= htmlspecialchars($t['cliente_nombre'] ?? '—') ?>
                                    <?php if (!empty($t['cliente_ciudad'])): ?>
                                        <div style="color:var(--brand-gray);font-size:11px;">
                                            <?= htmlspecialchars($t['cliente_ciudad']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($tipoTxt) ?></td>
                                <td>
                                    <?= htmlspecialchars($diaOriginal ?: '—') ?>
                                    <?php if (!empty($t['hora_programada']) && $pospDia === ''): ?>
                                        <div style="color:var(--brand-gray);font-size:11px;">
                                            <?= htmlspecialchars($t['hora_programada']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($reprog !== ''): ?>
                                        <span style="color:#d97706;font-weight:700;">
                                            <i class="fas fa-arrow-right" style="font-size:10px;"></i>
                                            <?= htmlspecialchars($reprog) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--brand-gray);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="est-badge <?= $estClass ?>"><?= htmlspecialchars($estLabel) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Tareas Programadas -->
            <h4 style="margin:22px 0 10px;font-size:15px;font-weight:800;color:var(--brand-navy-deep);">
                <i class="fas fa-calendar-alt" style="color:#3b82f6;"></i>
                Tareas programadas
                <span style="font-weight:500;color:var(--brand-gray);font-size:12px;">
                    (<?= count($tareas_programadas) ?>)
                </span>
            </h4>
            <?php if (empty($tareas_programadas)): ?>
                <p style="color:var(--brand-gray);padding:14px;text-align:center;">
                    <i class="fas fa-inbox"></i>
                    No hay tareas programadas en este rango.
                </p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tareas_programadas as $t): ?>
                            <?php
                            $tipoTxt   = metas_tipo_tarea_label($t['tipo_tarea'] ?? '');
                            $fechaProg = $t['fecha_programada'] ?? '';
                            $horaProg  = $t['hora_programada']  ?? '';
                            $fuePosp   = !empty($t['pospuesta_de_dia']);
                            ?>
                            <tr>
                                <td><b><?= htmlspecialchars($t['asesor_nombre'] ?? '') ?></b></td>
                                <td>
                                    <?= htmlspecialchars($t['cliente_nombre'] ?? '—') ?>
                                    <?php if (!empty($t['cliente_ciudad'])): ?>
                                        <div style="color:var(--brand-gray);font-size:11px;">
                                            <?= htmlspecialchars($t['cliente_ciudad']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($tipoTxt) ?></td>
                                <td>
                                    <?= htmlspecialchars($fechaProg ?: '—') ?>
                                    <?php if ($fuePosp): ?>
                                        <div style="color:#d97706;font-size:11px;font-weight:700;">
                                            <i class="fas fa-history" style="font-size:9px;"></i>
                                            Reprogramada desde <?= htmlspecialchars($t['pospuesta_de_dia']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($horaProg ?: '—') ?></td>
                                <td><span class="est-badge est-pendiente">Programada</span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Tareas Completadas -->
            <h4 style="margin:22px 0 10px;font-size:15px;font-weight:800;color:var(--brand-navy-deep);">
                <i class="fas fa-check-circle" style="color:#10b981;"></i>
                Tareas completadas
                <span style="font-weight:500;color:var(--brand-gray);font-size:12px;">
                    (<?= count($tareas_completadas) ?>)
                </span>
            </h4>
            <?php if (empty($tareas_completadas)): ?>
                <p style="color:var(--brand-gray);padding:14px;text-align:center;">
                    <i class="fas fa-inbox"></i>
                    No hay tareas completadas en este rango.
                </p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Programada</th>
                                <th>Realizada</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tareas_completadas as $t): ?>
                            <?php
                            $tipoTxt = metas_tipo_tarea_label($t['tipo_tarea'] ?? '');
                            $fechaProg = trim(($t['fecha_programada'] ?? '') . ' ' . ($t['hora_programada'] ?? ''));
                            $fechaReal = trim(($t['fecha_realizada']  ?? '') . ' ' . ($t['hora_realizada']  ?? ''));
                            ?>
                            <tr>
                                <td><b><?= htmlspecialchars($t['asesor_nombre'] ?? '') ?></b></td>
                                <td>
                                    <?= htmlspecialchars($t['cliente_nombre'] ?? '—') ?>
                                    <?php if (!empty($t['cliente_ciudad'])): ?>
                                        <div style="color:var(--brand-gray);font-size:11px;">
                                            <?= htmlspecialchars($t['cliente_ciudad']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($tipoTxt) ?></td>
                                <td><?= htmlspecialchars(trim($fechaProg)) ?: '—' ?></td>
                                <td><?= htmlspecialchars(trim($fechaReal)) ?: '—' ?></td>
                                <td><span class="est-badge est-completado">Completada</span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>
    </div> <!-- .content-area -->
</div> <!-- .main-content -->
</body>
</html>

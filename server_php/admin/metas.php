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
    $m_vis     = (int)($_POST['meta_visitas'] ?? 0);
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

            // Auto-crear columnas si faltan (para evitar errores SQLSTATE[42S22])
            try {
                $cols_exist = $pdo->query("SHOW COLUMNS FROM meta_asesor_diaria")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('meta_visitas', $cols_exist)) {
                    $pdo->exec("ALTER TABLE meta_asesor_diaria ADD COLUMN meta_visitas INT DEFAULT 0 AFTER meta_inversiones");
                }
                
                $cols_asesor = $pdo->query("SHOW COLUMNS FROM asesor")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('meta_visitas', $cols_asesor)) {
                    $pdo->exec("ALTER TABLE asesor ADD COLUMN meta_visitas INT DEFAULT 0");
                }
            } catch (PDOException $e) {}

            // Algunas instalaciones agregaron meta_asesor_diaria.supervisor_id como NOT NULL.
            // Aun así, el filtrado se mantiene por asesor.supervisor_id.

            $cols = [
                'asesor_id', 'fecha',
                'meta_encuestas', 'meta_clientes_nuevos', 'meta_creditos',
                'meta_cuenta_ahorros', 'meta_cuenta_corriente', 'meta_inversiones',
                'meta_visitas',
                'observaciones'
            ];
            $vals = [
                $asesor_id, $fecha,
                $m_enc, $m_cli, $m_cre, $m_cah, $m_cco, $m_inv,
                $m_vis, $obs
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
                      meta_visitas = VALUES(meta_visitas),
                      observaciones = VALUES(observaciones)";

            if ($has_supervisor_id) {
                $sql .= ", supervisor_id = VALUES(supervisor_id)";
            }
            if ($has_actualizado_at) {
                $sql .= ", actualizado_at = CURRENT_TIMESTAMP";
            }

            $st = $pdo->prepare($sql);
            $st->execute($vals);

            // También actualizar las metas base en la tabla asesor (como solicitó el usuario)
            try {
                $stBase = $pdo->prepare("UPDATE asesor SET 
                    meta_encuestas = :m1, meta_clientes_nuevos = :m2, meta_creditos = :m3,
                    meta_cuenta_ahorros = :m4, meta_cuenta_corriente = :m5, meta_inversiones = :m6,
                    meta_visitas = :m7
                    WHERE id = :aid");
                $stBase->execute([
                    ':m1' => $m_enc, ':m2' => $m_cli, ':m3' => $m_cre,
                    ':m4' => $m_cah, ':m5' => $m_cco, ':m6' => $m_inv,
                    ':m7' => $m_vis,
                    ':aid' => $asesor_id
                ]);
            } catch (PDOException $e_base) {
                // Si las columnas no existen en asesor, ignorar o manejar silenciosamente
            }

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
                   v.avance_cuenta_ahorros, v.avance_cuenta_corriente, v.avance_inversiones,
                   v.avance_visitas
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
                            0 AS avance_cuenta_ahorros, 0 AS avance_cuenta_corriente, 0 AS avance_inversiones,
                            0 AS avance_visitas
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
                ['meta_visitas','avance_visitas'],
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
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
</head>
<body>

<?php $navTitle = ''; $navIcon = ''; $navSubtitle = ''; ?>
<?php require_once '_sidebar_supervisor.php'; ?>

<?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

        <!-- WELCOME BANNER -->
        <div class="welcome-card mb-4">
            <div>
                <h1>Metas y Seguimiento</h1>
                <p>Gestiona los objetivos diarios de tu equipo y monitorea su progreso en tiempo real.</p>
            </div>
            <div class="welcome-meta">
                <div class="welcome-meta-item">
                    <div class="wm-num"><?= count($metas_hoy) ?></div>
                    <div class="wm-lbl">Metas Hoy</div>
                </div>
                <div class="welcome-meta-item">
                    <div class="wm-num"><?= count($tareas_incompletas) ?></div>
                    <div class="wm-lbl">Pendientes</div>
                </div>
            </div>
        </div>

        <!-- FORMULARIO ASIGNAR META -->
        <div class="section-card mb-4">
            <div class="section-header">
                <h5><i class="fas fa-plus-circle text-success"></i> Asignar Meta Diaria</h5>
                <span class="badge-premium badge-navy-soft">Nueva Asignación</span>
            </div>
            <div class="section-body">
                <form method="post" action="metas.php">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="form-section h-100">
                                <h4><i class="fas fa-user-tie"></i> Información General</h4>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small text-muted">Asesor a Cargo</label>
                                        <select name="asesor_id" class="form-select form-select-lg shadow-sm" required style="border-radius:12px; border-color:#e2e8f0;">
                                            <option value="">-- Selecciona --</option>
                                            <?php foreach ($asesores as $a): ?>
                                                <option value="<?= htmlspecialchars($a['id']) ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold small text-muted">Fecha de Aplicación</label>
                                        <input type="date" name="fecha" class="form-control form-control-lg shadow-sm" value="<?= htmlspecialchars($fecha_filtro) ?>" required style="border-radius:12px; border-color:#e2e8f0;">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-section h-100">
                                <h4><i class="fas fa-bullseye"></i> Objetivos del Día</h4>
                                <div class="row g-3">
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-poll me-1"></i> Encuestas</label>
                                        <input type="number" name="meta_encuestas" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-user-plus me-1"></i> Clientes</label>
                                        <input type="number" name="meta_clientes_nuevos" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-hand-holding-usd me-1"></i> Créditos</label>
                                        <input type="number" name="meta_creditos" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-piggy-bank me-1"></i> Ahorros</label>
                                        <input type="number" name="meta_cuenta_ahorros" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-money-check-alt me-1"></i> C. Corriente</label>
                                        <input type="number" name="meta_cuenta_corriente" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-chart-line me-1"></i> Inversiones</label>
                                        <input type="number" name="meta_inversiones" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-bold text-muted"><i class="fas fa-walking me-1"></i> Visitas</label>
                                        <input type="number" name="meta_visitas" class="form-control shadow-sm" min="0" value="0" style="border-radius:10px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group px-2">
                                <label class="form-label fw-bold small text-muted">Observaciones y Notas para el Asesor</label>
                                <textarea name="observaciones" class="form-control shadow-sm" rows="2" placeholder="Instrucciones específicas..." style="border-radius:12px; border-color:#e2e8f0;"></textarea>
                            </div>
                        </div>
                        <div class="col-12 text-center pt-2">
                            <button type="submit" class="btn-save px-5 shadow" style="height:50px; border-radius:15px; font-weight:800; letter-spacing:0.5px;">
                                <i class="fas fa-save me-2"></i> ESTABLECER METAS
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>


        <!-- METAS ACTUALES -->
        <div class="section-card mb-4">
            <div class="section-header">
                <h5><i class="fas fa-list-check text-primary"></i> Estado de Metas del Equipo</h5>
                <form method="get" class="d-flex align-items-center gap-3">
                    <label class="small fw-bold text-muted text-nowrap m-0"><i class="fas fa-calendar-day"></i> Consultar Fecha:</label>
                    <input type="date" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>" onchange="this.form.submit()" class="form-control form-control-sm shadow-sm" style="width:auto; border-radius:8px;">
                </form>
            </div>
            <div class="section-body p-0">

            <?php if (empty($metas_hoy)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay metas asignadas para esta fecha.</p>
                </div>
            <?php else: ?>
                <div class="table-premium-container">
                    <table class="table-premium">
                        <thead>
                            <tr>
                                <th>Asesor</th>
                                <th class="text-center">Encuestas</th>
                                <th class="text-center">Clientes</th>
                                <th class="text-center">Créditos</th>
                                <th class="text-center">Ahorros</th>
                                <th class="text-center">C. Corriente</th>
                                <th class="text-center">Inversiones</th>
                                <th class="text-center">Visitas</th>
                                <th class="text-center">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($metas_hoy as $m): ?>
                            <?php
                            $estClass = 'badge-' . ($m['estado'] === 'completado' ? 'success' : ($m['estado'] === 'no_cumplido' ? 'danger' : 'warning')) . '-soft';
                            $estLabel = ['pendiente'=>'Pendiente','completado'=>'Completado','no_cumplido'=>'No cumplido'][$m['estado']] ?? $m['estado'];
                            
                            $fmtProgress = function($av, $meta) {
                                $av = (int)$av; $meta = (int)$meta;
                                if ($meta <= 0) return '<span class="text-muted small">—</span>';
                                $pct = min(100, round($av * 100 / $meta));
                                $color = $av >= $meta ? 'var(--brand-success)' : 'var(--brand-warning)';
                                return '
                                    <div class="d-flex flex-column align-items-center" style="min-width:80px;">
                                        <div class="fw-bold mb-1" style="font-size:12px; color:'.$color.'">'.$av.'/'.$meta.'</div>
                                        <div class="progress-container" style="height:4px;">
                                            <div class="progress-bar-fill" style="width:'.$pct.'%; background:'.$color.'"></div>
                                        </div>
                                    </div>';
                            };
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-navy"><?= htmlspecialchars($m['asesor_nombre']) ?></div>
                                    <small class="text-muted" style="font-size:10px;">ID: <?= $m['asesor_id'] ?></small>
                                </td>
                                <td class="text-center"><?= $fmtProgress($m['avance_encuestas'], $m['meta_encuestas']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_clientes_nuevos'], $m['meta_clientes_nuevos']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_creditos'], $m['meta_creditos']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_cuenta_ahorros'], $m['meta_cuenta_ahorros']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_cuenta_corriente'], $m['meta_cuenta_corriente']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_inversiones'], $m['meta_inversiones']) ?></td>
                                <td class="text-center"><?= $fmtProgress($m['avance_visitas'], $m['meta_visitas']) ?></td>
                                <td class="text-center">
                                    <span class="badge-premium <?= $estClass ?>"><?= $estLabel ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- ── TAREAS DEL EQUIPO ── -->
        <div class="section-card">
            <div class="section-header">
                <h5><i class="fas fa-tasks text-purple"></i> Gestión de Tareas del Equipo</h5>
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="fecha" value="<?= htmlspecialchars($fecha_filtro) ?>">
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Filtrar Asesor</label>
                        <select name="t_asesor" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                            <option value="">— Todos —</option>
                            <?php foreach ($asesores as $a): ?>
                                <option value="<?= htmlspecialchars($a['id']) ?>" <?= $tareas_asesor_filtro === (string)$a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Desde</label>
                        <input type="date" name="t_desde" value="<?= htmlspecialchars($tareas_desde) ?>" class="form-control form-control-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Hasta</label>
                        <input type="date" name="t_hasta" value="<?= htmlspecialchars($tareas_hasta) ?>" class="form-control form-control-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                    </div>
                </form>
            </div>
            <div class="section-body">

            <!-- Tareas Incompletas / Pospuestas -->
            <div class="mt-4">
                <h5 class="fw-800 text-navy mb-3 d-flex align-items-center gap-2">
                    <i class="fas fa-hourglass-half text-warning"></i> 
                    Incompletas / Pospuestas
                    <span class="badge-premium badge-warning-soft ms-2"><?= count($tareas_incompletas) ?></span>
                </h5>
                
                <?php if (empty($tareas_incompletas)): ?>
                    <div class="p-4 text-center border rounded-4 bg-light opacity-50">
                        <i class="fas fa-check-double mb-2 d-block"></i> No hay pendientes en este rango
                    </div>
                <?php else: ?>
                    <div class="table-premium-container">
                        <table class="table-premium">
                            <thead>
                                <tr>
                                    <th>Asesor / Cliente</th>
                                    <th>Tarea</th>
                                    <th>Día Original</th>
                                    <th>Nueva Fecha</th>
                                    <th class="text-end">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tareas_incompletas as $t): ?>
                                <?php
                                [$estLabel, $estClass] = metas_estado_tarea_badge(
                                    $t['estado'] ?? '', $t['seleccionada_dia'] ?? '',
                                    $t['fecha_programada'] ?? '', $t['pospuesta_de_dia'] ?? null
                                );
                                $tipoTxt = metas_tipo_tarea_label($t['tipo_tarea'] ?? '');
                                $diaOriginal = $t['pospuesta_de_dia'] ?: ($t['seleccionada_dia'] ?: ($t['fecha_programada'] ?? ''));
                                
                                $reprog = '';
                                if ($t['pospuesta_de_dia']) {
                                    $reprog = $t['fecha_programada'] ?: $t['seleccionada_dia'];
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($t['asesor_nombre']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin cliente') ?></div>
                                    </td>
                                    <td><span class="badge-premium badge-info-soft"><?= htmlspecialchars($tipoTxt) ?></span></td>
                                    <td><?= date('d M', strtotime($diaOriginal)) ?></td>
                                    <td>
                                        <?php if ($reprog): ?>
                                            <span class="text-warning fw-bold">
                                                <i class="fas fa-arrow-right small"></i> <?= date('d M', strtotime($reprog)) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge-premium badge-danger-soft"><?= $estLabel ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tareas Programadas / Completadas Grid -->
            <div class="row g-4 mt-4">
                <div class="col-lg-6">
                    <h5 class="fw-800 text-navy mb-3 d-flex align-items-center gap-2">
                        <i class="fas fa-calendar-alt text-primary"></i> 
                        Programadas
                        <span class="badge-premium badge-info-soft ms-2"><?= count($tareas_programadas) ?></span>
                    </h5>
                    <div class="p-0 border rounded-4 bg-white overflow-hidden shadow-sm" style="max-height: 500px; overflow-y: auto;">
                        <?php if (empty($tareas_programadas)): ?>
                            <div class="p-5 text-center text-muted opacity-50 small">No hay tareas programadas</div>
                        <?php else: ?>
                            <?php foreach ($tareas_programadas as $t): 
                                $tipoTxt = metas_tipo_tarea_label($t['tipo_tarea']);
                            ?>
                            <div class="act-item">
                                <div class="act-dot dot-blue"><i class="fas fa-clock"></i></div>
                                <div class="act-body">
                                    <div class="act-title"><?= htmlspecialchars($t['asesor_nombre']) ?></div>
                                    <div class="act-meta"><?= htmlspecialchars($tipoTxt) ?> · <?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin cliente') ?></div>
                                </div>
                                <div class="act-date text-end">
                                    <div class="fw-bold"><?= date('d/m', strtotime($t['fecha_programada'])) ?></div>
                                    <div class="small opacity-75"><?= $t['hora_programada'] ?: '--:--' ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <h5 class="fw-800 text-navy mb-3 d-flex align-items-center gap-2">
                        <i class="fas fa-check-circle text-success"></i> 
                        Completadas
                        <span class="badge-premium badge-success-soft ms-2"><?= count($tareas_completadas) ?></span>
                    </h5>
                    <div class="p-0 border rounded-4 bg-white overflow-hidden shadow-sm" style="max-height: 500px; overflow-y: auto;">
                        <?php if (empty($tareas_completadas)): ?>
                            <div class="p-5 text-center text-muted opacity-50 small">No hay tareas completadas</div>
                        <?php else: ?>
                            <?php foreach ($tareas_completadas as $t): 
                                $tipoTxt = metas_tipo_tarea_label($t['tipo_tarea']);
                            ?>
                            <div class="act-item">
                                <div class="act-dot dot-ok"><i class="fas fa-check"></i></div>
                                <div class="act-body">
                                    <div class="act-title"><?= htmlspecialchars($t['asesor_nombre']) ?></div>
                                    <div class="act-meta"><?= htmlspecialchars($tipoTxt) ?> · <?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin cliente') ?></div>
                                </div>
                                <div class="act-date text-end">
                                    <div class="fw-bold text-success"><?= date('d/m', strtotime($t['fecha_realizada'])) ?></div>
                                    <div class="small opacity-75"><?= $t['hora_realizada'] ?: '--:--' ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</body>
</html>

<?php
// ============================================================
// admin/encuestas.php — Encuestas del Equipo (Supervisor / Gerente)
// ------------------------------------------------------------
// Reemplaza el listado genérico de "actividades" por una vista
// dedicada a las ENCUESTAS reales que llenan los asesores desde
// el celular: tanto las que nacen de una tarea de la agenda
// (NuevaEncuestaScreen -> tabla encuesta_comercial) como los
// Levantamientos de Empresa (LevantarEmpresaScreen -> tabla
// encuesta_negocio). Se puede filtrar por fecha, por asesor, por
// tipo y por estado (Completada / Programada / Incompleta), y
// cada fila indica si la encuesta ya llegó al servidor ("Subida")
// o si el celular todavía la tiene pendiente de sincronizar.
//
// El botón "Ver detalle" abre ver_cliente.php, que ya muestra la
// encuesta pregunta por pregunta exactamente con los mismos datos
// capturados en la app móvil (interés en productos, situación
// financiera, activos, deudas, etc.).
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once 'db_admin.php';   // PDO ($pdo)

$is_admin_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!isset($_SESSION['supervisor_logged_in']) && !$is_admin_gerente) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_usuario_id = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre     = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol        = $_SESSION['supervisor_rol'] ?? 'Supervisor';

// Resolver supervisor.id
$supervisor_table_id = null;
if ($supervisor_usuario_id) {
    try {
        $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$supervisor_usuario_id]);
        $supervisor_table_id = $st->fetchColumn() ?: null;
    } catch (PDOException $e) {}
}

$currentPage = 'encuestas';
$alertas_pendientes = 0;
if ($supervisor_table_id) {
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id=? AND vista_supervisor=0');
        $st->execute([$supervisor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();
    } catch (PDOException $e) { $alertas_pendientes = 0; }
}

// ── Cargar asesores del equipo (para el filtro) ──────────────
$asesores = [];
if ($supervisor_table_id) {
    $st = $pdo->prepare('SELECT a.id, u.nombre FROM asesor a
                         JOIN usuario u ON u.id = a.usuario_id
                         WHERE a.supervisor_id = ? AND u.activo = 1
                         ORDER BY u.nombre');
    $st->execute([$supervisor_table_id]);
    $asesores = $st->fetchAll();
} elseif ($is_admin_gerente) {
    try {
        $st = $pdo->query('SELECT a.id, u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE u.activo = 1 ORDER BY u.nombre');
        $asesores = $st->fetchAll();
    } catch (Throwable $_) {}
}
$asesor_ids_equipo = array_map(fn($a) => (string)$a['id'], $asesores);

// ── Filtros ───────────────────────────────────────────────────
$f_desde  = trim($_GET['desde'] ?? '');
$f_hasta  = trim($_GET['hasta'] ?? '');
$f_estado = trim($_GET['estado'] ?? '');   // '' | completada | programada | incompleta
$f_tipo   = trim($_GET['tipo'] ?? '');     // '' | encuesta | levantamiento
$f_asesor = trim($_GET['asesor'] ?? '');
$f_busca  = trim($_GET['busca'] ?? '');    // nombre o cédula del cliente

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_desde)) {
    $f_desde = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_hasta)) {
    $f_hasta = date('Y-m-d');
}
if ($f_desde > $f_hasta) {
    [$f_desde, $f_hasta] = [$f_hasta, $f_desde];
}
if (!in_array($f_estado, ['completada', 'programada', 'incompleta', 'incumplida'], true)) {
    $f_estado = '';
}
if (!in_array($f_tipo, ['encuesta', 'levantamiento'], true)) {
    $f_tipo = '';
}
if ($f_asesor !== '' && !in_array($f_asesor, $asesor_ids_equipo, true)) {
    $f_asesor = '';
}

// ── Helpers de presentación ──────────────────────────────────
function enc_tipo_label(string $tipo): string {
    switch ($tipo) {
        case 'levantamiento':         return 'Levantamiento de Empresa';
        case 'evaluacion':            return 'Evaluación';
        case 'prospecto_nuevo':       return 'Prospecto Nuevo';
        case 'visita_frio':
        case 'frio':                  return 'Visita en Frío';
        case 'nueva_cita_inversion':  return 'Nueva cita inversión';
        case 'nueva_cita_campo':      return 'Nueva cita en campo';
        case 'nueva_cita_oficina':    return 'Nueva cita en oficina';
        case 'post_venta':            return 'Post venta';
        case 'represtamo':            return 'Represtamo';
        case 'seguimiento':           return 'Seguimiento';
        case 'documentos_pendientes': return 'Recolectar documentación';
        default:                      return ucfirst(str_replace('_', ' ', $tipo));
    }
}
function enc_bucket_estado(array $t): string {
    $estado = $t['estado'] ?? '';
    if ($estado === 'completada') return 'completada';
    if ($estado === 'cancelada')  return 'cancelada';
    if ($estado === 'incumplida') return 'incumplida';

    $hoy = date('Y-m-d');
    $horaActual = (int)date('H');
    $fechaProg = $t['fecha_programada'] ?? null;

    if (!empty($t['pospuesta_de_dia'])) return 'incompleta';
    if ($fechaProg && ($fechaProg < $hoy || ($fechaProg === $hoy && $horaActual >= 18))) return 'incompleta';
    return 'programada';
}
function enc_bucket_badge(string $bucket): array {
    switch ($bucket) {
        case 'completada': return ['Completada', 'badge-success-soft'];
        case 'cancelada':  return ['Cancelada',  'badge-navy-soft'];
        case 'incompleta': return ['Incompleta', 'badge-danger-soft'];
        case 'incumplida': return ['Incumplida', 'badge-dark-soft'];
        default:           return ['Programada', 'badge-info-soft'];
    }
}

// Asegurar columnas nuevas de posposición (por si el mobile aún no las
// creó en esta BD — mismo patrón defensivo usado en el resto del panel).
foreach ([
    'posposiciones' => "ADD COLUMN posposiciones INT NOT NULL DEFAULT 0",
    'incumplida_at' => "ADD COLUMN incumplida_at DATETIME DEFAULT NULL",
] as $col => $ddl) {
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM tarea LIKE '$col'")->fetchAll();
        if (empty($chk)) {
            $pdo->exec("ALTER TABLE tarea $ddl");
        }
    } catch (Throwable $e) {}
}
try {
    $colEstado = $pdo->query("SHOW COLUMNS FROM tarea LIKE 'estado'")->fetch();
    if ($colEstado && stripos($colEstado['Type'], 'enum') !== false && stripos($colEstado['Type'], "'incumplida'") === false) {
        $pdo->exec("ALTER TABLE tarea MODIFY COLUMN estado
            ENUM('programada','pendiente','postergada','en_proceso','completada','cancelada','incumplida')
            NOT NULL DEFAULT 'programada'");
    }
} catch (Throwable $e) {}

// ── Consulta principal ────────────────────────────────────────
$encuestas = [];
$resumen = ['total' => 0, 'completada' => 0, 'programada' => 0, 'incompleta' => 0, 'cancelada' => 0, 'incumplida' => 0, 'pendiente_sync' => 0];

if (!empty($asesor_ids_equipo)) {
    try {
        $ph = implode(',', array_fill(0, count($asesor_ids_equipo), '?'));
        $sup_where  = $supervisor_table_id ? 'AND a.supervisor_id = ?' : '';
        $sup_params = $supervisor_table_id ? [$supervisor_table_id]    : [];

        $tipo_where = '';
        if ($f_tipo === 'levantamiento') {
            $tipo_where = "AND t.tipo_tarea = 'levantamiento'";
        } elseif ($f_tipo === 'encuesta') {
            $tipo_where = "AND t.tipo_tarea <> 'levantamiento'";
        }

        $asesor_where = '';
        $asesor_params = [];
        if ($f_asesor !== '') {
            $asesor_where = 'AND t.asesor_id = ?';
            $asesor_params = [$f_asesor];
        }

        $busca_where = '';
        $busca_params = [];
        if ($f_busca !== '') {
            $busca_where = 'AND (cp.nombre LIKE ? OR cp.cedula LIKE ?)';
            $busca_params = ['%' . $f_busca . '%', '%' . $f_busca . '%'];
        }

        $sql = "SELECT t.id, t.tipo_tarea, t.estado,
                       t.fecha_programada, t.hora_programada,
                       t.fecha_realizada, t.hora_realizada,
                       t.pospuesta_de_dia,
                       COALESCE(t.posposiciones, 0) AS posposiciones,
                       t.asesor_id,
                       u.nombre AS asesor_nombre,
                       cp.id AS cliente_id,
                       cp.nombre AS cliente_nombre,
                       cp.cedula AS cliente_cedula,
                       cp.ciudad AS cliente_ciudad,
                       cp.nombre_empresa AS cliente_nombre_empresa,
                       (SELECT COUNT(*) FROM encuesta_comercial ec WHERE ec.tarea_id = t.id) AS tiene_encuesta,
                       (SELECT COUNT(*) FROM encuesta_negocio en2 WHERE en2.tarea_id = t.id) AS tiene_levantamiento
                FROM tarea t
                JOIN asesor a ON a.id = t.asesor_id
                JOIN usuario u ON u.id = a.usuario_id
                LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                WHERE t.asesor_id IN ($ph)
                  $sup_where
                  $asesor_where
                  AND t.tipo_tarea NOT LIKE 'ficha_producto_%'
                  $tipo_where
                  $busca_where
                  AND (
                        (t.fecha_realizada IS NOT NULL AND t.fecha_realizada BETWEEN ? AND ?)
                     OR (t.fecha_realizada IS NULL AND t.fecha_programada BETWEEN ? AND ?)
                  )
                ORDER BY COALESCE(t.fecha_realizada, t.fecha_programada) DESC,
                         COALESCE(t.hora_realizada, t.hora_programada) DESC
                LIMIT 500";

        $params = array_merge($asesor_ids_equipo, $sup_params, $asesor_params, $busca_params, [$f_desde, $f_hasta, $f_desde, $f_hasta]);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $filas = $st->fetchAll();

        foreach ($filas as $row) {
            $bucket = enc_bucket_estado($row);
            $resumen['total']++;
            if (isset($resumen[$bucket])) $resumen[$bucket]++;

            $esLevantamiento = ($row['tipo_tarea'] === 'levantamiento');
            $subida = $esLevantamiento ? ((int)$row['tiene_levantamiento'] > 0) : ((int)$row['tiene_encuesta'] > 0);
            if ($bucket === 'completada' && !$subida) $resumen['pendiente_sync']++;

            if ($f_estado !== '' && $bucket !== $f_estado) continue;

            $row['_bucket'] = $bucket;
            $row['_subida'] = $subida;
            $row['_es_levantamiento'] = $esLevantamiento;
            $encuestas[] = $row;
        }
    } catch (PDOException $e) {
        $encuestas = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encuestas del Equipo — Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
    <style>
        .subida-chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;}
        .subida-chip.ok{background:#ecfdf5;color:#059669;}
        .subida-chip.pend{background:#fffbeb;color:#d97706;}
        .subida-chip.na{background:#f3f4f6;color:#9ca3af;}
        .empresa-chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:20px;}
        .empresa-chip.tiene{background:#eef2ff;color:#4338ca;}
        .empresa-chip.sin{background:#f3f4f6;color:#9ca3af;font-weight:600;font-style:italic;}
        .enc-cliente-cell .nombre{font-weight:700;color:#0a2748;}
        .enc-cliente-cell .sub{font-size:11.5px;color:#6b7280;}
        .btn-ver-detalle{background:var(--brand-navy,#123a6d);color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12.5px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:.15s;}
        .btn-ver-detalle:hover{background:#0a2748;color:#fff;}
        .kpi-row{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px;}
        .kpi-card{background:#fff;border:1px solid var(--brand-border,#d7e0ea);border-radius:14px;padding:16px 18px;box-shadow:0 4px 12px rgba(18,58,109,.06);}
        .kpi-card .kpi-num{font-size:24px;font-weight:800;color:#0a2748;}
        .kpi-card .kpi-lbl{font-size:11.5px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-top:2px;}
        .kpi-card.kpi-completada .kpi-num{color:#059669;}
        .kpi-card.kpi-programada .kpi-num{color:#3b82f6;}
        .kpi-card.kpi-incompleta .kpi-num{color:#dc2626;}
        .kpi-card.kpi-incumplida .kpi-num{color:#1f2937;}
        .kpi-card.kpi-pendiente .kpi-num{color:#d97706;}
        .badge-dark-soft{background:#1f2937;color:#fca5a5;}
        .btn-reasignar{background:#fff;color:#1f2937;border:1.5px solid #1f2937;border-radius:8px;padding:7px 14px;font-size:12.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.15s;}
        .btn-reasignar:hover{background:#1f2937;color:#fff;}
        .posp-chip{display:inline-block;margin-top:3px;font-size:10.5px;font-weight:700;color:#991b1b;}
        @media (max-width: 992px){ .kpi-row{grid-template-columns:repeat(2,1fr);} }
    </style>
</head>
<body>

<?php
$navTitle = ''; $navIcon = ''; $navSubtitle = '';
if ($is_admin_gerente) {
    require_once '_sidebar_gerente.php';
} else {
    require_once '_sidebar_supervisor.php';
}
?>

        <!-- BOTÓN VOLVER -->
        <div class="mb-3">
            <a href="metas.php" class="btn btn-sm btn-outline-secondary" style="border-radius:10px; font-weight:700;">
                <i class="fas fa-arrow-left"></i> Volver a Metas y Tareas
            </a>
        </div>

        <!-- WELCOME BANNER -->
        <div class="welcome-card mb-4">
            <div>
                <h1>Encuestas del Equipo</h1>
                <p>Revisa, filtra y confirma cada encuesta y levantamiento de empresa hecho por tus asesores, pregunta por pregunta, tal como se ve en el celular.</p>
            </div>
        </div>

        <!-- KPIs -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-num"><?= $resumen['total'] ?></div>
                <div class="kpi-lbl">Total en el rango</div>
            </div>
            <div class="kpi-card kpi-completada">
                <div class="kpi-num"><?= $resumen['completada'] ?></div>
                <div class="kpi-lbl">Completadas</div>
            </div>
            <div class="kpi-card kpi-programada">
                <div class="kpi-num"><?= $resumen['programada'] ?></div>
                <div class="kpi-lbl">Programadas</div>
            </div>
            <div class="kpi-card kpi-incompleta">
                <div class="kpi-num"><?= $resumen['incompleta'] ?></div>
                <div class="kpi-lbl">Incompletas</div>
            </div>
            <div class="kpi-card kpi-incumplida">
                <div class="kpi-num"><?= $resumen['incumplida'] ?></div>
                <div class="kpi-lbl">Incumplidas (por reasignar)</div>
            </div>
            <div class="kpi-card kpi-pendiente">
                <div class="kpi-num"><?= $resumen['pendiente_sync'] ?></div>
                <div class="kpi-lbl">Pendientes de sincronizar</div>
            </div>
        </div>

        <!-- FILTROS + LISTADO -->
        <div class="section-card">
            <div class="section-header">
                <h5><i class="fas fa-clipboard-list text-purple"></i> Listado de Encuestas</h5>
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Cliente (nombre o cédula)</label>
                        <input type="text" name="busca" value="<?= htmlspecialchars($f_busca) ?>" placeholder="Buscar…" class="form-control form-control-sm shadow-sm" style="border-radius:8px; min-width:180px;">
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Asesor</label>
                        <select name="asesor" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                            <option value="">— Todos —</option>
                            <?php foreach ($asesores as $a): ?>
                                <option value="<?= htmlspecialchars($a['id']) ?>" <?= $f_asesor === (string)$a['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Tipo</label>
                        <select name="tipo" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                            <option value="" <?= $f_tipo === '' ? 'selected' : '' ?>>— Todos —</option>
                            <option value="encuesta" <?= $f_tipo === 'encuesta' ? 'selected' : '' ?>>Encuesta (agenda)</option>
                            <option value="levantamiento" <?= $f_tipo === 'levantamiento' ? 'selected' : '' ?>>Levantamiento de Empresa</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Estado</label>
                        <select name="estado" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                            <option value="" <?= $f_estado === '' ? 'selected' : '' ?>>— Todos —</option>
                            <option value="completada" <?= $f_estado === 'completada' ? 'selected' : '' ?>>Completada</option>
                            <option value="programada" <?= $f_estado === 'programada' ? 'selected' : '' ?>>Programada</option>
                            <option value="incompleta" <?= $f_estado === 'incompleta' ? 'selected' : '' ?>>Incompleta</option>
                            <option value="incumplida" <?= $f_estado === 'incumplida' ? 'selected' : '' ?>>Incumplida (por reasignar)</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Desde</label>
                        <input type="date" name="desde" value="<?= htmlspecialchars($f_desde) ?>" class="form-control form-control-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                    </div>
                    <div class="col-auto">
                        <label class="small fw-bold text-muted mb-1 d-block">Hasta</label>
                        <input type="date" name="hasta" value="<?= htmlspecialchars($f_hasta) ?>" class="form-control form-control-sm shadow-sm" onchange="this.form.submit()" style="border-radius:8px;">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm shadow-sm" style="border-radius:8px; background:var(--brand-navy,#123a6d); color:#fff; font-weight:700;">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                    </div>
                    <?php if ($f_estado !== '' || $f_tipo !== '' || $f_asesor !== '' || $f_busca !== ''): ?>
                    <div class="col-auto">
                        <a href="encuestas.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;"><i class="fas fa-rotate-left"></i> Limpiar</a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
            <div class="section-body p-0">
                <?php if (empty($encuestas)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>No hay encuestas que coincidan con estos filtros.</p>
                    </div>
                <?php else: ?>
                    <div class="table-premium-container">
                        <table class="table-premium">
                            <thead>
                                <tr>
                                    <th>Asesor / Cliente</th>
                                    <th>Empresa</th>
                                    <th>Tipo</th>
                                    <th>Fecha</th>
                                    <th class="text-center">Subida al servidor</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($encuestas as $t): ?>
                                <?php
                                [$estLabel, $estClass] = enc_bucket_badge($t['_bucket']);
                                $tipoTxt = enc_tipo_label($t['tipo_tarea']);
                                $tipoBadgeClass = $t['_es_levantamiento'] ? 'badge-warning-soft' : 'badge-info-soft';
                                $fechaMostrar = $t['fecha_realizada'] ?: $t['fecha_programada'];
                                $horaMostrar  = $t['fecha_realizada'] ? $t['hora_realizada'] : $t['hora_programada'];

                                if ($t['_subida']) {
                                    $subidaHtml = '<span class="subida-chip ok"><i class="fas fa-check-circle"></i> Subida</span>';
                                } elseif ($t['_bucket'] === 'completada') {
                                    $subidaHtml = '<span class="subida-chip pend"><i class="fas fa-cloud-upload-alt"></i> Pendiente de sincronizar</span>';
                                } else {
                                    $subidaHtml = '<span class="subida-chip na">— Aún no aplica</span>';
                                }
                                ?>
                                <tr>
                                    <td class="enc-cliente-cell">
                                        <div class="nombre"><?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin cliente asignado') ?></div>
                                        <div class="sub"><?= htmlspecialchars($t['asesor_nombre'] ?: '—') ?><?= $t['cliente_ciudad'] ? ' · ' . htmlspecialchars($t['cliente_ciudad']) : '' ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($t['cliente_nombre_empresa'])): ?>
                                            <span class="empresa-chip tiene"><i class="fas fa-building"></i> <?= htmlspecialchars($t['cliente_nombre_empresa']) ?></span>
                                        <?php else: ?>
                                            <span class="empresa-chip sin">No tiene empresa</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge-premium <?= $tipoBadgeClass ?>"><?= htmlspecialchars($tipoTxt) ?></span></td>
                                    <td>
                                        <?php if ($fechaMostrar): ?>
                                            <div class="fw-bold"><?= date('d M Y', strtotime($fechaMostrar)) ?></div>
                                            <div class="small text-muted"><?= $horaMostrar ?: '--:--' ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-premium <?= $estClass ?>"><?= $estLabel ?></span>
                                        <?php if ((int)($t['posposiciones'] ?? 0) > 0): ?>
                                            <div class="posp-chip"><i class="fas fa-history"></i> Pospuesta <?= (int)$t['posposiciones'] ?>x</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($t['_bucket'] === 'incumplida'): ?>
                                            <button type="button" class="btn-reasignar"
                                                onclick="abrirReasignar('<?= htmlspecialchars($t['id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($t['cliente_nombre'] ?: 'Sin cliente', ENT_QUOTES) ?>', '<?= htmlspecialchars($t['asesor_nombre'] ?: '—', ENT_QUOTES) ?>')">
                                                <i class="fas fa-people-arrows"></i> Reasignar
                                            </button>
                                        <?php else: ?>
                                            <a class="btn-ver-detalle" href="ver_encuesta.php?tarea_id=<?= urlencode($t['id']) ?>">
                                                <i class="fas fa-eye"></i> Ver encuesta
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    </div><!-- /.content-area -->
</div><!-- /.main-content -->

<!-- Modal Reasignar tarea incumplida -->
<div id="modalReasignar" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:420px;max-width:95vw;overflow:hidden;box-shadow:0 24px 48px rgba(0,0,0,.2);">
        <div style="background:linear-gradient(135deg,#1f2937,#111827);color:#fff;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
            <strong style="font-size:15px;"><i class="fas fa-people-arrows me-2"></i>Reasignar tarea incumplida</strong>
            <button onclick="cerrarReasignar()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">×</button>
        </div>
        <div style="padding:22px;">
            <p style="font-size:13.5px;color:#374151;margin-bottom:4px;">Cliente: <strong id="reasignarCliente"></strong></p>
            <p style="font-size:12.5px;color:#9ca3af;margin-bottom:16px;">Asesor actual: <span id="reasignarAsesorActual"></span> (5 posposiciones agotadas)</p>

            <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Nuevo asesor:</label>
            <select id="reasignarNuevoAsesor" style="width:100%;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;font-size:14px;margin-bottom:14px;">
                <option value="">— Selecciona un asesor —</option>
                <?php foreach ($asesores as $a): ?>
                    <option value="<?= htmlspecialchars($a['id']) ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                <?php endforeach; ?>
            </select>

            <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Nueva fecha:</label>
            <input type="date" id="reasignarFecha" style="width:100%;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;font-size:14px;">
            <input type="hidden" id="reasignarTareaId">
        </div>
        <div style="padding:0 22px 22px;display:flex;gap:10px;">
            <button onclick="cerrarReasignar()" style="flex:1;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancelar</button>
            <button onclick="confirmarReasignar()" id="btnConfReasignar" style="flex:1;padding:10px;background:#1f2937;border:none;border-radius:9px;font-weight:800;color:#fff;cursor:pointer;"><i class="fas fa-check me-1"></i>Reasignar</button>
        </div>
    </div>
</div>

<script>
    function abrirReasignar(tareaId, cliente, asesorActual) {
        document.getElementById('reasignarTareaId').value = tareaId;
        document.getElementById('reasignarCliente').textContent = cliente;
        document.getElementById('reasignarAsesorActual').textContent = asesorActual;
        document.getElementById('reasignarNuevoAsesor').value = '';
        var d = new Date(); d.setDate(d.getDate() + 1);
        document.getElementById('reasignarFecha').value = d.toISOString().split('T')[0];
        document.getElementById('modalReasignar').style.display = 'flex';
    }
    function cerrarReasignar() { document.getElementById('modalReasignar').style.display = 'none'; }
    function confirmarReasignar() {
        var btn = document.getElementById('btnConfReasignar'); btn.disabled = true;
        var tid = document.getElementById('reasignarTareaId').value;
        var nuevoAsesor = document.getElementById('reasignarNuevoAsesor').value;
        var fecha = document.getElementById('reasignarFecha').value;
        if (!nuevoAsesor) { alert('Selecciona el nuevo asesor'); btn.disabled = false; return; }
        if (!fecha) { alert('Selecciona una fecha'); btn.disabled = false; return; }
        var fd = new FormData();
        fd.append('tarea_id', tid);
        fd.append('nuevo_asesor_id', nuevoAsesor);
        fd.append('nueva_fecha', fecha);
        fetch('reasignar_tarea.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(j => {
                btn.disabled = false;
                if (j.status === 'success') {
                    cerrarReasignar();
                    alert('✅ ' + (j.message || 'Tarea reasignada'));
                    window.location.reload();
                } else {
                    alert('Error: ' + (j.message || 'no se pudo reasignar'));
                }
            }).catch(() => { btn.disabled = false; alert('Error de red'); });
    }
</script>

</body>
</html>

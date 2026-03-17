<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$msg = ''; $msgType = 'success';

// ══════════════════════════════════════════════════════════════
//  ACCIONES POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Crear nuevo código ──
    if ($_POST['action'] === 'crear') {
        $codigo      = strtoupper(trim($_POST['codigo']));
        $tipo        = $_POST['tipo'];          // porcentaje | fijo
        $valor       = floatval($_POST['valor']);
        $minimo      = floatval($_POST['minimo_viaje'] ?? 0);
        $max_usos    = $_POST['maximo_usos'] !== '' ? intval($_POST['maximo_usos']) : null;
        $fecha_ini   = $_POST['fecha_inicio'] !== '' ? $_POST['fecha_inicio'] : null;
        $fecha_fin   = $_POST['fecha_fin']    !== '' ? $_POST['fecha_fin']    : null;

        if ($codigo === '' || $valor <= 0) {
            $msg = 'El código y el valor son obligatorios.'; $msgType = 'danger';
        } else {
            // Verificar que no exista
            $existe = $pdo->prepare("SELECT id FROM codigos_descuento WHERE codigo=?");
            $existe->execute([$codigo]);
            if ($existe->fetch()) {
                $msg = "El código «{$codigo}» ya existe."; $msgType = 'danger';
            } else {
                $pdo->prepare("
                    INSERT INTO codigos_descuento
                        (codigo, tipo, valor, minimo_viaje, maximo_usos, fecha_inicio, fecha_fin, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ")->execute([$codigo, $tipo, $valor, $minimo, $max_usos, $fecha_ini, $fecha_fin]);
                $msg = "Código «{$codigo}» creado correctamente.";
            }
        }
    }

    // ── Activar / desactivar ──
    elseif ($_POST['action'] === 'toggle') {
        $id     = intval($_POST['id']);
        $activo = intval($_POST['activo']);
        $pdo->prepare("UPDATE codigos_descuento SET activo=? WHERE id=?")->execute([$activo, $id]);
        $msg = $activo ? 'Código activado.' : 'Código desactivado.';
        $msgType = $activo ? 'success' : 'warning';
    }

    // ── Eliminar ──
    elseif ($_POST['action'] === 'eliminar') {
        $id = intval($_POST['id']);
        $pdo->prepare("DELETE FROM codigos_descuento WHERE id=?")->execute([$id]);
        $msg = 'Código eliminado.'; $msgType = 'warning';
    }

    // ── Editar ──
    elseif ($_POST['action'] === 'editar') {
        $id       = intval($_POST['id']);
        $tipo     = $_POST['tipo'];
        $valor    = floatval($_POST['valor']);
        $minimo   = floatval($_POST['minimo_viaje'] ?? 0);
        $max_usos = $_POST['maximo_usos'] !== '' ? intval($_POST['maximo_usos']) : null;
        $fecha_ini = $_POST['fecha_inicio'] !== '' ? $_POST['fecha_inicio'] : null;
        $fecha_fin = $_POST['fecha_fin']    !== '' ? $_POST['fecha_fin']    : null;

        $pdo->prepare("
            UPDATE codigos_descuento
            SET tipo=?, valor=?, minimo_viaje=?, maximo_usos=?, fecha_inicio=?, fecha_fin=?
            WHERE id=?
        ")->execute([$tipo, $valor, $minimo, $max_usos, $fecha_ini, $fecha_fin, $id]);
        $msg = 'Código actualizado correctamente.';
    }
}

// ══════════════════════════════════════════════════════════════
//  DATOS
// ══════════════════════════════════════════════════════════════
$filtro  = $_GET['filtro'] ?? 'todos';
$where   = match($filtro) {
    'activos'   => 'WHERE activo = 1',
    'inactivos' => 'WHERE activo = 0',
    'vencidos'  => 'WHERE fecha_fin IS NOT NULL AND fecha_fin < NOW()',
    default     => ''
};

$codigos = $pdo->query("
    SELECT *,
           (usos_actuales >= COALESCE(maximo_usos, 999999))   AS agotado,
           (fecha_fin IS NOT NULL AND fecha_fin < NOW())        AS vencido
    FROM codigos_descuento
    $where
    ORDER BY id DESC
")->fetchAll();

// Totales para tarjetas
$totales = $pdo->query("
    SELECT
        COUNT(*)                      AS total,
        SUM(activo = 1)               AS activos,
        SUM(usos_actuales)            AS usos_totales,
        SUM(activo = 0)               AS inactivos
    FROM codigos_descuento
")->fetch();

$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado=0")->fetchColumn();

$currentPage = 'descuentos';

// Genera un código aleatorio sugerido
$sugerido = strtoupper(substr(md5(uniqid()), 0, 8));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Códigos de Descuento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .stat-card { background:#fff; border-radius:14px; padding:20px 22px; box-shadow:0 4px 16px rgba(0,0,0,.06); }
        .stat-card h3 { font-size:28px; font-weight:700; margin:0; color:#24243e; }
        .stat-card p  { color:#6c757d; margin:4px 0 0; font-size:13px; }
        .icon-box { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .bg-purple { background:linear-gradient(135deg,#6b11ff,#9b51e0); }
        .bg-green  { background:linear-gradient(135deg,#11998e,#38ef7d); }
        .bg-orange { background:linear-gradient(135deg,#f46b45,#eea849); }
        .bg-red    { background:linear-gradient(135deg,#e53935,#e35d5b); }

        .card-section { background:#fff; border-radius:14px; padding:24px; box-shadow:0 4px 16px rgba(0,0,0,.06); }

        /* Tabla */
        .table-card { background:#fff; border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,.06); overflow:hidden; }
        .table thead th { background:#f8f9fa; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; border:none; padding:12px 14px; }
        .table tbody td { padding:12px 14px; vertical-align:middle; border-color:#f5f5f5; font-size:13px; }
        .table tbody tr:hover { background:#fafbff; }

        /* Badge cupón */
        .coupon-code { font-family:monospace; font-size:15px; font-weight:700; background:#f0f4ff; color:#4a6cf7; padding:4px 12px; border-radius:8px; letter-spacing:1px; }

        /* Estado badges */
        .badge-activo   { background:#d4edda; color:#155724; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .badge-inactivo { background:#f8d7da; color:#721c24; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .badge-agotado  { background:#fff3cd; color:#856404; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .badge-vencido  { background:#e2e3e5; color:#383d41; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }

        /* Barra de uso */
        .uso-bar { height:6px; border-radius:3px; background:#e9ecef; overflow:hidden; }
        .uso-bar-fill { height:100%; border-radius:3px; background:linear-gradient(135deg,#6b11ff,#9b51e0); transition:.3s; }

        .btn-purple { background:#6b11ff; color:#fff; border:none; }
        .btn-purple:hover { background:#5a0de0; color:#fff; }

        .form-control:focus, .form-select:focus { border-color:#6b11ff; box-shadow:0 0 0 .2rem rgba(107,17,255,.15); }

        /* Filtros */
        .filter-btn { border-radius:20px; padding:6px 16px; font-size:13px; font-weight:500; }
        .filter-btn.active { background:#6b11ff; color:#fff; border-color:#6b11ff; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- ── CONTENIDO ── -->
    <div class="col-md-10 content">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0"><i class="fas fa-ticket-alt me-2 text-primary"></i>Códigos de Descuento</h4>
                <small class="text-muted">Crea y gestiona cupones para los pasajeros de la plataforma</small>
            </div>
            <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#modalNuevo">
                <i class="fas fa-plus me-2"></i>Nuevo Código
            </button>
        </div>

        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
            <i class="fas fa-<?= $msgType==='success' ? 'check-circle' : ($msgType==='warning' ? 'exclamation-triangle' : 'times-circle') ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Tarjetas resumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= $totales['total'] ?? 0 ?></h3><p>Códigos totales</p></div>
                    <div class="icon-box bg-purple"><i class="fas fa-ticket-alt"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= $totales['activos'] ?? 0 ?></h3><p>Activos</p></div>
                    <div class="icon-box bg-green"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= $totales['inactivos'] ?? 0 ?></h3><p>Inactivos</p></div>
                    <div class="icon-box bg-red"><i class="fas fa-times-circle"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= number_format($totales['usos_totales'] ?? 0) ?></h3><p>Usos totales</p></div>
                    <div class="icon-box bg-orange"><i class="fas fa-chart-bar"></i></div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="table-card">
            <div class="p-3 border-bottom d-flex gap-2 flex-wrap align-items-center">
                <span class="text-muted small fw-semibold me-2">Filtrar:</span>
                <?php foreach (['todos'=>'Todos','activos'=>'Activos','inactivos'=>'Inactivos','vencidos'=>'Vencidos'] as $k=>$v): ?>
                <a href="?filtro=<?= $k ?>"
                   class="btn btn-sm btn-outline-secondary filter-btn <?= $filtro===$k ? 'active' : '' ?>">
                    <?= $v ?>
                </a>
                <?php endforeach; ?>
                <span class="ms-auto text-muted small"><?= count($codigos) ?> código(s)</span>
            </div>

            <!-- Tabla -->
            <?php if (empty($codigos)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-ticket-alt fa-3x mb-3 opacity-25"></i>
                <p class="mb-0">No hay códigos<?= $filtro !== 'todos' ? " en esta categoría" : " creados aún" ?>.</p>
                <?php if ($filtro === 'todos'): ?>
                <button class="btn btn-purple btn-sm mt-3" data-bs-toggle="modal" data-bs-target="#modalNuevo">
                    <i class="fas fa-plus me-1"></i>Crear primer código
                </button>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Tipo / Valor</th>
                            <th class="text-center">Usos</th>
                            <th>Vigencia</th>
                            <th class="text-center">Mínimo viaje</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($codigos as $c):
                            $pct     = $c['maximo_usos'] ? min(100, ($c['usos_actuales'] / $c['maximo_usos']) * 100) : 0;
                            $agotado = $c['agotado'];
                            $vencido = $c['vencido'];
                        ?>
                        <tr>
                            <!-- Código -->
                            <td>
                                <span class="coupon-code"><?= htmlspecialchars($c['codigo']) ?></span>
                            </td>

                            <!-- Tipo/Valor -->
                            <td>
                                <?php if ($c['tipo'] === 'porcentaje'): ?>
                                    <span class="badge bg-primary fs-6">
                                        <i class="fas fa-percent me-1"></i><?= number_format($c['valor'], 0) ?>% OFF
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success fs-6">
                                        <i class="fas fa-dollar-sign me-1"></i>$<?= number_format($c['valor'], 2) ?> OFF
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Usos -->
                            <td class="text-center" style="min-width:110px">
                                <div class="fw-semibold small mb-1">
                                    <?= $c['usos_actuales'] ?>
                                    <?= $c['maximo_usos'] ? '/ '.$c['maximo_usos'] : '/ ∞' ?>
                                </div>
                                <?php if ($c['maximo_usos']): ?>
                                <div class="uso-bar">
                                    <div class="uso-bar-fill" style="width:<?= $pct ?>%;
                                         background:<?= $pct >= 100 ? '#e53935' : ($pct >= 75 ? '#f46b45' : '') ?>">
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>

                            <!-- Vigencia -->
                            <td>
                                <?php if ($c['fecha_inicio'] || $c['fecha_fin']): ?>
                                    <div class="small">
                                        <?php if ($c['fecha_inicio']): ?>
                                            <i class="fas fa-play text-success me-1"></i>
                                            <?= date('d/m/Y', strtotime($c['fecha_inicio'])) ?>
                                        <?php endif; ?>
                                        <?php if ($c['fecha_fin']): ?>
                                            <br><i class="fas fa-stop text-danger me-1"></i>
                                            <?= date('d/m/Y', strtotime($c['fecha_fin'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">Sin vencimiento</small>
                                <?php endif; ?>
                            </td>

                            <!-- Mínimo -->
                            <td class="text-center">
                                <?= $c['minimo_viaje'] > 0
                                    ? '<span class="text-muted small">$'.number_format($c['minimo_viaje'],2).'</span>'
                                    : '<span class="text-muted small">—</span>' ?>
                            </td>

                            <!-- Estado -->
                            <td class="text-center">
                                <?php if ($vencido): ?>
                                    <span class="badge-vencido"><i class="fas fa-clock me-1"></i>Vencido</span>
                                <?php elseif ($agotado): ?>
                                    <span class="badge-agotado"><i class="fas fa-ban me-1"></i>Agotado</span>
                                <?php elseif ($c['activo']): ?>
                                    <span class="badge-activo"><i class="fas fa-circle me-1" style="font-size:8px"></i>Activo</span>
                                <?php else: ?>
                                    <span class="badge-inactivo"><i class="fas fa-circle me-1" style="font-size:8px"></i>Inactivo</span>
                                <?php endif; ?>
                            </td>

                            <!-- Acciones -->
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <!-- Editar -->
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="abrirEditar(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"
                                            title="Editar">
                                        <i class="fas fa-pen"></i>
                                    </button>

                                    <!-- Toggle activo -->
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id"     value="<?= $c['id'] ?>">
                                        <input type="hidden" name="activo" value="<?= $c['activo'] ? 0 : 1 ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $c['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                title="<?= $c['activo'] ? 'Desactivar' : 'Activar' ?>">
                                            <i class="fas <?= $c['activo'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Eliminar -->
                                    <form method="POST" class="m-0"
                                          onsubmit="return confirm('¿Eliminar el código <?= htmlspecialchars($c['codigo'], ENT_QUOTES) ?>?')">
                                        <input type="hidden" name="action" value="eliminar">
                                        <input type="hidden" name="id"     value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Nuevo código
══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalNuevo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-plus-circle text-success me-2"></i>Nuevo Código de Descuento
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="crear">
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Código -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Código <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="codigo" id="nuevoCodigo" class="form-control text-uppercase"
                                       placeholder="Ej: BIENVENIDO20" maxlength="50" required
                                       value="<?= $sugerido ?>"
                                       style="font-family:monospace;font-weight:700;letter-spacing:1px">
                                <button type="button" class="btn btn-outline-secondary" onclick="generarCodigo()" title="Generar aleatorio">
                                    <i class="fas fa-dice"></i>
                                </button>
                            </div>
                            <div class="form-text">El pasajero ingresará este código en la app.</div>
                        </div>

                        <!-- Tipo -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Tipo de descuento</label>
                            <select name="tipo" id="tipoDescuento" class="form-select" onchange="actualizarTipo()">
                                <option value="porcentaje">Porcentaje (%)</option>
                                <option value="fijo">Monto fijo ($)</option>
                            </select>
                        </div>

                        <!-- Valor -->
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" id="lblValor">Descuento (%)</label>
                            <div class="input-group">
                                <input type="number" name="valor" class="form-control"
                                       step="0.01" min="0.01" max="100" value="10" required>
                                <span class="input-group-text" id="sufValor">%</span>
                            </div>
                        </div>

                        <!-- Máximo usos -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Máximo de usos</label>
                            <input type="number" name="maximo_usos" class="form-control"
                                   min="1" placeholder="Dejar vacío = ilimitado">
                            <div class="form-text">Cuántas veces puede usarse en total.</div>
                        </div>

                        <!-- Mínimo viaje -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Mínimo de viaje ($)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="minimo_viaje" class="form-control"
                                       step="0.01" min="0" value="0" placeholder="0 = sin mínimo">
                            </div>
                            <div class="form-text">Tarifa mínima para aplicar el cupón.</div>
                        </div>

                        <!-- Fecha inicio -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Válido desde</label>
                            <input type="datetime-local" name="fecha_inicio" class="form-control">
                            <div class="form-text">Dejar vacío = válido de inmediato.</div>
                        </div>

                        <!-- Fecha fin -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Válido hasta</label>
                            <input type="datetime-local" name="fecha_fin" class="form-control">
                            <div class="form-text">Dejar vacío = sin fecha de vencimiento.</div>
                        </div>

                        <!-- Preview -->
                        <div class="col-12">
                            <div class="p-3 rounded-3" style="background:#f0f4ff; border:1px dashed #6b11ff55">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fas fa-tag fa-2x text-primary"></i>
                                    <div>
                                        <div class="fw-bold" id="previewCodigo" style="font-family:monospace;font-size:18px;color:#4a6cf7"><?= $sugerido ?></div>
                                        <div class="text-muted small" id="previewDesc">10% de descuento en tu viaje</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-purple px-4">
                        <i class="fas fa-save me-2"></i>Crear Código
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Editar código
══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-pen text-primary me-2"></i>Editar Código: <span id="editTitulo" style="font-family:monospace;color:#4a6cf7"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="id" id="editId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tipo</label>
                            <select name="tipo" id="editTipo" class="form-select">
                                <option value="porcentaje">Porcentaje (%)</option>
                                <option value="fijo">Monto fijo ($)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Valor</label>
                            <input type="number" name="valor" id="editValor" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Máx. usos</label>
                            <input type="number" name="maximo_usos" id="editMaxUsos" class="form-control" min="1" placeholder="Ilimitado">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Mínimo viaje ($)</label>
                            <input type="number" name="minimo_viaje" id="editMinimo" class="form-control" step="0.01" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Válido desde</label>
                            <input type="datetime-local" name="fecha_inicio" id="editFechaIni" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Válido hasta</label>
                            <input type="datetime-local" name="fecha_fin" id="editFechaFin" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-purple px-4">
                        <i class="fas fa-save me-2"></i>Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function generarCodigo() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let code = '';
    for (let i = 0; i < 8; i++) code += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('nuevoCodigo').value = code;
    document.getElementById('previewCodigo').textContent = code;
}

function actualizarTipo() {
    const tipo  = document.getElementById('tipoDescuento').value;
    const suf   = document.getElementById('sufValor');
    const lbl   = document.getElementById('lblValor');
    const input = document.querySelector('[name="valor"]');
    if (tipo === 'porcentaje') {
        suf.textContent = '%'; lbl.textContent = 'Descuento (%)';
        input.max = 100;
    } else {
        suf.textContent = '$'; lbl.textContent = 'Descuento ($)';
        input.max = 9999;
    }
    actualizarPreview();
}

function actualizarPreview() {
    const tipo  = document.getElementById('tipoDescuento').value;
    const valor = document.querySelector('[name="valor"]').value || '0';
    const desc  = tipo === 'porcentaje'
        ? `${valor}% de descuento en tu viaje`
        : `$${parseFloat(valor).toFixed(2)} de descuento en tu viaje`;
    document.getElementById('previewDesc').textContent = desc;
}

document.getElementById('nuevoCodigo').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    document.getElementById('previewCodigo').textContent = this.value || '—';
});
document.querySelector('[name="valor"]').addEventListener('input', actualizarPreview);

function abrirEditar(c) {
    document.getElementById('editId').value       = c.id;
    document.getElementById('editTitulo').textContent = c.codigo;
    document.getElementById('editTipo').value     = c.tipo;
    document.getElementById('editValor').value    = c.valor;
    document.getElementById('editMaxUsos').value  = c.maximo_usos || '';
    document.getElementById('editMinimo').value   = c.minimo_viaje || 0;

    const toLocal = iso => iso ? iso.replace(' ', 'T').substring(0, 16) : '';
    document.getElementById('editFechaIni').value = toLocal(c.fecha_inicio);
    document.getElementById('editFechaFin').value = toLocal(c.fecha_fin);

    new bootstrap.Modal(document.getElementById('modalEditar')).show();
}
</script>
</body>
</html>

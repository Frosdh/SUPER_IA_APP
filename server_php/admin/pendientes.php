<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Lógica de aprobar o rechazar rápido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conductor_id'])) {
    $cId = (int)$_POST['conductor_id'];
    if ($_POST['action'] === 'aprobar') {
    $stmt = $pdo->prepare("UPDATE conductores SET verificado = 1, estado = 'libre' WHERE id = ?");
    $stmt->execute([$cId]);
    $msg = ['tipo' => 'success', 'texto' => 'Conductor aprobado correctamente.'];
} elseif ($_POST['action'] === 'rechazar') {
    $stmt = $pdo->prepare("UPDATE conductores SET verificado = 2 WHERE id = ?");
    $stmt->execute([$cId]);
    $msg = ['tipo' => 'warning', 'texto' => 'Conductor rechazado.'];
}
}

$f_canton = $_GET['canton'] ?? '';
$f_coop   = $_GET['coop_id'] ?? '';
$f_cat    = $_GET['cat_id']   ?? '';

$whereExtra = "";
$params = [];
if ($f_canton !== '') {
$whereExtra .= " AND c.canton = ?";
$params[] = $f_canton;
}
if ($f_coop !== '') {
$whereExtra .= " AND c.cooperativa_id = ?";
$params[] = $f_coop;
}
if ($f_cat !== '') {
$whereExtra .= " AND c.categoria_id = ?";
$params[] = $f_cat;
}

// Listar pendientes - versión simplificada
try {
    $stmt = $pdo->prepare("
    SELECT c.id, c.nombre, c.telefono, c.email, c.ciudad, c.canton, c.creado_en,
           c.foto_perfil
    FROM conductores c
    WHERE c.verificado = 0 $whereExtra
    ORDER BY c.creado_en DESC
    ");
    $stmt->execute($params);
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendientes = [];
}

$currentPage = 'pendientes';
$totalPendientes = count($pendientes);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Admin – Conductores Pendientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ── Driver card ─────────────────────────── */
        .driver-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,.06);
            padding: 20px 24px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
            transition: box-shadow .2s;
        }
        .driver-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.10); }

        .driver-avatar {
            width: 64px; height: 64px; border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e9ecef;
            flex-shrink: 0;
        }
        .driver-avatar-placeholder {
            width: 64px; height: 64px; border-radius: 50%;
            background: linear-gradient(135deg,#302b63,#6b11ff);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .driver-avatar-placeholder i { color:#fff; font-size: 1.6rem; }

        .doc-progress-bar { height: 6px; border-radius: 4px; background: #e9ecef; overflow: hidden; }
        .doc-progress-fill { height: 100%; border-radius: 4px; transition: width .4s; }

        .badge-docs-ok    { background: #d1fae5; color: #065f46; }
        .badge-docs-warn  { background: #fef3c7; color: #92400e; }
        .badge-docs-err   { background: #fee2e2; color: #991b1b; }

        .pill-info { background: #f1f5f9; color: #475569; font-size: .78rem; border-radius: 20px; padding: 3px 10px; display: inline-block; }

        .btn-ver { background: linear-gradient(135deg,#302b63,#6b11ff); color:#fff; border: none; border-radius: 8px; padding: 6px 16px; font-size: .82rem; font-weight: 600; }
        .btn-ver:hover { opacity: .88; color: #fff; }

        .empty-state { background: #fff; border-radius: 16px; padding: 60px 20px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.04); }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">

<?php include '_sidebar.php'; ?>

        <!-- Main content -->
        <div class="col-md-10 content">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div>
                    <h2 class="fw-bold mb-0">Conductores Pendientes</h2>
                    <p class="text-muted mb-0" style="font-size:.9rem">
                        <?= count($pendientes) ?> conductor<?= count($pendientes) !== 1 ? 'es' : '' ?> esperando revisión
                    </p>
                </div>
                <span class="badge bg-warning text-dark fs-6 px-3 py-2 rounded-pill">
                    <i class="fas fa-clock me-1"></i> <?= count($pendientes) ?> pendiente<?= count($pendientes) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <!-- Filtros de búsqueda -->
            <div class="card shadow-sm border-0 mb-4 bg-light">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Cantón</label>
                            <select name="canton" class="form-select form-select-sm">
                                <option value="">Todos</option>
                                <?php
                                $cantones = $pdo->query("SELECT DISTINCT canton FROM conductores WHERE canton IS NOT NULL AND canton != ''")->fetchAll();
                                foreach($cantones as $c) {
                                    $sel = ($f_canton == $c['canton']) ? 'selected' : '';
                                    echo "<option value='{$c['canton']}' $sel>{$c['canton']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Cooperativa</label>
                            <select name="coop_id" class="form-select form-select-sm">
                                <option value="">Todas</option>
                                <?php
                                $coops = $pdo->query("SELECT id, nombre FROM cooperativas")->fetchAll();
                                foreach($coops as $co) {
                                    $sel = ($f_coop == $co['id']) ? 'selected' : '';
                                    echo "<option value='{$co['id']}' $sel>{$co['nombre']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Categoría</label>
                            <select name="cat_id" class="form-select form-select-sm">
                                <option value="">Todas</option>
                                <?php
                                $cats = $pdo->query("SELECT id, nombre FROM categorias")->fetchAll();
                                foreach($cats as $ca) {
                                    $sel = ($f_cat == $ca['id']) ? 'selected' : '';
                                    echo "<option value='{$ca['id']}' $sel>{$ca['nombre']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-search me-1"></i> Filtrar</button>
                        </div>
                        <div class="col-md-2">
                            <a href="pendientes.php" class="btn btn-sm btn-outline-secondary w-100">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($msg)): ?>
                <div class="alert alert-<?= $msg['tipo'] === 'success' ? 'success' : 'warning' ?> rounded-3 shadow-sm">
                    <i class="fas fa-<?= $msg['tipo'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    <?= htmlspecialchars($msg['texto']) ?>
                </div>
            <?php endif; ?>

            <?php if (count($pendientes) === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-check-double fa-3x text-success mb-3"></i>
                    <h5 class="text-muted">¡Todo al día! No hay conductores pendientes de revisión.</h5>
                </div>

            <?php else: ?>
                <?php foreach ($pendientes as $p):
                    $docsTotal     = (int)$p['docs_total'];
                    $docsAprobados = (int)$p['docs_aprobados'];
                    $docsRechazados= (int)$p['docs_rechazados'];
                    $docsPct       = $docsTotal > 0 ? round($docsAprobados / 5 * 100) : 0;
                    $allDocsOk     = ($docsAprobados === 5);

                    // Color de la barra de documentos
                    if ($docsRechazados > 0)      $barColor = '#ef4444';
                    elseif ($allDocsOk)            $barColor = '#22c55e';
                    elseif ($docsAprobados >= 3)   $barColor = '#f59e0b';
                    else                           $barColor = '#6b11ff';

                    // Badge de documentos
                    if ($docsRechazados > 0)       $badgeCls = 'badge-docs-err';
                    elseif ($allDocsOk)            $badgeCls = 'badge-docs-ok';
                    else                           $badgeCls = 'badge-docs-warn';
                ?>
                <div class="driver-card">
                    <div class="d-flex align-items-start gap-3 flex-wrap">

                        <!-- Avatar -->
                        <?php if (!empty($p['foto_perfil'])): ?>
                            <img src="data:image/jpeg;base64,<?= $p['foto_perfil'] ?>" class="driver-avatar" alt="Foto">
                        <?php else: ?>
                            <div class="driver-avatar-placeholder"><i class="fas fa-user"></i></div>
                        <?php endif; ?>

                        <!-- Info -->
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <h5 class="fw-bold mb-0"><?= htmlspecialchars($p['nombre']) ?></h5>
                                <?php if (!empty($p['ciudad'])): ?>
                                    <span class="pill-info"><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($p['ciudad']) ?></span>
                                <?php endif; ?>
                                <span class="badge bg-secondary rounded-pill" style="font-size:.72rem">
                                    <i class="fas fa-calendar-alt me-1"></i>
                                    <?= date('d/m/Y H:i', strtotime($p['creado_en'])) ?>
                                </span>
                            </div>

                            <!-- Datos personales -->
                            <div class="d-flex gap-3 flex-wrap text-muted mb-2" style="font-size:.84rem">
                                <span><i class="fas fa-id-card me-1"></i><?= htmlspecialchars($p['cedula']) ?></span>
                                <span><i class="fas fa-phone me-1"></i><?= htmlspecialchars($p['telefono']) ?></span>
                                <?php if (!empty($p['email'])): ?>
                                    <span><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($p['email']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Vehículo -->
                            <?php if (!empty($p['placa'])): ?>
                            <div class="mb-3">
                                <span class="pill-info">
                                    <i class="fas fa-car me-1"></i>
                                    <strong><?= htmlspecialchars($p['placa']) ?></strong>
                                    — <?= htmlspecialchars($p['marca']) ?> <?= htmlspecialchars($p['modelo']) ?>
                                    (<?= htmlspecialchars($p['color']) ?>)
                                    <?= $p['anio'] ? '· ' . htmlspecialchars($p['anio']) : '' ?>
                                </span>
                            </div>
                            <?php endif; ?>

                            <!-- Progreso documentos -->
                            <div class="mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <small class="text-muted fw-semibold">Documentos revisados</small>
                                    <span class="badge rounded-pill <?= $badgeCls ?>" style="font-size:.75rem">
                                        <?php if ($docsRechazados > 0): ?>
                                            <i class="fas fa-times-circle me-1"></i><?= $docsRechazados ?> rechazado<?= $docsRechazados > 1 ? 's' : '' ?>
                                        <?php elseif ($allDocsOk): ?>
                                            <i class="fas fa-check-circle me-1"></i>Todos aprobados
                                        <?php else: ?>
                                            <i class="fas fa-hourglass-half me-1"></i><?= $docsAprobados ?>/5 aprobados
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="doc-progress-bar">
                                    <div class="doc-progress-fill" style="width:<?= $docsPct ?>%; background:<?= $barColor ?>"></div>
                                </div>
                                <?php if ($docsTotal === 0): ?>
                                    <small class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Sin documentos aún</small>
                                <?php endif; ?>
                            </div>

                            <!-- Acciones -->
                            <div class="d-flex gap-2 flex-wrap align-items-center">
                                <!-- Ver documentos -->
                                <a href="ver_conductor.php?id=<?= $p['id'] ?>" class="btn btn-ver">
                                    <i class="fas fa-folder-open me-1"></i> Ver documentos
                                </a>

                                <!-- Aprobar rápido (solo si todos los docs están OK) -->
                                <?php if ($allDocsOk): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="conductor_id" value="<?= $p['id'] ?>">
                                    <button type="submit" name="action" value="aprobar"
                                        class="btn btn-sm btn-success px-3 rounded-pill fw-semibold"
                                        onclick="return confirm('¿Aprobar al conductor <?= htmlspecialchars(addslashes($p['nombre'])) ?>?')">
                                        <i class="fas fa-check me-1"></i> Aprobar
                                    </button>
                                </form>
                                <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary px-3 rounded-pill" disabled
                                    title="Revisa y aprueba los 5 documentos primero">
                                    <i class="fas fa-lock me-1"></i> Aprobar (pendiente docs)
                                </button>
                                <?php endif; ?>

                                <!-- Rechazar -->
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="conductor_id" value="<?= $p['id'] ?>">
                                    <button type="submit" name="action" value="rechazar"
                                        class="btn btn-sm btn-outline-danger px-3 rounded-pill fw-semibold"
                                        onclick="return confirm('¿Rechazar la solicitud de <?= htmlspecialchars(addslashes($p['nombre'])) ?>?')">
                                        <i class="fas fa-times me-1"></i> Rechazar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div><!-- /content -->
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

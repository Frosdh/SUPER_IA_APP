<?php
// ============================================================
// admin/ver_conductor_coop.php — Vista de detalles para secretaria
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['secretary_logged_in']) || $_SESSION['secretary_logged_in'] !== true) {
    header('Location: login_selector.php');
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { header('Location: panel_cooperativa.php'); exit; }

// ── Datos del conductor ───────────────────────────────────────
$stmtC = $pdo->prepare("
    SELECT c.id, c.nombre, c.email, c.telefono, c.cedula, c.ciudad,
           c.verificado, c.estado, c.foto_perfil, c.creado_en,
           c.calificacion_promedio, c.cooperativa_id,
           v.marca, v.modelo, v.placa, v.color, v.anio,
           cat.nombre AS categoria
    FROM conductores c
    LEFT JOIN vehiculos v   ON c.id = v.conductor_id
    LEFT JOIN categorias cat ON v.categoria_id = cat.id
    WHERE c.id = ?
");
$stmtC->execute([$id]);
$c = $stmtC->fetch(PDO::FETCH_ASSOC);

if (!$c) { 
    header('Location: panel_cooperativa.php'); 
    exit; 
}

// Bloqueo de seguridad: Solo puede ver a sus conductores
if ((int)$c['cooperativa_id'] !== (int)$_SESSION['cooperativa_id']) {
    header('Location: panel_cooperativa.php?error=no_autorizado');
    exit;
}

// ── Documentos ────────────────────────────────────────────────
$stmtD = $pdo->prepare("SELECT tipo, imagen, estado, notas FROM documentos_conductor WHERE conductor_id=?");
$stmtD->execute([$id]);
$docs = [];
while ($row = $stmtD->fetch(PDO::FETCH_ASSOC)) {
    $docs[$row['tipo']] = $row;
}

$tiposDoc = [
    'licencia_frente'  => 'Licencia (frente)',
    'licencia_reverso' => 'Licencia (reverso)',
    'cedula'           => 'Cédula de identidad',
    'soat'             => 'SOAT / Seguro',
    'matricula'        => 'Matrícula vehicular',
    'vinculacion_cooperativa' => 'Vínculo a Cooperativa',
];

$aprobados = count(array_filter($docs, function ($d) {
    return isset($d['estado']) && $d['estado'] === 'aprobado';
}));
$total     = count($tiposDoc);

function estadoBadge($estado) {
    $estado = $estado !== null ? $estado : 'pendiente';
    switch ($estado) {
        case 'aprobado':
            return '<span class="badge bg-success">Aprobado</span>';
        case 'rechazado':
            return '<span class="badge bg-danger">Rechazado</span>';
        default:
            return '<span class="badge bg-warning text-dark">Pendiente</span>';
    }
}

$currentPage = 'conductores';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove — <?= htmlspecialchars($c['nombre']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .content { padding:28px; background: var(--bg); color: var(--text); min-height: 100vh; }
        .doc-card { background: var(--card); border-radius:14px; overflow:hidden; box-shadow: var(--shadow-sm); margin-bottom:20px; color: var(--text); }
        .doc-card .doc-img { width:100%; height:220px; object-fit:cover; background: var(--border); display:flex; align-items:center; justify-content:center; }
        .doc-card .doc-img img { width:100%; height:220px; object-fit:cover; cursor:pointer; }
        .profile-photo { width:110px; height:110px; border-radius:50%; object-fit:cover; border:4px solid var(--primary); }
        .info-card { background: var(--card); border-radius:14px; padding:22px; box-shadow: var(--shadow-sm); color: var(--text); }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">
    <?php include '_sidebar.php'; ?>
    <div class="col-md-10 content">
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="panel_cooperativa.php">Dashboard</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($c['nombre']) ?></li>
            </ol>
        </nav>

        <div class="row">
            <div class="col-md-4">
                <div class="info-card text-center mb-3">
                    <?php if (!empty($c['foto_perfil'])): ?>
                        <img src="data:image/jpeg;base64,<?= $c['foto_perfil'] ?>" class="profile-photo mb-3">
                    <?php else: ?>
                        <div class="mx-auto mb-3 p-4 rounded-circle" style="width:110px; background: var(--border);"><i class="fas fa-user fa-3x text-secondary"></i></div>
                    <?php endif; ?>
                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($c['nombre']) ?></h5>
                    <div class="mb-3">
                        <?php if ($c['verificado'] == 1): ?>
                            <span class="badge bg-success px-3 py-2">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark px-3 py-2">Pendiente</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-card mb-3">
                    <h6 class="fw-bold mb-3">Datos Personales</h6>
                    <table class="table table-sm table-borderless small mb-0">
                        <tr><td class="text-muted">Cédula:</td><td class="fw-bold"><?= htmlspecialchars($c['cedula']) ?></td></tr>
                        <tr><td class="text-muted">Teléfono:</td><td><?= htmlspecialchars($c['telefono']) ?></td></tr>
                    </table>
                </div>

                <div class="info-card">
                    <h6 class="fw-bold mb-3">Vehículo</h6>
                    <?php if ($c['placa']): ?>
                        <table class="table table-sm table-borderless small mb-0">
                            <tr><td class="text-muted">Placa:</td><td class="fw-bold"><?= htmlspecialchars($c['placa']) ?></td></tr>
                            <tr><td class="text-muted">Marca/Modelo:</td><td><?= htmlspecialchars($c['marca']) ?> <?= htmlspecialchars($c['modelo']) ?></td></tr>
                        </table>
                    <?php else: ?>
                        <p class="text-muted small">Sin vehículo registrado.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-md-8">
                <h5 class="fw-bold mb-3">Supervisión de Documentos</h5>
                <div class="row">
                    <?php foreach ($tiposDoc as $tipo => $nombreDoc):
                        $doc = $docs[$tipo] ?? null;
                        $estado = $doc['estado'] ?? 'no_subido';
                    ?>
                    <div class="col-md-6">
                        <div class="doc-card">
                            <div class="doc-img">
                                <?php if ($doc && !empty($doc['imagen'])): ?>
                                    <img src="data:image/jpeg;base64,<?= $doc['imagen'] ?>" onclick="verImagen('<?= htmlspecialchars($doc['imagen'], ENT_QUOTES) ?>', '<?= htmlspecialchars($nombreDoc) ?>')">
                                <?php else: ?>
                                    <small class="text-muted">No subido</small>
                                <?php endif; ?>
                            </div>
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="small fw-bold"><?= htmlspecialchars($nombreDoc) ?></span>
                                    <?= estadoBadge($estado) ?>
                                </div>
                                <?php if ($doc && !empty($doc['notas'])): ?>
                                    <div class="mt-2 p-2 rounded small text-danger" style="background: rgba(239, 68, 68, 0.1);">
                                        <strong>Nota admin:</strong> <?= htmlspecialchars($doc['notas']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="imgModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-body p-1 text-center">
                <img id="imgModalSrc" src="" style="max-width:100%; border-radius:8px;">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function verImagen(base64, titulo) {
    document.getElementById('imgModalSrc').src = 'data:image/jpeg;base64,' + base64;
    new bootstrap.Modal(document.getElementById('imgModal')).show();
}
</script>
</body>
</html>

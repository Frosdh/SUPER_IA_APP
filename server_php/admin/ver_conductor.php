<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { header('Location: pendientes.php'); exit; }

// ── Acciones POST ─────────────────────────────────────────────
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Aprobar / rechazar conductor completo
    if ($_POST['action'] === 'aprobar_conductor') {
        $pdo->prepare("UPDATE conductores SET verificado=1, estado='libre' WHERE id=?")->execute([$id]);
        $msg = 'Conductor aprobado correctamente. Ya puede iniciar sesión.';

        // Enviar Correo de Bienvenida al Conductor
        $stmtCorreo = $pdo->prepare("SELECT nombre, email FROM conductores WHERE id = ?");
        $stmtCorreo->execute([$id]);
        $dest = $stmtCorreo->fetch(PDO::FETCH_ASSOC);

        if ($dest && !empty($dest['email'])) {
            require_once __DIR__ . '/../email_helper.php';
            
            $subject = 'Cuenta Aprobada - Bienvenido a GeoMove';
            $htmlBody = 'Hola ' . htmlspecialchars($dest['nombre']) . ',<br><br>¡Felicidades! Tus documentos han sido <b>verificados y tu cuenta está activa</b>.<br><br>Ya puedes abrir la aplicación de conductores, conectarte y empezar a recibir viajes.<br><br>Saludos cordiales,<br>El equipo de GeoMove.';
            $plainBody = 'Hola ' . $dest['nombre'] . ', ¡Felicidades! Tus documentos han sido verificados y tu cuenta está activa. Ya puedes abrir la aplicación de conductores. Saludos cordiales.';
            
            list($success, $error) = sendEmailMessage($dest['email'], $subject, $htmlBody, $plainBody);
            if ($success) {
                $msg .= ' Se ha enviado el correo de bienvenida al conductor.';
            }
        }
    } elseif ($_POST['action'] === 'rechazar_solicitud') {
        $nota = trim($_POST['motivo'] ?? 'Sin motivo especificado');
        $pdo->prepare("UPDATE conductores SET verificado=2 WHERE id=?")->execute([$id]);
        $msg = 'Conductor rechazado.'; $msgType = 'danger';

    // Aprobar / rechazar documento individual
    } elseif ($_POST['action'] === 'aprobar_doc') {
        $tipo = $_POST['tipo'] ?? '';
        $pdo->prepare("UPDATE documentos_conductor SET estado='aprobado', notas=NULL WHERE conductor_id=? AND tipo=?")
            ->execute([$id, $tipo]);
        $msg = 'Documento aprobado.';

    } elseif ($_POST['action'] === 'rechazar_doc') {
        $tipo  = $_POST['tipo'] ?? '';
        $notas = trim($_POST['notas'] ?? 'Documento no válido');
        $pdo->prepare("UPDATE documentos_conductor SET estado='rechazado', notas=? WHERE conductor_id=? AND tipo=?")
            ->execute([$notas, $id, $tipo]);
        $msg = 'Documento rechazado.'; $msgType = 'warning';
    }
}

// ── Datos del conductor ───────────────────────────────────────
$stmtC = $pdo->prepare("
    SELECT c.id, c.nombre, c.email, c.telefono, c.cedula, c.ciudad,
           c.verificado, c.estado, c.foto_perfil, c.creado_en,
           c.calificacion_promedio, c.tipo_conductor,
           v.marca, v.modelo, v.placa, v.color, v.anio,
           cat.nombre AS categoria
    FROM conductores c
    LEFT JOIN vehiculos v   ON c.id = v.conductor_id
    LEFT JOIN categorias cat ON v.categoria_id = cat.id
    WHERE c.id = ?
");
$stmtC->execute([$id]);
$c = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$c) { header('Location: pendientes.php'); exit; }

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
];

if (($c['tipo_conductor'] ?? '') === 'cooperativa') {
    $tiposDoc['vinculacion_cooperativa'] = 'Resolución de Cooperativa';
}

// Contar documentos aprobados
$aprobados = count(array_filter($docs, fn($d) => $d['estado'] === 'aprobado'));
$total     = count($tiposDoc);

function estadoBadge($estado) {
    return match($estado ?? 'pendiente') {
        'aprobado'  => '<span class="badge bg-success">Aprobado</span>',
        'rechazado' => '<span class="badge bg-danger">Rechazado</span>',
        default     => '<span class="badge bg-warning text-dark">Pendiente</span>',
    };
}

$currentPage = 'conductores';
$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — <?= htmlspecialchars($c['nombre']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .content { padding:28px; }

        .doc-card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 3px 12px rgba(0,0,0,.07); margin-bottom:20px; }
        .doc-card .doc-img { width:100%; height:220px; object-fit:cover; background:#e9ecef; display:flex; align-items:center; justify-content:center; cursor:pointer; }
        .doc-card .doc-img img { width:100%; height:220px; object-fit:cover; cursor:pointer; }
        .doc-card .doc-body { padding:14px 16px; }

        .profile-photo { width:110px; height:110px; border-radius:50%; object-fit:cover; border:4px solid #6b11ff; box-shadow:0 4px 16px rgba(107,17,255,.25); }
        .profile-placeholder { width:110px; height:110px; border-radius:50%; background:#e9ecef; display:flex; align-items:center; justify-content:center; border:4px solid #dee2e6; }
        .info-card { background:#fff; border-radius:14px; padding:22px; box-shadow:0 3px 12px rgba(0,0,0,.07); }
        .progress-docs { height:10px; border-radius:6px; }

        /* Modal de imagen */
        #imgModal .modal-dialog { max-width:860px; }
        #imgModal img { width:100%; border-radius:10px; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- Contenido -->
    <div class="col-md-10 content">

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="pendientes.php">Pendientes</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($c['nombre']) ?></li>
            </ol>
        </nav>

        <h4 class="fw-bold mb-4"><i class="fas fa-id-card me-2 text-purple"></i>Revisión de conductor</h4>

        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">

            <!-- ── Columna izquierda: info del conductor ── -->
            <div class="col-md-4">
                <div class="info-card text-center mb-3">
                    <!-- Foto de perfil -->
                    <?php if (!empty($c['foto_perfil'])): ?>
                        <img src="data:image/jpeg;base64,<?= $c['foto_perfil'] ?>"
                             class="profile-photo mb-3" alt="Foto perfil">
                    <?php else: ?>
                        <div class="profile-placeholder mx-auto mb-3">
                            <i class="fas fa-user fa-2x text-secondary"></i>
                        </div>
                    <?php endif; ?>

                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($c['nombre']) ?></h5>
                    <p class="text-muted small mb-3"><?= htmlspecialchars($c['ciudad'] ?? 'Cuenca') ?></p>

                    <!-- Estado actual -->
                    <div class="mb-3">
                        <?php if ($c['verificado'] == 1): ?>
                            <span class="badge bg-success fs-6 px-3 py-2">
                                <i class="fas fa-check-circle me-1"></i> Aprobado
                            </span>
                        <?php elseif ($c['verificado'] == 2): ?>
                            <span class="badge bg-danger fs-6 px-3 py-2">
                                <i class="fas fa-times-circle me-1"></i> Rechazado
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                                <i class="fas fa-clock me-1"></i> Pendiente
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Progreso de documentos -->
                    <div class="mb-3 text-start">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted">Documentos aprobados</small>
                            <small class="fw-bold"><?= $aprobados ?>/<?= $total ?></small>
                        </div>
                        <div class="progress progress-docs">
                            <div class="progress-bar bg-success" style="width:<?= ($aprobados/$total)*100 ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Datos personales -->
                <div class="info-card mb-3">
                    <h6 class="fw-bold mb-3"><i class="fas fa-user me-2 text-primary"></i>Datos personales</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:38%">Cédula</td><td class="fw-semibold"><?= htmlspecialchars($c['cedula']) ?></td></tr>
                        <tr><td class="text-muted">Teléfono</td><td><?= htmlspecialchars($c['telefono']) ?></td></tr>
                        <tr><td class="text-muted">Correo</td><td><?= htmlspecialchars($c['email'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Ciudad</td><td><?= htmlspecialchars($c['ciudad'] ?? 'Cuenca') ?></td></tr>
                        <tr><td class="text-muted">Registro</td><td><?= date('d/m/Y', strtotime($c['creado_en'])) ?></td></tr>
                    </table>
                </div>

                <!-- Datos del vehículo -->
                <div class="info-card mb-3">
                    <h6 class="fw-bold mb-3"><i class="fas fa-car me-2 text-info"></i>Vehículo</h6>
                    <?php if ($c['placa']): ?>
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted" style="width:38%">Marca</td><td class="fw-semibold"><?= htmlspecialchars($c['marca']) ?></td></tr>
                        <tr><td class="text-muted">Modelo</td><td><?= htmlspecialchars($c['modelo']) ?></td></tr>
                        <tr><td class="text-muted">Placa</td><td><span class="badge bg-dark fs-6"><?= htmlspecialchars($c['placa']) ?></span></td></tr>
                        <tr><td class="text-muted">Color</td><td><?= htmlspecialchars($c['color']) ?></td></tr>
                        <tr><td class="text-muted">Año</td><td><?= htmlspecialchars($c['anio']) ?></td></tr>
                        <tr><td class="text-muted">Categoría</td><td><?= htmlspecialchars($c['categoria'] ?? '—') ?></td></tr>
                    </table>
                    <?php else: ?>
                    <p class="text-muted small">No se registró vehículo aún.</p>
                    <?php endif; ?>
                </div>

                <!-- Botones de decisión final -->
                <?php if ($c['verificado'] != 1 && $c['verificado'] != 2): ?>
                <div class="info-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-gavel me-2 text-warning"></i>Decisión final</h6>
                    <p class="text-muted small mb-3">
                        Revisa todos los documentos antes de aprobar.
                        Documentos aprobados: <strong><?= $aprobados ?>/<?= $total ?></strong>
                    </p>
                    <form method="POST" class="d-grid gap-2"
                          onsubmit="return confirm('¿Aprobar al conductor <?= htmlspecialchars($c['nombre']) ?>? Podrá iniciar sesión inmediatamente.')">
                        <input type="hidden" name="action" value="aprobar_conductor">
                        <button type="submit" class="btn btn-success"
                                <?= $aprobados < $total ? 'disabled title="Aprueba todos los documentos primero"' : '' ?>>
                            <i class="fas fa-check-circle me-2"></i>Aprobar conductor
                        </button>
                    </form>
                    <form method="POST" class="mt-2" onsubmit="return confirm('¿Rechazar esta solicitud?')">
                        <input type="hidden" name="action" value="rechazar_conductor">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="fas fa-times-circle me-2"></i>Rechazar solicitud
                        </button>
                    </form>
                </div>
                <?php elseif ($c['verificado'] == 1): ?>
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle me-2"></i><strong>Conductor activo.</strong> Ya tiene acceso al panel.
                </div>
                <?php else: ?>
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-times-circle me-2"></i><strong>Solicitud rechazada.</strong>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Columna derecha: documentos ── -->
            <div class="col-md-8">
                <h5 class="fw-bold mb-3"><i class="fas fa-folder-open me-2 text-warning"></i>Documentos enviados</h5>

                <?php if (empty($docs)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Este conductor aún no ha subido ningún documento.
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($tiposDoc as $tipo => $nombreDoc):
                        $doc = $docs[$tipo] ?? null;
                        $estado = $doc['estado'] ?? 'no_subido';
                    ?>
                    <div class="col-md-6">
                        <div class="doc-card">
                            <!-- Imagen del documento -->
                            <?php if ($doc && !empty($doc['imagen'])): ?>
                                <div class="doc-img"
                                     onclick="verImagen('<?= htmlspecialchars($doc['imagen'], ENT_QUOTES) ?>', '<?= htmlspecialchars($nombreDoc) ?>')">
                                    <img src="data:image/jpeg;base64,<?= $doc['imagen'] ?>"
                                         alt="<?= htmlspecialchars($nombreDoc) ?>">
                                </div>
                            <?php else: ?>
                                <div class="doc-img text-center text-muted flex-column">
                                    <i class="fas fa-file-image fa-3x mb-2 opacity-25"></i>
                                    <small>No subido aún</small>
                                </div>
                            <?php endif; ?>

                            <div class="doc-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <strong class="small"><?= htmlspecialchars($nombreDoc) ?></strong>
                                    <?= estadoBadge($estado) ?>
                                </div>

                                <?php if ($doc && !empty($doc['notas'])): ?>
                                <p class="small text-danger mb-2">
                                    <i class="fas fa-info-circle me-1"></i><?= htmlspecialchars($doc['notas']) ?>
                                </p>
                                <?php endif; ?>

                                <?php if ($doc && $estado !== 'aprobado'): ?>
                                <!-- Aprobar -->
                                <form method="POST" class="mb-1">
                                    <input type="hidden" name="action" value="aprobar_doc">
                                    <input type="hidden" name="tipo"   value="<?= $tipo ?>">
                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                        <i class="fas fa-check me-1"></i>Aprobar
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($doc && $estado !== 'rechazado'): ?>
                                <!-- Rechazar con motivo -->
                                <form method="POST"
                                      onsubmit="return setNota(this, '<?= $tipo ?>')">
                                    <input type="hidden" name="action" value="rechazar_doc">
                                    <input type="hidden" name="tipo"   value="<?= $tipo ?>">
                                    <input type="hidden" name="notas"  id="notas_<?= $tipo ?>" value="">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                        <i class="fas fa-times me-1"></i>Rechazar
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if ($estado === 'aprobado'): ?>
                                <div class="text-center text-success small fw-semibold">
                                    <i class="fas fa-check-circle me-1"></i>Documento verificado
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal para ver imagen a pantalla completa -->
<div class="modal fade" id="imgModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title text-white" id="imgModalTitle"></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2">
                <img id="imgModalSrc" src="" alt="">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function verImagen(base64, titulo) {
    document.getElementById('imgModalSrc').src = 'data:image/jpeg;base64,' + base64;
    document.getElementById('imgModalTitle').textContent = titulo;
    new bootstrap.Modal(document.getElementById('imgModal')).show();
}

function setNota(form, tipo) {
    const motivo = prompt('Motivo del rechazo (opcional):') ?? 'Documento no válido o ilegible';
    document.getElementById('notas_' + tipo).value = motivo;
    return true;
}
</script>
</body>
</html>

<?php
require_once 'db_admin.php';

// Cargar PHPMailer si está disponible (la ruta depende de tu estructura real)
$phpMailerLoaded = false;
$mailerPath1 = __DIR__ . '/../PHPMailer/src/Exception.php';
$mailerPath2 = __DIR__ . '/../PHPMailer/src/PHPMailer.php';
$mailerPath3 = __DIR__ . '/../PHPMailer/src/SMTP.php';
if (file_exists($mailerPath1) && file_exists($mailerPath2) && file_exists($mailerPath3)) {
    require_once $mailerPath1;
    require_once $mailerPath2;
    require_once $mailerPath3;
    $phpMailerLoaded = true;
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$secId = $_GET['id'] ?? 0;
if (!$secId) {
    header('Location: pendientes_secretarias.php');
    exit;
}

$msg = '';

// Aprobar o rechazar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'aprobar_secretaria') {
        $pdo->prepare("UPDATE secretarias SET verificado=1 WHERE id=?")->execute([$secId]);
        
        $msg = 'Secretaria aprobada correctamente. Ya puede iniciar sesión.';
        
        // Enviar Correo Electrónico
        $stmtCorreo = $pdo->prepare("SELECT nombre, email FROM secretarias WHERE id = ?");
        $stmtCorreo->execute([$secId]);
        $dest = $stmtCorreo->fetch(PDO::FETCH_ASSOC);

        if ($dest && !empty($dest['email'])) {
            require_once __DIR__ . '/../email_helper.php';
            
            $subject = 'Cuenta Activada - GeoMove';
            $htmlBody = 'Hola ' . htmlspecialchars($dest['nombre']) . ',<br><br>Tu cuenta de Secretaria en GeoMove ha sido <b>aprobada y activada</b> exitosamente por el administrador.<br><br>Ya puedes ingresar al panel de cooperativa con tu usuario y contraseña.<br><br>Saludos cordiales,<br>El equipo de GeoMove.';
            $plainBody = 'Hola ' . $dest['nombre'] . ', tu cuenta de Secretaria en GeoMove ha sido aprobada y activada exitosamente por el administrador. Ya puedes ingresar al panel de cooperativa. Saludos cordiales.';
            
            list($success, $error) = sendEmailMessage($dest['email'], $subject, $htmlBody, $plainBody);
            if ($success) {
                $msg .= ' Se ha enviado un correo de notificación a la secretaria.';
            } else {
                $msg .= ' (No se pudo enviar correo: ' . $error . ')';
            }
        }

    } elseif ($_POST['action'] === 'rechazar_solicitud') {
        $pdo->prepare("UPDATE secretarias SET verificado=2 WHERE id=?")->execute([$secId]);
        $msg = 'Solicitud de secretaria rechazada.';
    }
}

// Obtener detalles de la secretaría
$stmt = $pdo->prepare("SELECT s.*, c.nombre as cooperativa_nombre FROM secretarias s LEFT JOIN cooperativas c ON s.cooperativa_id = c.id WHERE s.id = ?");
$stmt->execute([$secId]);
$sec = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sec) { header('Location: pendientes_secretarias.php'); exit; }

$isAprobada = ($sec['verificado'] == 1);
$isRechazada = ($sec['verificado'] == 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Ver Secretaria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .doc-image { max-width: 100%; border-radius: 8px; border: 1px solid #dee2e6; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">
        <?php $currentPage = 'secretarias'; include '_sidebar.php'; ?>
        
        <div class="col-md-10 content">
            <div class="mb-4">
                <a href="pendientes_secretarias.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left me-2"></i>Secretarias Pendientes</a>
                <span class="mx-2 text-muted">/</span>
                <span class="text-dark fw-medium"><?= htmlspecialchars($sec['nombre']) ?></span>
            </div>

            <h3 class="fw-bold mb-4 text-dark"><i class="fas fa-id-card me-2" style="color:#6f42c1;"></i>Revisión de Secretaria</h3>

            <?php if ($msg): ?>
                <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm">
                    <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-md-4">
                    <!-- Perfil -->
                    <div class="card border-0 shadow-sm rounded-4 text-center p-4 h-100">
                        <div class="mx-auto bg-light rounded-circle d-flex align-items-center justify-content-center mb-3" style="width:100px; height:100px; font-size: 2.5rem; color:#6f42c1;">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <h4 class="fw-bold"><?= htmlspecialchars($sec['nombre']) ?></h4>
                        <p class="text-muted small"><?= htmlspecialchars($sec['email'] ?? 'Sin correo') ?></p>
                        
                        <?php if ($isAprobada): ?>
                            <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check-circle me-1"></i>Aprobada</span>
                        <?php elseif ($isRechazada): ?>
                            <span class="badge bg-danger rounded-pill px-3 py-2"><i class="fas fa-times-circle me-1"></i>Rechazada</span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark rounded-pill px-3 py-2"><i class="fas fa-clock me-1"></i>Pendiente</span>
                        <?php endif; ?>
                        
                        <hr class="my-4">
                        <div class="text-start">
                            <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>Datos</h6>
                            <p class="mb-2 small"><span class="text-muted d-inline-block" style="width:90px;">Usuario:</span> <span class="fw-medium"><?= htmlspecialchars($sec['usuario']) ?></span></p>
                            <p class="mb-2 small"><span class="text-muted d-inline-block" style="width:90px;">Cooperativa:</span> <span class="fw-medium"><?= htmlspecialchars($sec['cooperativa_nombre']) ?></span></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <!-- Documento y Decision -->
                    <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                        <h5 class="fw-bold mb-3"><i class="fas fa-file-contract me-2 text-secondary"></i>Credencial / Nombramiento</h5>
                        <div class="mb-4 text-center bg-light p-3 rounded">
                            <?php if (!empty($sec['documento_credencial'])): ?>
                                <?php if (strpos($sec['documento_credencial'], 'data:application/pdf') === 0): ?>
                                    <embed src="<?= $sec['documento_credencial'] ?>" type="application/pdf" width="100%" height="400px" class="doc-image"/>
                                    <a href="<?= $sec['documento_credencial'] ?>" download="nombramiento_<?= $sec['id'] ?>.pdf" class="btn btn-sm btn-outline-primary mt-3">Descargar PDF</a>
                                <?php else: ?>
                                    <img src="<?= $sec['documento_credencial'] ?>" class="doc-image" alt="Credencial"/>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted py-5"><i class="fas fa-file-excel fa-2x mb-3 d-block text-danger"></i>El usuario no subió ningún documento.</p>
                            <?php endif; ?>
                        </div>

                        <?php if (!$isAprobada && !$isRechazada): ?>
                            <h6 class="fw-bold mb-3"><i class="fas fa-gavel me-2 text-warning"></i>Decisión final</h6>
                            <p class="text-muted small mb-4">Revisa la credencial antes de aprobar. Una vez aprobada, podrá iniciar sesión.</p>
                            
                            <form method="POST" class="d-flex flex-column gap-2 mt-auto">
                                <input type="hidden" name="action" id="actionSelect" value="">
                                <button type="submit" class="btn btn-success p-3 fw-bold rounded-3 shadow-sm border-0" onclick="document.getElementById('actionSelect').value='aprobar_secretaria';">
                                    <i class="fas fa-check-circle me-2"></i>Aprobar Secretaria (y enviar correo)
                                </button>
                                <button type="submit" class="btn btn-outline-danger p-3 fw-bold rounded-3" onclick="document.getElementById('actionSelect').value='rechazar_solicitud';">
                                    <i class="fas fa-times-circle me-2"></i>Rechazar Solicitud
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>
</body>
</html>

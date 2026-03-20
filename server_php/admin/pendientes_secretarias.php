<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Lógica de aprobar o rechazar rápido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['secretaria_id'])) {
    $secId = (int)$_POST['secretaria_id'];
    if ($_POST['action'] === 'aprobar') {
        $stmt = $pdo->prepare("UPDATE secretarias SET verificado = 1 WHERE id = ?");
        $stmt->execute([$secId]);
        $msg = ['tipo' => 'success', 'texto' => 'Secretaria aprobada correctamente.'];
        // Aquí entraría la lógica de PHPMailer si estuviera integrado en la lista.
        // Pero lo dejaremos para la vista detallada (ver_secretaria.php)
    } elseif ($_POST['action'] === 'rechazar') {
        $stmt = $pdo->prepare("UPDATE secretarias SET verificado = 2 WHERE id = ?");
        $stmt->execute([$secId]);
        $msg = ['tipo' => 'warning', 'texto' => 'Secretaria rechazada.'];
    }
}

// Listar secretarias pendientes
$stmt = $pdo->query("
    SELECT s.id, s.nombre, s.email, s.usuario, c.nombre AS cooperativa_nombre
    FROM secretarias s
    LEFT JOIN cooperativas c ON s.cooperativa_id = c.id
    WHERE s.verificado = 0 OR s.verificado IS NULL
");
$pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'secretarias';
$totalPendientes = count($pendientes);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin – Secretarias Pendientes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .driver-card {
            background: #fff; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,.06);
            padding: 20px 24px; margin-bottom: 16px; border: 1px solid #e9ecef;
        }
        .driver-avatar-placeholder {
            width: 64px; height: 64px; border-radius: 50%;
            background: linear-gradient(135deg,#e83e8c,#6f42c1);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; color: #fff; font-size: 1.6rem;
        }
        .pill-info { background: #f1f5f9; color: #475569; font-size: .78rem; border-radius: 20px; padding: 3px 10px; display: inline-block; }
        .btn-ver { background: linear-gradient(135deg,#e83e8c,#6f42c1); color:#fff; border: none; border-radius: 8px; padding: 6px 16px; font-size: .82rem; font-weight: 600; text-decoration:none; }
        .btn-ver:hover { opacity: .88; color: #fff; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">

<?php include '_sidebar.php'; ?>

        <div class="col-md-10 content">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h2 class="mb-1 fw-bold text-dark"><i class="fas fa-user-tie me-2" style="color:#6f42c1;"></i>Secretarias Pendientes</h2>
                    <p class="text-secondary mb-0">Solicitudes de registro de secretarias de cooperativas</p>
                </div>
                <div class="text-end">
                    <span class="badge" style="background: rgba(111, 66, 193, 0.1); color: #6f42c1; font-size: 1.1rem; padding: 8px 16px;">
                        <?= $totalPendientes ?> En espera
                    </span>
                </div>
            </div>

            <?php if (isset($msg)): ?>
                <div class="alert alert-<?= $msg['tipo'] ?> alert-dismissible fade show border-0 shadow-sm">
                    <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($msg['texto']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <?php if (empty($pendientes)): ?>
                    <div class="col-12">
                        <div class="empty-state">
                            <i class="fas fa-check-circle text-success mb-3" style="font-size: 3rem;"></i>
                            <h4 class="fw-bold">No hay secretarias pendientes</h4>
                            <p class="text-muted">Todas las solicitudes han sido gestionadas.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($pendientes as $p): ?>
                        <div class="col-md-6 col-xxl-4">
                            <div class="driver-card d-flex gap-3 align-items-center">
                                <div class="driver-avatar-placeholder">
                                    <i class="fas fa-user-shield"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="fw-bold mb-1 text-dark"><?= htmlspecialchars($p['nombre']) ?></h5>
                                    <div class="text-muted small mb-2"><i class="fas fa-envelope me-1"></i><?= htmlspecialchars($p['email'] ?? 'Sin correo') ?></div>
                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                        <span class="pill-info"><i class="fas fa-building me-1"></i><?= htmlspecialchars($p['cooperativa_nombre']) ?></span>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="ver_secretaria.php?id=<?= $p['id'] ?>" class="btn-ver w-100 text-center">Ver Credencial & Aprobar</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

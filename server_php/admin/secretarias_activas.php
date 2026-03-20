<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$msg = '';
$msgType = '';

// Lógica para bloquear cuenta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['secretaria_id'])) {
    $secId = (int)$_POST['secretaria_id'];
    if ($_POST['action'] === 'bloquear') {
        $stmt = $pdo->prepare("UPDATE secretarias SET verificado = 2 WHERE id = ?");
        $stmt->execute([$secId]);
        $msg = "La cuenta de la secretaria ha sido bloqueada. Deberá resubir documentos para volver a entrar.";
        $msgType = "warning";
    }
}

// Listar secretarias activas
$stmt = $pdo->query("
    SELECT s.id, s.nombre, s.email, s.usuario, c.nombre AS cooperativa_nombre, s.verificado
    FROM secretarias s
    LEFT JOIN cooperativas c ON s.cooperativa_id = c.id
    WHERE s.verificado = 1
    ORDER BY s.nombre ASC
");
$activas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentPage = 'secretarias_activas';
$totalActivas = count($activas);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin – Secretarias Activas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .driver-card {
            background: #fff; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,0,0,.06);
            padding: 20px 24px; margin-bottom: 16px; border: 1px solid #e9ecef;
        }
        .driver-avatar-placeholder {
            width: 50px; height: 50px; border-radius: 50%;
            background: linear-gradient(135deg,#20c997,#0ca678);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; color: #fff; font-size: 1.2rem;
        }
    </style>
</head>
<body>
<div class="container-fluid p-0">
    <div class="row g-0">

<?php include '_sidebar.php'; ?>

        <div class="col-md-10 content">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h2 class="mb-1 fw-bold text-dark"><i class="fas fa-briefcase me-2" style="color:#20c997;"></i>Secretarias Activas</h2>
                    <p class="text-secondary mb-0">Listado de secretarias operativas en el sistema</p>
                </div>
                <div class="text-end">
                    <span class="badge" style="background: rgba(32, 201, 151, 0.1); color: #20c997; font-size: 1.1rem; padding: 8px 16px;">
                        <?= $totalActivas ?> Activas
                    </span>
                </div>
            </div>

            <?php if ($msg): ?>
                <div class="alert alert-<?= $msgType ?> alert-dismissible fade show border-0 shadow-sm">
                    <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted" style="font-size:0.85rem; letter-spacing:0.5px; text-transform:uppercase;">
                                <tr>
                                    <th class="ps-4 py-3 border-0 rounded-start-4">Secretaria</th>
                                    <th class="py-3 border-0">Cooperativa</th>
                                    <th class="py-3 border-0">Contacto / Usuario</th>
                                    <th class="py-3 border-0">Estado</th>
                                    <th class="text-end pe-4 py-3 border-0 rounded-end-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="border-top-0">
                                <?php if (empty($activas)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">No hay secretarias activas en este momento.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($activas as $a): ?>
                                    <tr>
                                        <td class="ps-4 py-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="driver-avatar-placeholder"><i class="fas fa-user-circle"></i></div>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($a['nombre']) ?></div>
                                            </div>
                                        </td>
                                        <td class="py-3 text-secondary">
                                            <span class="badge bg-light text-dark border"><i class="fas fa-building me-1"></i><?= htmlspecialchars($a['cooperativa_nombre']) ?></span>
                                        </td>
                                        <td class="py-3">
                                            <div class="text-dark small mb-1"><i class="fas fa-envelope me-1 text-muted"></i><?= htmlspecialchars($a['email'] ?? 'Sin correo') ?></div>
                                            <div class="text-muted small"><i class="fas fa-user-tag me-1 text-muted"></i>@<?= htmlspecialchars($a['usuario']) ?></div>
                                        </td>
                                        <td class="py-3">
                                            <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-check-circle me-1"></i>Aprobada</span>
                                        </td>
                                        <td class="text-end pe-4 py-3">
                                            <form method="POST" onsubmit="return confirm('¿Seguro que deseas bloquear a esta secretaria? No podrá iniciar sesión hasta que suba un documento válido nuevamente.');" class="d-inline">
                                                <input type="hidden" name="secretaria_id" value="<?= $a['id'] ?>">
                                                <input type="hidden" name="action" value="bloquear">
                                                <button type="submit" class="btn btn-sm btn-outline-danger px-3 rounded-pill"><i class="fas fa-ban me-1"></i>Bloquear Acceso</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

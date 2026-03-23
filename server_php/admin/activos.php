<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conductor_id'])) {
    $cId = (int)$_POST['conductor_id'];
    if ($_POST['action'] === 'suspender') {
        $stmt = $pdo->prepare("UPDATE conductores SET verificado = 0, estado = 'desconectado' WHERE id = ?");
        $stmt->execute([$cId]);
        $msg = ['tipo' => 'warning', 'texto' => 'Conductor suspendido. Deberá ser aprobado nuevamente.'];
    }
}

$stmt = $pdo->query("
    SELECT c.id, c.nombre, c.telefono, c.cedula, c.estado, c.calificacion_promedio,
           v.marca, v.modelo, v.placa
    FROM conductores c
    LEFT JOIN vehiculos v ON c.id = v.conductor_id
    WHERE c.verificado = 1
    ORDER BY c.nombre ASC
");
$activos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0")->fetchColumn();
$currentPage = 'conductores';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Conductores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

<div class="col-md-10 content">

    <div class="page-header">
        <div>
            <h4 class="page-title"><i class="fas fa-id-badge me-2" style="color:var(--primary)"></i>Conductores Activos</h4>
            <p class="page-sub">Directorio de conductores verificados en la plataforma</p>
        </div>
        <span class="badge-premium badge-primary" style="font-size:13px;padding:7px 16px;">
            <i class="fas fa-users me-1"></i><?= count($activos) ?> conductores
        </span>
    </div>

    <?php if (isset($msg)): ?>
        <div class="alert alert-<?= $msg['tipo'] === 'success' ? 'success' : 'warning' ?> mb-4 d-flex align-items-center gap-2" style="border-radius:10px;border:none;">
            <i class="fas fa-exclamation-triangle"></i>
            <?= htmlspecialchars($msg['texto']) ?>
        </div>
    <?php endif; ?>

    <div class="table-premium">
        <div class="table-title-row">
            <h5><i class="fas fa-list me-2" style="color:var(--primary)"></i>Lista de conductores</h5>
            <a href="pendientes.php" class="btn btn-sm btn-premium">
                <i class="fas fa-user-clock me-1"></i>Ver pendientes
            </a>
        </div>
        <?php if (count($activos) === 0): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-car fa-3x mb-3 opacity-25"></i>
                <p class="mb-0">Aún no hay conductores verificados.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Conductor</th>
                        <th>Cédula / Teléfono</th>
                        <th>Vehículo</th>
                        <th class="text-center">Calificación</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activos as $a):
                        $estado = $a['estado'];
                        $badgeClass = match($estado) {
                            'libre'        => 'badge-success',
                            'ocupado'      => 'badge-warning',
                            default        => 'badge-secondary',
                        };
                        $initials = strtoupper(substr($a['nombre'], 0, 1));
                    ?>
                    <tr>
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar" style="font-size:13px;width:34px;height:34px;"><?= $initials ?></div>
                                <span class="fw-semibold"><?= htmlspecialchars($a['nombre']) ?></span>
                            </div>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($a['cedula']) ?></div>
                            <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars($a['telefono']) ?></div>
                        </td>
                        <td>
                            <?php if ($a['marca']): ?>
                                <span class="fw-semibold"><?= htmlspecialchars($a['marca']) ?> <?= htmlspecialchars($a['modelo']) ?></span><br>
                                <span class="badge-premium badge-secondary" style="font-size:10.5px;"><?= htmlspecialchars($a['placa']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="stars">
                                <?php for ($s=1;$s<=5;$s++): ?>
                                    <i class="fa<?= $s<=round($a['calificacion_promedio']) ? 's' : 'r' ?> fa-star"></i>
                                <?php endfor; ?>
                            </span>
                            <div style="font-size:12px;font-weight:700;"><?= number_format((float)$a['calificacion_promedio'],1) ?></div>
                        </td>
                        <td class="text-center">
                            <span class="badge-premium <?= $badgeClass ?>"><?= ucfirst(htmlspecialchars($estado)) ?></span>
                        </td>
                        <td class="text-center">
                            <div class="d-flex gap-2 justify-content-center">
                                <a href="ver_conductor.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size:12px;border-radius:7px;">
                                    <i class="fas fa-eye me-1"></i>Ver
                                </a>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="conductor_id" value="<?= $a['id'] ?>">
                                    <button type="submit" name="action" value="suspender"
                                        class="btn btn-sm btn-outline-danger" style="font-size:12px;border-radius:7px;"
                                        onclick="return confirm('¿Suspender a este conductor?')">
                                        <i class="fas fa-ban me-1"></i>Suspender
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

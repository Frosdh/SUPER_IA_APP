<?php
require_once 'db_admin.php';

// Validar sesión de secretaria
if (!isset($_SESSION['secretary_logged_in']) || $_SESSION['secretary_logged_in'] !== true) {
    header('Location: login.php?role=secretary');
    exit;
}

$coopId = $_SESSION['cooperativa_id'];
$secName = $_SESSION['secretary_name'];

// Obtener nombre de la cooperativa
$stmt = $pdo->prepare("SELECT nombre FROM cooperativas WHERE id = ?");
$stmt->execute([$coopId]);
$coop = $stmt->fetch();
$coopName = $coop['nombre'] ?? 'Mi Cooperativa';

// Estadísticas rápidas
// 1. Conductores totales de la coop
$stmt = $pdo->prepare("SELECT COUNT(*) FROM conductores WHERE cooperativa_id = ?");
$stmt->execute([$coopId]);
$totalConductores = $stmt->fetchColumn();

// 2. Conductores en línea (libre u ocupado)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM conductores WHERE cooperativa_id = ? AND estado IN ('libre', 'ocupado')");
$stmt->execute([$coopId]);
$conductoresEnLinea = $stmt->fetchColumn();

// 3. Documentos pendientes de revisión
$stmt = $pdo->prepare("SELECT COUNT(*) FROM documentos_conductor d 
                       JOIN conductores c ON d.conductor_id = c.id 
                       WHERE c.cooperativa_id = ? AND d.estado = 'pendiente'");
$stmt->execute([$coopId]);
$documentosPendientes = $stmt->fetchColumn();

// 4. Ganancias de hoy (Viajes terminados de su flota)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(v.tarifa_total), 0) FROM viajes v
                       JOIN conductores c ON v.conductor_id = c.id
                       WHERE c.cooperativa_id = ? AND v.estado = 'terminado' 
                       AND DATE(v.fecha_pedido) = CURDATE()");
$stmt->execute([$coopId]);
$gananciasHoy = $stmt->fetchColumn();

// Obtener lista de conductores
$stmt = $pdo->prepare("SELECT id, nombre, telefono, estado, calificacion_promedio, foto_perfil FROM conductores WHERE cooperativa_id = ? ORDER BY nombre ASC");
$stmt->execute([$coopId]);
$conductores = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel <?= htmlspecialchars($coopName) ?> — GeoMove</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .card-stat { border-radius: 15px; border: none; box-shadow: var(--shadow); transition: 0.3s; background: var(--card); }
        .card-stat:hover { transform: translateY(-5px); }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .bg-libre { background-color: var(--accent); }
        .bg-ocupado { background-color: var(--warning); }
        .bg-desconectado { background-color: var(--text-muted); }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar Compartido -->
            <?php 
            $currentPage = 'dashboard';
            include '_sidebar.php'; 
            ?>

            <!-- Main Content -->
            <div class="col-md-10 main-content p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold">Dashboard de Cooperativa</h2>
                    <span class="badge bg-primary px-3 py-2">Rol: Secretaria</span>
                </div>

                <!-- Stats -->
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="card card-stat p-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3">
                                    <i class="fas fa-users text-primary fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1 small">Total Flota</h6>
                                    <h4 class="mb-0 fw-bold"><?= $totalConductores ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-stat p-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-success bg-opacity-10 p-2 rounded-circle me-3">
                                    <i class="fas fa-satellite-dish text-success fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1 small">En Línea</h6>
                                    <h4 class="mb-0 fw-bold"><?= $conductoresEnLinea ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-stat p-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-warning bg-opacity-10 p-2 rounded-circle me-3">
                                    <i class="fas fa-file-invoice text-warning fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1 small">Pendientes</h6>
                                    <h4 class="mb-0 fw-bold"><?= $documentosPendientes ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card card-stat p-4">
                            <div class="d-flex align-items-center">
                                <div class="bg-info bg-opacity-10 p-2 rounded-circle me-3">
                                    <i class="fas fa-dollar-sign text-info fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="text-muted mb-1 small">Ingresos Hoy</h6>
                                    <h4 class="mb-0 fw-bold">$<?= number_format($gananciasHoy, 2) ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Drivers Table -->
                <div class="card border-0 shadow-sm rounded-4" style="background: var(--card);">
                    <div class="card-header bg-transparent py-3 border-bottom">
                        <h5 class="mb-0 fw-bold">Nuestra Flota de Conductores</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-4">Conductor</th>
                                        <th>Teléfono</th>
                                        <th>Estado</th>
                                        <th>Calificación</th>
                                        <th class="text-end pe-4">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($conductores as $c): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm me-3">
                                                    <?php if($c['foto_perfil']): ?>
                                                        <img src="data:image/jpeg;base64,<?= $c['foto_perfil'] ?>" class="rounded-circle" style="width:40px; height:40px; object-fit:cover;">
                                                    <?php else: ?>
                                                        <div class="bg-secondary rounded-circle text-white d-flex align-items-center justify-content-center" style="width:40px; height:40px;"><i class="fas fa-user"></i></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?= htmlspecialchars($c['nombre']) ?></div>
                                                    <div class="text-muted small">Cédula: <?= htmlspecialchars($c['cedula'] ?? '—') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($c['telefono']) ?></td>
                                        <td>
                                            <span class="status-dot bg-<?= $c['estado'] ?>"></span>
                                            <?= ucfirst($c['estado']) ?>
                                        </td>
                                        <td>
                                            <div class="text-warning">
                                                <i class="fas fa-star"></i> <?= number_format($c['calificacion_promedio'], 1) ?>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="ver_conductor_coop.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">Ver Perfil</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($conductores)): ?>
                                        <tr><td colspan="5" class="text-center py-4">No hay conductores registrados en esta cooperativa.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

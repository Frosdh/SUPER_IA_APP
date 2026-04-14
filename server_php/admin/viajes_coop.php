<?php
// ============================================================
// admin/viajes_coop.php — Historial de viajes de la cooperativa
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['secretary_logged_in']) || $_SESSION['secretary_logged_in'] !== true) {
    header('Location: login_selector.php');
    exit;
}

$coopId = $_SESSION['cooperativa_id'];
$secName = $_SESSION['secretary_name'];

// Obtener nombre de la cooperativa
$stmt = $pdo->prepare("SELECT nombre FROM cooperativas WHERE id = ?");
$stmt->execute([$coopId]);
$coopName = $stmt->fetchColumn() ?? 'Mi Cooperativa';

// Obtener todos los viajes de la flota de esta cooperativa
$stmt = $pdo->prepare("
    SELECT v.id, v.estado, v.fecha_pedido, v.tarifa_total, v.origen_texto, v.destino_texto,
           u.nombre as cliente, u.telefono as cliente_telefono,
           cond.nombre as conductor, cond.telefono as conductor_telefono
    FROM viajes v
    JOIN usuarios u ON v.usuario_id = u.id
    JOIN conductores cond ON v.conductor_id = cond.id
    WHERE cond.cooperativa_id = ?
    ORDER BY v.fecha_pedido DESC
    LIMIT 100
");
$stmt->execute([$coopId]);
$viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Manejo de AJAX ──────────────────────────────────────────
if (isset($_GET['ajax'])) {
    if (count($viajes) === 0) {
        echo '<div class="text-center py-5 text-muted"><i class="fas fa-route fa-3x mb-3 text-secondary opacity-50"></i><h5>No se encontraron registros</h5></div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-4">Fecha</th><th>Cliente</th><th>Conductor</th><th>Ruta</th><th class="text-center">Tarifa</th><th class="text-center">Estado</th></tr></thead><tbody>';
        foreach ($viajes as $v) {
            echo "<tr>
                    <td class='ps-4'><strong>".date('d/m/Y H:i', strtotime($v['fecha_pedido']))."</strong></td>
                    <td><strong>".htmlspecialchars($v['cliente'])."</strong><br><small class='text-muted'>".htmlspecialchars($v['cliente_telefono'])."</small></td>
                    <td><strong>".htmlspecialchars($v['conductor'])."</strong><br><small class='text-muted'>".htmlspecialchars($v['conductor_telefono'])."</small></td>
                    <td>
                        <div class='small text-truncate' style='max-width:200px'><i class='fas fa-circle text-success me-1' style='font-size:8px'></i> ".htmlspecialchars($v['origen_texto'])."</div>
                        <div class='small text-truncate' style='max-width:200px'><i class='fas fa-map-marker-alt text-danger me-1' style='font-size:8px'></i> ".htmlspecialchars($v['destino_texto'])."</div>
                    </td>
                    <td class='text-center fw-bold text-success'>$".number_format((float)$v['tarifa_total'], 2)."</td>
                    <td class='text-center'>".estadoBadge($v['estado'])."</td>
                  </tr>";
        }
        echo '</tbody></table></div>';
    }
    exit;
}

function estadoBadge($e) {
    switch ($e) {
        case 'terminado':
            return '<span class="badge bg-success">Completado</span>';
        case 'cancelado':
            return '<span class="badge bg-danger">Cancelado</span>';
        case 'en_camino':
            return '<span class="badge bg-info text-dark">En camino</span>';
        case 'iniciado':
            return '<span class="badge bg-primary">En curso</span>';
        case 'aceptado':
            return '<span class="badge bg-warning text-dark">Aceptado</span>';
        default:
            return '<span class="badge bg-secondary">Pedido</span>';
    }
}

$currentPage = 'viajes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viajes GeoMove — <?= htmlspecialchars($coopName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php include '_sidebar.php'; ?>
            <div class="col-md-10 content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">Historial de Viajes</h2>
                    <div class="text-end">
                        <small class="text-muted d-block">Cooperativa</small>
                        <span class="fw-bold text-primary"><?= htmlspecialchars($coopName) ?></span>
                    </div>
                </div>

                <!-- Buscador AJAX (Opcional, pero útil) -->
                <div class="card border-0 shadow-sm mb-4 rounded-4" style="background: var(--card);">
                    <div class="card-body">
                        <form id="filter-form" class="row g-3 align-items-end" onsubmit="handleFilter(event)">
                            <div class="col-md-8">
                                <label class="form-label small fw-bold">Búsqueda rápida</label>
                                <input type="text" name="search" class="form-control" placeholder="Buscar por cliente o conductor...">
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Filtrar</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="container-viajes" class="card border-0 shadow-sm rounded-4 overflow-hidden" style="background: var(--card);">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Fecha</th>
                                    <th>Cliente</th>
                                    <th>Conductor</th>
                                    <th>Ruta (Origen -> Destino)</th>
                                    <th class="text-center">Tarifa</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viajes as $v): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= date('d/m/Y H:i', strtotime($v['fecha_pedido'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($v['cliente']) ?></div>
                                        <div class="small text-muted"><i class="fas fa-phone fa-xs"></i> <?= htmlspecialchars($v['cliente_telefono']) ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($v['conductor']) ?></div>
                                        <div class="small text-muted"><i class="fas fa-phone fa-xs"></i> <?= htmlspecialchars($v['conductor_telefono']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($v['origen_texto']) ?>">
                                            <i class="fas fa-circle text-success me-1" style="font-size: 8px;"></i> <?= htmlspecialchars($v['origen_texto']) ?>
                                        </div>
                                        <div class="small text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($v['destino_texto']) ?>">
                                            <i class="fas fa-map-marker-alt text-danger me-1" style="font-size: 8px;"></i> <?= htmlspecialchars($v['destino_texto']) ?>
                                        </div>
                                    </td>
                                    <td class="text-center fw-bold text-success">
                                        $<?= number_format((float)$v['tarifa_total'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?= estadoBadge($v['estado']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($viajes)): ?>
                                    <tr><td colspan="6" class="text-center py-5">No hay registros de viajes para esta cooperativa.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function handleFilter(e) {
            e.preventDefault();
            const form = e.target;
            const params = new URLSearchParams(new FormData(form)).toString();
            GeoMove.fetchWithSkeleton(window.location.pathname + '?' + params, 'container-viajes', 8);
        }
    </script>
</body>
</html>

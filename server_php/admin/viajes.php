<?php
require_once 'db_admin.php';

// Habilitar errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuperAdmin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$isAdmin && !$isSuperAdmin) {
    header('Location: login.php');
    exit;
}

$f_canton = $_GET['canton'] ?? '';
$f_coop   = $_GET['coop_id'] ?? '';
$f_cat    = $_GET['cat_id']   ?? '';
$isHist   = isset($_GET['historico']) && $_GET['historico'] == '1';

$whereExtra = "";
$params = [];
if ($f_canton !== '') {
    $whereExtra .= " AND cond.canton = ?";
    $params[] = $f_canton;
}
if ($f_coop !== '') {
    $whereExtra .= " AND cond.cooperativa_id = ?";
    $params[] = $f_coop;
}
if ($f_cat !== '') {
    $whereExtra .= " AND v.categoria_id = ?";
    $params[] = $f_cat;
}

// Determinar estados según el modo (Histórico vs Activo)
$estadosFiltro = $isHist ? "('completado', 'cancelado')" : "('completado')";

// Obtener viajes - versión simplificada usando tabla viajes disponible
try {
    $stmt = $pdo->prepare("
        SELECT v.id, v.estado, v.fecha_hora as fecha_pedido, v.distancia_km,
               v.nombre_conductor as conductor, 'N/A' as conductor_telefono,
               'Usuario' as cliente, 'N/A' as cliente_telefono,
               'Transporte' AS categoria
        FROM viajes v
        WHERE v.estado IN $estadosFiltro
        ORDER BY v.fecha_hora DESC
        LIMIT " . ($isHist ? "100" : "50") . "
    ");
    $stmt->execute($params);
    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Si falla, mostrar array vacío
    $viajes = [];
}

$currentPage     = $isHist ? 'viajes_hist' : 'viajes_live';

// ── Manejo de AJAX ──────────────────────────────────────────
if (isset($_GET['ajax'])) {
    if (count($viajes) === 0) {
        echo '<div class="text-center py-5 text-muted"><i class="fas fa-car fa-3x mb-3 text-secondary opacity-50"></i><h5>No se encontraron resultados</h5></div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Hora Inicio</th><th>Cliente</th><th>Conductor Asignado</th><th>Tarifa Aprox.</th><th>Estado</th></tr></thead><tbody>';
        foreach ($viajes as $v) {
            $cls = 'est-pedido';
            if($v['estado'] == 'aceptado') $cls = 'est-aceptado';
            if($v['estado'] == 'en_curso') $cls = 'est-encurso';
            echo "<tr>
                    <td>".date('H:i', strtotime($v['fecha_pedido']))."</td>
                    <td>".htmlspecialchars($v['cliente'])."<br><small class='text-muted'><i class='fas fa-phone fa-sm'></i> ".htmlspecialchars($v['cliente_telefono'])."</small></td>
                    <td>".($v['conductor'] ? "<span class='fw-bold'>".htmlspecialchars($v['conductor'])."</span><br><small class='text-muted'><i class='fas fa-phone fa-sm'></i> ".htmlspecialchars($v['conductor_telefono'])."</small>" : "<span class='text-muted fst-italic'>Buscando...</span>")."</td>
                    <td class='fw-bold text-success'>$".number_format((float)$v['tarifa_total'], 2)."</td>
                    <td><span class='badge-estado $cls'>".str_replace('_', ' ', $v['estado'])."</span></td>
                  </tr>";
        }
        echo '</tbody></table></div>';
    }
    exit;
}

try {
    $totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0")->fetchColumn();
} catch (Exception $e) {
    $totalPendientes = count($viajes);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Admin - <?= $isHist ? 'Historial de Viajes' : 'Viajes en Curso' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .data-table { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .table th { background: #f1f3f5; border-bottom: none; color: #495057; font-weight: 600; }
        .table td { vertical-align: middle; border-bottom: 1px solid #f1f3f5; }

        .badge-estado { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .est-pedido { background: #cff4fc; color: #055160; }
        .est-aceptado { background: #fff3cd; color: #856404; }
        .est-encurso { background: #d1e7dd; color: #0f5132; }
        .est-terminado { background: #cfe2ff; color: #084298; }
        .est-cancelado { background: #f8d7da; color: #842029; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">

<?php include '_sidebar.php'; ?>

            <div class="col-md-10 content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="fw-bold mb-0">
                        <i class="fas <?= $isHist ? 'fa-history' : 'fa-route' ?> me-2 text-primary"></i>
                        <?= $isHist ? 'Historial de Viajes' : 'Viajes en Curso' ?>
                    </h2>
                </div>
                
                <?php if ($isHist): ?>
                    <p class="text-muted small mb-4">Consulta los servicios ya finalizados o cancelados en la plataforma.</p>
                <?php else: ?>
                    <p class="text-muted small mb-4">Monitoreo en tiempo real de los servicios que se están realizando o solicitando ahora.</p>
                <?php endif; ?>

                <!-- Filtros -->
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <form id="filter-form" method="GET" class="row g-3 align-items-end" onsubmit="handleFilter(event)">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Cantón</label>
                                <select name="canton" class="form-select">
                                    <option value="">Todos</option>
                                    <?php
                                    try {
                                        $cantones = $pdo->query("SELECT DISTINCT canton FROM conductores WHERE canton IS NOT NULL")->fetchAll();
                                        foreach($cantones as $c) {
                                            $sel = ($f_canton == $c['canton']) ? 'selected' : '';
                                            echo "<option value='{$c['canton']}' $sel>{$c['canton']}</option>";
                                        }
                                    } catch (Exception $e) {
                                        // Si falla, no mostrar opciones adicionales
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Cooperativa</label>
                                <select name="coop_id" class="form-select">
                                    <option value="">Todas</option>
                                    <?php
                                    try {
                                        $coops = $pdo->query("SELECT DISTINCT cooperativa_id as id, ' Coop ' || CAST(cooperativa_id as CHAR) as nombre FROM viajes WHERE cooperativa_id > 0")->fetchAll();
                                        foreach($coops as $co) {
                                            $sel = ($f_coop == $co['id']) ? 'selected' : '';
                                            echo "<option value='{$co['id']}' $sel>{$co['nombre']}</option>";
                                        }
                                    } catch (Exception $e) {
                                        // Si falla, no mostrar opciones adicionales
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold">Tipo</label>
                                <select name="cat_id" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="1">Transporte</option>
                                    <option value="2">Servicios</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Filtrar</button>
                            </div>
                            <div class="col-md-2">
                                <a href="viajes.php<?= $isHist ? '?historico=1' : '' ?>" class="btn btn-outline-secondary w-100">Limpiar</a>
                            </div>
                            <?php if ($isHist): ?>
                                <input type="hidden" name="historico" value="1">
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div id="container-viajes" class="data-table p-3">
                    <?php if (count($viajes) === 0): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-car fa-3x mb-3 text-secondary opacity-50"></i>
                            <h5>No hay viajes activos en este momento</h5>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Hora Inicio</th>
                                        <th>Cliente</th>
                                        <th>Conductor Asignado</th>
                                        <th>Tarifa Aprox.</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($viajes as $v): 
                                        $cls = 'est-pedido';
                                        if($v['estado'] == 'aceptado') $cls = 'est-aceptado';
                                        if($v['estado'] == 'en_curso') $cls = 'est-encurso';
                                    ?>
                                    <tr>
                                        <td><?= date('H:i', strtotime($v['fecha_pedido'])) ?></td>
                                        <td>
                                            <?= htmlspecialchars($v['cliente']) ?><br>
                                            <small class="text-muted"><i class="fas fa-phone fa-sm"></i> <?= htmlspecialchars($v['cliente_telefono']) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($v['conductor']): ?>
                                                <span class="fw-bold"><?= htmlspecialchars($v['conductor']) ?></span><br>
                                                <small class="text-muted"><i class="fas fa-phone fa-sm"></i> <?= htmlspecialchars($v['conductor_telefono']) ?></small>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Buscando conductor...</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold text-success"><?= number_format((float)$v['distancia_km'], 2) ?> km</td>
                                        <td><span class="badge-estado <?= $cls ?>"><?= str_replace('_', ' ', ucfirst($v['estado'])) ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
        function handleFilter(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const params = new URLSearchParams(formData).toString();
            const currentUrl = window.location.pathname + '?' + params;
            
            // Usar nuestro helper global
            Super_IA.fetchWithSkeleton(currentUrl, 'container-viajes', 6);
            
            // Actualizar URL sin recargar
            window.history.pushState({}, '', currentUrl);
        }
    </script>
</body>
</html>

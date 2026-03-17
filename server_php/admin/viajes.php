<?php
require_once 'db_admin.php';

// Habilitar errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Obtener viajes activos (pedido, aceptado, en_curso)
$stmt = $pdo->query("
    SELECT v.id, v.estado, v.fecha_pedido, v.tarifa_total,
           u.nombre as cliente, u.telefono as cliente_telefono,
           cond.nombre as conductor, cond.telefono as conductor_telefono
    FROM viajes v
    JOIN usuarios u ON v.usuario_id = u.id
    LEFT JOIN conductores cond ON v.conductor_id = cond.id
    WHERE v.estado IN ('pedido', 'aceptado', 'en_curso')
    ORDER BY v.fecha_pedido DESC
");
$viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin - Viajes en Curso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #24243e; min-height: 100vh; color: #fff; padding-top: 20px; }
        .sidebar a { color: #ccc; text-decoration: none; display: block; padding: 15px 20px; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.1); color: #fff; border-left: 4px solid #6b11ff; }
        .sidebar i { margin-right: 10px; width: 20px; text-align: center; }
        .content { padding: 30px; }
        .data-table { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .table th { background: #f1f3f5; border-bottom: none; color: #495057; font-weight: 600; }
        .table td { vertical-align: middle; border-bottom: 1px solid #f1f3f5; }
        
        .badge-estado { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .est-pedido { background: #cff4fc; color: #055160; }
        .est-aceptado { background: #fff3cd; color: #856404; }
        .est-encurso { background: #d1e7dd; color: #0f5132; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <div class="col-md-2 sidebar">
                <h4 class="text-center mb-4 font-weight-bold">GeoMove Admin</h4>
                <a href="index.php"><i class="fas fa-home"></i> Inicio</a>
                <a href="pendientes.php"><i class="fas fa-user-clock"></i> Pendientes</a>
                <a href="activos.php"><i class="fas fa-users"></i> Conductores Activos</a>
                <a href="viajes.php" class="active"><i class="fas fa-route"></i> Viajes en Curso</a>
                <a href="logout.php" class="text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
            </div>
            
            <div class="col-md-10 content">
                <h2 class="mb-4 fw-bold">Monitoreo de Viajes Activos</h2>

                <div class="data-table p-3">
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
                                        <th>ID Viaje</th>
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
                                        <td class="text-muted fw-bold">#<?= $v['id'] ?></td>
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
                                        <td class="fw-bold text-success">$<?= number_format((float)$v['tarifa_total'], 2) ?></td>
                                        <td><span class="badge-estado <?= $cls ?>"><?= str_replace('_', ' ', $v['estado']) ?></span></td>
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
</body>
</html>

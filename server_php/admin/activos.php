<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Funcionalidad para suspender/remover
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['conductor_id'])) {
    $cId = (int)$_POST['conductor_id'];
    if ($_POST['action'] === 'suspender') {
        // En lugar de borrar conectamos a verificado = 0 y estado inactivo
        $stmt = $pdo->prepare("UPDATE conductores SET verificado = 0, estado = 'inactivo' WHERE id = ?");
        $stmt->execute([$cId]);
        $msg = "Conductor suspendido. Deberá ser aprobado nuevamente.";
    }
}

// Obtener conductores probados
$stmt = $pdo->query("
    SELECT c.id, c.nombre, c.telefono, c.cedula, c.estado, c.calificacion_promedio,
           v.marca, v.modelo, v.placa
    FROM conductores c
    LEFT JOIN vehiculos v ON c.id = v.conductor_id
    WHERE c.verificado = 1
    ORDER BY c.nombre ASC
");
$activos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin - Conductores Activos</title>
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
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-libre { background: #d1e7dd; color: #0f5132; }
        .status-ocupado { background: #fff3cd; color: #856404; }
        .status-inactivo { background: #f8d7da; color: #842029; }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <div class="col-md-2 sidebar">
                <h4 class="text-center mb-4 font-weight-bold">GeoMove Admin</h4>
                <a href="index.php"><i class="fas fa-home"></i> Inicio</a>
                <a href="pendientes.php"><i class="fas fa-user-clock"></i> Pendientes</a>
                <a href="activos.php" class="active"><i class="fas fa-users"></i> Conductores Activos</a>
                <a href="viajes.php"><i class="fas fa-route"></i> Viajes en Curso</a>
                <a href="logout.php" class="text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
            </div>
            
            <div class="col-md-10 content">
                <h2 class="mb-4 fw-bold">Directorio de Conductores Activos</h2>
                
                <?php if (isset($msg)): ?>
                    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <div class="data-table p-3">
                    <?php if (count($activos) === 0): ?>
                        <div class="text-center py-4 text-muted">Aún no hay conductores verificados.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Conductor</th>
                                        <th>Cédula / Teléfono</th>
                                        <th>Vehículo asociado</th>
                                        <th>Calificación</th>
                                        <th>Estado Actual</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activos as $a): 
                                        $badgeClass = 'status-inactivo';
                                        if($a['estado'] == 'libre') $badgeClass = 'status-libre';
                                        if($a['estado'] == 'ocupado') $badgeClass = 'status-ocupado';
                                    ?>
                                    <tr>
                                        <td class="text-muted">#<?= $a['id'] ?></td>
                                        <td class="fw-bold"><?= htmlspecialchars($a['nombre']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($a['cedula']) ?><br>
                                            <small class="text-muted"><?= htmlspecialchars($a['telefono']) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($a['marca']) ?> <?= htmlspecialchars($a['modelo']) ?> <br>
                                            <small class="fw-bold"><?= htmlspecialchars($a['placa']) ?></small>
                                        </td>
                                        <td>
                                            <i class="fas fa-star text-warning"></i> <?= number_format((float)$a['calificacion_promedio'], 1) ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $badgeClass ?>"><?= strtoupper(htmlspecialchars($a['estado'])) ?></span>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="conductor_id" value="<?= $a['id'] ?>">
                                                <button type="submit" name="action" value="suspender" class="btn btn-sm btn-outline-danger rounded-pill" onclick="return confirm('¿Suspender al conductor? Perderá el acceso y tendrá que ser aprobado de nuevo.');">
                                                    Suspender
                                                </button>
                                            </form>
                                        </td>
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

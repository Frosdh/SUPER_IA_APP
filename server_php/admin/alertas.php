<?php
require_once 'db_admin.php';

// Verificar sesión según rol
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
    $user_id = $_SESSION['super_admin_id'];
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
    $user_id = $_SESSION['admin_id'];
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
    $user_id = $_SESSION['supervisor_id']; // ID de la tabla supervisor (UUID)
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    $user_id = $_SESSION['asesor_id']; // ID de la tabla asesor (UUID)
} else {
    header('Location: login.php?role=admin');
    exit;
}

// ======================
// 1. Alertas de modificaciones de tareas (tabla alerta_modificacion)
// ======================
if ($user_role === 'super_admin' || $user_role === 'admin') {
    // Admin ve todas las modificaciones
    $sqlAlertas = "
        SELECT 
            am.id as id_alerta,
            'Modificación de tarea' as tipo,
            CONCAT('La tarea ', t.id, ' fue modificada por el asesor ', u_asesor.nombre) as mensaje,
            cp.nombre as cliente_nombre,
            u_asesor.nombre as asesor_nombre,
            am.created_at as fecha,
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        JOIN asesor a ON am.asesor_id = a.id
        JOIN usuario u_asesor ON a.usuario_id = u_asesor.id
        ORDER BY am.created_at DESC
    ";
    $stmt = $pdo->query($sqlAlertas);
    $alertas = $stmt->fetchAll();
    $col_asesor = true;

} elseif ($user_role === 'supervisor') {
    // Supervisor ve modificaciones de sus asesores
    $sqlAlertas = "
        SELECT 
            am.id as id_alerta,
            'Modificación de tarea' as tipo,
            CONCAT('La tarea ', t.id, ' fue modificada por el asesor ', u_asesor.nombre) as mensaje,
            cp.nombre as cliente_nombre,
            u_asesor.nombre as asesor_nombre,
            am.created_at as fecha,
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        JOIN asesor a ON am.asesor_id = a.id
        JOIN usuario u_asesor ON a.usuario_id = u_asesor.id
        WHERE a.supervisor_id = :supervisor_id
        ORDER BY am.created_at DESC
    ";
    $stmt = $pdo->prepare($sqlAlertas);
    $stmt->execute([':supervisor_id' => $user_id]);
    $alertas = $stmt->fetchAll();
    $col_asesor = true;

} else { // asesor
    // Asesor ve solo las alertas relacionadas con sus propias tareas
    $sqlAlertas = "
        SELECT 
            am.id as id_alerta,
            'Modificación de tarea' as tipo,
            CONCAT('La tarea ', t.id, ' fue modificada') as mensaje,
            cp.nombre as cliente_nombre,
            am.created_at as fecha,
            CASE WHEN am.vista_supervisor = 0 THEN 'abierta' ELSE 'cerrada' END as estado
        FROM alerta_modificacion am
        JOIN tarea t ON am.tarea_id = t.id
        LEFT JOIN cliente_prospecto cp ON t.cliente_prospecto_id = cp.id
        WHERE am.asesor_id = :asesor_id
        ORDER BY am.created_at DESC
    ";
    $stmt = $pdo->prepare($sqlAlertas);
    $stmt->execute([':asesor_id' => $user_id]);
    $alertas = $stmt->fetchAll();
    $col_asesor = false;
}

// ======================
// 2. (Opcional) Alertas adicionales: tareas vencidas
// ======================
// Puedes agregar aquí tareas con fecha_programada < CURDATE() y estado != 'completada'
// y fusionarlas con $alertas. Por simplicidad no lo incluyo, pero puedes añadirlo.

// ======================
// Estadísticas para el resumen
// ======================
$total_alertas = count($alertas);
$pendientes = 0;
$revisadas = 0;
foreach ($alertas as $a) {
    if ($a['estado'] === 'abierta') $pendientes++;
    else $revisadas++;
}
$stats = [
    'total_alertas' => $total_alertas,
    'pendientes' => $pendientes,
    'revisadas' => $revisadas
];

$currentPage = 'alertas';
$is_supervisor_ui = ($user_role === 'supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Alertas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Tus estilos actuales (los copias tal cual de tu archivo) */
        /* ... (los mismos que tenías, no los repito por longitud) ... */
    </style>
</head>
<body>

<!-- SIDEBAR (exactamente igual que en tu código original, no lo repito) -->
<!-- ... -->

<div class="main-content">
    <div class="navbar-custom"><!-- igual --></div>
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-bell me-2"></i>Centro de Alertas</h1>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_alertas']; ?></div>
                <div class="label">Total de Alertas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #ef4444;"><?php echo $stats['pendientes']; ?></div>
                <div class="label">Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #10b981;"><?php echo $stats['revisadas']; ?></div>
                <div class="label">Revisadas</div>
            </div>
        </div>

        <!-- Tabla de alertas -->
        <div class="table-card">
            <div class="card-header-custom">
                <h6>⚠️ Listado de Alertas</h6>
            </div>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tipo</th>
                        <th>Mensaje</th>
                        <th>Cliente</th>
                        <?php if ($col_asesor): ?>
                        <th>Asesor Asignado</th>
                        <?php endif; ?>
                        <th>Fecha</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alertas)): ?>
                    <tr>
                        <td colspan="<?php echo $col_asesor ? 8 : 7; ?>" class="text-center py-4">
                            <i class="fas fa-check-circle me-2" style="color: #10b981;"></i>No hay alertas pendientes
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($alertas as $alerta): ?>
                        <tr>
                            <td><strong>#<?php echo htmlspecialchars(substr($alerta['id_alerta'], 0, 8)); ?></strong></td>
                            <td><span class="badge" style="background: #3182fe;"><?php echo htmlspecialchars($alerta['tipo']); ?></span></td>
                            <td><?php echo htmlspecialchars(substr($alerta['mensaje'], 0, 80) . (strlen($alerta['mensaje']) > 80 ? '…' : '')); ?></td>
                            <td><?php echo htmlspecialchars($alerta['cliente_nombre'] ?? 'Sin cliente'); ?></td>
                            <?php if ($col_asesor): ?>
                            <td><?php echo htmlspecialchars($alerta['asesor_nombre'] ?? 'N/A'); ?></td>
                            <?php endif; ?>
                            <td><?php echo date('d/m/Y H:i', strtotime($alerta['fecha'])); ?></td>
                            <td>
                                <?php if ($alerta['estado'] === 'abierta'): ?>
                                    <span class="badge" style="background: #ef4444;">⏳ Abierta</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #10b981;">✓ Cerrada</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="#" class="btn btn-sm btn-outline-primary" title="Ver detalles">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
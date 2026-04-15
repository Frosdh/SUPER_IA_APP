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
    // En login.php, se guarda usuario.id
    $user_id = $_SESSION['supervisor_id'];
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
    // En login.php, se guarda usuario.id
    $user_id = $_SESSION['asesor_id'];
} else {
    header('Location: login.php?role=admin');
    exit;
}

// Resolver IDs reales de tablas supervisor/asesor cuando la sesión guarda usuario.id
$supervisor_table_id = null;
$asesor_table_id = null;
if ($user_role === 'supervisor') {
    $stmtSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = :uid LIMIT 1');
    $stmtSup->execute([':uid' => $user_id]);
    $supervisor_table_id = $stmtSup->fetchColumn();
}
if ($user_role === 'asesor') {
    $stmtAs = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = :uid LIMIT 1');
    $stmtAs->execute([':uid' => $user_id]);
    $asesor_table_id = $stmtAs->fetchColumn();
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
    $stmt->execute([':supervisor_id' => $supervisor_table_id ?: '']);
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
    $stmt->execute([':asesor_id' => $asesor_table_id ?: '']);
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

$currentPage        = 'alertas';
$alertas_pendientes = 0; // ya está en la página activa, no hace falta badge aquí
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
$is_supervisor_ui = ($user_role === 'supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Alertas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
<?php if ($is_supervisor_ui): ?>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-gray: #6b7280;
            --brand-border: #d7e0ea;
            --brand-card: #ffffff;
            --brand-bg: #f4f6f9;
            --brand-shadow: 0 16px 34px rgba(18, 58, 109, 0.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: linear-gradient(180deg, #f8fafc 0%, var(--brand-bg) 100%); display: flex; height: 100vh; color: var(--brand-navy-deep); }
        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 22px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin: 18px 0 26px; }
        .stat-card { background: var(--brand-card); border-radius: 18px; border: 1px solid var(--brand-border); box-shadow: var(--brand-shadow); padding: 18px; text-align: center; }
        .stat-card .number { font-size: 34px; font-weight: 900; color: var(--brand-navy-deep); line-height: 1; }
        .stat-card .label { margin-top: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: var(--brand-gray); font-weight: 700; }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 800; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: var(--brand-gray); border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: rgba(215,224,234,0.55); }
        .table tbody tr:hover { background: rgba(255,221,0,0.06); }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php else: ?>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar { width: 230px; background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #6b11ff, #7c3aed); color: #fff; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) { .sidebar { width: 200px; } .main-content { margin-left: 200px; } }
        @media (max-width: 768px) { .sidebar { width: 180px; } .main-content { margin-left: 180px; } }
        .navbar-custom { background: linear-gradient(135deg, #6b11ff, #3182fe); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 22px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin: 18px 0 26px; }
        .stat-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 18px; text-align: center; }
        .stat-card .number { font-size: 34px; font-weight: 800; color: #111827; line-height: 1; }
        .stat-card .label { margin-top: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: #6b7280; font-weight: 700; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
<?php endif; ?>
    </style>
</head>
<body>

<!-- SIDEBAR -->
<?php if ($user_role === 'supervisor'): require_once '_sidebar_supervisor.php'; else: ?>
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> Super_IA
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <?php if ($user_role === 'supervisor'): ?>
        <a href="supervisor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php elseif ($user_role === 'super_admin'): ?>
        <a href="super_admin_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php elseif ($user_role === 'admin'): ?>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <a href="mapa_calor.php" class="sidebar-link">
            <i class="fas fa-fire"></i> Mapa de Calor
        </a>
        <a href="historial_rutas.php" class="sidebar-link">
            <i class="fas fa-history"></i> Historial de Viajes
        </a>
        <?php else: ?>
        <a href="<?php echo ($user_role === 'supervisor') ? 'supervisor_index.php' : 'asesor_index.php'; ?>" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
        <?php endif; ?>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <?php if ($user_role === 'super_admin' || $user_role === 'admin'): ?>
        <a href="usuarios.php" class="sidebar-link">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <?php endif; ?>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> <?php echo ($user_role === 'asesor') ? 'Mis ' : ''; ?>Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> <?php echo ($user_role === 'asesor') ? 'Mis ' : ''; ?>Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link active">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>

    <?php if ($user_role === 'supervisor'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Mi Equipo</div>
        <a href="mis_asesores.php" class="sidebar-link">
            <i class="fas fa-users"></i> Mis Asesores
        </a>
        <a href="registro_asesor.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link">
            <i class="fas fa-file-circle-check"></i> Solicitudes de Asesor
        </a>
    </div>
    <?php endif; ?>

    <?php if ($user_role === 'super_admin'): ?>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link">
            <i class="fas fa-file-alt"></i> Solicitudes de Admin
        </a>
    </div>
    <?php endif; ?>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Configuración</div>
        <a href="#" class="sidebar-link">
            <i class="fas fa-cog"></i> Configuración
        </a>
    </div>
</div>
<?php endif; ?>

<div class="main-content">
    <div class="navbar-custom">
        <?php if ($user_role === 'supervisor'): ?>
            <h2><i class="fas fa-shield-halved me-2" style="color: var(--brand-yellow);"></i>Super_IA - Supervisor</h2>
        <?php else: ?>
            <h2><?php echo $user_role === 'super_admin' ? '👑' : '🎯'; ?> Super_IA 
                <?php 
                if ($user_role === 'super_admin') echo '- SuperAdministrador';
                elseif ($user_role === 'admin') echo '- Admin';
                elseif ($user_role === 'supervisor') echo '- Supervisor';
                else echo '- Asesor';
                ?>
            </h2>
        <?php endif; ?>
        <div class="user-info">
            <div>
                <strong>
                    <?php 
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_nombre']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_nombre']);
                    elseif ($user_role === 'supervisor') echo htmlspecialchars($_SESSION['supervisor_nombre']);
                    else echo htmlspecialchars($_SESSION['asesor_nombre']);
                    ?>
                </strong><br>
                <small>
                    <?php 
                    if ($user_role === 'super_admin') echo htmlspecialchars($_SESSION['super_admin_rol']);
                    elseif ($user_role === 'admin') echo htmlspecialchars($_SESSION['admin_rol']);
                    elseif ($user_role === 'supervisor') echo htmlspecialchars($_SESSION['supervisor_rol']);
                    else echo htmlspecialchars($_SESSION['asesor_rol']);
                    ?>
                </small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
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
<?php
require_once 'db_admin.php';

// Verificar sesión de super_admin o admin
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
    $user_id = $_SESSION['super_admin_id'];
    $user_nombre = $_SESSION['super_admin_nombre'];
    $user_rol = $_SESSION['super_admin_rol'];
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
    $user_id = $_SESSION['admin_id'];
    $user_nombre = $_SESSION['admin_nombre'];
    $user_rol = $_SESSION['admin_rol'];
} else {
    header('Location: login.php?role=admin');
    exit;
}

// Obtener todos los usuarios
$usuarios = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, r.nombre as rol, u.activo
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    ORDER BY u.nombres ASC
")->fetchAll();

// Obtener todos los admins
$admins = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, r.nombre as rol, u.activo
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE u.id_rol_fk = 2
    ORDER BY u.nombres ASC
")->fetchAll();

// Obtener todos los supervisores
$supervisores = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, r.nombre as rol, u.activo
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE u.id_rol_fk = 3
    ORDER BY u.nombres ASC
")->fetchAll();

// Obtener todos los asesores
$asesores = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, r.nombre as rol, u.activo
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE u.id_rol_fk = 4
    ORDER BY u.nombres ASC
")->fetchAll();

// Obtener cooperativas con supervisores
$cooperativas = $pdo->query("
    SELECT DISTINCT c.id_cooperativa, c.nombre
    FROM cooperativa c
    ORDER BY c.nombre ASC
")->fetchAll();

// Obtener supervisores por cooperativa
$supervisoresPorCoop = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, r.nombre as rol, u.activo
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE u.id_rol_fk = 3 AND u.activo = 1
    ORDER BY u.nombres ASC
")->fetchAll();

// Obtener todos los admins para la jerarquía
$adminsHierarquia = $pdo->query("
    SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, r.nombre as rol
    FROM usuarios u
    JOIN roles r ON u.id_rol_fk = r.id_rol
    WHERE u.id_rol_fk IN (1, 2)
    ORDER BY u.nombres ASC
")->fetchAll();

$currentPage = 'usuarios';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #7c3aed; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #6b11ff, #7c3aed); color: #fff; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) {
            .sidebar { width: 200px; }
            .main-content { margin-left: 200px; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 180px; }
            .main-content { margin-left: 180px; }
        }
        .navbar-custom { background: linear-gradient(135deg, #6b11ff, #3182fe); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        .badge-success { background: #10b981; }
        .badge-danger { background: #ef4444; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> COAC Finance
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="<?php echo ($user_role === 'super_admin') ? 'super_admin_index.php' : 'index.php'; ?>" class="sidebar-link">
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
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <a href="usuarios.php" class="sidebar-link active">
            <i class="fas fa-users"></i> Usuarios
        </a>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>

    <div class="sidebar-section">
        <div class="sidebar-section-title">Configuración</div>
        <a href="#" class="sidebar-link">
            <i class="fas fa-cog"></i> Configuración
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><?php echo ($user_role === 'super_admin') ? '👑' : '🎯'; ?> COAC Finance - <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($user_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($user_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-users me-2"></i>Usuarios del Sistema</h1>
            <p class="text-muted mt-2">Total de usuarios: <strong><?php echo count($usuarios); ?></strong></p>
        </div>

        <!-- TABS -->
        <ul class="nav nav-tabs mb-4" id="usuariosTabs" style="background: white; border-radius: 12px 12px 0 0; padding: 15px; flex-wrap: wrap;">
            <li class="nav-item">
                <a class="nav-link active" id="tab-todos" data-bs-toggle="tab" href="#todas" role="tab">
                    <i class="fas fa-list me-2"></i>Todos (<?php echo count($usuarios); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="tab-admins" data-bs-toggle="tab" href="#admins" role="tab">
                    <i class="fas fa-crown me-2"></i>Admins (<?php echo count($admins); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="tab-supervisores" data-bs-toggle="tab" href="#supervisores" role="tab">
                    <i class="fas fa-user-tie me-2"></i>Supervisores (<?php echo count($supervisores); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="tab-asesores" data-bs-toggle="tab" href="#asesores" role="tab">
                    <i class="fas fa-briefcase me-2"></i>Asesores (<?php echo count($asesores); ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="tab-jerarquia" data-bs-toggle="tab" href="#jerarquia" role="tab">
                    <i class="fas fa-sitemap me-2"></i>Jerarquía
                </a>
            </li>
        </ul>

        <!-- TAB CONTENT -->
        <div class="tab-content" id="usuariosTabContent">
            
            <!-- TAB 1: TODOS LOS USUARIOS -->
            <div class="tab-pane fade show active" id="todas" role="tabpanel">
                <div class="table-card">
                    <div class="card-header-custom">
                        <h6>👥 Listado Completo</h6>
                    </div>
                    
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Nombre Completo</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td><strong><?php echo $usuario['id_usuario']; ?></strong></td>
                                <td><?php echo htmlspecialchars($usuario['usuario']); ?></td>
                                <td><?php echo htmlspecialchars($usuario['nombres'] . ' ' . $usuario['apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                <td>
                                    <span class="badge" style="background: linear-gradient(135deg, #6b11ff, #7c3aed); color: white;">
                                        <?php echo htmlspecialchars($usuario['rol']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($usuario['activo']): ?>
                                        <span class="badge badge-success">✓ Activo</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">✗ Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="#" class="btn btn-sm btn-outline-primary" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: ADMINS -->
            <div class="tab-pane fade" id="admins" role="tabpanel">
                <div class="table-card">
                    <div class="card-header-custom">
                        <h6>👑 Administradores del Sistema</h6>
                    </div>
                    
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Nombre Completo</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($admins) > 0): ?>
                                <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td><strong><?php echo $admin['id_usuario']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($admin['usuario']); ?></td>
                                    <td><?php echo htmlspecialchars($admin['nombres'] . ' ' . $admin['apellidos']); ?></td>
                                    <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                    <td>
                                        <span class="badge" style="background: linear-gradient(135deg, #dc2626, #991b1b); color: white;">
                                            <?php echo htmlspecialchars($admin['rol']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($admin['activo']): ?>
                                            <span class="badge badge-success">✓ Activo</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">✗ Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="#" class="btn btn-sm btn-outline-primary" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #9ca3af; padding: 30px;">
                                        <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                        No hay administradores registrados
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 3: SUPERVISORES -->
            <div class="tab-pane fade" id="supervisores" role="tabpanel">
                <div class="table-card">
                    <div class="card-header-custom">
                        <h6>👔 Supervisores</h6>
                    </div>
                    
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Nombre Completo</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($supervisores) > 0): ?>
                                <?php foreach ($supervisores as $supervisor): ?>
                                <tr>
                                    <td><strong><?php echo $supervisor['id_usuario']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($supervisor['usuario']); ?></td>
                                    <td><?php echo htmlspecialchars($supervisor['nombres'] . ' ' . $supervisor['apellidos']); ?></td>
                                    <td><?php echo htmlspecialchars($supervisor['email']); ?></td>
                                    <td>
                                        <span class="badge" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white;">
                                            <?php echo htmlspecialchars($supervisor['rol']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($supervisor['activo']): ?>
                                            <span class="badge badge-success">✓ Activo</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">✗ Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="#" class="btn btn-sm btn-outline-primary" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #9ca3af; padding: 30px;">
                                        <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                        No hay supervisores registrados
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 4: ASESORES -->
            <div class="tab-pane fade" id="asesores" role="tabpanel">
                <div class="table-card">
                    <div class="card-header-custom">
                        <h6>💼 Asesores</h6>
                    </div>
                    
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Nombre Completo</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($asesores) > 0): ?>
                                <?php foreach ($asesores as $asesor): ?>
                                <tr>
                                    <td><strong><?php echo $asesor['id_usuario']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($asesor['usuario']); ?></td>
                                    <td><?php echo htmlspecialchars($asesor['nombres'] . ' ' . $asesor['apellidos']); ?></td>
                                    <td><?php echo htmlspecialchars($asesor['email']); ?></td>
                                    <td>
                                        <span class="badge" style="background: linear-gradient(135deg, #10b981, #059669); color: white;">
                                            <?php echo htmlspecialchars($asesor['rol']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($asesor['activo']): ?>
                                            <span class="badge badge-success">✓ Activo</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">✗ Inactivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="#" class="btn btn-sm btn-outline-primary" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #9ca3af; padding: 30px;">
                                        <i class="fas fa-inbox" style="font-size: 24px; margin-bottom: 10px; display: block;"></i>
                                        No hay asesores registrados
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 5: JERARQUÍA ORGANIZACIONAL -->
            <div class="tab-pane fade" id="jerarquia" role="tabpanel">
                <div class="table-card" style="background: #fff;">
                    <div class="card-header-custom">
                        <h6>🏢 Estructura Organizacional: Admin → Supervisores → Asesores</h6>
                    </div>
                    
                    <div style="padding: 25px;">
                        <!-- ADMINS -->
                        <?php if (count($adminsHierarquia) > 0): ?>
                            <?php foreach ($adminsHierarquia as $admin): ?>
                            <div class="org-item" style="margin-bottom: 30px;">
                                <div style="display: flex; align-items: center; padding: 15px; background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; border-radius: 8px; margin-bottom: 15px;">
                                    <i class="fas fa-crown" style="font-size: 20px; margin-right: 12px;"></i>
                                    <div style="flex: 1;">
                                        <strong><?php echo htmlspecialchars($admin['nombres'] . ' ' . $admin['apellidos']); ?></strong>
                                        <div style="font-size: 12px; opacity: 0.9;">Administrador (<?php echo htmlspecialchars($admin['rol']); ?>)</div>
                                    </div>
                                    <span style="font-size: 12px; background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 12px;">
                                        ID: <?php echo $admin['id_usuario']; ?>
                                    </span>
                                </div>

                                <!-- SUPERVISORES -->
                                <div style="margin-left: 20px; border-left: 2px solid #e5e7eb; padding-left: 20px;">
                                    <?php if (empty($supervisoresPorCoop)): ?>
                                        <p style="color: #9ca3af; font-style: italic;">No hay supervisores asignados</p>
                                    <?php else: ?>
                                        <?php foreach ($supervisoresPorCoop as $supervisor): ?>
                                        <div style="margin-bottom: 20px;">
                                            <div style="display: flex; align-items: center; padding: 12px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border-radius: 6px; margin-bottom: 10px;">
                                                <i class="fas fa-user-tie" style="margin-right: 10px;"></i>
                                                <div style="flex: 1;">
                                                    <strong><?php echo htmlspecialchars($supervisor['nombres'] . ' ' . $supervisor['apellidos']); ?></strong>
                                                    <div style="font-size: 11px; opacity: 0.85;"><?php echo htmlspecialchars($supervisor['email']); ?></div>
                                                </div>
                                                <span style="font-size: 11px; background: rgba(255,255,255,0.2); padding: 3px 10px; border-radius: 10px;">
                                                    Supervisor
                                                </span>
                                            </div>

                                            <!-- ASESORES -->
                                            <div style="margin-left: 15px; border-left: 2px solid #bfdbfe; padding-left: 15px;">
                                                <?php if (empty($asesores)): ?>
                                                    <div style="color: #9ca3af; font-size: 12px; font-style: italic; padding: 8px;">
                                                        <i class="fas fa-info-circle me-2"></i>Sin asesores asignados
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($asesores as $asesor): ?>
                                                    <div style="display: flex; align-items: center; padding: 10px; background: linear-gradient(135deg, #10b981, #059669); color: white; border-radius: 6px; margin-bottom: 8px; font-size: 14px;">
                                                        <i class="fas fa-briefcase" style="margin-right: 10px;"></i>
                                                        <div style="flex: 1;">
                                                            <strong><?php echo htmlspecialchars($asesor['nombres'] . ' ' . $asesor['apellidos']); ?></strong>
                                                            <div style="font-size: 10px; opacity: 0.85;"><?php echo htmlspecialchars($asesor['email']); ?></div>
                                                        </div>
                                                        <span style="font-size: 10px; background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 8px;">
                                                            Asesor
                                                        </span>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div style="padding: 20px; background: #fef2f2; border-radius: 8px; color: #dc2626;">
                            <i class="fas fa-alert-triangle me-2"></i>No hay administradores registrados
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

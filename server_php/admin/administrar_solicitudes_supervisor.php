<?php
require_once 'db_admin.php';

// Verificar sesión del admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

$admin_id = $is_super_admin ? $_SESSION['super_admin_id'] : $_SESSION['admin_id'];
$admin_nombre = $is_super_admin ? $_SESSION['super_admin_nombre'] : $_SESSION['admin_nombre'];

// Procesar aprobación/rechazo de solicitudes
$mensaje_exito = '';
$mensaje_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_solicitud = $_POST['id_solicitud'] ?? null;
    $accion = $_POST['accion'] ?? null;
    $observaciones = $_POST['observaciones'] ?? '';

    if ($id_solicitud && $accion) {
        try {
            if ($accion === 'aprobar') {
                // Crear tabla si no existe
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS solicitudes_supervisor (
                        id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
                        id_cooperativa INT NOT NULL,
                        id_administrador INT NOT NULL,
                        usuario VARCHAR(50) NOT NULL UNIQUE,
                        nombres VARCHAR(100) NOT NULL,
                        apellidos VARCHAR(100) NOT NULL,
                        email VARCHAR(100) NOT NULL UNIQUE,
                        password_hash VARCHAR(255) NOT NULL,
                        telefono VARCHAR(20) NOT NULL,
                        credencial_archivo VARCHAR(255) NULL,
                        estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
                        fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        fecha_aprobacion TIMESTAMP NULL,
                        observaciones TEXT NULL
                    )
                ");
                
                // Verificar si la columna credencial_archivo existe, si no agregarla
                $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'credencial_archivo'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE solicitudes_supervisor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER telefono");
                }

                // Obtener datos de la solicitud
                $stmt = $pdo->prepare("SELECT * FROM solicitudes_supervisor WHERE id_solicitud = ? AND estado = 'pendiente'");
                $stmt->execute([$id_solicitud]);
                $solicitud = $stmt->fetch();

                if ($solicitud) {
                    // Validar permisos: Si es admin, solo puede procesar sus propias solicitudes
                    if (!$is_super_admin && $solicitud['id_administrador'] != $admin_id) {
                        $mensaje_error = "❌ No tienes permiso para procesar esta solicitud.";
                    } else {
                        // Insertar usuario en tabla usuarios con rol Supervisor (asumiendo id_rol = 3)
                        $stmt = $pdo->prepare("
                            INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, activo, id_rol_fk)
                            VALUES (?, ?, ?, ?, ?, ?, 1, 3)
                        ");

                        $stmt->execute([
                            $solicitud['usuario'],
                            $solicitud['password_hash'],
                            $solicitud['nombres'],
                            $solicitud['apellidos'],
                            $solicitud['email'],
                            $solicitud['telefono']
                        ]);

                        // Actualizar solicitud como aprobada
                        $stmt = $pdo->prepare("
                            UPDATE solicitudes_supervisor 
                            SET estado = 'aprobada', fecha_aprobacion = NOW() 
                            WHERE id_solicitud = ?
                        ");
                        $stmt->execute([$id_solicitud]);

                        $mensaje_exito = "✅ Solicitud aprobada. El nuevo supervisor puede iniciar sesión.";
                    }
                }
            } elseif ($accion === 'rechazar') {
                // Obtener datos de la solicitud para validar permisos
                $stmt = $pdo->prepare("SELECT * FROM solicitudes_supervisor WHERE id_solicitud = ? AND estado = 'pendiente'");
                $stmt->execute([$id_solicitud]);
                $solicitud = $stmt->fetch();

                if ($solicitud) {
                    // Validar permisos: Si es admin, solo puede procesar sus propias solicitudes
                    if (!$is_super_admin && $solicitud['id_administrador'] != $admin_id) {
                        $mensaje_error = "❌ No tienes permiso para procesar esta solicitud.";
                    } else {
                        // Actualizar como rechazada
                        $stmt = $pdo->prepare("
                            UPDATE solicitudes_supervisor 
                            SET estado = 'rechazada', observaciones = ?, fecha_aprobacion = NOW()
                            WHERE id_solicitud = ?
                        ");
                        $stmt->execute([$observaciones, $id_solicitud]);
                        $mensaje_exito = "❌ Solicitud rechazada.";
                    }
                } else {
                    $mensaje_error = "❌ Solicitud no encontrada.";
                }
            }
        } catch (Exception $e) {
            $mensaje_error = "Error: " . $e->getMessage();
        }
    }
}

// Crear tabla si no existe
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_supervisor (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa INT NOT NULL,
            id_administrador INT NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            telefono VARCHAR(20) NOT NULL,
            credencial_archivo VARCHAR(255) NULL,
            estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
            fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion TIMESTAMP NULL,
            observaciones TEXT NULL
        )
    ");
    
    // Verificar si la columna credencial_archivo existe, si no agregarla
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'credencial_archivo'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE solicitudes_supervisor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER telefono");
    }
} catch (Exception $e) {}

// Obtener solicitudes de supervisores
$solicitudes = [];
try {
    $query = "SELECT * FROM solicitudes_supervisor ";
    
    // Si es admin (no super admin), solo ver sus propias solicitudes
    if (!$is_super_admin && $is_admin) {
        $query .= "WHERE id_administrador = " . intval($admin_id) . " ";
    }
    
    $query .= "ORDER BY 
            CASE estado 
                WHEN 'pendiente' THEN 1 
                WHEN 'rechazada' THEN 2 
                WHEN 'aprobada' THEN 3 
            END,
            fecha_solicitud DESC
    ";
    
    $stmt = $pdo->query($query);
    $solicitudes = $stmt->fetchAll();
} catch (Exception $e) {}

$currentPage = 'solicitudes_supervisor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Solicitudes de Supervisor</title>
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
        .sidebar-brand i { margin-right: 10px; color: #fbbf24; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #fbbf24, #f59e0b); color: #1a0f3d; }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #1a0f3d; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(0, 0, 0, 0.1); color: #1a0f3d; border: 1px solid #1a0f3d; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(0, 0, 0, 0.2); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .stat-card .number { font-size: 32px; font-weight: 700; color: #1f2937; }
        .stat-card .label { color: #9ca3af; font-size: 13px; margin-top: 5px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .table-card { background: #fff; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); overflow: hidden; }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid #f0f0f0; }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8f9fa; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: #f5f5f5; }
        .table tbody tr:hover { background: #fafbff; }
        .badge-pendiente { background: #fef08a; color: #713f12; padding: 5px 10px; border-radius: 6px; font-weight: 600; }
        .badge-aprobada { background: #dcfce7; color: #166534; padding: 5px 10px; border-radius: 6px; font-weight: 600; }
        .badge-rechazada { background: #fee2e2; color: #991b1b; padding: 5px 10px; border-radius: 6px; font-weight: 600; }
        .btn-aprobar { background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; }
        .btn-aprobar:hover { background: #059669; }
        .btn-rechazar { background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; }
        .btn-rechazar:hover { background: #dc2626; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; }
        .modal-header { margin-bottom: 1.5rem; }
        .modal-header h5 { margin: 0; font-weight: 700; color: #1f2937; }
        .modal-body { margin-bottom: 1.5rem; }
        .modal-body textarea { width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px; font-family: 'Inter', sans-serif; resize: vertical; }
        .modal-footer { display: flex; gap: 10px; justify-content: flex-end; }
        .modal-footer button { padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
        .btn-primary-modal { background: #3182fe; color: white; }
        .btn-primary-modal:hover { background: #1e40af; }
        .btn-secondary-modal { background: #e5e7eb; color: #1f2937; }
        .btn-secondary-modal:hover { background: #d1d5db; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-crown"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Administración</div>
        <a href="administrar_solicitudes_supervisor.php" class="sidebar-link active">
            <i class="fas fa-file-alt"></i> Solicitudes de Supervisor
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> Super_IA - Solicitudes de Supervisor</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($admin_nombre); ?></strong><br>
                <small><?php echo $is_super_admin ? 'Super Administrador' : 'Administrador'; ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-users-cog me-2"></i>Solicitudes de Supervisores</h1>
        </div>

        <?php if ($mensaje_exito): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo $mensaje_exito; ?>
        </div>
        <?php endif; ?>

        <?php if ($mensaje_error): ?>
        <div class="alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $mensaje_error; ?>
        </div>
        <?php endif; ?>

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number" style="color: #fbbf24;">
                    <?php echo count(array_filter($solicitudes, fn($s) => $s['estado'] === 'pendiente')); ?>
                </div>
                <div class="label">Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #10b981;">
                    <?php echo count(array_filter($solicitudes, fn($s) => $s['estado'] === 'aprobada')); ?>
                </div>
                <div class="label">Aprobadas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #ef4444;">
                    <?php echo count(array_filter($solicitudes, fn($s) => $s['estado'] === 'rechazada')); ?>
                </div>
                <div class="label">Rechazadas</div>
            </div>
        </div>

        <!-- TABLA DE SOLICITUDES -->
        <div class="table-card">
            <div class="card-header-custom">
                <h6><i class="fas fa-list me-2"></i>Listado de Solicitudes</h6>
            </div>
            <div style="overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Credencial</th>
                            <th>Estado</th>
                            <th>Fecha Solicitud</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($solicitudes)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #9ca3af; padding: 30px;">
                                <i class="fas fa-inbox me-2"></i>No hay solicitudes
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($solicitudes as $solicitud): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($solicitud['usuario']); ?></strong></td>
                                <td><?php echo htmlspecialchars($solicitud['nombres'] . ' ' . $solicitud['apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($solicitud['email']); ?></td>
                                <td>
                                    <?php if ($solicitud['credencial_archivo']): ?>
                                    <a href="../../uploads/supervisor_credentials/<?php echo htmlspecialchars($solicitud['credencial_archivo']); ?>" 
                                       target="_blank" 
                                       style="color: #3182fe; text-decoration: none; font-weight: 600;">
                                        <i class="fas fa-file-pdf me-1"></i>Ver Credencial
                                    </a>
                                    <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 12px;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>No disponible
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-<?php echo $solicitud['estado']; ?>">
                                        <?php echo ucfirst($solicitud['estado']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($solicitud['fecha_solicitud'])); ?></td>
                                <td>
                                    <?php if ($solicitud['estado'] === 'pendiente'): ?>
                                    <button class="btn-aprobar" onclick="mostrarModal('aprobar', <?php echo $solicitud['id_solicitud']; ?>, '<?php echo htmlspecialchars(addslashes($solicitud['credencial_archivo'])); ?>')">
                                        <i class="fas fa-check me-1"></i>Aprobar
                                    </button>
                                    <button class="btn-rechazar" onclick="mostrarModal('rechazar', <?php echo $solicitud['id_solicitud']; ?>, '<?php echo htmlspecialchars(addslashes($solicitud['credencial_archivo'])); ?>')" style="margin-left: 5px;">
                                        <i class="fas fa-times me-1"></i>Rechazar
                                    </button>
                                    <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 12px;">Procesada</span>
                                    <?php endif; ?>
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

<!-- MODAL -->
<div id="modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5 id="modal-title">Confirmar</h5>
        </div>
        <form id="form-modal" method="POST">
            <input type="hidden" name="id_solicitud" id="input-solicitud">
            <input type="hidden" name="accion" id="input-accion">
            
            <div class="modal-body">
                <div id="modal-body-content"></div>
                <textarea id="observaciones" name="observaciones" placeholder="Observaciones (opcional)..." style="display: none; margin-top: 10px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary-modal" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-primary-modal" id="btn-confirmar">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script>
function mostrarModal(accion, id, credencial) {
    const modal = document.getElementById('modal');
    const title = document.getElementById('modal-title');
    const inputSolicitud = document.getElementById('input-solicitud');
    const inputAccion = document.getElementById('input-accion');
    const modalBody = document.getElementById('modal-body-content');
    const observaciones = document.getElementById('observaciones');
    const btnConfirmar = document.getElementById('btn-confirmar');

    inputSolicitud.value = id;
    inputAccion.value = accion;

    let modalHTML = '';
    
    if (accion === 'aprobar') {
        title.textContent = 'Aprobar Solicitud';
        modalHTML = '<p>¿Estás seguro de que deseas <strong>aprobar</strong> esta solicitud?</p><p style="color: #9ca3af; font-size: 13px;">El supervisor podrá iniciar sesión inmediatamente.</p>';
        observaciones.style.display = 'none';
        btnConfirmar.textContent = 'Aprobar';
        btnConfirmar.className = 'btn-primary-modal';
    } else {
        title.textContent = 'Rechazar Solicitud';
        modalHTML = '<p>¿Estás seguro de que deseas <strong>rechazar</strong> esta solicitud?</p>';
        observaciones.style.display = 'block';
        btnConfirmar.textContent = 'Rechazar';
        btnConfirmar.className = 'btn-primary-modal';
        btnConfirmar.style.background = '#ef4444';
    }
    
    // Agregar sección de credencial
    if (credencial) {
        modalHTML += '<hr style="margin: 15px 0; border: none; border-top: 1px solid #e5e7eb;">';
        modalHTML += '<p style="margin-bottom: 10px; color: #6c757d; font-size: 13px;"><strong>Credencial:</strong></p>';
        const ext = credencial.split('.').pop().toLowerCase();
        if (ext === 'pdf') {
            modalHTML += '<embed src="../../uploads/supervisor_credentials/' + encodeURIComponent(credencial) + '" type="application/pdf" style="width: 100%; height: 300px; border: 1px solid #e5e7eb; border-radius: 6px;">';
        } else {
            modalHTML += '<img src="../../uploads/supervisor_credentials/' + encodeURIComponent(credencial) + '" style="max-width: 100%; max-height: 300px; border: 1px solid #e5e7eb; border-radius: 6px;">';
        }
        modalHTML += '<p style="margin-top: 10px;"><a href="../../uploads/supervisor_credentials/' + encodeURIComponent(credencial) + '" target="_blank" style="color: #3182fe; text-decoration: none; font-size: 12px;"><i class="fas fa-download me-1"></i>Descargar archivo</a></p>';
    } else {
        modalHTML += '<hr style="margin: 15px 0; border: none; border-top: 1px solid #e5e7eb;">';
        modalHTML += '<p style="color: #fbbf24; font-size: 12px;"><i class="fas fa-exclamation-triangle me-1"></i>⚠️ No hay credencial disponible</p>';
    }
    
    modalBody.innerHTML = modalHTML;
    modal.classList.add('show');
}

function cerrarModal() {
    const modal = document.getElementById('modal');
    modal.classList.remove('show');
}

document.getElementById('form-modal').onsubmit = function(e) {
    e.preventDefault();
    this.submit();
};

document.getElementById('modal').onclick = function(e) {
    if (e.target === this) {
        cerrarModal();
    }
};
</script>

</body>
</html>

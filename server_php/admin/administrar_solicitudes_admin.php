<?php
require_once 'db_admin.php';

// Verificar sesión - SOLO SUPER ADMIN
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_super_admin) {
    header('Location: login.php?role=super_admin');
    exit;
}

// Variables del super admin
$admin_id = $_SESSION['super_admin_id'];
$admin_nombre = $_SESSION['super_admin_nombre'];
$admin_rol = $_SESSION['super_admin_rol'];

// Procesar aprobación/rechazo de solicitudes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_solicitud = $_POST['id_solicitud'] ?? null;
    $accion = $_POST['accion'] ?? null;
    $observaciones = $_POST['observaciones'] ?? '';

    if ($id_solicitud && $accion) {
        try {
            if ($accion === 'aprobar') {
                // Obtener datos de la solicitud
                $stmt = $pdo->prepare("SELECT * FROM solicitudes_admin WHERE id_solicitud = ? AND estado = 'pendiente'");
                $stmt->execute([$id_solicitud]);
                $solicitud = $stmt->fetch();

                if ($solicitud) {
                    // Insertar usuario en tabla usuarios
                    $stmt = $pdo->prepare("
                        INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, ciudad, provincia, canton, activo, id_rol_fk)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 2)
                    ");

                    $stmt->execute([
                        $solicitud['usuario'],
                        $solicitud['password_hash'],
                        $solicitud['nombres'],
                        $solicitud['apellidos'],
                        $solicitud['email'],
                        $solicitud['telefono'],
                        $solicitud['region'],
                        $solicitud['region'],
                        $solicitud['region']
                    ]);

                    // Actualizar solicitud como aprobada
                    $stmt = $pdo->prepare("
                        UPDATE solicitudes_admin 
                        SET estado = 'aprobada', fecha_aprobacion = NOW() 
                        WHERE id_solicitud = ?
                    ");
                    $stmt->execute([$id_solicitud]);

                    $mensaje_exito = "✅ Solicitud aprobada. El nuevo administrador puede iniciar sesión.";
                }
            } elseif ($accion === 'rechazar') {
                // Actualizar como rechazada
                $stmt = $pdo->prepare("
                    UPDATE solicitudes_admin 
                    SET estado = 'rechazada', observaciones = ?, fecha_aprobacion = NOW()
                    WHERE id_solicitud = ?
                ");
                $stmt->execute([$observaciones, $id_solicitud]);
                $mensaje_exito = "❌ Solicitud rechazada.";
            }
        } catch (Exception $e) {
            $mensaje_error = "Error: " . $e->getMessage();
        }
    }
}

// Obtener tabla solicitudes_admin si existe
$solicitudes = [];
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_admin (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa INT NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            region VARCHAR(100) NOT NULL,
            telefono VARCHAR(20) NOT NULL,
            archivo_credencial VARCHAR(255) NOT NULL,
            estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
            fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion TIMESTAMP NULL,
            observaciones TEXT NULL
        )
    ");
    
    // Agregar columnas faltantes si es necesario
    try {
        $pdo->exec("ALTER TABLE solicitudes_admin ADD COLUMN id_cooperativa INT NOT NULL DEFAULT 1");
    } catch (Exception $e) {}
    
    try {
        $pdo->exec("ALTER TABLE solicitudes_admin ADD COLUMN archivo_credencial VARCHAR(255) NOT NULL DEFAULT ''");
    } catch (Exception $e) {}
    
    $stmt = $pdo->query("
        SELECT * FROM solicitudes_admin 
        ORDER BY 
            CASE estado 
                WHEN 'pendiente' THEN 1 
                WHEN 'rechazada' THEN 2 
                WHEN 'aprobada' THEN 3 
            END,
            fecha_solicitud DESC
    ");
    $solicitudes = $stmt->fetchAll();
} catch (Exception $e) {
    // Tabla no existe aún
}

$currentPage = 'solicitudes_admin';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Gestión de Solicitudes de Administrador</title>
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
        .badge-pendiente { background: #fef08a; color: #713f12; }
        .badge-aprobada { background: #dcfce7; color: #166534; }
        .badge-rechazada { background: #fee2e2; color: #991b1b; }
        .modal-custom { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-custom.show { display: flex; }
        .modal-content-custom { background: white; border-radius: 12px; padding: 2rem; max-width: 500px; width: 90%; }
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
        <a href="<?php echo $is_super_admin ? 'super_admin_index.php' : 'index.php'; ?>" class="sidebar-link">
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
        <a href="usuarios.php" class="sidebar-link">
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
        <div class="sidebar-section-title">Super Administración</div>
        <a href="administrar_solicitudes_admin.php" class="sidebar-link active">
            <i class="fas fa-file-alt"></i> Solicitudes de Admin
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
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> COAC Finance - <?php echo htmlspecialchars($admin_rol); ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($admin_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($admin_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-file-alt me-2"></i>Gestión de Solicitudes de Administrador</h1>
        </div>

        <?php if (isset($mensaje_exito)): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($mensaje_exito); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($mensaje_error)): ?>
        <div class="alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($mensaje_error); ?>
        </div>
        <?php endif; ?>

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number" style="color: #f59e0b;">
                    <?php 
                    $pendientes = array_filter($solicitudes, fn($s) => $s['estado'] === 'pendiente');
                    echo count($pendientes); 
                    ?>
                </div>
                <div class="label">Solicitudes Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #10b981;">
                    <?php 
                    $aprobadas = array_filter($solicitudes, fn($s) => $s['estado'] === 'aprobada');
                    echo count($aprobadas); 
                    ?>
                </div>
                <div class="label">Aprobadas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #ef4444;">
                    <?php 
                    $rechazadas = array_filter($solicitudes, fn($s) => $s['estado'] === 'rechazada');
                    echo count($rechazadas); 
                    ?>
                </div>
                <div class="label">Rechazadas</div>
            </div>
        </div>

        <!-- TABLA DE SOLICITUDES -->
        <div class="table-card">
            <div class="card-header-custom">
                <h6>📋 Solicitudes de Creación de Cuenta</h6>
            </div>
            
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Nombre Completo</th>
                        <th>Email</th>
                        <th>Región</th>
                        <th>Credencial</th>
                        <th>Fecha Solicitud</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($solicitudes)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="fas fa-inbox me-2" style="color: #d1d5db;"></i>No hay solicitudes
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($solicitudes as $sol): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($sol['usuario']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sol['nombres'] . ' ' . $sol['apellidos']); ?></td>
                            <td><?php echo htmlspecialchars($sol['email']); ?></td>
                            <td><?php echo htmlspecialchars($sol['region']); ?></td>
                            <td>
                                <?php if (!empty($sol['archivo_credencial'])): ?>
                                <a href="descargar_credencial.php?id=<?php echo urlencode($sol['id_solicitud']); ?>" 
                                   class="btn btn-sm btn-secondary" target="_blank" title="Descargar PDF">
                                    <i class="fas fa-file-pdf me-1"></i>Ver PDF
                                </a>
                                <?php else: ?>
                                <span class="text-muted"><i class="fas fa-times me-1"></i>Sin archivo</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($sol['fecha_solicitud'])); ?></td>
                            <td>
                                <?php 
                                $clase = match($sol['estado']) {
                                    'pendiente' => 'badge-pendiente',
                                    'aprobada' => 'badge-aprobada',
                                    'rechazada' => 'badge-rechazada',
                                    default => 'badge-pendiente'
                                };
                                $icono = match($sol['estado']) {
                                    'pendiente' => '⏳',
                                    'aprobada' => '✓',
                                    'rechazada' => '✗',
                                    default => '⏳'
                                };
                                ?>
                                <span class="badge <?php echo $clase; ?>">
                                    <?php echo $icono . ' ' . ucfirst($sol['estado']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($sol['estado'] === 'pendiente'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="id_solicitud" value="<?php echo $sol['id_solicitud']; ?>">
                                    <input type="hidden" name="accion" value="aprobar">
                                    <button type="submit" class="btn btn-sm btn-success" 
                                            onclick="return confirm('¿Aprobar solicitud de <?php echo htmlspecialchars($sol['usuario']); ?>?')">
                                        <i class="fas fa-check me-1"></i>Aprobar
                                    </button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="id_solicitud" value="<?php echo $sol['id_solicitud']; ?>">
                                    <input type="hidden" name="accion" value="rechazar">
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal"
                                            onclick="abrirModalRechazo(<?php echo $sol['id_solicitud']; ?>, '<?php echo htmlspecialchars($sol['usuario']); ?>')">
                                        <i class="fas fa-times me-1"></i>Rechazar
                                    </button>
                                </form>
                                <?php elseif ($sol['estado'] === 'aprobada'): ?>
                                <span class="text-success">
                                    <i class="fas fa-check-circle me-1"></i>
                                    <?php echo date('d/m/Y', strtotime($sol['fecha_aprobacion'])); ?>
                                </span>
                                <?php else: ?>
                                <span class="text-danger">
                                    <i class="fas fa-times-circle me-1"></i>
                                    <?php echo date('d/m/Y', strtotime($sol['fecha_aprobacion'])); ?>
                                </span>
                                <?php if ($sol['observaciones']): ?>
                                <br><small title="<?php echo htmlspecialchars($sol['observaciones']); ?>">
                                    <?php echo htmlspecialchars(substr($sol['observaciones'], 0, 30)); ?>...
                                </small>
                                <?php endif; ?>
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

<!-- MODAL RECHAZO -->
<div class="modal-custom" id="modalRechazo">
    <div class="modal-content-custom">
        <h5 class="mb-3"><i class="fas fa-times-circle text-danger me-2"></i>Rechazar Solicitud</h5>
        <form method="POST" id="formRechazo">
            <input type="hidden" name="id_solicitud" id="rechazoIdSolicitud">
            <input type="hidden" name="accion" value="rechazar">
            <div class="mb-3">
                <label for="observaciones" class="form-label">Motivo del Rechazo (opcional)</label>
                <textarea name="observaciones" id="observaciones" class="form-control" rows="3" 
                          placeholder="Ej: Email duplicado, usuario ya existe..."></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger" style="flex: 1;">
                    <i class="fas fa-times me-1"></i>Confirmar Rechazo
                </button>
                <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="cerrarModal('modalRechazo')">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalRechazo(idSolicitud, usuario) {
    document.getElementById('rechazoIdSolicitud').value = idSolicitud;
    document.getElementById('modalRechazo').classList.add('show');
}

function cerrarModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

document.getElementById('formRechazo').addEventListener('submit', function(e) {
    e.preventDefault();
    this.submit();
});
</script>

</body>
</html>

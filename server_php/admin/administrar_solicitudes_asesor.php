<?php
$mensaje_exito = '';
$mensaje_error = '';

// Asegurar conexión PDO disponible (fallback a db_config.php que define $db_* y $conn)
require_once __DIR__ . '/../db_config.php';
if (!isset($pdo)) {
    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        $mensaje_error = "Error de conexión PDO: " . $e->getMessage();
        // seguir sin detener la página; las secciones que requieren BD fallarán con mensaje
    }
}

// Sesión / supervisor
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$supervisor_id = $_SESSION['supervisor_id'] ?? $_SESSION['id_usuario'] ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? $_SESSION['nombre'] ?? 'Supervisor';

// Flash messages (Post/Redirect/Get)
if (!$mensaje_exito && isset($_SESSION['flash_success'])) {
    $mensaje_exito = (string)$_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!$mensaje_error && isset($_SESSION['flash_error'])) {
    $mensaje_error = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_solicitud = $_POST['id_solicitud'] ?? null;
    $accion = $_POST['accion'] ?? null;
    $observaciones = $_POST['observaciones'] ?? '';

    if ($id_solicitud && $accion) {
        try {
            // detectar solicitudes originadas desde la app (id prefijo 'app_')
            $is_app_request = false;
            $app_usuario_id = null;
            if (is_string($id_solicitud) && strpos($id_solicitud, 'app_') === 0) {
                $is_app_request = true;
                $app_usuario_id = substr($id_solicitud, 4);
            }

            if ($accion === 'aprobar') {
                if ($is_app_request) {
                    if (!$app_usuario_id) {
                        $mensaje_error = "Usuario de la solicitud no identificado.";
                    } else {
                        $upd = $pdo->prepare("UPDATE usuario SET activo = 1, estado_aprobacion = 'aprobado' WHERE id = ?");
                        $upd->execute([$app_usuario_id]);

                        // Marcar también la solicitud de registro (si existe)
                        try {
                            $updSol = $pdo->prepare("UPDATE solicitud_registro SET estado = 'aprobada' WHERE usuario_id = ? AND rol_solicitado = 'asesor'");
                            $updSol->execute([$app_usuario_id]);
                        } catch (Exception $e) {
                            // no bloquear
                        }

                        $mensaje_exito = "✅ Solicitud aprobada. El asesor ya puede iniciar sesión en la app.";
                    }
                } else {
                    // Procesar solicitud tradicional desde la tabla solicitudes_asesor
                    $stmt = $pdo->prepare("SELECT * FROM solicitudes_asesor WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$id_solicitud]);
                    $solicitud = $stmt->fetch();

                    if ($solicitud) {
                        if ($solicitud['id_supervisor'] != $supervisor_id) {
                            $mensaje_error = "❌ No tienes permiso para procesar esta solicitud.";
                        } else {
                            // Insertar usuario
                            $hasSupervisorFk = false;
                            try {
                                $col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'supervisor_id_fk'")->fetch();
                                $hasSupervisorFk = (bool)$col;
                            } catch (Exception $e) {
                                $hasSupervisorFk = false;
                            }

                            if ($hasSupervisorFk) {
                                $stmtIns = $pdo->prepare("INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, activo, id_rol_fk, supervisor_id_fk) VALUES (?, ?, ?, ?, ?, ?, 1, 4, ?)");
                                $stmtIns->execute([
                                    $solicitud['usuario'],
                                    $solicitud['password_hash'],
                                    $solicitud['nombres'],
                                    $solicitud['apellidos'],
                                    $solicitud['email'],
                                    $solicitud['telefono'],
                                    $solicitud['id_supervisor']
                                ]);
                            } else {
                                $stmtIns = $pdo->prepare("INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, activo, id_rol_fk) VALUES (?, ?, ?, ?, ?, ?, 1, 4)");
                                $stmtIns->execute([
                                    $solicitud['usuario'],
                                    $solicitud['password_hash'],
                                    $solicitud['nombres'],
                                    $solicitud['apellidos'],
                                    $solicitud['email'],
                                    $solicitud['telefono']
                                ]);
                            }

                            $stmtUpd = $pdo->prepare("UPDATE solicitudes_asesor SET estado = 'aprobada', fecha_aprobacion = NOW() WHERE id_solicitud = ?");
                            $stmtUpd->execute([$id_solicitud]);
                            $mensaje_exito = "✅ Solicitud aprobada. El nuevo asesor puede iniciar sesión.";
                        }
                    } else {
                        $mensaje_error = "❌ Solicitud no encontrada.";
                    }
                }
            } elseif ($accion === 'rechazar') {
                if ($is_app_request) {
                    if (!$app_usuario_id) {
                        $mensaje_error = "Usuario de la solicitud no identificado.";
                    } else {
                        $upd = $pdo->prepare("UPDATE usuario SET estado_aprobacion = 'rechazada', activo = 0 WHERE id = ?");
                        $upd->execute([$app_usuario_id]);

                        // Marcar también la solicitud de registro (si existe)
                        try {
                            $updSol = $pdo->prepare("UPDATE solicitud_registro SET estado = 'rechazada' WHERE usuario_id = ? AND rol_solicitado = 'asesor'");
                            $updSol->execute([$app_usuario_id]);
                        } catch (Exception $e) {
                            // no bloquear
                        }

                        $mensaje_exito = "❌ Solicitud rechazada. El asesor no podrá iniciar sesión.";
                    }
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM solicitudes_asesor WHERE id_solicitud = ? AND estado = 'pendiente'");
                    $stmt->execute([$id_solicitud]);
                    $solicitud = $stmt->fetch();

                    if ($solicitud) {
                        if ($solicitud['id_supervisor'] != $supervisor_id) {
                            $mensaje_error = "❌ No tienes permiso para procesar esta solicitud.";
                        } else {
                            $stmt = $pdo->prepare("UPDATE solicitudes_asesor SET estado = 'rechazada', observaciones = ?, fecha_aprobacion = NOW() WHERE id_solicitud = ?");
                            $stmt->execute([$observaciones, $id_solicitud]);
                            $mensaje_exito = "❌ Solicitud rechazada.";
                        }
                    } else {
                        $mensaje_error = "❌ Solicitud no encontrada.";
                    }
                }
            }
        } catch (Exception $e) {
            $mensaje_error = "Error: " . $e->getMessage();
        }

        // Refrescar la página para que se actualice el listado/contadores
        $_SESSION['flash_success'] = $mensaje_exito;
        $_SESSION['flash_error'] = $mensaje_error;
        header('Location: administrar_solicitudes_asesor.php');
        exit;
    }
}

// Crear tabla si no existe
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_asesor (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
            id_supervisor INT NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            telefono VARCHAR(20) NOT NULL,
            banco VARCHAR(100) NOT NULL,
            numero_cuenta VARCHAR(50) NOT NULL,
            tipo_cuenta VARCHAR(50) NOT NULL,
            credencial_archivo VARCHAR(255) NULL,
            estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
            fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion TIMESTAMP NULL,
            observaciones TEXT NULL
        )
    ");
    
    // Verificar si la columna credencial_archivo existe, si no agregarla
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE 'credencial_archivo'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE solicitudes_asesor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER tipo_cuenta");
    }
} catch (Exception $e) {}

// Obtener solicitudes de este supervisor
$solicitudes = [];
try {
    // Legacy: solo aplica si el supervisor_id es numérico
    if (is_numeric($supervisor_id)) {
        $query = "SELECT * FROM solicitudes_asesor 
            WHERE id_supervisor = " . intval($supervisor_id) . " 
            ORDER BY 
                CASE estado 
                    WHEN 'pendiente' THEN 1 
                    WHEN 'rechazada' THEN 2 
                    WHEN 'aprobada' THEN 3 
                END,
                fecha_solicitud DESC
        ";
        
        $stmt = $pdo->query($query);
        $solicitudes = $stmt->fetchAll();
    }
} catch (Exception $e) {}

// --- Traer solicitudes generadas por la app (usuario + asesor) del supervisor actual, en cualquier estado
try {
    $pdo2 = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password);
    $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql_app = "SELECT 
                    u.id AS usuario_id,
                    u.nombre AS nombre_completo,
                    u.email,
                    u.telefono,
                    u.created_at,
                    u.estado_aprobacion,
                    a.documento_path
                FROM usuario u
                JOIN asesor a ON a.usuario_id = u.id
                JOIN supervisor s ON s.id = a.supervisor_id
                WHERE u.rol = 'asesor'
                  AND s.usuario_id = :supervisor_usuario_id
                ORDER BY 
                  CASE u.estado_aprobacion
                    WHEN 'pendiente' THEN 1
                    WHEN 'rechazada' THEN 2
                    WHEN 'aprobado' THEN 3
                    ELSE 4
                  END,
                  u.created_at DESC";

    $stmt2 = $pdo2->prepare($sql_app);
    $stmt2->execute([':supervisor_usuario_id' => (string)$supervisor_id]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        // separar nombre y apellido si es posible
        $parts = explode(' ', trim($r['nombre_completo']), 2);
        $nombres = $parts[0] ?? '';
        $apellidos = $parts[1] ?? '';

        // Normalizar estado para UI (legacy usa aprobada/rechazada)
        $estado_ui = 'pendiente';
        if (($r['estado_aprobacion'] ?? '') === 'aprobado') $estado_ui = 'aprobada';
        if (($r['estado_aprobacion'] ?? '') === 'rechazada') $estado_ui = 'rechazada';

        $solicitudes[] = [
            'id_solicitud' => 'app_' . $r['usuario_id'],
            'id_supervisor' => intval($supervisor_id), // permitir que el supervisor actual la vea/procese
            'usuario' => strstr($r['email'], '@', true) ?: $r['email'],
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'email' => $r['email'],
            'telefono' => $r['telefono'] ?? '',
            'banco' => '',
            'numero_cuenta' => '',
            'tipo_cuenta' => '',
            'credencial_archivo' => $r['documento_path'] ?? '',
            'estado' => $estado_ui,
            'fecha_solicitud' => $r['created_at'],
            'fecha_aprobacion' => null,
            'observaciones' => null
        ];
    }
} catch (Exception $e) {
    // no bloquear si falla esta integración
}

$currentPage = 'solicitudes_asesor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Solicitudes de Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: var(--brand-card); padding: 20px; border-radius: 16px; box-shadow: var(--brand-shadow); text-align: center; border: 1px solid var(--brand-border); }
        .stat-card .number { font-size: 32px; font-weight: 800; color: var(--brand-navy-deep); }
        .stat-card .label { color: var(--brand-gray); font-size: 13px; margin-top: 5px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .table-card { background: var(--brand-card); border-radius: 18px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 20px; border-bottom: 1px solid rgba(215,224,234,0.7); }
        .table-card h6 { font-weight: 800; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: var(--brand-gray); border: none; padding: 14px; }
        .table tbody td { padding: 14px; vertical-align: middle; border-color: rgba(215,224,234,0.55); }
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
        .modal-content { background: white; border-radius: 12px; padding: 2rem; max-width: 550px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .modal-header { margin-bottom: 1.5rem; }
        .modal-header h5 { margin: 0; font-weight: 700; color: #1f2937; }
        .modal-body { margin-bottom: 1.5rem; }
        .modal-body textarea { width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px; font-family: 'Inter', sans-serif; resize: vertical; }
        .modal-footer { display: flex; gap: 10px; justify-content: flex-end; }
        .modal-footer button { padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; }
        .btn-primary-modal { background: var(--brand-navy); color: white; }
        .btn-primary-modal:hover { background: var(--brand-navy-deep); }
        .btn-secondary-modal { background: #e5e7eb; color: #1f2937; }
        .btn-secondary-modal:hover { background: #d1d5db; }
        .info-group { margin-bottom: 15px; padding: 12px; background: #f9fafb; border-left: 3px solid var(--brand-yellow-deep); border-radius: 4px; }
        .info-label { font-size: 12px; color: #6c757d; font-weight: 600; text-transform: uppercase; }
        .info-value { font-weight: 600; color: #1f2937; margin-top: 4px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> COAC Finance
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="supervisor_index.php" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
            <a href="mapa_vivo_superIA.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
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
        <div class="sidebar-section-title">Mi Equipo</div>
        <a href="mis_asesores.php" class="sidebar-link">
            <i class="fas fa-users"></i> Mis Asesores
        </a>
        <a href="registro_asesor.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link active">
            <i class="fas fa-file-circle-check"></i> Solicitudes de Asesor
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2>📊 COAC Finance - Solicitudes de Asesor</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($supervisor_nombre); ?></strong><br>
                <small>Supervisor</small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-users me-2"></i>Solicitudes de Asesores</h1>
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
                            <th>Banco</th>
                            <th>Credencial</th>
                            <th>Estado</th>
                            <th>Fecha Solicitud</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($solicitudes)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #9ca3af; padding: 30px;">
                                <i class="fas fa-inbox me-2"></i>No hay solicitudes
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($solicitudes as $solicitud): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($solicitud['usuario']); ?></strong></td>
                                <td><?php echo htmlspecialchars($solicitud['nombres'] . ' ' . $solicitud['apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($solicitud['email']); ?></td>
                                <td><small><?php echo htmlspecialchars($solicitud['banco']); ?></small></td>
                                <td>
                                    <?php if (!empty($solicitud['credencial_archivo'])):
                                        // soportar rutas de la app (uploads/documentos_asesor/...) o la carpeta antigua uploads/asesor_credentials
                                        $cred = $solicitud['credencial_archivo'];
                                        if (str_contains($cred, 'documentos_asesor') || str_contains($cred, 'uploads/')) {
                                            $credPath = '../../' . ltrim($cred, '/');
                                        } else {
                                            $credPath = '../../uploads/asesor_credentials/' . $cred;
                                        }
                                    ?>
                                    <a href="<?php echo htmlspecialchars($credPath); ?>" 
                                       target="_blank" 
                                       style="color: #3182fe; text-decoration: none; font-weight: 600;">
                                        <i class="fas fa-file-pdf me-1"></i>Ver
                                    </a>
                                    <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 12px;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>No
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
                                    <button class="btn-aprobar" onclick="mostrarModal('aprobar', '<?php echo htmlspecialchars(addslashes((string)$solicitud['id_solicitud'])); ?>', '<?php echo htmlspecialchars(addslashes($solicitud['nombres'])); ?>', '<?php echo htmlspecialchars(addslashes($solicitud['credencial_archivo'])); ?>')">
                                        <i class="fas fa-check me-1"></i>Aprobar
                                    </button>
                                    <button class="btn-rechazar" onclick="mostrarModal('rechazar', '<?php echo htmlspecialchars(addslashes((string)$solicitud['id_solicitud'])); ?>', '<?php echo htmlspecialchars(addslashes($solicitud['nombres'])); ?>', '<?php echo htmlspecialchars(addslashes($solicitud['credencial_archivo'])); ?>')" style="margin-left: 5px;">
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
function mostrarModal(accion, id, nombre, credencial) {
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
        modalHTML = '<p><strong>Asesor:</strong> ' + nombre + '</p>';
        modalHTML += '<p>¿Estás seguro de que deseas <strong>aprobar</strong> esta solicitud?</p><p style="color: #9ca3af; font-size: 13px;">El asesor podrá iniciar sesión inmediatamente.</p>';
        observaciones.style.display = 'none';
        btnConfirmar.textContent = 'Aprobar';
        btnConfirmar.className = 'btn-primary-modal';
    } else {
        title.textContent = 'Rechazar Solicitud';
        modalHTML = '<p><strong>Asesor:</strong> ' + nombre + '</p>';
        modalHTML += '<p>¿Estás seguro de que deseas <strong>rechazar</strong> esta solicitud?</p>';
        observaciones.style.display = 'block';
        btnConfirmar.textContent = 'Rechazar';
        btnConfirmar.className = 'btn-primary-modal';
        btnConfirmar.style.background = '#ef4444';
    }
    
    // Agregar sección de credencial
    if (credencial) {
        modalHTML += '<hr style="margin: 15px 0; border: none; border-top: 1px solid #e5e7eb;">';
        modalHTML += '<p style="margin-bottom: 10px; color: #6c757d; font-size: 13px;\"><strong>Credencial:</strong></p>';
        // Normalizar diferentes formatos que puedan venir desde la DB:
        // - URLs absolutas (http/https)
        // - Rutas relativas que contienen 'uploads/' (con / o \ en Windows)
        // - Solo nombre de archivo (legacy)
        let credPath = '';
        // URL absoluta
        if (/^(https?:)?\/\//i.test(credencial)) {
            credPath = credencial;
        } else {
            // Normalizar backslashes a slashes
            let normalized = credencial.replace(/\\\\/g, '/').replace(/\\/g, '/');
            const lower = normalized.toLowerCase();
            const idx = lower.indexOf('uploads/');
            if (idx !== -1) {
                // Tomar desde 'uploads/' en adelante y convertir a ruta relativa web
                const sub = normalized.substr(idx);
                credPath = '../../' + sub.replace(/^\/+/, '');
            } else {
                // Tratar como nombre de archivo almacenado en la carpeta legacy
                credPath = '../../uploads/asesor_credentials/' + encodeURIComponent(normalized);
            }
        }
        console.log('Credencial raw:', credencial, '-> credPath:', credPath);
        const ext = credPath.split('.').pop().toLowerCase();
        // Intentar HEAD al recurso y si falla, usar fallback legacy
        const legacyPath = '../../uploads/asesor_credentials/' + encodeURIComponent(credencial.replace(/.*[\\\/]?/, ''));
        // marcador temporal donde colocaremos la vista previa
        const previewId = 'cred-preview-' + Math.random().toString(36).substring(2, 8);
        modalHTML += '<div id="' + previewId + '" style="min-height: 60px; display:flex; align-items:center; justify-content:center;"></div>';
        modalHTML += '<p style="margin-top: 10px;"><a id="cred-download-' + previewId + '" href="#" target="_blank" style="color: #3182fe; text-decoration: none; font-size: 12px;\"><i class="fas fa-download me-1"></i>Descargar</a></p>';

        // Después de que el modal sea insertado en DOM, haremos la comprobación
        setTimeout(() => {
            const container = document.getElementById(previewId);
            const downloadLink = document.getElementById('cred-download-' + previewId);
            const tryPaths = [credPath, legacyPath];

            const tryNext = (index) => {
                if (index >= tryPaths.length) {
                    container.innerHTML = '<div style="color:#6c757d;">No se encontró el archivo.</div>';
                    downloadLink.href = '#';
                    return;
                }
                const p = tryPaths[index];
                fetch(p, { method: 'HEAD' }).then(res => {
                    if (res.ok) {
                        if (ext === 'pdf') {
                            container.innerHTML = '<embed src="' + p + '" type="application/pdf" style="width: 100%; height: 300px; border: 1px solid #e5e7eb; border-radius: 6px;">';
                        } else {
                            container.innerHTML = '<img src="' + p + '" style="max-width: 100%; max-height: 300px; border: 1px solid #e5e7eb; border-radius: 6px;">';
                        }
                        downloadLink.href = p;
                    } else {
                        tryNext(index + 1);
                    }
                }).catch(err => {
                    tryNext(index + 1);
                });
            };

            tryNext(0);
        }, 50);
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
</script>

</body>
</html>

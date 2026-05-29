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
    <title>Super_IA - Solicitudes de Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── PAGE HEADER ─────────────────────────── */
        .ma-page-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
            padding-bottom: 18px;
            border-bottom: 2px solid #e8eef6;
        }
        .ma-page-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #0a2748, #1e4d8c);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px rgba(10,39,72,.22);
            flex-shrink: 0;
        }
        .ma-page-icon i { color: #ffdd00; font-size: 22px; }
        .ma-page-title { font-size: 22px; font-weight: 900; color: #0a2748; margin: 0; }
        .ma-page-sub { font-size: 13px; color: #94a3b8; margin: 2px 0 0; font-weight: 500; }

        /* Botón regresar */
        .btn-outline-navy {
            background: transparent;
            color: #0a2748;
            border: 2px solid #0a2748;
            border-radius: 10px;
            padding: 8px 16px;
            font-size: 13.5px;
            font-weight: 700;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-outline-navy:hover {
            background: rgba(10,39,72,.05);
            color: #0a2748;
            transform: translateY(-1px);
        }

        /* ── SOLICITUDES GRID ─────────────────────── */
        .solicitudes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        /* Tarjeta Solicitud */
        .sc {
            background: #fff;
            border-radius: 18px;
            border: 2px solid #e2eaf4;
            box-shadow: 0 3px 12px rgba(10,39,72,.07);
            transition: all .2s;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .sc:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 28px rgba(10,39,72,.15);
            border-color: #93c5fd;
        }
        
        .sc-stripe {
            height: 5px;
            background: #fbbf24; /* Yellow by default for pending */
        }
        .sc-stripe.aprobada { background: #10b981; }
        .sc-stripe.rechazada { background: #ef4444; }

        .sc-body {
            padding: 20px;
            flex-grow: 1;
        }
        .sc-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }
        .sc-avatar {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0a2748, #1e4d8c);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 800; color: #ffdd00;
            box-shadow: 0 3px 8px rgba(10,39,72,.15);
        }
        .sc-avatar.aprobada { background: linear-gradient(135deg, #065f46, #10b981); color: #fff; }
        .sc-avatar.rechazada { background: linear-gradient(135deg, #991b1b, #ef4444); color: #fff; }

        .sc-name {
            font-size: 15px;
            font-weight: 800;
            color: #0a2748;
            margin: 0;
            line-height: 1.2;
        }
        .sc-username {
            font-size: 11.5px;
            color: #94a3b8;
            font-weight: 600;
            margin-top: 2px;
        }

        .sc-info {
            font-size: 12px;
            color: #4b5563;
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }
        .sc-info span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .sc-info i {
            color: #64748b;
            width: 14px;
            text-align: center;
        }

        .sc-bank {
            background: #f8fafc;
            border-radius: 10px;
            padding: 10px 12px;
            border: 1px solid #edf2f7;
            font-size: 11px;
            color: #64748b;
            margin-top: 10px;
        }
        .sc-bank-title {
            font-weight: 700;
            color: #475569;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 9.5px;
        }

        .sc-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            background: #f8fafc;
            border-top: 1px solid #edf2f9;
            gap: 8px;
        }

        .badge-solicitud {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-solicitud.pendiente { background: #fef3c7; color: #d97706; }
        .badge-solicitud.aprobada { background: #d1fae5; color: #059669; }
        .badge-solicitud.rechazada { background: #fee2e2; color: #dc2626; }

        /* Fallbacks */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: #fff; padding: 20px; border-radius: 16px; box-shadow: 0 4px 14px rgba(10,39,72,.06); text-align: center; border: 1px solid #e2eaf4; }
        .stat-card .number { font-size: 32px; font-weight: 800; color: #0a2748; }
        .stat-card .label { color: #64748b; font-size: 13px; margin-top: 5px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }

        /* Modals y Botones */
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
        .btn-primary-modal { background: #0a2748; color: white; }
        .btn-primary-modal:hover { background: #1e4d8c; }
        .btn-secondary-modal { background: #e5e7eb; color: #1f2937; }
        .btn-secondary-modal:hover { background: #d1d5db; }

        /* Botones de filtro de estado */
        .btn-filter {
            background: #f8fafc;
            color: #64748b;
            border: 1.5px solid #e2eaf4;
            border-radius: 9px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-filter:hover {
            background: #f1f5f9;
            color: #0a2748;
            border-color: #cbd5e1;
        }
        .btn-filter.active {
            background: #0a2748;
            color: #fff;
            border-color: #0a2748;
            box-shadow: 0 4px 10px rgba(10,39,72,.15);
        }
        .btn-filter.active span {
            background: #fff !important;
        }
        .btn-filter span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-pendiente { background: #fbbf24; }
        .dot-aprobada { background: #10b981; }
        .dot-rechazada { background: #ef4444; }
    </style>
</head>
<body>

<?php
$navTitle = '';
$navIcon = '';
$navSubtitle = '';
require_once '_sidebar_supervisor.php';
?>

    <!-- HEADER -->
    <div class="ma-page-header">
        <div class="ma-page-icon"><i class="fas fa-file-circle-check"></i></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3" style="flex: 1;">
            <div>
                <h1 class="ma-page-title">Solicitudes de Asesores</h1>
                <p class="ma-page-sub">Aprueba o rechaza los registros de nuevos asesores</p>
            </div>
            <a href="mis_asesores.php" class="btn-outline-navy text-decoration-none">
                <i class="fas fa-arrow-left"></i> Regresar a Mi Equipo
            </a>
        </div>
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

    <!-- FILTROS DE BÚSQUEDA -->
    <div class="filter-bar" style="background: #fff; border: 1px solid #e2eaf4; border-radius: 14px; padding: 14px 18px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 24px; box-shadow: 0 4px 14px rgba(10,39,72,.05);">
        <div class="d-flex align-items-center gap-2" style="flex: 1; min-width: 280px;">
            <i class="fas fa-search" style="color:#64748b;"></i>
            <input type="text" id="busquedaSolicitud" placeholder="Buscar por nombre o usuario..." style="width:100%; padding:9px 14px; border:1.5px solid #d7e0ea; border-radius:9px; font-size:14px; outline:none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#0a2748'" onblur="this.style.borderColor='#d7e0ea'">
        </div>
        
        <div class="d-flex align-items-center gap-2 flex-wrap" id="statusFilters">
            <button class="btn-filter active" data-status="todos">Todos</button>
            <button class="btn-filter" data-status="pendiente" style="border-color: #fbbf2433;"><span class="dot-pendiente"></span>Pendientes</button>
            <button class="btn-filter" data-status="aprobada" style="border-color: #10b98133;"><span class="dot-aprobada"></span>Aprobadas</button>
            <button class="btn-filter" data-status="rechazada" style="border-color: #ef444433;"><span class="dot-rechazada"></span>Rechazadas</button>
        </div>
        
        <span id="cntResultados" style="font-size:13px; color:#64748b; margin-left:auto; font-weight: 600;"><?php echo count($solicitudes); ?> resultados</span>
    </div>

    <!-- GRID DE SOLICITUDES EN TARJETAS -->
    <div class="solicitudes-grid">
        <?php if (empty($solicitudes)): ?>
            <div class="col-12" style="grid-column: 1 / -1;">
                <div style="text-align: center; padding: 60px 20px; color: #94a3b8; background: #fff; border-radius: 18px; border: 1.5px dashed #d7e0ea;">
                    <i class="fas fa-inbox" style="font-size: 48px; display: block; margin-bottom: 14px; opacity: 0.3;"></i>
                    <p style="font-size: 15px; font-weight: 600; margin: 0;">No hay solicitudes pendientes o procesadas</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($solicitudes as $solicitud):
                $idSol = htmlspecialchars($solicitud['id_solicitud']);
                $nom = htmlspecialchars($solicitud['nombres'] . ' ' . $solicitud['apellidos']);
                $inicial = strtoupper(mb_substr(trim($solicitud['nombres']), 0, 1));
                $usr = htmlspecialchars($solicitud['usuario']);
                $em = htmlspecialchars($solicitud['email']);
                $tel = htmlspecialchars($solicitud['telefono']);
                $bco = htmlspecialchars($solicitud['banco']);
                $cta = htmlspecialchars($solicitud['numero_cuenta']);
                $t_cta = htmlspecialchars($solicitud['tipo_cuenta']);
                $est = $solicitud['estado']; // pendiente, aprobada, rechazada
                
                // Credencial
                $cred = $solicitud['credencial_archivo'];
                $credPath = '';
                $ext = '';
                if (!empty($cred)) {
                    if (str_contains($cred, 'documentos_asesor') || str_contains($cred, 'uploads/')) {
                        $credPath = '../../' . ltrim($cred, '/');
                    } else {
                        $credPath = '../../uploads/asesor_credentials/' . $cred;
                    }
                    $ext = strtolower(pathinfo($cred, PATHINFO_EXTENSION));
                }
            ?>
            <div class="sc" data-search-user="<?= strtolower($usr) ?>" data-search-name="<?= strtolower($nom) ?>" data-search-status="<?= $est ?>">
                <div class="sc-stripe <?= $est ?>"></div>
                <div class="sc-body">
                    <div class="sc-top">
                        <div class="sc-avatar <?= $est ?>"><?= $inicial ?></div>
                        <div style="min-width: 0; flex: 1;">
                            <h3 class="sc-name text-truncate" title="<?= $nom ?>"><?= $nom ?></h3>
                            <p class="sc-username"><i class="fas fa-at text-muted me-1"></i><?= $usr ?></p>
                        </div>
                    </div>
                    <div class="sc-info">
                        <span><i class="fas fa-envelope"></i> <?= $em ?></span>
                        <?php if ($tel): ?>
                        <span><i class="fas fa-phone"></i> <?= $tel ?></span>
                        <?php endif; ?>
                        <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($solicitud['fecha_solicitud'])) ?></span>
                    </div>

                    <?php if ($bco || $cta): ?>
                    <div class="sc-bank">
                        <div class="sc-bank-title"><i class="fas fa-university me-1"></i>Datos de Cuenta</div>
                        <div><strong>Banco:</strong> <?= $bco ?: 'No especificado' ?></div>
                        <div><strong>Cuenta:</strong> <?= $cta ?: 'No especificada' ?> (<?= $t_cta ?: 'Ahorros' ?>)</div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($solicitud['observaciones'])): ?>
                    <div style="margin-top: 10px; font-size: 11px; color: #ef4444; background: #fee2e2; padding: 8px 10px; border-radius: 8px; border: 1px solid #fca5a5;">
                        <strong>Observaciones:</strong> <?= htmlspecialchars($solicitud['observaciones']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="sc-footer">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span class="badge-solicitud <?= $est ?>">
                            <?php if ($est === 'pendiente'): ?><i class="fas fa-clock"></i>
                            <?php elseif ($est === 'aprobada'): ?><i class="fas fa-check-circle"></i>
                            <?php else: ?><i class="fas fa-times-circle"></i><?php endif; ?>
                            <?= ucfirst($est) ?>
                        </span>
                        
                        <?php if (!empty($cred)):
                            $icon = $ext === 'pdf' ? 'fa-file-pdf' : 'fa-file-image';
                            $color = $ext === 'pdf' ? '#ef4444' : '#3182fe';
                        ?>
                        <a href="<?= htmlspecialchars($credPath) ?>"
                           target="_blank"
                           title="Ver credencial (<?= strtoupper($ext) ?>)"
                           style="color:<?= $color ?>; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:4px; padding:4px 8px; border:1px solid <?= $color ?>33; border-radius:8px; font-size:11.5px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                            <i class="fas <?= $icon ?>"></i> Credencial
                        </a>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 5px;">
                        <?php if ($est === 'pendiente'): ?>
                        <button class="btn-aprobar btn-sm" style="padding: 6px 10px; border-radius: 8px;" onclick="mostrarModal('aprobar', '<?= htmlspecialchars(addslashes((string)$solicitud['id_solicitud'])) ?>', '<?= htmlspecialchars(addslashes($solicitud['nombres'])) ?>', '<?= htmlspecialchars(addslashes($solicitud['credencial_archivo'])) ?>')">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn-rechazar btn-sm" style="padding: 6px 10px; border-radius: 8px;" onclick="mostrarModal('rechazar', '<?= htmlspecialchars(addslashes((string)$solicitud['id_solicitud'])) ?>', '<?= htmlspecialchars(addslashes($solicitud['nombres'])) ?>', '<?= htmlspecialchars(addslashes($solicitud['credencial_archivo'])) ?>')">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php else: ?>
                        <span style="color: #94a3b8; font-size: 11.5px; font-weight: 600;"><i class="fas fa-check-double me-1"></i>Procesada</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
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

// Lógica de búsqueda y filtrado de solicitudes en tarjetas
document.addEventListener('DOMContentLoaded', function() {
    const inputBusqueda = document.getElementById('busquedaSolicitud');
    const cntResultados = document.getElementById('cntResultados');
    const filterButtons = document.querySelectorAll('.btn-filter');
    let currentStatus = 'todos';

    function aplicarFiltros() {
        const term = inputBusqueda ? inputBusqueda.value.toLowerCase().trim() : '';
        const cards = document.querySelectorAll('.solicitudes-grid .sc');
        let visibles = 0;

        cards.forEach(card => {
            const usuario = card.getAttribute('data-search-user') || '';
            const nombre = card.getAttribute('data-search-name') || '';
            const status = card.getAttribute('data-search-status') || '';

            // Comprobar coincidencia de texto
            const matchesSearch = usuario.includes(term) || nombre.includes(term);
            
            // Comprobar coincidencia de estado
            const matchesStatus = (currentStatus === 'todos' || status === currentStatus);

            if (matchesSearch && matchesStatus) {
                card.style.display = '';
                visibles++;
            } else {
                card.style.display = 'none';
            }
        });

        if (cntResultados) {
            cntResultados.textContent = visibles + (visibles === 1 ? ' resultado' : ' resultados');
        }

        let emptyPlaceholder = document.getElementById('emptyFiltered');
        if (visibles === 0 && cards.length > 0) {
            if (!emptyPlaceholder) {
                const grid = document.querySelector('.solicitudes-grid');
                const div = document.createElement('div');
                div.id = 'emptyFiltered';
                div.className = 'col-12';
                div.style.gridColumn = '1 / -1';
                div.innerHTML = '<div style="text-align:center;padding:48px 20px;color:#94a3b8;background:#fff;border-radius:18px;border:1.5px dashed #d7e0ea;"><i class="fas fa-search" style="font-size:32px;display:block;margin-bottom:12px;opacity:.4;"></i>No hay solicitudes que coincidan con la búsqueda.</div>';
                grid.appendChild(div);
            } else {
                emptyPlaceholder.style.display = '';
            }
        } else if (emptyPlaceholder) {
            emptyPlaceholder.style.display = 'none';
        }
    }

    if (inputBusqueda) {
        inputBusqueda.addEventListener('input', aplicarFiltros);
    }

    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatus = this.getAttribute('data-status');
            aplicarFiltros();
        });
    });
});
</script>

</body>
</html>

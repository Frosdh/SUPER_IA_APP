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
                        id_cooperativa VARCHAR(36) NOT NULL,
                        id_administrador VARCHAR(64) NOT NULL,
                        usuario VARCHAR(50) NOT NULL UNIQUE,
                        cedula VARCHAR(13) NULL,
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

                // Verificar si la columna cedula existe, si no agregarla
                $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'cedula'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE solicitudes_supervisor ADD COLUMN cedula VARCHAR(13) NULL AFTER usuario");
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
                        // Asegurar que la columna cedula exista en la tabla usuarios
                        $chkCed = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'cedula'");
                        if (!$chkCed->fetch()) {
                            $pdo->exec("ALTER TABLE usuarios ADD COLUMN cedula VARCHAR(13) NULL AFTER usuario");
                        }

                        // Insertar usuario en tabla usuarios con rol Supervisor (asumiendo id_rol = 3)
                        $stmt = $pdo->prepare("
                            INSERT INTO usuarios (usuario, clave, nombres, apellidos, email, telefono, cedula, activo, id_rol_fk)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 3)
                        ");

                        $stmt->execute([
                            $solicitud['usuario'],
                            $solicitud['password_hash'],
                            $solicitud['nombres'],
                            $solicitud['apellidos'],
                            $solicitud['email'],
                            $solicitud['telefono'],
                            $solicitud['cedula']
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
            id_cooperativa VARCHAR(36) NOT NULL,
            id_administrador VARCHAR(64) NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            cedula VARCHAR(13) NULL,
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

    // Verificar si la columna cedula existe, si no agregarla
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'cedula'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE solicitudes_supervisor ADD COLUMN cedula VARCHAR(13) NULL AFTER usuario");
    }

    // Corregir tipo de id_administrador / id_cooperativa si la tabla ya
    // existía como INT. El gerente y la cooperativa reales son UUID
    // (VARCHAR), no un entero: si estas columnas se crearon como INT en
    // algún momento, MySQL truncaba/convertía ese UUID a un número al
    // guardar la solicitud, y luego "WHERE id_administrador = ..." (con el
    // UUID de la sesión) nunca coincidía — por eso el gerente no veía las
    // solicitudes de supervisor que le correspondían.
    $colInfo = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'id_administrador'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
        $pdo->exec("ALTER TABLE solicitudes_supervisor MODIFY COLUMN id_administrador VARCHAR(64) NOT NULL DEFAULT ''");
    }
    $colInfo = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'id_cooperativa'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
        $pdo->exec("ALTER TABLE solicitudes_supervisor MODIFY COLUMN id_cooperativa VARCHAR(36) NOT NULL DEFAULT ''");
    }
} catch (Exception $e) {}

// Obtener solicitudes de supervisores
$solicitudes = [];
try {
    $query = "SELECT * FROM solicitudes_supervisor ";
    $params = [];

    // Si es admin (no super admin), solo ver sus propias solicitudes.
    // id_administrador es un UUID (VARCHAR) — antes se usaba intval($admin_id),
    // que truncaba el UUID a 0 y nunca traía resultados.
    if (!$is_super_admin && $is_admin) {
        $query .= "WHERE id_administrador = ? ";
        $params[] = $admin_id;
    }

    $query .= "ORDER BY
            CASE estado
                WHEN 'pendiente' THEN 1
                WHEN 'rechazada' THEN 2
                WHEN 'aprobada' THEN 3
            END,
            fecha_solicitud DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $solicitudes = $stmt->fetchAll();
} catch (Exception $e) {}

$currentPage = 'solicitudes_supervisor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Solicitudes de Supervisores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffdd00; --brand-navy: #123a6d; --brand-navy-deep: #0a2748;
            --brand-border: #d7e0ea; --brand-bg: #f4f6f9; --brand-shadow: 0 16px 34px rgba(18,58,109,.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter','Segoe UI',sans-serif; background: linear-gradient(180deg,#f8fafc 0%,var(--brand-bg) 100%); display: flex; height: 100vh; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 24px; }
        .stat-card { background: #fff; padding: 22px 20px; border-radius: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; border: 1px solid var(--brand-border); }
        .stat-card .number { font-size: 34px; font-weight: 800; }
        .stat-card .label { color: #9ca3af; font-size: 13px; margin-top: 5px; font-weight: 500; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
        .alert-danger  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; }
        .table-card { background: #fff; border-radius: 16px; box-shadow: var(--brand-shadow); overflow: hidden; border: 1px solid var(--brand-border); }
        .table-card .card-header-custom { padding: 18px 22px; border-bottom: 1px solid var(--brand-border); }
        .table-card h6 { font-weight: 700; margin: 0; font-size: 16px; color: var(--brand-navy-deep); }
        .table { margin-bottom: 0; }
        .table thead th { background: #f4f6f9; font-size: 11px; text-transform: uppercase; color: #6c757d; border: none; padding: 13px 14px; letter-spacing: .4px; }
        .table tbody td { padding: 13px 14px; vertical-align: middle; border-color: #f0f4f8; font-size: 13.5px; }
        .table tbody tr:hover { background: #f8fafc; }
        .badge-pendiente { background: #fef08a; color: #713f12; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; }
        .badge-aprobada  { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; }
        .badge-rechazada { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; }
        .btn-aprobar  { background: #10b981; color: #fff; border: none; padding: 6px 13px; border-radius: 7px; cursor: pointer; font-size: 12px; font-weight: 700; }
        .btn-aprobar:hover  { background: #059669; }
        .btn-rechazar { background: #ef4444; color: #fff; border: none; padding: 6px 13px; border-radius: 7px; cursor: pointer; font-size: 12px; font-weight: 700; }
        .btn-rechazar:hover { background: #dc2626; }
        .sol-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.45); align-items: center; justify-content: center; z-index: 1050; }
        .sol-modal.show { display: flex; }
        .sol-modal-box { background: #fff; border-radius: 16px; padding: 2rem; max-width: 520px; width: 92%; box-shadow: 0 24px 48px rgba(0,0,0,.18); }
        .sol-modal-box h5 { margin: 0 0 1.2rem; font-weight: 800; color: var(--brand-navy-deep); font-size: 18px; }
        .sol-modal-box textarea { width: 100%; padding: 10px 14px; border: 1.5px solid #e5e7eb; border-radius: 9px; font-family: inherit; resize: vertical; font-size: 13.5px; margin-top: 10px; }
        .sol-modal-footer { display: flex; gap: 10px; justify-content: flex-end; margin-top: 1.4rem; }
        .sol-modal-footer button { padding: 9px 20px; border-radius: 9px; border: none; cursor: pointer; font-weight: 700; font-size: 14px; }
        .btn-confirm { background: linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color: #fff; }
        .btn-cancel  { background: #e5e7eb; color: #374151; }
        .page-title { font-size: 26px; font-weight: 800; color: var(--brand-navy-deep); margin-bottom: 4px; }
        .page-sub   { font-size: 14px; color: #64748b; margin-bottom: 22px; }
    </style>
</head>
<body>

<?php
$alertas_pendientes = 0;
require_once '_sidebar_gerente.php';
?>

    <div style="padding: 30px; padding-bottom: 40px;">

        <a href="mis_supervisores.php" style="display:inline-flex;align-items:center;gap:8px;padding:8px 18px;background:rgba(18,58,109,.08);color:#0a2748;border:1.5px solid #d7e0ea;border-radius:10px;text-decoration:none;font-weight:600;font-size:13.5px;margin-bottom:14px;transition:background .2s;" onmouseover="this.style.background='rgba(18,58,109,.15)'" onmouseout="this.style.background='rgba(18,58,109,.08)'">
            <i class="fas fa-arrow-left"></i> Volver a Mis Supervisores
        </a>
        <div class="page-title"><i class="fas fa-clipboard-list me-2"></i>Solicitudes de Supervisores</div>
        <p class="page-sub">Revisa y gestiona las solicitudes de registro de supervisores.</p>

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
        <div class="filter-bar" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 18px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,.04);">
            <i class="fas fa-search" style="color:#6b7280;"></i>
            <input type="text" id="busquedaSolicitud" placeholder="Buscar por nombre o email..." style="min-width:260px; flex:1; padding:9px 14px; border:1.5px solid #e2e8f0; border-radius:9px; font-size:14px; outline:none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#fbbf24'" onblur="this.style.borderColor='#e2e8f0'">
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="btn-filter active" data-status="todos" style="padding:7px 14px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;">Todos</button>
                <button type="button" class="btn-filter" data-status="pendiente" style="padding:7px 14px;border:1.5px solid #fbbf2433;border-radius:8px;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;">Pendientes</button>
                <button type="button" class="btn-filter" data-status="aprobada" style="padding:7px 14px;border:1.5px solid #10b98133;border-radius:8px;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;">Aprobadas</button>
                <button type="button" class="btn-filter" data-status="rechazada" style="padding:7px 14px;border:1.5px solid #ef444433;border-radius:8px;background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;">Rechazadas</button>
            </div>
            <span id="cntResultados" style="font-size:13px; color:#6b7280; margin-left:auto;"><?php echo count($solicitudes); ?> resultados</span>
        </div>
        <style>
            .btn-filter.active { background: var(--brand-navy-deep) !important; color: #fff !important; border-color: var(--brand-navy-deep) !important; }
        </style>

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
                            <th>Cédula</th>
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
                            <td colspan="8" style="text-align: center; color: #9ca3af; padding: 30px;">
                                <i class="fas fa-inbox me-2"></i>No hay solicitudes
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($solicitudes as $solicitud):
                                // Credencial: verificar en disco (is_file) igual que en
                                // solicitudes de asesor, para no mostrar "Ver Credencial"
                                // si el archivo en realidad no existe ahí.
                                $credSup = $solicitud['credencial_archivo'] ?? '';
                                $credSupPath = '';
                                $credSupExiste = false;
                                if (!empty($credSup)) {
                                    $rutaFisicaSup = __DIR__ . '/../../uploads/supervisor_credentials/' . basename($credSup);
                                    if (is_file($rutaFisicaSup)) {
                                        $credSupExiste = true;
                                        $credSupPath = '../../uploads/supervisor_credentials/' . basename($credSup);
                                    }
                                }
                            ?>
                            <tr data-search-status="<?php echo htmlspecialchars($solicitud['estado']); ?>">
                                <td><strong><?php echo htmlspecialchars($solicitud['usuario']); ?></strong></td>
                                <td><?php echo htmlspecialchars($solicitud['cedula'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($solicitud['nombres'] . ' ' . $solicitud['apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($solicitud['email']); ?></td>
                                <td>
                                    <?php if ($credSupExiste): ?>
                                    <a href="<?php echo htmlspecialchars($credSupPath); ?>"
                                       target="_blank"
                                       style="color: #3182fe; text-decoration: none; font-weight: 600;">
                                        <i class="fas fa-file-pdf me-1"></i>Ver Credencial
                                    </a>
                                    <?php elseif (!empty($credSup)): ?>
                                    <span style="color: #9ca3af; font-size: 12px;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>Credencial no encontrada
                                    </span>
                                    <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 12px;">
                                        <i class="fas fa-file-circle-xmark me-1"></i>Sin credencial
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
                                    <button class="btn-aprobar" onclick="mostrarModal('aprobar', <?php echo $solicitud['id_solicitud']; ?>, '<?php echo $credSupExiste ? htmlspecialchars(addslashes($credSupPath)) : ''; ?>')">
                                        <i class="fas fa-check me-1"></i>Aprobar
                                    </button>
                                    <button class="btn-rechazar" onclick="mostrarModal('rechazar', <?php echo $solicitud['id_solicitud']; ?>, '<?php echo $credSupExiste ? htmlspecialchars(addslashes($credSupPath)) : ''; ?>')" style="margin-left: 5px;">
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

    </div><!-- /.inner padding -->
</div><!-- /.content-area -->
</div><!-- /.main-content -->

<!-- MODAL SOLICITUD -->
<div id="solModal" class="sol-modal">
    <div class="sol-modal-box">
        <h5 id="solModalTitle">Confirmar</h5>
        <form id="solModalForm" method="POST">
            <input type="hidden" name="id_solicitud" id="input-solicitud">
            <input type="hidden" name="accion" id="input-accion">
            <div id="modal-body-content"></div>
            <textarea id="observaciones" name="observaciones" placeholder="Observaciones (opcional)..." style="display:none;"></textarea>
            <div class="sol-modal-footer">
                <button type="button" class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
                <button type="submit" class="btn-confirm" id="btn-confirmar">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script>
function mostrarModal(accion, id, credencial) {
    const modal = document.getElementById('solModal');
    const title = document.getElementById('solModalTitle');
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
    
    // Agregar sección de credencial.
    // "credencial" ya llega como la ruta relativa VERIFICADA en el servidor
    // (is_file() ya confirmó que el archivo existe ahí); si no se pudo
    // confirmar, llega vacío y se muestra el aviso de "no disponible".
    if (credencial) {
        modalHTML += '<hr style="margin: 15px 0; border: none; border-top: 1px solid #e5e7eb;">';
        modalHTML += '<p style="margin-bottom: 10px; color: #6c757d; font-size: 13px;"><strong>Credencial:</strong></p>';
        const ext = credencial.split('.').pop().toLowerCase();
        if (ext === 'pdf') {
            modalHTML += '<embed src="' + credencial + '" type="application/pdf" style="width: 100%; height: 300px; border: 1px solid #e5e7eb; border-radius: 6px;">';
        } else {
            modalHTML += '<img src="' + credencial + '" style="max-width: 100%; max-height: 300px; border: 1px solid #e5e7eb; border-radius: 6px;">';
        }
        modalHTML += '<p style="margin-top: 10px;"><a href="' + credencial + '" target="_blank" style="color: #3182fe; text-decoration: none; font-size: 12px;"><i class="fas fa-download me-1"></i>Descargar archivo</a></p>';
    } else {
        modalHTML += '<hr style="margin: 15px 0; border: none; border-top: 1px solid #e5e7eb;">';
        modalHTML += '<p style="color: #fbbf24; font-size: 12px;"><i class="fas fa-exclamation-triangle me-1"></i>⚠️ No hay credencial disponible</p>';
    }
    
    modalBody.innerHTML = modalHTML;
    modal.classList.add('show');
}

function cerrarModal() {
    document.getElementById('solModal').classList.remove('show');
}

// Lógica de búsqueda + filtro por estado en la tabla
document.addEventListener('DOMContentLoaded', function() {
    const inputBusqueda = document.getElementById('busquedaSolicitud');
    const cntResultados = document.getElementById('cntResultados');
    const filterButtons = document.querySelectorAll('.btn-filter');
    let currentStatus = 'todos';

    function aplicarFiltros() {
        const term = inputBusqueda ? inputBusqueda.value.toLowerCase().trim() : '';
        const filas = document.querySelectorAll('.table tbody tr:not(.empty-row)');
        let visibles = 0;

        filas.forEach(fila => {
            // Si la fila es la de "No hay solicitudes", la ignoramos
            if (fila.querySelector('td[colspan]')) return;

            const estado = (fila.dataset.searchStatus || '').toLowerCase();
            const usuario = fila.querySelector('td:nth-child(1)').textContent.toLowerCase();
            const cedula = fila.querySelector('td:nth-child(2)').textContent.toLowerCase();
            const nombre = fila.querySelector('td:nth-child(3)').textContent.toLowerCase();
            const email = fila.querySelector('td:nth-child(4)').textContent.toLowerCase();

            const matchBusq = !term || usuario.includes(term) || cedula.includes(term) || nombre.includes(term) || email.includes(term);
            const matchFilt = currentStatus === 'todos' || estado === currentStatus;

            if (matchBusq && matchFilt) {
                fila.style.display = '';
                visibles++;
            } else {
                fila.style.display = 'none';
            }
        });

        if (cntResultados) cntResultados.textContent = visibles + (visibles === 1 ? ' resultado' : ' resultados');

        let emptyRow = document.querySelector('.empty-row');
        if (visibles === 0 && filas.length > 0) {
            if (!emptyRow) {
                const tbody = document.querySelector('.table tbody');
                const tr = document.createElement('tr');
                tr.className = 'empty-row';
                tr.innerHTML = '<td colspan="8" style="text-align:center;padding:32px 0;color:#9ca3af;"><i class="fas fa-search" style="font-size:28px;display:block;margin-bottom:10px;opacity:.4;"></i>No hay solicitudes que coincidan con el filtro.</td>';
                tbody.appendChild(tr);
            }
        } else {
            if (emptyRow) emptyRow.remove();
        }
    }

    if (inputBusqueda) inputBusqueda.addEventListener('input', aplicarFiltros);

    filterButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            filterButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStatus = this.dataset.status || 'todos';
            aplicarFiltros();
        });
    });
});

document.getElementById('solModalForm').onsubmit = function(e) {
    e.preventDefault();
    this.submit();
};

document.getElementById('solModal').onclick = function(e) {
    if (e.target === this) cerrarModal();
};
</script>

</body>
</html>

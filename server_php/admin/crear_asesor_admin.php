<?php
require_once 'db_admin.php';

// Verificar si es admin o super admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

$admin_id = $is_super_admin ? $_SESSION['super_admin_id'] : $_SESSION['admin_id'];
$admin_nombre = $is_super_admin ? $_SESSION['super_admin_nombre'] : $_SESSION['admin_nombre'];

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

// Obtener cooperativas/bancos desde tabla unidad_bancaria
$cooperativas = [];
try {
    $stmt = $pdo->query("
        SELECT id, nombre, codigo
        FROM unidad_bancaria 
        WHERE activo = 1 
        ORDER BY nombre ASC
    ");
    $cooperativas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Si no existe, intentar alternativas
    error_log("Error cargando cooperativas: " . $e->getMessage());
}

// Obtener supervisores disponibles (dinamicamente basado en cooperativa seleccionada)
$supervisores = [];
try {
    $stmt = $pdo->query("
        SELECT u.id, u.nombre, u.email, u.rol
        FROM usuario u
        WHERE u.rol = 'supervisor' AND u.activo = 1 AND u.estado_aprobacion = 'aprobado'
        ORDER BY u.nombre ASC
    ");
    $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando supervisores: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Crear Asesor</title>
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
        .form-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); padding: 30px; max-width: 700px; margin: 0 auto; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #1f2937; font-size: 14px; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 14px; transition: all 0.3s ease; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .form-group input::placeholder { color: #d1d5db; }
        .row-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .row-cols .form-group { margin-bottom: 0; }
        .file-upload { border: 2px dashed rgba(102, 126, 234, 0.5); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s ease; background: rgba(102, 126, 234, 0.05); }
        .file-upload:hover { border-color: #667eea; background: rgba(102, 126, 234, 0.1); }
        .file-input-label { cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .file-input-label i { font-size: 32px; color: #667eea; }
        .file-input-label div { font-weight: 600; color: #1f2937; }
        .file-input-label small { color: #9ca3af; }
        .file-name { margin-top: 10px; color: #10b981; font-weight: 600; font-size: 13px; display: none; }
        .file-name.show { display: block; }
        .btn-submit { width: 100%; padding: 12px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 16px; transition: all 0.3s ease; margin-top: 20px; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4); }
        .btn-back { padding: 8px 16px; background: #e5e7eb; color: #1f2937; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-weight: 600; margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-back:hover { background: #d1d5db; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        #credencial { display: none; }
        input[type="file"] { display: none; }
        @media (max-width: 600px) { .row-cols { grid-template-columns: 1fr; } .form-card { padding: 20px; } }
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
        <a href="crear_asesor_admin.php" class="sidebar-link active">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> Super_IA - Crear Asesor</h2>
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
            <a href="index.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <h1><i class="fas fa-user-tie me-2"></i>Crear Nueva Solicitud de Asesor</h1>
        </div>

        <div class="form-card">

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>✅ Solicitud de asesor creada exitosamente.
            </div>
            <?php endif; ?>

            <form method="POST" action="procesar_crear_asesor_admin.php" enctype="multipart/form-data" novalidate>

                <!-- SELECCIÓN DE SUPERVISOR -->
                <h6 style="margin-top: 0; margin-bottom: 15px; color: #1f2937; font-weight: 700;">
                    <i class="fas fa-bank me-2"></i>Cooperativa / Banco
                </h6>

                <div class="form-group">
                    <label><i class="fas fa-building me-2"></i>Selecciona Cooperativa / Banco</label>
                    <select name="unidad_bancaria_id" id="selectCooperativa" required onchange="cargarSupervisores()">
                        <option value="">-- Selecciona una cooperativa --</option>
                        <?php foreach ($cooperativas as $coop): ?>
                        <option value="<?php echo htmlspecialchars($coop['id']); ?>">
                            <?php echo htmlspecialchars($coop['nombre'] . ' (' . $coop['codigo'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($cooperativas)): ?>
                    <small style="color: #ef4444;">⚠️ No hay cooperativas registradas. Contacta al administrador.</small>
                    <?php endif; ?>
                </div>

                <!-- SUPERVISORES (se cargan dinámicamente según cooperativa) -->
                <h6 style="margin-top: 20px; margin-bottom: 15px; color: #1f2937; font-weight: 700;">
                    <i class="fas fa-user-check me-2"></i>Supervisor Asignado
                </h6>

                <div class="form-group">
                    <label><i class="fas fa-user-tie me-2"></i>Selecciona Supervisor</label>
                    <select name="id_supervisor" id="selectSupervisor" required>
                        <option value="">-- Primero selecciona una cooperativa --</option>
                        <?php foreach ($supervisores as $sup): ?>
                        <option value="<?php echo htmlspecialchars($sup['id']); ?>" data-cooperativa="<?php echo htmlspecialchars($sup['id']); ?>">
                            <?php echo htmlspecialchars($sup['nombre'] . ' (' . $sup['email'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- DATOS PERSONALES -->
                <h6 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; font-weight: 700;">
                    <i class="fas fa-user me-2"></i>Datos Personales
                </h6>

                <div class="row-cols">
                    <div class="form-group">
                        <label><i class="fas fa-user me-2"></i>Nombres</label>
                        <input type="text" name="nombres" placeholder="Ej: Juan Carlos" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user me-2"></i>Apellidos</label>
                        <input type="text" name="apellidos" placeholder="Ej: García López" required>
                    </div>
                </div>

                <div class="row-cols">
                    <div class="form-group">
                        <label><i class="fas fa-envelope me-2"></i>Email</label>
                        <input type="email" name="email" placeholder="Ej: correo@ejemplo.com" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone me-2"></i>Teléfono</label>
                        <input type="tel" name="telefono" placeholder="Ej: 0987654321" required>
                    </div>
                </div>

                <!-- CUENTA DE USUARIO -->
                <h6 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; font-weight: 700;">
                    <i class="fas fa-lock me-2"></i>Cuenta de Usuario
                </h6>

                <div class="row-cols">
                    <div class="form-group">
                        <label><i class="fas fa-envelope me-2"></i>Correo Electrónico</label>
                        <input type="email" name="email" placeholder="Ej: juan.garcia@example.com" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-key me-2"></i>Contraseña</label>
                        <input type="password" name="password" placeholder="Min. 6 caracteres" minlength="6" required>
                    </div>
                </div>

                <!-- DATOS BANCARIOS -->
                <!-- Comentado: No requerido en SUPER_IA LOGAN -->
                <!--
                <h6 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; font-weight: 700;">
                    <i class="fas fa-university me-2"></i>Datos Bancarios
                </h6>

                <div class="form-group">
                    <label><i class="fas fa-building me-2"></i>Banco</label>
                    <select name="banco" required>
                        <option value="">-- Selecciona un banco --</option>
                        <?php if (!empty($bancos)): ?>
                            <?php foreach ($bancos as $bancoNombre): ?>
                                <option value="<?php echo htmlspecialchars($bancoNombre); ?>"><?php echo htmlspecialchars($bancoNombre); ?></option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No hay bancos registrados en la base</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-credit-card me-2"></i>Número de Cuenta</label>
                    <input type="text" name="numero_cuenta" placeholder="Ej: 1234567890" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-id-card me-2"></i>Tipo de Cuenta</label>
                    <select name="tipo_cuenta" required>
                        <option value="">-- Selecciona tipo --</option>
                        <option value="Ahorros">Cuenta de Ahorros</option>
                        <option value="Corriente">Cuenta Corriente</option>
                        <option value="Nómina">Cuenta de Nómina</option>
                    </select>
                </div>
                -->

                <!-- ARCHIVO DE CREDENCIAL -->
                <h6 style="margin-top: 30px; margin-bottom: 15px; color: #1f2937; font-weight: 700;">
                    <i class="fas fa-file-pdf me-2"></i>Credencial / Nombramiento
                </h6>

                <div class="form-group">
                    <label><i class="fas fa-file-pdf me-2"></i>Credencial / Nombramiento (PDF o Imagen)</label>
                    <div class="file-upload">
                        <label for="credencial" class="file-input-label">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <div>Haz clic o arrastra tu archivo aquí</div>
                            <small>(PDF, JPG, PNG – Máx. 5MB)</small>
                            <div class="file-name" id="file-name"></div>
                        </label>
                        <input type="file" name="credencial" id="credencial" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane me-2"></i>Crear Solicitud de Asesor
                </button>

            </form>

        </div>
    </div>
</div>

<script>
// Manejo del file upload
const fileInput = document.getElementById('credencial');
const fileLabel = document.querySelector('.file-input-label');
const fileName = document.getElementById('file-name');

fileLabel.addEventListener('click', () => {
    fileInput.click();
});

fileInput.addEventListener('change', function(e) {
    const file = this.files[0];
    if (file) {
        const maxSize = 5 * 1024 * 1024; // 5MB
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        
        if (!allowedTypes.includes(file.type)) {
            fileName.textContent = '❌ Tipo de archivo no permitido (PDF, JPG, PNG)';
            fileName.classList.add('show');
            this.value = '';
            return;
        }
        
        if (file.size > maxSize) {
            fileName.textContent = '❌ Archivo muy grande (máx. 5MB)';
            fileName.classList.add('show');
            this.value = '';
            return;
        }
        
        fileName.textContent = '✅ ' + file.name;
        fileName.classList.add('show');
    }
});

fileLabel.addEventListener('dragover', (e) => {
    e.preventDefault();
    fileLabel.style.background = 'rgba(255, 255, 255, 0.1)';
    fileLabel.style.borderColor = '#667eea';
});

fileLabel.addEventListener('dragleave', () => {
    fileLabel.style.background = 'rgba(102, 126, 234, 0.05)';
    fileLabel.style.borderColor = 'rgba(102, 126, 234, 0.5)';
});

fileLabel.addEventListener('drop', (e) => {
    e.preventDefault();
    fileLabel.style.background = 'rgba(102, 126, 234, 0.05)';
    fileLabel.style.borderColor = 'rgba(102, 126, 234, 0.5)';
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        const event = new Event('change', { bubbles: true });
        fileInput.dispatchEvent(event);
    }
});

// ============================================================
// Cargar supervisores dinámicamente según cooperativa seleccionada
// ============================================================
async function cargarSupervisores() {
    const selectCooperativa = document.getElementById('selectCooperativa');
    const selectSupervisor = document.getElementById('selectSupervisor');
    const cooperativaId = selectCooperativa.value;

    if (!cooperativaId) {
        selectSupervisor.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>';
        return;
    }

    try {
        // Mostrar loading
        selectSupervisor.innerHTML = '<option value="">Cargando supervisores...</option>';
        selectSupervisor.disabled = true;

        // Hacer petición al API
        const formData = new FormData();
        formData.append('cooperativa_id', cooperativaId);

        const response = await fetch('api_supervisores_por_cooperativa.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.status === 'success' && data.supervisores && data.supervisores.length > 0) {
            // Construir opciones
            let html = '<option value="">-- Selecciona un supervisor --</option>';
            data.supervisores.forEach(supervisor => {
                html += `<option value="${supervisor.id}">
                    ${supervisor.nombre} (${supervisor.email})
                </option>`;
            });
            selectSupervisor.innerHTML = html;
            selectSupervisor.disabled = false;
        } else {
            selectSupervisor.innerHTML = '<option value="">No hay supervisores disponibles</option>';
            selectSupervisor.disabled = false;
        }
    } catch (error) {
        console.error('Error cargando supervisores:', error);
        selectSupervisor.innerHTML = '<option value="">Error al cargar supervisores</option>';
        selectSupervisor.disabled = false;
    }
}
</script>

</body>
</html>

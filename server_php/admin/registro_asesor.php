<?php
require_once 'db_admin.php';

// Verificar si es supervisor
$supervisor_logged_in = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;

if (!$supervisor_logged_in) {
    header('Location: ../../login.php?role=supervisor');
    exit;
}

$supervisor_id = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? null;

// Obtener bancos desde BD (tabla que contenga "banco" en el nombre)
$bancos = [];
try {
    $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%banco%' ORDER BY table_name ASC")->fetchAll();
    foreach ($tables as $t) {
        $table = $t['table_name'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            continue;
        }
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
        $fields = array_map(fn($c) => $c['Field'], $cols);
        $nameCol = null;
        foreach (['nombre', 'nombre_banco', 'banco', 'descripcion'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $nameCol = $candidate;
                break;
            }
        }
        if (!$nameCol) {
            continue;
        }
        $stmt = $pdo->query("SELECT DISTINCT `{$nameCol}` AS nombre FROM `{$table}` WHERE `{$nameCol}` IS NOT NULL AND `{$nameCol}` <> '' ORDER BY `{$nameCol}` ASC");
        $rows = $stmt->fetchAll();
        $bancos = array_values(array_filter(array_map(fn($r) => $r['nombre'] ?? '', $rows)));
        if (!empty($bancos)) {
            break;
        }
    }
} catch (Exception $e) {
    $bancos = [];
}

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Crear Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .form-container { background: white; border-radius: 16px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2); max-width: 600px; width: 100%; padding: 40px; }
        .form-header { text-align: center; margin-bottom: 30px; }
        .form-header h1 { font-size: 28px; font-weight: 700; color: #1f2937; margin-bottom: 8px; }
        .form-header p { color: #9ca3af; font-size: 14px; }
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
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        #credencial { display: none; }
        input[type="file"] { display: none; }
        @media (max-width: 600px) { .row-cols { grid-template-columns: 1fr; } .form-container { padding: 25px; } }
    </style>
</head>
<body>

<div class="form-container">
    <div class="form-header">
        <h1><i class="fas fa-user-tie me-2"></i>Crear Cuenta de Asesor</h1>
        <p>Ingresa los datos del nuevo asesor para tu equipo</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>✅ Solicitud de asesor registrada exitosamente. Espera la aprobación del administrador.
    </div>
    <?php endif; ?>

    <form method="POST" action="procesar_registro_asesor.php" enctype="multipart/form-data" novalidate>
        
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
                <label><i class="fas fa-user-circle me-2"></i>Usuario</label>
                <input type="text" name="usuario" placeholder="Ej: jgarcia" minlength="4" required>
            </div>
            <div class="form-group">
                <label><i class="fas fa-key me-2"></i>Contraseña</label>
                <input type="password" name="password" placeholder="Min. 6 caracteres" minlength="6" required>
            </div>
        </div>

        <!-- DATOS BANCARIOS -->
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
            <i class="fas fa-paper-plane me-2"></i>Enviar Solicitud
        </button>

    </form>
    <
</div>

<script>
// Manejo del file upload
const fileInput = document.getElementById('credencial');
const fileLabel = document.querySelector('.file-input-label');
const fileName = document.getElementById('file-name');

// Clic en el label
fileLabel.addEventListener('click', () => {
    fileInput.click();
});

// Cambio de archivo
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

// Drag and drop
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
        // Trigger change event
        const event = new Event('change', { bubbles: true });
        fileInput.dispatchEvent(event);
    }
});
</script>

</body>
</html>
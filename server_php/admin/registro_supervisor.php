<?php
require_once 'db_admin.php';

$success = isset($_GET['success']) ? $_GET['success'] : false;
$error = isset($_GET['error']) ? $_GET['error'] : false;

// Obtener cooperativas
$cooperativas = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT id_cooperativa, nombre 
        FROM (
            SELECT 1 as id_cooperativa, 'COAC Finance - Quito' as nombre
            UNION SELECT 2, 'COAC Finance - Guayaquil'
            UNION SELECT 3, 'COAC Finance - Cuenca'
            UNION SELECT 4, 'COAC Finance - Ambato'
        ) a
        ORDER BY nombre ASC
    ");
    $cooperativas = $stmt->fetchAll();
} catch (Exception $e) {
    $cooperativas = [
        ['id_cooperativa' => 1, 'nombre' => 'COAC Finance - Quito'],
        ['id_cooperativa' => 2, 'nombre' => 'COAC Finance - Guayaquil'],
        ['id_cooperativa' => 3, 'nombre' => 'COAC Finance - Cuenca'],
        ['id_cooperativa' => 4, 'nombre' => 'COAC Finance - Ambato']
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Crear Cuenta de Supervisor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f8fafc;
            padding: 2rem;
        }
        .container-custom {
            max-width: 650px;
            width: 100%;
        }
        .card-custom {
            background: rgba(30, 41, 59, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 3rem;
            backdrop-filter: blur(10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        }
        .header-custom {
            text-align: center;
            margin-bottom: 2rem;
        }
        .icon-header {
            font-size: 3rem;
            color: #3182fe;
            margin-bottom: 1rem;
        }
        .header-custom h1 {
            font-weight: 800;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .header-custom p {
            color: #94a3b8;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            font-weight: 600;
            color: #e2e8f0;
            margin-bottom: 0.6rem;
            display: block;
        }
        .form-control {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            padding: 0.8rem 1rem;
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s;
            width: 100%;
        }
        .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: #3182fe;
            color: #f8fafc;
            box-shadow: 0 0 10px rgba(49, 130, 254, 0.3);
        }
        .form-control::placeholder {
            color: #64748b;
        }
        .form-control option {
            background: #1e293b;
            color: #f8fafc;
            padding: 0.5rem;
        }
        select.form-control {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%233182fe' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
            cursor: pointer;
        }
        .btn-submit {
            width: 100%;
            padding: 0.8rem 1.5rem;
            background: linear-gradient(135deg, #3182fe, #1e40af);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 1rem;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(49, 130, 254, 0.3);
        }
        .btn-back {
            width: 100%;
            padding: 0.8rem 1.5rem;
            background: rgba(255, 255, 255, 0.1);
            color: #e2e8f0;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            display: block;
            text-align: center;
            transition: all 0.3s;
            margin-top: 1rem;
        }
        .btn-back:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #f8fafc;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid #10b981;
            color: #d1fae5;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid #ef4444;
            color: #fee2e2;
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #93c5fd;
        }
        .row-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 600px) {
            .row-cols {
                grid-template-columns: 1fr;
            }
        }
        .file-upload {
            position: relative;
            display: block;
        }
        .file-upload input[type="file"] {
            display: none;
        }
        .file-input-label {
            display: block;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 2px dashed rgba(49, 130, 254, 0.5);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            color: #94a3b8;
        }
        .file-input-label:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: #3182fe;
            color: #e2e8f0;
        }
        .file-input-label i {
            font-size: 1.8rem;
            color: #3182fe;
            margin-bottom: 0.5rem;
            display: block;
        }
        .file-name {
            font-size: 0.85rem;
            color: #10b981;
            margin-top: 0.5rem;
            display: none;
        }
        .file-name.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <div class="card-custom">
            <div class="header-custom">
                <div class="icon-header">
                    <i class="fas fa-user-tie"></i>
                </div>
                <h1>Crear Cuenta de Supervisor</h1>
                <p>Crea una cuenta de supervisor</p>
            </div>

            <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Solicitud Enviada</strong><br>
                Tu solicitud ha sido enviada. El Administrador la revisará pronto.
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="info-box">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Selección Requerida:</strong> Escoge la cooperativa y el administrador filtrará automáticamente.
            </div>

            <form method="POST" action="procesar_registro_supervisor.php" enctype="multipart/form-data" novalidate>
                <div class="form-group">
                    <label for="cooperativa"><i class="fas fa-building me-2"></i>Cooperativa</label>
                    <select name="cooperativa" id="cooperativa" class="form-control" required>
                        <option value="">-- Selecciona una cooperativa --</option>
                        <?php foreach ($cooperativas as $coop): ?>
                            <option value="<?= htmlspecialchars($coop['id_cooperativa']) ?>">
                                <?= htmlspecialchars($coop['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="administrador"><i class="fas fa-user-cog me-2"></i>Administrador Responsable</label>
                    <select name="administrador" id="administrador" class="form-control" required>
                        <option value="">-- Primero selecciona una cooperativa --</option>
                    </select>
                </div>

                <div class="row-cols">
                    <div class="form-group">
                        <label for="nombres"><i class="fas fa-user me-2"></i>Nombres</label>
                        <input type="text" name="nombres" id="nombres" class="form-control" placeholder="Juan" required>
                    </div>
                    <div class="form-group">
                        <label for="apellidos"><i class="fas fa-user me-2"></i>Apellidos</label>
                        <input type="text" name="apellidos" id="apellidos" class="form-control" placeholder="Pérez" required>
                    </div>
                </div>

                <div class="row-cols">
                    <div class="form-group">
                        <label for="usuario"><i class="fas fa-user-circle me-2"></i>Usuario</label>
                        <input type="text" name="usuario" id="usuario" class="form-control" placeholder="juanperez" required>
                    </div>
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope me-2"></i>Email</label>
                        <input type="email" name="email" id="email" class="form-control" placeholder="juan@coac.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="telefono"><i class="fas fa-phone me-2"></i>Teléfono</label>
                    <input type="tel" name="telefono" id="telefono" class="form-control" placeholder="+593 98 1234567" required>
                </div>

                <div class="form-group">
                    <label for="password"><i class="fas fa-lock me-2"></i>Contraseña</label>
                    <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-file-pdf me-2"></i>Credencial / Nombramiento (PDF o Imagen)</label>
                    <div class="file-upload">
                        <label for="credencial" class="file-input-label">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <div>Haz clic o arrastra tu archivo aquí</div>
                            <small>(PDF, JPG, PNG - Máx. 5MB)</small>
                            <div class="file-name" id="file-name"></div>
                        </label>
                        <input type="file" name="credencial" id="credencial" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>

                <button type="submit" class="btn-submit"><i class="fas fa-paper-plane me-2"></i>Enviar Solicitud</button>
                <a href="login.php?role=supervisor" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Volver a Login</a>
            </form>
        </div>
    </div>

<script>
// Manejo del file upload
document.getElementById('cooperativa').addEventListener('change', function() {
    const coopId = this.value;
    const adminSelect = document.getElementById('administrador');
    
    if (!coopId) {
        adminSelect.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>';
        return;
    }

    // Cargar administradores de esa cooperativa
    fetch(`api_administradores_por_coop.php?cooperativa_id=${coopId}`)
        .then(res => res.json())
        .then(data => {
            adminSelect.innerHTML = '<option value="">-- Selecciona un administrador --</option>';
            
            if (data.administradores && data.administradores.length > 0) {
                data.administradores.forEach(admin => {
                    const option = document.createElement('option');
                    option.value = admin.id_usuario;
                    option.textContent = admin.nombre + ' (' + admin.email + ')';
                    adminSelect.appendChild(option);
                });
            } else {
                adminSelect.innerHTML = '<option value="">No hay administradores en esta cooperativa</option>';
            }
        })
        .catch(err => {
            console.error('Error cargando administradores:', err);
            adminSelect.innerHTML = '<option value="">Error al cargar administradores</option>';
        });
});

// Manejo del file input
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
    fileLabel.style.borderColor = '#3182fe';
});

fileLabel.addEventListener('dragleave', () => {
    fileLabel.style.background = 'rgba(255, 255, 255, 0.05)';
    fileLabel.style.borderColor = 'rgba(49, 130, 254, 0.5)';
});

fileLabel.addEventListener('drop', (e) => {
    e.preventDefault();
    fileLabel.style.background = 'rgba(255, 255, 255, 0.05)';
    fileLabel.style.borderColor = 'rgba(49, 130, 254, 0.5)';
    
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

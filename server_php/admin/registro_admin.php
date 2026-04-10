<?php
require_once 'db_admin.php';

// Si hay un parámetro de éxito, mostrar mensaje
$success = isset($_GET['success']) ? $_GET['success'] : false;
$error = isset($_GET['error']) ? $_GET['error'] : false;

// Obtener cooperativas
$cooperativas = [];
try {
    $stmt = $pdo->query("
        SELECT DISTINCT id_cooperativa, nombre 
        FROM cooperativas 
        ORDER BY nombre ASC 
        LIMIT 20
    ");
    $cooperativas = $stmt->fetchAll();
    
    // Si no hay resultados, usar por defecto
    if (empty($cooperativas)) {
        throw new Exception("No hay cooperativas");
    }
} catch (Exception $e) {
    // Usar valores por defecto
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
    <title>COAC Finance - Registro de Administrador</title>
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
            max-width: 600px;
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
            color: #6366f1;
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
            border-color: #6366f1;
            color: #f8fafc;
            box-shadow: 0 0 10px rgba(99, 102, 241, 0.3);
        }
        .form-control::placeholder {
            color: #64748b;
        }
        .form-control option {
            background: #1e293b;
            color: #f8fafc;
            padding: 0.5rem;
        }
        .form-control option:hover {
            background: #6366f1;
            color: white;
        }
        .form-control option:checked {
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
        }
        .btn-submit {
            width: 100%;
            padding: 0.8rem 1.5rem;
            background: linear-gradient(135deg, #6366f1, #a855f7);
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
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
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
        .password-helper {
            font-size: 0.85rem;
            color: #94a3b8;
            margin-top: 0.3rem;
        }
        .divider {
            text-align: center;
            color: #64748b;
            margin: 2rem 0 1.5rem;
            font-size: 0.9rem;
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
            border: 2px dashed rgba(99, 102, 241, 0.5);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            color: #94a3b8;
        }
        .file-input-label:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: #6366f1;
            color: #e2e8f0;
        }
        .file-input-label i {
            font-size: 1.8rem;
            color: #6366f1;
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
        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #93c5fd;
        }
        select.form-control {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236366f1' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
            cursor: pointer;
        }
        select.form-control:hover {
            border-color: #6366f1;
            background: rgba(255, 255, 255, 0.08);
        }
        select.form-control option {
            background: #1e293b;
            color: #f8fafc;
            padding: 0.5rem 1rem;
            border: none;
        }
        select.form-control option:hover {
            background: #6366f1;
            color: white;
        }
        select.form-control option:checked {
            background: linear-gradient(#6366f1, #6366f1);
            background-color: #6366f1;
        }
    </style>
</head>
<body>
    <div class="container-custom">
        <div class="card-custom">
            <div class="header-custom">
                <div class="icon-header">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h1>Registro de Administrador</h1>
                <p>Crea una solicitud de cuenta de administrador</p>
            </div>

            <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Solicitud Enviada</strong><br>
                Tu solicitud de creación de cuenta ha sido enviada. <br>
                El Superadministrador revisará y habilitará tu cuenta pronto.
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
                <strong>Verificación Requerida:</strong> El Super administrador verificará tu documento para autorizar tu cuenta.
            </div>

            <form method="POST" action="procesar_registro_admin.php" enctype="multipart/form-data" novalidate>
                <div class="form-group">
                    <label for="cooperativa"><i class="fas fa-building me-2"></i>Cooperativa</label>
                    <select class="form-control" id="cooperativa" name="cooperativa" required style="min-height: 45px;">
                        <option value="" disabled selected>-- Selecciona tu cooperativa --</option>
                        <?php 
                        if (!empty($cooperativas)):
                            foreach ($cooperativas as $coop): 
                        ?>
                        <option value="<?php echo htmlspecialchars($coop['id_cooperativa']); ?>">
                            <?php echo htmlspecialchars($coop['nombre']); ?>
                        </option>
                        <?php 
                            endforeach;
                        else:
                        ?>
                        <option value="">No hay cooperativas disponibles</option>
                        <?php 
                        endif;
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nombres">Nombre Completo</label>
                    <input type="text" class="form-control" id="nombres" name="nombres" 
                           placeholder="Ej: Juan Pérez" required>
                </div>

                <div class="form-group">
                    <label for="apellidos">Apellidos</label>
                    <input type="text" class="form-control" id="apellidos" name="apellidos" 
                           placeholder="Ej: García López" required>
                </div>

                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="admin@coac.local" required>
                </div>

                <div class="form-group">
                    <label for="usuario">Usuario</label>
                    <input type="text" class="form-control" id="usuario" name="usuario" 
                           placeholder="nombre_usuario" required>
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="Mínimo 8 caracteres" required>
                    <div class="password-helper">
                        <i class="fas fa-info-circle me-1"></i>
                        La contraseña debe tener al menos 8 caracteres
                    </div>
                </div>

                <div class="form-group">
                    <label for="region">Región/Ciudad Asignada</label>
                    <input type="text" class="form-control" id="region" name="region" 
                           placeholder="Ej: Quito" required>
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" class="form-control" id="telefono" name="telefono" 
                           placeholder="Ej: 0998765432" required>
                </div>

                <div class="form-group">
                    <label>Credencial / Nombramiento (PDF)</label>
                    <div class="file-upload">
                        <input type="file" id="credencial" name="credencial" accept=".pdf" required>
                        <label for="credencial" class="file-input-label">
                            <i class="fas fa-file-pdf"></i>
                            <span>Haz clic aquí o arrastra tu archivo PDF</span>
                            <div class="file-name" id="fileName"></div>
                        </label>
                    </div>
                    <div class="password-helper" style="margin-top: 0.5rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Solo se aceptan archivos PDF (máx 5MB)
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane me-2"></i>Enviar Solicitud
                </button>

                <a href="login_selector.php" class="btn-back">
                    <i class="fas fa-arrow-left me-2"></i>Volver al Selector de Rol
                </a>
            </form>

            <div class="divider">
                <i class="fas fa-lock me-2"></i>
                Tu solicitud será revisada por el Superadministrador
            </div>
        </div>
    </div>

    <script>
        // Manejar carga de archivo
        const fileInput = document.getElementById('credencial');
        const fileNameDisplay = document.getElementById('fileName');
        const fileLabel = document.querySelector('.file-input-label');

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                const file = this.files[0];
                
                // Validar que sea PDF
                if (file.type !== 'application/pdf') {
                    alert('Solo se aceptan archivos PDF');
                    this.value = '';
                    fileNameDisplay.textContent = '';
                    fileNameDisplay.classList.remove('show');
                    return;
                }
                
                // Validar tamaño (máx 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('El archivo no debe superar 5MB');
                    this.value = '';
                    fileNameDisplay.textContent = '';
                    fileNameDisplay.classList.remove('show');
                    return;
                }
                
                // Mostrar nombre del archivo
                fileNameDisplay.textContent = '✓ ' + file.name + ' (' + (file.size / 1024).toFixed(2) + ' KB)';
                fileNameDisplay.classList.add('show');
                fileLabel.style.borderColor = '#10b981';
            } else {
                fileNameDisplay.textContent = '';
                fileNameDisplay.classList.remove('show');
                fileLabel.style.borderColor = '';
            }
        });

        // Drag and drop
        fileLabel.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.background = 'rgba(99, 102, 241, 0.2)';
        });

        fileLabel.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.background = '';
        });

        fileLabel.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.background = '';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                // Disparar evento change
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    </script>
</body>
</html>

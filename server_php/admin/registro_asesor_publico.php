<?php
require_once 'db_admin.php';

// No permitir acceso si ya inició sesión
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    header('Location: super_admin_index.php');
    exit;
}
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}
if (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    header('Location: supervisor_index.php');
    exit;
}
if (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    header('Location: asesor_index.php');
    exit;
}

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

// Obtener cooperativas
$cooperativas = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT id_cooperativa, nombre FROM cooperativas ORDER BY nombre ASC LIMIT 50");
    $cooperativas = $stmt->fetchAll();

    if (empty($cooperativas)) {
        throw new Exception('No hay cooperativas (id_cooperativa)');
    }
} catch (Exception $e) {
    // Intentar con otra estructura (id, nombre)
    try {
        $stmt = $pdo->query("SELECT DISTINCT id, nombre FROM cooperativas ORDER BY nombre ASC LIMIT 50");
        $rows = $stmt->fetchAll();
        $cooperativas = array_map(fn($r) => ['id_cooperativa' => $r['id'], 'nombre' => $r['nombre']], $rows);

        if (empty($cooperativas)) {
            throw new Exception('No hay cooperativas (id)');
        }
    } catch (Exception $e2) {
        // Fallback igual a registro_admin.php para no bloquear el formulario
        $cooperativas = [
            ['id_cooperativa' => 1, 'nombre' => 'COAC Finance - Quito'],
            ['id_cooperativa' => 2, 'nombre' => 'COAC Finance - Guayaquil'],
            ['id_cooperativa' => 3, 'nombre' => 'COAC Finance - Cuenca'],
            ['id_cooperativa' => 4, 'nombre' => 'COAC Finance - Ambato']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance - Crear Cuenta de Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 40%,#1e3a5f 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:22px;}
        .card{width:900px;max-width:96vw;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.45);display:grid;grid-template-columns: 1fr 1.1fr;}
        .left{background:linear-gradient(160deg,rgba(107,17,255,.55),rgba(49,130,254,.45));padding:46px 40px;color:#fff;display:flex;flex-direction:column;justify-content:center;border-right:1px solid rgba(255,255,255,.1)}
        .brand-icon{width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:24px;border:1px solid rgba(255,255,255,.2)}
        .left h1{font-size:28px;font-weight:800;margin:0 0 10px 0}
        .left p{font-size:14px;opacity:.75;line-height:1.7;margin:0 0 26px 0}
        .feat{display:flex;align-items:center;gap:12px;font-size:13.5px;margin-bottom:14px;opacity:.86}
        .feat .fi{width:32px;height:32px;background:rgba(255,255,255,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
        .right{padding:46px 44px;}
        .title{font-size:22px;font-weight:800;color:#1e293b;margin-bottom:6px}
        .sub{font-size:13.5px;color:#64748b;margin-bottom:22px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .form-group{margin-bottom:14px}
        label{display:block;font-size:12.5px;font-weight:700;color:#374151;margin-bottom:7px}
        input, select{width:100%;padding:12px 12px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:'Inter',sans-serif;color:#1e293b;outline:none;transition:.2s}
        input:focus, select:focus{border-color:#6b11ff;box-shadow:0 0 0 3px rgba(107,17,255,.1)}
        .file-upload{border:2px dashed rgba(107,17,255,.35);border-radius:12px;padding:18px;text-align:center;cursor:pointer;transition:.2s;background:rgba(107,17,255,.04)}
        .file-upload:hover{border-color:#6b11ff;background:rgba(107,17,255,.07)}
        .file-upload .hint{color:#64748b;font-size:12px;margin-top:6px}
        .file-name{margin-top:10px;color:#10b981;font-weight:700;font-size:13px;display:none}
        .file-name.show{display:block}
        .btn-primary{width:100%;padding:13px;background:linear-gradient(135deg,#6b11ff,#3182fe);border:none;border-radius:11px;color:#fff;font-size:15px;font-weight:800;cursor:pointer;transition:.2s;box-shadow:0 6px 20px rgba(107,17,255,.35)}
        .btn-primary:hover{opacity:.92;transform:translateY(-2px);box-shadow:0 10px 28px rgba(107,17,255,.45)}
        .btn-link{width:100%;padding:10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:700;cursor:pointer;transition:.2s;text-decoration:none;display:inline-block;text-align:center;margin-top:12px}
        .btn-link:hover{background:#f8fafc;border-color:#d1d5db;color:#1e293b}
        .alert{padding:11px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}
        .alert-success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
        .alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
        #credencial{display:none}
        @media(max-width:720px){.card{grid-template-columns:1fr}.left{display:none}.right{padding:34px 28px}.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>

<div class="card">
    <div class="left">
        <div class="brand-icon"><i class="fas fa-map-marked-alt"></i></div>
        <h1>COAC Finance</h1>
        <p>Registra tu cuenta de asesor y adjunta tu credencial/nombramiento para validación.</p>
        <div class="feat"><div class="fi"><i class="fas fa-user-check"></i></div><span>Tu supervisor revisa y aprueba</span></div>
        <div class="feat"><div class="fi"><i class="fas fa-id-badge"></i></div><span>Completa tu información de registro</span></div>
        <div class="feat"><div class="fi"><i class="fas fa-file-pdf"></i></div><span>Sube PDF o imagen (máx. 5MB)</span></div>
    </div>

    <div class="right">
        <div class="title">Crear Cuenta de Asesor</div>
        <div class="sub">Completa tus datos para enviar la solicitud.</div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Solicitud enviada. Espera la aprobación del supervisor.</div>
        <?php endif; ?>

        <form method="POST" action="procesar_registro_asesor_publico.php" enctype="multipart/form-data" novalidate>

            <div class="form-group">
                <div style="background:#e3f2fd;border:1px solid #64b5f6;border-radius:8px;padding:10px 12px;margin-bottom:16px;font-size:13px;color:#1565c0;">
                    <i class="fas fa-info-circle me-2"></i><strong>Selección Requerida:</strong> Escoge la cooperativa y el supervisor filtrará automáticamente.
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-building me-2"></i>Cooperativa</label>
                <select name="id_cooperativa" id="id_cooperativa" required>
                    <option value="">-- Selecciona una cooperativa --</option>
                    <?php foreach ($cooperativas as $coop): ?>
                        <option value="<?= (int)$coop['id_cooperativa'] ?>"><?= htmlspecialchars($coop['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($cooperativas)): ?>
                    <div class="hint" style="color:#ef4444;font-size:12px;margin-top:6px;">No hay cooperativas disponibles.</div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label><i class="fas fa-user-tie me-2"></i>Supervisor Responsable</label>
                <select name="id_supervisor" id="id_supervisor" required>
                    <option value="">-- Primero selecciona una cooperativa --</option>
                </select>
            </div>

            <div class="grid">
                <div class="form-group">
                    <label><i class="fas fa-user me-2"></i>Nombres</label>
                    <input type="text" name="nombres" placeholder="Ej: Juan Carlos" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user me-2"></i>Apellidos</label>
                    <input type="text" name="apellidos" placeholder="Ej: García López" required>
                </div>
            </div>

            <div class="grid">
                <div class="form-group">
                    <label><i class="fas fa-envelope me-2"></i>Email</label>
                    <input type="email" name="email" placeholder="correo@ejemplo.com" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone me-2"></i>Teléfono</label>
                    <input type="tel" name="telefono" placeholder="0987654321" required>
                </div>
            </div>

            <div class="grid">
                <div class="form-group">
                    <label><i class="fas fa-user-circle me-2"></i>Usuario</label>
                    <input type="text" name="usuario" placeholder="Ej: jgarcia" minlength="4" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-key me-2"></i>Contraseña</label>
                    <input type="password" name="password" placeholder="Min. 6 caracteres" minlength="6" required>
                </div>
            </div>

            <div class="form-group">
                <label><i class="fas fa-file-pdf me-2"></i>Credencial / Nombramiento (PDF o Imagen)</label>
                <div class="file-upload" id="dropzone">
                    <div style="font-weight:800;color:#1e293b;"><i class="fas fa-cloud-upload-alt me-2" style="color:#6b11ff;"></i>Sube tu archivo</div>
                    <div class="hint">(PDF, JPG, PNG – Máx. 5MB)</div>
                    <div class="file-name" id="file-name"></div>
                    <input type="file" name="credencial" id="credencial" accept=".pdf,.jpg,.jpeg,.png" required>
                </div>
            </div>

            <button type="submit" class="btn-primary"><i class="fas fa-paper-plane me-2"></i>Enviar Solicitud</button>
            <a href="login.php?role=asesor" class="btn-link"><i class="fas fa-arrow-left me-2"></i>Volver al Login</a>
        </form>
    </div>
</div>

<script>
// Filtro de Supervisores por Cooperativa
const cooperativaSelect = document.getElementById('id_cooperativa');
const supervisorSelect = document.getElementById('id_supervisor');

cooperativaSelect.addEventListener('change', async function() {
    const id_cooperativa = this.value;
    supervisorSelect.innerHTML = '<option value="">-- Cargando supervisores --</option>';
    supervisorSelect.disabled = true;

    if (!id_cooperativa) {
        supervisorSelect.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>';
        supervisorSelect.disabled = true;
        return;
    }

    try {
        const response = await fetch('get_supervisores_por_cooperativa.php?id_cooperativa=' + encodeURIComponent(id_cooperativa));
        const data = await response.json();

        supervisorSelect.innerHTML = '<option value="">-- Selecciona un supervisor --</option>';
        
        if (data.supervisores && data.supervisores.length > 0) {
            data.supervisores.forEach(sup => {
                const option = document.createElement('option');
                option.value = sup.id_usuario;
                option.textContent = sup.nombre;
                supervisorSelect.appendChild(option);
            });
            supervisorSelect.disabled = false;
        } else {
            supervisorSelect.innerHTML = '<option value="" disabled>No hay supervisores en esta cooperativa</option>';
            supervisorSelect.disabled = true;
        }
    } catch (error) {
        console.error('Error:', error);
        supervisorSelect.innerHTML = '<option value="" disabled>Error al cargar supervisores</option>';
        supervisorSelect.disabled = true;
    }
});

// Validación de archivo
const fileInput = document.getElementById('credencial');
const dropzone = document.getElementById('dropzone');
const fileName = document.getElementById('file-name');

const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
const maxSize = 5 * 1024 * 1024;

function setFile(file) {
    if (!file) return;
    if (!allowedTypes.includes(file.type)) {
        fileName.textContent = '❌ Tipo no permitido (PDF, JPG, PNG)';
        fileName.classList.add('show');
        fileInput.value = '';
        return;
    }
    if (file.size > maxSize) {
        fileName.textContent = '❌ Archivo muy grande (máx. 5MB)';
        fileName.classList.add('show');
        fileInput.value = '';
        return;
    }
    fileName.textContent = '✅ ' + file.name;
    fileName.classList.add('show');
}

// Click para abrir selector
 dropzone.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', () => setFile(fileInput.files[0]));

dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.style.background = 'rgba(107,17,255,.08)';
    dropzone.style.borderColor = '#6b11ff';
});

dropzone.addEventListener('dragleave', () => {
    dropzone.style.background = 'rgba(107,17,255,.04)';
    dropzone.style.borderColor = 'rgba(107,17,255,.35)';
});

dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.style.background = 'rgba(107,17,255,.04)';
    dropzone.style.borderColor = 'rgba(107,17,255,.35)';

    const files = e.dataTransfer.files;
    if (files && files.length > 0) {
        fileInput.files = files;
        setFile(files[0]);
    }
});
</script>

</body>
</html>

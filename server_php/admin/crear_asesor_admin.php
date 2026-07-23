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

// Obtener cooperativas/bancos desde tabla unidad_bancaria (mismo <select> se
// usa para las 3 pestañas de rol; sirve para filtrar gerentes/supervisores).
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
    error_log("Error cargando cooperativas: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Crear Cuenta (Gerente / Supervisor / Asesor)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; min-height: 100vh; }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: sticky;
            height: 100vh;
            top: 0;
            flex-shrink: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: var(--brand-yellow); color: var(--brand-navy-deep); border: 1px solid var(--brand-yellow-deep); padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 700; }
        .btn-logout:hover { opacity: .9; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .form-card { background: white; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); padding: 34px; max-width: 100%; margin: 0; }
        .page-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 22px; max-width: 100%; }
        .page-header .btn-back { margin-bottom: 0; }
        .page-header-text h1 { margin: 0 0 4px; font-size: 24px; font-weight: 800; color: #1f2937; }
        .page-header-text p { margin: 0; color: #6b7280; font-size: 13.5px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #1f2937; font-size: 14px; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1.5px solid #e2eaf4; border-radius: 9px; font-family: 'Inter', sans-serif; font-size: 14px; transition: all 0.2s ease; background: #fff; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--brand-navy); box-shadow: 0 0 0 3px rgba(18, 58, 109, 0.1); }
        .form-group input::placeholder { color: #d1d5db; }
        .form-group small.hint { display: block; margin-top: 6px; color: #9ca3af; font-size: 12px; }
        .section-title { display: flex; align-items: center; gap: 10px; margin: 28px 0 16px; padding-bottom: 10px; border-bottom: 1.5px solid #f0f4f8; }
        .section-title:first-of-type { margin-top: 0; }
        .section-title .ic { width: 30px; height: 30px; border-radius: 9px; background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .section-title .ic i { color: var(--brand-yellow); font-size: 13px; }
        .section-title span { font-weight: 800; font-size: 14.5px; color: #1f2937; }
        .row-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .row-cols .form-group { margin-bottom: 0; }
        .row-cols-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .row-cols-3 .form-group { margin-bottom: 0; }
        .role-tabs { display: flex; gap: 10px; margin-bottom: 22px; max-width: 100%; }
        .role-tab { flex: 1; text-align: center; padding: 16px 10px; border-radius: 14px; border: 2px solid #e2eaf4; background: #fff; cursor: pointer; font-weight: 700; font-size: 13.5px; color: #6b7280; transition: all .2s ease; box-shadow: 0 2px 6px rgba(10,39,72,.04); }
        .role-tab:hover { border-color: #cbd5e1; transform: translateY(-1px); }
        .role-tab .role-ic { width: 38px; height: 38px; border-radius: 11px; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-size: 16px; background: #f3f4f6; color: #6b7280; transition: all .2s ease; }
        .role-tab[data-rol="gerente_general"] .role-ic { background: #ede9fe; color: #6d28d9; }
        .role-tab[data-rol="supervisor"] .role-ic { background: #dbeafe; color: #1d4ed8; }
        .role-tab[data-rol="asesor"] .role-ic { background: #fef3c7; color: #92400e; }
        .role-tab.active { border-color: var(--brand-navy); background: rgba(18,58,109,.04); color: var(--brand-navy-deep); }
        .role-tab.active .role-ic { box-shadow: 0 0 0 3px rgba(18,58,109,.12); }
        .file-upload { border: 2px dashed #c7d3e3; border-radius: 12px; padding: 28px; text-align: center; cursor: pointer; transition: all 0.2s ease; background: #f8fafc; }
        .file-upload:hover { border-color: var(--brand-navy); background: rgba(18,58,109,.04); }
        .file-input-label { cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .file-input-label i { font-size: 30px; color: var(--brand-navy); }
        .file-input-label div { font-weight: 600; color: #1f2937; }
        .file-input-label small { color: #9ca3af; }
        .file-name { margin-top: 10px; color: #10b981; font-weight: 600; font-size: 13px; display: none; }
        .file-name.show { display: block; }
        .btn-submit { width: 100%; padding: 14px; background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: #fff; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 15px; transition: all 0.2s ease; margin-top: 22px; box-shadow: 0 4px 14px rgba(10,39,72,.2); }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(10,39,72,.28); }
        .btn-submit i { color: var(--brand-yellow); }
        .btn-back { padding: 8px 16px; background: #e5e7eb; color: #1f2937; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; font-weight: 600; margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; }
        .btn-back:hover { background: #d1d5db; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        #credencial { display: none; }
        input[type="file"] { display: none; }
        @media (max-width: 900px) { .row-cols-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 600px) { .row-cols, .row-cols-3 { grid-template-columns: 1fr; } .form-card { padding: 20px; } .role-tabs { flex-direction: column; } }
    </style>
</head>
<body>

<?php if ($is_super_admin): ?>
    <?php $currentPage = 'crear_asesor'; require_once '_sidebar_super_admin.php'; ?>
<?php else: ?>
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
            <i class="fas fa-user-plus"></i> Crear Cuenta
        </a>
    </div>
</div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><?php echo $is_super_admin ? '👑' : '🎯'; ?> Super_IA - Crear Cuenta</h2>
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

        <div class="page-header-text" style="margin:0 0 22px;">
            <a href="index.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
            <h1><i class="fas fa-user-plus me-2"></i>Crear Cuenta Nueva</h1>
            <p>Da de alta una cuenta de Gerente General, Supervisor o Asesor. Queda activa de inmediato, sin pasar por aprobación.</p>
        </div>

        <!-- SELECTOR DE ROL -->
        <div class="role-tabs" id="roleTabs">
            <div class="role-tab" data-rol="gerente_general">
                <div class="role-ic"><i class="fas fa-user-tie"></i></div>
                Gerente General
            </div>
            <div class="role-tab" data-rol="supervisor">
                <div class="role-ic"><i class="fas fa-user-shield"></i></div>
                Supervisor
            </div>
            <div class="role-tab active" data-rol="asesor">
                <div class="role-ic"><i class="fas fa-user"></i></div>
                Asesor
            </div>
        </div>

        <div class="form-card">

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>✅ Cuenta creada y activa. Ya puede iniciar sesión.
            </div>
            <?php endif; ?>

            <div class="alert alert-info" style="margin-bottom:0;">
                <i class="fas fa-info-circle me-2"></i>La cuenta se crea <strong>activa de inmediato</strong> — no queda pendiente de aprobación, porque la estás creando tú mismo como administrador.
            </div>
            <div style="height:20px;"></div>

            <form method="POST" action="procesar_crear_asesor_admin.php" enctype="multipart/form-data" novalidate id="formCrear">
                <input type="hidden" name="rol_crear" id="rolCrearInput" value="asesor">

                <!-- COOPERATIVA / BANCO (siempre visible, filtra los selects de abajo) -->
                <div class="section-title"><div class="ic"><i class="fas fa-university"></i></div><span>Cooperativa / Banco</span></div>

                <div class="form-group">
                    <label><i class="fas fa-building me-2"></i>Selecciona Cooperativa / Banco</label>
                    <select name="unidad_bancaria_id" id="selectCooperativa" required onchange="onCooperativaChange()">
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

                <!-- GERENTE RESPONSABLE (solo para rol = supervisor) -->
                <div id="seccionGerente" style="display:none;">
                    <div class="section-title"><div class="ic"><i class="fas fa-user-tie"></i></div><span>Gerente Responsable</span></div>
                    <div class="form-group">
                        <label><i class="fas fa-user-check me-2"></i>Selecciona el Gerente de esta cooperativa</label>
                        <select name="gerente_responsable_id" id="selectGerente">
                            <option value="">-- Primero selecciona una cooperativa --</option>
                        </select>
                        <small class="hint">El supervisor queda bajo la agencia de este gerente. Requerido para poder aprobar/crear la cuenta.</small>
                    </div>
                </div>

                <!-- SUPERVISOR (solo para rol = asesor) -->
                <div id="seccionSupervisor">
                    <div class="section-title"><div class="ic"><i class="fas fa-user-check"></i></div><span>Supervisor Asignado</span></div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tie me-2"></i>Selecciona Supervisor</label>
                        <select name="id_supervisor" id="selectSupervisor">
                            <option value="">-- Primero selecciona una cooperativa --</option>
                        </select>
                    </div>
                </div>

                <!-- DATOS PERSONALES -->
                <div class="section-title"><div class="ic"><i class="fas fa-user"></i></div><span>Datos Personales</span></div>

                <div class="row-cols-3">
                    <div class="form-group">
                        <label><i class="fas fa-user me-2"></i>Nombres</label>
                        <input type="text" name="nombres" placeholder="Ej: Juan Carlos" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user me-2"></i>Apellidos</label>
                        <input type="text" name="apellidos" placeholder="Ej: García López" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-id-card me-2"></i>Cédula de Identidad</label>
                        <input type="text" name="cedula" placeholder="Ej: 1712345678" maxlength="13" required>
                    </div>
                </div>

                <div class="row-cols" style="margin-top:20px;">
                    <div class="form-group">
                        <label><i class="fas fa-envelope me-2"></i>Correo Electrónico</label>
                        <input type="email" name="email" placeholder="Ej: correo@ejemplo.com" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone me-2"></i>Teléfono</label>
                        <input type="tel" name="telefono" placeholder="Ej: 0987654321" required>
                    </div>
                </div>

                <!-- CUENTA DE USUARIO -->
                <div class="section-title"><div class="ic"><i class="fas fa-lock"></i></div><span>Contraseña de Acceso</span></div>

                <div class="form-group">
                    <label><i class="fas fa-key me-2"></i>Contraseña</label>
                    <input type="password" name="password" placeholder="Mín. 8 caracteres, mayúscula, minúscula, número y símbolo" minlength="8" required>
                    <small class="hint">Debe incluir mayúscula, minúscula, número y símbolo (ej: Yantzaza#2026).</small>
                </div>

                <!-- ARCHIVO DE CREDENCIAL -->
                <div class="section-title"><div class="ic"><i class="fas fa-file-pdf"></i></div><span>Credencial / Nombramiento (opcional)</span></div>

                <div class="form-group">
                    <label><i class="fas fa-file-pdf me-2"></i>Credencial / Nombramiento (PDF o Imagen)</label>
                    <div class="file-upload">
                        <label for="credencial" class="file-input-label">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <div>Haz clic o arrastra tu archivo aquí</div>
                            <small>(PDF, JPG, PNG – Máx. 5MB, opcional)</small>
                            <div class="file-name" id="file-name"></div>
                        </label>
                        <input type="file" name="credencial" id="credencial" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">
                    <i class="fas fa-paper-plane me-2"></i>Crear cuenta de Asesor
                </button>

            </form>

        </div>
    </div>
</div>

<script src="js/validaciones.js"></script>
<script>
// ── Activar validaciones (sin campo 'usuario' en este form) ──
document.addEventListener('DOMContentLoaded', () => {
    bindValidaciones('form');
});

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
    fileLabel.style.background = 'rgba(18, 58, 109, 0.08)';
    fileLabel.style.borderColor = '#123a6d';
});

fileLabel.addEventListener('dragleave', () => {
    fileLabel.style.background = '#f8fafc';
    fileLabel.style.borderColor = '#c7d3e3';
});

fileLabel.addEventListener('drop', (e) => {
    e.preventDefault();
    fileLabel.style.background = '#f8fafc';
    fileLabel.style.borderColor = '#c7d3e3';

    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        const event = new Event('change', { bubbles: true });
        fileInput.dispatchEvent(event);
    }
});

// ============================================================
// Selector de rol: Gerente General / Supervisor / Asesor
// ============================================================
const roleLabels = {
    gerente_general: { titulo: 'Gerente General', boton: 'Crear cuenta de Gerente General' },
    supervisor:      { titulo: 'Supervisor',       boton: 'Crear cuenta de Supervisor' },
    asesor:          { titulo: 'Asesor',           boton: 'Crear cuenta de Asesor' },
};

function seleccionarRol(rol) {
    document.getElementById('rolCrearInput').value = rol;

    document.querySelectorAll('.role-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.rol === rol);
    });

    const seccionGerente = document.getElementById('seccionGerente');
    const seccionSupervisor = document.getElementById('seccionSupervisor');
    const selectGerente = document.getElementById('selectGerente');
    const selectSupervisor = document.getElementById('selectSupervisor');

    // Gerente General: no necesita ni gerente responsable ni supervisor.
    // Supervisor: necesita elegir el Gerente Responsable (para resolver su agencia).
    // Asesor: necesita elegir el Supervisor al que reporta.
    seccionGerente.style.display = (rol === 'supervisor') ? 'block' : 'none';
    seccionSupervisor.style.display = (rol === 'asesor') ? 'block' : 'none';

    selectGerente.required = (rol === 'supervisor');
    selectSupervisor.required = (rol === 'asesor');

    document.getElementById('btnSubmit').innerHTML =
        '<i class="fas fa-paper-plane me-2"></i>' + roleLabels[rol].boton;

    // Recarga el select correspondiente por si ya había una cooperativa elegida
    onCooperativaChange();
}

document.querySelectorAll('.role-tab').forEach(tab => {
    tab.addEventListener('click', () => seleccionarRol(tab.dataset.rol));
});

// ============================================================
// Cargar gerentes o supervisores según cooperativa + rol elegido
// ============================================================
async function onCooperativaChange() {
    const rol = document.getElementById('rolCrearInput').value;
    const cooperativaId = document.getElementById('selectCooperativa').value;

    if (rol === 'supervisor') {
        await cargarGerentes(cooperativaId);
    } else if (rol === 'asesor') {
        await cargarSupervisores(cooperativaId);
    }
}

async function cargarGerentes(cooperativaId) {
    const select = document.getElementById('selectGerente');
    if (!cooperativaId) {
        select.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>';
        return;
    }
    select.innerHTML = '<option value="">Cargando gerentes…</option>';
    try {
        const res = await fetch(`api_gerentes_por_coop.php?cooperativa_id=${encodeURIComponent(cooperativaId)}`);
        const data = await res.json();
        const gerentes = data.gerentes || [];
        if (gerentes.length === 0) {
            select.innerHTML = '<option value="">-- No hay gerentes en esta cooperativa todavía --</option>';
            return;
        }
        let html = '<option value="">-- Selecciona un gerente --</option>';
        gerentes.forEach(g => {
            html += `<option value="${g.id_usuario}">${g.nombre} (${g.email})</option>`;
        });
        select.innerHTML = html;
    } catch (e) {
        select.innerHTML = '<option value="">Error al cargar gerentes</option>';
    }
}

async function cargarSupervisores(cooperativaId) {
    const select = document.getElementById('selectSupervisor');
    if (!cooperativaId) {
        select.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>';
        return;
    }
    select.innerHTML = '<option value="">Cargando supervisores…</option>';
    select.disabled = true;
    try {
        const formData = new FormData();
        formData.append('cooperativa_id', cooperativaId);
        const response = await fetch('api_supervisores_por_cooperativa.php', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.status === 'success' && data.supervisores && data.supervisores.length > 0) {
            let html = '<option value="">-- Selecciona un supervisor --</option>';
            data.supervisores.forEach(supervisor => {
                html += `<option value="${supervisor.id}">${supervisor.nombre} (${supervisor.email})</option>`;
            });
            select.innerHTML = html;
        } else {
            select.innerHTML = '<option value="">No hay supervisores en esta cooperativa</option>';
        }
        select.disabled = false;
    } catch (error) {
        console.error('Error cargando supervisores:', error);
        select.innerHTML = '<option value="">Error al cargar supervisores</option>';
        select.disabled = false;
    }
}

// Estado inicial: rol Asesor ya activo por defecto
seleccionarRol('asesor');
</script>

</body>
</html>

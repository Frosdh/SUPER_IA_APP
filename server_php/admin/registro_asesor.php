<?php
// ============================================================
// admin/registro_asesor.php — Formulario ÚNICO de registro de asesor.
//
// MODO SUPERVISOR (con sesión):
//   El supervisor accede desde su panel; el supervisor queda fijado
//   desde la sesión. Muestra sidebar y navbar del panel.
//
// MODO PÚBLICO (sin sesión):
//   El asesor se registra por su cuenta seleccionando cooperativa
//   y supervisor. No muestra sidebar.
//
// En ambos casos el procesador es procesar_registro_asesor.php.
// ============================================================
require_once 'db_admin.php';

// ── Detectar modo ──────────────────────────────────────────
$modo_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;

// Redirigir si ya inició sesión como otro rol (solo en modo público)
if (!$modo_supervisor) {
    if (!empty($_SESSION['super_admin_logged_in'])) { header('Location: super_admin_index.php'); exit; }
    if (!empty($_SESSION['admin_logged_in']))        { header('Location: index.php');             exit; }
}

// ── Datos de sesión (modo supervisor) ─────────────────────
$supervisor_id     = $_SESSION['supervisor_id']     ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol    = $_SESSION['supervisor_rol']    ?? 'Supervisor';

// ── Mensajes de resultado ──────────────────────────────────
$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';

// ── Cargar cooperativas (modo público) ────────────────────
$cooperativas = [];
if (!$modo_supervisor) {
    try {
        $stmt = $pdo->query("SELECT DISTINCT id_cooperativa, nombre FROM cooperativas ORDER BY nombre ASC LIMIT 50");
        $cooperativas = $stmt->fetchAll();
        if (empty($cooperativas)) throw new Exception('empty');
    } catch (\Throwable $e) {
        try {
            $stmt = $pdo->query("SELECT DISTINCT id AS id_cooperativa, nombre FROM cooperativas ORDER BY nombre ASC LIMIT 50");
            $cooperativas = $stmt->fetchAll();
        } catch (\Throwable $e2) {
            $cooperativas = [
                ['id_cooperativa' => 1, 'nombre' => 'Super_IA - Quito'],
                ['id_cooperativa' => 2, 'nombre' => 'Super_IA - Guayaquil'],
                ['id_cooperativa' => 3, 'nombre' => 'Super_IA - Cuenca'],
                ['id_cooperativa' => 4, 'nombre' => 'Super_IA - Ambato'],
            ];
        }
    }
}

// ── Badge de pendientes (sidebar) ─────────────────────────
$totalPendientes = 0;
$currentPage     = 'agregar';
if ($modo_supervisor) {
    try {
        $stSup = $conn->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
        $stSup->bind_param('s', $supervisor_id);
        $stSup->execute();
        $rowSup      = $stSup->get_result()->fetch_assoc();
        $real_sup_id = $rowSup['id'] ?? null;
        $stSup->close();
        if ($real_sup_id) {
            $stPend = $conn->prepare(
                'SELECT COUNT(*) AS cnt FROM usuario u
                 JOIN asesor a ON a.usuario_id = u.id
                 WHERE a.supervisor_id = ? AND u.estado_registro = "pendiente"'
            );
            $stPend->bind_param('s', $real_sup_id);
            $stPend->execute();
            $totalPendientes = (int)($stPend->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stPend->close();
        }
    } catch (\Throwable $e) { /* silencioso */ }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Registro de Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-yellow:      #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy:        #123a6d;
            --brand-navy-deep:   #0a2748;
            --brand-border:      #d7e0ea;
            --brand-card:        #ffffff;
            --brand-bg:          #f4f6f9;
            --brand-shadow:      0 16px 34px rgba(18,58,109,.08);
        }
        * { margin:0; padding:0; box-sizing:border-box; }

        /* ── PANEL (modo supervisor) ── */
        body.panel-mode {
            font-family:'Inter','Segoe UI',sans-serif;
            background:linear-gradient(180deg,#f8fafc 0%,var(--brand-bg) 100%);
            display:flex; height:100vh; color:var(--brand-navy-deep);
        }
        .sidebar { width:230px; background:linear-gradient(180deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; padding:20px 0; overflow-y:auto; position:fixed; height:100vh; left:0; top:0; z-index:100; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding:0 15px; margin-bottom:22px; }
        .sidebar-section-title { font-size:11px; text-transform:uppercase; color:rgba(255,255,255,.5); letter-spacing:.6px; padding:0 10px; margin-bottom:10px; font-weight:700; }
        .sidebar-link { display:flex; align-items:center; gap:12px; padding:11px 15px; margin-bottom:4px; border-radius:10px; color:rgba(255,255,255,.82); text-decoration:none; font-size:14px; border:1px solid transparent; transition:all .22s; }
        .sidebar-link:hover { background:rgba(255,221,0,.12); color:#fff; padding-left:20px; border-color:rgba(255,221,0,.15); }
        .sidebar-link.active { background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep)); color:var(--brand-navy-deep); font-weight:700; }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }
        .main-content { flex:1; margin-left:230px; display:flex; flex-direction:column; overflow:hidden; }
        .navbar-custom { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 12px 28px rgba(18,58,109,.18); }
        .navbar-custom h2 { margin:0; font-size:20px; font-weight:700; }
        .user-info { display:flex; align-items:center; gap:15px; }
        .btn-logout { background:rgba(255,221,0,.15); color:#fff; border:1px solid rgba(255,221,0,.28); padding:8px 15px; border-radius:10px; cursor:pointer; text-decoration:none; font-weight:600; }
        .btn-logout:hover { background:rgba(255,221,0,.26); color:#fff; }
        .content-area { flex:1; overflow-y:auto; padding:32px; }
        .btn-back { padding:8px 18px; background:rgba(18,58,109,.08); color:var(--brand-navy-deep); border:1.5px solid var(--brand-border); border-radius:10px; cursor:pointer; text-decoration:none; font-weight:600; margin-bottom:22px; display:inline-flex; align-items:center; gap:8px; font-size:13.5px; transition:background .2s; }
        .btn-back:hover { background:rgba(18,58,109,.15); color:var(--brand-navy-deep); }

        /* ── PÚBLICO (modo sin sesión) ── */
        body.public-mode {
            font-family:'Inter','Segoe UI',sans-serif;
            background:linear-gradient(135deg,#1e1b4b 0%,#312e81 40%,#1e3a5f 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center;
            padding:2rem; position:relative;
        }
        body.public-mode::before { content:''; position:absolute; width:500px; height:500px; border-radius:50%; background:radial-gradient(circle,rgba(107,17,255,.18) 0%,transparent 70%); top:-150px; left:-100px; }
        body.public-mode::after  { content:''; position:absolute; width:400px; height:400px; border-radius:50%; background:radial-gradient(circle,rgba(49,130,254,.15) 0%,transparent 70%); bottom:-100px; right:-80px; }
        .public-wrapper { width:100%; max-width:620px; position:relative; z-index:1; }
        .public-header { text-align:center; margin-bottom:2rem; }
        .public-header .icon-wrap { width:62px; height:62px; background:linear-gradient(135deg,#6b11ff,#3182fe); border-radius:18px; display:flex; align-items:center; justify-content:center; font-size:26px; color:#fff; margin:0 auto 16px; }
        .public-header h1 { font-size:26px; font-weight:800; color:#fff; margin-bottom:6px; }
        .public-header p { color:rgba(255,255,255,.68); font-size:14px; }
        .public-card { background:#fff; border-radius:22px; padding:36px; box-shadow:0 30px 80px rgba(0,0,0,.40); }
        .pub-back { display:inline-flex; align-items:center; gap:7px; color:rgba(255,255,255,.75); text-decoration:none; font-size:13px; font-weight:600; margin-bottom:1.5rem; transition:.2s; }
        .pub-back:hover { color:#fff; }

        /* ── FORM COMÚN ── */
        .page-header { margin-bottom:28px; }
        .page-header h1 { font-size:26px; font-weight:800; color:var(--brand-navy-deep); }
        .form-card { background:var(--brand-card); border-radius:18px; box-shadow:var(--brand-shadow); padding:36px; max-width:720px; border:1px solid var(--brand-border); }
        .section-title { font-size:12.5px; font-weight:800; color:var(--brand-navy); text-transform:uppercase; letter-spacing:.4px; margin:26px 0 14px; padding-bottom:8px; border-bottom:2px solid var(--brand-yellow); display:flex; align-items:center; gap:8px; }
        .section-title:first-child { margin-top:0; }
        .form-group { margin-bottom:16px; }
        .form-group label { display:block; font-weight:600; margin-bottom:7px; color:#374151; font-size:13px; }
        .form-group input,
        .form-group select { width:100%; padding:11px 14px; border:1.5px solid #e5e7eb; border-radius:10px; font-family:inherit; font-size:14px; color:#1e293b; background:#fff; transition:border .2s,box-shadow .2s; }
        .form-group input:focus,
        .form-group select:focus { outline:none; border-color:var(--brand-navy); box-shadow:0 0 0 3px rgba(18,58,109,.10); }
        .form-group input::placeholder { color:#b0bac5; }
        .row-cols { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
        .row-cols .form-group { margin-bottom:0; }
        .file-upload { border:2px dashed rgba(18,58,109,.25); border-radius:12px; padding:24px; text-align:center; cursor:pointer; transition:all .25s; background:rgba(18,58,109,.03); }
        .file-upload:hover { border-color:var(--brand-navy); background:rgba(18,58,109,.07); }
        .file-input-label { cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:8px; }
        .file-input-label i { font-size:28px; color:var(--brand-navy); }
        .file-input-label div { font-weight:700; color:var(--brand-navy-deep); font-size:14px; }
        .file-input-label small { color:#9ca3af; }
        .file-name { margin-top:8px; color:#10b981; font-weight:700; font-size:13px; display:none; }
        .file-name.show { display:block; }
        input[type="file"] { display:none; }
        .btn-submit { width:100%; padding:13px; background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; border:none; border-radius:12px; font-weight:700; cursor:pointer; font-size:15px; transition:all .25s; margin-top:20px; display:flex; align-items:center; justify-content:center; gap:10px; }
        .btn-submit:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(18,58,109,.30); }
        .alert { padding:13px 17px; border-radius:10px; margin-bottom:18px; font-size:14px; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        .alert-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .pass-hint { margin-top:5px; font-size:12px; display:none; }
        .eye-btn { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:#9ca3af; cursor:pointer; padding:0; font-size:14px; }
        .eye-btn:hover { color:var(--brand-navy); }
        select option { background:#fff; color:#1e293b; }
        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }
        @media(max-width:640px) { .row-cols { grid-template-columns:1fr; } .form-card,.public-card { padding:20px; } }
    </style>
</head>
<body class="<?= $modo_supervisor ? 'panel-mode' : 'public-mode' ?>">

<?php if ($modo_supervisor): ?>
<!-- ════════════════ MODO SUPERVISOR ════════════════ -->
<?php
$alertas_pendientes = 0;
require_once '_sidebar_supervisor.php';
?>
<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-shield-halved me-2" style="color:var(--brand-yellow);"></i>Super_IA — Supervisor</h2>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($supervisor_nombre) ?></strong><br>
                <small><?= htmlspecialchars($supervisor_rol) ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    <div class="content-area">
        <div class="page-header">
            <a href="mis_asesores.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver a Mis Asesores</a>
            <h1><i class="fas fa-user-tie me-2"></i>Registrar Nuevo Asesor</h1>
            <p class="text-muted mt-1" style="font-size:14px;">El asesor quedará pendiente de aprobación del administrador.</p>
        </div>
        <div class="form-card">

<?php else: ?>
<!-- ════════════════ MODO PÚBLICO ════════════════ -->
<div class="public-wrapper">
    <a href="login.php?role=supervisor" class="pub-back"><i class="fas fa-arrow-left me-1"></i> Volver al Login</a>
    <div class="public-header">
        <div class="icon-wrap"><i class="fas fa-user-tie"></i></div>
        <h1>Registro de Asesor</h1>
        <p>Crea tu cuenta y un supervisor la aprobará para darte acceso.</p>
    </div>
    <div class="public-card">

<?php endif; ?>

        <!-- ── Alertas ───────────────────────────────────── -->
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i>Solicitud enviada. El supervisor revisará tu registro pronto.</div>
        <?php endif; ?>

        <!-- ── Formulario ────────────────────────────────── -->
        <form method="POST" action="procesar_registro_asesor.php" enctype="multipart/form-data" novalidate>

            <?php if (!$modo_supervisor): ?>
            <!-- Selección cooperativa + supervisor (solo público) -->
            <div class="section-title"><i class="fas fa-building" style="color:var(--brand-yellow-deep);"></i> Cooperativa y Supervisor</div>
            <div class="form-group">
                <label><i class="fas fa-building me-1"></i> Cooperativa</label>
                <select name="id_cooperativa" id="id_cooperativa" required>
                    <option value="">-- Selecciona una cooperativa --</option>
                    <?php foreach ($cooperativas as $c): ?>
                        <option value="<?= (int)$c['id_cooperativa'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-user-tie me-1"></i> Supervisor</label>
                <select name="id_supervisor" id="id_supervisor" required disabled>
                    <option value="">-- Primero selecciona una cooperativa --</option>
                </select>
            </div>
            <?php endif; ?>

            <!-- Datos personales -->
            <div class="section-title"><i class="fas fa-user" style="color:var(--brand-yellow-deep);"></i> Datos Personales</div>
            <div class="row-cols">
                <div class="form-group">
                    <label><i class="fas fa-user me-1"></i> Nombres</label>
                    <input type="text" name="nombres" placeholder="Ej: Juan Carlos" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user me-1"></i> Apellidos</label>
                    <input type="text" name="apellidos" placeholder="Ej: García López" required>
                </div>
            </div>
            <div class="row-cols">
                <div class="form-group">
                    <label><i class="fas fa-envelope me-1"></i> Email</label>
                    <input type="email" name="email" placeholder="correo@ejemplo.com" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone me-1"></i> Teléfono</label>
                    <input type="tel" name="telefono" placeholder="0987654321" required>
                </div>
            </div>

            <!-- Cuenta de acceso -->
            <div class="section-title"><i class="fas fa-lock" style="color:var(--brand-yellow-deep);"></i> Cuenta de Acceso</div>
            <div class="form-group">
                <label><i class="fas fa-user-circle me-1"></i> Usuario</label>
                <input type="text" name="usuario" placeholder="Ej: jgarcia" minlength="4" required>
            </div>
            <div class="row-cols">
                <div class="form-group">
                    <label><i class="fas fa-key me-1"></i> Contraseña</label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="pass1" placeholder="Mín. 6 caracteres" minlength="6" required style="padding-right:40px;">
                        <button type="button" class="eye-btn" onclick="toggleVis('pass1','eye1')"><i class="fas fa-eye" id="eye1"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-key me-1"></i> Confirmar Contraseña</label>
                    <div style="position:relative;">
                        <input type="password" name="password_confirm" id="pass2" placeholder="Repite la contraseña" minlength="6" required style="padding-right:40px;">
                        <button type="button" class="eye-btn" onclick="toggleVis('pass2','eye2')"><i class="fas fa-eye" id="eye2"></i></button>
                    </div>
                    <div class="pass-hint" id="passHint"></div>
                </div>
            </div>

            <!-- Credencial -->
            <div class="section-title"><i class="fas fa-file-pdf" style="color:var(--brand-yellow-deep);"></i> Credencial / Nombramiento <span style="font-weight:400;text-transform:none;font-size:11px;color:#9ca3af;">(opcional)</span></div>
            <div class="form-group">
                <label><i class="fas fa-file-pdf me-1"></i> Adjuntar documento — PDF, JPG, PNG · Máx. 5 MB</label>
                <div class="file-upload" id="dropZone">
                    <label for="credencial" class="file-input-label">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div>Haz clic o arrastra tu archivo aquí</div>
                        <small>PDF, JPG o PNG · Máx. 5 MB</small>
                        <div class="file-name" id="fileName"></div>
                    </label>
                    <input type="file" name="credencial" id="credencial" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane"></i> Enviar Solicitud
            </button>

        </form>

<?php if ($modo_supervisor): ?>
        </div><!-- /.form-card -->
    </div><!-- /.content-area -->
</div><!-- /.main-content -->
<?php else: ?>
    </div><!-- /.public-card -->
</div><!-- /.public-wrapper -->
<?php endif; ?>

<script>
// ── Mostrar/ocultar contraseña ─────────────────────────────
function toggleVis(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

// ── Validar que coincidan en tiempo real ──────────────────
const p1   = document.getElementById('pass1');
const p2   = document.getElementById('pass2');
const hint = document.getElementById('passHint');

function checkPass() {
    if (!p2.value) { hint.style.display = 'none'; return; }
    hint.style.display = 'block';
    if (p1.value === p2.value) {
        hint.style.color = '#059669';
        hint.textContent = '✔ Las contraseñas coinciden';
    } else {
        hint.style.color = '#dc2626';
        hint.textContent = '✖ Las contraseñas no coinciden';
    }
}
p1.addEventListener('input', checkPass);
p2.addEventListener('input', checkPass);

document.querySelector('form').addEventListener('submit', function(e) {
    if (p1.value !== p2.value) {
        e.preventDefault();
        hint.style.display = 'block';
        hint.style.color   = '#dc2626';
        hint.textContent   = '✖ Las contraseñas no coinciden';
        p2.focus();
    }
});

// ── Carga dinámica de supervisores (modo público) ─────────
<?php if (!$modo_supervisor): ?>
const coopSel = document.getElementById('id_cooperativa');
const supSel  = document.getElementById('id_supervisor');

coopSel?.addEventListener('change', async function() {
    const id = this.value;
    supSel.innerHTML  = '<option value="">-- Cargando... --</option>';
    supSel.disabled   = true;
    if (!id) { supSel.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>'; return; }
    try {
        const res  = await fetch('get_supervisores_por_cooperativa.php?id_cooperativa=' + encodeURIComponent(id));
        const data = await res.json();
        supSel.innerHTML = '<option value="">-- Selecciona un supervisor --</option>';
        if (data.supervisores?.length) {
            data.supervisores.forEach(s => {
                const o = document.createElement('option');
                o.value       = s.id_usuario ?? s.id;
                o.textContent = s.nombre;
                supSel.appendChild(o);
            });
            supSel.disabled = false;
        } else {
            supSel.innerHTML = '<option value="" disabled>No hay supervisores en esta cooperativa</option>';
        }
    } catch(err) {
        supSel.innerHTML = '<option value="" disabled>Error al cargar</option>';
    }
});
<?php endif; ?>

// ── File upload ───────────────────────────────────────────
const fileInput = document.getElementById('credencial');
const dropZone  = document.getElementById('dropZone');
const fileName  = document.getElementById('fileName');
const allowed   = ['application/pdf','image/jpeg','image/png'];
const maxSize   = 5 * 1024 * 1024;

function handleFile(file) {
    if (!file) return;
    if (!allowed.includes(file.type)) {
        fileName.textContent = '❌ Tipo no permitido (PDF, JPG, PNG)';
        fileName.className   = 'file-name show';
        fileInput.value = ''; return;
    }
    if (file.size > maxSize) {
        fileName.textContent = '❌ Archivo muy grande (máx. 5 MB)';
        fileName.className   = 'file-name show';
        fileInput.value = ''; return;
    }
    fileName.textContent = '✅ ' + file.name;
    fileName.className   = 'file-name show';
}

dropZone.addEventListener('click',     () => fileInput.click());
fileInput.addEventListener('change',   () => handleFile(fileInput.files[0]));
dropZone.addEventListener('dragover',  e  => { e.preventDefault(); dropZone.style.borderColor = '#123a6d'; });
dropZone.addEventListener('dragleave', ()  => { dropZone.style.borderColor = ''; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '';
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        handleFile(fileInput.files[0]);
    }
});
</script>
</body>
</html>

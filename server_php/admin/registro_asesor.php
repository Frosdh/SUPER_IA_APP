<?php
// ============================================================
// admin/registro_asesor.php — Super_IA Logan
// Crear nueva solicitud de asesor (vista integrada en panel supervisor)
// ============================================================
require_once 'db_admin_superIA.php'; // provee $conn (mysqli)

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_id     = $_SESSION['supervisor_id']     ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol    = $_SESSION['supervisor_rol']    ?? 'Supervisor';

$error   = $_GET['error']   ?? '';
$success = $_GET['success'] ?? '';

// ── Pendientes para badge del sidebar ──────────────────────
$currentPage     = 'agregar';   // coincide con $nav_asesor (no aplica en supervisor)
$totalPendientes = 0;
try {
    $stSup = $conn->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    if ($stSup) {
        $stSup->bind_param('s', $supervisor_id);
        $stSup->execute();
        $rowSup     = $stSup->get_result()->fetch_assoc();
        $real_sup_id = $rowSup['id'] ?? null;
        $stSup->close();
        if ($real_sup_id) {
            $stPend = $conn->prepare(
                'SELECT COUNT(*) AS cnt FROM usuario u
                 JOIN asesor a ON a.usuario_id = u.id
                 WHERE a.supervisor_id = ? AND u.estado_registro = "pendiente"'
            );
            if ($stPend) {
                $stPend->bind_param('s', $real_sup_id);
                $stPend->execute();
                $rowPend = $stPend->get_result()->fetch_assoc();
                $totalPendientes = (int)($rowPend['cnt'] ?? 0);
                $stPend->close();
            }
        }
    }
} catch (\Throwable $e) { /* silencioso */ }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Crear Asesor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow:      #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy:        #123a6d;
            --brand-navy-deep:   #0a2748;
            --brand-gray:        #6b7280;
            --brand-border:      #d7e0ea;
            --brand-card:        #ffffff;
            --brand-bg:          #f4f6f9;
            --brand-shadow:      0 16px 34px rgba(18,58,109,.08);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter','Segoe UI',sans-serif; background:linear-gradient(180deg,#f8fafc 0%,var(--brand-bg) 100%); display:flex; height:100vh; color:var(--brand-navy-deep); }

        /* ── SIDEBAR ── */
        .sidebar { width:230px; background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%); color:#fff; padding:20px 0; overflow-y:auto; position:fixed; height:100vh; left:0; top:0; z-index:100; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding:0 15px; margin-bottom:22px; }
        .sidebar-section-title { font-size:11px; text-transform:uppercase; color:rgba(255,255,255,.5); letter-spacing:.6px; padding:0 10px; margin-bottom:10px; font-weight:700; }
        .sidebar-link { display:flex; align-items:center; gap:12px; padding:11px 15px; margin-bottom:4px; border-radius:10px; color:rgba(255,255,255,.82); text-decoration:none; font-size:14px; border:1px solid transparent; transition:all .22s; }
        .sidebar-link:hover { background:rgba(255,221,0,.12); color:#fff; padding-left:20px; border-color:rgba(255,221,0,.15); }
        .sidebar-link.active { background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep)); color:var(--brand-navy-deep); font-weight:700; }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }

        /* ── MAIN ── */
        .main-content { flex:1; margin-left:230px; display:flex; flex-direction:column; overflow:hidden; }
        .navbar-custom { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 12px 28px rgba(18,58,109,.18); }
        .navbar-custom h2 { margin:0; font-size:20px; font-weight:700; }
        .user-info { display:flex; align-items:center; gap:15px; }
        .btn-logout { background:rgba(255,221,0,.15); color:#fff; border:1px solid rgba(255,221,0,.28); padding:8px 15px; border-radius:10px; cursor:pointer; text-decoration:none; font-weight:600; }
        .btn-logout:hover { background:rgba(255,221,0,.26); color:#fff; }
        .content-area { flex:1; overflow-y:auto; padding:32px; }

        /* ── FORM CARD ── */
        .page-header { margin-bottom:28px; }
        .page-header h1 { font-size:26px; font-weight:800; color:var(--brand-navy-deep); }
        .form-card { background:var(--brand-card); border-radius:18px; box-shadow:var(--brand-shadow); padding:36px; max-width:720px; border:1px solid var(--brand-border); }
        .section-title { font-size:13px; font-weight:800; color:var(--brand-navy); text-transform:uppercase; letter-spacing:.4px; margin:28px 0 14px; padding-bottom:8px; border-bottom:2px solid var(--brand-yellow); display:flex; align-items:center; gap:8px; }
        .section-title:first-child { margin-top:0; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-weight:600; margin-bottom:7px; color:var(--brand-navy-deep); font-size:13.5px; }
        .form-group input,
        .form-group select { width:100%; padding:11px 14px; border:1.5px solid var(--brand-border); border-radius:10px; font-family:inherit; font-size:14px; color:var(--brand-navy-deep); background:#fff; transition:border .2s,box-shadow .2s; }
        .form-group input:focus,
        .form-group select:focus { outline:none; border-color:var(--brand-navy); box-shadow:0 0 0 3px rgba(18,58,109,.10); }
        .form-group input::placeholder { color:#b0bac5; }
        .row-cols { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
        .row-cols .form-group { margin-bottom:0; }

        /* ── FILE UPLOAD ── */
        .file-upload { border:2px dashed rgba(18,58,109,.25); border-radius:12px; padding:28px; text-align:center; cursor:pointer; transition:all .25s; background:rgba(18,58,109,.03); }
        .file-upload:hover { border-color:var(--brand-navy); background:rgba(18,58,109,.07); }
        .file-input-label { cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:10px; }
        .file-input-label i { font-size:30px; color:var(--brand-navy); }
        .file-input-label div { font-weight:700; color:var(--brand-navy-deep); }
        .file-input-label small { color:#9ca3af; }
        .file-name { margin-top:10px; color:#10b981; font-weight:700; font-size:13px; display:none; }
        .file-name.show { display:block; }
        input[type="file"] { display:none; }

        /* ── BUTTONS ── */
        .btn-submit { width:100%; padding:13px; background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; border:none; border-radius:12px; font-weight:700; cursor:pointer; font-size:15px; transition:all .25s; margin-top:22px; display:flex; align-items:center; justify-content:center; gap:10px; }
        .btn-submit:hover { transform:translateY(-2px); box-shadow:0 8px 22px rgba(18,58,109,.30); }
        .btn-back { padding:8px 18px; background:rgba(18,58,109,.08); color:var(--brand-navy-deep); border:1.5px solid var(--brand-border); border-radius:10px; cursor:pointer; text-decoration:none; font-weight:600; margin-bottom:22px; display:inline-flex; align-items:center; gap:8px; font-size:13.5px; transition:background .2s; }
        .btn-back:hover { background:rgba(18,58,109,.15); color:var(--brand-navy-deep); }

        /* ── ALERTS ── */
        .alert { padding:13px 17px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        .alert-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

        /* ── BADGE ── */
        .nav-badge { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }

        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-track { background:transparent; }
        ::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }

        @media (max-width:640px) { .row-cols { grid-template-columns:1fr; } .form-card { padding:20px; } }
    </style>
</head>
<body>

<?php
$alertas_pendientes = 0; // registro_asesor no carga alertas; el sidebar las muestra solo si >0
require_once '_sidebar_supervisor.php';
?>

<!-- ══════════ MAIN ══════════ -->
<div class="main-content">

    <!-- NAVBAR -->
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

    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <a href="mis_asesores.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Volver a Mis Asesores
            </a>
            <h1><i class="fas fa-user-tie me-2"></i>Crear Nuevo Asesor</h1>
            <p class="text-muted mt-1" style="font-size:14px;">Registra los datos del nuevo miembro de tu equipo</p>
        </div>

        <div class="form-card">

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                ✅ Solicitud de asesor registrada exitosamente. Espera la aprobación del administrador.
            </div>
            <?php endif; ?>

            <form method="POST" action="procesar_registro_asesor.php" enctype="multipart/form-data" novalidate>

                <!-- DATOS PERSONALES -->
                <div class="section-title">
                    <i class="fas fa-user" style="color:var(--brand-yellow-deep);"></i>
                    Datos Personales
                </div>

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
                        <input type="email" name="email" placeholder="Ej: correo@ejemplo.com" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone me-1"></i> Teléfono</label>
                        <input type="tel" name="telefono" placeholder="Ej: 0987654321" required>
                    </div>
                </div>

                <!-- CUENTA DE ACCESO -->
                <div class="section-title">
                    <i class="fas fa-lock" style="color:var(--brand-yellow-deep);"></i>
                    Cuenta de Acceso
                </div>

                <div class="row-cols">
                    <div class="form-group">
                        <label><i class="fas fa-user-circle me-1"></i> Usuario</label>
                        <input type="text" name="usuario" placeholder="Ej: jgarcia" minlength="4" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-key me-1"></i> Contraseña</label>
                        <input type="password" name="password" placeholder="Mín. 6 caracteres" minlength="6" required>
                    </div>
                </div>

                <!-- CREDENCIAL -->
                <div class="section-title">
                    <i class="fas fa-file-pdf" style="color:var(--brand-yellow-deep);"></i>
                    Credencial / Nombramiento
                </div>

                <div class="form-group">
                    <label><i class="fas fa-file-pdf me-1"></i> Adjuntar documento (PDF, JPG, PNG — Máx. 5 MB)</label>
                    <div class="file-upload" id="dropZone">
                        <label for="credencial" class="file-input-label">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <div>Haz clic o arrastra tu archivo aquí</div>
                            <small>PDF, JPG o PNG · Máx. 5 MB</small>
                            <div class="file-name" id="fileName"></div>
                        </label>
                        <input type="file" name="credencial" id="credencial" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-paper-plane"></i>
                    Enviar Solicitud de Asesor
                </button>

            </form>

        </div><!-- /.form-card -->
    </div><!-- /.content-area -->
</div><!-- /.main-content -->

<script>
// ── File upload ────────────────────────────────────────────
const fileInput = document.getElementById('credencial');
const fileLabel = document.querySelector('.file-input-label');
const fileName  = document.getElementById('fileName');
const dropZone  = document.getElementById('dropZone');

fileLabel.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const maxSize = 5 * 1024 * 1024;
    const allowed = ['application/pdf','image/jpeg','image/png'];
    if (!allowed.includes(file.type)) {
        fileName.textContent = '❌ Tipo no permitido (PDF, JPG, PNG)';
        fileName.className = 'file-name show';
        this.value = '';
        return;
    }
    if (file.size > maxSize) {
        fileName.textContent = '❌ Archivo muy grande (máx. 5 MB)';
        fileName.className = 'file-name show';
        this.value = '';
        return;
    }
    fileName.textContent = '✅ ' + file.name;
    fileName.className = 'file-name show';
});

dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.style.borderColor = '#123a6d';
    dropZone.style.background  = 'rgba(18,58,109,.09)';
});
dropZone.addEventListener('dragleave', () => {
    dropZone.style.borderColor = '';
    dropZone.style.background  = '';
});
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '';
    dropZone.style.background  = '';
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
});
</script>

</body>
</html>

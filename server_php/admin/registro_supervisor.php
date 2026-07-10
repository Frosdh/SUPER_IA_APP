<?php
require_once 'db_admin.php';

// ── Detectar modo: gerente desde su panel, o registro público ──
$modo_gerente = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// ── Datos fijos del gerente (cooperativa y administrador responsable) ──
$gerente_admin_id           = null;
$gerente_admin_nombre       = '—';
$gerente_cooperativa_id     = null;
$gerente_cooperativa_nombre = '—';
if ($modo_gerente) {
    $gerente_admin_id     = $_SESSION['admin_id']     ?? null;
    $gerente_admin_nombre = $_SESSION['admin_nombre'] ?? 'Gerente';
    try {
        // Vía 1: jefe_agencia propio → agencia → unidad_bancaria
        $st = $pdo->prepare("SELECT ag.unidad_bancaria_id FROM jefe_agencia ja JOIN agencia ag ON ag.id = ja.agencia_id WHERE ja.usuario_id = ? LIMIT 1");
        $st->execute([$gerente_admin_id]);
        $ub_id = $st->fetchColumn() ?: null;

        // Vía 2: gerente_general → unidad_bancaria
        if (!$ub_id) {
            $st = $pdo->prepare("SELECT unidad_bancaria_id FROM gerente_general WHERE usuario_id = ? LIMIT 1");
            $st->execute([$gerente_admin_id]);
            $ub_id = $st->fetchColumn() ?: null;
        }

        if ($ub_id) {
            $gerente_cooperativa_id = $ub_id;
            $st = $pdo->prepare("SELECT nombre FROM unidad_bancaria WHERE id = ? LIMIT 1");
            $st->execute([$ub_id]);
            $gerente_cooperativa_nombre = $st->fetchColumn() ?: '—';
        }
    } catch (\Throwable $e) {}
}

$success = isset($_GET['success']) ? $_GET['success'] : false;
$error = isset($_GET['error']) ? $_GET['error'] : false;

// Obtener cooperativas: unidad_bancaria (internas) + seps_cooperativas
// (catastro SEPS importado). Antes esta lista era un UNION SELECT con 4
// cooperativas fijas de ejemplo ("Super_IA - Quito/Guayaquil/Cuenca/Ambato"),
// sin tocar ninguna tabla real — por eso nunca aparecían los bancos
// importados. Se cambia para leer las mismas dos tablas que ya se usan en
// registro_asesor.php y en Encuestas.
$cooperativas = [];
try {
    $stmt = $pdo->query("SELECT id AS id_cooperativa, nombre FROM unidad_bancaria ORDER BY nombre ASC");
    $cooperativas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $cooperativas = [];
}
try {
    $stmtSepsSup = $pdo->query("
        SELECT CONCAT('seps_', id) AS id_cooperativa, razon_social AS nombre
        FROM seps_cooperativas
        WHERE activo = 1
        ORDER BY razon_social ASC
    ");
    foreach ($stmtSepsSup->fetchAll(PDO::FETCH_ASSOC) as $scSup) {
        $cooperativas[] = $scSup;
    }
} catch (\Throwable $e) {
    // Tabla SEPS ausente: se ignora, quedan solo las internas.
}

// Si por algún motivo ambas consultas fallan (tablas ausentes), usar los
// 4 valores de ejemplo como último respaldo para no dejar el select vacío.
if (empty($cooperativas)) {
    $cooperativas = [
        ['id_cooperativa' => 1, 'nombre' => 'Super_IA - Quito'],
        ['id_cooperativa' => 2, 'nombre' => 'Super_IA - Guayaquil'],
        ['id_cooperativa' => 3, 'nombre' => 'Super_IA - Cuenca'],
        ['id_cooperativa' => 4, 'nombre' => 'Super_IA - Ambato']
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Crear Cuenta de Supervisor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body.public-mode {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f8fafc;
            padding: 2rem;
        }

        /* ── PANEL (modo gerente) ── */
        body.panel-mode {
            font-family:'Inter','Segoe UI',sans-serif;
            background:linear-gradient(180deg,#f8fafc 0%,var(--brand-bg) 100%);
            display:flex; height:100vh; color:var(--brand-navy-deep);
            padding: 0;
        }
        .btn-back-link { padding:8px 18px; background:rgba(18,58,109,.08); color:var(--brand-navy-deep); border:1.5px solid var(--brand-border); border-radius:10px; cursor:pointer; text-decoration:none; font-weight:600; margin-bottom:14px; display:inline-flex; align-items:center; gap:8px; font-size:13.5px; transition:background .2s; }
        .btn-back-link:hover { background:rgba(18,58,109,.15); color:var(--brand-navy-deep); }
        .form-container-centered { max-width:100%; margin:0 auto; padding-bottom:40px; }
        .page-header { margin-bottom:20px; }
        .page-header h1 { font-size:26px; font-weight:800; color:var(--brand-navy-deep); }
        .form-card { background:var(--brand-card); border-radius:18px; box-shadow:var(--brand-shadow); padding:30px; max-width:100%; border:1px solid var(--brand-border); }

        /* Overrides claros para el formulario dentro del panel (tema oscuro -> claro) */
        body.panel-mode .form-card .form-group label { color:#374151; }
        body.panel-mode .form-card .form-control {
            background:#fff; border:1.5px solid #e5e7eb; color:#1e293b;
            box-shadow:none;
        }
        body.panel-mode .form-card .form-control:focus {
            background:#fff; border-color:var(--brand-navy); color:#1e293b;
            box-shadow:0 0 0 3px rgba(18,58,109,.10);
        }
        body.panel-mode .form-card .form-control::placeholder { color:#b0bac5; }
        body.panel-mode .form-card .field-readonly {
            display:flex; align-items:center;
            background:#f1f5f9; border:1.5px solid #e5e7eb; color:#64748b;
            cursor:not-allowed; font-weight:600;
        }
        body.panel-mode .form-card .form-control option { background:#fff; color:#1e293b; }
        body.panel-mode .form-card select.form-control {
            background-color: #fff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23123a6d' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
        }
        body.panel-mode .form-card .btn-submit {
            background: linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));
        }
        body.panel-mode .form-card .btn-submit:hover {
            box-shadow: 0 10px 20px rgba(18,58,109,.25);
        }
        body.panel-mode .form-card .info-box {
            background: rgba(18,58,109,.06); border:1px solid rgba(18,58,109,.18); color: var(--brand-navy-deep);
        }
        body.panel-mode .form-card .alert-success { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; }
        body.panel-mode .form-card .alert-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        body.panel-mode .form-card .file-input-label {
            background: rgba(18,58,109,.03); border:2px dashed rgba(18,58,109,.25); color:#6b7280;
        }
        body.panel-mode .form-card .file-input-label:hover {
            background: rgba(18,58,109,.07); border-color: var(--brand-navy); color:#374151;
        }
        body.panel-mode .form-card .file-input-label i { color: var(--brand-navy); }
        body.panel-mode .form-card .eye-btn-color { color:#9ca3af; }
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
        .coop-buscador-wrap { position: relative; }
        .coop-buscador-list {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 50;
            max-height: 260px;
            overflow-y: auto;
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            margin-top: 6px;
            box-shadow: 0 12px 28px rgba(0,0,0,.35);
        }
        .coop-buscador-item { padding: 10px 14px; font-size: 0.9rem; color: #e2e8f0; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .coop-buscador-item:last-child { border-bottom: none; }
        .coop-buscador-item:hover { background: rgba(49,130,254,0.25); }
        .coop-buscador-empty { padding: 12px 14px; font-size: 0.85rem; color: #94a3b8; font-style: italic; }
    </style>
</head>
<body class="<?= $modo_gerente ? 'panel-mode' : 'public-mode' ?>">

<?php if ($modo_gerente): ?>
<!-- ════════════════ MODO GERENTE (panel) ════════════════ -->
<?php
$alertas_pendientes = 0;
$currentPage = 'nuevo_supervisor';
require_once '_sidebar_gerente.php';
?>
    <div class="form-container-centered">
        <div class="page-header">
            <a href="mis_supervisores.php" class="btn-back-link"><i class="fas fa-arrow-left"></i> Volver a Mis Supervisores</a>
            <h1><i class="fas fa-user-tie me-2"></i>Crear Cuenta de Supervisor</h1>
            <p class="text-muted mt-1" style="font-size:14px;">Completa los datos para registrar un nuevo supervisor.</p>
        </div>
        <div class="form-card">
<?php else: ?>
<!-- ════════════════ MODO PÚBLICO (standalone) ════════════════ -->
    <div class="container-custom">
        <div class="card-custom">
            <div class="header-custom">
                <div class="icon-header">
                    <i class="fas fa-user-tie"></i>
                </div>
                <h1>Crear Cuenta de Supervisor</h1>
                <p>Crea una cuenta de supervisor</p>
            </div>
<?php endif; ?>

            <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Solicitud Enviada</strong><br>
                Tu solicitud ha sido enviada. El Administrador la revisará pronto.
                <?php if (!$modo_gerente): ?>
                <br>Serás redirigido al inicio de sesión de supervisor en <span id="countdownRedirectSup">4</span> segundos…
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <?php if (!$modo_gerente): ?>
            <div class="info-box">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Selección Requerida:</strong> Escoge la cooperativa y el gerente responsable se filtrará automáticamente.
            </div>
            <?php endif; ?>

            <form method="POST" action="procesar_registro_supervisor.php" enctype="multipart/form-data" novalidate>
                <?php if ($modo_gerente): ?>
                <div class="form-group">
                    <label><i class="fas fa-building me-2"></i>Cooperativa</label>
                    <div class="form-control field-readonly"><?= htmlspecialchars($gerente_cooperativa_nombre) ?></div>
                    <input type="hidden" name="cooperativa" value="<?= htmlspecialchars((string)$gerente_cooperativa_id) ?>">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user-cog me-2"></i>Gerente Responsable</label>
                    <div class="form-control field-readonly"><?= htmlspecialchars($gerente_admin_nombre) ?></div>
                    <input type="hidden" name="gerente" value="<?= htmlspecialchars((string)$gerente_admin_id) ?>">
                </div>
                <?php else: ?>
                <div class="form-group coop-buscador-wrap">
                    <label for="cooperativa_buscar"><i class="fas fa-building me-2"></i>Cooperativa</label>
                    <input type="text" class="form-control" id="cooperativa_buscar" placeholder="Escribe para buscar tu cooperativa/banco…" autocomplete="off">
                    <input type="hidden" id="cooperativa" name="cooperativa" required>
                    <div id="cooperativa_lista" class="coop-buscador-list"></div>
                </div>

                <div class="form-group">
                    <label for="gerente"><i class="fas fa-user-cog me-2"></i>Gerente Responsable</label>
                    <select name="gerente" id="gerente" class="form-control" required>
                        <option value="">-- Primero selecciona una cooperativa --</option>
                    </select>
                </div>
                <?php endif; ?>

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

                <div class="row-cols">
                    <div class="form-group">
                        <label for="cedula"><i class="fas fa-id-card me-2"></i>Cédula de Identidad</label>
                        <input type="text" name="cedula" id="cedula" class="form-control" placeholder="1712345678" maxlength="10" inputmode="numeric" required>
                    </div>
                    <div class="form-group">
                        <label for="telefono"><i class="fas fa-phone me-2"></i>Teléfono</label>
                        <input type="tel" name="telefono" id="telefono" class="form-control" placeholder="+593 98 1234567" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><i class="fas fa-lock me-2"></i>Contraseña</label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="password" class="form-control" placeholder="Mín. 6 caracteres" required style="padding-right:42px;">
                        <button type="button" onclick="toggleVis('password','eyeP')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:0;font-size:14px;"><i class="fas fa-eye" id="eyeP"></i></button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password_confirm"><i class="fas fa-lock me-2"></i>Confirmar Contraseña</label>
                    <div style="position:relative;">
                        <input type="password" name="password_confirm" id="password_confirm" class="form-control" placeholder="Repite tu contraseña" required style="padding-right:42px;">
                        <button type="button" onclick="toggleVis('password_confirm','eyeC')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#64748b;cursor:pointer;padding:0;font-size:14px;"><i class="fas fa-eye" id="eyeC"></i></button>
                    </div>
                    <div id="pass-msg" style="margin-top:6px;font-size:12.5px;display:none;"></div>
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
                <?php if (!$modo_gerente): ?>
                <a href="login.php?role=supervisor" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Volver a Login</a>
                <?php endif; ?>
            </form>

<?php if ($modo_gerente): ?>
        </div><!-- /.form-card -->
    </div><!-- /.form-container-centered -->
</div><!-- /.content-area -->
</div><!-- /.main-content -->
<?php else: ?>
        </div><!-- /.card-custom -->
    </div><!-- /.container-custom -->
<?php endif; ?>

<script src="js/validaciones.js"></script>
<script src="js/cooperativa_buscador.js"></script>
<script>
// ── Validación completa en tiempo real (nombres, apellidos, teléfono, email, usuario, password) ──
bindValidaciones('form');

// ── Validar cédula ecuatoriana en tiempo real ──────────────
const cedulaInput = document.getElementById('cedula');
cedulaInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
    const r = validarCedulaEc(this.value);
    setFieldState(this, this.value ? r.ok : null, r.msg);
});

// ── Mostrar/ocultar contraseña ─────────────────────────────
function toggleVis(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// ── Validar que las contraseñas coincidan ──────────────────
const passInput    = document.getElementById('password');
const confirmInput = document.getElementById('password_confirm');
const passMsg      = document.getElementById('pass-msg');

function checkPasswords() {
    if (!confirmInput.value) { passMsg.style.display = 'none'; return; }
    if (passInput.value === confirmInput.value) {
        passMsg.style.display = 'block';
        passMsg.style.color   = '#10b981';
        passMsg.textContent   = '✔ Las contraseñas coinciden';
    } else {
        passMsg.style.display = 'block';
        passMsg.style.color   = '#ef4444';
        passMsg.textContent   = '✖ Las contraseñas no coinciden';
    }
}
passInput.addEventListener('input', checkPasswords);
confirmInput.addEventListener('input', checkPasswords);

document.querySelector('form').addEventListener('submit', function(e) {
    let valido = true;

    if (passInput.value !== confirmInput.value) {
        passMsg.style.display = 'block';
        passMsg.style.color   = '#ef4444';
        passMsg.textContent   = '✖ Las contraseñas no coinciden';
        valido = false;
    }

    const rCedula = validarCedulaEc(cedulaInput.value);
    if (!setFieldState(cedulaInput, !!cedulaInput.value && rCedula.ok, cedulaInput.value ? rCedula.msg : 'La cédula es requerida')) {
        valido = false;
    }

    if (!valido) {
        e.preventDefault();
        if (!cedulaInput.value || !rCedula.ok) {
            cedulaInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            cedulaInput.focus();
        } else {
            confirmInput.focus();
        }
    }
});

<?php if (!$modo_gerente): ?>
function cargarGerentesDeCooperativa(coopId) {
    const gerenteSelect = document.getElementById('gerente');

    if (!coopId) {
        gerenteSelect.innerHTML = '<option value="">-- Primero selecciona una cooperativa --</option>';
        return;
    }

    gerenteSelect.innerHTML = '<option value="">Cargando gerentes…</option>';

    // Cargar gerentes reales vinculados a esa cooperativa
    fetch(`api_gerentes_por_coop.php?cooperativa_id=${encodeURIComponent(coopId)}`)
        .then(res => res.json())
        .then(data => {
            gerenteSelect.innerHTML = '<option value="">-- Selecciona un gerente --</option>';

            if (data.gerentes && data.gerentes.length > 0) {
                data.gerentes.forEach(ger => {
                    const option = document.createElement('option');
                    option.value = ger.id_usuario;
                    option.textContent = ger.nombre + ' (' + ger.email + ')';
                    gerenteSelect.appendChild(option);
                });
            } else {
                gerenteSelect.innerHTML = '<option value="">No hay gerente asignado a esta cooperativa</option>';
            }
        })
        .catch(err => {
            console.error('Error cargando gerentes:', err);
            gerenteSelect.innerHTML = '<option value="">Error al cargar gerentes</option>';
        });
}

initCooperativaBuscador({
    inputId:  'cooperativa_buscar',
    hiddenId: 'cooperativa',
    listId:   'cooperativa_lista',
    data:     <?= json_encode(array_map(function ($c) {
        return ['id' => (string)$c['id_cooperativa'], 'nombre' => (string)$c['nombre']];
    }, $cooperativas), JSON_UNESCAPED_UNICODE) ?>,
    onSelect: function (item) { cargarGerentesDeCooperativa(item.id); }
});

// El "required" del navegador no aplica a inputs type="hidden", así que se
// valida a mano que se haya elegido una cooperativa real de la lista.
document.querySelector('form').addEventListener('submit', function (e) {
    const coopHidden = document.getElementById('cooperativa');
    if (!coopHidden.value) {
        e.preventDefault();
        alert('Selecciona tu cooperativa de la lista de sugerencias.');
        document.getElementById('cooperativa_buscar').focus();
    }
});
<?php endif; ?>

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

// ── Tras registrarse con éxito (modo público), redirigir automáticamente
// al login de supervisor — igual que se hizo para el registro de asesor.
<?php if ($success && !$modo_gerente): ?>
(function () {
    let seg = 4;
    const span = document.getElementById('countdownRedirectSup');
    const timer = setInterval(() => {
        seg--;
        if (span) span.textContent = seg;
        if (seg <= 0) {
            clearInterval(timer);
            window.location.href = 'login.php?role=supervisor';
        }
    }, 1000);
})();
<?php endif; ?>
</script>

</body>
</html>

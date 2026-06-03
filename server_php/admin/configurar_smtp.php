<?php
// ============================================================
// admin/configurar_smtp.php — Configuración SMTP desde el Panel
// Solo accesible para super_admin o admin logueados.
// ============================================================
require_once 'db_admin.php';

// Seguridad: solo super_admin o admin pueden acceder
$allowed = (
    (!empty($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) ||
    (!empty($_SESSION['admin_logged_in'])       && $_SESSION['admin_logged_in']       === true)
);
if (!$allowed) {
    header('Location: login_selector.php');
    exit;
}

$configPath = __DIR__ . '/../email_config.php';
$error   = '';
$success = '';

// ── Cargar configuración actual ──────────────────────────────
$current = [];
if (file_exists($configPath)) {
    $current = require $configPath;
}

// ── Procesar guardado ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host       = trim($_POST['host']       ?? '');
    $port       = (int)($_POST['port']      ?? 587);
    $secure     = trim($_POST['secure']     ?? 'tls');
    $username   = trim($_POST['username']   ?? '');
    $password   = trim($_POST['password']   ?? '');
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name  = trim($_POST['from_name']  ?? 'Super_IA');

    // Si el password quedó en blanco, conservar el anterior
    if (empty($password) && !empty($current['password'])) {
        $password = $current['password'];
    }

    if (empty($host) || empty($username) || empty($from_email)) {
        $error = 'Completa todos los campos obligatorios.';
    } else {
        // Generar el contenido del archivo email_config.php
        $php = "<?php\n";
        $php .= "// ============================================================\n";
        $php .= "// email_config.php  —  Credenciales SMTP para PHPMailer\n";
        $php .= "// Generado automáticamente desde configurar_smtp.php\n";
        $php .= "// ============================================================\n\n";
        $php .= "return [\n";
        $php .= "    'host'       => " . var_export($host,       true) . ",\n";
        $php .= "    'port'       => " . (int)$port              . ",\n";
        $php .= "    'secure'     => " . var_export($secure,     true) . ",\n";
        $php .= "    'username'   => " . var_export($username,   true) . ",\n";
        $php .= "    'password'   => " . var_export($password,   true) . ",\n";
        $php .= "    'from_email' => " . var_export($from_email, true) . ",\n";
        $php .= "    'from_name'  => " . var_export($from_name,  true) . ",\n";
        $php .= "];\n";

        if (file_put_contents($configPath, $php) !== false) {
            $success = '¡Configuración SMTP guardada correctamente!';
            // Recargar la configuración para mostrar valores actualizados
            $current = require $configPath;
        } else {
            $error = 'No se pudo escribir el archivo email_config.php. Verifica los permisos del servidor.';
        }
    }
}

// ── Probar conexión (AJAX) ───────────────────────────────────
if (isset($_GET['test']) && $_GET['test'] === '1') {
    header('Content-Type: application/json');
    $helperPath = __DIR__ . '/../email_helper.php';
    if (!file_exists($helperPath)) {
        echo json_encode(['ok' => false, 'msg' => 'No se encontró email_helper.php']);
        exit;
    }
    require_once $helperPath;
    $toEmail = trim($_GET['to'] ?? ($current['from_email'] ?? ''));
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'msg' => 'Correo de prueba inválido.']);
        exit;
    }
    $html  = '<div style="font-family:Arial;padding:24px;background:#f0fdf4;border-radius:12px;"><h2 style="color:#166534;">✅ Conexión SMTP verificada</h2><p>El correo de Super_IA está configurado correctamente.</p></div>';
    $plain = 'Conexión SMTP verificada. El correo de Super_IA está configurado correctamente.';
    list($sent, $err) = sendEmailMessage($toEmail, 'Prueba SMTP — Super_IA', $html, $plain);
    echo json_encode(['ok' => $sent, 'msg' => $sent ? 'Correo de prueba enviado a ' . htmlspecialchars($toEmail) : $err]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Configurar SMTP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:linear-gradient(135deg,#0b1929 0%,#112035 60%,#0f1e30 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;padding:24px 0;}
        body::before{content:'';position:fixed;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(255,200,0,.07) 0%,transparent 70%);top:-150px;right:-100px;pointer-events:none;}
        body::after{content:'';position:fixed;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(49,130,254,.09) 0%,transparent 70%);bottom:-80px;left:-80px;pointer-events:none;}
        .card{width:540px;max-width:96vw;background:#fff;border-radius:22px;padding:44px 40px;box-shadow:0 30px 80px rgba(0,0,0,.45);position:relative;z-index:1;}
        .icon-wrap{width:60px;height:60px;background:linear-gradient(135deg,#6b11ff,#3182fe);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;margin:0 auto 18px;}
        h2{font-size:22px;font-weight:800;color:#1e293b;text-align:center;margin-bottom:4px;}
        .subtitle{font-size:13px;color:#64748b;text-align:center;margin-bottom:28px;line-height:1.5;}
        .section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin:20px 0 12px;padding-bottom:6px;border-bottom:1px solid #f1f5f9;}
        .inp-group{margin-bottom:14px;}
        .inp-group label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;}
        .inp-row{display:flex;gap:12px;}
        .inp-row .inp-group{flex:1;}
        .inp-wrap{position:relative;}
        .inp-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;}
        .inp-wrap input,.inp-wrap select{width:100%;padding:11px 12px 11px 36px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:13.5px;font-family:'Inter',sans-serif;color:#1e293b;outline:none;transition:.2s;background:#fff;}
        .inp-wrap input:focus,.inp-wrap select:focus{border-color:#6b11ff;box-shadow:0 0 0 3px rgba(107,17,255,.10);}
        .inp-wrap.no-icon input{padding-left:12px;}
        .badge-required{font-size:10px;color:#ef4444;font-weight:700;margin-left:4px;}
        .hint{font-size:11px;color:#94a3b8;margin-top:5px;line-height:1.4;}
        .hint a{color:#6b11ff;font-weight:600;text-decoration:none;}
        .btn-main{width:100%;padding:13px;background:linear-gradient(135deg,#6b11ff,#3182fe);border:none;border-radius:11px;color:#fff;font-size:14.5px;font-weight:700;cursor:pointer;transition:.22s;box-shadow:0 6px 20px rgba(107,17,255,.28);font-family:'Inter',sans-serif;margin-top:6px;}
        .btn-main:hover{opacity:.92;transform:translateY(-1px);}
        .btn-test{width:100%;padding:11px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;font-family:'Inter',sans-serif;margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px;}
        .btn-test:hover{background:#f8fafc;color:#6b11ff;border-color:#6b11ff;}
        .btn-back{display:block;text-align:center;margin-top:12px;padding:10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:600;text-decoration:none;transition:.2s;}
        .btn-back:hover{background:#f8fafc;color:#1e293b;}
        .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:11px 14px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:11px 14px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .alert-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:10px;padding:11px 14px;font-size:12.5px;margin-bottom:20px;line-height:1.5;}
        .alert-info strong{display:block;font-size:13px;margin-bottom:4px;}
        .tag{display:inline-block;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe;}
        .test-result{display:none;margin-top:10px;border-radius:10px;padding:11px 14px;font-size:13px;}
        .footer{margin-top:20px;text-align:center;font-size:12px;color:#9ca3af;}
        .pass-toggle{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:13px;padding:2px;}
        .pass-toggle:hover{color:#6b11ff;}
    </style>
</head>
<body>
<div class="card">
    <div class="icon-wrap"><i class="fas fa-mail-bulk"></i></div>
    <h2>Configurar SMTP</h2>
    <p class="subtitle">Actualiza las credenciales del servidor de correo para envío de códigos OTP y notificaciones.</p>

    <?php if ($error): ?>
        <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="alert-info">
        <strong><i class="fas fa-lightbulb" style="color:#f59e0b;"></i>&nbsp; Si usas Gmail:</strong>
        1. Ve a <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:#1d4ed8;font-weight:700;">myaccount.google.com/apppasswords</a><br>
        2. Crea una contraseña de aplicación para "Super_IA"<br>
        3. Copia las 16 letras (sin espacios) y pégalas en <strong>Contraseña de aplicación</strong>
    </div>

    <form method="POST" id="smtp-form">

        <div class="section-label"><i class="fas fa-server me-1"></i>Servidor SMTP</div>

        <div class="inp-row">
            <div class="inp-group" style="flex:2;">
                <label>Host SMTP <span class="badge-required">*</span></label>
                <div class="inp-wrap">
                    <i class="fas fa-globe"></i>
                    <input type="text" name="host" id="host" value="<?= htmlspecialchars($current['host'] ?? 'smtp.gmail.com') ?>" placeholder="smtp.gmail.com" required>
                </div>
            </div>
            <div class="inp-group" style="flex:1;">
                <label>Puerto</label>
                <div class="inp-wrap">
                    <i class="fas fa-plug"></i>
                    <input type="number" name="port" value="<?= (int)($current['port'] ?? 587) ?>" min="1" max="65535">
                </div>
            </div>
            <div class="inp-group" style="flex:1;">
                <label>Cifrado</label>
                <div class="inp-wrap">
                    <i class="fas fa-lock"></i>
                    <select name="secure">
                        <option value="tls" <?= ($current['secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= ($current['secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="section-label"><i class="fas fa-user me-1"></i>Credenciales</div>

        <div class="inp-group">
            <label>Correo de usuario (username) <span class="badge-required">*</span></label>
            <div class="inp-wrap">
                <i class="fas fa-envelope"></i>
                <input type="email" name="username" value="<?= htmlspecialchars($current['username'] ?? '') ?>" placeholder="tu_correo@gmail.com" required>
            </div>
        </div>

        <div class="inp-group">
            <label>Contraseña de aplicación <span style="font-size:10px;color:#94a3b8;">(deja en blanco para no cambiarla)</span></label>
            <div class="inp-wrap">
                <i class="fas fa-key"></i>
                <input type="password" name="password" id="smtp-pass" placeholder="16 letras sin espacios (ej: abcdwxyzefghijkl)" autocomplete="new-password">
                <button type="button" class="pass-toggle" onclick="togglePass()" id="eye-btn"><i class="fas fa-eye" id="eye-icon"></i></button>
            </div>
            <p class="hint">La contraseña de aplicación tiene 16 caracteres. <strong>NO</strong> es tu contraseña normal de Gmail. <a href="https://myaccount.google.com/apppasswords" target="_blank">Generar una nueva &rarr;</a></p>
        </div>

        <div class="section-label"><i class="fas fa-paper-plane me-1"></i>Remitente</div>

        <div class="inp-row">
            <div class="inp-group">
                <label>Correo remitente (From) <span class="badge-required">*</span></label>
                <div class="inp-wrap">
                    <i class="fas fa-at"></i>
                    <input type="email" name="from_email" value="<?= htmlspecialchars($current['from_email'] ?? '') ?>" placeholder="tu_correo@gmail.com" required>
                </div>
            </div>
            <div class="inp-group">
                <label>Nombre del remitente</label>
                <div class="inp-wrap">
                    <i class="fas fa-id-badge"></i>
                    <input type="text" name="from_name" value="<?= htmlspecialchars($current['from_name'] ?? 'Super_IA') ?>" placeholder="Super_IA">
                </div>
            </div>
        </div>

        <button type="submit" class="btn-main"><i class="fas fa-save me-2"></i>Guardar Configuración SMTP</button>
    </form>

    <hr style="border:none;border-top:1px solid #f1f5f9;margin:20px 0 14px;">

    <div class="inp-group mb-0">
        <label>Correo de destino para la prueba</label>
        <div class="inp-wrap">
            <i class="fas fa-envelope-open-text"></i>
            <input type="email" id="test-email" placeholder="correo@ejemplo.com" value="<?= htmlspecialchars($current['username'] ?? '') ?>">
        </div>
    </div>
    <button type="button" class="btn-test" onclick="testSMTP()">
        <i class="fas fa-paper-plane" id="test-icon"></i>
        <span id="test-label">Probar conexión y enviar correo de prueba</span>
    </button>
    <div class="test-result" id="test-result"></div>

    <a href="login_selector.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Volver al Panel</a>
    <div class="footer">Super_IA &copy; 2026</div>
</div>

<script>
function togglePass() {
    const inp  = document.getElementById('smtp-pass');
    const icon = document.getElementById('eye-icon');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

async function testSMTP() {
    const to  = document.getElementById('test-email').value.trim();
    const btn = document.querySelector('.btn-test');
    const lbl = document.getElementById('test-label');
    const ico = document.getElementById('test-icon');
    const res = document.getElementById('test-result');

    if (!to) { alert('Ingresa un correo de destino para la prueba.'); return; }

    btn.disabled = true;
    lbl.textContent = 'Enviando...';
    ico.className = 'fas fa-spinner fa-spin';
    res.style.display = 'none';

    try {
        const r   = await fetch('configurar_smtp.php?test=1&to=' + encodeURIComponent(to));
        const data = await r.json();
        res.style.display = 'block';
        if (data.ok) {
            res.style.background = '#f0fdf4';
            res.style.border     = '1px solid #bbf7d0';
            res.style.color      = '#166534';
            res.innerHTML = '<i class="fas fa-check-circle me-2"></i>' + data.msg;
        } else {
            res.style.background = '#fef2f2';
            res.style.border     = '1px solid #fecaca';
            res.style.color      = '#dc2626';
            res.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>' + data.msg;
        }
    } catch(e) {
        res.style.display = 'block';
        res.style.background = '#fef2f2';
        res.style.border     = '1px solid #fecaca';
        res.style.color      = '#dc2626';
        res.innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Error al conectar con el servidor.';
    }

    btn.disabled = false;
    lbl.textContent = 'Probar conexión y enviar correo de prueba';
    ico.className = 'fas fa-paper-plane';
}
</script>
</body>
</html>

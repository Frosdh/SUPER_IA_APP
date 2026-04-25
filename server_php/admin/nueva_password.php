<?php
// ============================================================
// admin/nueva_password.php — Paso 3: Establecer nueva contraseña
// ============================================================
require_once 'db_admin.php';

// Verificar que vino del flujo correcto
if (empty($_SESSION['recovery_email']) || empty($_SESSION['recovery_otp_ok'])) {
    header('Location: recuperar_password.php');
    exit;
}

$email = $_SESSION['recovery_email'];
$role  = $_SESSION['recovery_role'] ?? 'admin';
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nueva     = $_POST['nueva_password'] ?? '';
    $confirmar = $_POST['confirmar_password'] ?? '';

    if (strlen($nueva) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($nueva !== $confirmar) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        // Actualizar en la tabla usuario
        $hash = password_hash($nueva, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE usuario SET password_hash = ? WHERE email = ? AND activo = 1");
        $stmt->execute([$hash, $email]);

        // También actualizar la tabla solicitudes_supervisor / solicitudes_asesor si aplica
        // (para que el hash esté consistente si aún no fue movido a usuario)
        try {
            $pdo->prepare("UPDATE solicitudes_supervisor SET password_hash = ? WHERE email = ?")->execute([$hash, $email]);
        } catch (\Throwable $e) { /* tabla puede no existir */ }

        // Limpiar sesión de recuperación
        unset($_SESSION['recovery_email'], $_SESSION['recovery_role'], $_SESSION['recovery_otp_ok']);

        $success = 'Contraseña actualizada correctamente. Ya puedes iniciar sesión.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Nueva Contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 40%,#1e3a5f 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;position:relative;}
        body::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(107,17,255,.18) 0%,transparent 70%);top:-150px;left:-100px;}
        body::after{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(49,130,254,.15) 0%,transparent 70%);bottom:-100px;right:-80px;}
        .card{width:440px;max-width:95vw;background:#fff;border-radius:20px;padding:44px 40px;box-shadow:0 30px 80px rgba(0,0,0,.40);position:relative;z-index:1;}
        .icon-wrap{width:60px;height:60px;background:linear-gradient(135deg,#6b11ff,#3182fe);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;margin:0 auto 20px;}
        h2{font-size:22px;font-weight:800;color:#1e293b;text-align:center;margin-bottom:6px;}
        .subtitle{font-size:13.5px;color:#64748b;text-align:center;margin-bottom:28px;line-height:1.5;}
        .inp-group{margin-bottom:18px;}
        .inp-group label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:7px;}
        .inp-wrap{position:relative;}
        .inp-wrap i.icon-left{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:14px;}
        .inp-wrap input{width:100%;padding:12px 42px 12px 40px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:'Inter',sans-serif;color:#1e293b;outline:none;transition:.2s;}
        .inp-wrap input:focus{border-color:#6b11ff;box-shadow:0 0 0 3px rgba(107,17,255,.10);}
        .toggle-btn{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;padding:4px;font-size:14px;transition:.2s;}
        .toggle-btn:hover{color:#6b11ff;}
        .pass-hint{margin-top:6px;font-size:12px;display:none;}
        .btn-main{width:100%;padding:13px;background:linear-gradient(135deg,#6b11ff,#3182fe);border:none;border-radius:11px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:.22s;box-shadow:0 6px 20px rgba(107,17,255,.30);font-family:'Inter',sans-serif;margin-top:6px;}
        .btn-main:hover{opacity:.92;transform:translateY(-2px);}
        .btn-login{display:block;text-align:center;margin-top:14px;padding:10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:600;text-decoration:none;transition:.2s;}
        .btn-login:hover{background:#f8fafc;color:#1e293b;}
        .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:11px 16px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:11px 16px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .footer{margin-top:24px;text-align:center;font-size:12px;color:#9ca3af;}
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap"><i class="fas fa-lock-open"></i></div>
        <h2>Nueva Contraseña</h2>
        <p class="subtitle">Crea una contraseña segura para tu cuenta <strong><?= htmlspecialchars($email) ?></strong>.</p>

        <?php if ($error): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
            <a href="login.php?role=<?= htmlspecialchars($role) ?>" class="btn-main" style="display:block;text-align:center;text-decoration:none;margin-top:0;">
                <i class="fas fa-sign-in-alt me-2"></i>Ir al Login
            </a>
        <?php else: ?>
        <form method="POST" id="pass-form">
            <div class="inp-group">
                <label>Nueva Contraseña</label>
                <div class="inp-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input type="password" name="nueva_password" id="nueva" placeholder="Mín. 6 caracteres" required>
                    <button type="button" class="toggle-btn" onclick="toggleV('nueva','eyeN')"><i class="fas fa-eye" id="eyeN"></i></button>
                </div>
            </div>
            <div class="inp-group">
                <label>Confirmar Nueva Contraseña</label>
                <div class="inp-wrap">
                    <i class="fas fa-lock icon-left"></i>
                    <input type="password" name="confirmar_password" id="confirmar" placeholder="Repite la contraseña" required>
                    <button type="button" class="toggle-btn" onclick="toggleV('confirmar','eyeC')"><i class="fas fa-eye" id="eyeC"></i></button>
                </div>
                <div class="pass-hint" id="hint"></div>
            </div>
            <button type="submit" class="btn-main"><i class="fas fa-save me-2"></i>Guardar Contraseña</button>
        </form>
        <a href="login.php?role=<?= htmlspecialchars($role) ?>" class="btn-login"><i class="fas fa-arrow-left me-2"></i>Cancelar</a>
        <?php endif; ?>

        <div class="footer">Super_IA &copy; 2026</div>
    </div>

<script>
function toggleV(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

const nueva     = document.getElementById('nueva');
const confirmar = document.getElementById('confirmar');
const hint      = document.getElementById('hint');

function checkMatch() {
    if (!confirmar || !confirmar.value) { if(hint) hint.style.display='none'; return; }
    hint.style.display = 'block';
    if (nueva.value === confirmar.value) {
        hint.style.color = '#166534';
        hint.textContent = '✔ Las contraseñas coinciden';
    } else {
        hint.style.color = '#dc2626';
        hint.textContent = '✖ Las contraseñas no coinciden';
    }
}
if(nueva) nueva.addEventListener('input', checkMatch);
if(confirmar) confirmar.addEventListener('input', checkMatch);

const form = document.getElementById('pass-form');
if(form) form.addEventListener('submit', function(e) {
    if (nueva.value !== confirmar.value) {
        e.preventDefault();
        hint.style.display = 'block';
        hint.style.color   = '#dc2626';
        hint.textContent   = '✖ Las contraseñas no coinciden';
        confirmar.focus();
    }
});
</script>
</body>
</html>

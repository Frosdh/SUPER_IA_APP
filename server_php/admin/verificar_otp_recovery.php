<?php
// ============================================================
// admin/verificar_otp_recovery.php — Paso 2: Verificar código OTP
// ============================================================
require_once 'db_admin.php';

// Si no hay email en sesión, redirigir
if (empty($_SESSION['recovery_email'])) {
    header('Location: recuperar_password.php');
    exit;
}

$email = $_SESSION['recovery_email'];
$role  = $_SESSION['recovery_role'] ?? 'admin';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = trim($_POST['codigo'] ?? '');

    if (strlen($codigo) !== 6 || !ctype_digit($codigo)) {
        $error = 'Ingresa el código de 6 dígitos que recibiste.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id FROM email_otp_codes
             WHERE email = ? AND codigo = ? AND usado = 0 AND expira_en > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$email, $codigo]);
        $row = $stmt->fetch();

        if (!$row) {
            $error = 'Código incorrecto o expirado. Solicita uno nuevo.';
        } else {
            // Marcar como usado
            $pdo->prepare("UPDATE email_otp_codes SET usado = 1 WHERE id = ?")->execute([$row['id']]);
            // Guardar en sesión que el OTP fue validado
            $_SESSION['recovery_otp_ok'] = true;
            header('Location: nueva_password.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Verificar Código</title>
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
        .subtitle{font-size:13.5px;color:#64748b;text-align:center;margin-bottom:8px;line-height:1.5;}
        .email-chip{display:inline-block;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:4px 12px;font-size:13px;font-weight:600;color:#1e293b;margin:0 auto 24px;display:block;text-align:center;}
        .otp-row{display:flex;gap:10px;justify-content:center;margin-bottom:22px;}
        .otp-row input{width:48px;height:56px;border:2px solid #e5e7eb;border-radius:12px;font-size:22px;font-weight:800;text-align:center;color:#1e293b;outline:none;transition:.2s;font-family:'Inter',sans-serif;}
        .otp-row input:focus{border-color:#6b11ff;box-shadow:0 0 0 3px rgba(107,17,255,.10);}
        .btn-main{width:100%;padding:13px;background:linear-gradient(135deg,#6b11ff,#3182fe);border:none;border-radius:11px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:.22s;box-shadow:0 6px 20px rgba(107,17,255,.30);font-family:'Inter',sans-serif;}
        .btn-main:hover{opacity:.92;transform:translateY(-2px);}
        .btn-back{display:block;text-align:center;margin-top:14px;padding:10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:600;text-decoration:none;transition:.2s;}
        .btn-back:hover{background:#f8fafc;color:#1e293b;}
        .resend{text-align:center;margin-top:14px;font-size:12.5px;color:#64748b;}
        .resend a{color:#6b11ff;font-weight:600;text-decoration:none;}
        .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:11px 16px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .footer{margin-top:24px;text-align:center;font-size:12px;color:#9ca3af;}
        /* hidden full input */
        #codigo-real{position:absolute;opacity:0;pointer-events:none;}
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap"><i class="fas fa-shield-alt"></i></div>
        <h2>Ingresa el Código</h2>
        <p class="subtitle">Te enviamos un código de 6 dígitos a:</p>
        <span class="email-chip"><?= htmlspecialchars($email) ?></span>

        <?php if ($error): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="otp-form">
            <!-- Inputs visuales separados por dígito -->
            <div class="otp-row" id="otp-boxes">
                <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]">
                <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]">
                <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]">
                <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]">
                <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]">
                <input type="text" maxlength="1" class="otp-box" inputmode="numeric" pattern="[0-9]">
            </div>
            <!-- Input real oculto que se envía -->
            <input type="hidden" name="codigo" id="codigo-real">
            <button type="submit" class="btn-main"><i class="fas fa-check-circle me-2"></i>Verificar Código</button>
        </form>

        <div class="resend">¿No recibiste el código? <a href="recuperar_password.php?role=<?= htmlspecialchars($role) ?>">Reenviar</a></div>
        <a href="login.php?role=<?= htmlspecialchars($role) ?>" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Volver al Login</a>
        <div class="footer">Super_IA &copy; 2026</div>
    </div>

<script>
const boxes = document.querySelectorAll('.otp-box');
const realInput = document.getElementById('codigo-real');

boxes.forEach((box, i) => {
    box.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
        if (this.value && i < boxes.length - 1) boxes[i + 1].focus();
        syncReal();
    });
    box.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !this.value && i > 0) {
            boxes[i - 1].focus();
        }
    });
    box.addEventListener('paste', function(e) {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
        text.split('').slice(0, 6).forEach((ch, j) => {
            if (boxes[j]) boxes[j].value = ch;
        });
        syncReal();
        const last = Math.min(text.length, 5);
        boxes[last].focus();
    });
});

function syncReal() {
    realInput.value = Array.from(boxes).map(b => b.value).join('');
}

document.getElementById('otp-form').addEventListener('submit', function(e) {
    syncReal();
    if (realInput.value.length !== 6) {
        e.preventDefault();
        alert('Ingresa los 6 dígitos del código.');
    }
});

// Auto-focus primer box
boxes[0].focus();
</script>
</body>
</html>

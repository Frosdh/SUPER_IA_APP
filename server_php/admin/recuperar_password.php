<?php
// ============================================================
// admin/recuperar_password.php — Paso 1: Ingresar email
// Genera un OTP de 6 dígitos, lo guarda en email_otp_codes
// y lo envía al correo del usuario.
// ============================================================
require_once 'db_admin.php';

$role = $_GET['role'] ?? 'admin';
if (!in_array($role, ['super_admin', 'admin', 'supervisor'])) $role = 'admin';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email     = trim($_POST['email'] ?? '');
    $postRole  = $_POST['role'] ?? $role;

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo electrónico válido.';
    } else {
        // Determinar el rol en la tabla usuario
        $rolMap = [
            'super_admin' => 'gerente_general',
            'admin'       => 'jefe_agencia',
            'supervisor'  => 'supervisor',
        ];
        $dbRol = $rolMap[$postRole] ?? 'jefe_agencia';

        // Buscar usuario (para super_admin también puede ser gerente_general)
        if ($postRole === 'admin') {
            $stmt = $pdo->prepare("SELECT id, nombre FROM usuario WHERE email = ? AND (rol = 'jefe_agencia' OR rol = 'gerente_general') AND activo = 1 LIMIT 1");
        } else {
            $stmt = $pdo->prepare("SELECT id, nombre FROM usuario WHERE email = ? AND rol = ? AND activo = 1 LIMIT 1");
        }

        if ($postRole === 'admin') {
            $stmt->execute([$email]);
        } else {
            $stmt->execute([$email, $dbRol]);
        }
        $user = $stmt->fetch();

        if (!$user) {
            // Por seguridad no revelamos si existe o no
            $success = 'Si el correo está registrado, recibirás un código en tu bandeja de entrada.';
        } else {
            // Generar OTP de 6 dígitos
            $codigo    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expira_en = date('Y-m-d H:i:s', time() + 600); // 10 minutos

            // Invalidar OTPs anteriores del mismo email
            $pdo->prepare("UPDATE email_otp_codes SET usado = 1 WHERE email = ? AND usado = 0")->execute([$email]);

            // Insertar nuevo OTP
            $pdo->prepare("INSERT INTO email_otp_codes (email, codigo, expira_en, usado, creado_en) VALUES (?, ?, ?, 0, NOW())")
                ->execute([$email, $codigo, $expira_en]);

            // Enviar email usando el helper existente
            $emailHelperPath = __DIR__ . '/../email_helper.php';
            $sent = false;
            $mailError = '';
            if (file_exists($emailHelperPath)) {
                require_once $emailHelperPath;
                $htmlBody  = buildOtpEmailHtml($codigo);
                $plainBody = buildOtpEmailText($codigo);
                list($sent, $mailError) = sendEmailMessage($email, 'Código de recuperación — Super_IA', $htmlBody, $plainBody);
            }

            if ($sent) {
                // Guardar email en sesión para el siguiente paso
                $_SESSION['recovery_email'] = $email;
                $_SESSION['recovery_role']  = $postRole;
                header('Location: verificar_otp_recovery.php');
                exit;
            } else {
                // Si el envío falla (config SMTP no lista), mostramos el código en pantalla para desarrollo
                $success = 'Si el correo está registrado, recibirás un código en tu bandeja de entrada.';
                // Guardar en sesión igualmente para que el flujo funcione
                $_SESSION['recovery_email'] = $email;
                $_SESSION['recovery_role']  = $postRole;
                // Si no hay SMTP configurado, redirigir igual
                if (!$sent && defined('SUPER_IA_DEV') && SUPER_IA_DEV) {
                    $success .= " [DEV] Código: $codigo";
                } else {
                    header('Location: verificar_otp_recovery.php');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Recuperar Contraseña</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 40%,#1e3a5f 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;overflow:hidden;position:relative;}
        body::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(107,17,255,.18) 0%,transparent 70%);top:-150px;left:-100px;}
        body::after{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(49,130,254,.15) 0%,transparent 70%);bottom:-100px;right:-80px;}
        .card{width:440px;max-width:95vw;background:#fff;border-radius:20px;padding:44px 40px;box-shadow:0 30px 80px rgba(0,0,0,.40);position:relative;z-index:1;}
        .icon-wrap{width:60px;height:60px;background:linear-gradient(135deg,#6b11ff,#3182fe);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff;margin:0 auto 20px;}
        h2{font-size:22px;font-weight:800;color:#1e293b;text-align:center;margin-bottom:6px;}
        .subtitle{font-size:13.5px;color:#64748b;text-align:center;margin-bottom:28px;line-height:1.5;}
        .inp-group{margin-bottom:18px;}
        .inp-group label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:7px;}
        .inp-wrap{position:relative;}
        .inp-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:14px;}
        .inp-wrap input{width:100%;padding:12px 14px 12px 40px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:'Inter',sans-serif;color:#1e293b;outline:none;transition:.2s;}
        .inp-wrap input:focus{border-color:#6b11ff;box-shadow:0 0 0 3px rgba(107,17,255,.10);}
        .btn-main{width:100%;padding:13px;background:linear-gradient(135deg,#6b11ff,#3182fe);border:none;border-radius:11px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:.22s;box-shadow:0 6px 20px rgba(107,17,255,.30);font-family:'Inter',sans-serif;margin-top:4px;}
        .btn-main:hover{opacity:.92;transform:translateY(-2px);}
        .btn-back{display:block;text-align:center;margin-top:14px;padding:10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:600;text-decoration:none;transition:.2s;}
        .btn-back:hover{background:#f8fafc;color:#1e293b;}
        .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:11px 16px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:10px;padding:11px 16px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}
        .footer{margin-top:24px;text-align:center;font-size:12px;color:#9ca3af;}
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap"><i class="fas fa-key"></i></div>
        <h2>Recuperar Contraseña</h2>
        <p class="subtitle">Ingresa tu correo registrado y te enviaremos un código de verificación de 6 dígitos.</p>

        <?php if ($error): ?>
            <div class="alert-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert-ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
            <div class="inp-group">
                <label>Correo Electrónico</label>
                <div class="inp-wrap">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="tu@correo.com" required autocomplete="off">
                </div>
            </div>
            <button type="submit" class="btn-main"><i class="fas fa-paper-plane me-2"></i>Enviar Código</button>
        </form>
        <a href="login.php?role=<?= htmlspecialchars($role) ?>" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Volver al Login</a>
        <div class="footer">Super_IA &copy; 2026</div>
    </div>
</body>
</html>

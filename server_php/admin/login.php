<?php
require_once 'db_admin.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}
if (isset($_SESSION['family_logged_in']) && $_SESSION['family_logged_in'] === true) {
    header('Location: mapa_familiar.php');
    exit;
}
if (isset($_SESSION['secretary_logged_in']) && $_SESSION['secretary_logged_in'] === true) {
    header('Location: panel_cooperativa.php');
    exit;
}

$role = $_GET['role'] ?? 'admin'; // 'admin', 'family', 'secretary'
$role_labels = [
    'admin' => ['title' => 'Admin Panel', 'subtitle' => 'Ingresa credenciales de administrador'],
    'family' => ['title' => 'Familia/Amigos', 'subtitle' => 'Ingresa credenciales del conductor'],
    'secretary' => ['title' => 'Panel Secretaria', 'subtitle' => 'Ingresa tu usuario de cooperativa']
];
$current_label = $role_labels[$role] ?? $role_labels['admin'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    $login_role = $_POST['role'] ?? 'admin';

    if ($login_role === 'admin') {
        // 1. Intentar como administrador (hardcoded)
        if ($user === 'admin' && $pass === 'Admin123!') {
            $_SESSION['admin_logged_in'] = true;
            header('Location: index.php');
            exit;
        } else {
            $error = 'Credenciales de administrador incorrectas.';
        }
    } elseif ($login_role === 'family') {
        // 2. Intentar como conductor (para seguimiento familiar)
        $stmt = $pdo->prepare("SELECT id, nombre, pass_hash FROM conductores WHERE (telefono = ? OR cedula = ?) AND verificado = 1 LIMIT 1");
        $stmt->execute([$user, $user]);
        $conductor = $stmt->fetch();

        if ($conductor && password_verify($pass, $conductor['pass_hash'])) {
            $_SESSION['family_logged_in'] = true;
            $_SESSION['conductor_id'] = (int)$conductor['id'];
            $_SESSION['conductor_nombre'] = $conductor['nombre'];
            header('Location: mapa_familiar.php');
            exit;
        } else {
            $error = 'Datos de conductor no encontrados o contraseña incorrecta.';
        }
    } elseif ($login_role === 'secretary') {
        // 3. Intentar como secretaria
        $stmt = $pdo->prepare("SELECT id, usuario, pass_hash, cooperativa_id, nombre, verificado FROM secretarias WHERE usuario = ? LIMIT 1");
        $stmt->execute([$user]);
        $sec = $stmt->fetch();

        if ($sec && password_verify($pass, $sec['pass_hash'])) {
            if (!isset($sec['verificado']) || (int)$sec['verificado'] === 1) {
                $_SESSION['secretary_logged_in'] = true;
                $_SESSION['secretary_id'] = (int)$sec['id'];
                $_SESSION['secretary_name'] = $sec['nombre'];
                $_SESSION['cooperativa_id'] = (int)$sec['cooperativa_id'];
                header('Location: panel_cooperativa.php');
                exit;
            } elseif ((int)$sec['verificado'] === 0) {
                $error = 'Tu cuenta ha sido creada y está pendiente de revisión por el administrador.';
            } else {
                $_SESSION['resubir_sec_id'] = (int)$sec['id'];
                header('Location: resubir_credencial.php');
                exit;
            }
        } else {
            $error = 'Usuario de secretaria incorrecto o contraseña inválida.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Iniciar Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{box-sizing:border-box;}
        body{font-family:'Inter','Segoe UI',sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 40%,#1e3a5f 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;overflow:hidden;position:relative;}
        body::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:radial-gradient(circle,rgba(107,17,255,.18) 0%,transparent 70%);top:-150px;left:-100px;}
        body::after{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:radial-gradient(circle,rgba(49,130,254,.15) 0%,transparent 70%);bottom:-100px;right:-80px;}
        .login-wrapper{display:flex;width:860px;max-width:95vw;min-height:520px;border-radius:24px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.45);position:relative;z-index:1;}
        .login-left{flex:1;background:linear-gradient(160deg,rgba(107,17,255,.55),rgba(49,130,254,.45));backdrop-filter:blur(20px);padding:50px 40px;display:flex;flex-direction:column;justify-content:center;color:#fff;border-right:1px solid rgba(255,255,255,.1);}
        .login-left .brand-icon{width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:28px;border:1px solid rgba(255,255,255,.2);}
        .login-left h1{font-size:28px;font-weight:800;margin-bottom:10px;}
        .login-left p{font-size:14px;opacity:.75;line-height:1.7;margin-bottom:32px;}
        .feature{display:flex;align-items:center;gap:12px;font-size:13.5px;margin-bottom:14px;opacity:.85;}
        .feature .fi{width:32px;height:32px;background:rgba(255,255,255,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
        .login-right{flex:1;background:#fff;padding:50px 44px;display:flex;flex-direction:column;justify-content:center;}
        .form-title{font-size:22px;font-weight:800;color:#1e293b;margin-bottom:6px;}
        .form-subtitle{font-size:13.5px;color:#64748b;margin-bottom:32px;}
        .inp-group{margin-bottom:20px;}
        .inp-group label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:7px;}
        .inp-wrap{position:relative;}
        .inp-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:14px;}
        .inp-wrap input{width:100%;padding:12px 14px 12px 40px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:'Inter',sans-serif;color:#1e293b;transition:.2s;outline:none;}
        .inp-wrap input:focus{border-color:#6b11ff;box-shadow:0 0 0 3px rgba(107,17,255,.1);}
        .btn-login{width:100%;padding:13px;background:linear-gradient(135deg,#6b11ff,#3182fe);border:none;border-radius:11px;color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:.22s;box-shadow:0 6px 20px rgba(107,17,255,.35);font-family:'Inter',sans-serif;margin-top:8px;}
        .btn-login:hover{opacity:.92;transform:translateY(-2px);box-shadow:0 10px 28px rgba(107,17,255,.45);}
        .btn-back{width:100%;padding:10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:11px;color:#64748b;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;font-family:'Inter',sans-serif;margin-top:12px;text-decoration:none;display:inline-block;text-align:center;}
        .btn-back:hover{background:#f8fafc;border-color:#d1d5db;color:#1e293b;}
        .error-msg{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:11px 16px;font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:20px;}
        .login-footer{margin-top:28px;text-align:center;font-size:12px;color:#9ca3af;}
        @media(max-width:640px){.login-left{display:none;}.login-right{padding:36px 28px;}}
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-left">
            <div class="brand-icon"><i class="fas fa-map-marked-alt"></i></div>
            <h1>GeoMove Admin</h1>
            <p>Panel de administración centralizado para gestionar conductores, viajes y usuarios de la plataforma Fuber.</p>
            <div class="feature"><div class="fi"><i class="fas fa-chart-line"></i></div><span>Dashboard con estadísticas en tiempo real</span></div>
            <div class="feature"><div class="fi"><i class="fas fa-map-marked-alt"></i></div><span>Mapa en vivo de conductores activos</span></div>
            <div class="feature"><div class="fi"><i class="fas fa-shield-alt"></i></div><span>Gestión de documentos y verificaciones</span></div>
        </div>
        <div class="login-right">
            <div class="form-title"><?= htmlspecialchars($current_label['title']) ?></div>
            <div class="form-subtitle"><?= htmlspecialchars($current_label['subtitle']) ?></div>
            <?php if ($error): ?>
                <div class="error-msg"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">
                <div class="inp-group">
                    <label>Usuario</label>
                    <div class="inp-wrap"><i class="fas fa-user"></i><input type="text" name="username" placeholder="Ingresa tu usuario" required autocomplete="off"></div>
                </div>
                <div class="inp-group">
                    <label>Contraseña</label>
                    <div class="inp-wrap"><i class="fas fa-lock"></i><input type="password" name="password" placeholder="••••••••" required></div>
                </div>
                <button type="submit" class="btn-login"><i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión</button>
                
                <?php if ($role === 'secretary'): ?>
                    <a href="registro_secretaria.php" class="btn-back mt-3" style="background: rgba(107,17,255,0.05); border-color: rgba(107,17,255,0.2); color: var(--primary-color);">
                        <i class="fas fa-user-plus me-2"></i>Crear Cuenta de Secretaria
                    </a>
                <?php endif; ?>

                <a href="login_selector.php" class="btn-back"><i class="fas fa-arrow-left me-2"></i>Cambiar de Rol</a>
            </form>
            <div class="login-footer">GeoMove App · Fuber Platform &copy; 2026</div>
        </div>
    </div>
</body>
</html>

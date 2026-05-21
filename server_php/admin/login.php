<?php
require_once 'db_admin.php';

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

$role = $_GET['role'] ?? 'admin'; // 'super_admin', 'admin', 'supervisor', 'asesor'
$role_labels = [
    'super_admin' => ['title' => 'Super Administrador', 'subtitle' => 'Ingresa credenciales de super administrador'],
    'admin'       => ['title' => 'Panel Gerente',        'subtitle' => 'Ingresa credenciales de gerente'],
    'supervisor'  => ['title' => 'Panel Supervisor',     'subtitle' => 'Ingresa credenciales de supervisor'],
    'asesor'      => ['title' => 'Panel Asesor',         'subtitle' => 'Ingresa credenciales de asesor'],
];
if (!array_key_exists($role, $role_labels)) $role = 'admin';
$current_label = $role_labels[$role];

// Paleta de colores por rol
$role_theme = [
    'super_admin' => [
        'accent'      => '#FFC800',
        'accent_dk'   => '#E6A800',
        'accent_rgb'  => '255,200,0',
        'left_grad'   => 'linear-gradient(155deg,#1A1400 0%,#2A2000 50%,#3A2E00 100%)',
        'icon'        => 'fa-crown',
        'label'       => 'Super Admin',
        'badge_bg'    => 'rgba(255,200,0,.12)',
        'badge_border'=> 'rgba(255,200,0,.35)',
        'badge_color' => '#B8860B',
        'feat' => [
            ['icon'=>'fa-globe','style'=>'fi-y','text'=>'Acceso total al sistema global'],
            ['icon'=>'fa-users-cog','style'=>'fi-y','text'=>'Gestión de todos los usuarios'],
            ['icon'=>'fa-shield-halved','style'=>'fi-y','text'=>'Supervisión y seguridad completa'],
        ],
    ],
    'admin' => [
        'accent'      => '#60A5FA',
        'accent_dk'   => '#3B82F6',
        'accent_rgb'  => '96,165,250',
        'left_grad'   => 'linear-gradient(155deg,#0B1929 0%,#0F2440 60%,#163057 100%)',
        'icon'        => 'fa-user-shield',
        'label'       => 'Gerente',
        'badge_bg'    => 'rgba(96,165,250,.12)',
        'badge_border'=> 'rgba(96,165,250,.35)',
        'badge_color' => '#3B82F6',
        'feat' => [
            ['icon'=>'fa-chart-line','style'=>'fi-b','text'=>'Dashboard con métricas de agencia'],
            ['icon'=>'fa-users-gear','style'=>'fi-b','text'=>'Gestión de supervisores y asesores'],
            ['icon'=>'fa-file-contract','style'=>'fi-b','text'=>'Reportes y configuración del sistema'],
        ],
    ],
    'supervisor' => [
        'accent'      => '#94A3B8',
        'accent_dk'   => '#64748B',
        'accent_rgb'  => '148,163,184',
        'left_grad'   => 'linear-gradient(155deg,#0F1520 0%,#1A2535 60%,#212F42 100%)',
        'icon'        => 'fa-users-gear',
        'label'       => 'Supervisor',
        'badge_bg'    => 'rgba(148,163,184,.12)',
        'badge_border'=> 'rgba(148,163,184,.35)',
        'badge_color' => '#64748B',
        'feat' => [
            ['icon'=>'fa-location-dot','style'=>'fi-s','text'=>'Mapa en vivo de asesores en campo'],
            ['icon'=>'fa-clipboard-list','style'=>'fi-s','text'=>'Seguimiento de encuestas y visitas'],
            ['icon'=>'fa-building-columns','style'=>'fi-s','text'=>'Monitoreo de operaciones de crédito'],
        ],
    ],
    'asesor' => [
        'accent'      => '#4ADE80',
        'accent_dk'   => '#16A34A',
        'accent_rgb'  => '74,222,128',
        'left_grad'   => 'linear-gradient(155deg,#071510 0%,#0D2218 60%,#102B1E 100%)',
        'icon'        => 'fa-user-tie',
        'label'       => 'Asesor',
        'badge_bg'    => 'rgba(74,222,128,.10)',
        'badge_border'=> 'rgba(74,222,128,.35)',
        'badge_color' => '#16A34A',
        'feat' => [
            ['icon'=>'fa-map-location-dot','style'=>'fi-g','text'=>'Registro de visitas y prospectos'],
            ['icon'=>'fa-clipboard-check','style'=>'fi-g','text'=>'Encuestas comerciales desde la app'],
            ['icon'=>'fa-bullseye','style'=>'fi-g','text'=>'Metas diarias y seguimiento personal'],
        ],
    ],
];
$theme = $role_theme[$role];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $pass = $_POST['password'] ?? '';
    $login_role = $_POST['role'] ?? 'admin';

    if ($login_role === 'super_admin') {
        // Super Administrador
        $stmt = $pdo->prepare("SELECT id, nombre, email, password_hash, rol, activo, estado_aprobacion 
                               FROM usuario
                               WHERE email = ? AND rol = 'gerente_general' AND activo = 1 AND estado_aprobacion = 'aprobado' LIMIT 1");
        $stmt->execute([$email]);
        $super_admin = $stmt->fetch();
        
        if ($super_admin && password_verify($pass, $super_admin['password_hash'])) {
            $_SESSION['super_admin_logged_in'] = true;
            $_SESSION['super_admin_id'] = $super_admin['id'];
            $_SESSION['super_admin_email'] = $super_admin['email'];
            $_SESSION['super_admin_nombre'] = $super_admin['nombre'];
            $_SESSION['super_admin_rol'] = 'gerente_general';
            session_write_close();
            header('Location: super_admin_index.php');
            exit;
        } else {
            $error = 'Credenciales de super administrador incorrectas.';
        }
    } elseif ($login_role === 'admin') {
        // Administrador (jefe_agencia)
        $stmt = $pdo->prepare("SELECT id, nombre, email, password_hash, rol, activo, estado_aprobacion 
                               FROM usuario
                               WHERE email = ? AND (rol = 'jefe_agencia' OR rol = 'gerente_general') AND activo = 1 AND estado_aprobacion = 'aprobado' LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($pass, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['admin_nombre'] = $admin['nombre'];
            $_SESSION['admin_rol'] = $admin['rol'];
            session_write_close();
            header('Location: index.php');
            exit;
        } else {
            $error = 'Credenciales de administrador incorrectas.';
        }
    } elseif ($login_role === 'supervisor') {
        // Supervisor
        $stmt = $pdo->prepare("SELECT id, nombre, email, password_hash, rol, activo, estado_aprobacion 
                               FROM usuario
                               WHERE email = ? AND rol = 'supervisor' AND activo = 1 AND estado_aprobacion = 'aprobado' LIMIT 1");
        $stmt->execute([$email]);
        $supervisor = $stmt->fetch();
        
        if ($supervisor && password_verify($pass, $supervisor['password_hash'])) {
            $_SESSION['supervisor_logged_in'] = true;
            $_SESSION['supervisor_id'] = $supervisor['id'];
            $_SESSION['supervisor_email'] = $supervisor['email'];
            $_SESSION['supervisor_nombre'] = $supervisor['nombre'];
            $_SESSION['supervisor_rol'] = 'supervisor';
            session_write_close();
            header('Location: supervisor_index.php');
            exit;
        } else {
            $error = 'Credenciales de supervisor incorrectas.';
        }
    } elseif ($login_role === 'asesor') {
        // Asesor
        $stmt = $pdo->prepare("SELECT id, nombre, email, password_hash, rol, activo, estado_aprobacion
                               FROM usuario
                               WHERE email = ? AND rol = 'asesor' AND activo = 1 AND estado_aprobacion = 'aprobado' LIMIT 1");
        $stmt->execute([$email]);
        $asesor = $stmt->fetch();

        if ($asesor && password_verify($pass, $asesor['password_hash'])) {
            $_SESSION['asesor_logged_in'] = true;
            // Importante: varios archivos del panel esperan que 'asesor_id' sea usuario.id
            $_SESSION['asesor_id'] = $asesor['id'];
            $_SESSION['asesor_email'] = $asesor['email'];
            $_SESSION['asesor_nombre'] = $asesor['nombre'];
            $_SESSION['asesor_rol'] = 'asesor';
            session_write_close();
            header('Location: asesor_index.php');
            exit;
        } else {
            $error = 'Credenciales de asesor incorrectas.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUPER_IA — <?= htmlspecialchars($current_label['title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* Base reset and responsive root */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: clamp(14px, 1.4vw, 18px); }

        :root {
            --accent:     <?= $theme['accent'] ?>;
            --accent-dk:  <?= $theme['accent_dk'] ?>;
            --accent-rgb: <?= $theme['accent_rgb'] ?>;
            --left-grad:  <?= $theme['left_grad'] ?>;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #080E1A;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden; position: relative;
        }

        /* Orbes de fondo: sizes in vw/clamp so they scale with zoom/viewport */
        .orb { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; }
        .orb1 {
            width: clamp(260px, 30vw, 580px); height: clamp(260px, 30vw, 580px);
            top: calc(-6vw); right: calc(-6vw);
            background: radial-gradient(circle, rgba(var(--accent-rgb),.10) 0%, transparent 65%);
        }
        .orb2 {
            width: clamp(220px, 24vw, 480px); height: clamp(220px, 24vw, 480px);
            bottom: calc(-6vw); left: calc(-6vw);
            background: radial-gradient(circle, rgba(var(--accent-rgb),.06) 0%, transparent 65%);
        }
        .grid-bg {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image:
                linear-gradient(rgba(var(--accent-rgb),.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(var(--accent-rgb),.025) 1px, transparent 1px);
            background-size: clamp(28px, 3.2vw, 60px) clamp(28px, 3.2vw, 60px);
        }

        /* Card principal: use clamp so it remains proportionate when zooming */
        .login-card {
            display: flex; width: clamp(320px, 94%, 940px); max-width: 96vw;
            min-height: clamp(480px, 60vh, 720px);
            border-radius: 1.75rem; overflow: hidden;
            box-shadow: 0 36px 90px rgba(0,0,0,.6);
            position: relative; z-index: 1;
            border: 1px solid rgba(var(--accent-rgb),.18);
        }

        /* Left panel (hidden on small screens) */
        .left-panel {
            flex: 1; background: var(--left-grad);
            padding: clamp(18px, 3.2vw, 54px) clamp(14px, 2.6vw, 46px);
            display: flex; flex-direction: column; justify-content: center;
            color: #fff; position: relative; overflow: hidden;
        }
        .left-panel::before, .left-panel::after { content: ''; position: absolute; border-radius: 50%; }
        .left-panel::before {
            top: -6vw; right: -6vw; width: clamp(120px, 14vw, 300px); height: clamp(120px, 14vw, 300px);
            background: radial-gradient(circle, rgba(var(--accent-rgb),.14) 0%, transparent 70%);
        }
        .left-panel::after {
            bottom: -5vw; left: -4vw; width: clamp(100px, 11vw, 220px); height: clamp(100px, 11vw, 220px);
            background: radial-gradient(circle, rgba(var(--accent-rgb),.08) 0%, transparent 70%);
        }

        .brand-logo { display: flex; align-items: center; gap: .8rem; margin-bottom: 1.6rem; position: relative; z-index: 1; }
        .brand-icon-box {
            width: 3.5rem; height: 3.5rem; border-radius: .9rem;
            background: rgba(var(--accent-rgb),.2); border: 1px solid rgba(var(--accent-rgb),.35);
            display: flex; align-items: center; justify-content: center; font-size: 1.25rem;
            box-shadow: 0 4px 20px rgba(var(--accent-rgb),.2);
        }
        .brand-name { font-size: 1.25rem; font-weight: 900; letter-spacing: -.5px; }
        .brand-name span { color: var(--accent); }

        .role-title-left { font-size: clamp(1.05rem, 2.2vw, 1.6rem); font-weight: 900; margin-bottom: .5rem; z-index:1 }
        .role-title-left span { color: var(--accent); }
        .role-desc-left { font-size: clamp(.85rem, 1.6vw, 1rem); color: rgba(255,255,255,.55); line-height: 1.75; margin-bottom: 1.6rem; }

        .feat-list { position: relative; z-index: 1; }
        .feat-item { display:flex; align-items:center; gap:.65rem; font-size: .9rem; color: rgba(255,255,255,.75); margin-bottom:.8rem }
        .feat-ico { width: 2.1rem; height:2.1rem; border-radius:.55rem; display:flex; align-items:center; justify-content:center; font-size:.8rem; background: rgba(var(--accent-rgb),.18); color:var(--accent); border:1px solid rgba(var(--accent-rgb),.25); }

        /* Right panel */
        .right-panel { flex:1; background:#F8FAFC; padding: clamp(18px,3.2vw,54px) clamp(14px,2.6vw,48px); display:flex; flex-direction:column; justify-content:center }

        .role-badge { display:inline-flex; align-items:center; gap:.5rem; font-size:.8rem; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; padding:.35rem .8rem; border-radius:1.25rem; background: <?= $theme['badge_bg'] ?>; border:1px solid <?= $theme['badge_border'] ?>; color: <?= $theme['badge_color'] ?>; margin-bottom:1.25rem }

        .form-title { font-size: clamp(1.25rem, 2.4vw, 1.6rem); font-weight:900; color:#0D1929; margin-bottom:.25rem }
        .form-subtitle { font-size: .95rem; color:#64748B; margin-bottom:1.6rem; line-height:1.5 }

        .inp-group { margin-bottom:1rem }
        .inp-group label { display:block; font-size:.75rem; font-weight:700; color:#1E3A5F; margin-bottom:.4rem; text-transform:uppercase }
        .inp-wrap { position:relative }
        .inp-wrap .ico { position:absolute; left:.85rem; top:50%; transform:translateY(-50%); color:#94A3B8; font-size:.85rem; pointer-events:none }
        .inp-wrap input { width:100%; padding: .65rem 2.6rem; border:1.5px solid #E2E8F0; border-radius:.7rem; font-size:.95rem; color:#0D1929; background:#fff; transition:border-color .2s, box-shadow .2s; outline:none }
        .inp-wrap input:focus { border-color:var(--accent); box-shadow: 0 0 0 .3rem rgba(var(--accent-rgb),.15) }
        .toggle-pass { position:absolute; right:.8rem; top:50%; transform:translateY(-50%); background:none; border:none; color:#94A3B8; cursor:pointer; padding:.25rem; font-size:.9rem }
        .toggle-pass:hover { color: var(--accent) }

        .btn-login { width:100%; padding:.85rem; background: linear-gradient(135deg,var(--accent),var(--accent-dk)); border:none; border-radius:.75rem; color:#fff; font-size:1rem; font-weight:800; cursor:pointer; transition:transform .2s, box-shadow .2s; box-shadow:0 6px 22px rgba(var(--accent-rgb),.38); display:flex; align-items:center; justify-content:center; gap:.5rem }
        <?php if ($role === 'super_admin' || $role === 'asesor'): ?>
        .btn-login { color: #0D1929; }
        <?php else: ?>
        .btn-login { color: #fff; }
        <?php endif; ?>
        .btn-login:hover { transform: translateY(-.125rem); box-shadow: 0 10px 30px rgba(var(--accent-rgb),.5) }

        .forgot-link { display:block; text-align:right; margin-top:.6rem; margin-bottom:.4rem; font-size:.85rem; color:#475569; font-weight:600; text-decoration:none }
        .forgot-link:hover { color: var(--accent-dk) }

        .btn-sec { width:100%; padding:.6rem; background:transparent; border:1.5px solid #E2E8F0; border-radius:.7rem; color:#64748B; font-size:.95rem; font-weight:600; cursor:pointer; transition:.2s; display:flex; align-items:center; justify-content:center; gap:.5rem; margin-top:.6rem }
        .btn-sec:hover { background:#F1F5F9; border-color:#CBD5E1; color:#1E293B }

        .error-msg { background:#FEF2F2; border:1px solid #FECACA; color:#DC2626; border-radius:.6rem; padding:.6rem .9rem; font-size:.95rem; display:flex; align-items:center; gap:.5rem; margin-bottom:1.1rem }

        .login-footer { margin-top:1.2rem; text-align:center; font-size:.85rem; color:#CBD5E1 }

        /* Switch to single column on small screens */
        @media (max-width: 640px) {
            .left-panel { display: none }
            .right-panel { padding: clamp(14px,4.2vw,36px) clamp(10px,3.2vw,28px) }
            .login-card { flex-direction: column; width: 94vw; min-height: auto }
        }
    </style>
</head>
<body>
    <div class="orb orb1"></div>
    <div class="orb orb2"></div>
    <div class="grid-bg"></div>

    <div class="login-card">

        <!-- ── PANEL IZQUIERDO (color por rol) ── -->
        <div class="left-panel">
            <div class="brand-logo">
                <div class="brand-icon-box">🛰️</div>
                <span class="brand-name">SUPER<span>_IA</span></span>
            </div>

            <div class="role-title-left">
                <span><?= htmlspecialchars($theme['label']) ?></span>
            </div>
            <p class="role-desc-left">
                Plataforma de monitoreo y gestión comercial inteligente para
                <?= strtolower(htmlspecialchars($theme['label'])) ?>es y su equipo.
            </p>

            <div class="feat-list">
                <?php foreach ($theme['feat'] as $f): ?>
                <div class="feat-item">
                    <div class="feat-ico"><i class="fas <?= $f['icon'] ?>"></i></div>
                    <span><?= htmlspecialchars($f['text']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── PANEL DERECHO ── -->
        <div class="right-panel">

            <div class="role-badge">
                <i class="fas <?= $theme['icon'] ?>"></i>
                <?= htmlspecialchars($theme['label']) ?>
            </div>

            <div class="form-title">Iniciar Sesión</div>
            <div class="form-subtitle"><?= htmlspecialchars($current_label['subtitle']) ?></div>

            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="role" value="<?= htmlspecialchars($role) ?>">

                <div class="inp-group">
                    <label>Correo Electrónico</label>
                    <div class="inp-wrap">
                        <i class="fas fa-envelope ico"></i>
                        <input type="email" name="email" placeholder="tu@correo.com" required autocomplete="off">
                    </div>
                </div>

                <div class="inp-group">
                    <label>Contraseña</label>
                    <div class="inp-wrap">
                        <i class="fas fa-lock ico"></i>
                        <input type="password" id="password-input" name="password" placeholder="••••••••" required>
                        <button type="button" class="toggle-pass" onclick="togglePass()">
                            <i class="fas fa-eye" id="toggle-icon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login">
                    <i class="fas fa-right-to-bracket"></i> Iniciar Sesión
                </button>

                <a href="recuperar_password.php?role=<?= htmlspecialchars($role) ?>" class="forgot-link">
                    <i class="fas fa-key"></i> ¿Olvidaste tu contraseña?
                </a>

                <?php if ($role === 'admin'): ?>
                <a href="registro_admin.php" class="btn-sec">
                    <i class="fas fa-user-plus"></i> Crear Cuenta de Gerente
                </a>
                <?php elseif ($role === 'supervisor'): ?>
                <a href="registro_supervisor.php" class="btn-sec">
                    <i class="fas fa-user-plus"></i> Crear Cuenta de Supervisor
                </a>
                <?php elseif ($role === 'asesor'): ?>
                <a href="registro_asesor.php" class="btn-sec">
                    <i class="fas fa-user-plus"></i> Crear Cuenta de Asesor
                </a>
                <?php endif; ?>

                <a href="login_selector.php" class="btn-sec">
                    <i class="fas fa-arrow-left"></i> Cambiar de Rol
                </a>
            </form>

            <div class="login-footer">SUPER_IA &copy; 2026 · Plataforma de Gestión Comercial</div>
        </div>
    </div>

    <script>
        function togglePass() {
            const inp  = document.getElementById('password-input');
            const icon = document.getElementById('toggle-icon');
            inp.type = inp.type === 'password' ? 'text' : 'password';
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        }
    </script>
</body>
</html>

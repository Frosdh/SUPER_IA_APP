<?php
session_start();
require_once 'db_config.php';

$error = '';
$success = '';
$selected_role = isset($_POST['role']) ? $_POST['role'] : '';
$email = isset($_POST['email']) ? $_POST['email'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    
    if (empty($email) || empty($password) || empty($role)) {
        $error = 'Completa todos los campos.';
    } else {
        try {
            $role_mapping = [
                'super_admin' => ['tabla' => 'super_admin', 'col_email' => 'email_super', 'col_pass' => 'password_super'],
                'admin' => ['tabla' => 'admin', 'col_email' => 'email_admin', 'col_pass' => 'password_admin'],
                'supervisor' => ['tabla' => 'supervisor', 'col_email' => 'email_supervisor', 'col_pass' => 'password_supervisor'],
                'asesor' => ['tabla' => 'usuarios', 'col_email' => 'email', 'col_pass' => 'password_hash']
            ];
            if (!isset($role_mapping[$role])) { $error = 'Rol inválido.'; }
            else {
                $cfg = $role_mapping[$role];
                $query = "SELECT * FROM {$cfg['tabla']} WHERE {$cfg['col_email']} = ? LIMIT 1";
                $stmt = $conn->prepare($query);
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    $pass_field = $cfg['col_pass'];
                    if ($role === 'asesor') {
                        $ok = password_verify($password, $user[$pass_field]);
                    } else {
                        $ok = ($user[$pass_field] === $password) || (hash('sha256', $password) === $user[$pass_field]) || password_verify($password, $user[$pass_field]);
                    }
                    if ($ok) {
                        $_SESSION['logged_in'] = true;
                        $_SESSION['user_id'] = $user['id'] ?? $user['id_usuario'] ?? null;
                        $_SESSION['role'] = $role;
                        $_SESSION['email'] = $email;
                        $_SESSION['nombre'] = $user['nombre'] ?? ($user['nombre_super'] ?? ($user['nombre_admin'] ?? ($user['nombre_supervisor'] ?? 'Usuario')));
                        $success = 'Login exitoso — redirigiendo...';
                        header('Refresh:1; url=dashboard.php');
                        exit;
                    } else { $error = 'Email o contraseña incorrectos.'; }
                } else { $error = 'Email no encontrado para este rol.'; }
            }
        } catch (Exception $e) {
            $error = 'Error del servidor.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>SUPER_IA — Iniciar Sesión</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --ink:#0A0E1A;--surface:#181E2E;--surface2:#1F2840;--muted:#9AAABF;--text:#F0F4FF;
      --accent:#3B82F6;--accent2:#60A5FA;--gold:#F59E0B;--glass:rgba(255,255,255,0.04);
    }
    html,body{height:100%}
    body{font-family:'DM Sans',sans-serif;background:linear-gradient(180deg,#070714 0%,#0b1220 60%);color:var(--text);-webkit-font-smoothing:antialiased}
    .aurora{position:fixed;inset:0;z-index:0;pointer-events:none}
    .aurora-blob{position:absolute;border-radius:50%;filter:blur(120px);opacity:.12;animation:drift 14s ease-in-out infinite alternate}
    .ab1{width:700px;height:500px;top:-150px;right:-100px;background:var(--accent);}
    .ab2{width:500px;height:400px;bottom:-100px;left:-80px;background:var(--gold);}
    @keyframes drift{from{transform:translate(0,0) scale(1)}to{transform:translate(40px,30px) scale(1.1)}}
    .page{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px}
    .card{width:100%;max-width:920px;background:linear-gradient(180deg,var(--surface),var(--surface2));border-radius:18px;box-shadow:0 10px 40px rgba(2,6,23,.6);display:grid;grid-template-columns:1fr 420px;overflow:hidden;border:1px solid rgba(255,255,255,0.03)}
    .left{padding:44px 48px}
    .logo{display:flex;align-items:center;gap:14px;margin-bottom:18px}
    .logo-mark{width:50px;height:50px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),#1D4ED8);box-shadow:0 8px 30px rgba(59,130,246,.12)}
    .logo-name{font-family:'Syne',sans-serif;font-weight:800;font-size:20px}
    .h1{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;margin:12px 0 18px}
    .lead{color:var(--muted);line-height:1.6;margin-bottom:26px}
    .cta-row{display:flex;gap:12px}
    .btn-main{background:linear-gradient(135deg,var(--accent),#1D4ED8);color:#fff;padding:12px 18px;border-radius:12px;text-decoration:none;border:none}
    .stats{display:flex;gap:8px;margin-top:22px}
    .s{background:var(--glass);padding:10px 12px;border-radius:10px;color:var(--muted);font-size:13px}
    .right{background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));padding:40px;display:flex;flex-direction:column;align-items:stretch;justify-content:center;gap:16px}
    .form-title{font-size:18px;font-weight:700;margin-bottom:2px}
    .form-sub{font-size:13px;color:var(--muted);margin-bottom:14px}
    .form{display:flex;flex-direction:column;gap:12px}
    .input{background:transparent;border:1px solid rgba(255,255,255,0.06);padding:12px 14px;border-radius:10px;color:var(--text)}
    .role-row{display:flex;gap:8px}
    .role-option{flex:1;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,0.03);cursor:pointer;text-align:center}
    .role-option input{display:none}
    .role-option.active{background:linear-gradient(90deg,rgba(59,130,246,.12),rgba(245,158,11,.06));border-color:rgba(59,130,246,.2)}
    .submit{padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--accent),#1D4ED8);color:#fff;font-weight:700;cursor:pointer}
    .back{padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--muted);text-align:center;text-decoration:none}
    .msg-error{background:rgba(244,63,94,.08);color:#ffb3b3;padding:10px;border-radius:8px}
    .msg-success{background:rgba(16,185,129,.08);color:#bff0d9;padding:10px;border-radius:8px}
    @media(max-width:900px){.card{grid-template-columns:1fr;max-width:720px}.left{padding:28px}.right{padding:28px}}
  </style>
</head>
<body>
  <div class="aurora"><div class="aurora-blob ab1"></div><div class="aurora-blob ab2"></div></div>
  <div class="page">
    <div class="card">
      <div class="left">
        <div class="logo"><div class="logo-mark">🛰️</div><div class="logo-name">SUPER_<em style="color:var(--accent2);font-style:normal">IA</em></div></div>
        <div class="h1">Monitoreo y Gestión<br/><strong style="background:linear-gradient(90deg,var(--accent2),var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent">Inteligencia Artificial</strong></div>
        <div class="lead">SUPER_IA centraliza rastreo GPS, gestión crediticia y encuestas comerciales. Inicia sesión con tu rol para acceder al panel adecuado.</div>
        <div class="cta-row"><a class="btn-main" href="./admin/login_selector.php"><i class="fa-solid fa-users-gear"></i>&nbsp; Seleccionar Rol</a>
        <a class="back" href="./bienvenida.php"><i class="fa-solid fa-arrow-left"></i>&nbsp; Volver</a></div>
        <div class="stats" style="margin-top:28px"><div class="s">Rastreo real-time</div><div class="s">Alertas 24/7</div><div class="s">Módulos integrados</div></div>
      </div>
      <div class="right">
        <div>
          <div class="form-title">Acceso al Sistema</div>
          <div class="form-sub">Selecciona tu rol e ingresa tus credenciales</div>
          <?php if(!empty($error)):?><div class="msg-error"><?php echo htmlspecialchars($error);?></div><?php endif;?>
          <?php if(!empty($success)):?><div class="msg-success"><?php echo htmlspecialchars($success);?></div><?php endif;?>
        </div>
        <form class="form" method="POST" action="">
          <div class="role-row">
            <label class="role-option <?php echo ($selected_role==='super_admin')? 'active':'';?>">
              <input type="radio" name="role" value="super_admin" <?php echo ($selected_role==='super_admin')? 'checked':'';?> />
              Súper Admin
            </label>
            <label class="role-option <?php echo ($selected_role==='admin')? 'active':'';?>">
              <input type="radio" name="role" value="admin" <?php echo ($selected_role==='admin')? 'checked':'';?> />
              Admin
            </label>
          </div>
          <div class="role-row" style="margin-top:6px">
            <label class="role-option <?php echo ($selected_role==='supervisor')? 'active':'';?>">
              <input type="radio" name="role" value="supervisor" <?php echo ($selected_role==='supervisor')? 'checked':'';?> />
              Supervisor
            </label>
            <label class="role-option <?php echo ($selected_role==='asesor')? 'active':'';?>">
              <input type="radio" name="role" value="asesor" <?php echo ($selected_role==='asesor')? 'checked':'';?> />
              Asesor
            </label>
          </div>
          <input class="input" type="email" name="email" placeholder="correo@dominio.com" value="<?php echo htmlspecialchars($email);?>" required />
          <input class="input" type="password" name="password" placeholder="Contraseña" required />
          <button class="submit" type="submit" name="login">Iniciar sesión</button>
          <a class="back" href="./bienvenida.php">Regresar a Bienvenida</a>
        </form>
        <div style="font-size:13px;color:var(--muted);margin-top:8px">Usuarios de prueba: admin@... / admin123 · asesor@... / asesor123</div>
      </div>
    </div>
  </div>
  <script>
    // role option toggle
    document.querySelectorAll('.role-option').forEach(label => {
      label.addEventListener('click', () => {
        document.querySelectorAll('.role-option').forEach(l=>l.classList.remove('active'));
        label.classList.add('active');
        const inp = label.querySelector('input'); if(inp) inp.checked = true;
      });
    });
  </script>
</body>
</html>

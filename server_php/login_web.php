<?php
session_start();
require_once 'db_config.php';

// Variables
$error = '';
$success = '';
$selected_role = isset($_POST['role']) ? $_POST['role'] : '';
$email = isset($_POST['email']) ? $_POST['email'] : '';

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    
    if (empty($email) || empty($password) || empty($role)) {
        $error = '❌ Completa todos los campos';
    } else {
        try {
            // Mapear rol a tabla/columna
            $role_mapping = [
                'super_admin' => ['tabla' => 'super_admin', 'col_email' => 'email_super', 'col_pass' => 'password_super'],
                'admin' => ['tabla' => 'admin', 'col_email' => 'email_admin', 'col_pass' => 'password_admin'],
                'supervisor' => ['tabla' => 'supervisor', 'col_email' => 'email_supervisor', 'col_pass' => 'password_supervisor'],
                'asesor' => ['tabla' => 'usuarios', 'col_email' => 'email', 'col_pass' => 'password_hash']
            ];
            
            if (!isset($role_mapping[$role])) {
                $error = '❌ Rol inválido';
            } else {
                $config = $role_mapping[$role];
                $query = "SELECT * FROM {$config['tabla']} WHERE {$config['col_email']} = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $pass_field = $config['col_pass'];
                    
                    // Verificar contraseña
                    if ($role === 'asesor') {
                        // Para asesor usamos password_verify
                        $password_match = password_verify($password, $user[$pass_field]);
                    } else {
                        // Para otros roles intentamos ambas formas
                        $password_match = ($user[$pass_field] === $password) || 
                                        (hash('sha256', $password) === $user[$pass_field]) ||
                                        (password_verify($password, $user[$pass_field]));
                    }
                    
                    if ($password_match) {
                        $_SESSION['logged_in'] = true;
                        $_SESSION['user_id'] = $user['id'] ?? $user['id_usuario'] ?? null;
                        $_SESSION['role'] = $role;
                        $_SESSION['email'] = $email;
                        $_SESSION['nombre'] = $user['nombre'] ?? $user['nombre_super'] ?? $user['nombre_admin'] ?? $user['nombre_supervisor'] ?? 'Usuario';
                        
                        $success = '✅ Login exitoso. Redirigiendo...';
                        header('refresh: 2; url=dashboard.php');
                    } else {
                        $error = '❌ Email o contraseña incorrectos';
                    }
                } else {
                    $error = '❌ Email no encontrado en este rol';
                }
            }
        } catch (Exception $e) {
            $error = '❌ Error en el servidor: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUPER_IA - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #FFC800 0%, #FFD700 50%, #FFC800 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 61, 122, 0.3);
            max-width: 450px;
            width: 100%;
            padding: 50px 40px;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo-box {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #003D7A 0%, #1E5A96 100%);
            border-radius: 20px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(0, 61, 122, 0.3);
            font-size: 40px;
        }
        
        .logo-box span {
            color: white;
            font-weight: bold;
        }
        
        h1 {
            color: #333333;
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }
        
        .subtitle {
            color: #666666;
            font-size: 14px;
            font-weight: 400;
        }
        
        .alerts {
            margin-bottom: 30px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 10px;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .alert-error {
            background: #FFE5E5;
            color: #C00000;
            border-left: 4px solid #C00000;
        }
        
        .alert-success {
            background: #E5F5E5;
            color: #008000;
            border-left: 4px solid #008000;
        }
        
        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            font-size: 13px;
            font-weight: 600;
            color: #003D7A;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 10px;
        }
        
        .role-option {
            position: relative;
        }
        
        .role-option input[type="radio"] {
            display: none;
        }
        
        .role-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px;
            border: 2px solid #E0E0E0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background: #F9F9F9;
        }
        
        .role-option input[type="radio"]:checked + .role-label {
            border-color: #003D7A;
            background: linear-gradient(135deg, #FFC800 0%, #FFD700 100%);
            color: #003D7A;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(0, 61, 122, 0.2);
        }
        
        .role-icon {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .role-name {
            font-size: 12px;
            font-weight: 600;
        }
        
        input[type="email"],
        input[type="password"] {
            padding: 12px 15px;
            border: 2px solid #E0E0E0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #003D7A;
            box-shadow: 0 0 0 3px rgba(0, 61, 122, 0.1);
            background: #F0F6FF;
        }
        
        .submit-btn {
            padding: 14px;
            background: linear-gradient(135deg, #003D7A 0%, #1E5A96 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 10px;
            box-shadow: 0 5px 20px rgba(0, 61, 122, 0.3);
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 61, 122, 0.4);
        }
        
        .submit-btn:active {
            transform: translateY(0);
        }
        
        .footer-text {
            text-align: center;
            font-size: 12px;
            color: #999999;
            margin-top: 20px;
        }
        
        .version {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            font-size: 11px;
            color: #CCCCCC;
            margin-top: 10px;
        }
        
        .test-users {
            background: #F5F5F5;
            border-left: 4px solid #FFC800;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 12px;
            line-height: 1.6;
        }
        
        .test-users strong {
            color: #003D7A;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-section">
            <div class="logo-box">
                <span>🚀</span>
            </div>
            <h1>SUPER_IA</h1>
            <p class="subtitle">Gestión Comercial y Crediticia</p>
        </div>
        
        <div class="alerts">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
        </div>
        
        <form method="POST" action="">
            <div class="form-group">
                <label>👤 Selecciona tu Rol</label>
                <div class="role-grid">
                    <div class="role-option">
                        <input type="radio" id="role_super_admin" name="role" value="super_admin" 
                               <?php echo ($selected_role === 'super_admin') ? 'checked' : ''; ?> required>
                        <label for="role_super_admin" class="role-label">
                            <span class="role-icon">👑</span>
                            <span class="role-name">Súper Admin</span>
                        </label>
                    </div>
                    
                    <div class="role-option">
                        <input type="radio" id="role_admin" name="role" value="admin"
                               <?php echo ($selected_role === 'admin') ? 'checked' : ''; ?> required>
                        <label for="role_admin" class="role-label">
                            <span class="role-icon">🔐</span>
                            <span class="role-name">Admin</span>
                        </label>
                    </div>
                    
                    <div class="role-option">
                        <input type="radio" id="role_supervisor" name="role" value="supervisor"
                               <?php echo ($selected_role === 'supervisor') ? 'checked' : ''; ?> required>
                        <label for="role_supervisor" class="role-label">
                            <span class="role-icon">📊</span>
                            <span class="role-name">Supervisor</span>
                        </label>
                    </div>
                    
                    <div class="role-option">
                        <input type="radio" id="role_asesor" name="role" value="asesor"
                               <?php echo ($selected_role === 'asesor') ? 'checked' : ''; ?> required>
                        <label for="role_asesor" class="role-label">
                            <span class="role-icon">👨‍💼</span>
                            <span class="role-name">Asesor</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">📧 Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" 
                       placeholder="tu@email.com" required>
            </div>
            
            <div class="form-group">
                <label for="password">🔑 Contraseña</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required>
            </div>
            
            <button type="submit" name="login" class="submit-btn">Acceder</button>
        </form>
        
        <div class="test-users">
            <strong>📝 Usuarios de Prueba:</strong><br>
            👑 Super Admin: admin@superialogal.test / admin123<br>
            🔐 Admin: admin@superialogal.test / admin123<br>
            📊 Supervisor: supervisor@superialogal.test / supervisor123<br>
            👨‍💼 Asesor: asesor.prueba@superialogal.test / asesor123
        </div>
        
        <div class="footer-text">
            <p>Sistema de Gestión Comercial y Crediticia</p>
            <div class="version">
                <span>v1.0 | SUPER_IA</span>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// ============================================================
// index.php - Pantalla de selección de rol con login
// ============================================================

session_start();

// Si ya está autenticado, redirigir al dashboard
if (isset($_SESSION['id_usuario'])) {
    header('Location: dashboard.php');
    exit;
}

// Procesar login si se envía el formulario
$loginError = '';
$loginSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    
    if (empty($email) || empty($password) || empty($role)) {
        $loginError = 'Email, contraseña y rol son requeridos';
    } else {
        // Mapeo de roles a tabla usuario SUPER_IA LOGAN
        $roleMap = [
            'administrador' => 'jefe_agencia',   // Equivalente a admin
            'supervisor' => 'supervisor',
            'asesor' => 'asesor'
        ];
        
        $roleBD = $roleMap[$role] ?? null;
        
        if (!$roleBD) {
            $loginError = 'Rol inválido';
        } else {
            // Incluir configuración de BD
            require_once 'db_config.php';
            
            try {
                // Consultar usuario en tabla usuario (SUPER_IA LOGAN)
                $query = "SELECT u.id, u.nombre, u.email, u.password_hash, u.rol, u.activo, u.estado_aprobacion
                          FROM usuario u
                          WHERE u.email = ? AND u.rol = ? AND u.activo = 1 AND u.estado_aprobacion = 'aprobado'";
                
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Error en preparación: " . $conn->error);
                }
                
                $stmt->bind_param('ss', $email, $roleBD);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    $loginError = 'Email, contraseña o rol incorrectos';
                } else {
                    $user = $result->fetch_assoc();
                    
                    // Verificar contraseña con password_verify
                    if (!password_verify($password, $user['password_hash'])) {
                        $loginError = 'Email, contraseña o rol incorrectos';
                    } else {
                        // Login exitoso
                        $_SESSION['id_usuario'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['nombre'] = $user['nombre'];
                        $_SESSION['rol'] = $user['rol'];
                        $_SESSION['rol_display'] = $role;
                        $_SESSION['login_time'] = time();
                        
                        // Actualizar último login
                        $updateQuery = "UPDATE usuario SET ultimo_login = NOW() WHERE id = ?";
                        $updateStmt = $conn->prepare($updateQuery);
                        if ($updateStmt) {
                            $updateStmt->bind_param('s', $user['id']);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }
                        
                        $loginSuccess = true;
                        header('Location: dashboard.php');
                        exit;
                    }
                }
                
                $stmt->close();
                
            } catch (Exception $e) {
                $loginError = 'Error del servidor: ' . $e->getMessage();
            }
        }
    }
}

// Determinar si mostrar modal de login
$mostrarModal = isset($_POST['role_select']) ? true : false;
$roleSeleccionado = isset($_POST['role_select']) ? $_POST['role_select'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Sistema de Monitoreo Comercial</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0F0C29 0%, #1A1535 50%, #0D0D1A 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fff;
            overflow-x: hidden;
        }

        .background-orbs {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .orb {
            position: absolute;
            border-radius: 50%;
            opacity: 0.1;
        }

        .orb1 {
            width: 300px;
            height: 300px;
            background: #7C3AED;
            top: -50px;
            right: -50px;
            animation: float 20s infinite ease-in-out;
        }

        .orb2 {
            width: 200px;
            height: 200px;
            background: #3B82F6;
            bottom: 10%;
            left: -100px;
            animation: float 15s infinite ease-in-out reverse;
        }

        .orb3 {
            width: 150px;
            height: 150px;
            background: #8B5CF6;
            top: 50%;
            right: 10%;
            animation: float 25s infinite ease-in-out;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, 30px); }
        }

        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1200px;
            padding: 40px 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 80px;
            animation: slideDown 0.8s ease-out;
        }

        .logo {
            font-size: 48px;
            font-weight: 800;
            background: linear-gradient(135deg, #7C3AED, #3B82F6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 16px;
            color: #9CA3AF;
            font-weight: 300;
            letter-spacing: 0.5px;
        }

        .title {
            font-size: 32px;
            font-weight: 700;
            margin-top: 15px;
            color: #E5E7EB;
        }

        .description {
            font-size: 14px;
            color: #9CA3AF;
            margin-top: 10px;
        }

        .cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 40px;
            animation: fadeIn 1s ease-out 0.3s both;
        }

        .card {
            background: rgba(17, 24, 39, 0.8);
            border: 1px solid rgba(124, 58, 237, 0.2);
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(124, 58, 237, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .card:hover::before {
            left: 100%;
        }

        .card:hover {
            border-color: rgba(124, 58, 237, 0.6);
            background: rgba(17, 24, 39, 0.95);
            transform: translateY(-10px);
            box-shadow: 0 20px 50px rgba(124, 58, 237, 0.3);
        }

        .icon-container {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #7C3AED, #3B82F6);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
            transition: all 0.3s ease;
        }

        .card:hover .icon-container {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 10px 30px rgba(124, 58, 237, 0.4);
        }

        .card-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #F3F4F6;
        }

        .card-description {
            font-size: 14px;
            color: #9CA3AF;
            line-height: 1.6;
            margin-bottom: 25px;
        }

        .btn-ingresar {
            background: linear-gradient(135deg, #7C3AED, #3B82F6);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
        }

        .btn-ingresar:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(124, 58, 237, 0.4);
            background: linear-gradient(135deg, #6D28D9, #2563EB);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .footer {
            text-align: center;
            color: #6B7280;
            font-size: 12px;
            margin-top: 40px;
        }

        .footer a {
            color: #7C3AED;
            text-decoration: none;
        }

        .footer a:hover {
            color: #3B82F6;
        }

        /* Modal */
        .modal-overlay {
            display: <?php echo $mostrarModal ? 'flex' : 'none'; ?>;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: linear-gradient(135deg, #111827, #1F2937);
            padding: 40px;
            border: 1px solid rgba(124, 58, 237, 0.3);
            border-radius: 20px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
        }

        .modal-header {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #F3F4F6;
            text-align: center;
        }

        .modal-role {
            font-size: 12px;
            color: #9CA3AF;
            text-align: center;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #9CA3AF;
            font-size: 14px;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            background: rgba(31, 41, 55, 0.5);
            border: 1px solid rgba(124, 58, 237, 0.2);
            border-radius: 10px;
            color: #F3F4F6;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #7C3AED;
            background: rgba(31, 41, 55, 0.8);
            box-shadow: 0 0 10px rgba(124, 58, 237, 0.2);
        }

        .form-group input::placeholder {
            color: #6B7280;
        }

        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #7C3AED, #3B82F6);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(124, 58, 237, 0.4);
        }

        .btn-volver {
            width: 100%;
            padding: 12px;
            background: rgba(124, 58, 237, 0.2);
            color: #9CA3AF;
            border: 1px solid rgba(124, 58, 237, 0.3);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-volver:hover {
            background: rgba(124, 58, 237, 0.3);
            color: #E5E7EB;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #FCA5A5;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .header {
                margin-bottom: 60px;
            }

            .logo {
                font-size: 36px;
            }

            .title {
                font-size: 24px;
            }

            .cards-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .card {
                padding: 30px 20px;
            }

            .icon-container {
                width: 70px;
                height: 70px;
                font-size: 32px;
            }

            .card-title {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <!-- Fondo con orbes animados -->
    <div class="background-orbs">
        <div class="orb orb1"></div>
        <div class="orb orb2"></div>
        <div class="orb orb3"></div>
    </div>

    <!-- Contenedor principal -->
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">🎯 Super_IA</div>
            <div class="subtitle">Sistema de Monitoreo</div>
            <h1 class="title">Bienvenido al Sistema</h1>
            <p class="description">Selecciona tu perfil para continuar al inicio de sesión</p>
        </div>

        <!-- Cards de roles -->
        <div class="cards-container">
            <!-- Card Administrador -->
            <div class="card">
                <div class="icon-container">👨‍💼</div>
                <h2 class="card-title">Administrador</h2>
                <p class="card-description">Gestión global de la plataforma, reportes y configuración del sistema.</p>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="role_select" value="administrador">
                    <button type="submit" class="btn-ingresar">Ingresar</button>
                </form>
            </div>

            <!-- Card Supervisor -->
            <div class="card">
                <div class="icon-container">👥</div>
                <h2 class="card-title">Supervisor</h2>
                <p class="card-description">Supervisión de asesores y monitoreo de clientes de tu región asignada.</p>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="role_select" value="supervisor">
                    <button type="submit" class="btn-ingresar">Ingresar</button>
                </form>
            </div>

            <!-- Card Asesor -->
            <div class="card">
                <div class="icon-container">💼</div>
                <h2 class="card-title">Asesor</h2>
                <p class="card-description">Gestión de clientes asignados y seguimiento de operaciones comerciales.</p>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="role_select" value="asesor">
                    <button type="submit" class="btn-ingresar">Ingresar</button>
                </form>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>© 2026 Super_IA. Sistema de Monitoreo Comercial. Todos los derechos reservados.</p>
        </div>
    </div>

    <!-- Modal Login -->
    <div class="modal-overlay" id="loginModal">
        <div class="modal-content">
            <div class="modal-header">Iniciar Sesión</div>
            <div class="modal-role">
                <?php
                $roleLabels = [
                    'administrador' => 'Administrador',
                    'supervisor' => 'Supervisor',
                    'asesor' => 'Asesor'
                ];
                echo isset($roleLabels[$roleSeleccionado]) ? $roleLabels[$roleSeleccionado] : '';
                ?>
            </div>

            <?php if (!empty($loginError)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($loginError); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="role" value="<?php echo htmlspecialchars($roleSeleccionado); ?>">
                
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" id="email" name="email" placeholder="Ingresa tu correo" autofocus required>
                </div>

                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" placeholder="Ingresa tu contraseña" required>
                </div>

                <button type="submit" name="login" class="btn-login">Iniciar Sesión</button>
                
                <form method="GET" style="display: inline-block; width: 100%;">
                    <button type="submit" class="btn-volver">Volver a Roles</button>
                </form>
            </form>
        </div>
    </div>
</body>
</html>

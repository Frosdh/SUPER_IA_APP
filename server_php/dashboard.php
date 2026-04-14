<?php
// ============================================================
// dashboard.php - Panel de control principal
// ============================================================

session_start();

// Verificar que el usuario esté autenticado
if (!isset($_SESSION['id_usuario'])) {
    header('Location: index.php');
    exit;
}

// Variables de sesión - Compatibles con nuevo login
$email = $_SESSION['email'] ?? 'Usuario';
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$rol = $_SESSION['rol'] ?? 'Sin rol';
$rol_display = $_SESSION['rol_display'] ?? $rol;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - COAC Finance</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #7C3AED, #3B82F6);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .navbar-left h1 {
            font-size: 24px;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info {
            text-align: right;
        }

        .user-info small {
            opacity: 0.9;
        }

        .btn-logout {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid white;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            background: rgba(255,255,255,0.3);
        }

        .container {
            padding: 30px;
        }

        .welcome-card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .welcome-card h2 {
            color: #333;
            margin-bottom: 10px;
        }

        .welcome-card p {
            color: #666;
        }

        .role-badge {
            display: inline-block;
            background: linear-gradient(135deg, #7C3AED, #3B82F6);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 10px;
        }

        .dashboard-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .dashboard-content h3 {
            color: #333;
            margin-bottom: 20px;
            border-bottom: 2px solid #7C3AED;
            padding-bottom: 10px;
        }

        .menu-items {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .menu-item {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #7C3AED;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #333;
        }

        .menu-item:hover {
            background: #f0f0f0;
            transform: translateX(5px);
        }

        .menu-item strong {
            display: block;
            margin-bottom: 5px;
            color: #7C3AED;
        }

        .menu-item small {
            color: #999;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 12px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <div class="navbar">
        <div class="navbar-left">
            <h1>🎯 COAC Finance - Dashboard</h1>
        </div>
        <div class="navbar-right">
            <div class="user-info">
                <strong><?php echo htmlspecialchars($nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($rol); ?></small>
            </div>
            <form method="POST" action="logout.php" style="display: inline;">
                <button type="submit" class="btn-logout">Cerrar Sesión</button>
            </form>
        </div>
    </div>

    <!-- Contenido Principal -->
    <div class="container">
        <!-- Tarjeta de Bienvenida -->
        <div class="welcome-card">
            <h2>Bienvenido, <?php echo htmlspecialchars($nombre); ?>! 👋</h2>
            <p>Te has registrado como <span class="role-badge"><?php echo htmlspecialchars($rol_display); ?></span></p>
            <p style="margin-top: 15px; color: #999;">Este es tu panel de control personalizado según tu rol en el sistema.</p>
        </div>

        <!-- Contenido del Dashboard -->
        <div class="dashboard-content">
            <?php
            // Mostrar contenido según el rol
            if ($rol === 'jefe_agencia') { // Admin
                ?>
                <h3>Panel Administrativo</h3>
                <p>Acceso total al sistema. Administra usuarios, genera reportes y configura el sistema.</p>
                <div class="menu-items">
                    <div class="menu-item">
                        <strong>👥 Usuarios</strong>
                        <small>Gestionar usuarios del sistema</small>
                    </div>
                    <div class="menu-item">
                        <strong>📊 Reportes</strong>
                        <small>Generar reportes del sistema</small>
                    </div>
                    <div class="menu-item">
                        <strong>⚙️ Configuración</strong>
                        <small>Configurar parámetros del sistema</small>
                    </div>
                    <div class="menu-item">
                        <strong>🔍 Auditoría</strong>
                        <small>Ver registro de cambios</small>
                    </div>
                </div>
                <?php
            } elseif ($rol === 'supervisor') { // Supervisor
                ?>
                <h3>Panel de Supervisión</h3>
                <p>Supervisa a tus asesores y monitorea a los clientes asignados a tu región.</p>
                <div class="menu-items">
                    <div class="menu-item">
                        <strong>👨‍💼 Mis Asesores</strong>
                        <small>Ver asesores bajo tu supervisión</small>
                    </div>
                    <div class="menu-item">
                        <strong>📋 Clientes</strong>
                        <small>Monitorear clientes de tu región</small>
                    </div>
                    <div class="menu-item">
                        <strong>📈 Desempeño</strong>
                        <small>Ver métricas de desempeño</small>
                    </div>
                    <div class="menu-item">
                        <strong>⏰ Gestiones</strong>
                        <small>Revisar gestiones realizadas</small>
                    </div>
                </div>
                <?php
            } elseif ($rol === 'asesor') { // Asesor
                ?>
                <h3>Panel de Asesor</h3>
                <p>Gestiona tus clientes asignados y realiza seguimiento de operaciones comerciales.</p>
                <div class="menu-items">
                    <div class="menu-item">
                        <strong>👥 Mis Clientes</strong>
                        <small>Ver clientes asignados a ti</small>
                    </div>
                    <div class="menu-item">
                        <strong>📞 Gestiones</strong>
                        <small>Registrar gestiones y llamadas</small>
                    </div>
                    <div class="menu-item">
                        <strong>💼 Operaciones</strong>
                        <small>Ver operaciones comerciales</small>
                    </div>
                    <div class="menu-item">
                        <strong>📅 Agenda</strong>
                        <small>Gestionar tu agenda de contactos</small>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>COAC Finance © 2026 | Sistema de Monitoreo Comercial | Todos los derechos reservados</p>
        </div>
    </div>
</body>
</html>

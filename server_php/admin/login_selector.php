<?php
require_once 'db_admin.php';

// Si ya hay una sesión activa, redirigir al panel correspondiente
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}
if (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    header('Location: mapa_familiar.php');
    exit;
}
if (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    header('Location: panel_cooperativa.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>COAC Finance — Seleccionar Rol</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            color: #f8fafc;
        }
        .container {
            max-width: 1400px;
            padding: 2rem;
        }
        .header-section {
            text-align: center;
            margin-bottom: 4rem;
        }
        .header-section h1 {
            font-weight: 800;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            background: linear-gradient(to right, #6366f1, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .header-section p {
            color: #94a3b8;
            font-size: 1.1rem;
        }
        .role-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
        }
        .role-card {
            background: rgba(30, 41, 59, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 2rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            backdrop-filter: blur(10px);
        }
        .role-card:hover {
            transform: translateY(-15px);
            background: rgba(30, 41, 59, 0.9);
            border-color: #6366f1;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 20px rgba(99, 102, 241, 0.2);
        }
        .icon-box {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
        }
        .role-card h2 {
            font-weight: 700;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        .role-card p {
            color: #94a3b8;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .role-card .btn-enter {
            margin-top: 1.5rem;
            padding: 0.6rem 1.5rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: 0.3s;
        }
        .role-card:hover .btn-enter {
            background: #6366f1;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <h1>Bienvenido a COAC Finance</h1>
            <p>Selecciona tu perfil para continuar al inicio de sesión</p>
        </div>

        <div class="role-grid">
            <!-- SUPER ADMIN ROLE -->
            <a href="login.php?role=super_admin" class="role-card">
                <div class="icon-box">
                    <i class="fas fa-crown"></i>
                </div>
                <h2>Super Administrador</h2>
                <p>Control total del sistema, gestión de administradores y supervisión completa.</p>
                <div class="btn-enter">Ingresar</div>
            </a>

            <!-- ADMIN ROLE -->
            <a href="login.php?role=admin" class="role-card">
                <div class="icon-box">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h2>Administrador</h2>
                <p>Gestión global de la plataforma, reportes y configuración del sistema.</p>
                <div class="btn-enter">Ingresar</div>
            </a>

            <!-- SUPERVISOR ROLE -->
            <a href="login.php?role=supervisor" class="role-card">
                <div class="icon-box">
                    <i class="fas fa-users-gear"></i>
                </div>
                <h2>Supervisor</h2>
                <p>Supervisión de operaciones, asesores y seguimiento de créditos.</p>
                <div class="btn-enter">Ingresar</div>
            </a>

            <!-- ASESOR ROLE -->
            <a href="login.php?role=asesor" class="role-card">
                <div class="icon-box">
                    <i class="fas fa-handshake"></i>
                </div>
                <h2>Asesor</h2>
                <p>Gestión de clientes, análisis de operaciones y seguimiento de créditos.</p>
                <div class="btn-enter">Ingresar</div>
            </a>
        </div>
    </div>
</body>
</html>

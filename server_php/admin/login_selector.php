<?php
require_once 'db_admin.php';

$BUILD = '2026-04-14a';

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
    header('Location: asesor_index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SUPER_IA — Seleccionar Rol</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #0B1929;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 24px;
            color: #F1F5F9;
            overflow-x: hidden;
            position: relative;
        }

        /* Fondo con círculos */
        body::before {
            content: '';
            position: fixed; top: -200px; right: -150px;
            width: 600px; height: 600px; border-radius: 50%;
            background: radial-gradient(circle, rgba(255,200,0,.10) 0%, transparent 65%);
            pointer-events: none; z-index: 0;
        }
        body::after {
            content: '';
            position: fixed; bottom: -150px; left: -100px;
            width: 500px; height: 500px; border-radius: 50%;
            background: radial-gradient(circle, rgba(22,48,87,.85) 0%, transparent 70%);
            pointer-events: none; z-index: 0;
        }
        .grid-bg {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image:
                linear-gradient(rgba(255,200,0,.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,200,0,.03) 1px, transparent 1px);
            background-size: 48px 48px;
        }

        .page-wrap {
            position: relative; z-index: 1;
            width: 100%; max-width: 1100px;
        }

        /* Header */
        .page-header {
            text-align: center;
            margin-bottom: 56px;
        }
        .page-logo {
            display: inline-flex; align-items: center; gap: 12px;
            margin-bottom: 28px;
        }
        .logo-icon {
            width: 48px; height: 48px; border-radius: 14px;
            background: linear-gradient(135deg, #FFC800, #E6A800);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 20px rgba(255,200,0,.3);
        }
        .logo-text {
            font-size: 22px; font-weight: 900; letter-spacing: -.5px;
            color: #fff;
        }
        .logo-text span { color: #FFC800; }

        .page-header h1 {
            font-size: clamp(1.8rem, 4vw, 2.6rem);
            font-weight: 900; letter-spacing: -1px;
            margin-bottom: 12px;
        }
        .page-header h1 em {
            font-style: normal; color: #FFC800;
        }
        .page-header p {
            font-size: 1rem; color: #8B99AE;
        }
        .header-divider {
            width: 60px; height: 3px; border-radius: 2px;
            background: linear-gradient(90deg, #FFC800, transparent);
            margin: 18px auto 0;
        }

        /* Grid de roles */
        .role-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 20px;
        }
        .role-grid-bottom {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            max-width: 360px;
            margin: 0 auto;
        }

        .role-card {
            background: #112035;
            border: 1px solid rgba(22,48,87,.9);
            border-radius: 22px;
            padding: 32px 24px 28px;
            text-align: center;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: transform .25s ease, border-color .25s ease, box-shadow .25s ease;
            position: relative; overflow: hidden;
        }
        .role-card::before {
            content: '';
            position: absolute; top: 0; left: 50%; transform: translateX(-50%);
            width: 60%; height: 2px;
            background: linear-gradient(90deg, transparent, var(--card-accent, #FFC800), transparent);
            opacity: 0; transition: opacity .25s;
        }
        .role-card:hover {
            transform: translateY(-10px);
            border-color: var(--card-accent, #FFC800);
            box-shadow: 0 20px 48px rgba(0,0,0,.35), 0 0 0 1px var(--card-accent, #FFC800) inset;
        }
        .role-card:hover::before { opacity: 1; }

        /* Variantes por rol */
        .card-superadmin { --card-accent: #FFC800; }
        .card-gerente     { --card-accent: #60A5FA; }
        .card-supervisor  { --card-accent: #8B99AE; }
        .card-asesor      { --card-accent: #4ADE80; }

        .icon-box {
            width: 72px; height: 72px; border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.9rem;
            margin-bottom: 18px;
            transition: transform .25s ease;
        }
        .role-card:hover .icon-box { transform: scale(1.08); }

        .ib-yellow { background: rgba(255,200,0,.15); color: #FFC800; box-shadow: 0 6px 20px rgba(255,200,0,.2); }
        .ib-blue   { background: rgba(96,165,250,.12); color: #60A5FA; box-shadow: 0 6px 20px rgba(96,165,250,.15); }
        .ib-slate  { background: rgba(139,153,174,.10); color: #B8C4D4; box-shadow: 0 6px 20px rgba(139,153,174,.1); }
        .ib-green  { background: rgba(74,222,128,.10); color: #4ADE80; box-shadow: 0 6px 20px rgba(74,222,128,.12); }

        .role-card h2 {
            font-size: 1.2rem; font-weight: 800;
            margin-bottom: 10px; letter-spacing: -.3px;
        }
        .role-card p {
            color: #8B99AE; font-size: .875rem; line-height: 1.6;
            flex: 1;
        }

        .btn-enter {
            margin-top: 22px;
            padding: 9px 22px;
            border-radius: 10px;
            font-size: .85rem; font-weight: 700;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.1);
            color: #B8C4D4;
            transition: .22s;
            display: inline-flex; align-items: center; gap: 7px;
        }
        .role-card:hover .btn-enter {
            background: var(--card-accent, #FFC800);
            border-color: var(--card-accent, #FFC800);
            color: #0B1929;
            box-shadow: 0 4px 14px rgba(0,0,0,.25);
        }

        /* Volver */
        .back-link {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 40px;
            font-size: 13px; color: #4A5568; text-decoration: none;
            transition: color .2s;
        }
        .back-link:hover { color: #FFC800; }

        /* Responsive */
        @media (max-width: 768px) {
            .role-grid { grid-template-columns: 1fr 1fr; }
            .role-grid-bottom { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .role-grid { grid-template-columns: 1fr; }
        }

        /* Fade in */
        .fade-in {
            opacity: 0; transform: translateY(18px);
            animation: fi .55s ease forwards;
        }
        @keyframes fi { to { opacity:1; transform:translateY(0); } }
        .d1{animation-delay:.05s} .d2{animation-delay:.12s} .d3{animation-delay:.19s}
        .d4{animation-delay:.26s} .d5{animation-delay:.33s} .d6{animation-delay:.40s}
    </style>
</head>
<body>
    <div class="grid-bg"></div>

    <div class="page-wrap">

        <!-- Header -->
        <div class="page-header fade-in d1">
            <div class="page-logo">
                <div class="logo-icon">🛰️</div>
                <span class="logo-text">SUPER<span>_IA</span></span>
            </div>
            <h1>Selecciona tu <em>Rol</em></h1>
            <p>Elige tu perfil de acceso para continuar al inicio de sesión</p>
            <div class="header-divider"></div>
        </div>

        <!-- Grid principal (3 columnas) -->
        <div class="role-grid">

            <!-- SUPER ADMINISTRADOR -->
            <a href="login.php?role=super_admin" class="role-card card-superadmin fade-in d2">
                <div class="icon-box ib-yellow">
                    <i class="fas fa-crown"></i>
                </div>
                <h2>Super Admin</h2>
                <p>Control total del sistema, gestión de usuarios y supervisión global completa.</p>
                <div class="btn-enter">
                    <i class="fas fa-arrow-right"></i> Ingresar
                </div>
            </a>

            <!-- GERENTE (antes Administrador) -->
            <a href="login.php?role=admin" class="role-card card-gerente fade-in d3">
                <div class="icon-box ib-blue">
                    <i class="fas fa-user-shield"></i>
                </div>
                <h2>Gerente</h2>
                <p>Gestión de la plataforma, reportes y configuración del sistema por agencia.</p>
                <div class="btn-enter">
                    <i class="fas fa-arrow-right"></i> Ingresar
                </div>
            </a>

            <!-- SUPERVISOR -->
            <a href="login.php?role=supervisor" class="role-card card-supervisor fade-in d4">
                <div class="icon-box ib-slate">
                    <i class="fas fa-users-gear"></i>
                </div>
                <h2>Supervisor</h2>
                <p>Supervisión de operaciones, asesores y seguimiento de créditos en campo.</p>
                <div class="btn-enter">
                    <i class="fas fa-arrow-right"></i> Ingresar
                </div>
            </a>

        </div>

        <!-- Fila inferior centrada -->
        <div class="role-grid-bottom" style="margin-top:18px;">
            <!-- ASESOR -->
            <a href="login.php?role=asesor" class="role-card card-asesor fade-in d5">
                <div class="icon-box ib-green">
                    <i class="fas fa-user-tie"></i>
                </div>
                <h2>Asesor</h2>
                <p>Panel del asesor: tareas del día, encuestas y seguimiento de clientes en campo.</p>
                <div class="btn-enter">
                    <i class="fas fa-arrow-right"></i> Ingresar
                </div>
            </a>
        </div>

        <!-- Volver -->
        <div style="text-align:center;">
            <a href="../bienvenida.php" class="back-link fade-in d6">
                <i class="fas fa-arrow-left"></i> Volver a la página de inicio
            </a>
        </div>

    </div>
</body>
</html>

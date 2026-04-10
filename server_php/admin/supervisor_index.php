<?php
require_once 'db_admin.php';

if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_id = $_SESSION['supervisor_id'];
$supervisor_nombre = $_SESSION['supervisor_nombre'];

try {
    $stats = $pdo->query("
        SELECT 
            COUNT(DISTINCT a.id) as total_asesores,
            COUNT(DISTINCT a.usuario_id) as total_usuarios_asesor
        FROM asesor a
        WHERE a.supervisor_id = '$supervisor_id'
    ")->fetch();
    
    if (!$stats) {
        $stats = [
            'total_asesores' => 0,
            'total_usuarios_asesor' => 0
        ];
    }
} catch (PDOException $e) {
    $stats = [
        'total_asesores' => 0,
        'total_usuarios_asesor' => 0
    ];
}

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Supervisor - COAC Finance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-gray: #6b7280;
            --brand-gray-soft: #edf2f7;
            --brand-border: #d7e0ea;
            --brand-card: #ffffff;
            --brand-bg: #f4f6f9;
            --brand-shadow: 0 16px 34px rgba(18, 58, 109, 0.08);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: linear-gradient(180deg, #f8fafc 0%, var(--brand-bg) 100%); display: flex; height: 100vh; color: var(--brand-navy-deep); }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: var(--brand-card); padding: 22px 20px; border-radius: 18px; box-shadow: var(--brand-shadow); text-align: center; border: 1px solid var(--brand-border); position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 4px; background: linear-gradient(90deg, var(--brand-yellow), var(--brand-navy)); }
        .stat-card .number { font-size: 32px; font-weight: 800; color: var(--brand-navy-deep); }
        .stat-card .label { color: var(--brand-gray); font-size: 13px; margin-top: 5px; }
        .welcome-card { background: linear-gradient(135deg, var(--brand-navy-deep) 0%, var(--brand-navy) 60%, #2b5b99 100%); color: white; padding: 32px; border-radius: 20px; margin-bottom: 30px; box-shadow: 0 20px 42px rgba(18, 58, 109, 0.18); position: relative; overflow: hidden; }
        .welcome-card::after { content: ''; position: absolute; right: -40px; top: -35px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(255,221,0,0.32) 0%, rgba(255,221,0,0) 70%); }
        .welcome-card h2 { margin: 0 0 10px 0; font-size: 24px; font-weight: 700; position: relative; z-index: 1; }
        .welcome-card p { margin: 0; opacity: 0.92; position: relative; z-index: 1; }
        .panel-box { background: var(--brand-card); padding: 22px; border-radius: 18px; box-shadow: var(--brand-shadow); border: 1px solid var(--brand-border); height: 100%; }
        .panel-box h5 { font-weight: 800; margin-bottom: 16px; color: var(--brand-navy-deep); }
        .quick-link { display: block; padding: 12px 16px; border-radius: 12px; text-decoration: none; margin-bottom: 10px; font-weight: 700; transition: 0.25s ease; }
        .quick-link:hover { transform: translateY(-2px); }
        .quick-yellow { background: linear-gradient(135deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); }
        .quick-navy { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; }
        .quick-gray { background: linear-gradient(135deg, #5f6b7a, #8a94a3); color: white; }
        .quick-light { background: linear-gradient(135deg, #e8edf3, #f7f9fb); color: var(--brand-navy-deep); border: 1px solid var(--brand-border); }
        .info-copy { color: var(--brand-gray); font-size: 14px; line-height: 1.7; }
        .info-list { color: var(--brand-gray); font-size: 14px; line-height: 1.85; margin-left: 20px; margin-bottom: 0; }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-chart-pie"></i> COAC Finance
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="supervisor_index.php" class="sidebar-link active">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_superIA.php" class="sidebar-link">
            <i class="fas fa-map"></i> Mapa en Vivo
        </a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestion</div>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Mi Equipo</div>
        <a href="mis_asesores.php" class="sidebar-link">
            <i class="fas fa-users"></i> Mis Asesores
        </a>
        <a href="registro_asesor.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link">
            <i class="fas fa-file-circle-check"></i> Solicitudes de Asesor
        </a>
    </div>
</div>
<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-shield-halved me-2" style="color: #ffdd00;"></i>COAC Finance - Supervisor</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($supervisor_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($_SESSION['supervisor_rol']); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesion</a>
        </div>
    </div>
    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-chart-bar me-2"></i>Panel de Control Supervisor</h1>
        </div>
        <div class="welcome-card">
            <h2>Bienvenido, <?php echo htmlspecialchars(explode(' ', $supervisor_nombre)[0]); ?>!</h2>
            <p>Gestiona tu equipo de asesores, revisa operaciones de credito y supervisa clientes en tiempo real.</p>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_asesores'] ?? 0; ?></div>
                <div class="label">Mi Equipo de Asesores</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_clientes'] ?? 0; ?></div>
                <div class="label">Total de Clientes</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #c59a00;"><?php echo $stats['clientes_activos'] ?? 0; ?></div>
                <div class="label">Clientes Activos</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #123a6d;"><?php echo $stats['operaciones_aprobadas'] ?? 0; ?></div>
                <div class="label">Operaciones Aprobadas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: #d1a400;">$<?php echo number_format($stats['monto_total'] ?? 0, 2); ?></div>
                <div class="label">Monto Total en Creditos</div>
            </div>
        </div>
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="panel-box">
                    <h5><i class="fas fa-bolt me-2" style="color: #ffdd00;"></i>Acceso Rapido</h5>
                    <a href="registro_asesor.php" class="quick-link quick-yellow"><i class="fas fa-user-plus me-2"></i>Crear Asesor</a>
                    <a href="administrar_solicitudes_asesor.php" class="quick-link quick-navy"><i class="fas fa-clipboard-check me-2"></i>Solicitudes de Asesor</a>
                    <a href="mis_asesores.php" class="quick-link quick-light"><i class="fas fa-users-gear me-2"></i>Ver Mis Asesores</a>
                    <a href="operaciones.php" class="quick-link quick-navy"><i class="fas fa-handshake me-2"></i>Ver Operaciones</a>
                    <a href="alertas.php" class="quick-link quick-gray"><i class="fas fa-bell me-2"></i>Ver Alertas Pendientes</a>
                </div>
            </div>
            <div class="col-md-6">
                <div class="panel-box">
                    <h5><i class="fas fa-circle-info me-2" style="color: #123a6d;"></i>Informacion</h5>
                    <p class="info-copy">Como Supervisor de COAC Finance, tienes acceso completo a:</p>
                    <ul class="info-list">
                        <li>Gestion de tu equipo de asesores</li>
                        <li>Seguimiento de operaciones de credito</li>
                        <li>Monitoreo de clientes asignados</li>
                        <li>Centro de alertas y notificaciones</li>
                        <li>Reportes en tiempo real</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

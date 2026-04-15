<?php
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

// Obtener datos del asesor
$asesor_id = $_SESSION['asesor_id'];
$asesor_nombre = $_SESSION['asesor_nombre'];

// Estadísticas para el asesor
$stats = ['total_clientes' => 0, 'clientes_activos' => 0, 'operaciones_aprobadas' => 0, 'monto_total' => 0];
try {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_clientes,
            SUM(CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END) as clientes_activos,
            (SELECT COUNT(*) FROM credito_proceso WHERE estado='aprobado') as operaciones_aprobadas,
            (SELECT SUM(monto) FROM credito_proceso WHERE estado='aprobado') as monto_total
        FROM cliente_prospecto cp
        WHERE cp.asesor_id = (SELECT id FROM asesor WHERE usuario_id = $asesor_id)
    ")->fetch();
} catch (Exception $e) {
    error_log("Error fetching asesor stats: " . $e->getMessage());
}

$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Asesor - Super_IA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --amarillo: #FBBF24;
            --amarillo-hover: #F59E0B;
            --azul-marino: #1e3a5f;
            --azul-claro: #2c5aa0;
            --gris: #6b7280;
            --gris-claro: #e5e7eb;
            --gris-muy-claro: #f3f4f6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--gris-muy-claro); display: flex; height: 100vh; }
        
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--azul-marino) 0%, #0f1f35 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
        }
        
        .sidebar-brand { 
            padding: 0 20px 30px; 
            font-size: 18px; 
            font-weight: 800; 
            border-bottom: 1px solid rgba(251, 191, 36, 0.2); 
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sidebar-brand i { margin-right: 0; color: var(--amarillo); font-size: 22px; }
        
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.6); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        
        .sidebar-link { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 12px 15px; 
            margin-bottom: 5px; 
            border-radius: 8px; 
            color: rgba(255,255,255,0.8); 
            cursor: pointer; 
            transition: all 0.3s ease; 
            text-decoration: none; 
            font-size: 14px; 
        }
        
        .sidebar-link:hover { 
            background: rgba(251, 191, 36, 0.15); 
            color: #fff; 
            padding-left: 20px; 
        }
        
        .sidebar-link.active { 
            background: linear-gradient(90deg, var(--amarillo), var(--amarillo-hover)); 
            color: var(--azul-marino); 
            font-weight: 600;
        }
        
        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        
        .navbar-custom { 
            background: linear-gradient(135deg, var(--amarillo) 0%, var(--amarillo-hover) 100%); 
            color: var(--azul-marino); 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); 
        }
        
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        
        .btn-logout { 
            background: rgba(30, 58, 95, 0.2); 
            color: var(--azul-marino); 
            border: 1px solid var(--azul-marino); 
            padding: 8px 15px; 
            border-radius: 6px; 
            cursor: pointer; 
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-logout:hover { 
            background: rgba(30, 58, 95, 0.3); 
            transform: translateY(-2px);
        }
        
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: var(--azul-marino); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        
        .stat-card { 
            background: white; 
            padding: 20px; 
            border-radius: 12px; 
            box-shadow: 0 2px 8px rgba(0,0,0,.06); 
            text-align: center;
            border-top: 3px solid var(--amarillo);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,.1);
        }
        
        .stat-card .number { font-size: 32px; font-weight: 700; color: var(--azul-marino); }
        .stat-card .label { color: var(--gris); font-size: 13px; margin-top: 5px; }
        
        .welcome-card { 
            background: linear-gradient(135deg, var(--amarillo) 0%, var(--amarillo-hover) 100%); 
            color: var(--azul-marino); 
            padding: 30px; 
            border-radius: 14px; 
            margin-bottom: 30px; 
            box-shadow: 0 4px 16px rgba(0,0,0,.1);
        }
        
        .welcome-card h2 { margin: 0 0 10px 0; font-size: 24px; font-weight: 700; }
        .welcome-card p { margin: 0; opacity: 0.85; }
        
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--gris-claro); border-radius: 3px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-user-tie"></i> Mi Panel
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="asesor_index.php" class="sidebar-link active">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo_asesor.php" class="sidebar-link">
            <i class="fas fa-map-location-dot"></i> Mi Ubicación
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <a href="clientes.php" class="sidebar-link">
            <i class="fas fa-briefcase"></i> Mis Clientes
        </a>
        <a href="operaciones.php" class="sidebar-link">
            <i class="fas fa-handshake"></i> Mis Operaciones
        </a>
        <a href="alertas.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Alertas
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2 style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-chart-bar"></i> Panel de Control Asesor
        </h2>
        <div class="user-info" style="color: var(--azul-marino);">
            <div>
                <strong><?php echo htmlspecialchars($asesor_nombre); ?></strong><br>
                <small style="color: rgba(30, 58, 95, 0.7);">Asesor</small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-chart-bar me-2"></i>Panel de Control Asesor</h1>
        </div>

        <!-- BIENVENIDA -->
        <div class="welcome-card">
            <h2>¡Bienvenido, <?php echo htmlspecialchars(explode(' ', $asesor_nombre)[0]); ?>!</h2>
            <p>Gestiona tus clientes, operaciones de crédito y mantén el seguimiento de tus actividades diarias.</p>
        </div>

        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo $stats['total_clientes'] ?? 0; ?></div>
                <div class="label">Total de Clientes</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: var(--amarillo);"><?php echo $stats['clientes_activos'] ?? 0; ?></div>
                <div class="label">Clientes Activos</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: var(--azul-claro);"><?php echo $stats['operaciones_aprobadas'] ?? 0; ?></div>
                <div class="label">Operaciones Aprobadas</div>
            </div>
            <div class="stat-card">
                <div class="number" style="color: var(--amarillo);">$<?php echo number_format($stats['monto_total'] ?? 0, 2); ?></div>
                <div class="label">Monto Total en Créditos</div>
            </div>
        </div>

        <!-- ACCESO RÁPIDO -->
        <div class="row mt-4">
            <div class="col-md-6">
                <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06);">
                    <h5 style="font-weight: 700; margin-bottom: 15px;">
                        <i class="fas fa-lightning-bolt me-2" style="color: var(--amarillo);"></i>Acceso Rápido
                    </h5>
                    <a href="clientes.php" style="display: block; padding: 12px 16px; background: linear-gradient(135deg, var(--amarillo), var(--amarillo-hover)); color: var(--azul-marino); border-radius: 8px; text-decoration: none; margin-bottom: 8px; font-weight: 600; transition: all 0.3s;">
                        <i class="fas fa-address-book me-2"></i>Ver Mis Clientes
                    </a>
                    <a href="operaciones.php" style="display: block; padding: 12px 16px; background: linear-gradient(135deg, var(--azul-claro), var(--azul-marino)); color: white; border-radius: 8px; text-decoration: none; margin-bottom: 8px; font-weight: 600; transition: all 0.3s;">
                        <i class="fas fa-handshake me-2"></i>Ver Mis Operaciones
                    </a>
                    <a href="alertas.php" style="display: block; padding: 12px 16px; background: var(--gris); color: white; border-radius: 8px; text-decoration: none; font-weight: 600; transition: all 0.3s;">
                        <i class="fas fa-bell me-2"></i>Ver Alertas
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <a href="mapa_vivo_asesor.php" style="text-decoration: none; display: block;">
                    <div style="background: linear-gradient(135deg, var(--amarillo), var(--amarillo-hover)); padding: 30px 20px; border-radius: 12px; box-shadow: 0 4px 16px rgba(107, 114, 128, 0.2); text-align: center; transition: all 0.3s ease; cursor: pointer;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 24px rgba(107, 114, 128, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 16px rgba(107, 114, 128, 0.2)';">
                        <i class="fas fa-map-location-dot" style="font-size: 48px; color: var(--azul-marino); margin-bottom: 15px; display: block;"></i>
                        <h5 style="font-weight: 700; color: var(--azul-marino); margin-bottom: 8px;">Mapa en Vivo</h5>
                        <p style="color: var(--azul-marino); font-size: 13px; margin: 0; opacity: 0.8;">
                            Ver tu ubicación en tiempo real
                        </p>
                    </div>
                </a>
            </div>
        </div>

    </div>
</div>

</body>
</html>

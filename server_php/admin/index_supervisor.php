<?php
// ============================================================
// admin/index_supervisor.php — Dashboard Super_IA Logan
// Panel principal para supervisores
// ============================================================

// Incluir conexión y BD
require_once 'db_admin_superIA.php';

// Verificar sesión del supervisor
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario es supervisor autenticado
$is_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;

if (!$is_supervisor) {
    header('Location: login.php?role=supervisor');
    exit;
}

// Evitar panel duplicado: el dashboard oficial del supervisor es supervisor_index.php (COAC Finance)
header('Location: supervisor_index.php');
exit;

// Obtener datos del supervisor
$supervisor_id = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$agencia_nombre = $_SESSION['agencia_name'] ?? 'Agencia';

if (!$supervisor_id) {
    die("Sesión inválida: ID de supervisor no encontrado");
}

// Obtener ID del supervisor (no usuario_id sino supervisor.id)
$sup_id_query = $conn->prepare("SELECT id FROM supervisor WHERE usuario_id = ?");
$sup_id_query->bind_param("s", $supervisor_id);
$sup_id_query->execute();
$sup_id_result = $sup_id_query->get_result()->fetch_assoc();
$real_supervisor_id = $sup_id_result['id'] ?? $supervisor_id;

// Obtener datos usando la clase
try {
    $supervisor_data = $dashboard->getSupervisorData($supervisor_id);
    $num_asesores = $dashboard->countAsesores($supervisor_id);
    $clientes_data = $dashboard->countClientesBySupervisor($supervisor_id);
    $tareas_hoy = $dashboard->getTareasHoy($supervisor_id);
    $kpis = $dashboard->getKPISupervisor($supervisor_id);
    $desempeno_asesores = $dashboard->getDesempenoAsesores($supervisor_id);
    $alertas_pendientes = $dashboard->getAlertasPendientes($supervisor_id);
    $penetracion = $dashboard->getPenetracionMercado($supervisor_id);
    
    // Obtener estadísticas de asesores por estado
    $pending_query = $conn->prepare("
        SELECT COUNT(*) as count FROM usuario u 
        JOIN asesor a ON a.usuario_id = u.id 
        WHERE a.supervisor_id = ? AND u.estado_registro = 'pendiente'
    ");
    $pending_query->bind_param("s", $real_supervisor_id);
    $pending_query->execute();
    $pending_result = $pending_query->get_result()->fetch_assoc();
    $asesores_pendientes = $pending_result['count'] ?? 0;
    
    $approved_query = $conn->prepare("
        SELECT COUNT(*) as count FROM usuario u 
        JOIN asesor a ON a.usuario_id = u.id 
        WHERE a.supervisor_id = ? AND u.estado_registro = 'aprobado'
    ");
    $approved_query->bind_param("s", $real_supervisor_id);
    $approved_query->execute();
    $approved_result = $approved_query->get_result()->fetch_assoc();
    $asesores_aprobados = $approved_result['count'] ?? 0;
    
    $rejected_query = $conn->prepare("
        SELECT COUNT(*) as count FROM usuario u 
        JOIN asesor a ON a.usuario_id = u.id 
        WHERE a.supervisor_id = ? AND u.estado_registro = 'rechazado'
    ");
    $rejected_query->bind_param("s", $real_supervisor_id);
    $rejected_query->execute();
    $rejected_result = $rejected_query->get_result()->fetch_assoc();
    $asesores_rechazados = $rejected_result['count'] ?? 0;
} catch (Exception $e) {
    error_log("Error loading dashboard: " . $e->getMessage());
    $supervisor_data = [];
    $num_asesores = 0;
    $clientes_data = ['total' => 0, 'clientes' => 0, 'prospectos' => 0];
    $tareas_hoy = [];
    $kpis = [];
    $desempeno_asesores = [];
    $alertas_pendientes = [];
    $penetracion = [];
    $asesores_pendientes = 0;
    $asesores_aprobados = 0;
    $asesores_rechazados = 0;
}

// Definir estadísticas
$stats = [
    'asesores_pendientes' => $asesores_pendientes ?? 0,
    'asesores_aprobados' => $asesores_aprobados ?? 0,
    'asesores_rechazados' => $asesores_rechazados ?? 0
];

$currentPage = 'dashboard';
$totalPendientes = count($alertas_pendientes);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Logan — Dashboard Supervisor</title>
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
        
        .sidebar-brand i { margin-right: 0; color: var(--amarillo); font-size: 24px; }
        
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        
        .sidebar-section-title { 
            font-size: 11px; 
            text-transform: uppercase; 
            color: rgba(255,255,255,0.6); 
            letter-spacing: 0.5px; 
            padding: 0 10px; 
            margin-bottom: 10px; 
            font-weight: 600; 
        }
        
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
        
        .main-content { 
            flex: 1; 
            margin-left: 230px; 
            display: flex; 
            flex-direction: column; 
            overflow: hidden; 
            min-width: 0; 
        }
        
        .navbar-custom { 
            background: linear-gradient(135deg, var(--amarillo) 0%, var(--amarillo-hover) 100%); 
            color: var(--azul-marino); 
            padding: 15px 30px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); 
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 40px; }
        
        .stat-card { 
            background: white; 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 2px 8px rgba(0,0,0,.06); 
            border-left: 4px solid var(--amarillo);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,.1);
        }
        
        .stat-card.pending { border-left-color: var(--amarillo); }
        .stat-card.approved { border-left-color: var(--azul-claro); }
        .stat-card.rejected { border-left-color: var(--gris); }
        
        .stat-card .number { 
            font-size: 36px; 
            font-weight: 700; 
            color: var(--azul-marino); 
            margin-bottom: 5px; 
        }
        
        .stat-card .label { 
            color: var(--gris); 
            font-size: 14px; 
            font-weight: 500; 
        }
        
        .action-cards { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
        }
        
        .action-card { 
            background: white; 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 2px 8px rgba(0,0,0,.06); 
            text-align: center; 
            text-decoration: none; 
            color: inherit; 
            transition: all 0.3s ease; 
            cursor: pointer;
            border-top: 3px solid var(--amarillo);
        }
        
        .action-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 8px 20px rgba(0,0,0,.1); 
        }
        
        .action-card i { 
            font-size: 40px; 
            color: var(--amarillo); 
            margin-bottom: 15px; 
        }
        
        .action-card h6 { 
            font-weight: 700; 
            margin-bottom: 8px; 
            color: var(--azul-marino); 
        }
        
        .action-card p { 
            color: var(--gris); 
            font-size: 13px; 
            margin: 0; 
        }

        h6 { color: var(--azul-marino); font-weight: 700; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-star"></i> Super_IA
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index_supervisor.php" class="sidebar-link active">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="registro_asesor.php" class="sidebar-link">
            <i class="fas fa-user-plus"></i> Crear Asesor
        </a>
    </div>
    
    <div class="sidebar-section">
        <div class="sidebar-section-title">Administración</div>
        <a href="administrar_solicitudes_asesor.php" class="sidebar-link">
            <i class="fas fa-file-alt"></i> Solicitudes de Asesor
        </a>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2 style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-star"></i> Super_IA Logan - Dashboard Supervisor
        </h2>
        <div class="user-info" style="color: var(--azul-marino);">
            <div>
                <strong><?php echo htmlspecialchars($supervisor_nombre); ?></strong><br>
                <small style="color: rgba(30, 58, 95, 0.7);">Supervisor</small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-chart-line me-2"></i>Dashboard</h1>
        </div>

        <!-- ESTADÍSTICAS -->
        <h6 style="color: #1f2937; font-weight: 700; margin-bottom: 20px;">Resumen de Asesores</h6>
        <div class="stats-grid">
            <div class="stat-card pending">
                <div class="number"><?php echo $stats['asesores_pendientes']; ?></div>
                <div class="label">Solicitudes Pendientes</div>
            </div>
            <div class="stat-card approved">
                <div class="number"><?php echo $stats['asesores_aprobados']; ?></div>
                <div class="label">Asesores Aprobados</div>
            </div>
            <div class="stat-card rejected">
                <div class="number"><?php echo $stats['asesores_rechazados']; ?></div>
                <div class="label">Solicitudes Rechazadas</div>
            </div>
        </div>

        <!-- ACCIONES RÁPIDAS -->
        <h6 style="color: #1f2937; font-weight: 700; margin-bottom: 20px; margin-top: 40px;">Acciones Rápidas</h6>
        <div class="action-cards">
            <a href="mapa_vivo_superIA.php" class="action-card" style="background: linear-gradient(135deg, var(--amarillo), var(--amarillo-hover)); border-left: 4px solid #F59E0B;">
                <i class="fas fa-map-location-dot" style="color: var(--azul-marino); font-size: 28px;"></i>
                <h6 style="color: var(--azul-marino);">Mapa en Vivo</h6>
                <p style="color: var(--azul-marino); opacity: 0.85;">Ver ubicaciones de tu equipo</p>
            </a>
            <a href="registro_asesor.php" class="action-card">
                <i class="fas fa-user-plus"></i>
                <h6>Crear Asesor</h6>
                <p>Registra un nuevo asesor para tu equipo</p>
            </a>
            <a href="administrar_solicitudes_asesor.php" class="action-card">
                <i class="fas fa-clipboard-check"></i>
                <h6>Gestionar Solicitudes</h6>
                <p>Aprueba o rechaza solicitudes de asesores</p>
            </a>
        </div>

    </div>
</div>

</body>
</html>

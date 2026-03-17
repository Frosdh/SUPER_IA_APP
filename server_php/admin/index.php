<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Obtener estadísticas
$stmtPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 0");
$totalPendientes = $stmtPendientes->fetchColumn();

$stmtActivos = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado = 1");
$totalActivos = $stmtActivos->fetchColumn();

$stmtViajes = $pdo->query("SELECT COUNT(*) FROM viajes WHERE estado IN ('pedido', 'aceptado', 'en_curso')");
$viajesActivos = $stmtViajes->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: #24243e; min-height: 100vh; color: #fff; padding-top: 20px; }
        .sidebar a { color: #ccc; text-decoration: none; display: block; padding: 15px 20px; transition: 0.3s; }
        .sidebar a:hover, .sidebar a.active { background: rgba(255,255,255,0.1); color: #fff; border-left: 4px solid #6b11ff; }
        .sidebar i { margin-right: 10px; width: 20px; text-align: center; }
        .content { padding: 30px; }
        .stat-card { background: #fff; border-radius: 15px; padding: 25px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .stat-card h3 { font-size: 32px; font-weight: bold; margin-bottom: 5px; color: #24243e; }
        .stat-card p { color: #6c757d; margin: 0; font-size: 15px; font-weight: 500;}
        .icon-box { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white;}
        .bg-purple { background: linear-gradient(135deg, #6b11ff, #9b51e0); }
        .bg-blue { background: linear-gradient(135deg, #3182fe, #5b9cfc); }
        .bg-green { background: linear-gradient(135deg, #11998e, #38ef7d); }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <div class="col-md-2 sidebar">
                <h4 class="text-center mb-4 font-weight-bold">GeoMove Admin</h4>
                <a href="index.php" class="active"><i class="fas fa-home"></i> Inicio</a>
                <a href="pendientes.php"><i class="fas fa-user-clock"></i> Pendientes <?php if($totalPendientes > 0) echo "<span class='badge bg-danger ms-2'>$totalPendientes</span>"; ?></a>
                <a href="activos.php"><i class="fas fa-users"></i> Conductores Activos</a>
                <a href="viajes.php"><i class="fas fa-route"></i> Viajes en Curso</a>
                <a href="logout.php" class="text-danger mt-5"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
            </div>
            
            <div class="col-md-10 content">
                <h2 class="mb-4 fw-bold">Dashboard General</h2>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3><?= number_format($totalPendientes) ?></h3>
                                    <p>Conductores Pendientes</p>
                                </div>
                                <div class="icon-box bg-purple"><i class="fas fa-user-clock"></i></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3><?= number_format($totalActivos) ?></h3>
                                    <p>Conductores Activos</p>
                                </div>
                                <div class="icon-box bg-blue"><i class="fas fa-users"></i></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h3><?= number_format($viajesActivos) ?></h3>
                                    <p>Viajes Activos</p>
                                </div>
                                <div class="icon-box bg-green"><i class="fas fa-car-side"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

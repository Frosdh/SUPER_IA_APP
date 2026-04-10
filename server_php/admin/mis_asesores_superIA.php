<?php
// ============================================================
// admin/mis_asesores_superIA.php — Gestión de Asesores SuperIA
// Panel mejorado para supervisores
// ============================================================

require_once 'db_admin_superIA.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;
if (!$is_supervisor) {
    header('Location: login.php?role=supervisor');
    exit;
}

$supervisor_id = $_SESSION['supervisor_id'] ?? null;
$supervisor_nombre = $_SESSION['supervisor_nombre'] ?? 'Supervisor';

// Paginación
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Obtener asesores
try {
    $asesores = $dashboard->listAsesores($supervisor_id, $limit, $offset);
    $total_asesores = $dashboard->countAsesores($supervisor_id);
    $total_pages = ceil($total_asesores / $limit);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $asesores = [];
    $total_asesores = 0;
    $total_pages = 0;
}

$currentPage = 'asesores';
$totalPendientes = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Logan — Mis Asesores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .asesor-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #FBBF24;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
            transition: all 0.3s ease;
        }
        .asesor-card:hover {
            box-shadow: 0 6px 20px rgba(251,191,36,.15);
            transform: translateY(-2px);
        }
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 12px;
        }
        .stat-item {
            text-align: center;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 8px;
            font-size: 12px;
        }
        .stat-item .number {
            font-weight: 700;
            font-size: 18px;
            color: #FBBF24;
        }
        .stat-item .label {
            color: #999;
            margin-top: 3px;
        }
    </style>
</head>
<body>
<div class="container-fluid" style="margin:0;padding:0;display:flex;height:100vh;">
    
    <?php include '_sidebar.php'; ?>
    
    <div class="col main-content" style="margin-left:230px;overflow-y:auto;">
        
        <div class="navbar-custom" style="background: linear-gradient(135deg, #FBBF24 0%, #F59E0B 100%);">
            <div>
                <h2 style="margin:0;display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-users"></i> Mi Equipo de Asesores
                </h2>
                <small style="opacity:0.9;"><?= $total_asesores ?> asesores activos</small>
            </div>
            <div class="user-info">
                <div>
                    <small><?= htmlspecialchars($supervisor_nombre) ?></small><br>
                    <small>Supervisor</small>
                </div>
                <a href="logout.php" class="btn-logout" title="Cerrar sesión">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </div>
        </div>
        
        <div class="content" style="padding: 25px 30px;">
            
            <div class="page-header" style="margin-bottom: 30px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h1 class="page-title">Equipo de Asesores</h1>
                        <p class="page-sub">Visualiza y gestiona las actividades de tu equipo</p>
                    </div>
                    <a href="crear_asesor_admin.php?ref=mis_asesores_superIA" class="btn btn-warning" style="background: linear-gradient(135deg, #FBBF24, #F59E0B); border: none; color: #1a0f3d; font-weight: 600;">
                        <i class="fas fa-user-plus"></i> Nuevo Asesor
                    </a>
                </div>
            </div>

            <!-- LISTA DE ASESORES -->
            <div style="margin-top: 30px;">
                <?php if (count($asesores) > 0): ?>
                    
                    <?php foreach ($asesores as $asesor): ?>
                    <div class="asesor-card">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <div style="flex: 1;">
                                <h5 style="margin:0;color:#1f2937;font-weight:700;display:flex;align-items:center;gap:10px;">
                                    <?= htmlspecialchars($asesor['nombre']) ?>
                                    <span class="badge bg-success" style="font-size:10px;">ACTIVO</span>
                                </h5>
                                <small style="color:#999;display:block;margin-top:3px;">
                                    <i class="fas fa-envelope"></i> <?= htmlspecialchars($asesor['email']) ?>
                                </small>
                                <small style="color:#999;display:block;">
                                    <i class="fas fa-clock"></i> Último acceso: 
                                    <?= $asesor['ultimo_login'] ? date('d/m/Y H:i', strtotime($asesor['ultimo_login'])) : 'Nunca' ?>
                                </small>
                            </div>
                            <div style="text-align: right; display: flex; gap: 5px;">
                                <a href="editar_asesor.php?id=<?= $asesor['asesor_id'] ?>" class="btn btn-sm btn-outline-warning" style="color:#F59E0B;border-color:#F59E0B;">
                                    <i class="fas fa-edit"></i> Editar
                                </a>
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarAsesor(<?= $asesor['asesor_id'] ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- ESTADÍSTICAS MINI -->
                        <div class="stats-mini">
                            <div class="stat-item">
                                <div class="number"><?= $asesor['num_clientes'] ?></div>
                                <div class="label">Clientes</div>
                            </div>
                            <div class="stat-item">
                                <div class="number"><?= $asesor['num_tareas'] ?></div>
                                <div class="label">Tareas</div>
                            </div>
                            <div class="stat-item">
                                <div class="number"><?= $asesor['tareas_completadas'] ?></div>
                                <div class="label">Completadas</div>
                            </div>
                            <div class="stat-item">
                                <div class="number"><?= $asesor['meta_tareas_diarias'] ?></div>
                                <div class="label">Meta Diaria</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- PAGINACIÓN -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Paginación" style="margin-top: 30px;">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1"><i class="fas fa-chevron-left"></i> Primera</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $total_pages ?>">Última <i class="fas fa-chevron-right"></i></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>

                <?php else: ?>
                    <div style="text-align: center; padding: 60px 20px;">
                        <i class="fas fa-inbox" style="font-size: 60px; color: #ddd; margin-bottom: 20px; display: block;"></i>
                        <h5 style="color: #999;">Sin asesores registrados</h5>
                        <p style="color: #bbb; margin-bottom: 20px;">Aún no tienes asesores en tu equipo</p>
                        <a href="crear_asesor_admin.php" class="btn btn-warning" style="background: linear-gradient(135deg, #FBBF24, #F59E0B); border: none; color: #1a0f3d; font-weight: 600;">
                            <i class="fas fa-user-plus"></i> Crear Primer Asesor
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function eliminarAsesor(asesorId) {
    if (confirm('¿Está seguro de que desea remover este asesor?\nEsto desactivará su cuenta pero no eliminará sus datos.')) {
        fetch('api_super_ia.php?action=eliminar_asesor', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'asesor_id=' + asesorId
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('✓ Asesor desactivado correctamente');
                location.reload();
            } else {
                alert('❌ Error: ' + (data.message || data.error || 'No se pudo remover'));
            }
        })
        .catch(e => alert('Error en la solicitud: ' + e));
    }
}
</script>

</body>
</html>

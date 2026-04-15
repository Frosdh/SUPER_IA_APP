<?php
require_once 'db_admin.php';

$session_missing = false;
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
    $session_missing = true;
}

// Determinar id del supervisor en sesión (varios nombres posibles)
$session_user_id = $_SESSION['supervisor_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['usuario_id'] ?? null;

$asesores = [];
$clientes_por_asesor = [];

if (!$session_missing && $session_user_id) {
    // Intentar la consulta heredada; si falla, intentar fallback con tablas `usuario` + `asesor`
    try {
        $supervisor_id = intval($session_user_id);
        $asesores = $pdo->query(
            "SELECT u.id_usuario, u.usuario, u.nombres, u.apellidos, u.email, u.telefono, u.ciudad, r.nombre as rol,\n" .
            "       COUNT(c.id_cliente) as total_clientes\n" .
            "FROM usuarios u\n" .
            "JOIN roles r ON u.id_rol_fk = r.id_rol\n" .
            "LEFT JOIN clientes c ON c.asesor_id_fk = u.id_usuario\n" .
            "WHERE r.nombre = 'Asesor' AND u.supervisor_id_fk = $supervisor_id\n" .
            "GROUP BY u.id_usuario, u.usuario\n" .
            "ORDER BY u.nombres"
        )->fetchAll();
    } catch (Exception $e) {
        // fallback: nuevo esquema
        try {
            $stmt = $pdo->prepare("SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1");
            $stmt->execute([$session_user_id]);
            $supRow = $stmt->fetch();
            if ($supRow && isset($supRow['id'])) {
                $supId = $supRow['id'];
                $stmt = $pdo->prepare(
                    "SELECT a.id AS asesor_id, u.id AS usuario_id, u.nombre AS nombre_completo, u.email, NULL AS telefono, COUNT(cp.id) AS total_clientes\n" .
                    "FROM asesor a\n" .
                    "JOIN usuario u ON u.id = a.usuario_id\n" .
                    "LEFT JOIN cliente_prospecto cp ON cp.asesor_id = a.id\n" .
                    "WHERE a.supervisor_id = ?\n" .
                    "GROUP BY a.id, u.id, u.nombre, u.email\n" .
                    "ORDER BY u.nombre"
                );
                $stmt->execute([$supId]);
                $rows = $stmt->fetchAll();
                foreach ($rows as $r) {
                    $parts = explode(' ', trim($r['nombre_completo']), 2);
                    $asesores[] = [
                        'id_usuario' => $r['usuario_id'],
                        'usuario' => strstr($r['email'], '@', true) ?: $r['email'],
                        'nombres' => $parts[0] ?? '',
                        'apellidos' => $parts[1] ?? '',
                        'email' => $r['email'],
                        'telefono' => $r['telefono'] ?? '',
                        'ciudad' => '',
                        'total_clientes' => $r['total_clientes'] ?? 0
                    ];
                }
            }
        } catch (Exception $e2) {
            // dejar vacío
            $asesores = [];
        }
    }

    // Obtener clientes por asesor (normalizar ambas estructuras)
    foreach ($asesores as $asesor) {
        $aid_usuario = $asesor['usuario_id'] ?? $asesor['id_usuario'] ?? null;
        if (!$aid_usuario) continue;

        // Query cliente_prospecto with proper schema mapping
        try {
            $asesor_id_subquery = intval($aid_usuario);
            $clientes = $pdo->query("
                SELECT 
                    cp.id AS id_cliente, 
                    cp.nombre, 
                    COALESCE(cp.cedula, '') as apellidos,
                    cp.email, 
                    cp.telefono, 
                    CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END as activo
                FROM cliente_prospecto cp
                WHERE cp.asesor_id = (SELECT id FROM asesor WHERE usuario_id = $asesor_id_subquery)
                ORDER BY cp.nombre
            ")->fetchAll();
        } catch (Exception $e) {
            error_log("Error fetching clientes for asesor $aid_usuario: " . $e->getMessage());
            $clientes = [];
        }
        $clientes_por_asesor[$aid_usuario] = $clientes;
    }
}

$currentPage        = 'asesores';
$alertas_pendientes = 0;
$supervisor_rol     = $_SESSION['supervisor_rol'] ?? 'Supervisor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Mis Asesores</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
            --brand-gray: #6b7280;
            --brand-border: #d7e0ea;
            --brand-card: #ffffff;
            --brand-bg: #f4f6f9;
            --brand-shadow: 0 16px 34px rgba(18, 58, 109, 0.08);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: linear-gradient(180deg, #f8fafc 0%, var(--brand-bg) 100%); display: flex; height: 100vh; color: var(--brand-navy-deep); }

        .sidebar { width: 230px; background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%); color: white; padding: 20px 0; overflow-y: auto; position: fixed; height: 100vh; left: 0; top: 0; }
        .sidebar-brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .sidebar-brand i { color:var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; border: 1px solid transparent; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; border-color: rgba(255,221,0,0.15); }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; box-shadow: 0 10px 24px rgba(255,221,0,0.18); }
        .badge-nav { background:#ef4444; color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; margin-left:auto; font-weight:700; }

        .main-content { flex: 1; margin-left: 230px; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 12px 28px rgba(18, 58, 109, 0.18); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255,221,0,0.15); color: white; border: 1px solid rgba(255,221,0,0.28); padding: 8px 15px; border-radius: 10px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .btn-logout:hover { background: rgba(255,221,0,0.24); color: white; }

        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 800; color: var(--brand-navy-deep); }
        .asesor-card { background: white; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06); margin-bottom: 20px; overflow: hidden; border-left: 4px solid var(--brand-yellow-deep); }
        .asesor-header { padding: 20px; background: #f8f9fa; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
        .asesor-header:hover { background: #f0f1f3; }
        .asesor-info h5 { margin: 0; font-weight: 700; color: #1f2937; }
        .asesor-meta { color: #64748b; font-size: 13px; margin-top: 5px; }
        .clients-count { background: var(--brand-navy); color: white; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 13px; }
        .asesor-clients { padding: 0; display: none; }
        .asesor-clients.show { display: block; }
        .client-item { padding: 12px 20px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .client-item:last-child { border-bottom: none; }
        .client-item:hover { background: #fafbff; }
        .client-name { font-weight: 600; color: #1f2937; }
        .client-contact { color: #64748b; font-size: 12px; }
        .badge-active { background: #10b981; }
        .badge-inactive { background: #ef4444; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<?php require_once '_sidebar_supervisor.php'; ?>

<!-- MAIN CONTENT -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2>🎯 Super_IA - Supervisor</h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($_SESSION['supervisor_nombre']); ?></strong><br>
                <small><?php echo htmlspecialchars($_SESSION['supervisor_rol']); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>
    
    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <h1><i class="fas fa-users me-2"></i>Mi Equipo de Asesores y sus Clientes</h1>
        </div>

        <?php if (empty($asesores)): ?>
        <div style="background: white; padding: 40px; text-align: center; border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,.06);">
            <i class="fas fa-inbox" style="font-size: 48px; color: #d1d5db; margin-bottom: 15px;"></i>
            <h4 style="color: #64748b;">No tienes asesores asignados</h4>
        </div>
        <?php else: ?>

        <!-- ASESORES Y CLIENTES -->
        <?php foreach ($asesores as $asesor): ?>
        <div class="asesor-card">
            <div class="asesor-header" onclick="toggleClientes(<?php echo $asesor['id_usuario']; ?>)">
                <div class="asesor-info">
                    <h5><?php echo htmlspecialchars($asesor['nombres'] . ' ' . $asesor['apellidos']); ?></h5>
                    <div class="asesor-meta">
                        <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($asesor['usuario']); ?> | 
                        <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($asesor['email']); ?> | 
                        <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($asesor['telefono'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div class="clients-count"><?php echo $asesor['total_clientes']; ?> clientes</div>
                    <i class="fas fa-chevron-down" id="chevron-<?php echo $asesor['id_usuario']; ?>" style="color: #6b11ff; transition: 0.3s;"></i>
                </div>
            </div>

            <div class="asesor-clients" id="clients-<?php echo $asesor['id_usuario']; ?>">
                <?php if (empty($clientes_por_asesor[$asesor['id_usuario']])): ?>
                <div style="padding: 20px; text-align: center; color: #9ca3af;">
                    <i class="fas fa-inbox me-2"></i>Sin clientes asignados
                </div>
                <?php else: ?>
                    <?php foreach ($clientes_por_asesor[$asesor['id_usuario']] as $cliente): ?>
                    <div class="client-item">
                        <div style="flex: 1;">
                            <div class="client-name">#<?php echo $cliente['id_cliente']; ?> - <?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellidos']); ?></div>
                            <div class="client-contact">
                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($cliente['email'] ?? 'N/A'); ?> | 
                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div>
                            <?php if ($cliente['activo']): ?>
                                <span class="badge badge-active" style="color: white;">✓ Activo</span>
                            <?php else: ?>
                                <span class="badge badge-inactive" style="color: white;">✗ Inactivo</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>
</div>

<script>
function toggleClientes(asesorId) {
    const clientsDiv = document.getElementById('clients-' + asesorId);
    const chevron = document.getElementById('chevron-' + asesorId);
    
    clientsDiv.classList.toggle('show');
    chevron.style.transform = clientsDiv.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
}
</script>

</body>
</html>

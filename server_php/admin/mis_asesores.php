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

        // Query cliente_prospecto con esquema nuevo (usuario_id puede ser UUID)
        try {
            $stmt = $pdo->prepare(
                "SELECT 
                    cp.id AS id_cliente,
                    cp.nombre,
                    COALESCE(cp.cedula, '') AS cedula,
                    cp.email,
                    cp.telefono,
                    cp.telefono2,
                    CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END AS activo
                 FROM cliente_prospecto cp
                 WHERE cp.asesor_id = (SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1)
                 ORDER BY cp.nombre"
            );
            $stmt->execute([$aid_usuario]);
            $clientes = $stmt->fetchAll();
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
    <link rel="stylesheet" href="supervisor_layout.css">
</head>
<body>


<?php $navTitle = 'Mis Asesores'; $navIcon = 'fas fa-users'; require_once '_sidebar_supervisor.php'; ?>
<div class="main-content">
    <div class="content-area">
        <div class="card-block" style="max-width:900px;margin:0 auto 28px;">
            <h2 style="font-size:1.5rem;font-weight:800;margin-bottom:18px;color:#123a6d;display:flex;align-items:center;gap:10px;">
                <i class="fas fa-users" style="color:#f4c400;"></i> Mi Equipo de Asesores y sus Clientes
            </h2>
            <?php if (empty($asesores)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No tienes asesores asignados</p>
                </div>
            <?php else: ?>
                <?php foreach ($asesores as $asesor): ?>
                    <?php $asesorKey = (string)($asesor['id_usuario'] ?? ''); ?>
                    <div class="asesor-card" style="margin-bottom:18px;">
                        <div class="asesor-header" style="padding:18px 22px;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;cursor:pointer;" onclick="toggleClientes('<?php echo htmlspecialchars($asesorKey, ENT_QUOTES, 'UTF-8'); ?>')">
                            <div class="asesor-info">
                                <h5 style="margin:0;font-weight:700;color:#1f2937;font-size:1.1rem;">
                                    <?php echo htmlspecialchars($asesor['nombres'] . ' ' . $asesor['apellidos']); ?>
                                </h5>
                                <div class="asesor-meta" style="color:#64748b;font-size:13px;margin-top:5px;">
                                    <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($asesor['usuario']); ?> |
                                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($asesor['email']); ?> |
                                    <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($asesor['telefono'] ?? 'N/A'); ?>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <div class="clients-count" style="background:#123a6d;color:white;padding:6px 12px;border-radius:6px;font-weight:600;font-size:13px;">
                                    <?php echo $asesor['total_clientes']; ?> clientes
                                </div>
                                <i class="fas fa-chevron-down" id="chevron-<?php echo htmlspecialchars($asesorKey, ENT_QUOTES, 'UTF-8'); ?>" style="color:#6b11ff;transition:0.3s;"></i>
                            </div>
                        </div>
                        <div class="asesor-clients" id="clients-<?php echo htmlspecialchars($asesorKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (empty($clientes_por_asesor[$asesorKey])): ?>
                                <div class="empty-state" style="padding:20px 0;">
                                    <i class="fas fa-inbox"></i> Sin clientes asignados
                                </div>
                            <?php else: ?>
                                <?php foreach ($clientes_por_asesor[$asesorKey] as $cliente): ?>
                                    <div class="client-item" style="padding:12px 20px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;">
                                        <div style="flex:1;">
                                            <div class="client-name" style="font-weight:600;color:#1f2937;">
                                                <?php echo htmlspecialchars($cliente['nombre']); ?><?php if (!empty($cliente['cedula'])): ?> <span style="font-weight:500;color:#64748b;">(CI: <?php echo htmlspecialchars($cliente['cedula']); ?>)</span><?php endif; ?>
                                            </div>
                                            <div class="client-contact" style="color:#64748b;font-size:12px;">
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($cliente['email'] ?? 'N/A'); ?> |
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($cliente['telefono2'] ?? $cliente['telefono'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($cliente['activo']): ?>
                                                <span class="badge badge-active" style="background:#10b981;color:white;">✓ Activo</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive" style="background:#ef4444;color:white;">✗ Inactivo</span>
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

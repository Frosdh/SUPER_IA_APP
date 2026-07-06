<?php
require_once 'db_admin.php';

// Verificar sesión según rol (mismo patrón que alertas.php / operaciones.php)
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
    $user_nombre = $_SESSION['super_admin_nombre'];
    $user_rol_label = $_SESSION['super_admin_rol'];
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
    $user_nombre = $_SESSION['admin_nombre'];
    $user_rol_label = $_SESSION['admin_rol'];
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
    $user_nombre = $_SESSION['supervisor_nombre'];
    $user_rol_label = 'supervisor';
} else {
    header('Location: login.php?role=admin');
    exit;
}

$is_super_admin = $user_role === 'super_admin';

// Resolver supervisor.id real si aplica
$supervisor_table_id = null;
if ($user_role === 'supervisor') {
    $stSup = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = :uid LIMIT 1');
    $stSup->execute([':uid' => $_SESSION['supervisor_id'] ?? '']);
    $supervisor_table_id = $stSup->fetchColumn();
}

// Asegurar columnas nuevas (por si el mobile aún no las creó en esta BD)
foreach ([
    'motivo_descarte' => "ADD COLUMN motivo_descarte TEXT DEFAULT NULL",
    'descartada_at'   => "ADD COLUMN descartada_at DATETIME DEFAULT NULL",
] as $col => $ddl) {
    $chk = $pdo->query("SHOW COLUMNS FROM tarea LIKE '$col'")->fetchAll();
    if (empty($chk)) {
        try { $pdo->exec("ALTER TABLE tarea $ddl"); } catch (Throwable $e) {}
    }
}

$sql = "
    SELECT
        t.id, t.tipo_tarea, t.fecha_programada, t.hora_programada,
        t.motivo_descarte, t.descartada_at, t.created_at,
        cp.nombre AS cliente_nombre, cp.cedula AS cliente_cedula,
        cp.telefono AS cliente_telefono,
        ua.nombre AS asesor_nombre,
        us.nombre AS supervisor_nombre
    FROM tarea t
    LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
    INNER JOIN asesor a ON a.id = t.asesor_id
    INNER JOIN usuario ua ON ua.id = a.usuario_id
    LEFT JOIN supervisor s ON s.id = a.supervisor_id
    LEFT JOIN usuario us ON us.id = s.usuario_id
    WHERE t.estado = 'cancelada'
";
$params = [];
if ($user_role === 'supervisor') {
    $sql .= " AND s.id = :sup_id";
    $params[':sup_id'] = $supervisor_table_id ?: '';
}
$sql .= " ORDER BY COALESCE(t.descartada_at, t.created_at) DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$descartadas = $stmt->fetchAll();

$total = count($descartadas);
$hoy = date('Y-m-d');
$mes = date('Y-m');
$hoyCount = 0; $mesCount = 0;
foreach ($descartadas as $d) {
    $ref = $d['descartada_at'] ?: $d['created_at'];
    if ($ref && substr($ref, 0, 10) === $hoy) $hoyCount++;
    if ($ref && substr($ref, 0, 7) === $mes) $mesCount++;
}

$tipo_labels = [
    'prospecto_nuevo'        => 'Prospecto nuevo',
    'visita_frio'             => 'Visita en frío',
    'evaluacion'              => 'Evaluación',
    'recuperacion'            => 'Recuperación',
    'post_venta'              => 'Post venta',
    'represtamo'              => 'Represtamo',
    'documentos_pendientes'   => 'Recolectar documentación',
    'nueva_cita_campo'        => 'Nueva cita en campo',
    'nueva_cita_oficina'      => 'Nueva cita en oficina',
    'nueva_cita_inversion'    => 'Nueva cita de inversión',
    'levantamiento'           => 'Levantamiento',
    'seguimiento'             => 'Seguimiento',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tareas Descartadas - Super_IA Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar {
            width: 230px; background: linear-gradient(180deg, #2d1b69 0%, #1a0f3d 100%);
            color: white; padding: 20px 0; overflow-y: auto; position: sticky; height: 100vh; top: 0; flex-shrink: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: #fbbf24; }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: #9ca3af; letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 8px; color: #d1d5db; cursor: pointer; transition: all 0.3s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(124, 58, 237, 0.2); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, #fbbf24, #f59e0b); color: #1a0f3d; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; }
        .navbar-custom { background: linear-gradient(135deg, #f59e0b, #fbbf24); color: #1a0f3d; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(0, 0, 0, 0.1); color: #1a0f3d; border: 1px solid #1a0f3d; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: 600; }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin: 20px 0 25px; }
        .stat-card { background: white; padding: 18px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #dc2626; }
        .stat-card .label { color: #9ca3af; font-size: 12.5px; margin-top: 4px; }
        .table-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f8f9fa; text-align: left; padding: 12px 16px; font-size: 12.5px; text-transform: uppercase; color: #6b7280; letter-spacing: .3px; }
        tbody td { padding: 12px 16px; font-size: 13.5px; color: #1f2937; border-top: 1px solid #f1f5f9; vertical-align: top; }
        .motivo { color: #6b7280; font-size: 12.5px; max-width: 260px; }
        .badge-tipo { background: #ede9fe; color: #6d28d9; padding: 3px 9px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
        .empty-state { text-align: center; padding: 60px 20px; color: #9ca3af; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-crown"></i> Super_IA</div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="<?= $is_super_admin ? 'super_admin_index.php' : ($user_role === 'supervisor' ? 'supervisor_index.php' : 'index.php') ?>" class="sidebar-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <?php if ($is_super_admin || $user_role === 'admin'): ?>
        <a href="mapa_calor.php" class="sidebar-link"><i class="fas fa-fire"></i> Mapa de Calor</a>
        <?php endif; ?>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <?php if ($is_super_admin || $user_role === 'admin'): ?>
        <a href="usuarios.php" class="sidebar-link"><i class="fas fa-users"></i> Usuarios</a>
        <?php endif; ?>
        <a href="clientes.php" class="sidebar-link"><i class="fas fa-briefcase"></i> Clientes</a>
        <a href="operaciones.php" class="sidebar-link"><i class="fas fa-handshake"></i> Operaciones</a>
        <a href="alertas.php" class="sidebar-link"><i class="fas fa-bell"></i> Alertas</a>
        <a href="tareas_descartadas.php" class="sidebar-link active"><i class="fas fa-ban"></i> Tareas Descartadas</a>
    </div>
</div>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-ban me-2"></i>Tareas Descartadas</h2>
        <div class="user-info">
            <div>
                <strong><?= htmlspecialchars($user_nombre) ?></strong><br>
                <small><?= htmlspecialchars($user_rol_label) ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-ban me-2"></i>Tareas Descartadas</h1>
            <p class="text-muted mt-2">Trámites que el asesor marcó como descartados porque el cliente o prospecto ya no quiso continuar.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card"><div class="number"><?= $total ?></div><div class="label">Total descartadas</div></div>
            <div class="stat-card"><div class="number"><?= $hoyCount ?></div><div class="label">Hoy</div></div>
            <div class="stat-card"><div class="number"><?= $mesCount ?></div><div class="label">Este mes</div></div>
        </div>

        <div class="table-card">
            <?php if (empty($descartadas)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox fa-2x mb-3"></i>
                    <p>No hay tareas descartadas por el momento.</p>
                </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Cliente / Prospecto</th>
                        <th>Tipo de trámite</th>
                        <th>Asesor</th>
                        <th>Supervisor</th>
                        <th>Programada</th>
                        <th>Descartada</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($descartadas as $d): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($d['cliente_nombre'] ?? 'Sin cliente') ?></strong>
                            <?php if (!empty($d['cliente_cedula'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($d['cliente_cedula']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge-tipo"><?= htmlspecialchars($tipo_labels[$d['tipo_tarea']] ?? $d['tipo_tarea']) ?></span></td>
                        <td><?= htmlspecialchars($d['asesor_nombre'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($d['supervisor_nombre'] ?? '—') ?></td>
                        <td><?= htmlspecialchars(trim(($d['fecha_programada'] ?? '') . ' ' . ($d['hora_programada'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars($d['descartada_at'] ?? $d['created_at'] ?? '—') ?></td>
                        <td class="motivo"><?= htmlspecialchars($d['motivo_descarte'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>

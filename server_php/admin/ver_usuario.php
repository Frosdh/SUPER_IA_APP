<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) { header('Location: usuarios.php'); exit; }

// ── Acciones POST ─────────────────────────────────────────────
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'activar') {
        $pdo->prepare("UPDATE usuarios SET activo=1 WHERE id=?")->execute([$id]);
        $msg = 'Cuenta activada correctamente.';
    } elseif ($_POST['action'] === 'desactivar') {
        $pdo->prepare("UPDATE usuarios SET activo=0 WHERE id=?")->execute([$id]);
        $msg = 'Cuenta desactivada.'; $msgType = 'warning';
    }
}

// ── Datos del usuario ─────────────────────────────────────────
$stmtU = $pdo->prepare("
    SELECT id, nombre, telefono, email, activo, saldo_billetera, creado_en, token_fcm
    FROM usuarios WHERE id = ?
");
$stmtU->execute([$id]);
$u = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$u) { header('Location: usuarios.php'); exit; }

// ── Estadísticas de viajes ────────────────────────────────────
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                               AS total_viajes,
        SUM(estado = 'terminado')                              AS completados,
        SUM(estado = 'cancelado')                              AS cancelados,
        COALESCE(SUM(CASE WHEN estado='terminado' THEN tarifa_total END), 0) AS gasto_total,
        COALESCE(AVG(CASE WHEN calificacion > 0 THEN calificacion END), 0)  AS calif_promedio
    FROM viajes WHERE usuario_id = ?
");
$stats->execute([$id]);
$s = $stats->fetch(PDO::FETCH_ASSOC);

// ── Últimos 10 viajes ─────────────────────────────────────────
$stmtV = $pdo->prepare("
    SELECT v.id, v.origen_texto, v.destino_texto, v.tarifa_total,
           v.estado, v.fecha_pedido, v.calificacion, v.descuento,
           c.nombre AS conductor_nombre
    FROM viajes v
    LEFT JOIN conductores c ON c.id = v.conductor_id
    WHERE v.usuario_id = ?
    ORDER BY v.fecha_pedido DESC
    LIMIT 10
");
$stmtV->execute([$id]);
$viajes = $stmtV->fetchAll(PDO::FETCH_ASSOC);

// ── Pendientes para badge sidebar ─────────────────────────────
$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado=0")->fetchColumn();

$currentPage = 'usuarios';

function estadoViajeLabel($estado) {
    return match($estado) {
        'terminado' => '<span class="badge bg-success">Completado</span>',
        'cancelado' => '<span class="badge bg-danger">Cancelado</span>',
        'en_camino' => '<span class="badge bg-info text-dark">En camino</span>',
        'iniciado'  => '<span class="badge bg-primary">En curso</span>',
        'aceptado'  => '<span class="badge bg-warning text-dark">Aceptado</span>',
        default     => '<span class="badge bg-secondary">Pedido</span>',
    };
}

function estrellas($n) {
    $n = round($n);
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= $i <= $n
            ? '<i class="fas fa-star text-warning"></i>'
            : '<i class="far fa-star text-warning"></i>';
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — <?= htmlspecialchars($u['nombre']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .sidebar .section-label { font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#888; padding:18px 20px 6px; }
        .content { padding:28px; }

        .info-card  { background:#fff; border-radius:14px; padding:22px; box-shadow:0 4px 16px rgba(0,0,0,.06); }
        .stat-mini  { background:#f8f9fa; border-radius:10px; padding:16px; text-align:center; }
        .stat-mini h4 { font-size:24px; font-weight:700; margin:0; color:#24243e; }
        .stat-mini p  { font-size:12px; color:#6c757d; margin:0; }

        .avatar-lg { width:80px; height:80px; border-radius:50%;
                     background:linear-gradient(135deg,#6b11ff,#9b51e0);
                     display:flex; align-items:center; justify-content:center;
                     color:#fff; font-weight:700; font-size:32px; }

        .table thead th { background:#f8f9fa; font-size:11px; text-transform:uppercase;
                          letter-spacing:.5px; color:#6c757d; border:none; padding:12px 14px; }
        .table tbody td { padding:12px 14px; vertical-align:middle; border-color:#f0f0f0; font-size:14px; }
        .table tbody tr:hover { background:#fafbff; }

        .badge-activo   { background:#d4edda; color:#155724; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; }
        .badge-inactivo { background:#f8d7da; color:#721c24; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>
        <div class="section-label">Configuración</div>
        <a href="tarifas.php"><i class="fas fa-tags"></i> Tarifas</a>
        <a href="descuentos.php"><i class="fas fa-ticket-alt"></i> Descuentos</a>
        <a href="soporte.php"><i class="fas fa-headset"></i> Soporte</a>
        <a href="reportes.php"><i class="fas fa-chart-bar"></i> Reportes</a>
        <div class="section-label">Cuenta</div>
        <a href="logout.php" style="color:#ff6b6b"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>

    <!-- ── CONTENIDO ── -->
    <div class="col-md-10 content">

        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="usuarios.php">Usuarios</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($u['nombre']) ?></li>
            </ol>
        </nav>

        <h4 class="fw-bold mb-4"><i class="fas fa-user-circle me-2 text-primary"></i>Detalle del Pasajero</h4>

        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
            <i class="fas fa-info-circle me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row g-3">

            <!-- ── Columna izquierda ── -->
            <div class="col-md-4">

                <!-- Perfil -->
                <div class="info-card text-center mb-3">
                    <div class="avatar-lg mx-auto mb-3">
                        <?= strtoupper(substr($u['nombre'], 0, 1)) ?>
                    </div>
                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($u['nombre']) ?></h5>
                    <p class="text-muted small mb-3">Pasajero Registrado</p>

                    <?php if ($u['activo']): ?>
                        <span class="badge-activo"><i class="fas fa-circle me-1" style="font-size:8px"></i>Cuenta Activa</span>
                    <?php else: ?>
                        <span class="badge-inactivo"><i class="fas fa-circle me-1" style="font-size:8px"></i>Cuenta Inactiva</span>
                    <?php endif; ?>

                    <!-- Estadísticas rápidas -->
                    <div class="row g-2 mt-3">
                        <div class="col-6">
                            <div class="stat-mini">
                                <h4><?= number_format($s['completados']) ?></h4>
                                <p>Viajes<br>completados</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <h4 class="text-success">$<?= number_format($s['gasto_total'], 0) ?></h4>
                                <p>Gasto<br>total</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <h4 class="text-danger"><?= number_format($s['cancelados']) ?></h4>
                                <p>Viajes<br>cancelados</p>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <h4 class="text-warning"><?= number_format($s['calif_promedio'], 1) ?></h4>
                                <p>Calif.<br>promedio</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Datos de contacto -->
                <div class="info-card mb-3">
                    <h6 class="fw-bold mb-3"><i class="fas fa-address-card me-2 text-primary"></i>Datos de contacto</h6>
                    <table class="table table-sm table-borderless mb-0">
                        <tr>
                            <td class="text-muted" style="width:40%"><i class="fas fa-phone me-1"></i>Teléfono</td>
                            <td class="fw-semibold"><?= htmlspecialchars($u['telefono']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fas fa-envelope me-1"></i>Email</td>
                            <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fas fa-wallet me-1"></i>Billetera</td>
                            <td class="fw-semibold text-success">$<?= number_format($u['saldo_billetera'], 2) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fas fa-calendar me-1"></i>Registro</td>
                            <td><?= date('d/m/Y', strtotime($u['creado_en'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted"><i class="fas fa-bell me-1"></i>Push</td>
                            <td>
                                <?php if ($u['token_fcm']): ?>
                                    <span class="badge bg-success">Habilitado</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Sin token</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Acciones -->
                <div class="info-card">
                    <h6 class="fw-bold mb-3"><i class="fas fa-cog me-2 text-warning"></i>Acciones</h6>
                    <?php if ($u['activo']): ?>
                    <form method="POST"
                          onsubmit="return confirm('¿Desactivar la cuenta de <?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>? No podrá solicitar viajes.')">
                        <input type="hidden" name="action" value="desactivar">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="fas fa-user-slash me-2"></i>Desactivar cuenta
                        </button>
                    </form>
                    <small class="text-muted d-block mt-2">
                        Al desactivar, el usuario no podrá iniciar sesión ni solicitar viajes.
                    </small>
                    <?php else: ?>
                    <form method="POST"
                          onsubmit="return confirm('¿Activar la cuenta de <?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>?')">
                        <input type="hidden" name="action" value="activar">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-user-check me-2"></i>Activar cuenta
                        </button>
                    </form>
                    <small class="text-muted d-block mt-2">
                        El usuario podrá volver a iniciar sesión y solicitar viajes.
                    </small>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ── Columna derecha: historial ── -->
            <div class="col-md-8">
                <div class="info-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">
                            <i class="fas fa-history me-2 text-info"></i>
                            Últimos <?= count($viajes) ?> viajes
                        </h6>
                        <small class="text-muted">Total: <?= number_format($s['total_viajes']) ?> viajes</small>
                    </div>

                    <?php if (empty($viajes)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-route fa-2x mb-2 opacity-25"></i>
                        <p class="mb-0">Este usuario aún no ha realizado ningún viaje.</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="ps-3">Hora Inicio</th>
                                    <th>Conductor</th>
                                    <th class="text-center">Tarifa</th>
                                    <th class="text-center">Calif.</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center">Fecha</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($viajes as $v): ?>
                                <tr>
                                    <td class="text-muted">#<?= $v['id'] ?></td>
                                    <td class="ps-3"><?= date('H:i', strtotime($v['fecha_pedido'])) ?></td>
                                    <td class="small"><?= htmlspecialchars($v['conductor_nombre'] ?? '—') ?></td>
                                    <td class="text-center fw-semibold text-success">
                                        $<?= number_format($v['tarifa_total'], 2) ?>
                                        <?php if ($v['descuento'] > 0): ?>
                                            <br><small class="text-muted text-decoration-line-through">
                                                -$<?= number_format($v['descuento'], 2) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($v['calificacion'] > 0): ?>
                                            <?= estrellas($v['calificacion']) ?>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= estadoViajeLabel($v['estado']) ?></td>
                                    <td class="text-center small text-muted">
                                        <?= date('d/m/Y', strtotime($v['fecha_pedido'])) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /row -->
    </div><!-- /content -->
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ── Parámetros de búsqueda y filtro ───────────────────────────
$busqueda  = trim($_GET['q']      ?? '');
$filtro    = trim($_GET['estado'] ?? 'todos');
$pagina    = max(1, intval($_GET['p'] ?? 1));
$porPagina = 15;
$offset    = ($pagina - 1) * $porPagina;

// ── Acción rápida (activar/desactivar) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $uid = intval($_POST['user_id']);
    if ($_POST['action'] === 'activar') {
        $pdo->prepare("UPDATE usuarios SET activo=1 WHERE id=?")->execute([$uid]);
    } elseif ($_POST['action'] === 'desactivar') {
        $pdo->prepare("UPDATE usuarios SET activo=0 WHERE id=?")->execute([$uid]);
    }
    // Redirigir para evitar re-POST
    $qs = http_build_query(['q' => $busqueda, 'estado' => $filtro, 'p' => $pagina]);
    header("Location: usuarios.php?$qs");
    exit;
}

// ── Construcción de la consulta ───────────────────────────────
$where  = "WHERE 1=1";
$params = [];

if ($busqueda !== '') {
    $where   .= " AND (u.nombre LIKE ? OR u.telefono LIKE ? OR u.email LIKE ?)";
    $like     = "%$busqueda%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}

if ($filtro === 'activos') {
    $where .= " AND u.activo = 1";
} elseif ($filtro === 'inactivos') {
    $where .= " AND u.activo = 0";
}

// Total para paginación
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM usuarios u $where");
$stmtTotal->execute($params);
$totalRegistros = (int) $stmtTotal->fetchColumn();
$totalPaginas   = max(1, ceil($totalRegistros / $porPagina));

// Usuarios con conteo de viajes
$sql = "
    SELECT u.id, u.nombre, u.telefono, u.email, u.activo,
           u.saldo_billetera, u.creado_en,
           COUNT(v.id)                                          AS total_viajes,
           COALESCE(SUM(v.tarifa_total), 0)                    AS gasto_total,
           MAX(v.fecha_pedido)                                  AS ultimo_viaje
    FROM usuarios u
    LEFT JOIN viajes v ON v.usuario_id = u.id AND v.estado = 'terminado'
    $where
    GROUP BY u.id
    ORDER BY u.creado_en DESC
    LIMIT $porPagina OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totales globales para las tarjetas resumen
$totales = $pdo->query("
    SELECT
        COUNT(*)                            AS total,
        SUM(activo = 1)                     AS activos,
        SUM(activo = 0)                     AS inactivos,
        SUM(saldo_billetera)                AS saldo_total
    FROM usuarios
")->fetch(PDO::FETCH_ASSOC);

// Pendientes conductores para badge
$totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado=0")->fetchColumn();

$currentPage = 'usuarios';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove Admin — Usuarios / Pasajeros</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        /* ── Tarjetas resumen ── */
        .stat-card { background:#fff; border-radius:14px; padding:22px 24px; box-shadow:0 4px 16px rgba(0,0,0,.06); }
        .stat-card h3 { font-size:30px; font-weight:700; margin:0; color:#24243e; }
        .stat-card p  { color:#6c757d; margin:0; font-size:14px; font-weight:500; }
        .icon-box { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; }
        .bg-purple { background:linear-gradient(135deg,#6b11ff,#9b51e0); }
        .bg-blue   { background:linear-gradient(135deg,#3182fe,#5b9cfc); }
        .bg-green  { background:linear-gradient(135deg,#11998e,#38ef7d); }
        .bg-orange { background:linear-gradient(135deg,#f46b45,#eea849); }

        /* ── Tabla ── */
        .table-card { background:#fff; border-radius:14px; box-shadow:0 4px 16px rgba(0,0,0,.06); overflow:hidden; }
        .table thead th { background:#f8f9fa; font-size:12px; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; border:none; padding:14px 16px; }
        .table tbody td { padding:14px 16px; vertical-align:middle; border-color:#f0f0f0; }
        .table tbody tr:hover { background:#fafbff; }

        /* ── Avatar ── */
        .avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#6b11ff,#9b51e0); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:16px; flex-shrink:0; }

        /* ── Badge estados ── */
        .badge-activo   { background:#d4edda; color:#155724; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; }
        .badge-inactivo { background:#f8d7da; color:#721c24; padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; }

        /* ── Paginación ── */
        .pagination .page-link { border-radius:8px !important; margin:0 2px; border:none; color:#6b11ff; }
        .pagination .page-item.active .page-link { background:#6b11ff; }
    </style>
</head>
<body>
<div class="container-fluid p-0">
<div class="row g-0">

<?php include '_sidebar.php'; ?>

    <!-- ── CONTENIDO ── -->
    <div class="col-md-10 content">

        <!-- Encabezado -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0"><i class="fas fa-users me-2 text-primary"></i>Usuarios / Pasajeros</h4>
                <small class="text-muted">Gestión de todas las cuentas de pasajeros registrados</small>
            </div>
        </div>

        <!-- Tarjetas resumen -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= number_format($totales['total']) ?></h3><p>Total Usuarios</p></div>
                    <div class="icon-box bg-purple"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= number_format($totales['activos']) ?></h3><p>Activos</p></div>
                    <div class="icon-box bg-green"><i class="fas fa-user-check"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3><?= number_format($totales['inactivos']) ?></h3><p>Inactivos</p></div>
                    <div class="icon-box bg-orange"><i class="fas fa-user-slash"></i></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div><h3>$<?= number_format($totales['saldo_total'], 2) ?></h3><p>Saldo Total Billeteras</p></div>
                    <div class="icon-box bg-blue"><i class="fas fa-wallet"></i></div>
                </div>
            </div>
        </div>

        <!-- Barra de búsqueda y filtros -->
        <div class="table-card mb-4">
            <div class="p-3 border-bottom">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" name="q" class="form-control border-start-0 ps-0"
                                   placeholder="Buscar por nombre, teléfono o correo..."
                                   value="<?= htmlspecialchars($busqueda) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="estado" class="form-select">
                            <option value="todos"    <?= $filtro==='todos'    ? 'selected':'' ?>>Todos los estados</option>
                            <option value="activos"  <?= $filtro==='activos'  ? 'selected':'' ?>>Solo activos</option>
                            <option value="inactivos"<?= $filtro==='inactivos'? 'selected':'' ?>>Solo inactivos</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-1"></i>Filtrar
                        </button>
                    </div>
                    <?php if ($busqueda || $filtro !== 'todos'): ?>
                    <div class="col-md-1">
                        <a href="usuarios.php" class="btn btn-outline-secondary w-100" title="Limpiar filtros">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tabla de usuarios -->
            <?php if (empty($usuarios)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-users fa-3x mb-3 opacity-25"></i>
                <p class="mb-0">No se encontraron usuarios<?= $busqueda ? " para \"$busqueda\"" : '' ?>.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Contacto</th>
                            <th class="text-center">Viajes</th>
                            <th class="text-center">Gasto Total</th>
                            <th class="text-center">Último Viaje</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $u):
                            $inicial = strtoupper(substr($u['nombre'], 0, 1));
                        ?>
                        <tr>
                            <!-- Avatar + nombre -->
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar"><?= htmlspecialchars($inicial) ?></div>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($u['nombre']) ?></div>
                                        <small class="text-muted">Desde <?= date('d/m/Y', strtotime($u['creado_en'])) ?></small>
                                    </div>
                                </div>
                            </td>
                            <!-- Contacto -->
                            <td>
                                <div><?= htmlspecialchars($u['telefono']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($u['email'] ?? '—') ?></small>
                            </td>
                            <!-- Viajes -->
                            <td class="text-center">
                                <span class="badge bg-light text-dark fs-6 px-3">
                                    <?= number_format($u['total_viajes']) ?>
                                </span>
                            </td>
                            <!-- Gasto total -->
                            <td class="text-center fw-semibold text-success">
                                $<?= number_format($u['gasto_total'], 2) ?>
                            </td>
                            <!-- Último viaje -->
                            <td class="text-center">
                                <?php if ($u['ultimo_viaje']): ?>
                                    <small><?= date('d/m/Y', strtotime($u['ultimo_viaje'])) ?></small>
                                <?php else: ?>
                                    <small class="text-muted">Sin viajes</small>
                                <?php endif; ?>
                            </td>
                            <!-- Estado -->
                            <td class="text-center">
                                <?php if ($u['activo']): ?>
                                    <span class="badge-activo"><i class="fas fa-circle me-1" style="font-size:8px"></i>Activo</span>
                                <?php else: ?>
                                    <span class="badge-inactivo"><i class="fas fa-circle me-1" style="font-size:8px"></i>Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <!-- Acciones -->
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <a href="ver_usuario.php?id=<?= $u['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Ver detalles">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" class="m-0"
                                          onsubmit="return confirm('¿<?= $u['activo'] ? 'Desactivar' : 'Activar' ?> a <?= htmlspecialchars($u['nombre'], ENT_QUOTES) ?>?')">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="action"  value="<?= $u['activo'] ? 'desactivar' : 'activar' ?>">
                                        <button type="submit"
                                                class="btn btn-sm <?= $u['activo'] ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                                title="<?= $u['activo'] ? 'Desactivar cuenta' : 'Activar cuenta' ?>">
                                            <i class="fas <?= $u['activo'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginación y contador -->
            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                <small class="text-muted">
                    Mostrando <?= ($offset + 1) ?>–<?= min($offset + $porPagina, $totalRegistros) ?>
                    de <?= number_format($totalRegistros) ?> usuarios
                </small>
                <?php if ($totalPaginas > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($i = 1; $i <= $totalPaginas; $i++):
                            $qs = http_build_query(['q' => $busqueda, 'estado' => $filtro, 'p' => $i]);
                        ?>
                        <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= $qs ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

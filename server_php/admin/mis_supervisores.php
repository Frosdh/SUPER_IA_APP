<?php
/**
 * mis_supervisores.php — Lista de supervisores a cargo del Gerente (jefe_agencia)
 */
require_once 'db_admin.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login_selector.php');
    exit;
}

$gerente_usuario_id = $_SESSION['admin_id']     ?? null;
$gerente_nombre     = $_SESSION['admin_nombre'] ?? 'Gerente';
$gerente_rol        = $_SESSION['admin_rol']    ?? 'jefe_agencia';

// ── Resolver jefe_agencia IDs según rol ──────────────────────
// jefe_agencia   → supervisor.jefe_agencia_id (1 jefe_agencia)
// gerente_general → gerente_general.unidad_bancaria_id → agencias → jefes_agencia (varios)
$ja_ids          = [];
$ja_id           = null; // primer ja_id (para compatibilidad)
$pre_sup_ids     = [];   // supervisor ids resueltos antes del filtro de búsqueda

try {
    if ($gerente_rol === 'jefe_agencia') {
        $st = $pdo->prepare('SELECT id FROM jefe_agencia WHERE usuario_id = ?');
        $st->execute([$gerente_usuario_id]);
        $ja_ids = $st->fetchAll(PDO::FETCH_COLUMN);

    } elseif ($gerente_rol === 'gerente_general') {
        $st = $pdo->prepare('SELECT unidad_bancaria_id FROM gerente_general WHERE usuario_id = ? LIMIT 1');
        $st->execute([$gerente_usuario_id]);
        $ub_id = $st->fetchColumn() ?: null;
        if ($ub_id) {
            $st = $pdo->prepare('SELECT ja.id FROM jefe_agencia ja JOIN agencia ag ON ag.id = ja.agencia_id WHERE ag.unidad_bancaria_id = ?');
            $st->execute([$ub_id]);
            $ja_ids = $st->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $ja_id = $ja_ids[0] ?? null;

    if (!empty($ja_ids)) {
        $phJa = implode(',', array_fill(0, count($ja_ids), '?'));
        $st = $pdo->prepare("SELECT id FROM supervisor WHERE jefe_agencia_id IN ($phJa)");
        $st->execute($ja_ids);
        $pre_sup_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }

} catch (PDOException $e) {
    error_log('mis_supervisores resolver: ' . $e->getMessage());
}

// ── Parámetros de búsqueda / filtro ─────────────────────────
$q          = trim($_GET['q']      ?? '');
$filtro_est = trim($_GET['estado'] ?? 'todos');

// ── Query principal: supervisores filtrados por supervisor_ids ─
$supervisores = [];
$total_sups   = 0;

if (!empty($pre_sup_ids)) {
    try {
        $phPre = implode(',', array_fill(0, count($pre_sup_ids), '?'));
        $whereExtra = '';
        $params = $pre_sup_ids;

        if ($q !== '') {
            $whereExtra .= " AND (u.nombre LIKE ? OR u.email LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($filtro_est === 'activo') {
            $whereExtra .= " AND u.activo = 1";
        } elseif ($filtro_est === 'inactivo') {
            $whereExtra .= " AND u.activo = 0";
        } elseif ($filtro_est === 'pendiente') {
            $whereExtra .= " AND u.estado_aprobacion = 'pendiente'";
        }

        $st = $pdo->prepare("
            SELECT s.id AS supervisor_table_id,
                   u.id AS usuario_id,
                   u.nombre,
                   u.email,
                   u.activo,
                   u.estado_aprobacion,
                   s.meta_asesores,
                   (SELECT COUNT(*)
                    FROM asesor a
                    JOIN usuario ua ON ua.id = a.usuario_id
                    WHERE a.supervisor_id = s.id AND ua.activo = 1
                   ) AS total_asesores,
                   (SELECT COUNT(*)
                    FROM asesor a2
                    JOIN cliente_prospecto cp ON cp.asesor_id = a2.id
                    WHERE a2.supervisor_id = s.id
                   ) AS total_clientes,
                   (SELECT COUNT(*)
                    FROM tarea t
                    JOIN asesor a3 ON a3.id = t.asesor_id
                    WHERE a3.supervisor_id = s.id
                      AND t.fecha_programada = CURDATE()
                      AND t.estado = 'completada'
                   ) AS tareas_hoy,
                   (SELECT COUNT(*)
                    FROM alerta_modificacion am
                    WHERE am.supervisor_id = s.id AND am.vista_supervisor = 0
                   ) AS alertas_sin_ver
            FROM supervisor s
            JOIN usuario u ON u.id = s.usuario_id
            WHERE s.id IN ($phPre)
            $whereExtra
            ORDER BY u.nombre ASC
        ");
        $st->execute($params);
        $supervisores = $st->fetchAll(PDO::FETCH_ASSOC);
        $total_sups   = count($supervisores);

    } catch (PDOException $e) {
        error_log('mis_supervisores error: ' . $e->getMessage());
    }
}

// ── Alertas globales para badge del sidebar ──────────────────
$alertas_pendientes_sidebar = 0;
if (!empty($supervisores)) {
    try {
        $supIds = array_column($supervisores, 'supervisor_table_id');
        $ph = implode(',', array_fill(0, count($supIds), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE supervisor_id IN ($ph) AND vista_supervisor=0");
        $st->execute($supIds);
        $alertas_pendientes_sidebar = (int)$st->fetchColumn();
    } catch (PDOException $e) {}
}

$currentPage = 'supervisores';

// Funciones helpers ─────────────────────────────────────────
function badge_estado(array $row): string {
    if ($row['estado_aprobacion'] === 'pendiente') {
        return '<span class="badge badge-warning">Pendiente</span>';
    }
    if (!$row['activo']) {
        return '<span class="badge badge-danger">Inactivo</span>';
    }
    return '<span class="badge badge-success">Activo</span>';
}
function iniciales(string $nombre): string {
    $partes = explode(' ', trim($nombre));
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini ?: '?';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mis Supervisores — Super_IA</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
<style>
/* ── Página específica ── */
.page-hero{background:linear-gradient(135deg,#0f2544 0%,#122e58 60%,#1a3a6b 100%);border-radius:20px;padding:32px 36px;margin-bottom:28px;display:flex;align-items:center;justify-content:space-between;gap:24px;border:1px solid rgba(96,165,250,.15);}
.page-hero h1{font-size:1.7rem;font-weight:900;color:#fff;margin:0 0 6px;}
.page-hero p{font-size:.9rem;color:#94a3b8;margin:0;}
.hero-icon{width:60px;height:60px;border-radius:16px;background:rgba(96,165,250,.15);display:flex;align-items:center;justify-content:center;font-size:1.6rem;color:#60a5fa;flex-shrink:0;}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap;}
.search-box{display:flex;align-items:center;gap:8px;background:#112035;border:1px solid rgba(96,165,250,.2);border-radius:10px;padding:8px 14px;flex:1;min-width:200px;max-width:340px;}
.search-box input{background:none;border:none;outline:none;color:#e2e8f0;font-size:.88rem;width:100%;}
.search-box input::placeholder{color:#475569;}
.filter-btns{display:flex;gap:6px;flex-wrap:wrap;}
.fbtn{padding:7px 15px;border-radius:9px;font-size:.8rem;font-weight:700;border:1px solid rgba(96,165,250,.2);background:#0f2544;color:#94a3b8;cursor:pointer;transition:.2s;text-decoration:none;}
.fbtn.active,.fbtn:hover{background:rgba(96,165,250,.15);color:#60a5fa;border-color:#60a5fa;}
.fbtn-add{background:rgba(96,165,250,.15);color:#60a5fa;border-color:#60a5fa;display:flex;align-items:center;gap:6px;}
.fbtn-add:hover{background:rgba(96,165,250,.3);}

/* Stats row */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px;}
.stat-mini{background:#112035;border:1px solid rgba(96,165,250,.12);border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:14px;}
.stat-mini-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.smi-blue{background:rgba(96,165,250,.12);color:#60a5fa;}
.smi-green{background:rgba(52,211,153,.1);color:#34d399;}
.smi-purple{background:rgba(167,139,250,.1);color:#a78bfa;}
.smi-red{background:rgba(248,113,113,.1);color:#f87171;}
.stat-mini-val{font-size:1.4rem;font-weight:900;color:#e2e8f0;line-height:1;}
.stat-mini-lbl{font-size:.75rem;color:#64748b;margin-top:3px;}

/* Cards grid */
.sups-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-bottom:32px;}
.sup-card{background:#112035;border:1px solid rgba(96,165,250,.12);border-radius:16px;padding:22px;transition:border-color .2s,transform .2s;}
.sup-card:hover{border-color:rgba(96,165,250,.4);transform:translateY(-2px);}
.sup-card-header{display:flex;align-items:center;gap:14px;margin-bottom:16px;}
.sup-avatar{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#1e40af,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;color:#fff;flex-shrink:0;letter-spacing:.5px;}
.sup-name{font-size:.98rem;font-weight:800;color:#e2e8f0;line-height:1.2;}
.sup-email{font-size:.75rem;color:#64748b;margin-top:2px;}
.sup-card-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;}
.sc-stat{background:rgba(15,37,68,.7);border-radius:10px;padding:10px 8px;text-align:center;}
.sc-stat-val{font-size:1.1rem;font-weight:800;color:#e2e8f0;}
.sc-stat-lbl{font-size:.68rem;color:#64748b;margin-top:2px;}
.sup-card-footer{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid rgba(96,165,250,.08);}
.sup-actions{display:flex;gap:6px;}
.btn-sm{padding:5px 12px;border-radius:8px;font-size:.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;transition:.18s;border:none;cursor:pointer;}
.btn-outline{background:rgba(96,165,250,.08);color:#60a5fa;border:1px solid rgba(96,165,250,.2);}
.btn-outline:hover{background:rgba(96,165,250,.2);}
.btn-danger-sm{background:rgba(239,68,68,.08);color:#f87171;border:1px solid rgba(239,68,68,.2);}
.btn-danger-sm:hover{background:rgba(239,68,68,.18);}

/* Badges */
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;}
.badge-success{background:rgba(52,211,153,.12);color:#34d399;}
.badge-danger{background:rgba(248,113,113,.1);color:#f87171;}
.badge-warning{background:rgba(251,191,36,.12);color:#fbbf24;}

/* Alerta badge */
.alert-chip{display:inline-flex;align-items:center;gap:4px;background:rgba(239,68,68,.12);color:#f87171;border-radius:8px;font-size:.7rem;font-weight:700;padding:2px 8px;}

/* Tabla (vista alternativa) */
.table-container{background:#112035;border:1px solid rgba(96,165,250,.1);border-radius:16px;overflow:hidden;}
.table-header{padding:16px 20px;border-bottom:1px solid rgba(96,165,250,.1);display:flex;align-items:center;justify-content:space-between;}
.table-header h3{font-size:1rem;font-weight:800;color:#e2e8f0;margin:0;}
table.t-sups{width:100%;border-collapse:collapse;}
table.t-sups th{padding:11px 16px;text-align:left;font-size:.75rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid rgba(96,165,250,.08);}
table.t-sups td{padding:12px 16px;font-size:.85rem;color:#cbd5e1;border-bottom:1px solid rgba(96,165,250,.06);vertical-align:middle;}
table.t-sups tr:last-child td{border-bottom:none;}
table.t-sups tr:hover td{background:rgba(96,165,250,.04);}
.td-name{font-weight:700;color:#e2e8f0;}
.td-email{font-size:.78rem;color:#64748b;}
.num-chip{display:inline-flex;align-items:center;gap:4px;background:rgba(96,165,250,.08);color:#60a5fa;border-radius:7px;padding:2px 9px;font-size:.78rem;font-weight:700;}

/* Estado vacío */
.empty-state{text-align:center;padding:60px 20px;}
.empty-icon{font-size:3rem;color:#1e3a5f;margin-bottom:16px;}
.empty-state h3{font-size:1.1rem;font-weight:700;color:#475569;margin:0 0 8px;}
.empty-state p{font-size:.85rem;color:#334155;}

/* Responsive */
@media(max-width:900px){
    .stats-row{grid-template-columns:1fr 1fr;}
    .page-hero{flex-direction:column;align-items:flex-start;}
}
@media(max-width:600px){
    .stats-row{grid-template-columns:1fr;}
    .sups-grid{grid-template-columns:1fr;}
    .toolbar{flex-direction:column;align-items:stretch;}
    .search-box{max-width:100%;}
}
</style>
</head>
<body>
<?php
// Variables para el sidebar
$alertas_pendientes = $alertas_pendientes_sidebar;
require_once '_sidebar_gerente.php';
?>

<!-- ══════════ CONTENIDO PRINCIPAL ══════════ -->

<!-- Hero -->
<div class="page-hero">
    <div>
        <h1><i class="fas fa-users-gear" style="color:#60a5fa;margin-right:10px;"></i>Mis Supervisores</h1>
        <p>Supervisores activos bajo tu gestión · <?= date('d/m/Y') ?></p>
    </div>
    <div class="hero-icon"><i class="fas fa-users-gear"></i></div>
</div>

<?php
// ── Totales para stats row ───────────────────────────────────
$total_asesores_suma = array_sum(array_column($supervisores, 'total_asesores'));
$total_clientes_suma = array_sum(array_column($supervisores, 'total_clientes'));
$total_alertas_suma  = array_sum(array_column($supervisores, 'alertas_sin_ver'));
?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-mini">
        <div class="stat-mini-icon smi-blue"><i class="fas fa-users-gear"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_sups ?></div>
            <div class="stat-mini-lbl">Supervisores</div>
        </div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-icon smi-green"><i class="fas fa-user-tie"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_asesores_suma ?></div>
            <div class="stat-mini-lbl">Asesores activos</div>
        </div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-icon smi-purple"><i class="fas fa-address-book"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_clientes_suma ?></div>
            <div class="stat-mini-lbl">Clientes totales</div>
        </div>
    </div>
    <div class="stat-mini">
        <div class="stat-mini-icon smi-red"><i class="fas fa-bell"></i></div>
        <div>
            <div class="stat-mini-val"><?= $total_alertas_suma ?></div>
            <div class="stat-mini-lbl">Alertas sin ver</div>
        </div>
    </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <form method="get" action="" style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;">
        <div class="search-box">
            <i class="fas fa-search" style="color:#475569;"></i>
            <input type="text" name="q" placeholder="Buscar supervisor..." value="<?= htmlspecialchars($q) ?>">
        </div>
        <div class="filter-btns">
            <?php
            $estados = ['todos' => 'Todos', 'activo' => 'Activos', 'inactivo' => 'Inactivos', 'pendiente' => 'Pendientes'];
            foreach ($estados as $val => $lbl):
                $extra = ($filtro_est === $val) ? ' active' : '';
            ?>
            <button type="submit" name="estado" value="<?= $val ?>" class="fbtn<?= $extra ?>"><?= $lbl ?></button>
            <?php endforeach; ?>
        </div>
    </form>
    <a href="registro_supervisor.php" class="fbtn fbtn-add">
        <i class="fas fa-user-plus"></i> Agregar Supervisor
    </a>
</div>

<?php if (empty($supervisores)): ?>
<!-- Estado vacío -->
<div class="empty-state">
    <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
    <?php if ($q || $filtro_est !== 'todos'): ?>
        <h3>Sin resultados</h3>
        <p>No se encontraron supervisores con esos filtros. <a href="mis_supervisores.php" style="color:#60a5fa;">Limpiar filtros</a></p>
    <?php elseif (empty($pre_sup_ids)): ?>
        <h3>Sin supervisores asignados</h3>
        <p>Tu cuenta aún no tiene supervisores a cargo. Verifica que tu perfil esté vinculado a una agencia o contacta al administrador.</p>
    <?php else: ?>
        <h3>No hay supervisores registrados</h3>
        <p>Aún no tienes supervisores asignados. <a href="registro_supervisor.php" style="color:#60a5fa;">Agregar el primero</a></p>
    <?php endif; ?>
</div>

<?php else: ?>

<!-- Cards Grid -->
<div class="sups-grid">
<?php foreach ($supervisores as $sup):
    $nombre    = $sup['nombre'] ?? '—';
    $email     = $sup['email']  ?? '';
    $activo    = (bool)($sup['activo'] ?? 0);
    $aprobado  = $sup['estado_aprobacion'] ?? 'pendiente';
    $asesores_ = (int)($sup['total_asesores'] ?? 0);
    $clientes_ = (int)($sup['total_clientes'] ?? 0);
    $tareas_   = (int)($sup['tareas_hoy']     ?? 0);
    $alertas_  = (int)($sup['alertas_sin_ver'] ?? 0);
    $meta_     = (int)($sup['meta_asesores']  ?? 0);
    $uid       = $sup['usuario_id']         ?? '';
    $sid       = $sup['supervisor_table_id'] ?? '';
?>
<div class="sup-card">
    <div class="sup-card-header">
        <div class="sup-avatar"><?= iniciales($nombre) ?></div>
        <div style="flex:1;min-width:0;">
            <div class="sup-name"><?= htmlspecialchars($nombre) ?></div>
            <div class="sup-email"><?= htmlspecialchars($email) ?></div>
            <div style="margin-top:5px;">
                <?= badge_estado($sup) ?>
                <?php if ($alertas_ > 0): ?>
                <span class="alert-chip" style="margin-left:4px;"><i class="fas fa-bell"></i> <?= $alertas_ ?> alerta<?= $alertas_ > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sup-card-stats">
        <div class="sc-stat">
            <div class="sc-stat-val" style="color:#60a5fa;"><?= $asesores_ ?></div>
            <div class="sc-stat-lbl">Asesores</div>
        </div>
        <div class="sc-stat">
            <div class="sc-stat-val" style="color:#34d399;"><?= $clientes_ ?></div>
            <div class="sc-stat-lbl">Clientes</div>
        </div>
        <div class="sc-stat">
            <div class="sc-stat-val" style="color:#a78bfa;"><?= $tareas_ ?></div>
            <div class="sc-stat-lbl">Tareas hoy</div>
        </div>
    </div>

    <?php if ($meta_ > 0): ?>
    <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="font-size:.73rem;color:#64748b;">Meta asesores</span>
            <span style="font-size:.73rem;font-weight:700;color:#94a3b8;"><?= $asesores_ ?> / <?= $meta_ ?></span>
        </div>
        <div style="height:5px;background:rgba(96,165,250,.1);border-radius:3px;overflow:hidden;">
            <div style="height:100%;width:<?= min(100, $meta_ > 0 ? round($asesores_*100/$meta_) : 0) ?>%;background:linear-gradient(90deg,#3b82f6,#60a5fa);border-radius:3px;"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sup-card-footer">
        <div class="sup-actions">
            <a href="perfil_supervisor.php?id=<?= urlencode($uid) ?>" class="btn-sm btn-outline">
                <i class="fas fa-eye"></i> Ver perfil
            </a>
            <?php if ($alertas_ > 0): ?>
            <a href="alertas.php?supervisor_id=<?= urlencode($sid) ?>" class="btn-sm btn-danger-sm">
                <i class="fas fa-bell"></i> Alertas
            </a>
            <?php endif; ?>
        </div>
        <span style="font-size:.72rem;color:#334155;"><?= $activo ? 'En línea' : 'Inactivo' ?></span>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Tabla compacta debajo de las cards -->
<div class="table-container">
    <div class="table-header">
        <h3><i class="fas fa-table-list" style="color:#60a5fa;margin-right:8px;"></i>Resumen detallado</h3>
        <span style="font-size:.8rem;color:#475569;"><?= $total_sups ?> supervisor<?= $total_sups !== 1 ? 'es' : '' ?></span>
    </div>
    <div style="overflow-x:auto;">
    <table class="t-sups">
        <thead>
            <tr>
                <th>Supervisor</th>
                <th>Estado</th>
                <th>Asesores</th>
                <th>Clientes</th>
                <th>Alertas</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($supervisores as $sup):
            $uid2 = $sup['usuario_id']         ?? '';
            $sid2 = $sup['supervisor_table_id'] ?? '';
        ?>
        <tr>
            <td>
                <div class="td-name"><?= htmlspecialchars($sup['nombre'] ?? '—') ?></div>
                <div class="td-email"><?= htmlspecialchars($sup['email'] ?? '') ?></div>
            </td>
            <td><?= badge_estado($sup) ?></td>
            <td><span class="num-chip"><i class="fas fa-user-tie"></i> <?= (int)($sup['total_asesores'] ?? 0) ?></span></td>
            <td><span class="num-chip" style="color:#34d399;background:rgba(52,211,153,.08);"><i class="fas fa-address-book"></i> <?= (int)($sup['total_clientes'] ?? 0) ?></span></td>
            <td>
                <?php $al = (int)($sup['alertas_sin_ver'] ?? 0); ?>
                <?php if ($al > 0): ?>
                <span class="num-chip" style="color:#f87171;background:rgba(248,113,113,.08);">
                    <i class="fas fa-bell"></i> <?= $al ?>
                </span>
                <?php else: ?>
                <span style="color:#334155;font-size:.78rem;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:6px;">
                    <a href="perfil_supervisor.php?id=<?= urlencode($uid2) ?>" class="btn-sm btn-outline" title="Ver perfil">
                        <i class="fas fa-eye"></i>
                    </a>
                    <?php if ((int)($sup['alertas_sin_ver'] ?? 0) > 0): ?>
                    <a href="alertas.php?supervisor_id=<?= urlencode($sid2) ?>" class="btn-sm btn-danger-sm" title="Ver alertas">
                        <i class="fas fa-bell"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div><!-- .content-area -->
</div><!-- .main-content -->
</body>
</html>

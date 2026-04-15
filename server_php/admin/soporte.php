<?php
require_once 'db_admin.php';

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSuperAdmin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$isAdmin && !$isSuperAdmin) {
    header('Location: login.php');
    exit;
}

$msg = ''; $msgType = 'success';

// ══════════════════════════════════════════════════════════════
//  ACCIONES POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Responder ticket ──
    if ($_POST['action'] === 'responder') {
        $id        = intval($_POST['ticket_id']);
        $respuesta = trim($_POST['respuesta']);
        $estado    = $_POST['estado_nuevo'] ?? 'resuelto';

        if ($respuesta === '') {
            $msg = 'La respuesta no puede estar vacía.'; $msgType = 'danger';
        } else {
            $pdo->prepare("
                UPDATE tickets_soporte
                SET respuesta=?, estado=?, respondido_en=NOW()
                WHERE id=?
            ")->execute([$respuesta, $estado, $id]);
            $msg = 'Respuesta enviada correctamente.';
        }
    }

    // ── Cambiar estado ──
    elseif ($_POST['action'] === 'cambiar_estado') {
        $id     = intval($_POST['ticket_id']);
        $estado = $_POST['estado'];
        $pdo->prepare("UPDATE tickets_soporte SET estado=? WHERE id=?")->execute([$estado, $id]);
        $msg = 'Estado actualizado.'; $msgType = 'warning';
    }

    // ── Eliminar ──
    elseif ($_POST['action'] === 'eliminar') {
        $id = intval($_POST['ticket_id']);
        $pdo->prepare("DELETE FROM tickets_soporte WHERE id=?")->execute([$id]);
        $msg = 'Ticket eliminado.'; $msgType = 'warning';
    }
}

// ══════════════════════════════════════════════════════════════
//  DATOS
// ══════════════════════════════════════════════════════════════
$filtroEstado = $_GET['estado'] ?? 'todos';
$filtroTipo   = $_GET['tipo']   ?? 'todos';
$busqueda     = trim($_GET['q'] ?? '');
$pagina       = max(1, intval($_GET['p'] ?? 1));
$porPagina    = 12;
$offset       = ($pagina - 1) * $porPagina;

$where  = "WHERE 1=1";
$params = [];

if ($filtroEstado !== 'todos') {
    $where .= " AND t.estado = :estado";
    $params[':estado'] = $filtroEstado;
}
if ($filtroTipo !== 'todos') {
    $where .= " AND t.tipo = :tipo";
    $params[':tipo'] = $filtroTipo;
}
if ($busqueda !== '') {
    $where .= " AND (u.nombre LIKE :q OR t.asunto LIKE :q2 OR u.telefono LIKE :q3)";
    $like = "%$busqueda%";
    $params[':q'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
}

// Total
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM tickets_soporte t LEFT JOIN usuarios u ON u.id=t.usuario_id $where");
$stmtCount->execute($params);
$totalRegistros = (int)$stmtCount->fetchColumn();
$totalPaginas   = max(1, ceil($totalRegistros / $porPagina));

// Tickets
$sql = "
    SELECT t.*, u.nombre AS usuario_nombre, u.telefono AS usuario_telefono
    FROM tickets_soporte t
    LEFT JOIN usuarios u ON u.id = t.usuario_id
    $where
    ORDER BY
        CASE t.estado WHEN 'abierto' THEN 1 WHEN 'en_proceso' THEN 2 ELSE 3 END,
        t.creado_en DESC
    LIMIT $porPagina OFFSET $offset
";
$stmtT = $pdo->prepare($sql);
$stmtT->execute($params);
$tickets = $stmtT->fetchAll();

// Resumen por estado
$resumen = $pdo->query("
    SELECT estado, COUNT(*) AS total FROM tickets_soporte GROUP BY estado
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalAbiertos   = $resumen['abierto']     ?? 0;
$totalEnProceso  = $resumen['en_proceso']  ?? 0;
$totalResueltos  = $resumen['resuelto']    ?? 0;
$totalCerrados   = $resumen['cerrado']     ?? 0;

try {
    $totalPendientes = $pdo->query("SELECT COUNT(*) FROM conductores WHERE verificado=0")->fetchColumn();
} catch (Exception $e) {
    $totalPendientes = 0;
}

$currentPage = 'soporte';

// Helpers
$tiposLabel = [
    'problema_tecnico' => ['label' => 'Problema técnico', 'icon' => 'fa-bug',        'color' => 'danger'],
    'pago'             => ['label' => 'Pago',              'icon' => 'fa-credit-card','color' => 'warning'],
    'conductor'        => ['label' => 'Conductor',         'icon' => 'fa-car',        'color' => 'info'],
    'cuenta'           => ['label' => 'Cuenta',            'icon' => 'fa-user',       'color' => 'primary'],
    'otro'             => ['label' => 'Otro',              'icon' => 'fa-question',   'color' => 'secondary'],
];

$estadosLabel = [
    'abierto'    => ['label' => 'Abierto',     'color' => 'danger'],
    'en_proceso' => ['label' => 'En proceso',  'color' => 'warning'],
    'resuelto'   => ['label' => 'Resuelto',    'color' => 'success'],
    'cerrado'    => ['label' => 'Cerrado',     'color' => 'secondary'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA Admin — Soporte</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin.css">
    <style>
        .stat-card { background:#fff; border-radius:14px; padding:18px 20px; box-shadow:0 4px 16px rgba(0,0,0,.06); cursor:pointer; transition:.2s; border:2px solid transparent; }
        .stat-card:hover { border-color:#6b11ff33; }
        .stat-card.active-filter { border-color:#6b11ff; }
        .stat-card h3 { font-size:26px; font-weight:700; margin:0; }
        .stat-card p  { color:#6c757d; margin:3px 0 0; font-size:13px; }
        .icon-box { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; flex-shrink:0; }
        .bg-red    { background:linear-gradient(135deg,#e53935,#e35d5b); }
        .bg-orange { background:linear-gradient(135deg,#f46b45,#eea849); }
        .bg-green  { background:linear-gradient(135deg,#11998e,#38ef7d); }
        .bg-gray   { background:linear-gradient(135deg,#636e72,#b2bec3); }

        /* Ticket card */
        .ticket-card { background:#fff; border-radius:14px; padding:18px 20px; box-shadow:0 3px 12px rgba(0,0,0,.06); border-left:4px solid #dee2e6; transition:.2s; }
        .ticket-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); }
        .ticket-card.estado-abierto    { border-color:#e53935; }
        .ticket-card.estado-en_proceso { border-color:#f46b45; }
        .ticket-card.estado-resuelto   { border-color:#11998e; }
        .ticket-card.estado-cerrado    { border-color:#b2bec3; }

        .ticket-card .ticket-id   { font-size:11px; color:#aaa; font-family:monospace; }
        .ticket-card .ticket-asunto { font-weight:700; font-size:15px; color:#24243e; margin:4px 0; }
        .ticket-card .ticket-meta { font-size:12px; color:#6c757d; }
        .ticket-card .ticket-preview { font-size:13px; color:#495057; margin-top:8px; background:#f8f9fa; border-radius:8px; padding:8px 12px; }
        .ticket-card .ticket-respuesta { font-size:13px; color:#155724; background:#d4edda; border-radius:8px; padding:8px 12px; margin-top:8px; }

        .badge-tipo { padding:3px 8px; border-radius:20px; font-size:11px; font-weight:600; }

        .form-control:focus, .form-select:focus, .form-check-input:focus {
            border-color:#6b11ff; box-shadow:0 0 0 .2rem rgba(107,17,255,.15);
        }
        .btn-purple { background:#6b11ff; color:#fff; border:none; }
        .btn-purple:hover { background:#5a0de0; color:#fff; }

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

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold mb-0"><i class="fas fa-headset me-2 text-primary"></i>Soporte y Tickets</h4>
                <small class="text-muted">Gestiona las solicitudes de ayuda de los pasajeros</small>
            </div>
        </div>

        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
            <i class="fas fa-<?= $msgType==='success' ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- ── Tarjetas resumen (clickeables) ── -->
        <div class="row g-3 mb-4">
            <?php
            $cards = [
                ['todos',      'Todos',       $totalAbiertos+$totalEnProceso+$totalResueltos+$totalCerrados, 'bg-gray',   'fa-inbox'],
                ['abierto',    'Abiertos',    $totalAbiertos,   'bg-red',    'fa-envelope-open'],
                ['en_proceso', 'En proceso',  $totalEnProceso,  'bg-orange', 'fa-spinner'],
                ['resuelto',   'Resueltos',   $totalResueltos,  'bg-green',  'fa-check-circle'],
            ];
            foreach ($cards as [$key, $lbl, $n, $bg, $ico]):
                $qs = http_build_query(['estado'=>$key,'tipo'=>$filtroTipo,'q'=>$busqueda]);
            ?>
            <div class="col-md-3">
                <a href="?<?= $qs ?>" class="text-decoration-none">
                    <div class="stat-card d-flex justify-content-between align-items-center <?= $filtroEstado===$key ? 'active-filter' : '' ?>">
                        <div>
                            <h3 class="<?= $key==='abierto' && $n>0 ? 'text-danger' : '' ?>"><?= $n ?></h3>
                            <p><?= $lbl ?></p>
                        </div>
                        <div class="icon-box <?= $bg ?>"><i class="fas <?= $ico ?>"></i></div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Filtros y búsqueda ── -->
        <div class="bg-white rounded-3 shadow-sm p-3 mb-4">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="q" class="form-control border-start-0 ps-0"
                               placeholder="Buscar por pasajero, teléfono o asunto..."
                               value="<?= htmlspecialchars($busqueda) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="tipo" class="form-select">
                        <option value="todos" <?= $filtroTipo==='todos' ? 'selected':'' ?>>Todos los tipos</option>
                        <?php foreach ($tiposLabel as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $filtroTipo===$k ? 'selected':'' ?>><?= $v['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="estado" value="<?= htmlspecialchars($filtroEstado) ?>">
                <div class="col-md-2">
                    <button type="submit" class="btn btn-purple w-100">
                        <i class="fas fa-filter me-1"></i>Filtrar
                    </button>
                </div>
                <?php if ($busqueda || $filtroTipo !== 'todos' || $filtroEstado !== 'todos'): ?>
                <div class="col-md-2">
                    <a href="soporte.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-times me-1"></i>Limpiar
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- ── Lista de tickets ── -->
        <?php if (empty($tickets)): ?>
        <div class="text-center py-5 text-muted bg-white rounded-3 shadow-sm">
            <i class="fas fa-headset fa-3x mb-3 opacity-25"></i>
            <p class="mb-0 fw-semibold">No hay tickets<?= $filtroEstado !== 'todos' ? " en este estado" : "" ?>.</p>
            <small>Cuando los pasajeros envíen solicitudes de soporte, aparecerán aquí.</small>
        </div>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($tickets as $t):
                $tipo_info   = $tiposLabel[$t['tipo']]   ?? $tiposLabel['otro'];
                $estado_info = $estadosLabel[$t['estado']] ?? $estadosLabel['abierto'];
                $tiempoDesde = function($fecha) {
                    $diff = time() - strtotime($fecha);
                    if ($diff < 3600)   return round($diff/60).'m atrás';
                    if ($diff < 86400)  return round($diff/3600).'h atrás';
                    return round($diff/86400).'d atrás';
                };
            ?>
            <div class="col-md-6">
                <div class="ticket-card estado-<?= $t['estado'] ?>">
                    <!-- Encabezado -->
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="ticket-id">#<?= str_pad($t['id'], 5, '0', STR_PAD_LEFT) ?></span>
                            <div class="ticket-asunto"><?= htmlspecialchars($t['asunto']) ?></div>
                            <div class="ticket-meta">
                                <i class="fas fa-user me-1"></i><?= htmlspecialchars($t['usuario_nombre'] ?? '—') ?>
                                &nbsp;·&nbsp;
                                <i class="fas fa-phone me-1"></i><?= htmlspecialchars($t['usuario_telefono'] ?? '—') ?>
                                &nbsp;·&nbsp;
                                <i class="fas fa-clock me-1"></i><?= $tiempoDesde($t['creado_en']) ?>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge bg-<?= $estado_info['color'] ?>">
                                <?= $estado_info['label'] ?>
                            </span>
                            <span class="badge-tipo bg-<?= $tipo_info['color'] ?>-subtle text-<?= $tipo_info['color'] ?>">
                                <i class="fas <?= $tipo_info['icon'] ?> me-1"></i><?= $tipo_info['label'] ?>
                            </span>
                        </div>
                    </div>

                    <!-- Mensaje del usuario -->
                    <div class="ticket-preview">
                        <i class="fas fa-quote-left text-muted me-1" style="font-size:10px"></i>
                        <?= htmlspecialchars(mb_substr($t['mensaje'], 0, 120)) ?>
                        <?= mb_strlen($t['mensaje']) > 120 ? '...' : '' ?>
                    </div>

                    <!-- Respuesta si existe -->
                    <?php if ($t['respuesta']): ?>
                    <div class="ticket-respuesta">
                        <i class="fas fa-reply me-1"></i>
                        <strong>Tu respuesta:</strong> <?= htmlspecialchars(mb_substr($t['respuesta'], 0, 100)) ?>
                        <?= mb_strlen($t['respuesta']) > 100 ? '...' : '' ?>
                        <div class="text-muted" style="font-size:11px">
                            <?= date('d/m/Y H:i', strtotime($t['respondido_en'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Acciones -->
                    <div class="d-flex gap-2 mt-3 flex-wrap">
                        <!-- Botón responder / ver completo -->
                        <button class="btn btn-sm btn-purple"
                                onclick="abrirTicket(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)">
                            <i class="fas fa-<?= $t['respuesta'] ? 'eye' : 'reply' ?> me-1"></i>
                            <?= $t['respuesta'] ? 'Ver / Actualizar' : 'Responder' ?>
                        </button>

                        <!-- Cambio rápido de estado -->
                        <?php if ($t['estado'] !== 'cerrado'): ?>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action"    value="cambiar_estado">
                            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                            <input type="hidden" name="estado"    value="cerrado">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                    onclick="return confirm('¿Cerrar este ticket?')">
                                <i class="fas fa-times me-1"></i>Cerrar
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- Eliminar -->
                        <form method="POST" class="m-0 ms-auto">
                            <input type="hidden" name="action"    value="eliminar">
                            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('¿Eliminar ticket #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?>?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Paginación -->
        <?php if ($totalPaginas > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-4">
            <small class="text-muted">
                Mostrando <?= ($offset+1) ?>–<?= min($offset+$porPagina, $totalRegistros) ?>
                de <?= $totalRegistros ?> tickets
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i=1; $i<=$totalPaginas; $i++):
                        $qs = http_build_query(['estado'=>$filtroEstado,'tipo'=>$filtroTipo,'q'=>$busqueda,'p'=>$i]);
                    ?>
                    <li class="page-item <?= $i===$pagina ? 'active':'' ?>">
                        <a class="page-link" href="?<?= $qs ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /content -->
</div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Ver y responder ticket
══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalTicket" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0" id="modalTicketTitulo">—</h5>
                    <small class="text-muted" id="modalTicketMeta">—</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Mensaje original -->
                <div class="mb-3">
                    <label class="fw-semibold small text-muted">MENSAJE DEL PASAJERO</label>
                    <div id="modalTicketMensaje" class="p-3 bg-light rounded-3 mt-1" style="font-size:14px;white-space:pre-wrap"></div>
                </div>

                <!-- Respuesta anterior (si existe) -->
                <div id="respuestaAnteriorBox" class="mb-3" style="display:none">
                    <label class="fw-semibold small text-muted">RESPUESTA ANTERIOR</label>
                    <div id="respuestaAnterior" class="p-3 bg-success bg-opacity-10 border border-success-subtle rounded-3 mt-1" style="font-size:14px;white-space:pre-wrap"></div>
                </div>

                <!-- Formulario de respuesta -->
                <form method="POST" id="formResponder">
                    <input type="hidden" name="action"    value="responder">
                    <input type="hidden" name="ticket_id" id="modalTicketId">
                    <div class="mb-3">
                        <label class="fw-semibold">Tu respuesta <span class="text-danger">*</span></label>
                        <textarea name="respuesta" id="modalRespuesta" class="form-control mt-1"
                                  rows="4" placeholder="Escribe tu respuesta al pasajero..." required></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="fw-semibold small">Cambiar estado a:</label>
                            <select name="estado_nuevo" class="form-select form-select-sm mt-1">
                                <option value="en_proceso">En proceso</option>
                                <option value="resuelto" selected>Resuelto</option>
                                <option value="cerrado">Cerrado</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="formResponder" class="btn btn-purple px-4">
                    <i class="fas fa-paper-plane me-2"></i>Enviar respuesta
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const tiposLabel = <?= json_encode(array_map(fn($v) => $v['label'], $tiposLabel)) ?>;
const estadosLabel = <?= json_encode(array_map(fn($v) => $v['label'], $estadosLabel)) ?>;

function abrirTicket(t) {
    document.getElementById('modalTicketId').value    = t.id;
    document.getElementById('modalTicketTitulo').textContent =
        '#' + String(t.id).padStart(5,'0') + ' — ' + t.asunto;
    document.getElementById('modalTicketMeta').textContent =
        (t.usuario_nombre || '—') + ' · ' + (t.usuario_telefono || '—') +
        ' · ' + new Date(t.creado_en).toLocaleDateString('es-EC');
    document.getElementById('modalTicketMensaje').textContent = t.mensaje;

    const raBox = document.getElementById('respuestaAnteriorBox');
    if (t.respuesta) {
        document.getElementById('respuestaAnterior').textContent = t.respuesta;
        raBox.style.display = 'block';
        document.getElementById('modalRespuesta').value = t.respuesta;
    } else {
        raBox.style.display = 'none';
        document.getElementById('modalRespuesta').value = '';
    }

    new bootstrap.Modal(document.getElementById('modalTicket')).show();
}
</script>
</body>
</html>

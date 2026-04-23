<?php
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id'];
$asesor_nombre = $_SESSION['asesor_nombre'] ?? 'Asesor';

$asesor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$asesor_usuario_id]);
    $asesor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {
}

/* ---------- Filtros ---------- */
$filtro = $_GET['filtro'] ?? 'hoy';   // hoy | semana | pendientes | todas
$tipo = $_GET['tipo'] ?? '';
$q = trim($_GET['q'] ?? '');

$where = ['t.asesor_id = :a'];
$params = [':a' => $asesor_table_id];

if ($filtro === 'hoy') {
    $where[] = 't.fecha_programada = :hoy';
    $params[':hoy'] = date('Y-m-d');
} elseif ($filtro === 'semana') {
    $where[] = 't.fecha_programada BETWEEN :ini AND :fin';
    $params[':ini'] = date('Y-m-d');
    $params[':fin'] = date('Y-m-d', strtotime('+7 days'));
} elseif ($filtro === 'pendientes') {
    $where[] = "t.estado IN ('programada','en_proceso','postergada')";
}
if ($tipo) {
    $where[] = 't.tipo_tarea = :tipo';
    $params[':tipo'] = $tipo;
}
if ($q !== '') {
    $where[] = '(cp.nombre LIKE :q OR cp.cedula LIKE :q OR cp.telefono LIKE :q)';
    $params[':q'] = "%$q%";
}

$tareas = [];
try {
    $sql = "
        SELECT t.*, cp.nombre AS cliente_nombre, cp.cedula, cp.telefono, cp.ciudad AS cli_ciudad
        FROM tarea t
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY (t.estado='completada') ASC, t.fecha_programada ASC, t.hora_programada ASC
        LIMIT 200
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $tareas = $st->fetchAll();
} catch (PDOException $e) {
}

$tareas_pendientes = 0;
foreach ($tareas as $t)
    if (($t['estado'] ?? '') !== 'completada')
        $tareas_pendientes++;

/* alertas para el badge del sidebar */
$alertas_pendientes = 0;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE asesor_id = ? AND vista_supervisor = 0");
        $st->execute([$asesor_table_id]);
        $alertas_pendientes = (int) $st->fetchColumn();
    }
} catch (PDOException $e) {
}

$currentPage = 'tareas';

$tipo_labels = [
    'prospecto_nuevo' => ['Prospecto', 'fa-user-plus', '#7c3aed'],
    'visita_frio' => ['Visita en frío', 'fa-snowflake', '#0ea5e9'],
    'evaluacion' => ['Evaluación', 'fa-clipboard-check', '#d97706'],
    'recuperacion' => ['Recuperación', 'fa-undo', '#dc2626'],
    'post_venta' => ['Post venta', 'fa-headset', '#10b981'],
    'represtamo' => ['Represtamo', 'fa-rotate', '#6366f1'],
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tareas Pendientes — Asesor</title>
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
            --brand-bg: #f4f6f9;
            --brand-shadow-sm: 0 4px 12px rgba(18, 58, 109, .06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', sans-serif;
            background: var(--brand-bg);
            display: flex;
            min-height: 100vh;
            color: var(--brand-navy-deep);
        }

        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%);
            color: #fff;
            padding: 20px 0;
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            z-index: 100;
        }

        .sidebar-brand {
            padding: 0 20px 24px;
            font-size: 18px;
            font-weight: 800;
            border-bottom: 1px solid rgba(255, 221, 0, .18);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-brand i {
            color: var(--brand-yellow);
        }

        .sidebar-section {
            padding: 0 15px;
            margin-bottom: 22px;
        }

        .sidebar-section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .5);
            letter-spacing: .6px;
            padding: 0 10px;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 15px;
            margin-bottom: 4px;
            border-radius: 10px;
            color: rgba(255, 255, 255, .82);
            text-decoration: none;
            font-size: 14px;
            border: 1px solid transparent;
            transition: .22s;
        }

        .sidebar-link:hover {
            background: rgba(255, 221, 0, .12);
            color: #fff;
            padding-left: 20px;
            border-color: rgba(255, 221, 0, .15);
        }

        .sidebar-link.active {
            background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep));
            color: var(--brand-navy-deep);
            font-weight: 700;
        }

        .badge-nav {
            background: #dc2626;
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: auto;
        }

        .main-content {
            flex: 1;
            margin-left: 230px;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .navbar-custom {
            background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy));
            color: #fff;
            padding: 14px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 12px 28px rgba(18, 58, 109, .18);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .navbar-custom h2 {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-custom h2 i {
            color: var(--brand-yellow);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 13px;
        }

        .btn-logout {
            background: rgba(255, 221, 0, .15);
            color: #fff;
            border: 1px solid rgba(255, 221, 0, .28);
            padding: 7px 14px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
        }

        .content-area {
            flex: 1;
            padding: 24px 30px 40px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 14px;
        }

        .page-header h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header h1 i {
            color: var(--brand-yellow-deep);
        }

        .filter-bar {
            background: #fff;
            border: 1px solid var(--brand-border);
            border-radius: 14px;
            padding: 14px 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 18px;
            box-shadow: var(--brand-shadow-sm);
        }

        .chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .chip {
            padding: 7px 14px;
            border-radius: 9px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            border: 1px solid transparent;
        }

        .chip:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .chip.active {
            background: var(--brand-navy-deep);
            color: #fff;
        }

        .filter-bar form {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-left: auto;
            flex-wrap: wrap;
        }

        .filter-bar select,
        .filter-bar input {
            padding: 8px 12px;
            border: 1.5px solid var(--brand-border);
            border-radius: 9px;
            font-size: 13.5px;
            background: #fff;
        }

        .filter-bar input {
            min-width: 220px;
        }

        .filter-bar .btn-search {
            background: var(--brand-yellow);
            color: var(--brand-navy-deep);
            font-weight: 800;
            padding: 8px 16px;
            border: none;
            border-radius: 9px;
            cursor: pointer;
        }

        .grid-tareas {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
            gap: 14px;
        }

        .card-tarea {
            background: #fff;
            border: 1px solid var(--brand-border);
            border-radius: 14px;
            padding: 16px;
            box-shadow: var(--brand-shadow-sm);
            transition: .2s;
            display: flex;
            flex-direction: column;
            gap: 10px;
            border-left: 4px solid var(--brand-navy);
        }

        .card-tarea:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(18, 58, 109, .10);
        }

        .card-tarea.done {
            border-left-color: #10b981;
            background: #fafefb;
        }

        .card-tarea.late {
            border-left-color: #dc2626;
        }

        .ct-head {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ct-ico {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
            flex-shrink: 0;
        }

        .ct-info {
            flex: 1;
            min-width: 0;
        }

        .ct-name {
            font-weight: 800;
            font-size: 15px;
            line-height: 1.25;
            color: var(--brand-navy-deep);
        }

        .ct-tipo {
            font-size: 11.5px;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--brand-gray);
            letter-spacing: .3px;
            margin-top: 2px;
        }

        .ct-meta {
            font-size: 12.5px;
            color: #374151;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 10px;
        }

        .ct-meta div {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ct-meta i {
            color: var(--brand-navy);
            font-size: 11px;
        }

        .ct-actions {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }

        .btn-act {
            flex: 1;
            padding: 8px 10px;
            border-radius: 9px;
            font-weight: 700;
            font-size: 12.5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: none;
            cursor: pointer;
        }

        .btn-act.primary {
            background: var(--brand-yellow);
            color: var(--brand-navy-deep);
        }

        .btn-act.primary:hover {
            background: var(--brand-yellow-deep);
        }

        .btn-act.ghost {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-act.ghost:hover {
            background: #e5e7eb;
        }

        .badge-estado {
            padding: 3px 9px;
            border-radius: 6px;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .3px;
            display: inline-block;
        }

        .be-prog {
            background: #dbeafe;
            color: #1e40af;
        }

        .be-proc {
            background: #fef3c7;
            color: #92400e;
        }

        .be-comp {
            background: #dcfce7;
            color: #065f46;
        }

        .be-post {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            background: #fff;
            border: 1px dashed var(--brand-border);
            border-radius: 14px;
            padding: 50px 20px;
            text-align: center;
            color: #9ca3af;
        }

        .empty i {
            font-size: 42px;
            display: block;
            margin-bottom: 12px;
            opacity: .6;
        }

        @media (max-width:768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .grid-tareas {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-bar form {
                margin-left: 0;
            }

            .filter-bar input {
                min-width: 0;
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <?php require __DIR__ . '/_sidebar_asesor.php'; ?>

    <div class="main-content">
        <div class="navbar-custom">
            <h2><i class="fas fa-list-check"></i> Mis Tareas</h2>
            <div class="user-info">
                <div><strong><?= htmlspecialchars($asesor_nombre) ?></strong></div>
                <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
            </div>
        </div>

        <div class="content-area">
            <div class="page-header">
                <h1><i class="fas fa-list-check"></i> Tareas y visitas</h1>
                <a href="nueva_encuesta.php" class="btn-act primary" style="padding:10px 18px;">
                    <i class="fas fa-plus"></i> Nueva encuesta
                </a>
            </div>

            <div class="filter-bar">
                <div class="chips">
                    <a class="chip <?= $filtro === 'hoy' ? 'active' : '' ?>" href="?filtro=hoy">Hoy</a>
                    <a class="chip <?= $filtro === 'semana' ? 'active' : '' ?>" href="?filtro=semana">Esta semana</a>
                    <a class="chip <?= $filtro === 'pendientes' ? 'active' : '' ?>" href="?filtro=pendientes">Solo
                        pendientes</a>
                    <a class="chip <?= $filtro === 'todas' ? 'active' : '' ?>" href="?filtro=todas">Todas</a>
                </div>
                <form method="get">
                    <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtro) ?>">
                    <select name="tipo">
                        <option value="">Todos los tipos</option>
                        <?php foreach ($tipo_labels as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= $v[0] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="q" placeholder="Buscar cliente, cédula o teléfono…"
                        value="<?= htmlspecialchars($q) ?>">
                    <button type="submit" class="btn-search"><i class="fas fa-search"></i></button>
                </form>
            </div>

            <?php if (empty($tareas)): ?>
                <div class="empty">
                    <i class="fas fa-mug-hot"></i>
                    <h5>No hay tareas con esos filtros</h5>
                    <p style="margin-top:6px;">Cambia los filtros o crea una nueva encuesta para empezar.</p>
                </div>
            <?php else: ?>
                <div class="grid-tareas">
                    <?php foreach ($tareas as $t):
                        $estado = $t['estado'] ?? 'programada';
                        $done = $estado === 'completada';
                        $tipo_meta = $tipo_labels[$t['tipo_tarea'] ?? ''] ?? ['Otro', 'fa-circle', '#6b7280'];
                        $late = (!$done && !empty($t['fecha_programada']) && strtotime($t['fecha_programada']) < strtotime(date('Y-m-d')));
                        ?>
                        <div class="card-tarea <?= $done ? 'done' : ($late ? 'late' : '') ?>">
                            <div class="ct-head">
                                <div class="ct-ico" style="background:<?= $tipo_meta[2] ?>;"><i
                                        class="fas <?= $tipo_meta[1] ?>"></i></div>
                                <div class="ct-info">
                                    <div class="ct-name"><?= htmlspecialchars($t['cliente_nombre'] ?? 'Sin cliente') ?></div>
                                    <div class="ct-tipo"><?= htmlspecialchars($tipo_meta[0]) ?>
                                        <?php
                                        $clase = 'be-prog';
                                        if ($estado === 'completada')
                                            $clase = 'be-comp';
                                        elseif ($estado === 'en_proceso')
                                            $clase = 'be-proc';
                                        elseif ($estado === 'postergada')
                                            $clase = 'be-post';
                                        ?>
                                        · <span
                                            class="badge-estado <?= $clase ?>"><?= ucfirst(str_replace('_', ' ', $estado)) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="ct-meta">
                                <div><i class="far fa-calendar"></i>
                                    <?= $t['fecha_programada'] ? date('d/m/Y', strtotime($t['fecha_programada'])) : '—' ?></div>
                                <div><i class="far fa-clock"></i>
                                    <?= $t['hora_programada'] ? date('H:i', strtotime($t['hora_programada'])) : '—' ?></div>
                                <?php if (!empty($t['cedula'])): ?>
                                    <div><i class="fas fa-id-card"></i> <?= htmlspecialchars($t['cedula']) ?></div><?php endif; ?>
                                <?php if (!empty($t['telefono'])): ?>
                                    <div><i class="fas fa-phone"></i> <?= htmlspecialchars($t['telefono']) ?></div><?php endif; ?>
                                <?php if (!empty($t['cli_ciudad'] ?? $t['ciudad'])): ?>
                                    <div><i class="fas fa-city"></i> <?= htmlspecialchars($t['cli_ciudad'] ?? $t['ciudad']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($t['zona'])): ?>
                                    <div><i class="fas fa-map-pin"></i> <?= htmlspecialchars($t['zona']) ?></div><?php endif; ?>
                            </div>
                            <div class="ct-actions">
                                <?php if ($done): ?>
                                    <a class="btn-act ghost" href="ver_cliente.php?id=<?= urlencode($t['cedula'] ?? '') ?>"><i
                                            class="fas fa-eye"></i> Ver ficha</a>
                                <?php else: ?>
                                    <a class="btn-act primary" href="nueva_encuesta.php?tarea_id=<?= urlencode($t['id']) ?>"><i
                                            class="fas fa-play"></i> Iniciar</a>
                                    <button class="btn-act ghost btn-posponer" data-tarea-id="<?= htmlspecialchars($t['id']) ?>"
                                        data-cliente="<?= htmlspecialchars($t['cliente_nombre'] ?? 'Cliente') ?>"
                                        title="Posponer tarea">
                                        <i class="fas fa-calendar-plus"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Posponer -->
    <div id="modalPosponer"
        style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
        <div
            style="background:#fff;border-radius:16px;width:380px;max-width:95vw;overflow:hidden;box-shadow:0 24px 48px rgba(0,0,0,.2);">
            <div
                style="background:linear-gradient(135deg,#0a2748,#123a6d);color:#fff;padding:18px 22px;display:flex;align-items:center;justify-content:space-between;">
                <strong style="font-size:15px;"><i class="fas fa-calendar-plus me-2"></i>Posponer Tarea</strong>
                <button onclick="cerrarPosponer()"
                    style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">×</button>
            </div>
            <div style="padding:22px;">
                <p style="font-size:13.5px;color:#374151;margin-bottom:16px;">Cliente: <strong
                        id="posponerCliente"></strong></p>
                <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Nueva fecha:</label>
                <input type="date" id="posponerFecha"
                    style="width:100%;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;font-size:14px;">
                <input type="hidden" id="posponerTareaId">
            </div>
            <div style="padding:0 22px 22px;display:flex;gap:10px;">
                <button onclick="cerrarPosponer()"
                    style="flex:1;padding:10px;border:1.5px solid #d7e0ea;border-radius:9px;background:#fff;font-weight:700;cursor:pointer;">Cancelar</button>
                <button onclick="confirmarPosponer()" id="btnConfPosponer"
                    style="flex:1;padding:10px;background:#ffdd00;border:none;border-radius:9px;font-weight:800;color:#0a2748;cursor:pointer;"><i
                        class="fas fa-check me-1"></i>Posponer</button>
            </div>
        </div>
    </div>

    <script>
        var _asesorUserId = '<?= addslashes($asesor_usuario_id) ?>';

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-posponer');
            if (!btn) return;
            document.getElementById('posponerTareaId').value = btn.dataset.tareaId;
            document.getElementById('posponerCliente').textContent = btn.dataset.cliente;
            var d = new Date(); d.setDate(d.getDate() + 1);
            document.getElementById('posponerFecha').value = d.toISOString().split('T')[0];
            document.getElementById('modalPosponer').style.display = 'flex';
        });
        function cerrarPosponer() { document.getElementById('modalPosponer').style.display = 'none'; }
        function confirmarPosponer() {
            var btn = document.getElementById('btnConfPosponer'); btn.disabled = true;
            var tid = document.getElementById('posponerTareaId').value;
            var fecha = document.getElementById('posponerFecha').value;
            if (!fecha) { alert('Seleccione una fecha'); btn.disabled = false; return; }
            var fd = new FormData();
            fd.append('usuario_id', _asesorUserId);
            fd.append('tarea_id', tid);
            fd.append('nueva_fecha', fecha);
            fetch('../posponer_tarea.php', { method: 'POST', body: fd })
                .then(r => r.json()).then(j => {
                    btn.disabled = false;
                    cerrarPosponer();
                    if (j.status === 'success') {
                        showToast('📅 Tarea pospuesta correctamente');
                        setTimeout(() => window.location.reload(), 1200);
                    } else { showToast('Error: ' + (j.message || 'no se pudo posponer'), 'err'); }
                }).catch(() => { btn.disabled = false; showToast('Error de red', 'err'); });
        }
        function showToast(msg, tipo) {
            var t = document.createElement('div');
            t.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;background:' + (tipo === 'err' ? '#991b1b' : '#065f46') + ';color:#fff;padding:13px 20px;border-radius:11px;font-weight:700;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.18);';
            t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 3500);
        }
    </script>
</body>

</html>
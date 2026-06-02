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
    <link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
    <style>
    /* ── PAGE HEADER ─────────────────────────── */
    .ma-page-header{
        display:flex;align-items:center;gap:14px;
        margin-bottom:28px;
        padding-bottom:18px;
        border-bottom:2px solid #e8eef6;
    }
    .ma-page-icon{
        width:52px;height:52px;border-radius:14px;
        background:linear-gradient(135deg,#0a2748,#1e4d8c);
        display:flex;align-items:center;justify-content:center;
        box-shadow:0 4px 14px rgba(10,39,72,.22);
        flex-shrink:0;
    }
    .ma-page-icon i{color:#ffdd00;font-size:22px;}
    .ma-page-title{font-size:22px;font-weight:900;color:#0a2748;margin:0;}
    .ma-page-sub{font-size:13px;color:#94a3b8;margin:2px 0 0;font-weight:500;}

    .btn-navy {
        background: #0a2748;
        color: #fff;
        border: 2px solid #0a2748;
        border-radius: 10px;
        padding: 8px 16px;
        font-size: 13.5px;
        font-weight: 700;
        transition: all 0.2s ease;
    }
    .btn-navy:hover {
        background: #1e4d8c;
        border-color: #1e4d8c;
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(10,39,72,.15);
    }
    .btn-outline-navy {
        background: transparent;
        color: #0a2748;
        border: 2px solid #0a2748;
        border-radius: 10px;
        padding: 8px 16px;
        font-size: 13.5px;
        font-weight: 700;
        transition: all 0.2s ease;
    }
    .btn-outline-navy:hover {
        background: rgba(10,39,72,.05);
        color: #0a2748;
        transform: translateY(-1px);
    }

    /* ── ASESORES GRID ────────────────────────── */
    .asesores-grid{
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(260px,1fr));
        gap:18px;
        margin-bottom:32px;
    }

    /* Tarjeta asesor */
    .ac{
        background:#fff;
        border-radius:18px;
        border:2px solid #e2eaf4;
        box-shadow:0 3px 12px rgba(10,39,72,.07);
        cursor:pointer;
        transition:all .2s;
        overflow:hidden;
        position:relative;
    }
    .ac:hover{ transform:translateY(-4px); box-shadow:0 10px 28px rgba(10,39,72,.15); border-color:#93c5fd; }
    .ac.active{ border-color:#0a2748; box-shadow:0 6px 22px rgba(10,39,72,.22); }

    .ac-stripe{
        height:5px;
        background:linear-gradient(90deg,#0a2748,#1e4d8c,#ffdd00);
    }
    .ac-body{ padding:18px 18px 14px; }

    .ac-avatar{
        width:52px;height:52px;border-radius:14px;
        background:linear-gradient(135deg,#0a2748,#1e4d8c);
        display:flex;align-items:center;justify-content:center;
        font-size:20px;font-weight:900;color:#ffdd00;
        margin-bottom:12px;
        box-shadow:0 3px 10px rgba(10,39,72,.2);
        flex-shrink:0;
    }
    .ac-name{
        font-size:15px;font-weight:800;color:#0a2748;
        margin:0 0 4px;line-height:1.2;
    }
    .ac-email{
        font-size:11.5px;color:#64748b;margin:0 0 2px;
        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .ac-phone{font-size:11.5px;color:#64748b;margin:0;}

    .ac-footer{
        display:flex;align-items:center;justify-content:space-between;
        padding:10px 18px;
        background:#f8fafc;
        border-top:1px solid #edf2f9;
    }
    .ac-badge{
        display:inline-flex;align-items:center;gap:5px;
        background:linear-gradient(135deg,#0a2748,#1e4d8c);
        color:#ffdd00;font-weight:800;font-size:12px;
        padding:4px 12px;border-radius:20px;
    }
    .ac-arrow{
        width:28px;height:28px;border-radius:50%;
        background:#eef2ff;display:flex;align-items:center;justify-content:center;
        color:#4f46e5;font-size:12px;
        transition:transform .25s,background .18s;
    }
    .ac.active .ac-arrow{ background:#0a2748;color:#ffdd00;transform:rotate(90deg); }

    /* ── CLIENTES PANEL ───────────────────────── */
    .cp-panel{
        display:none;
        background:#f0f5fb;
        border-radius:18px;
        border:2px solid #0a2748;
        padding:20px;
        margin-bottom:24px;
        animation:cpIn .22s ease-out;
    }
    .cp-panel.show{ display:block; }
    @keyframes cpIn{ from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none} }

    .cp-panel-header{
        display:flex;align-items:center;justify-content:space-between;
        margin-bottom:16px;padding-bottom:12px;
        border-bottom:2px solid #dde5f0;
        flex-wrap:wrap;gap:8px;
    }
    .cp-panel-title{
        font-size:15px;font-weight:800;color:#0a2748;
        display:flex;align-items:center;gap:8px;
    }
    .cp-panel-title i{color:#ffdd00;background:#0a2748;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;}
    .cp-close{
        border:none;background:rgba(10,39,72,.08);color:#0a2748;
        width:32px;height:32px;border-radius:50%;cursor:pointer;
        font-size:14px;display:flex;align-items:center;justify-content:center;
        transition:.18s;
    }
    .cp-close:hover{background:#0a2748;color:#fff;}

    /* Clientes grid */
    .clientes-grid{
        display:grid;
        grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
        gap:14px;
    }

    /* Tarjeta cliente */
    .cc{
        background:#fff;
        border-radius:14px;
        border:1.5px solid #d7e0ea;
        box-shadow:0 2px 8px rgba(10,39,72,.06);
        padding:14px 15px;
        transition:all .18s;
    }
    .cc:hover{ transform:translateY(-2px); box-shadow:0 6px 16px rgba(10,39,72,.13); border-color:#93c5fd; }

    .cc-top{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;}
    .cc-icon{
        width:36px;height:36px;border-radius:10px;flex-shrink:0;
        background:linear-gradient(135deg,#eff6ff,#dbeafe);
        display:flex;align-items:center;justify-content:center;
        color:#1e40af;font-size:14px;
    }
    .cc-name{font-size:13px;font-weight:800;color:#0a2748;margin:0 0 2px;line-height:1.3;}
    .cc-ci{font-size:11px;color:#94a3b8;font-weight:600;margin:0;}

    .cc-contact{
        font-size:11px;color:#64748b;
        display:flex;flex-direction:column;gap:3px;
        padding-top:8px;border-top:1px solid #f0f4f8;
    }
    .cc-contact span{display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .cc-contact i{color:#94a3b8;width:12px;flex-shrink:0;}

    .cc-status{
        margin-top:9px;padding-top:8px;border-top:1px solid #f0f4f8;
    }
    .pill-activo{
        display:inline-flex;align-items:center;gap:4px;
        background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;
        border-radius:20px;padding:2px 10px;font-size:10.5px;font-weight:700;
    }
    .pill-inactivo{
        display:inline-flex;align-items:center;gap:4px;
        background:#fef2f2;color:#991b1b;border:1px solid #fecaca;
        border-radius:20px;padding:2px 10px;font-size:10.5px;font-weight:700;
    }

    .cc-empty{
        grid-column:1/-1;text-align:center;padding:30px 20px;
        color:#94a3b8;font-size:14px;
        background:#fff;border-radius:14px;border:1.5px dashed #d7e0ea;
    }
    .cc-empty i{display:block;font-size:28px;margin-bottom:8px;opacity:.4;}
    </style>
</head>
<body>

<?php $navTitle = ''; $navIcon = ''; $navSubtitle = ''; require_once '_sidebar_supervisor.php'; ?>


    <!-- HEADER -->
    <div class="ma-page-header">
        <div class="ma-page-icon"><i class="fas fa-users"></i></div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3" style="flex: 1;">
            <div>
                <h1 class="ma-page-title">Mi Equipo de Asesores</h1>
                <p class="ma-page-sub">Selecciona un asesor para ver sus clientes</p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="input-group" style="width: 260px; box-shadow: 0 4px 14px rgba(10,39,72,.06); border-radius: 10px; overflow: hidden;">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="advisorSearch" class="form-control border-start-0" style="padding: 8px 12px; font-size: 14px;" placeholder="Buscar asesor..." oninput="filterAdvisors()">
                </div>
                <a href="registro_asesor.php" class="btn-navy text-decoration-none d-inline-flex align-items-center gap-2">
                    <i class="fas fa-user-plus"></i> Crear Asesor
                </a>
                <a href="administrar_solicitudes_asesor.php" class="btn-outline-navy text-decoration-none d-inline-flex align-items-center gap-2">
                    <i class="fas fa-file-circle-check"></i> Solicitudes de Asesor
                </a>
            </div>
        </div>
    </div>

    <?php if (empty($asesores)): ?>
        <div style="text-align:center;padding:60px 20px;color:#94a3b8;">
            <i class="fas fa-users-slash" style="font-size:48px;display:block;margin-bottom:14px;opacity:.3;"></i>
            <p style="font-size:15px;font-weight:600;margin:0;">No tienes asesores asignados</p>
        </div>
    <?php else: ?>

        <!-- GRID DE ASESORES -->
        <div class="asesores-grid" id="asesoresGrid">
            <?php foreach ($asesores as $idx => $asesor):
                $asesorKey = (string)($asesor['id_usuario'] ?? '');
                $nombre    = htmlspecialchars(trim($asesor['nombres'].' '.$asesor['apellidos']));
                $inicial   = strtoupper(mb_substr(trim($asesor['nombres']), 0, 1));
                $email     = htmlspecialchars($asesor['email'] ?? '');
                $telefono  = htmlspecialchars($asesor['telefono'] ?? 'Sin teléfono');
                $total     = intval($asesor['total_clientes']);
                $asesorKeyEsc = htmlspecialchars($asesorKey, ENT_QUOTES, 'UTF-8');
            ?>
            <div class="ac" 
                 id="ac-<?= $asesorKeyEsc ?>" 
                 onclick="toggleClientes('<?= $asesorKeyEsc ?>')"
                 data-search-name="<?= strtolower(htmlspecialchars($nombre)) ?>"
                 data-search-user="<?= strtolower(htmlspecialchars($asesor['usuario'] ?? '')) ?>"
                 data-search-email="<?= strtolower(htmlspecialchars($email)) ?>">
                <div class="ac-stripe"></div>
                <div class="ac-body">
                    <div class="ac-avatar"><?= $inicial ?></div>
                    <h3 class="ac-name"><?= $nombre ?></h3>
                    <p class="ac-email"><i class="fas fa-envelope" style="margin-right:5px;color:#94a3b8;font-size:10px;"></i><?= $email ?></p>
                    <p class="ac-phone"><i class="fas fa-phone" style="margin-right:5px;color:#94a3b8;font-size:10px;"></i><?= $telefono ?></p>
                </div>
                <div class="ac-footer">
                    <span class="ac-badge"><i class="fas fa-user-group"></i> <?= $total ?> cliente<?= $total !== 1 ? 's' : '' ?></span>
                    <span class="ac-arrow" id="arrow-<?= $asesorKeyEsc ?>"><i class="fas fa-chevron-right"></i></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="cc-empty w-100 d-none" id="advisor-search-empty" style="grid-column: 1/-1; padding: 40px 20px;">
                <i class="fas fa-search-minus" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i>
                No se encontraron asesores coincidentes
            </div>
        </div>

        <!-- PANELES DE CLIENTES (uno por asesor) -->
        <?php foreach ($asesores as $asesor):
            $asesorKey    = (string)($asesor['id_usuario'] ?? '');
            $nombre       = htmlspecialchars(trim($asesor['nombres'].' '.$asesor['apellidos']));
            $asesorKeyEsc = htmlspecialchars($asesorKey, ENT_QUOTES, 'UTF-8');
            $clientes     = $clientes_por_asesor[$asesorKey] ?? [];
        ?>
        <div class="cp-panel" id="panel-<?= $asesorKeyEsc ?>">
            <div class="cp-panel-header">
                <div class="cp-panel-title">
                    <i class="fas fa-users"></i>
                    Clientes de <strong style="margin-left:4px;"><?= $nombre ?></strong>
                    <span style="background:#e0f2fe;color:#0369a1;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;margin-left:6px;"><?= count($clientes) ?></span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if (!empty($clientes)): ?>
                    <div class="input-group" style="width: 260px; box-shadow: 0 2px 8px rgba(10,39,72,.05); border-radius: 8px; overflow: hidden;">
                        <span class="input-group-text bg-white border-end-0" style="padding: 6px 10px; font-size: 13px;"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 client-search-input" style="padding: 6px 10px; font-size: 13px;" placeholder="Buscar cliente..." data-asesor-id="<?= $asesorKeyEsc ?>" oninput="filterClients(this)">
                    </div>
                    <?php endif; ?>
                    <button class="cp-close" onclick="cerrarPanel('<?= $asesorKeyEsc ?>')"><i class="fas fa-times"></i></button>
                </div>
            </div>

            <div class="clientes-grid">
                <?php if (empty($clientes)): ?>
                    <div class="cc-empty">
                        <i class="fas fa-inbox"></i>
                        Sin clientes asignados aún
                    </div>
                <?php else: ?>
                    <?php foreach ($clientes as $c):
                        $cnombre = htmlspecialchars($c['nombre'] ?? '');
                        $ccedula = htmlspecialchars($c['cedula'] ?? '');
                        $cemail  = htmlspecialchars($c['email'] ?? '');
                        $ctel    = htmlspecialchars($c['telefono2'] ?? $c['telefono'] ?? '');
                        $activo  = !empty($c['activo']);
                    ?>
                    <div class="cc"
                         data-search-name="<?= strtolower($cnombre) ?>"
                         data-search-cedula="<?= strtolower($ccedula) ?>">
                        <div class="cc-top">
                            <div class="cc-icon"><i class="fas fa-user"></i></div>
                            <div style="min-width:0;">
                                <p class="cc-name"><?= $cnombre ?></p>
                                <?php if ($ccedula): ?>
                                <p class="cc-ci"><i class="fas fa-id-card" style="margin-right:3px;"></i>CI: <?= $ccedula ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="cc-contact">
                            <?php if ($cemail): ?><span><i class="fas fa-envelope"></i><?= $cemail ?></span><?php endif; ?>
                            <?php if ($ctel): ?><span><i class="fas fa-phone"></i><?= $ctel ?></span><?php endif; ?>
                        </div>
                        <div class="cc-status">
                            <?php if ($activo): ?>
                                <span class="pill-activo"><i class="fas fa-check-circle"></i> Activo</span>
                            <?php else: ?>
                                <span class="pill-inactivo"><i class="fas fa-times-circle"></i> Inactivo</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="cc-empty w-100 d-none client-search-empty" style="grid-column: 1/-1;">
                        <i class="fas fa-search-minus" style="font-size: 24px; margin-bottom: 8px; opacity: 0.5;"></i>
                        No se encontraron clientes coincidentes
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>

<script>
var asesorActivo = null;

function toggleClientes(id) {
    // Si ya está activo, cerrar
    if (asesorActivo === id) {
        cerrarPanel(id);
        return;
    }
    // Cerrar anterior
    if (asesorActivo) cerrarPanel(asesorActivo);

    // Abrir nuevo
    asesorActivo = id;
    document.getElementById('ac-' + id)?.classList.add('active');
    var panel = document.getElementById('panel-' + id);
    if (panel) {
        panel.classList.add('show');
        // scroll suave al panel
        setTimeout(function(){ panel.scrollIntoView({behavior:'smooth', block:'nearest'}); }, 80);
    }
}

function cerrarPanel(id) {
    document.getElementById('ac-' + id)?.classList.remove('active');
    var panel = document.getElementById('panel-' + id);
    if (panel) {
        panel.classList.remove('show');
        // Limpiar el buscador de clientes de este panel al cerrar
        var searchInput = panel.querySelector('.client-search-input');
        if (searchInput) {
            searchInput.value = '';
            filterClients(searchInput);
        }
    }
    if (asesorActivo === id) asesorActivo = null;
}

function filterAdvisors() {
    var query = document.getElementById('advisorSearch').value.toLowerCase().trim();
    var cards = document.querySelectorAll('#asesoresGrid .ac');
    var visibleCount = 0;

    cards.forEach(function(card) {
        var name = card.getAttribute('data-search-name') || '';
        var user = card.getAttribute('data-search-user') || '';
        var email = card.getAttribute('data-search-email') || '';
        
        if (name.includes(query) || user.includes(query) || email.includes(query)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
            // Si la tarjeta está oculta y es la activa, cerrar su panel
            var id = card.id.replace('ac-', '');
            if (asesorActivo === id) {
                cerrarPanel(id);
            }
        }
    });

    var emptyEl = document.getElementById('advisor-search-empty');
    if (emptyEl) {
        if (visibleCount === 0) {
            emptyEl.classList.remove('d-none');
        } else {
            emptyEl.classList.add('d-none');
        }
    }
}

function filterClients(input) {
    var query = input.value.toLowerCase().trim();
    var advisorId = input.getAttribute('data-asesor-id');
    var panel = document.getElementById('panel-' + advisorId);
    if (!panel) return;

    var clientCards = panel.querySelectorAll('.clientes-grid .cc');
    var visibleCount = 0;

    clientCards.forEach(function(card) {
        var name = card.getAttribute('data-search-name') || '';
        var cedula = card.getAttribute('data-search-cedula') || '';
        
        if (name.includes(query) || cedula.includes(query)) {
            card.style.display = '';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });

    var emptyEl = panel.querySelector('.client-search-empty');
    if (emptyEl) {
        if (visibleCount === 0) {
            emptyEl.classList.remove('d-none');
        } else {
            emptyEl.classList.add('d-none');
        }
    }
}
</script>
</body>
</html>

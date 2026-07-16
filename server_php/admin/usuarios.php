<?php
require_once 'db_admin.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar sesión de super_admin o admin (gerente)
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role   = 'super_admin';
    $user_nombre = $_SESSION['super_admin_nombre'] ?? 'Super Admin';
    $user_rol    = $_SESSION['super_admin_rol'] ?? 'Super Administrador';
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role   = 'admin';
    $user_nombre = $_SESSION['admin_nombre'] ?? 'Gerente';
    $user_rol    = $_SESSION['admin_rol'] ?? 'Gerente';
} else {
    header('Location: login.php?role=admin');
    exit;
}

// ============================================================
// Datos: bancos/cooperativas -> gerentes -> supervisores -> asesores
//
// OJO: la versión anterior de esta página consultaba tablas
// `usuarios`, `roles` y `cooperativa` que no existen en el esquema
// actual (el esquema real usa `usuario`, `unidad_bancaria`,
// `agencia`, `jefe_agencia`, `supervisor`, `asesor`), por eso la
// página nunca cargaba. Esta versión usa las tablas reales y
// arma la jerarquía completa banco -> gerente -> supervisor ->
// asesor para mostrarla como tarjetas (mismo patrón visual y de
// interacción que usa el supervisor en mis_asesores.php).
// ============================================================
$bancos = [];
$gerentes = [];
$supervisores = [];
$asesores = [];
$clientes = [];
$error_carga = '';

try {
    $bancos = $pdo->query("
        SELECT id, nombre
        FROM unidad_bancaria
        ORDER BY nombre ASC
    ")->fetchAll();

    $gerentes = $pdo->query("
        SELECT
            ja.id AS gerente_id,
            ju.id AS usuario_id,
            ju.nombre,
            ju.email,
            ju.activo,
            ju.estado_aprobacion,
            ag.unidad_bancaria_id AS banco_id
        FROM jefe_agencia ja
        JOIN usuario ju ON ju.id = ja.usuario_id
        LEFT JOIN agencia ag ON ag.id = ja.agencia_id
        ORDER BY ju.nombre ASC
    ")->fetchAll();

    $supervisores = $pdo->query("
        SELECT
            sup.id AS supervisor_id,
            su.id AS usuario_id,
            su.nombre,
            su.email,
            su.activo,
            su.estado_aprobacion,
            sup.jefe_agencia_id AS gerente_id,
            ag.unidad_bancaria_id AS banco_id,
            COUNT(DISTINCT a.id) AS total_asesores
        FROM supervisor sup
        JOIN usuario su ON su.id = sup.usuario_id
        LEFT JOIN jefe_agencia ja ON ja.id = sup.jefe_agencia_id
        LEFT JOIN agencia ag ON ag.id = ja.agencia_id
        LEFT JOIN asesor a ON a.supervisor_id = sup.id
        GROUP BY sup.id, su.id, su.nombre, su.email, su.activo, su.estado_aprobacion,
                 sup.jefe_agencia_id, ag.unidad_bancaria_id
        ORDER BY su.nombre ASC
    ")->fetchAll();

    $asesores = $pdo->query("
        SELECT
            a.id AS asesor_id,
            au.id AS usuario_id,
            au.nombre,
            au.email,
            au.activo,
            au.estado_aprobacion,
            a.supervisor_id,
            sup.jefe_agencia_id AS gerente_id,
            ag.unidad_bancaria_id AS banco_id,
            COUNT(DISTINCT cp.id) AS total_clientes
        FROM asesor a
        JOIN usuario au ON au.id = a.usuario_id
        LEFT JOIN supervisor sup ON sup.id = a.supervisor_id
        LEFT JOIN jefe_agencia ja ON ja.id = sup.jefe_agencia_id
        LEFT JOIN agencia ag ON ag.id = ja.agencia_id
        LEFT JOIN cliente_prospecto cp ON cp.asesor_id = a.id
        GROUP BY a.id, au.id, au.nombre, au.email, au.activo, au.estado_aprobacion,
                 a.supervisor_id, sup.jefe_agencia_id, ag.unidad_bancaria_id
        ORDER BY au.nombre ASC
    ")->fetchAll();

    $clientes = $pdo->query("
        SELECT
            cp.id AS cliente_id,
            cp.asesor_id,
            cp.nombre,
            COALESCE(cp.cedula, '') AS cedula,
            cp.email,
            cp.telefono,
            cp.telefono2,
            CASE WHEN cp.estado != 'descartado' THEN 1 ELSE 0 END AS activo
        FROM cliente_prospecto cp
        ORDER BY cp.nombre ASC
    ")->fetchAll();
} catch (\Throwable $e) {
    $error_carga = $e->getMessage();
    error_log('[usuarios.php] ' . $error_carga);
}

// Agrupar supervisores por gerente_id y asesores por supervisor_id para
// poder pintar los paneles anidados server-side sin necesidad de una
// llamada AJAX aparte.
$supervisoresPorGerente = [];
foreach ($supervisores as $s) {
    $gid = (string)($s['gerente_id'] ?? '');
    $supervisoresPorGerente[$gid][] = $s;
}
$asesoresPorSupervisor = [];
foreach ($asesores as $a) {
    $sid = (string)($a['supervisor_id'] ?? '');
    $asesoresPorSupervisor[$sid][] = $a;
}
$clientesPorAsesor = [];
foreach ($clientes as $c) {
    $aid = (string)($c['asesor_id'] ?? '');
    $clientesPorAsesor[$aid][] = $c;
}
$bancoNombrePorId = [];
foreach ($bancos as $b) {
    $bancoNombrePorId[(string)$b['id']] = $b['nombre'];
}

$totalGerentes     = count($gerentes);
$totalSupervisores = count($supervisores);
$totalAsesores     = count($asesores);

$currentPage = 'usuarios';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA - Usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="js/cooperativa_buscador.js"></script>
    <style>
        :root {
            --brand-yellow: #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy: #123a6d;
            --brand-navy-deep: #0a2748;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: #f5f7fa; display: flex; height: 100vh; }
        .sidebar {
            width: 230px;
            background: linear-gradient(180deg, var(--brand-navy-deep) 0%, var(--brand-navy) 100%);
            color: white;
            padding: 20px 0;
            overflow-y: auto;
            position: sticky;
            height: 100vh;
            top: 0;
            flex-shrink: 0;
        }
        .sidebar-brand { padding: 0 20px 30px; font-size: 18px; font-weight: 800; border-bottom: 1px solid rgba(255,221,0,0.18); margin-bottom: 20px; }
        .sidebar-brand i { margin-right: 10px; color: var(--brand-yellow); }
        .sidebar-section { padding: 0 15px; margin-bottom: 25px; }
        .sidebar-section-title { font-size: 11px; text-transform: uppercase; color: rgba(255,255,255,0.58); letter-spacing: 0.5px; padding: 0 10px; margin-bottom: 10px; font-weight: 600; }
        .sidebar-link { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin-bottom: 5px; border-radius: 10px; color: rgba(255,255,255,0.82); cursor: pointer; transition: all 0.25s ease; text-decoration: none; font-size: 14px; }
        .sidebar-link:hover { background: rgba(255,221,0,0.12); color: #fff; padding-left: 20px; }
        .sidebar-link.active { background: linear-gradient(90deg, var(--brand-yellow), var(--brand-yellow-deep)); color: var(--brand-navy-deep); font-weight: 700; }
        .main-content { flex: 1; margin-left: 0 !important; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        @media (max-width: 1200px) { .sidebar { width: 200px; } }
        @media (max-width: 768px) { .sidebar { width: 180px; } }
        .navbar-custom { background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 24px rgba(18, 58, 109, 0.16); }
        .navbar-custom h2 { margin: 0; font-size: 20px; font-weight: 700; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .btn-logout { background: rgba(255, 255, 255, 0.2); color: white; border: 1px solid white; padding: 8px 15px; border-radius: 5px; cursor: pointer; text-decoration: none; }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.3); }
        .content-area { flex: 1; overflow-y: auto; padding: 30px; }
        .page-header { margin-bottom: 22px; }
        .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; }

        /* ── Barra de filtro banco/cooperativa + búsqueda ── */
        .filtro-bar {
            display: flex; flex-wrap: wrap; align-items: flex-end; gap: 16px;
            background: #fff; border-radius: 14px; padding: 16px 18px; margin-bottom: 22px;
            box-shadow: 0 4px 16px rgba(0,0,0,.06);
        }
        .filtro-bar .field { display: flex; flex-direction: column; gap: 5px; min-width: 240px; position: relative; }
        .filtro-bar label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--brand-navy-deep); }
        .filtro-bar input[type="text"] {
            padding: 9px 30px 9px 12px; border-radius: 9px; border: 1.5px solid #E2E8F0;
            font-size: 13.5px; font-family: 'Inter', sans-serif; color: #0D1929; background: #fff;
        }
        .coop-buscador-clear {
            position: absolute; right: 9px; top: 34px;
            border: none; background: transparent; color: #94a3b8;
            cursor: pointer; font-size: 13px; padding: 4px; display: none;
        }
        .coop-buscador-clear:hover { color: #ef4444; }
        .coop-buscador-clear.show { display: block; }
        .coop-buscador-list {
            display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 40;
            max-height: 260px; overflow-y: auto; background: #fff; border: 1.5px solid #E2E8F0;
            border-radius: 10px; margin-top: 6px; box-shadow: 0 12px 28px rgba(18,58,109,.16);
        }
        .coop-buscador-item { padding: 9px 14px; font-size: 13.5px; color: #0D1929; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
        .coop-buscador-item:last-child { border-bottom: none; }
        .coop-buscador-item:hover { background: rgba(255,221,0,.16); }
        .coop-buscador-empty { padding: 10px 14px; font-size: 12.5px; color: #94a3b8; font-style: italic; }
        .filtro-bar .search-field { flex: 1; min-width: 260px; }
        .stats-chip { margin-left: auto; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .stats-chip .chip {
            background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 8px 14px; font-size: 12px; color: #475569; font-weight: 600;
        }
        .stats-chip .chip b { color: var(--brand-navy-deep); font-size: 14px; }

        /* ── Grid de tarjetas (gerente / supervisor / asesor) ── */
        .cards-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 18px; margin-bottom: 24px;
        }
        .uc {
            background: #fff; border-radius: 18px; border: 2px solid #e2eaf4;
            box-shadow: 0 3px 12px rgba(10,39,72,.07); cursor: pointer;
            transition: all .2s; overflow: hidden; position: relative;
        }
        .uc:hover { transform: translateY(-4px); box-shadow: 0 10px 28px rgba(10,39,72,.15); border-color: #93c5fd; }
        .uc.active { border-color: #0a2748; box-shadow: 0 6px 22px rgba(10,39,72,.22); }
        .uc-stripe { height: 5px; }
        .uc-stripe.gerente    { background: linear-gradient(90deg,#dc2626,#991b1b); }
        .uc-stripe.supervisor { background: linear-gradient(90deg,#3b82f6,#1d4ed8); }
        .uc-stripe.asesor     { background: linear-gradient(90deg,#10b981,#059669); }
        .uc-body { padding: 18px 18px 14px; }
        .uc-avatar {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 900; color: #fff; margin-bottom: 12px; flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(10,39,72,.2);
        }
        .uc-avatar.gerente    { background: linear-gradient(135deg,#dc2626,#991b1b); }
        .uc-avatar.supervisor { background: linear-gradient(135deg,#3b82f6,#1d4ed8); }
        .uc-avatar.asesor     { background: linear-gradient(135deg,#10b981,#059669); }
        .uc-name { font-size: 15px; font-weight: 800; color: #0a2748; margin: 0 0 4px; line-height: 1.2; }
        .uc-email { font-size: 11.5px; color: #64748b; margin: 0 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .uc-sub { font-size: 11px; color: #94a3b8; margin: 0; }
        .uc-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 18px; background: #f8fafc; border-top: 1px solid #edf2f9;
        }
        .uc-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-weight: 800; font-size: 12px; padding: 4px 12px; border-radius: 20px; color: #fff;
        }
        .uc-badge.gerente    { background: linear-gradient(135deg,#dc2626,#991b1b); }
        .uc-badge.supervisor { background: linear-gradient(135deg,#3b82f6,#1d4ed8); }
        .uc-badge.asesor     { background: linear-gradient(135deg,#10b981,#059669); }
        .uc-arrow {
            width: 28px; height: 28px; border-radius: 50%; background: #eef2ff;
            display: flex; align-items: center; justify-content: center; color: #4f46e5; font-size: 12px;
            transition: transform .25s, background .18s;
        }
        .uc.active .uc-arrow { background: #0a2748; color: #ffdd00; transform: rotate(90deg); }
        .pill-activo {
            display: inline-flex; align-items: center; gap: 4px; background: #ecfdf5; color: #065f46;
            border: 1px solid #a7f3d0; border-radius: 20px; padding: 2px 10px; font-size: 10.5px; font-weight: 700;
        }
        .pill-inactivo {
            display: inline-flex; align-items: center; gap: 4px; background: #fef2f2; color: #991b1b;
            border: 1px solid #fecaca; border-radius: 20px; padding: 2px 10px; font-size: 10.5px; font-weight: 700;
        }
        .banco-tag {
            display: inline-flex; align-items: center; gap: 4px; background: #fffbeb; color: #92400e;
            border: 1px solid #fde68a; border-radius: 20px; padding: 1px 9px; font-size: 10px; font-weight: 700;
            margin-top: 4px;
        }

        /* ── Panel anidado (supervisores dentro de gerente, asesores dentro de supervisor) ── */
        .nested-panel {
            display: none; background: #f0f5fb; border-radius: 18px; border: 2px solid #0a2748;
            padding: 18px; margin: -6px 0 22px; animation: cpIn .2s ease-out;
        }
        .nested-panel.show { display: block; }
        @keyframes cpIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        .nested-panel-header {
            display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;
            padding-bottom: 10px; border-bottom: 2px solid #dde5f0; flex-wrap: wrap; gap: 8px;
        }
        .nested-panel-title { font-size: 14px; font-weight: 800; color: #0a2748; display: flex; align-items: center; gap: 8px; }
        .empty-msg {
            grid-column: 1/-1; text-align: center; padding: 26px 20px; color: #94a3b8; font-size: 13.5px;
            background: #fff; border-radius: 14px; border: 1.5px dashed #d7e0ea;
        }
        .empty-msg i { display: block; font-size: 26px; margin-bottom: 8px; opacity: .4; }

        /* ── Tarjetas de cliente (dentro del panel de asesor) ── */
        .clientes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
        .cc {
            background: #fff; border-radius: 14px; border: 1.5px solid #d7e0ea;
            box-shadow: 0 2px 8px rgba(10,39,72,.06); padding: 14px 15px; transition: all .18s;
        }
        .cc:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(10,39,72,.13); border-color: #93c5fd; }
        .cc-top { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
        .cc-icon {
            width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
            background: linear-gradient(135deg,#eff6ff,#dbeafe);
            display: flex; align-items: center; justify-content: center; color: #1e40af; font-size: 14px;
        }
        .cc-name { font-size: 13px; font-weight: 800; color: #0a2748; margin: 0 0 2px; line-height: 1.3; }
        .cc-ci { font-size: 11px; color: #94a3b8; font-weight: 600; margin: 0; }
        .cc-contact {
            font-size: 11px; color: #64748b; display: flex; flex-direction: column; gap: 3px;
            padding-top: 8px; border-top: 1px solid #f0f4f8;
        }
        .cc-contact span { display: flex; align-items: center; gap: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cc-contact i { color: #94a3b8; width: 12px; flex-shrink: 0; }
        .cc-status { margin-top: 9px; padding-top: 8px; border-top: 1px solid #f0f4f8; }
        .cc-empty {
            grid-column: 1/-1; text-align: center; padding: 30px 20px; color: #94a3b8; font-size: 14px;
            background: #fff; border-radius: 14px; border: 1.5px dashed #d7e0ea;
        }
        .cc-empty i { display: block; font-size: 28px; margin-bottom: 8px; opacity: .4; }
        .client-search-wrap { width: 240px; }
        .client-search-wrap .input-group {
            box-shadow: 0 2px 8px rgba(10,39,72,.05); border-radius: 8px; overflow: hidden;
        }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    </style>
</head>
<body>

<?php if ($user_role === 'super_admin'): ?>
    <?php $currentPage = 'usuarios'; require_once '_sidebar_super_admin.php'; ?>
<?php else: ?>
<div class="sidebar">
    <div class="sidebar-brand"><i class="fas fa-crown"></i> Super_IA</div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Principal</div>
        <a href="index.php" class="sidebar-link"><i class="fas fa-home"></i> Dashboard</a>
        <a href="mapa_vivo.php" class="sidebar-link"><i class="fas fa-map"></i> Mapa en Vivo</a>
        <a href="mapa_calor.php" class="sidebar-link"><i class="fas fa-fire"></i> Mapa de Calor</a>
        <a href="historial_rutas.php" class="sidebar-link"><i class="fas fa-history"></i> Historial de Viajes</a>
    </div>
    <div class="sidebar-section">
        <div class="sidebar-section-title">Gestión</div>
        <a href="usuarios.php" class="sidebar-link active"><i class="fas fa-users"></i> Usuarios</a>
        <a href="clientes.php" class="sidebar-link"><i class="fas fa-briefcase"></i> Clientes</a>
        <a href="operaciones.php" class="sidebar-link"><i class="fas fa-handshake"></i> Operaciones</a>
        <a href="alertas.php" class="sidebar-link"><i class="fas fa-bell"></i> Alertas</a>
    </div>
</div>
<?php endif; ?>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-users me-2" style="color:#ffdd00;"></i>Super_IA - <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></h2>
        <div class="user-info">
            <div>
                <strong><?php echo htmlspecialchars($user_nombre); ?></strong><br>
                <small><?php echo htmlspecialchars($user_rol); ?></small>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <div class="content-area">
        <div class="page-header">
            <h1><i class="fas fa-users me-2"></i>Usuarios del Sistema</h1>
            <p class="text-muted mt-2">Filtra por banco/cooperativa para ver su jerarquía de gerentes, supervisores y asesores</p>
        </div>

        <?php if ($error_carga): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>No se pudo cargar la información: <?php echo htmlspecialchars($error_carga); ?>
        </div>
        <?php endif; ?>

        <div class="filtro-bar">
            <div class="field">
                <label for="filtro-banco-buscar">Banco / Cooperativa</label>
                <input type="text" id="filtro-banco-buscar" placeholder="Escribe para buscar…" autocomplete="off">
                <input type="hidden" id="filtro-banco">
                <button type="button" class="coop-buscador-clear" id="filtro-banco-clear" title="Quitar filtro">
                    <i class="fas fa-times-circle"></i>
                </button>
                <div id="filtro-banco-lista" class="coop-buscador-list"></div>
            </div>
            <div class="field search-field">
                <label for="searchUsers">Buscar gerente</label>
                <div class="input-group" style="border-radius:9px;overflow:hidden;border:1.5px solid #E2E8F0;">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" id="searchUsers" class="form-control border-0" style="font-size:13.5px;padding:9px 12px;" placeholder="Nombre o email del gerente...">
                </div>
            </div>
            <div class="stats-chip">
                <div class="chip">Gerentes<br><b id="cnt-gerentes"><?= $totalGerentes ?></b></div>
                <div class="chip">Supervisores<br><b><?= $totalSupervisores ?></b></div>
                <div class="chip">Asesores<br><b><?= $totalAsesores ?></b></div>
            </div>
        </div>

        <!-- GRID DE GERENTES -->
        <?php if (empty($gerentes)): ?>
            <div class="empty-msg" style="display:block;">
                <i class="fas fa-users-slash"></i>
                No hay gerentes registrados en el sistema.
            </div>
        <?php else: ?>
        <div class="cards-grid" id="gerentesGrid">
            <?php foreach ($gerentes as $ger):
                $gid       = (string)$ger['gerente_id'];
                $gidEsc    = htmlspecialchars($gid, ENT_QUOTES, 'UTF-8');
                $nombre    = htmlspecialchars($ger['nombre'] ?? '');
                $inicial   = strtoupper(mb_substr(trim($ger['nombre'] ?? '?'), 0, 1));
                $email     = htmlspecialchars($ger['email'] ?? '');
                $bancoId   = (string)($ger['banco_id'] ?? '');
                $bancoNom  = htmlspecialchars($bancoNombrePorId[$bancoId] ?? 'Sin banco asignado');
                $activo    = !empty($ger['activo']);
                $numSup    = count($supervisoresPorGerente[$gid] ?? []);
            ?>
            <div class="uc" id="ger-<?= $gidEsc ?>"
                 data-banco-id="<?= htmlspecialchars($bancoId, ENT_QUOTES, 'UTF-8') ?>"
                 data-search-name="<?= strtolower($nombre) ?>"
                 data-search-email="<?= strtolower($email) ?>">
                <div class="uc-stripe gerente"></div>
                <div class="uc-body" onclick="toggleGerente('<?= $gidEsc ?>')">
                    <div class="uc-avatar gerente"><?= $inicial ?></div>
                    <h3 class="uc-name"><?= $nombre ?></h3>
                    <p class="uc-email"><i class="fas fa-envelope me-1" style="color:#94a3b8;font-size:10px;"></i><?= $email ?></p>
                    <span class="banco-tag"><i class="fas fa-building"></i> <?= $bancoNom ?></span>
                    <div class="mt-2"><?= $activo ? '<span class="pill-activo"><i class="fas fa-check-circle"></i> Activo</span>' : '<span class="pill-inactivo"><i class="fas fa-times-circle"></i> Inactivo</span>' ?></div>
                </div>
                <div class="uc-footer">
                    <span class="uc-badge gerente"><i class="fas fa-user-tie"></i> <?= $numSup ?> supervisor<?= $numSup !== 1 ? 'es' : '' ?></span>
                    <span class="uc-arrow" id="arrow-ger-<?= $gidEsc ?>" onclick="event.stopPropagation(); toggleGerente('<?= $gidEsc ?>')">
                        <i class="fas fa-chevron-right"></i>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="empty-msg d-none" id="gerentes-empty" style="grid-column:1/-1;">
                <i class="fas fa-search-minus"></i> No se encontraron gerentes con este filtro.
            </div>
        </div>

        <!-- PANELES DE SUPERVISORES (uno por gerente) -->
        <?php foreach ($gerentes as $ger):
            $gid      = (string)$ger['gerente_id'];
            $gidEsc   = htmlspecialchars($gid, ENT_QUOTES, 'UTF-8');
            $gerNom   = htmlspecialchars($ger['nombre'] ?? '');
            $supsDeGer = $supervisoresPorGerente[$gid] ?? [];
        ?>
        <div class="nested-panel" id="panel-ger-<?= $gidEsc ?>">
            <div class="nested-panel-header">
                <div class="nested-panel-title">
                    <i class="fas fa-user-tie" style="color:#3b82f6;"></i>
                    Supervisores de <strong><?= $gerNom ?></strong>
                    <span class="banco-tag" style="margin:0;"><?= count($supsDeGer) ?></span>
                </div>
            </div>
            <div class="cards-grid">
                <?php if (empty($supsDeGer)): ?>
                    <div class="empty-msg"><i class="fas fa-inbox"></i>Sin supervisores asignados</div>
                <?php else: foreach ($supsDeGer as $sup):
                    $sid      = (string)$sup['supervisor_id'];
                    $sidEsc   = htmlspecialchars($sid, ENT_QUOTES, 'UTF-8');
                    $sNombre  = htmlspecialchars($sup['nombre'] ?? '');
                    $sInicial = strtoupper(mb_substr(trim($sup['nombre'] ?? '?'), 0, 1));
                    $sEmail   = htmlspecialchars($sup['email'] ?? '');
                    $sActivo  = !empty($sup['activo']);
                    $numAse   = (int)$sup['total_asesores'];
                ?>
                <div class="uc" id="sup-<?= $sidEsc ?>">
                    <div class="uc-stripe supervisor"></div>
                    <div class="uc-body" onclick="toggleSupervisor('<?= $sidEsc ?>')">
                        <div class="uc-avatar supervisor"><?= $sInicial ?></div>
                        <h3 class="uc-name"><?= $sNombre ?></h3>
                        <p class="uc-email"><i class="fas fa-envelope me-1" style="color:#94a3b8;font-size:10px;"></i><?= $sEmail ?></p>
                        <div class="mt-2"><?= $sActivo ? '<span class="pill-activo"><i class="fas fa-check-circle"></i> Activo</span>' : '<span class="pill-inactivo"><i class="fas fa-times-circle"></i> Inactivo</span>' ?></div>
                    </div>
                    <div class="uc-footer">
                        <span class="uc-badge supervisor"><i class="fas fa-briefcase"></i> <?= $numAse ?> asesor<?= $numAse !== 1 ? 'es' : '' ?></span>
                        <span class="uc-arrow" id="arrow-sup-<?= $sidEsc ?>" onclick="event.stopPropagation(); toggleSupervisor('<?= $sidEsc ?>')">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- PANELES DE ASESORES (uno por supervisor) -->
        <?php foreach ($supervisores as $sup):
            $sid      = (string)$sup['supervisor_id'];
            $sidEsc   = htmlspecialchars($sid, ENT_QUOTES, 'UTF-8');
            $supNom   = htmlspecialchars($sup['nombre'] ?? '');
            $asesDeSup = $asesoresPorSupervisor[$sid] ?? [];
        ?>
        <div class="nested-panel" id="panel-sup-<?= $sidEsc ?>" style="margin-left:20px;border-left:4px solid #3b82f6;">
            <div class="nested-panel-header">
                <div class="nested-panel-title">
                    <i class="fas fa-briefcase" style="color:#10b981;"></i>
                    Asesores de <strong><?= $supNom ?></strong>
                    <span class="banco-tag" style="margin:0;"><?= count($asesDeSup) ?></span>
                </div>
            </div>
            <div class="cards-grid">
                <?php if (empty($asesDeSup)): ?>
                    <div class="empty-msg"><i class="fas fa-inbox"></i>Sin asesores asignados</div>
                <?php else: foreach ($asesDeSup as $ase):
                    $aid      = (string)$ase['asesor_id'];
                    $aidEsc   = htmlspecialchars($aid, ENT_QUOTES, 'UTF-8');
                    $aNombre  = htmlspecialchars($ase['nombre'] ?? '');
                    $aInicial = strtoupper(mb_substr(trim($ase['nombre'] ?? '?'), 0, 1));
                    $aEmail   = htmlspecialchars($ase['email'] ?? '');
                    $aActivo  = !empty($ase['activo']);
                    $aClientes = (int)$ase['total_clientes'];
                ?>
                <div class="uc" id="ase-<?= $aidEsc ?>">
                    <div class="uc-stripe asesor"></div>
                    <div class="uc-body" onclick="toggleAsesor('<?= $aidEsc ?>')">
                        <div class="uc-avatar asesor"><?= $aInicial ?></div>
                        <h3 class="uc-name"><?= $aNombre ?></h3>
                        <p class="uc-email"><i class="fas fa-envelope me-1" style="color:#94a3b8;font-size:10px;"></i><?= $aEmail ?></p>
                        <div class="mt-2"><?= $aActivo ? '<span class="pill-activo"><i class="fas fa-check-circle"></i> Activo</span>' : '<span class="pill-inactivo"><i class="fas fa-times-circle"></i> Inactivo</span>' ?></div>
                    </div>
                    <div class="uc-footer">
                        <span class="uc-badge asesor"><i class="fas fa-user-group"></i> <?= $aClientes ?> cliente<?= $aClientes !== 1 ? 's' : '' ?></span>
                        <span class="uc-arrow" id="arrow-ase-<?= $aidEsc ?>" onclick="event.stopPropagation(); toggleAsesor('<?= $aidEsc ?>')">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- PANELES DE CLIENTES (uno por asesor) -->
        <?php foreach ($asesores as $ase):
            $aid       = (string)$ase['asesor_id'];
            $aidEsc    = htmlspecialchars($aid, ENT_QUOTES, 'UTF-8');
            $aseNom    = htmlspecialchars($ase['nombre'] ?? '');
            $clientesDeAse = $clientesPorAsesor[$aid] ?? [];
        ?>
        <div class="nested-panel" id="panel-ase-<?= $aidEsc ?>" style="margin-left:40px;border-left:4px solid #10b981;">
            <div class="nested-panel-header">
                <div class="nested-panel-title">
                    <i class="fas fa-users" style="color:#059669;"></i>
                    Clientes de <strong><?= $aseNom ?></strong>
                    <span class="banco-tag" style="margin:0;"><?= count($clientesDeAse) ?></span>
                </div>
                <?php if (!empty($clientesDeAse)): ?>
                <div class="client-search-wrap">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0" style="padding:6px 10px;font-size:13px;"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control border-start-0 client-search-input" style="padding:6px 10px;font-size:13px;" placeholder="Buscar por nombre o cédula..." data-asesor-id="<?= $aidEsc ?>" oninput="filterClientesAsesor(this)">
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="clientes-grid">
                <?php if (empty($clientesDeAse)): ?>
                    <div class="cc-empty"><i class="fas fa-inbox"></i>Sin clientes asignados aún</div>
                <?php else: foreach ($clientesDeAse as $c):
                    $cNombre = htmlspecialchars($c['nombre'] ?? '');
                    $cCedula = htmlspecialchars($c['cedula'] ?? '');
                    $cEmail  = htmlspecialchars($c['email'] ?? '');
                    $cTel    = htmlspecialchars($c['telefono2'] ?? $c['telefono'] ?? '');
                    $cActivo = !empty($c['activo']);
                ?>
                <div class="cc" data-search-name="<?= strtolower($cNombre) ?>" data-search-cedula="<?= strtolower($cCedula) ?>">
                    <div class="cc-top">
                        <div class="cc-icon"><i class="fas fa-user"></i></div>
                        <div style="min-width:0;">
                            <p class="cc-name"><?= $cNombre ?></p>
                            <?php if ($cCedula): ?><p class="cc-ci"><i class="fas fa-id-card" style="margin-right:3px;"></i>CI: <?= $cCedula ?></p><?php endif; ?>
                        </div>
                    </div>
                    <div class="cc-contact">
                        <?php if ($cEmail): ?><span><i class="fas fa-envelope"></i><?= $cEmail ?></span><?php endif; ?>
                        <?php if ($cTel): ?><span><i class="fas fa-phone"></i><?= $cTel ?></span><?php endif; ?>
                    </div>
                    <div class="cc-status">
                        <?= $cActivo ? '<span class="pill-activo"><i class="fas fa-check-circle"></i> Activo</span>' : '<span class="pill-inactivo"><i class="fas fa-times-circle"></i> Inactivo</span>' ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="cc-empty w-100 d-none client-search-empty" style="grid-column:1/-1;">
                    <i class="fas fa-search-minus"></i> No se encontraron clientes coincidentes
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Datos completos de bancos para el combobox de búsqueda ──
const BANCOS = <?= json_encode(array_map(fn($b) => ['id' => (string)$b['id'], 'nombre' => $b['nombre']], $bancos), JSON_UNESCAPED_UNICODE) ?>;

const hiddenBanco = document.getElementById('filtro-banco');
const bancoBuscarInput = document.getElementById('filtro-banco-buscar');
const bancoClearBtn = document.getElementById('filtro-banco-clear');

initCooperativaBuscador({
    inputId:  'filtro-banco-buscar',
    hiddenId: 'filtro-banco',
    listId:   'filtro-banco-lista',
    data: BANCOS,
    onSelect: function () {
        bancoClearBtn.classList.add('show');
        aplicarFiltroBanco();
    }
});

bancoClearBtn.addEventListener('click', () => {
    bancoBuscarInput.value = '';
    hiddenBanco.value = '';
    bancoClearBtn.classList.remove('show');
    aplicarFiltroBanco();
});
bancoBuscarInput.addEventListener('input', () => {
    bancoClearBtn.classList.toggle('show', !!hiddenBanco.value);
    if (!hiddenBanco.value) aplicarFiltroBanco();
});

// ── Filtrar tarjetas de gerente por banco seleccionado ──
function aplicarFiltroBanco() {
    const bancoId = hiddenBanco.value;
    const cards = document.querySelectorAll('#gerentesGrid .uc');
    let visibles = 0;
    cards.forEach(card => {
        const matches = !bancoId || card.getAttribute('data-banco-id') === bancoId;
        card.style.display = matches ? '' : 'none';
        if (matches) visibles++;
    });
    const emptyMsg = document.getElementById('gerentes-empty');
    if (emptyMsg) emptyMsg.classList.toggle('d-none', visibles > 0);

    const cnt = document.getElementById('cnt-gerentes');
    if (cnt) cnt.textContent = visibles;

    // Re-aplica también el filtro de texto por si había algo escrito
    filtrarPorTexto();
}

// ── Buscador de texto sobre las tarjetas de gerente visibles ──
function filtrarPorTexto() {
    const q = (document.getElementById('searchUsers').value || '').toLowerCase().trim();
    const bancoId = hiddenBanco.value;
    const cards = document.querySelectorAll('#gerentesGrid .uc');
    let visibles = 0;
    cards.forEach(card => {
        const matchBanco = !bancoId || card.getAttribute('data-banco-id') === bancoId;
        const name = card.getAttribute('data-search-name') || '';
        const email = card.getAttribute('data-search-email') || '';
        const matchTexto = !q || name.includes(q) || email.includes(q);
        const visible = matchBanco && matchTexto;
        card.style.display = visible ? '' : 'none';
        if (visible) visibles++;
    });
    const emptyMsg = document.getElementById('gerentes-empty');
    if (emptyMsg) emptyMsg.classList.toggle('d-none', visibles > 0);
    const cnt = document.getElementById('cnt-gerentes');
    if (cnt) cnt.textContent = visibles;
}
document.getElementById('searchUsers').addEventListener('input', filtrarPorTexto);

// ── Acordeón: gerente -> supervisores ──
let gerenteActivo = null;
function toggleGerente(id) {
    if (gerenteActivo === id) { cerrarGerente(id); return; }
    if (gerenteActivo) cerrarGerente(gerenteActivo);

    gerenteActivo = id;
    document.getElementById('ger-' + id)?.classList.add('active');
    const panel = document.getElementById('panel-ger-' + id);
    if (panel) {
        panel.classList.add('show');
        setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 80);
    }
}
function cerrarGerente(id) {
    document.getElementById('ger-' + id)?.classList.remove('active');
    document.getElementById('panel-ger-' + id)?.classList.remove('show');
    if (gerenteActivo === id) gerenteActivo = null;
    // Cerrar también cualquier panel de supervisor que hubiera quedado abierto dentro
    if (supervisorActivo) cerrarSupervisor(supervisorActivo);
}

// ── Acordeón: supervisor -> asesores ──
let supervisorActivo = null;
function toggleSupervisor(id) {
    if (supervisorActivo === id) { cerrarSupervisor(id); return; }
    if (supervisorActivo) cerrarSupervisor(supervisorActivo);

    supervisorActivo = id;
    document.getElementById('sup-' + id)?.classList.add('active');
    const panel = document.getElementById('panel-sup-' + id);
    if (panel) {
        panel.classList.add('show');
        setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 80);
    }
}
function cerrarSupervisor(id) {
    document.getElementById('sup-' + id)?.classList.remove('active');
    document.getElementById('panel-sup-' + id)?.classList.remove('show');
    if (supervisorActivo === id) supervisorActivo = null;
    // Cerrar también cualquier panel de clientes que hubiera quedado abierto dentro
    if (asesorActivo) cerrarAsesor(asesorActivo);
}

// ── Acordeón: asesor -> clientes ──
let asesorActivo = null;
function toggleAsesor(id) {
    if (asesorActivo === id) { cerrarAsesor(id); return; }
    if (asesorActivo) cerrarAsesor(asesorActivo);

    asesorActivo = id;
    document.getElementById('ase-' + id)?.classList.add('active');
    const panel = document.getElementById('panel-ase-' + id);
    if (panel) {
        panel.classList.add('show');
        setTimeout(() => panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 80);
    }
}
function cerrarAsesor(id) {
    document.getElementById('ase-' + id)?.classList.remove('active');
    document.getElementById('panel-ase-' + id)?.classList.remove('show');
    if (asesorActivo === id) asesorActivo = null;
}

// ── Buscar cliente por nombre o cédula dentro del panel de un asesor ──
function filterClientesAsesor(input) {
    const query = input.value.toLowerCase().trim();
    const asesorId = input.getAttribute('data-asesor-id');
    const panel = document.getElementById('panel-ase-' + asesorId);
    if (!panel) return;

    const cards = panel.querySelectorAll('.clientes-grid .cc');
    let visibles = 0;
    cards.forEach(card => {
        const name = card.getAttribute('data-search-name') || '';
        const cedula = card.getAttribute('data-search-cedula') || '';
        const match = !query || name.includes(query) || cedula.includes(query);
        card.style.display = match ? '' : 'none';
        if (match) visibles++;
    });
    const emptyMsg = panel.querySelector('.client-search-empty');
    if (emptyMsg) emptyMsg.classList.toggle('d-none', visibles > 0 || cards.length === 0);
}
</script>
</body>
</html>

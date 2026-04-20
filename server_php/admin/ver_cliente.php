<?php
// ============================================================
// admin/ver_cliente.php — Super_IA Logan
// Vista detallada del cliente: encuestas, productos solicitados y fichas
// ============================================================
require_once 'db_admin.php'; // PDO

function table_exists_pdo(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function column_exists_pdo(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetchColumn();
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function cliente_es_cliente_por_aprobacion(PDO $pdo, string $clienteId, ?string $cedula): bool {
    $cedula = $cedula ? trim($cedula) : '';
    $clienteId = trim($clienteId);

    try {
        if ($cedula && table_exists_pdo($pdo, 'ficha_producto') && column_exists_pdo($pdo, 'ficha_producto', 'estado_revision')) {
            $st = $pdo->prepare("SELECT 1 FROM ficha_producto WHERE cliente_cedula = ? AND estado_revision = 'aprobada' LIMIT 1");
            $st->execute([$cedula]);
            if ($st->fetchColumn()) return true;
        }

        if ($clienteId && table_exists_pdo($pdo, 'credito_proceso')) {
            $has_estado_credito = column_exists_pdo($pdo, 'credito_proceso', 'estado_credito');
            $has_estado = column_exists_pdo($pdo, 'credito_proceso', 'estado');
            $estadoCol = $has_estado_credito ? 'estado_credito' : ($has_estado ? 'estado' : null);
            if ($estadoCol) {
                $st = $pdo->prepare("SELECT 1 FROM credito_proceso WHERE cliente_prospecto_id = ? AND $estadoCol IN ('aprobado','desembolsado') LIMIT 1");
                $st->execute([$clienteId]);
                if ($st->fetchColumn()) return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

// ── Autenticación ────────────────────────────────────────────
if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
} else {
    header('Location: login.php?role=admin');
    exit;
}

$cliente_id    = $_GET['id']     ?? '';
$cliente_cedula = $_GET['cedula'] ?? '';

// Si viene por cédula, resolver el id
if (!$cliente_id && $cliente_cedula) {
    try {
        $stC = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
        $stC->execute([$cliente_cedula]);
        $cliente_id = $stC->fetchColumn() ?: '';
    } catch (PDOException $e) {}
}

if (!$cliente_id) {
    header('Location: clientes.php');
    exit;
}

// ── 1. Datos básicos del cliente ─────────────────────────────
$cliente = null;
try {
    $st = $pdo->prepare("
        SELECT cp.*,
               u.nombre AS asesor_nombre, u.email AS asesor_email
        FROM   cliente_prospecto cp
        LEFT JOIN asesor a ON a.id = cp.asesor_id
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE cp.id = ?
        LIMIT 1
    ");
    $st->execute([$cliente_id]);
    $cliente = $st->fetch();
} catch (PDOException $e) { /* silencioso */ }

if (!$cliente) {
    header('Location: clientes.php?error=cliente_no_encontrado');
    exit;
}

// ── 2. Encuesta comercial ─────────────────────────────────────
$encuesta = null;
try {
    $st = $pdo->prepare("SELECT * FROM encuesta_comercial WHERE cliente_prospecto_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$cliente_id]);
    $encuesta = $st->fetch();
} catch (PDOException $e) { $encuesta = null; }

// ── 3. Tareas del cliente ─────────────────────────────────────
$tareas = [];
try {
    $st = $pdo->prepare("
        SELECT t.*, u.nombre AS asesor_nombre
        FROM   tarea t
        LEFT JOIN asesor a ON a.id = t.asesor_id
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE  t.cliente_prospecto_id = ?
        ORDER  BY t.created_at DESC
    ");
    $st->execute([$cliente_id]);
    $tareas = $st->fetchAll();
} catch (PDOException $e) { $tareas = []; }

// ── 4. Fichas de producto ─────────────────────────────────────
$fichas = [];
$ficha_credito     = null;
$ficha_corriente   = null;
$ficha_ahorros     = null;
$ficha_inversiones = null;

try {
    $st = $pdo->prepare("SELECT * FROM ficha_producto WHERE cliente_cedula = ? ORDER BY created_at DESC");
    $st->execute([$cliente['cedula'] ?? '']);
    $fichas = $st->fetchAll();
} catch (PDOException $e) { $fichas = []; }

// Cargar detalles de cada tipo
foreach ($fichas as $ficha) {
    try {
        switch ($ficha['producto_tipo']) {
            case 'credito':
                if (!$ficha_credito) {
                    $st = $pdo->prepare("SELECT * FROM ficha_credito WHERE ficha_id = ? LIMIT 1");
                    $st->execute([$ficha['id']]);
                    $row = $st->fetch();
                    if ($row) $ficha_credito = array_merge($ficha, $row);
                }
                break;
            case 'cuenta_corriente':
                if (!$ficha_corriente) {
                    $st = $pdo->prepare("SELECT * FROM ficha_cuenta_corriente WHERE ficha_id = ? LIMIT 1");
                    $st->execute([$ficha['id']]);
                    $row = $st->fetch();
                    if ($row) $ficha_corriente = array_merge($ficha, $row);
                }
                break;
            case 'cuenta_ahorros':
                if (!$ficha_ahorros) {
                    $st = $pdo->prepare("SELECT * FROM ficha_cuenta_ahorros WHERE ficha_id = ? LIMIT 1");
                    $st->execute([$ficha['id']]);
                    $row = $st->fetch();
                    if ($row) $ficha_ahorros = array_merge($ficha, $row);
                }
                break;
            case 'inversiones':
                if (!$ficha_inversiones) {
                    $st = $pdo->prepare("SELECT * FROM ficha_inversiones WHERE ficha_id = ? LIMIT 1");
                    $st->execute([$ficha['id']]);
                    $row = $st->fetch();
                    if ($row) $ficha_inversiones = array_merge($ficha, $row);
                }
                break;
        }
    } catch (PDOException $e) { /* tabla puede no existir */ }
}

// ── 5. Trámites formales de crédito (credito_proceso) ─────────
$tramites_credito = [];
try {
    $st = $pdo->prepare("
        SELECT cp.*,
               u.nombre AS asesor_nombre
        FROM   credito_proceso cp
        LEFT JOIN asesor a ON a.id = cp.asesor_id
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE  cp.cliente_prospecto_id = ?
        ORDER  BY cp.created_at DESC
    ");
    $st->execute([$cliente_id]);
    $tramites_credito = $st->fetchAll();
} catch (PDOException $e) { $tramites_credito = []; }

// ── Helpers de presentación ───────────────────────────────────
function yn($v, $si = 'Sí', $no = 'No'): string {
    if ($v === null || $v === '') return '<span class="dato-vacio">—</span>';
    return (intval($v) === 1 || $v === 'si' || $v === 'true' || $v === 1)
        ? "<span class='chip-si'>$si</span>"
        : "<span class='chip-no'>$no</span>";
}
function dato($v, string $suffix = ''): string {
    if ($v === null || trim((string)$v) === '') return '<span class="dato-vacio">—</span>';
    return '<strong>' . htmlspecialchars($v) . '</strong>' . ($suffix ? " $suffix" : '');
}
function etiq(string $label, $value, string $suffix = ''): string {
    return '<div class="dato-row"><span class="dato-label">' . htmlspecialchars($label) . '</span><span class="dato-val">' . dato($value, $suffix) . '</span></div>';
}
function etiqYN(string $label, $value, string $si = 'Sí', string $no = 'No'): string {
    return '<div class="dato-row"><span class="dato-label">' . htmlspecialchars($label) . '</span><span class="dato-val">' . yn($value, $si, $no) . '</span></div>';
}
function chips(array $items): string {
    if (empty($items)) return '<span class="dato-vacio">Ninguno</span>';
    return implode(' ', array_map(fn($i) => "<span class='chip-prod'>$i</span>", $items));
}

$is_supervisor = ($user_role === 'supervisor');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super_IA — Detalle Prospecto / Cliente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-yellow:      #ffdd00;
            --brand-yellow-deep: #f4c400;
            --brand-navy:        #123a6d;
            --brand-navy-deep:   #0a2748;
            --brand-gray:        #6b7280;
            --brand-border:      #d7e0ea;
            --brand-card:        #ffffff;
            --brand-bg:          #f4f6f9;
            --brand-shadow:      0 16px 34px rgba(18,58,109,.08);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter','Segoe UI',sans-serif; background:var(--brand-bg); display:flex; min-height:100vh; color:var(--brand-navy-deep); }

        /* ── SIDEBAR ── */
        .sidebar { width:230px; background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%); color:#fff; padding:20px 0; overflow-y:auto; position:fixed; height:100vh; left:0; top:0; z-index:100; }
        .brand { padding:0 20px 24px; font-size:18px; font-weight:800; border-bottom:1px solid rgba(255,221,0,.18); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .brand i { color:var(--brand-yellow); }
        .section-label { font-size:11px; text-transform:uppercase; color:rgba(255,255,255,.5); letter-spacing:.6px; padding:0 25px; margin-bottom:10px; font-weight:700; }
        .sidebar a { display:flex; align-items:center; gap:12px; padding:11px 20px; color:rgba(255,255,255,.82); text-decoration:none; font-size:14px; border-radius:10px; margin:2px 10px; transition:all .22s; }
        .sidebar a:hover { background:rgba(255,221,0,.12); color:#fff; padding-left:26px; }
        .sidebar a.active { background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep)); color:var(--brand-navy-deep); font-weight:700; }
        .logout-link { margin-top:auto; border-top:1px solid rgba(255,255,255,.1); padding-top:16px; }

        /* ── MAIN ── */
        .main-content { flex:1; margin-left:230px; display:flex; flex-direction:column; overflow:hidden; min-width:0; }
        .navbar-custom { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 12px 28px rgba(18,58,109,.18); flex-shrink:0; }
        .navbar-custom h2 { margin:0; font-size:20px; font-weight:700; }
        .user-info { display:flex; align-items:center; gap:15px; }
        .btn-logout { background:rgba(255,221,0,.15); color:#fff; border:1px solid rgba(255,221,0,.28); padding:8px 15px; border-radius:10px; text-decoration:none; font-weight:600; }
        .btn-logout:hover { background:rgba(255,221,0,.26); color:#fff; }
        .content-area { flex:1; overflow-y:auto; padding:30px; }

        /* ── PAGE HEADER ── */
        .page-header { margin-bottom:26px; }
        .page-header h1 { font-size:25px; font-weight:800; color:var(--brand-navy-deep); }
        .btn-back { padding:8px 18px; background:rgba(18,58,109,.08); color:var(--brand-navy-deep); border:1.5px solid var(--brand-border); border-radius:10px; text-decoration:none; font-weight:600; margin-bottom:20px; display:inline-flex; align-items:center; gap:8px; font-size:13.5px; }
        .btn-back:hover { background:rgba(18,58,109,.15); color:var(--brand-navy-deep); }

        /* ── AVATAR / HEADER CARD ── */
        .client-hero { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); border-radius:18px; padding:28px 32px; color:#fff; display:flex; align-items:center; gap:24px; margin-bottom:24px; box-shadow:0 8px 28px rgba(18,58,109,.18); }
        .client-avatar { width:72px; height:72px; background:var(--brand-yellow); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:900; color:var(--brand-navy-deep); flex-shrink:0; }
        .client-hero-info h2 { font-size:22px; font-weight:800; margin-bottom:4px; }
        .client-hero-info p { opacity:.8; font-size:14px; margin:0; }
        .client-hero-badges { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
        .hero-badge { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.22); border-radius:20px; padding:4px 14px; font-size:12px; font-weight:600; }
        .hero-badge.yellow { background:var(--brand-yellow); color:var(--brand-navy-deep); border-color:transparent; }

        /* ── SECTION CARD ── */
        .section-card { background:#fff; border-radius:16px; box-shadow:var(--brand-shadow); border:1px solid var(--brand-border); margin-bottom:22px; overflow:hidden; }
        .section-header { padding:16px 22px; border-bottom:1px solid var(--brand-border); display:flex; align-items:center; gap:12px; background:#fafbfc; }
        .section-header .sec-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .sec-blue  { background:rgba(18,58,109,.10); color:var(--brand-navy); }
        .sec-green { background:rgba(16,185,129,.12); color:#059669; }
        .sec-yellow{ background:rgba(245,158,11,.12); color:#d97706; }
        .sec-red   { background:rgba(239,68,68,.10);  color:#dc2626; }
        .sec-purple{ background:rgba(124,58,237,.10); color:#7c3aed; }
        .sec-teal  { background:rgba(20,184,166,.12); color:#0d9488; }
        .section-header h5 { font-size:15px; font-weight:800; margin:0; color:var(--brand-navy-deep); }
        .section-body { padding:20px 22px; }

        /* ── DATOS ── */
        .dato-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0; }
        .dato-row { display:flex; flex-direction:column; padding:10px 0; border-bottom:1px solid rgba(215,224,234,.5); }
        .dato-row:last-child { border-bottom:none; }
        .dato-label { font-size:11.5px; color:var(--brand-gray); font-weight:600; text-transform:uppercase; letter-spacing:.3px; margin-bottom:3px; }
        .dato-val { font-size:14px; color:var(--brand-navy-deep); }
        .dato-vacio { color:#b0bac5; font-style:italic; }

        /* ── CHIPS ── */
        .chip-si  { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:6px; padding:2px 10px; font-size:12px; font-weight:700; }
        .chip-no  { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:2px 10px; font-size:12px; font-weight:700; }
        .chip-prod { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; border-radius:20px; padding:4px 14px; font-size:12px; font-weight:700; display:inline-block; margin:2px; }
        .chip-prod.green  { background:linear-gradient(135deg,#059669,#10b981); }
        .chip-prod.amber  { background:linear-gradient(135deg,#d97706,#f59e0b); }
        .chip-prod.purple { background:linear-gradient(135deg,#7c3aed,#8b5cf6); }
        .chip-prod.teal   { background:linear-gradient(135deg,#0d9488,#14b8a6); }
        .chip-prod.red    { background:linear-gradient(135deg,#dc2626,#ef4444); }

        /* ── TABLA TAREAS ── */
        .task-table { width:100%; border-collapse:collapse; font-size:13.5px; }
        .task-table thead th { background:#f8fafc; font-size:11px; text-transform:uppercase; color:var(--brand-gray); padding:12px 14px; text-align:left; font-weight:700; border-bottom:2px solid var(--brand-border); }
        .task-table tbody td { padding:12px 14px; border-bottom:1px solid rgba(215,224,234,.4); vertical-align:middle; }
        .task-table tbody tr:last-child td { border-bottom:none; }
        .task-table tbody tr:hover { background:rgba(255,221,0,.04); }
        .badge-estado { border-radius:6px; padding:3px 10px; font-size:11.5px; font-weight:700; }
        .badge-completada { background:#ecfdf5; color:#065f46; }
        .badge-pendiente  { background:#fffbeb; color:#92400e; }
        .badge-cancelada  { background:#fef2f2; color:#991b1b; }

        /* ── ACUERDO ── */
        .acuerdo-badge { border-radius:8px; padding:5px 14px; font-size:13px; font-weight:700; display:inline-block; }
        .acuerdo-ninguno       { background:#f3f4f6; color:#6b7280; }
        .acuerdo-nueva_cita    { background:#dbeafe; color:#1e40af; }
        .acuerdo-documentos    { background:#ede9fe; color:#5b21b6; }
        .acuerdo-levantamiento { background:#ecfdf5; color:#065f46; }

        /* ── FICHA SECTION ── */
        .ficha-subsection { margin-bottom:18px; }
        .ficha-subtitle { font-size:12px; text-transform:uppercase; color:var(--brand-navy); font-weight:800; letter-spacing:.4px; margin-bottom:10px; padding-bottom:5px; border-bottom:2px solid var(--brand-yellow); display:flex; align-items:center; gap:7px; }
        .doc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .doc-chip { border-radius:6px; padding:4px 12px; font-size:12px; font-weight:600; }
        .doc-chip.ok  { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .doc-chip.no  { background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; text-decoration:line-through; }

        .empty-state { text-align:center; padding:30px; color:#9ca3af; font-size:14px; }
        .empty-state i { font-size:28px; display:block; margin-bottom:10px; }

        ::-webkit-scrollbar { width:6px; }
        ::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }

        @media (max-width:768px) { .client-hero { flex-direction:column; text-align:center; } .dato-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<!-- ══════════ SIDEBAR ══════════ -->
<div class="sidebar">
    <div class="brand"><i class="fas fa-star"></i><span>Super_IA</span></div>

    <?php if ($user_role === 'supervisor'): ?>
    <div class="section-label">PRINCIPAL</div>
    <a href="index_supervisor.php"><i class="fas fa-home"></i> Dashboard</a>
    <a href="mis_asesores.php"><i class="fas fa-users"></i> Mis Asesores</a>
    <a href="operaciones.php"><i class="fas fa-briefcase"></i> Operaciones</a>
    <a href="pendientes.php"><i class="fas fa-hourglass-end"></i> Pendientes</a>
    <div class="section-label" style="margin-top:18px;">ANÁLISIS</div>
    <a href="reportes.php"><i class="fas fa-chart-bar"></i> Reportes KPI</a>
    <a href="mapa_vivo.php"><i class="fas fa-map-marked-alt"></i> Ubicaciones</a>
    <a href="alertas.php"><i class="fas fa-bell"></i> Alertas</a>
    <div class="section-label" style="margin-top:18px;">GESTIÓN</div>
    <a href="clientes.php" class="active"><i class="fas fa-address-book"></i> Mis Clientes</a>
    <a href="registro_asesor.php"><i class="fas fa-user-plus"></i> Nuevo Asesor</a>
    <?php elseif ($user_role === 'admin' || $user_role === 'super_admin'): ?>
    <div class="section-label">PRINCIPAL</div>
    <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
    <div class="section-label" style="margin-top:18px;">GESTIÓN</div>
    <a href="usuarios.php"><i class="fas fa-users"></i> Usuarios</a>
    <a href="clientes.php" class="active"><i class="fas fa-address-book"></i> Clientes</a>
    <a href="operaciones.php"><i class="fas fa-briefcase"></i> Operaciones</a>
    <?php elseif ($user_role === 'asesor'): ?>
    <div class="section-label">PRINCIPAL</div>
    <a href="asesor_index.php"><i class="fas fa-home"></i> Mi Dashboard</a>
    <a href="clientes.php" class="active"><i class="fas fa-address-book"></i> Mis Clientes</a>
    <?php endif; ?>

    <div class="logout-link">
        <div class="section-label">SESIÓN</div>
        <a href="logout.php" style="color:rgba(252,165,165,.8)!important;"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
    </div>
</div>

<!-- ══════════ MAIN ══════════ -->
<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><i class="fas fa-address-book me-2" style="color:var(--brand-yellow);"></i>Super_IA — Detalle de Prospecto / Cliente</h2>
        <div class="user-info">
            <div>
                <strong><?php
                if ($user_role==='super_admin') echo htmlspecialchars($_SESSION['super_admin_nombre']);
                elseif ($user_role==='admin') echo htmlspecialchars($_SESSION['admin_nombre']);
                elseif ($user_role==='supervisor') echo htmlspecialchars($_SESSION['supervisor_nombre']);
                else echo htmlspecialchars($_SESSION['asesor_nombre']); ?></strong>
            </div>
            <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content-area">

        <div class="page-header">
            <a href="clientes.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver al listado</a>
            <h1><i class="fas fa-user me-2"></i>Perfil de <?= htmlspecialchars($estado_label) ?></h1>
        </div>

        <!-- ── HERO ── -->
        <?php
        $iniciales = '';
        foreach (explode(' ', $cliente['nombre'] ?? '') as $p) {
            $iniciales .= strtoupper(mb_substr($p, 0, 1));
            if (strlen($iniciales) >= 2) break;
        }
        $estadoDb = strtolower((string)($cliente['estado'] ?? 'prospecto'));
        $estadoColor = ($estadoDb === 'descartado') ? '#ef4444' : '#10b981';

        // Regla final: solo es CLIENTE si tiene al menos una transacción aprobada
        // (crédito/cuenta/inversión). Mientras no se apruebe, es PROSPECTO.
        if ($estadoDb === 'descartado') {
            $estado_label = 'Descartado';
        } else {
            $esCliente = cliente_es_cliente_por_aprobacion($pdo, (string)$cliente_id, (string)($cliente['cedula'] ?? ''));
            $estado_label = $esCliente ? 'Cliente' : 'Prospecto';
        }
        ?>
        <div class="client-hero">
            <div class="client-avatar"><?= htmlspecialchars($iniciales ?: '?') ?></div>
            <div class="client-hero-info" style="flex:1;">
                <h2><?= htmlspecialchars($cliente['nombre'] ?? '—') ?></h2>
                <p>Cédula: <?= htmlspecialchars($cliente['cedula'] ?? '—') ?> &nbsp;|&nbsp; <?= htmlspecialchars($cliente['email'] ?? '—') ?></p>
                <div class="client-hero-badges">
                    <span class="hero-badge yellow"><?= htmlspecialchars($estado_label) ?></span>
                    <?php if ($cliente['asesor_nombre'] ?? null): ?>
                    <span class="hero-badge"><i class="fas fa-user-tie me-1"></i><?= htmlspecialchars($cliente['asesor_nombre']) ?></span>
                    <?php endif; ?>
                    <?php if ($cliente['zona'] ?? null): ?>
                    <span class="hero-badge"><i class="fas fa-map-pin me-1"></i><?= htmlspecialchars($cliente['zona']) ?><?= ($cliente['ciudad'] ?? '') ? ', ' . htmlspecialchars($cliente['ciudad']) : '' ?></span>
                    <?php endif; ?>
                    <?php if ($cliente['created_at'] ?? null): ?>
                    <span class="hero-badge">Registrado: <?= date('d/m/Y', strtotime($cliente['created_at'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── DATOS PERSONALES ── -->
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-blue"><i class="fas fa-id-card"></i></div>
                <h5>Datos Personales</h5>
            </div>
            <div class="section-body">
                <div class="dato-grid">
                    <?= etiq('Nombre completo',  $cliente['nombre']) ?>
                    <?= etiq('Cédula / RUC',     $cliente['cedula']) ?>
                    <?= etiq('Teléfono',          $cliente['telefono'] ?? '') ?>
                    <?= etiq('Celular',           $cliente['celular'] ?? ($cliente['telefono2'] ?? '')) ?>
                    <?= etiq('Email',             $cliente['email']    ?? '') ?>
                    <?= etiq('Dirección',         $cliente['direccion'] ?? '') ?>
                    <?= etiq('Actividad económica', $cliente['actividad'] ?? '') ?>
                    <?= etiq('Nombre empresa',    $cliente['nombre_empresa'] ?? '') ?>
                    <?= etiqYN('Tiene RUC',  $cliente['tiene_ruc']  ?? null) ?>
                    <?= etiqYN('Tiene RISE', $cliente['tiene_rise'] ?? null) ?>
                    <?= etiq('Zona',   $cliente['zona']   ?? '') ?>
                    <?= etiq('Ciudad', $cliente['ciudad'] ?? '') ?>
                </div>
            </div>
        </div>

        <!-- ── ENCUESTA COMERCIAL ── -->
        <?php if ($encuesta): ?>
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-yellow"><i class="fas fa-clipboard-list"></i></div>
                <h5>Encuesta Comercial</h5>
            </div>
            <div class="section-body">

                <!-- Productos actuales -->
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-wallet"></i> Productos que maneja actualmente</div>
                    <?php
                    $prods_actuales = [];
                    if (!empty($encuesta['mantiene_cuenta_ahorro']))    $prods_actuales[] = '<span class="chip-prod green"><i class="fas fa-piggy-bank me-1"></i>Cuenta de Ahorros</span>';
                    if (!empty($encuesta['mantiene_cuenta_corriente'])) $prods_actuales[] = '<span class="chip-prod teal"><i class="fas fa-exchange-alt me-1"></i>Cuenta Corriente</span>';
                    if (!empty($encuesta['tiene_inversiones']))         $prods_actuales[] = '<span class="chip-prod purple"><i class="fas fa-chart-line me-1"></i>Inversiones</span>';
                    if (!empty($encuesta['tiene_operaciones_crediticias'])) $prods_actuales[] = '<span class="chip-prod amber"><i class="fas fa-hand-holding-usd me-1"></i>Crédito activo</span>';
                    echo empty($prods_actuales) ? '<span class="dato-vacio">No reporta productos activos</span>' : implode(' ', $prods_actuales);
                    ?>
                </div>

                <?php if (!empty($encuesta['institucion_inversiones']) || !empty($encuesta['valor_inversion'])): ?>
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-chart-line"></i> Detalle de inversión actual</div>
                    <div class="dato-grid">
                        <?= etiq('Institución',       $encuesta['institucion_inversiones'] ?? '') ?>
                        <?= etiq('Valor inversión',   $encuesta['valor_inversion']         ?? '', 'USD') ?>
                        <?= etiq('Plazo',             $encuesta['plazo_inversion']          ?? '') ?>
                        <?= etiq('Fecha vencimiento', $encuesta['fecha_vencimiento_inversion'] ?? '') ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($encuesta['institucion_credito'])): ?>
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-university"></i> Crédito / Producto financiero actual</div>
                    <div class="dato-grid">
                        <?= etiq('Institución crédito',          $encuesta['institucion_credito']          ?? '') ?>
                        <?= etiq('Institución prod. financiero', $encuesta['institucion_producto_financiero'] ?? '') ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Interés en productos -->
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-star"></i> Interés en nuestros productos</div>
                    <?php
                    $interes = [];
                    if (!empty($encuesta['interes_cc']))        $interes[] = '<span class="chip-prod teal"><i class="fas fa-exchange-alt me-1"></i>Cuenta Corriente</span>';
                    if (!empty($encuesta['interes_ahorro']))    $interes[] = '<span class="chip-prod green"><i class="fas fa-piggy-bank me-1"></i>Cuenta de Ahorros</span>';
                    if (!empty($encuesta['interes_inversion'])) $interes[] = '<span class="chip-prod purple"><i class="fas fa-chart-line me-1"></i>Inversiones</span>';
                    if (!empty($encuesta['interes_credito']))   $interes[] = '<span class="chip-prod amber"><i class="fas fa-hand-holding-usd me-1"></i>Crédito</span>';
                    echo empty($interes) ? '<span class="dato-vacio">Ninguno registrado</span>' : implode(' ', $interes);
                    ?>
                    <?php if (!empty($encuesta['nivel_interes']) && $encuesta['nivel_interes'] !== 'ninguno'): ?>
                    <div style="margin-top:8px;"><?= etiq('Nivel de interés', ucfirst($encuesta['nivel_interes'])) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Razones de no interés -->
                <?php
                $razones = [];
                if (!empty($encuesta['razon_ya_trabaja']))  $razones[] = 'Ya trabaja con otra institución';
                if (!empty($encuesta['razon_desconfia']))   $razones[] = 'Desconfía de servicios financieros';
                if (!empty($encuesta['razon_agusto']))      $razones[] = 'Agusto con institución actual';
                if (!empty($encuesta['razon_mala_exp']))    $razones[] = 'Mala experiencia previa';
                if (!empty($encuesta['razon_otros']))       $razones[] = htmlspecialchars($encuesta['razon_otros']);
                if (!empty($razones)):
                ?>
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-times-circle"></i> Razones de no interés</div>
                    <div class="doc-chips">
                        <?php foreach ($razones as $r): ?><span class="doc-chip no"><?= $r ?></span><?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Qué busca -->
                <?php
                $busca = [];
                if (!empty($encuesta['busca_agilidad']))  $busca[] = 'Agilidad';
                if (!empty($encuesta['busca_cajeros']))   $busca[] = 'Cajeros';
                if (!empty($encuesta['busca_banca']))     $busca[] = 'Banca en línea';
                if (!empty($encuesta['busca_agencias']))  $busca[] = 'Agencias cerca';
                if (!empty($encuesta['busca_credito']))   $busca[] = 'Crédito rápido';
                if (!empty($encuesta['busca_td']))        $busca[] = 'Tarjeta débito';
                if (!empty($encuesta['busca_tc']))        $busca[] = 'Tarjeta crédito';
                if (!empty($busca)):
                ?>
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-search"></i> Qué busca en un producto financiero</div>
                    <div class="doc-chips">
                        <?php foreach ($busca as $b): ?><span class="doc-chip ok"><?= htmlspecialchars($b) ?></span><?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- ── TAREAS ── -->
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-teal"><i class="fas fa-tasks"></i></div>
                <h5>Historial de Visitas y Tareas</h5>
            </div>
            <div class="section-body" style="padding:0;">
                <?php if (empty($tareas)): ?>
                <div class="empty-state"><i class="fas fa-calendar-times"></i>Sin tareas registradas</div>
                <?php else: ?>
                <table class="task-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Fecha</th>
                            <th>Estado</th>
                            <th>Acuerdo logrado</th>
                            <th>Asesor</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tareas as $t): ?>
                        <?php
                        $tipoLabel  = ucfirst(str_replace('_', ' ', $t['tipo_tarea'] ?? '—'));
                        $estadoClass= 'badge-pendiente';
                        if (($t['estado'] ?? '') === 'completada') $estadoClass = 'badge-completada';
                        elseif (($t['estado'] ?? '') === 'cancelada') $estadoClass = 'badge-cancelada';
                        $acuerdo = $t['acuerdo_logrado'] ?? 'ninguno';
                        $acuerdoClass = 'acuerdo-ninguno';
                        if (str_starts_with($acuerdo, 'nueva_cita')) $acuerdoClass = 'acuerdo-nueva_cita';
                        elseif (str_starts_with($acuerdo, 'recolectar')) $acuerdoClass = 'acuerdo-documentos';
                        elseif (str_starts_with($acuerdo, 'levantamiento')) $acuerdoClass = 'acuerdo-levantamiento';
                        $acuerdoLabel = ucfirst(str_replace('_', ' ', $acuerdo));
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($tipoLabel) ?></strong></td>
                            <td><?= $t['fecha_programada'] ? date('d/m/Y', strtotime($t['fecha_programada'])) : '—' ?></td>
                            <td><span class="badge-estado <?= $estadoClass ?>"><?= ucfirst($t['estado'] ?? '—') ?></span></td>
                            <td><span class="acuerdo-badge <?= $acuerdoClass ?>"><?= htmlspecialchars($acuerdoLabel) ?></span></td>
                            <td><?= htmlspecialchars($t['asesor_nombre'] ?? '—') ?></td>
                            <td style="max-width:220px;white-space:normal;font-size:13px;"><?= htmlspecialchars($t['observaciones'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════ TRÁMITES FORMALES DE CRÉDITO ══════════ -->
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-blue"><i class="fas fa-handshake"></i></div>
                <h5>Trámites de Crédito</h5>
            </div>
            <div class="section-body" style="padding:0;">
            <?php if (empty($tramites_credito)): ?>
                <div class="empty-state"><i class="fas fa-folder-open"></i>Sin trámites de crédito formales registrados</div>
            <?php else: ?>
                <table class="task-table">
                    <thead>
                        <tr>
                            <th>Estado</th>
                            <th>Monto aprobado</th>
                            <th>Actividad</th>
                            <th>¿Microcrédito?</th>
                            <th>Asesor</th>
                            <th>Documentos</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tramites_credito as $tr):
                        $estado_cred = $tr['estado_credito'] ?? 'prospectado';
                        $estadoColors = [
                            'desembolsado' => ['#10b981','✓ Desembolsado'],
                            'aprobado'     => ['#22c55e','✓ Aprobado'],
                            'analisis'     => ['#3b82f6','🔍 En análisis'],
                            'solicitud'    => ['#6366f1','📋 Solicitud'],
                            'levantamiento'=> ['#f59e0b','📐 Levantamiento'],
                            'entrevista_venta' => ['#8b5cf6','🗣 Entrevista'],
                            'rechazado'    => ['#ef4444','✗ Rechazado'],
                            'recuperacion' => ['#dc2626','⚠ Recuperación'],
                            'prospectado'  => ['#9ca3af','🔎 Prospectado'],
                        ];
                        [$color,$label] = $estadoColors[$estado_cred] ?? ['#9ca3af', ucfirst($estado_cred)];
                        $docs_ok = $tr['documentos_completos'] ? '<span class="chip-si">Completos</span>' : '<span class="chip-no">Incompletos</span>';
                        if (!$tr['documentos_completos'] && !empty($tr['documentos_faltantes'])) {
                            $docs_ok .= '<br><small style="color:#9ca3af;font-size:11px;">' . htmlspecialchars(mb_substr($tr['documentos_faltantes'],0,50)) . '</small>';
                        }
                    ?>
                        <tr>
                            <td>
                                <span style="background:<?= $color ?>;color:#fff;padding:3px 10px;border-radius:6px;font-size:12px;white-space:nowrap;">
                                    <?= $label ?>
                                </span>
                            </td>
                            <td><?= $tr['monto_aprobado'] ? '<strong>$' . number_format($tr['monto_aprobado'],2) . '</strong>' : '<span class="dato-vacio">—</span>' ?></td>
                            <td><?= htmlspecialchars(ucfirst(str_replace('_',' ',$tr['actividad'] ?? ''))) ?: '<span class="dato-vacio">—</span>' ?></td>
                            <td><?= yn($tr['es_microcredito'],'Sí','No') ?></td>
                            <td><?= htmlspecialchars($tr['asesor_nombre'] ?? '—') ?></td>
                            <td><?= $docs_ok ?></td>
                            <td style="white-space:nowrap;font-size:12px;"><?= date('d/m/Y', strtotime($tr['created_at'])) ?></td>
                        </tr>
                        <?php if (!empty($tr['fecha_solicitud']) || !empty($tr['fecha_desembolso'])): ?>
                        <tr style="background:#fafbfc;">
                            <td colspan="7" style="padding:6px 14px;font-size:12px;color:#6b7280;">
                                <?php
                                $fases = [];
                                if ($tr['fecha_prospeccion'])     $fases[] = '🔎 Prospeccion: '     . date('d/m/Y', strtotime($tr['fecha_prospeccion']));
                                if ($tr['fecha_entrevista_venta'])$fases[] = '🗣 Entrevista: '      . date('d/m/Y', strtotime($tr['fecha_entrevista_venta']));
                                if ($tr['fecha_levantamiento'])   $fases[] = '📐 Levantamiento: '   . date('d/m/Y', strtotime($tr['fecha_levantamiento']));
                                if ($tr['fecha_solicitud'])       $fases[] = '📋 Solicitud: '       . date('d/m/Y', strtotime($tr['fecha_solicitud']));
                                if ($tr['fecha_desembolso'])      $fases[] = '💵 Desembolso: '      . date('d/m/Y', strtotime($tr['fecha_desembolso']));
                                echo implode(' &nbsp;·&nbsp; ', $fases);
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>

        <!-- ══════════ FICHAS DE PRODUCTO ══════════ -->
        <?php if ($ficha_credito || $ficha_corriente || $ficha_ahorros || $ficha_inversiones): ?>
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-purple"><i class="fas fa-folder-open"></i></div>
                <h5>Fichas de Productos Solicitados</h5>
            </div>
            <div class="section-body">

                <!-- ── FICHA CRÉDITO ── -->
                <?php if ($ficha_credito): ?>
                <div class="ficha-subsection">
                    <div class="ficha-subtitle" style="border-bottom-color:#f59e0b;">
                        <i class="fas fa-hand-holding-usd" style="color:#d97706;"></i>
                        Ficha de Crédito
                        <small style="font-weight:400;text-transform:none;color:#9ca3af;margin-left:6px;"><?= date('d/m/Y H:i', strtotime($ficha_credito['created_at'])) ?></small>
                    </div>
                    <div class="dato-grid">
                        <?= etiqYN('Requiere crédito',    $ficha_credito['requiere_credito']) ?>
                        <?= etiq('Monto solicitado',       $ficha_credito['monto_credito']       ?? '', 'USD') ?>
                        <?= etiq('Plazo (meses)',          $ficha_credito['plazo_credito_meses']  ?? '') ?>
                        <?= etiq('Solicitante',            $ficha_credito['solicitante_nombre']   ?? '') ?>
                        <?= etiq('Cédula solicitante',     $ficha_credito['solicitante_cedula']   ?? '') ?>
                        <?= etiq('Garante',                $ficha_credito['garante_nombre']       ?? '') ?>
                        <?= etiq('Cédula garante',         $ficha_credito['garante_cedula']       ?? '') ?>
                    </div>
                    <?php
                    $destinos = [];
                    if (!empty($ficha_credito['dest_capital_trabajo']))  $destinos[] = 'Capital de trabajo';
                    if (!empty($ficha_credito['dest_activos_fijos']))    $destinos[] = 'Activos fijos';
                    if (!empty($ficha_credito['dest_pago_deudas']))      $destinos[] = 'Pago de deudas';
                    if (!empty($ficha_credito['dest_consolidacion']))    $destinos[] = 'Consolidación';
                    if (!empty($ficha_credito['dest_vehiculo']))         $destinos[] = 'Vehículo';
                    if (!empty($ficha_credito['dest_vivienda_compra']))  $destinos[] = 'Compra vivienda';
                    if (!empty($ficha_credito['dest_arreglos_vivienda']))$destinos[] = 'Arreglos vivienda';
                    if (!empty($ficha_credito['dest_educacion']))        $destinos[] = 'Educación';
                    if (!empty($ficha_credito['dest_viajes']))           $destinos[] = 'Viajes';
                    if (!empty($ficha_credito['dest_otros']))            $destinos[] = 'Otros: ' . htmlspecialchars($ficha_credito['dest_otros_detalle'] ?? '');
                    if (!empty($destinos)):
                    ?>
                    <div style="margin-top:10px;">
                        <div class="dato-label" style="margin-bottom:6px;">Destino del crédito</div>
                        <div class="doc-chips">
                            <?php foreach ($destinos as $d): ?><span class="doc-chip ok"><?= $d ?></span><?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php
                    $docs = [
                        'doc_cedula'        => 'Cédula',
                        'doc_planilla'      => 'Planilla',
                        'doc_ruc_rise'      => 'RUC/RISE',
                        'doc_estados_cuenta'=> 'Estados de cuenta',
                        'doc_declaraciones' => 'Declaraciones',
                        'doc_matricula'     => 'Matrícula',
                        'doc_foto_negocio'  => 'Foto negocio',
                    ];
                    ?>
                    <div style="margin-top:10px;">
                        <div class="dato-label" style="margin-bottom:6px;">Documentos disponibles</div>
                        <div class="doc-chips">
                            <?php foreach ($docs as $field => $label): ?>
                            <span class="doc-chip <?= !empty($ficha_credito[$field]) ? 'ok' : 'no' ?>">
                                <?php if (!empty($ficha_credito[$field])): ?><i class="fas fa-check me-1"></i><?php endif; ?>
                                <?= $label ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ── FICHA CUENTA CORRIENTE ── -->
                <?php if ($ficha_corriente): ?>
                <div class="ficha-subsection" style="margin-top:20px;">
                    <div class="ficha-subtitle" style="border-bottom-color:#14b8a6;">
                        <i class="fas fa-exchange-alt" style="color:#0d9488;"></i>
                        Ficha de Cuenta Corriente
                        <small style="font-weight:400;text-transform:none;color:#9ca3af;margin-left:6px;"><?= date('d/m/Y H:i', strtotime($ficha_corriente['created_at'])) ?></small>
                    </div>
                    <div class="dato-grid">
                        <?= etiq('Tipo de cuenta',          $ficha_corriente['tipo_cc']             ?? '') ?>
                        <?= etiq('Propósito',               $ficha_corriente['proposito']            ?? '') ?>
                        <?= etiq('Depósito promedio',       $ficha_corriente['monto_deposito_prom']  ?? '', 'USD') ?>
                        <?= etiq('Frecuencia de uso',       $ficha_corriente['frecuencia_uso']       ?? '') ?>
                        <?= etiqYN('Necesita talonario',    $ficha_corriente['necesita_talonario']   ?? null) ?>
                        <?= etiqYN('Maneja cheques',        $ficha_corriente['maneja_cheques']       ?? null) ?>
                        <?= etiq('Número de cheques/mes',   $ficha_corriente['num_cheques_mes']      ?? '') ?>
                        <?= etiq('Origen de fondos',        $ficha_corriente['origen_fondos']        ?? '') ?>
                        <?= etiq('Observaciones',           $ficha_corriente['observaciones']        ?? '') ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ── FICHA CUENTA AHORROS ── -->
                <?php if ($ficha_ahorros): ?>
                <div class="ficha-subsection" style="margin-top:20px;">
                    <div class="ficha-subtitle" style="border-bottom-color:#10b981;">
                        <i class="fas fa-piggy-bank" style="color:#059669;"></i>
                        Ficha de Cuenta de Ahorros
                        <small style="font-weight:400;text-transform:none;color:#9ca3af;margin-left:6px;"><?= date('d/m/Y H:i', strtotime($ficha_ahorros['created_at'])) ?></small>
                    </div>
                    <div class="dato-grid">
                        <?= etiq('Tipo de ahorro',          $ficha_ahorros['tipo_ahorro']          ?? '') ?>
                        <?= etiq('Propósito',               $ficha_ahorros['proposito']             ?? '') ?>
                        <?= etiq('Ahorro mensual estimado', $ficha_ahorros['monto_ahorro_mensual']  ?? '', 'USD') ?>
                        <?= etiq('Frecuencia de depósito',  $ficha_ahorros['frecuencia_deposito']   ?? '') ?>
                        <?= etiqYN('Desea débito automático', $ficha_ahorros['desea_debito_automatico'] ?? null) ?>
                        <?= etiq('Meta de ahorro',          $ficha_ahorros['meta_ahorro']           ?? '') ?>
                        <?= etiq('Plazo meta',              $ficha_ahorros['plazo_meta']            ?? '') ?>
                        <?= etiq('Origen de fondos',        $ficha_ahorros['origen_fondos']         ?? '') ?>
                        <?= etiq('Observaciones',           $ficha_ahorros['observaciones']         ?? '') ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ── FICHA INVERSIONES ── -->
                <?php if ($ficha_inversiones): ?>
                <div class="ficha-subsection" style="margin-top:20px;">
                    <div class="ficha-subtitle" style="border-bottom-color:#8b5cf6;">
                        <i class="fas fa-chart-line" style="color:#7c3aed;"></i>
                        Ficha de Inversiones
                        <small style="font-weight:400;text-transform:none;color:#9ca3af;margin-left:6px;"><?= date('d/m/Y H:i', strtotime($ficha_inversiones['created_at'])) ?></small>
                    </div>
                    <div class="dato-grid">
                        <?= etiq('Tipo de inversión',       $ficha_inversiones['tipo_inversion']    ?? '') ?>
                        <?= etiq('Monto a invertir',        $ficha_inversiones['monto_inversion']   ?? '', 'USD') ?>
                        <?= etiq('Plazo deseado',           $ficha_inversiones['plazo_inversion']   ?? '') ?>
                        <?= etiq('Tasa de referencia',      $ficha_inversiones['tasa_referencia']   ?? '', '%') ?>
                        <?= etiqYN('Inversión automática',  $ficha_inversiones['inversion_automatica'] ?? null) ?>
                        <?= etiq('Origen de fondos',        $ficha_inversiones['origen_fondos']     ?? '') ?>
                        <?= etiq('Perfil de riesgo',        $ficha_inversiones['perfil_riesgo']     ?? '') ?>
                        <?= etiq('Objetivo financiero',     $ficha_inversiones['objetivo_financiero'] ?? '') ?>
                        <?= etiq('Observaciones',           $ficha_inversiones['observaciones']     ?? '') ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php elseif (!empty($fichas)): ?>
        <!-- fichas sin detalle cargado -->
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-purple"><i class="fas fa-folder-open"></i></div>
                <h5>Fichas de Productos Solicitados</h5>
            </div>
            <div class="section-body">
                <?php foreach ($fichas as $f): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--brand-border);">
                    <span class="chip-prod"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$f['producto_tipo']))) ?></span>
                    <small style="color:var(--brand-gray);"><?= date('d/m/Y H:i', strtotime($f['created_at'])) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- Sin fichas -->
        <div class="section-card">
            <div class="section-header">
                <div class="sec-icon sec-purple"><i class="fas fa-folder-open"></i></div>
                <h5>Fichas de Productos Solicitados</h5>
            </div>
            <div class="section-body">
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    No hay fichas de productos registradas para este prospecto/cliente
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</body>
</html>

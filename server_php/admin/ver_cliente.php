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
 
 // ── 2b. Levantamiento de Empresa (Encuesta de Negocio) ────────
 $encuesta_negocio = null;
 try {
     $st = $pdo->prepare("
         SELECT en.* 
         FROM   encuesta_negocio en
         JOIN   tarea t ON t.id = en.tarea_id
         WHERE  t.cliente_prospecto_id = ?
         ORDER  BY en.created_at DESC
         LIMIT  1
     ");
     $st->execute([$cliente_id]);
     $encuesta_negocio = $st->fetch();
 } catch (PDOException $e) { $encuesta_negocio = null; }
 
 // ── 2c. Cálculos de Negocio (Totales) ─────────────────────────
 $en_tot_v_sem = 0; $en_tot_c_sem = 0;
 if ($encuesta_negocio) {
     $en_tot_v_sem = ($encuesta_negocio['venta_lunes'] ?? 0) + ($encuesta_negocio['venta_martes'] ?? 0) + ($encuesta_negocio['venta_miercoles'] ?? 0) + ($encuesta_negocio['venta_jueves'] ?? 0) + ($encuesta_negocio['venta_viernes'] ?? 0) + ($encuesta_negocio['venta_sabado'] ?? 0) + ($encuesta_negocio['venta_domingo'] ?? 0);
     if ($en_tot_v_sem <= 0) $en_tot_v_sem = ($encuesta_negocio['venta_lv'] ?? 0) + ($encuesta_negocio['venta_sabado'] ?? 0) + ($encuesta_negocio['venta_domingo'] ?? 0);
     
     $en_tot_c_sem = ($encuesta_negocio['compra_lunes'] ?? 0) + ($encuesta_negocio['compra_martes'] ?? 0) + ($encuesta_negocio['compra_miercoles'] ?? 0) + ($encuesta_negocio['compra_jueves'] ?? 0) + ($encuesta_negocio['compra_viernes'] ?? 0) + ($encuesta_negocio['compra_sabado'] ?? 0) + ($encuesta_negocio['compra_domingo'] ?? 0);
     if ($en_tot_c_sem <= 0) $en_tot_c_sem = ($encuesta_negocio['compra_lv'] ?? 0) + ($encuesta_negocio['compra_sabado'] ?? 0) + ($encuesta_negocio['compra_domingo'] ?? 0);
 }
 $en_tot_v_mes = $en_tot_v_sem * 4.33;
 $en_tot_c_mes = $en_tot_c_sem * 4.33;
 
 // ── 2d. Alertas del Cliente ──────────────────────────────────
 $alertas_cliente = [];
 try {
     $st = $pdo->prepare("
         SELECT am.*, u.nombre as asesor_nombre
         FROM alerta_modificacion am
         JOIN tarea t ON t.id = am.tarea_id
         LEFT JOIN asesor a ON a.id = am.asesor_id
         LEFT JOIN usuario u ON u.id = a.usuario_id
         WHERE t.cliente_prospecto_id = ?
         ORDER BY am.created_at DESC
     ");
     $st->execute([$cliente_id]);
     $alertas_cliente = $st->fetchAll();
 } catch (PDOException $e) { $alertas_cliente = []; }
 
 // ── 3. Tareas del cliente ─────────────────────────────────────
$tareas = [];
try {
    $st = $pdo->prepare("
        SELECT t.*, u.nombre AS asesor_nombre,
               av.acuerdo AS av_tipo, av.fecha_acuerdo AS av_fecha, av.hora_acuerdo AS av_hora, 
               av.lugar AS av_lugar, av.resultado AS av_resultado
        FROM   tarea t
        LEFT JOIN asesor a ON a.id = t.asesor_id
        LEFT JOIN usuario u ON u.id = a.usuario_id
        LEFT JOIN acuerdo_visita av ON av.tarea_id = t.id
        WHERE  t.cliente_prospecto_id = ?
        ORDER  BY t.fecha_programada DESC, t.created_at DESC
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
    <title>Super_IA — Detalle Cliente</title>
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
        <h2><i class="fas fa-address-book me-2" style="color:var(--brand-yellow);"></i>Super_IA — Detalle de Cliente</h2>
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
            <a href="clientes.php" class="btn-back"><i class="fas fa-arrow-left"></i> Volver a Clientes</a>
            <h1><i class="fas fa-user me-2"></i>Perfil del Cliente</h1>
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
                     
                     <!-- Campos adicionales si existen -->
                     <?php if (isset($cliente['genero'])): ?>
                         <?= etiq('Género', $cliente['genero'] ?? '') ?>
                         <?= etiq('Estado Civil', $cliente['estado_civil'] ?? '') ?>
                         <?= etiq('Nivel Educación', $cliente['nivel_educacion'] ?? '') ?>
                         <?= etiq('Tipo Vivienda', $cliente['tipo_vivienda'] ?? '') ?>
                         <?= etiq('Dependientes', $cliente['num_dependientes'] ?? '') ?>
                     <?php endif; ?>
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
                
                // Nuevos campos de búsqueda
                if (!empty($encuesta['que_busca_agilidad']))        $busca[] = 'Agilidad (Detalle)';
                if (!empty($encuesta['que_busca_cajeros']))         $busca[] = 'Cajeros (Detalle)';
                if (!empty($encuesta['que_busca_banca_linea']))     $busca[] = 'Banca en línea (Detalle)';
                if (!empty($encuesta['que_busca_agencias']))        $busca[] = 'Agencias (Detalle)';
                if (!empty($encuesta['que_busca_credito_rapido']))  $busca[] = 'Crédito rápido (Detalle)';
                
                if (!empty($busca)):
                ?>
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-search"></i> Qué busca en un producto financiero</div>
                    <div class="doc-chips">
                        <?php foreach ($busca as $b): ?><span class="doc-chip ok"><?= htmlspecialchars($b) ?></span><?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
 
                <!-- Información Institucional y Bancaria -->
                <div class="ficha-subsection">
                    <div class="ficha-subtitle"><i class="fas fa-university"></i> Relación Bancaria e Institucional</div>
                    <?php 
                    // Estas preguntas se guardan en encuesta_negocio según el último esquema
                    $inst = $encuesta_negocio ?: $encuesta; 
                    ?>
                    <div class="dato-grid">
                        <?= etiq('Banco Ahorro (Actual)', $encuesta['banco_ahorro'] ?? '') ?>
                        <?= etiq('Banco Corriente (Actual)', $encuesta['banco_corriente'] ?? '') ?>
                        <?= etiqYN('¿Conoce la institución?', $inst['p1_conoce_institucion'] ?? null) ?>
                        <?= etiqYN('¿Es cliente actualmente?', $inst['p2_es_cliente'] ?? null) ?>
                        <?= etiq('Satisfacción General', $inst['p3_satisfaccion'] ?? '') ?>
                        <?php if (!empty($inst['p2_producto'])): ?>
                            <?= etiq('Producto que posee', $inst['p2_producto']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($inst['p1_obs']) || !empty($inst['p2_obs']) || !empty($inst['p3_obs'])): ?>
                        <div class="mt-2 p-2 rounded" style="background: #f8fafc; font-size: 12px; border-left: 3px solid #cbd5e1;">
                            <strong>Observaciones Institucionales:</strong><br>
                            <?= htmlspecialchars(trim(implode(' / ', array_filter([$inst['p1_obs'] ?? '', $inst['p2_obs'] ?? '', $inst['p3_obs'] ?? ''])), ' /')) ?>
                        </div>
                    <?php endif; ?>
                </div>
 

 
             </div>
         </div>
         <?php endif; ?>
 
         <!-- ── LEVANTAMIENTO DE EMPRESA ── -->
         <?php if ($encuesta_negocio): ?>
         <div class="section-card">
             <div class="section-header">
                 <div class="sec-icon sec-green"><i class="fas fa-store"></i></div>
                 <h5>Levantamiento de Empresa / Negocio</h5>
             </div>
             <div class="section-body">
                 
                  <!-- Ventas y Compras -->
                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-chart-line"></i> Flujo de Ventas y Compras (Detalle Diario y Mensual)</div>
                      
                      <div class="table-responsive mb-3">
                          <table class="table table-bordered table-sm text-center" style="font-size: 12px; background: #f8fafc;">
                              <thead class="table-light">
                                  <tr>
                                      <th>Concepto</th>
                                      <th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th>
                                      <th class="table-primary">Semanal</th>
                                      <th class="table-success">Mensual Est.</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <tr>
                                      <td class="text-start"><strong>Ventas</strong></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_lunes'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_martes'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_miercoles'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_jueves'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_viernes'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_sabado'] ?? 0, 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['venta_domingo'] ?? 0, 2) ?></td>
                                      <td class="table-primary"><strong>$<?= number_format($en_tot_v_sem, 2) ?></strong></td>
                                      <td class="table-success"><strong>$<?= number_format($en_tot_v_mes, 2) ?></strong></td>
                                  </tr>
                                  <tr>
                                      <td class="text-start"><strong>Compras</strong></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_lunes'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_martes'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_miercoles'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_jueves'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_viernes'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_sabado'] ?? 0, 2) ?></td>
                                      <td>$<?= number_format($encuesta_negocio['compra_domingo'] ?? 0, 2) ?></td>
                                      <td class="table-primary"><strong>$<?= number_format($en_tot_c_sem, 2) ?></strong></td>
                                      <td class="table-success"><strong>$<?= number_format($en_tot_c_mes, 2) ?></strong></td>
                                  </tr>
                              </tbody>
                          </table>
                      </div>
 
                      <div class="dato-grid">
                          <?= etiq('Mes Alta Venta',         $encuesta_negocio['mes_alta_venta'] ?? '') ?>
                          <?= etiq('Mes Baja Venta',          $encuesta_negocio['mes_baja_venta'] ?? '') ?>
                          <?= etiq('Mes Alta Compra',         $encuesta_negocio['mes_alta_compra'] ?? '') ?>
                          
                          <div class="dato-row" style="grid-column: 1 / -1; border-bottom: none; padding-top: 15px;">
                              <div class="dato-label">Días de atención (Activos)</div>
                              <div class="doc-chips">
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_lunes'] ?? $encuesta_negocio['dia_lv'] ?? 0) ? 'ok' : 'no' ?>">Lun</span>
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_martes'] ?? $encuesta_negocio['dia_lv'] ?? 0) ? 'ok' : 'no' ?>">Mar</span>
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_miercoles'] ?? $encuesta_negocio['dia_lv'] ?? 0) ? 'ok' : 'no' ?>">Mié</span>
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_jueves'] ?? $encuesta_negocio['dia_lv'] ?? 0) ? 'ok' : 'no' ?>">Jue</span>
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_viernes'] ?? $encuesta_negocio['dia_lv'] ?? 0) ? 'ok' : 'no' ?>">Vie</span>
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_sab'] ?? 0) ? 'ok' : 'no' ?>">Sáb</span>
                                  <span class="doc-chip <?= ($encuesta_negocio['dia_dom'] ?? 0) ? 'ok' : 'no' ?>">Dom</span>
                              </div>
                          </div>
                      </div>
                  </div>
 
                 <!-- Política de Ventas -->
                 <div class="ficha-subsection">
                     <div class="ficha-subtitle"><i class="fas fa-receipt"></i> Política de Ventas y Cobro (Detalle de porcentajes)</div>
                     <div class="dato-grid">
                         <div class="dato-row" style="border-bottom: 2px solid #ffdd00;">
                             <span class="dato-label">Ventas al Contado</span>
                             <span class="dato-val"><strong style="font-size: 18px; color: #059669;"><?= $encuesta_negocio['pct_contado'] ?? '0' ?>%</strong></span>
                         </div>
                         <div class="dato-row" style="border-bottom: 2px solid #ffdd00;">
                             <span class="dato-label">Ventas al Crédito</span>
                             <span class="dato-val"><strong style="font-size: 18px; color: #dc2626;"><?= $encuesta_negocio['pct_credito'] ?? '0' ?>%</strong></span>
                         </div>
                         <div class="dato-row" style="border-bottom: 2px solid #ffdd00;">
                             <span class="dato-label">Uso de Efectivo</span>
                             <span class="dato-val"><strong style="font-size: 18px; color: #1e40af;"><?= $encuesta_negocio['pct_efectivo'] ?? '0' ?>%</strong></span>
                         </div>
                         <?= etiq('Recuperación de cartera', $encuesta_negocio['recuperacion_credito'] ?? '', 'USD') ?>
                     </div>
                 </div>
 
                 <!-- Gastos del Negocio -->
                 <div class="ficha-subsection">
                     <div class="ficha-subtitle"><i class="fas fa-file-invoice-dollar"></i> Gastos del Negocio</div>
                     <div class="dato-grid">
                         <?= etiq('Costos de Ventas',  $encuesta_negocio['costos_ventas'] ?? '', 'USD') ?>
                         <?= etiq('Sueldos y Salarios', $encuesta_negocio['g_neg_sueldos'] ?? '', 'USD') ?>
                         <?= etiq('Arriendo Local',     $encuesta_negocio['g_neg_arriendo'] ?? '', 'USD') ?>
                         <?= etiq('Servicios Básicos',  $encuesta_negocio['g_neg_serv_bas'] ?? '', 'USD') ?>
                         <?= etiq('Transporte',         $encuesta_negocio['g_neg_transporte'] ?? '', 'USD') ?>
                         <?= etiq('Mantenimiento',      $encuesta_negocio['g_neg_mantenimiento'] ?? '', 'USD') ?>
                         <?= etiq('Otros Gastos',       $encuesta_negocio['g_neg_otros'] ?? '', 'USD') ?>
                         <?= etiq('Imprevistos',        $encuesta_negocio['g_neg_imprevistos'] ?? '', 'USD') ?>
                         <div class="dato-row">
                             <span class="dato-label">Total Gastos Negocio</span>
                             <span class="dato-val"><strong>$<?= number_format($encuesta_negocio['gastos_negocio'] ?? 0, 2) ?></strong></span>
                         </div>
                     </div>
                 </div>
 
                 <!-- Otros Ingresos y Gastos Familiares -->
                 <div class="row">
                     <div class="col-md-6">
                         <div class="ficha-subsection">
                             <div class="ficha-subtitle"><i class="fas fa-plus-circle"></i> Otros Ingresos</div>
                             <div class="dato-grid" style="grid-template-columns: 1fr;">
                                 <?= etiq('Ingresos Cónyuge', $encuesta_negocio['o_ing_conyuge'] ?? '', 'USD') ?>
                                 <?= etiq('Arriendos',        $encuesta_negocio['o_ing_arriendos'] ?? '', 'USD') ?>
                                 <?= etiq('Pensiones',        $encuesta_negocio['o_ing_pensiones'] ?? '', 'USD') ?>
                                 <?= etiq('Otros',            $encuesta_negocio['o_ing_otros'] ?? '', 'USD') ?>
                                 <?= etiq('Total Otros Ingresos', $encuesta_negocio['otros_ingresos'] ?? '', 'USD') ?>
                             </div>
                         </div>
                     </div>
                     <div class="col-md-6">
                         <div class="ficha-subsection">
                             <div class="ficha-subtitle"><i class="fas fa-home"></i> Gastos Familiares</div>
                             <div class="dato-grid" style="grid-template-columns: 1fr;">
                                 <?= etiq('Alimentación',   $encuesta_negocio['g_fam_alim'] ?? '', 'USD') ?>
                                 <?= etiq('Arriendo Casa',  $encuesta_negocio['g_fam_arriendo'] ?? '', 'USD') ?>
                                 <?= etiq('Servicios Bás.', $encuesta_negocio['g_fam_serv_bas'] ?? '', 'USD') ?>
                                 <?= etiq('Educación',      $encuesta_negocio['g_fam_educacion'] ?? '', 'USD') ?>
                                 <?= etiq('Salud',          $encuesta_negocio['g_fam_salud'] ?? '', 'USD') ?>
                                 <?= etiq('Otros Gastos',   $encuesta_negocio['g_fam_otros'] ?? '', 'USD') ?>
                                 <?= etiq('Imprevistos',    $encuesta_negocio['g_fam_imprevistos'] ?? '', 'USD') ?>
                                 <?= etiq('Total Gastos Fam.', $encuesta_negocio['gastos_familiares'] ?? '', 'USD') ?>
                             </div>
                         </div>
                     </div>
                 </div>
                 
                 <!-- Estado Financiero / Balance -->
                 <?php if (isset($encuesta_negocio['caja_efectivo']) || isset($encuesta_negocio['bancos_saldo'])): ?>
                 <div class="ficha-subsection">
                     <div class="ficha-subtitle"><i class="fas fa-university"></i> Situación Financiera / Saldos</div>
                     <div class="dato-grid">
                          <?= etiq('Caja Efectivo', $encuesta_negocio['caja_efectivo'] ?? '', 'USD') ?>
                          <?= etiq('Saldo Bancos',   $encuesta_negocio['bancos_saldo'] ?? '', 'USD') ?>
                          <?= etiq('Cuentas x Pagar (Netas)', $encuesta_negocio['cxp_netas'] ?? '', 'USD') ?>
                          <?= etiq('Inv. Materia Prima', $encuesta_negocio['inv_mat_prima'] ?? '', 'USD') ?>
                          <?= etiq('Inv. Prod. Proceso', $encuesta_negocio['inv_prod_proc'] ?? '', 'USD') ?>
                          <?= etiq('Créditos x Pagar', $encuesta_negocio['creditos_pagar'] ?? '', 'USD') ?>
                          <?= etiq('Proveedores', $encuesta_negocio['proveedores'] ?? '', 'USD') ?>
                          <?= etiq('Pasivos LP', $encuesta_negocio['pasivos_lp'] ?? '', 'USD') ?>
                     </div>
                 </div>
                 <?php endif; ?>

                  <!-- Activos Declarados -->
                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-car-side"></i> Activos y Vehículos</div>
                      <?php 
                      $veh_neg = json_decode($encuesta_negocio['vehiculos_negocio_json'] ?? '[]', true);
                      $veh_hog = json_decode($encuesta_negocio['vehiculos_hogar_json'] ?? '[]', true);
                      $all_veh = array_merge(
                          array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($veh_neg)?$veh_neg:[]),
                          array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($veh_hog)?$veh_hog:[])
                      );
                      ?>
                      <?php if (!empty($all_veh)): ?>
                      <div class="table-responsive">
                          <table class="table table-sm table-bordered" style="font-size:12px;">
                              <thead class="bg-light">
                                  <tr>
                                      <th>Tipo</th><th>Descripción</th><th>Marca/Modelo</th><th>Año</th><th>Valor</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($all_veh as $v): ?>
                                  <?php if (empty($v['descripcion']) && empty($v['marca'])) continue; ?>
                                  <tr>
                                      <td><span class="badge <?= $v['tipo']=='Negocio'?'bg-info':'bg-secondary' ?>"><?= $v['tipo'] ?></span></td>
                                      <td><?= htmlspecialchars($v['descripcion'] ?? '') ?></td>
                                      <td><?= htmlspecialchars($v['marca'] ?? '') ?> <?= htmlspecialchars($v['modelo'] ?? '') ?></td>
                                      <td><?= htmlspecialchars($v['anio'] ?? '') ?></td>
                                      <td>$<?= number_format((float)($v['valor'] ?? 0), 2) ?></td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                          <p class="text-muted" style="font-size:12px;">No se declararon vehículos.</p>
                      <?php endif; ?>
                  </div>

                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-building"></i> Inmuebles y Propiedades</div>
                      <?php 
                      $inm_neg = json_decode($encuesta_negocio['inmuebles_negocio_json'] ?? '[]', true);
                      $inm_hog = json_decode($encuesta_negocio['inmuebles_hogar_json'] ?? '[]', true);
                      $all_inm = array_merge(
                          array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($inm_neg)?$inm_neg:[]),
                          array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($inm_hog)?$inm_hog:[])
                      );
                      ?>
                      <?php if (!empty($all_inm)): ?>
                      <div class="table-responsive">
                          <table class="table table-sm table-bordered" style="font-size:12px;">
                              <thead class="bg-light">
                                  <tr>
                                      <th>Tipo</th><th>Descripción</th><th>Ubicación</th><th>Área</th><th>Valor Est.</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($all_inm as $i): ?>
                                  <?php if (empty($i['descripcion']) && empty($i['ubicacion'])) continue; ?>
                                  <tr>
                                      <td><span class="badge <?= $i['tipo']=='Negocio'?'bg-info':'bg-secondary' ?>"><?= $i['tipo'] ?></span></td>
                                      <td><?= htmlspecialchars($i['descripcion'] ?? '') ?></td>
                                      <td><?= htmlspecialchars($i['ubicacion'] ?? '') ?></td>
                                      <td><?= htmlspecialchars($i['area'] ?? '') ?></td>
                                      <td>$<?= number_format((float)($i['valor'] ?? 0), 2) ?></td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                          <p class="text-muted" style="font-size:12px;">No se declararon inmuebles.</p>
                      <?php endif; ?>
                  </div>

                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-hand-holding-usd"></i> Otras Deudas Declaradas</div>
                      <?php 
                      $deudas = json_decode($encuesta_negocio['otras_deudas_json'] ?? '[]', true);
                      ?>
                      <?php if (!empty($deudas)): ?>
                      <div class="table-responsive">
                          <table class="table table-sm table-bordered" style="font-size:12px;">
                              <thead class="bg-light">
                                  <tr>
                                      <th>Acreedor</th><th>Destino</th><th>Monto Inicial</th><th>Saldo Actual</th><th>Cuota Mes</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($deudas as $d): ?>
                                  <?php if (empty($d['acreedor'])) continue; ?>
                                  <tr>
                                      <td><strong><?= htmlspecialchars($d['acreedor'] ?? '') ?></strong></td>
                                      <td><?= htmlspecialchars($d['destino'] ?? '') ?></td>
                                      <td>$<?= number_format((float)($d['monto_inicial'] ?? 0), 2) ?></td>
                                      <td class="text-danger">$<?= number_format((float)($d['saldo_actual'] ?? 0), 2) ?></td>
                                      <td>$<?= number_format((float)($d['pago_mes'] ?? 0), 2) ?></td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                          <p class="text-muted" style="font-size:12px;">No se reportaron otras deudas.</p>
                      <?php endif; ?>
                  </div>

                  <!-- Detalle de Productos (Comercio / Producción) -->
                  <?php 
                  $com_prods = json_decode($encuesta_negocio['comercio_productos_json'] ?? '[]', true);
                  $prods     = json_decode($encuesta_negocio['productos_json'] ?? '[]', true);
                  ?>
                  
                  <?php if (!empty($com_prods)): ?>
                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-shopping-basket"></i> Detalle de Productos (Comercio)</div>
                      <div class="table-responsive">
                          <table class="table table-sm table-bordered" style="font-size:11px;">
                              <thead class="table-light">
                                  <tr>
                                      <th>Producto</th><th>Costo Unit.</th><th>P. Venta</th><th>Cant. Mes</th><th>Venta Mes</th><th>Margen</th><th>Existencias</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($com_prods as $cp): ?>
                                  <?php if (empty($cp['nombre'])) continue; ?>
                                  <tr>
                                      <td><strong><?= htmlspecialchars($cp['nombre']) ?></strong></td>
                                      <td>$<?= number_format((float)($cp['costo_unitario'] ?? 0), 2) ?></td>
                                      <td>$<?= number_format((float)($cp['precio_venta_unitario'] ?? $cp['precio_venta_unidad'] ?? 0), 2) ?></td>
                                      <td><?= (float)($cp['cantidad_vendida_mes'] ?? 0) ?></td>
                                      <td class="table-success">$<?= number_format((float)($cp['venta_mes'] ?? 0), 2) ?></td>
                                      <td><?= (float)($cp['margen_utilidad'] ?? 0) ?>%</td>
                                      <td><?= (float)($cp['unidades_existentes'] ?? 0) ?></td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>
                  <?php endif; ?>

                  <?php if (!empty($prods)): ?>
                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-tools"></i> Detalle de Producción / Servicios</div>
                      <?php foreach ($prods as $p): ?>
                      <?php if (empty($p['nombre'])) continue; ?>
                      <div class="border rounded p-2 mb-3 bg-white">
                          <h6 class="mb-2" style="color:#1e40af; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">
                              <i class="fas fa-box-open"></i> <?= htmlspecialchars($p['nombre']) ?>
                          </h6>
                          <div class="row g-2">
                              <div class="col-md-7">
                                  <table class="table table-sm table-borderless m-0" style="font-size:10.5px;">
                                      <tr class="table-light">
                                          <th colspan="2">Estructura de Costos</th>
                                      </tr>
                                      <?php 
                                      $materias = is_array($p['materias'] ?? null) ? $p['materias'] : [];
                                      foreach ($materias as $m): if (empty($m['nombre'])) continue;
                                      ?>
                                      <tr>
                                          <td><?= htmlspecialchars($m['nombre']) ?></td>
                                          <td class="text-end">$<?= number_format((float)($m['valor'] ?? 0), 2) ?></td>
                                      </tr>
                                      <?php endforeach; ?>
                                      <tr class="border-top">
                                          <td><strong>Total Materia Prima</strong></td>
                                          <td class="text-end"><strong>$<?= number_format((float)($p['total_materia_prima'] ?? 0), 2) ?></strong></td>
                                      </tr>
                                      <tr><td>Mano de obra / Empaques / Otros</td><td class="text-end">$<?= number_format((float)($p['mano_obra']??0) + (float)($p['empaques']??0) + (float)($p['otros_costos']??0), 2) ?></td></tr>
                                      <tr class="table-primary">
                                          <td><strong>COSTO TOTAL UNITARIO</strong></td>
                                          <td class="text-end"><strong>$<?= number_format((float)($p['costo_unitario'] ?? 0), 2) ?></strong></td>
                                      </tr>
                                  </table>
                              </div>
                              <div class="col-md-5">
                                  <div class="dato-grid" style="grid-template-columns: 1fr; gap:5px; border:none; padding:0;">
                                      <div class="dato-row"><span class="dato-label">Precio Unitario</span><span class="dato-val">$<?= number_format((float)($p['precio_unitario']??0), 2) ?></span></div>
                                      <div class="dato-row"><span class="dato-label">Ventas Mes</span><span class="dato-val"><strong>$<?= number_format((float)($p['ventas_mensuales']??0), 2) ?></strong></span></div>
                                      <div class="dato-row"><span class="dato-label">Costo Ventas</span><span class="dato-val">$<?= number_format((float)($p['costo_ventas']??0), 2) ?></span></div>
                                      <div class="dato-row"><span class="dato-label">Inventario</span><span class="dato-val">$<?= number_format((float)($p['inventarios']??0), 2) ?></span></div>
                                  </div>
                              </div>
                          </div>
                      </div>
                      <?php endforeach; ?>
                  </div>
                  <?php endif; ?>

                  <div class="ficha-subsection">
                      <div class="ficha-subtitle"><i class="fas fa-box"></i> Activos Fijos y Herramientas</div>
                      <?php 
                      $act_neg = json_decode($encuesta_negocio['activos_negocio_json'] ?? '[]', true);
                      $act_hog = json_decode($encuesta_negocio['activos_hogar_json'] ?? '[]', true);
                      $all_act = array_merge(
                          array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($act_neg)?$act_neg:[]),
                          array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($act_hog)?$act_hog:[])
                      );
                      ?>
                      <?php if (!empty($all_act)): ?>
                      <div class="table-responsive">
                          <table class="table table-sm table-bordered" style="font-size:11px;">
                              <thead class="bg-light">
                                  <tr>
                                      <th>Tipo</th><th>Descripción</th><th>Marca/Modelo</th><th>Serie</th><th>Valor</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($all_act as $a): ?>
                                  <?php if (empty($a['descripcion'])) continue; ?>
                                  <tr>
                                      <td><span class="badge <?= $a['tipo']=='Negocio'?'bg-info':'bg-secondary' ?>"><?= $a['tipo'] ?></span></td>
                                      <td><?= htmlspecialchars($a['descripcion'] ?? '') ?></td>
                                      <td><?= htmlspecialchars($a['marca'] ?? '') ?> <?= htmlspecialchars($a['modelo'] ?? '') ?></td>
                                      <td><?= htmlspecialchars($a['serie'] ?? '') ?></td>
                                      <td>$<?= number_format((float)($a['valor_comercial'] ?? 0), 2) ?></td>
                                  </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                      <?php else: ?>
                          <p class="text-muted" style="font-size:12px;">No se declararon otros activos fijos.</p>
                      <?php endif; ?>
                  </div>


 
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
                            <th>Tipo / Tarea</th>
                            <th>Programación</th>
                            <th>Estado</th>
                            <th>Ejecución / GPS</th>
                            <th>Acuerdo / Resultado</th>
                            <th>Asesor</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tareas as $t): ?>
                        <?php
                        $tipoLabel  = ucfirst(str_replace('_', ' ', $t['tipo_tarea'] ?? '—'));
                        $estadoClass= 'badge-pendiente';
                        if (($t['estado'] ?? '') === 'completada') $estadoClass = 'badge-completada';
                        elseif (($t['estado'] ?? '') === 'cancelada') $estadoClass = 'badge-cancelada';
                        
                        // GPS info
                        $gps = '—';
                        if (!empty($t['latitud_inicio']) && !empty($t['longitud_inicio'])) {
                            $gps = sprintf('<a href="https://www.google.com/maps?q=%s,%s" target="_blank" class="text-primary" title="Ver en Mapa"><i class="fas fa-map-marker-alt"></i> Inicio</a>', $t['latitud_inicio'], $t['longitud_inicio']);
                        }
                        
                        $acuerdo = $t['acuerdo_logrado'] ?? 'ninguno';
                        $acuerdoClass = 'acuerdo-ninguno';
                        if (str_starts_with($acuerdo, 'nueva_cita')) $acuerdoClass = 'acuerdo-nueva_cita';
                        elseif (str_starts_with($acuerdo, 'recolectar')) $acuerdoClass = 'acuerdo-documentos';
                        elseif (str_starts_with($acuerdo, 'levantamiento')) $acuerdoClass = 'acuerdo-levantamiento';
                        $acuerdoLabel = ucfirst(str_replace('_', ' ', $acuerdo));
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($tipoLabel) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars(mb_substr($t['observaciones'] ?? '',0,40)) ?>...</small>
                            </td>
                            <td>
                                <i class="far fa-calendar-alt me-1"></i> <?= $t['fecha_programada'] ? date('d/m/Y', strtotime($t['fecha_programada'])) : '—' ?><br>
                                <i class="far fa-clock me-1"></i> <?= $t['hora_programada'] ?? '—' ?>
                            </td>
                            <td><span class="badge-estado <?= $estadoClass ?>"><?= ucfirst($t['estado'] ?? '—') ?></span></td>
                            <td>
                                <?php if ($t['fecha_realizada']): ?>
                                    <small><?= date('d/m/Y', strtotime($t['fecha_realizada'])) ?> <?= $t['hora_realizada'] ?></small><br>
                                    <?= $gps ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['av_tipo']): ?>
                                    <span class="badge bg-light text-dark border" style="font-size:11px;">
                                        <i class="fas fa-handshake me-1"></i> <?= htmlspecialchars($t['av_tipo']) ?>
                                    </span><br>
                                    <small class="text-success"><?= htmlspecialchars($t['av_resultado'] ?? '') ?></small>
                                <?php else: ?>
                                    <span class="acuerdo-badge <?= $acuerdoClass ?>"><?= htmlspecialchars($acuerdoLabel) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($t['asesor_nombre'] ?? '—') ?></td>
                        </tr>
                        <!-- Fila de detalle si hay acuerdo de visita específico -->
                        <?php if ($t['av_tipo'] || !empty($t['observaciones'])): ?>
                        <tr style="background:#fdfdfd;">
                            <td colspan="6" style="padding: 5px 15px; border-top: none;">
                                <div style="font-size: 12px; color: #64748b; line-height: 1.4;">
                                    <?php if (!empty($t['observaciones'])): ?>
                                        <strong>Obs:</strong> <?= htmlspecialchars($t['observaciones']) ?>
                                    <?php endif; ?>
                                    <?php if ($t['av_lugar']): ?>
                                        <span class="ms-3"><strong>Lugar Acuerdo:</strong> <?= htmlspecialchars($t['av_lugar']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($t['av_fecha']): ?>
                                        <span class="ms-3"><strong>Próxima Cita:</strong> <?= date('d/m/Y', strtotime($t['av_fecha'])) ?> <?= $t['av_hora'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
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
 
         <!-- ── ALERTAS DEL CLIENTE ── -->
         <?php if ($alertas_cliente): ?>
         <div class="section-card">
             <div class="section-header">
                 <div class="sec-icon sec-red" style="background: #fee2e2; color: #dc2626;"><i class="fas fa-bell"></i></div>
                 <h5>Alertas y Modificaciones Recientes</h5>
             </div>
             <div class="section-body" style="padding:0;">
                 <table class="task-table">
                     <thead>
                         <tr>
                             <th>Tipo / Campo</th>
                             <th>Estado</th>
                             <th>Asesor</th>
                             <th>Fecha Alerta</th>
                             <th>Acción</th>
                         </tr>
                     </thead>
                     <tbody>
                         <?php foreach ($alertas_cliente as $al): ?>
                         <tr>
                             <td><strong><?= htmlspecialchars($al['campo_modificado'] ?? 'Modificación') ?></strong></td>
                             <td>
                                 <?php if (!($al['vista_supervisor'] ?? 0)): ?>
                                     <span class="badge bg-danger">Pendiente</span>
                                 <?php else: ?>
                                     <span class="badge bg-success">Revisada</span>
                                 <?php endif; ?>
                             </td>
                             <td><?= htmlspecialchars($al['asesor_nombre'] ?? '—') ?></td>
                             <td><?= date('d/m/Y H:i', strtotime($al['created_at'])) ?></td>
                             <td>
                                 <a href="alertas_detalle.php?id=<?= urlencode($al['id']) ?>" class="btn btn-sm btn-outline-danger" title="Ver detalle de la modificación">
                                     <i class="fas fa-search-plus"></i>
                                 </a>
                             </td>
                         </tr>
                         <?php endforeach; ?>
                     </tbody>
                 </table>
             </div>
         </div>
         <?php endif; ?>

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
                    No hay fichas de productos registradas para este cliente
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.content-area -->
</div><!-- /.main-content -->
</body>
</html>

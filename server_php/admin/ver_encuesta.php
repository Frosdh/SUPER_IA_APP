<?php
// ============================================================
// admin/ver_encuesta.php — Detalle de una Encuesta / Levantamiento
// ------------------------------------------------------------
// Muestra UNA encuesta específica (la de una tarea puntual, no
// "la más reciente del cliente") con las mismas secciones que ve
// el asesor en el celular al llenarla:
//   1) Identificación      (quién, cuándo, qué tipo de actividad)
//   2) Datos del Prospecto (cliente_prospecto)
//   3) Empresa / Negocio + situación financiera del levantamiento
//      (encuesta_negocio) — solo si esta tarea es un levantamiento
//   4) Situación Financiera / Interés en Productos (encuesta_comercial)
//      — solo si esta tarea es una encuesta de agenda
//   5) Acuerdo y Cierre
//
// Reutiliza los mismos helpers/estilos que ver_cliente.php para
// que la presentación de los datos sea idéntica en todo el panel.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_admin.php'; // PDO ($pdo)

// ── Autenticación (mismos roles que ver_cliente.php) ──────────
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

$tarea_id = trim($_GET['tarea_id'] ?? '');
if ($tarea_id === '') {
    header('Location: encuestas.php');
    exit;
}

// ── 1. Tarea + asesor ─────────────────────────────────────────
$tarea = null;
try {
    $st = $pdo->prepare("
        SELECT t.*, u.nombre AS asesor_nombre
        FROM tarea t
        LEFT JOIN asesor a ON a.id = t.asesor_id
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE t.id = ? LIMIT 1
    ");
    $st->execute([$tarea_id]);
    $tarea = $st->fetch();
} catch (PDOException $e) { $tarea = null; }

if (!$tarea) {
    header('Location: encuestas.php?error=no_encontrada');
    exit;
}

// ── 2. Cliente / Prospecto ─────────────────────────────────────
$cliente = null;
if (!empty($tarea['cliente_prospecto_id'])) {
    try {
        $st = $pdo->prepare('SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1');
        $st->execute([$tarea['cliente_prospecto_id']]);
        $cliente = $st->fetch();
    } catch (PDOException $e) { $cliente = null; }
}

// ── 3. Encuesta comercial de ESTA tarea puntual ───────────────
$encuesta = null;
try {
    $st = $pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$tarea_id]);
    $encuesta = $st->fetch();
} catch (PDOException $e) { $encuesta = null; }

// ── 4. Levantamiento de Empresa de ESTA tarea puntual ─────────
$encuesta_negocio = null;
try {
    $st = $pdo->prepare('SELECT * FROM encuesta_negocio WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$tarea_id]);
    $encuesta_negocio = $st->fetch();
} catch (PDOException $e) { $encuesta_negocio = null; }

$es_levantamiento = ($tarea['tipo_tarea'] ?? '') === 'levantamiento';
$subida = $es_levantamiento ? (bool)$encuesta_negocio : (bool)$encuesta;

// ── Totales de ventas/compras del levantamiento (si aplica) ───
$en_tot_v_sem = 0; $en_tot_c_sem = 0;
if ($encuesta_negocio) {
    $en_tot_v_sem = ($encuesta_negocio['venta_lunes'] ?? 0) + ($encuesta_negocio['venta_martes'] ?? 0) + ($encuesta_negocio['venta_miercoles'] ?? 0) + ($encuesta_negocio['venta_jueves'] ?? 0) + ($encuesta_negocio['venta_viernes'] ?? 0) + ($encuesta_negocio['venta_sabado'] ?? 0) + ($encuesta_negocio['venta_domingo'] ?? 0);
    if ($en_tot_v_sem <= 0) $en_tot_v_sem = ($encuesta_negocio['venta_lv'] ?? 0) + ($encuesta_negocio['venta_sabado'] ?? 0) + ($encuesta_negocio['venta_domingo'] ?? 0);
    $en_tot_c_sem = ($encuesta_negocio['compra_lunes'] ?? 0) + ($encuesta_negocio['compra_martes'] ?? 0) + ($encuesta_negocio['compra_miercoles'] ?? 0) + ($encuesta_negocio['compra_jueves'] ?? 0) + ($encuesta_negocio['compra_viernes'] ?? 0) + ($encuesta_negocio['compra_sabado'] ?? 0) + ($encuesta_negocio['compra_domingo'] ?? 0);
    if ($en_tot_c_sem <= 0) $en_tot_c_sem = ($encuesta_negocio['compra_lv'] ?? 0) + ($encuesta_negocio['compra_sabado'] ?? 0) + ($encuesta_negocio['compra_domingo'] ?? 0);
}
$en_tot_v_mes = $en_tot_v_sem * 4.33;
$en_tot_c_mes = $en_tot_c_sem * 4.33;

// ── Helpers de presentación (idénticos a ver_cliente.php) ─────
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
function venc_tipo_label(string $tipo): string {
    switch ($tipo) {
        case 'levantamiento':         return 'Levantamiento de Empresa';
        case 'evaluacion':            return 'Evaluación';
        case 'prospecto_nuevo':       return 'Prospecto Nuevo';
        case 'visita_frio':
        case 'frio':                  return 'Visita en Frío';
        case 'nueva_cita_inversion':  return 'Nueva cita inversión';
        case 'nueva_cita_campo':      return 'Nueva cita en campo';
        case 'nueva_cita_oficina':    return 'Nueva cita en oficina';
        case 'post_venta':            return 'Post venta';
        case 'represtamo':            return 'Represtamo';
        case 'seguimiento':           return 'Seguimiento';
        case 'documentos_pendientes': return 'Recolectar documentación';
        default:                      return ucfirst(str_replace('_', ' ', $tipo));
    }
}
function venc_acuerdo_label(?string $acuerdo): array {
    $acuerdo = $acuerdo ?: 'ninguno';
    $class = 'acuerdo-ninguno';
    if (str_starts_with($acuerdo, 'nueva_cita'))        $class = 'acuerdo-nueva_cita';
    elseif (str_starts_with($acuerdo, 'recolectar'))    $class = 'acuerdo-documentos';
    elseif (str_starts_with($acuerdo, 'levantamiento')) $class = 'acuerdo-levantamiento';
    return [ucfirst(str_replace('_', ' ', $acuerdo)), $class];
}
function venc_origen_prospecto(?string $origen): array {
    switch ($origen) {
        case 'frio':           return ['Frío', 'No conoce / no nos sigue', 'acuerdo-ninguno'];
        case 'seguidor':       return ['Seguidor', 'Sí conoce / sí nos sigue', 'acuerdo-nueva_cita'];
        case 'cliente':        return ['Cliente', 'Ya es cliente nuestro', 'acuerdo-levantamiento'];
        case 'leads_llamadas': return ['Leads / Llamadas', 'Links o llamadas', 'acuerdo-documentos'];
        default:                return ['—', '', 'acuerdo-ninguno'];
    }
}
function venc_regimen_label(?string $r): string {
    switch ($r) {
        case 'ruc':           return 'RUC (Régimen general)';
        case 'rise':          return 'RISE (Régimen simplificado)';
        case 'no_registrado': return 'No está registrado';
        default:              return '';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head> 
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de Encuesta — Super_IA</title>
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
        body { font-family:'Inter','Segoe UI',sans-serif; background:var(--brand-bg); color:var(--brand-navy-deep); }
        .content-area { max-width:1100px; margin:0 auto; padding:30px 24px 60px; }

        .page-header { margin-bottom:22px; }
        .btn-back { padding:8px 18px; background:rgba(18,58,109,.08); color:var(--brand-navy-deep); border:1.5px solid var(--brand-border); border-radius:10px; text-decoration:none; font-weight:600; margin-bottom:16px; display:inline-flex; align-items:center; gap:8px; font-size:13.5px; cursor:pointer; }
        .btn-back:hover { background:rgba(18,58,109,.15); color:var(--brand-navy-deep); }

        .client-hero { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); border-radius:18px; padding:26px 30px; color:#fff; margin-bottom:22px; box-shadow:0 8px 28px rgba(18,58,109,.18); }
        .client-hero h2 { font-size:21px; font-weight:800; margin-bottom:4px; }
        .client-hero p { opacity:.85; font-size:13.5px; margin:0; }
        .client-hero-badges { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
        .hero-badge { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.22); border-radius:20px; padding:4px 14px; font-size:12px; font-weight:600; }
        .hero-badge.yellow { background:var(--brand-yellow); color:var(--brand-navy-deep); border-color:transparent; }
        .hero-badge.ok { background:#059669; }
        .hero-badge.pend { background:#d97706; }

        .section-card { background:#fff; border-radius:16px; box-shadow:var(--brand-shadow); border:1px solid var(--brand-border); margin-bottom:22px; overflow:hidden; }
        .section-header { padding:16px 22px; border-bottom:1px solid var(--brand-border); display:flex; align-items:center; gap:12px; background:#fafbfc; }
        .section-header .sec-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .sec-blue  { background:rgba(18,58,109,.10); color:var(--brand-navy); }
        .sec-green { background:rgba(16,185,129,.12); color:#059669; }
        .sec-yellow{ background:rgba(245,158,11,.12); color:#d97706; }
        .sec-red   { background:rgba(239,68,68,.10);  color:#dc2626; }
        .sec-purple{ background:rgba(124,58,237,.10); color:#7c3aed; }
        .section-header h5 { font-size:15px; font-weight:800; margin:0; color:var(--brand-navy-deep); }
        .section-body { padding:20px 22px; }

        .dato-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0; }
        .dato-row { display:flex; flex-direction:column; padding:10px 0; border-bottom:1px solid rgba(215,224,234,.5); }
        .dato-row:last-child { border-bottom:none; }
        .dato-label { font-size:11.5px; color:var(--brand-gray); font-weight:600; text-transform:uppercase; letter-spacing:.3px; margin-bottom:3px; }
        .dato-val { font-size:14px; color:var(--brand-navy-deep); }
        .dato-vacio { color:#b0bac5; font-style:italic; }

        .chip-si  { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:6px; padding:2px 10px; font-size:12px; font-weight:700; }
        .chip-no  { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:6px; padding:2px 10px; font-size:12px; font-weight:700; }
        .chip-prod { background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy)); color:#fff; border-radius:20px; padding:4px 14px; font-size:12px; font-weight:700; display:inline-block; margin:2px; }
        .chip-prod.green  { background:linear-gradient(135deg,#059669,#10b981); }
        .chip-prod.amber  { background:linear-gradient(135deg,#d97706,#f59e0b); }
        .chip-prod.purple { background:linear-gradient(135deg,#7c3aed,#8b5cf6); }
        .chip-prod.teal   { background:linear-gradient(135deg,#0d9488,#14b8a6); }

        .acuerdo-badge { border-radius:8px; padding:5px 14px; font-size:13px; font-weight:700; display:inline-block; }
        .acuerdo-ninguno       { background:#f3f4f6; color:#6b7280; }
        .acuerdo-nueva_cita    { background:#dbeafe; color:#1e40af; }
        .acuerdo-documentos    { background:#ede9fe; color:#5b21b6; }
        .acuerdo-levantamiento { background:#ecfdf5; color:#065f46; }

        .ficha-subsection { margin-bottom:18px; }
        .ficha-subtitle { font-size:12px; text-transform:uppercase; color:var(--brand-navy); font-weight:800; letter-spacing:.4px; margin-bottom:10px; padding-bottom:5px; border-bottom:2px solid var(--brand-yellow); display:flex; align-items:center; gap:7px; }
        .doc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .doc-chip { border-radius:6px; padding:4px 12px; font-size:12px; font-weight:600; }
        .doc-chip.ok  { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .doc-chip.no  { background:#f3f4f6; color:#9ca3af; border:1px solid #e5e7eb; text-decoration:line-through; }
        .empty-state { text-align:center; padding:30px; color:#9ca3af; font-size:14px; }
        .empty-state i { font-size:28px; display:block; margin-bottom:10px; }
    </style>
</head>
<body>
<div class="content-area">

    <a href="javascript:void(0)" onclick="if (document.referrer.includes('encuestas.php')) { history.back(); } else { window.location.href = 'encuestas.php'; }" class="btn-back">
        <i class="fas fa-arrow-left"></i> Volver a Encuestas
    </a>

    <!-- ── ENCABEZADO / IDENTIFICACIÓN ── -->
    <div class="client-hero">
        <h2><i class="fas fa-clipboard-list me-2"></i><?= htmlspecialchars(venc_tipo_label($tarea['tipo_tarea'] ?? '')) ?></h2>
        <p>Asesor: <?= htmlspecialchars($tarea['asesor_nombre'] ?: '—') ?> &nbsp;|&nbsp;
           <?php
                $fechaMostrar = $tarea['fecha_realizada'] ?: $tarea['fecha_programada'];
                $horaMostrar  = $tarea['fecha_realizada'] ? $tarea['hora_realizada'] : $tarea['hora_programada'];
           ?>
           Fecha: <?= $fechaMostrar ? date('d/m/Y', strtotime($fechaMostrar)) : '—' ?> <?= $horaMostrar ? ' ' . $horaMostrar : '' ?>
        </p>
        <div class="client-hero-badges">
            <span class="hero-badge yellow"><?= htmlspecialchars(ucfirst($tarea['estado'] ?? '—')) ?></span>
            <span class="hero-badge <?= $subida ? 'ok' : 'pend' ?>">
                <i class="fas <?= $subida ? 'fa-check-circle' : 'fa-cloud-upload-alt' ?>"></i>
                <?= $subida ? 'Subida al servidor' : 'Pendiente de sincronizar' ?>
            </span>
            <?php if ($cliente && !empty($cliente['origen_prospecto'])): ?>
                <?php [$origenLabel, $origenSub] = venc_origen_prospecto($cliente['origen_prospecto']); ?>
                <span class="hero-badge"><i class="fas fa-user-tag"></i> Tipo de prospecto: <?= htmlspecialchars($origenLabel) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($encuesta): ?>
    <!-- ── PREGUNTAS DE IDENTIFICACIÓN (Paso 1 en mobile) ── -->
    <div class="section-card">
        <div class="section-header">
            <div class="sec-icon sec-blue"><i class="fas fa-comments"></i></div>
            <h5>Preguntas de Identificación</h5>
        </div>
        <div class="section-body">

            <div class="ficha-subsection">
                <div class="ficha-subtitle">1. ¿Usted conoce o ha escuchado sobre nuestra institución?</div>
                <div class="dato-grid">
                    <div class="dato-row"><span class="dato-label">Respuesta</span><span class="dato-val"><?= yn($encuesta['p1_conoce_institucion'] ?? null) ?></span></div>
                </div>
                <?php if (!empty($encuesta['p1_obs'])): ?>
                    <div class="mt-2 p-2 rounded" style="background:#f8fafc;font-size:12px;border-left:3px solid #cbd5e1;"><strong>Observación:</strong> <?= htmlspecialchars($encuesta['p1_obs']) ?></div>
                <?php endif; ?>
            </div>

            <div class="ficha-subsection">
                <div class="ficha-subtitle">2. ¿Es usted cliente de nuestra institución?</div>
                <div class="dato-grid">
                    <div class="dato-row"><span class="dato-label">Respuesta</span><span class="dato-val"><?= yn($encuesta['p2_es_cliente'] ?? null) ?></span></div>
                </div>
                <?php if (!empty($encuesta['p2_es_cliente'])):
                    $p2str = strtolower((string)($encuesta['p2_producto'] ?? ''));
                    $p2prods = [];
                    if (str_contains($p2str, 'ahorro'))    $p2prods[] = 'Cuenta de Ahorro';
                    if (str_contains($p2str, 'corriente')) $p2prods[] = 'Cuenta Corriente';
                    if (str_contains($p2str, 'inversion')) $p2prods[] = 'Inversión / Depósito';
                    if (str_contains($p2str, 'credito'))   $p2prods[] = 'Crédito';
                ?>
                    <div style="margin-top:10px;">
                        <span class="dato-label" style="display:block;margin-bottom:6px;">Productos que mantiene o mantuvo</span>
                        <?php if (empty($p2prods)): ?>
                            <span class="dato-vacio">No especificado</span>
                        <?php else: ?>
                            <?php foreach ($p2prods as $p): ?><span class="chip-prod"><?= htmlspecialchars($p) ?></span><?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($encuesta['p2_obs'])): ?>
                    <div class="mt-2 p-2 rounded" style="background:#f8fafc;font-size:12px;border-left:3px solid #cbd5e1;"><strong>Observación:</strong> <?= htmlspecialchars($encuesta['p2_obs']) ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($encuesta['p2_es_cliente'])): ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle">3. ¿Qué tan a gusto está con nuestros servicios?</div>
                <?php
                $satisMap = ['muy_a_gusto' => 'Muy a gusto', 'medianamente' => 'Medianamente a gusto', 'no_a_gusto' => 'No estoy a gusto'];
                $satisVal = (string)($encuesta['p3_satisfaccion'] ?? '');
                ?>
                <div class="dato-grid">
                    <div class="dato-row"><span class="dato-label">Respuesta</span><span class="dato-val"><?= dato($satisMap[$satisVal] ?? $satisVal) ?></span></div>
                </div>
                <?php if (!empty($encuesta['p3_obs'])): ?>
                    <div class="mt-2 p-2 rounded" style="background:#f8fafc;font-size:12px;border-left:3px solid #cbd5e1;"><strong>Observación:</strong> <?= htmlspecialchars($encuesta['p3_obs']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

    <!-- ── DATOS DEL PROSPECTO ── -->
    <div class="section-card">
        <div class="section-header">
            <div class="sec-icon sec-blue"><i class="fas fa-id-card"></i></div>
            <h5>Datos del Prospecto</h5>
        </div>
        <div class="section-body">
            <?php if (!$cliente): ?>
                <div class="empty-state"><i class="fas fa-user-slash"></i>No hay datos de prospecto asociados a esta tarea.</div>
            <?php else: ?>
                <div class="dato-grid">
                    <?= etiq('Nombre completo',  $cliente['nombre']    ?? '') ?>
                    <?= etiq('Cédula / RUC',     $cliente['cedula']    ?? '') ?>
                    <?= etiq('Teléfono',         $cliente['telefono']  ?? '') ?>
                    <?= etiq('Celular',          $cliente['celular']   ?? ($cliente['telefono2'] ?? '')) ?>
                    <?= etiq('Email',            $cliente['email']     ?? '') ?>
                    <?= etiq('Dirección',        $cliente['direccion'] ?? '') ?>
                    <?= etiq('Actividad económica', $cliente['actividad'] ?? '') ?>
                    <?= etiq('Nombre empresa',   $cliente['nombre_empresa'] ?? '') ?>
                    <?= etiqYN('Tiene RUC',  $cliente['tiene_ruc']  ?? null) ?>
                    <?= etiqYN('Tiene RISE', $cliente['tiene_rise'] ?? null) ?>
                    <?= etiq('Zona',   $cliente['zona']   ?? '') ?>
                    <?= etiq('Ciudad', $cliente['ciudad'] ?? '') ?>
                    <?php if (isset($cliente['genero'])): ?>
                        <?= etiq('Género', $cliente['genero'] ?? '') ?>
                        <?= etiq('Estado Civil', $cliente['estado_civil'] ?? '') ?>
                        <?= etiq('Nivel Educación', $cliente['nivel_educacion'] ?? '') ?>
                        <?= etiq('Tipo Vivienda', $cliente['tipo_vivienda'] ?? '') ?>
                        <?= etiq('Dependientes', $cliente['num_dependientes'] ?? '') ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($cliente['origen_prospecto'])): ?>
                    <?php [$origenLabel, $origenSub] = venc_origen_prospecto($cliente['origen_prospecto']); ?>
                    <div class="ficha-subsection" style="margin-top:14px;">
                        <div class="ficha-subtitle"><i class="fas fa-user-tag"></i> Tipo de Prospecto</div>
                        <div class="dato-grid">
                            <?= etiq('Clasificación', $origenLabel . ($origenSub ? " ($origenSub)" : '')) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                $tipoEmpresaStr = (string)($cliente['tipo_empresa'] ?? '');
                $esServProd = str_contains($tipoEmpresaStr, 'servicio_produccion');
                $esComercio = str_contains($tipoEmpresaStr, 'comercio');
                if ($esServProd || $esComercio):
                ?>
                    <div class="ficha-subsection" style="margin-top:14px;">
                        <div class="ficha-subtitle"><i class="fas fa-industry"></i> Tipo de Empresa</div>
                        <?php
                        $tiposChips = [];
                        if ($esServProd) $tiposChips[] = '<span class="chip-prod teal"><i class="fas fa-cogs me-1"></i>Servicio / Producción</span>';
                        if ($esComercio) $tiposChips[] = '<span class="chip-prod amber"><i class="fas fa-shopping-cart me-1"></i>Comercio</span>';
                        echo implode(' ', $tiposChips);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($cliente['regimen_tributario'])): ?>
                <div class="ficha-subsection" style="margin-top:14px;">
                    <div class="ficha-subtitle"><i class="fas fa-file-invoice"></i> Régimen Tributario</div>
                    <div class="dato-grid">
                        <?= etiq('Régimen', venc_regimen_label($cliente['regimen_tributario'])) ?>
                        <?php if ($cliente['regimen_tributario'] === 'ruc'): ?>
                            <?= etiq('Número de RUC', $cliente['numero_ruc'] ?? '') ?>
                            <?= etiqYN('¿Declara IVA mensualmente?', $cliente['declara_iva'] ?? null) ?>
                            <?= etiqYN('¿Emite facturas electrónicas?', $cliente['emite_facturas'] ?? null) ?>
                            <?= etiqYN('¿Lleva contabilidad?', $cliente['lleva_contabilidad'] ?? null) ?>
                            <?= etiq('Valor RUC', $cliente['ruc_val'] ?? '') ?>
                        <?php elseif ($cliente['regimen_tributario'] === 'rise'): ?>
                            <?= etiqYN('¿Paga su cuota mensual del RISE?', $cliente['paga_cuota_rise'] ?? null) ?>
                            <?= etiqYN('¿Emite notas de venta?', $cliente['emite_notas_venta'] ?? null) ?>
                            <?= etiqYN('¿Conoce el límite de ingresos del RISE?', $cliente['conoce_limite_rise'] ?? null) ?>
                            <?= etiq('Valor RISE', $cliente['rise_val'] ?? '') ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($encuesta): ?>
    <!-- ── SITUACIÓN FINANCIERA / INTERÉS EN PRODUCTOS (encuesta de agenda) ── -->
    <div class="section-card">
        <div class="section-header">
            <div class="sec-icon sec-yellow"><i class="fas fa-star"></i></div>
            <h5>Situación Financiera e Interés en Productos</h5>
        </div>
        <div class="section-body">

            <div class="ficha-subsection">
                <div class="ficha-subtitle">¿Qué cuentas mantiene?</div>
                <div class="dato-grid">
                    <div class="dato-row">
                        <span class="dato-label">Cuenta de Ahorros</span>
                        <span class="dato-val">
                            <?= yn($encuesta['mantiene_cuenta_ahorro'] ?? null) ?>
                            <?php if (!empty($encuesta['mantiene_cuenta_ahorro']) && !empty($encuesta['banco_ahorro'])): ?>
                                — Institución: <strong><?= htmlspecialchars($encuesta['banco_ahorro']) ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="dato-row">
                        <span class="dato-label">Cuenta Corriente</span>
                        <span class="dato-val">
                            <?= yn($encuesta['mantiene_cuenta_corriente'] ?? null) ?>
                            <?php if (!empty($encuesta['mantiene_cuenta_corriente']) && !empty($encuesta['banco_corriente'])): ?>
                                — Institución: <strong><?= htmlspecialchars($encuesta['banco_corriente']) ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="ficha-subsection">
                <div class="ficha-subtitle">¿Tiene inversiones?</div>
                <div class="dato-grid">
                    <div class="dato-row"><span class="dato-label">Respuesta</span><span class="dato-val"><?= yn($encuesta['tiene_inversiones'] ?? null) ?></span></div>
                </div>
                <?php if (!empty($encuesta['tiene_inversiones'])): ?>
                <div class="dato-grid" style="margin-top:6px;">
                    <?= etiq('Institución',       $encuesta['institucion_inversiones'] ?? '') ?>
                    <?= etiq('Valor inversión',   $encuesta['valor_inversion']         ?? '', 'USD') ?>
                    <?= etiq('Plazo',             $encuesta['plazo_inversion']          ?? '') ?>
                    <?= etiq('Fecha vencimiento', $encuesta['fecha_vencimiento_inversion'] ?? '') ?>
                    <?= etiqYN('¿Le interesaría una propuesta previa al vencimiento?', $encuesta['propuesta_prev_vencimiento'] ?? null) ?>
                </div>
                <?php if (!empty($encuesta['propuesta_inversion'])): ?>
                    <div class="mt-2 p-2 rounded" style="background:#f8fafc;font-size:12px;border-left:3px solid #cbd5e1;"><strong>Propuesta de inversión:</strong> <?= htmlspecialchars($encuesta['propuesta_inversion']) ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($encuesta['institucion_credito'])): ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-university"></i> Crédito / Producto financiero actual</div>
                <div class="dato-grid">
                    <?= etiq('Institución crédito',          $encuesta['institucion_credito']          ?? '') ?>
                    <?= etiq('Institución prod. financiero', $encuesta['institucion_producto_financiero'] ?? '') ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="ficha-subsection">
                <div class="ficha-subtitle">¿Le interesaría conocer nuestros productos o servicios?</div>
                <div class="dato-grid">
                    <div class="dato-row"><span class="dato-label">Respuesta</span><span class="dato-val"><?= yn($encuesta['interes_conocer_productos'] ?? null) ?></span></div>
                </div>

                <?php if (!empty($encuesta['interes_conocer_productos'])):
                    $interes = [];
                    if (!empty($encuesta['interes_cc']))        $interes[] = '<span class="chip-prod teal"><i class="fas fa-exchange-alt me-1"></i>Cuenta Corriente</span>';
                    if (!empty($encuesta['interes_ahorro']))    $interes[] = '<span class="chip-prod green"><i class="fas fa-piggy-bank me-1"></i>Cuenta de Ahorros</span>';
                    if (!empty($encuesta['interes_inversion'])) $interes[] = '<span class="chip-prod purple"><i class="fas fa-chart-line me-1"></i>Inversiones</span>';
                    if (!empty($encuesta['interes_credito']))   $interes[] = '<span class="chip-prod amber"><i class="fas fa-hand-holding-usd me-1"></i>Crédito</span>';
                ?>
                    <div style="margin-top:10px;">
                        <span class="dato-label" style="display:block;margin-bottom:6px;">¿Cuáles productos le interesan?</span>
                        <?= empty($interes) ? '<span class="dato-vacio">Ninguno seleccionado</span>' : implode(' ', $interes) ?>
                    </div>
                <?php elseif ($encuesta['interes_conocer_productos'] !== null && $encuesta['interes_conocer_productos'] !== ''):
                    // Respondió que NO le interesa: mostrar la razón (mapea a los campos
                    // razon_ya_trabaja_institucion / razon_desconfia_servicios / razon_agusto_actual /
                    // razon_mala_experiencia guardados por guardar_cliente_encuesta.php).
                    $razones = [];
                    if (!empty($encuesta['razon_ya_trabaja_institucion'])) $razones[] = 'Ya trabaja con otra institución por muchos años';
                    if (!empty($encuesta['razon_desconfia_servicios']))    $razones[] = 'Desconfía en los servicios a ofrecer';
                    if (!empty($encuesta['razon_agusto_actual']))          $razones[] = 'Está a gusto con la institución actual';
                    if (!empty($encuesta['razon_mala_experiencia']))       $razones[] = 'Mala experiencia con nuestra institución';
                    if (!empty($encuesta['razon_otros']))                  $razones[] = htmlspecialchars($encuesta['razon_otros']);
                ?>
                    <div style="margin-top:10px;">
                        <span class="dato-label" style="display:block;margin-bottom:6px;">¿Cuál es la razón?</span>
                        <?php if (empty($razones)): ?>
                            <span class="dato-vacio">No especificada</span>
                        <?php else: ?>
                            <div class="doc-chips"><?php foreach ($razones as $r): ?><span class="doc-chip no"><?= $r ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            $busca = [];
            if (!empty($encuesta['que_busca_agilidad']))        $busca[] = 'Agilidad';
            if (!empty($encuesta['que_busca_cajeros']))         $busca[] = 'Cajeros';
            if (!empty($encuesta['que_busca_banca_linea']))     $busca[] = 'Banca en línea';
            if (!empty($encuesta['que_busca_agencias']))        $busca[] = 'Agencias cerca';
            if (!empty($encuesta['que_busca_credito_rapido']))  $busca[] = 'Crédito rápido';
            ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle">¿Qué busca de una institución financiera?</div>
                <?php if (empty($busca)): ?>
                    <span class="dato-vacio">No especificado</span>
                <?php else: ?>
                    <div class="doc-chips"><?php foreach ($busca as $b): ?><span class="doc-chip ok"><?= htmlspecialchars($b) ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <?php if ($encuesta_negocio): ?>
    <!-- ── EMPRESA / NEGOCIO (Levantamiento de Empresa) ── -->
    <div class="section-card">
        <div class="section-header">
            <div class="sec-icon sec-green"><i class="fas fa-store"></i></div>
            <h5>Empresa / Negocio — Levantamiento</h5>
        </div>
        <div class="section-body">

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
                    <?= etiq('Mes Alta Venta',  $encuesta_negocio['mes_alta_venta'] ?? '') ?>
                    <?= etiq('Mes Baja Venta',  $encuesta_negocio['mes_baja_venta'] ?? '') ?>
                    <?= etiq('Mes Alta Compra', $encuesta_negocio['mes_alta_compra'] ?? '') ?>
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

            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-file-invoice-dollar"></i> Gastos del Negocio</div>
                <div class="dato-grid">
                    <?= etiq('Costos de Ventas',   $encuesta_negocio['costos_ventas'] ?? '', 'USD') ?>
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

            <?php
            $veh_neg = json_decode($encuesta_negocio['vehiculos_negocio_json'] ?? '[]', true);
            $veh_hog = json_decode($encuesta_negocio['vehiculos_hogar_json'] ?? '[]', true);
            $all_veh = array_merge(
                array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($veh_neg)?$veh_neg:[]),
                array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($veh_hog)?$veh_hog:[])
            );
            ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-car-side"></i> Vehículos</div>
                <?php if (!empty($all_veh)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" style="font-size:12px;">
                        <thead class="bg-light"><tr><th>Tipo</th><th>Descripción</th><th>Marca/Modelo</th><th>Año</th><th>Valor</th></tr></thead>
                        <tbody>
                        <?php foreach ($all_veh as $v): if (empty($v['descripcion']) && empty($v['marca'])) continue; ?>
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
                <?php else: ?><p class="text-muted" style="font-size:12px;">No se declararon vehículos.</p><?php endif; ?>
            </div>

            <?php
            $inm_neg = json_decode($encuesta_negocio['inmuebles_negocio_json'] ?? '[]', true);
            $inm_hog = json_decode($encuesta_negocio['inmuebles_hogar_json'] ?? '[]', true);
            $all_inm = array_merge(
                array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($inm_neg)?$inm_neg:[]),
                array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($inm_hog)?$inm_hog:[])
            );
            ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-building"></i> Inmuebles y Propiedades</div>
                <?php if (!empty($all_inm)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" style="font-size:12px;">
                        <thead class="bg-light"><tr><th>Tipo</th><th>Descripción</th><th>Ubicación</th><th>Área</th><th>Valor Est.</th></tr></thead>
                        <tbody>
                        <?php foreach ($all_inm as $i): if (empty($i['descripcion']) && empty($i['ubicacion'])) continue; ?>
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
                <?php else: ?><p class="text-muted" style="font-size:12px;">No se declararon inmuebles.</p><?php endif; ?>
            </div>

            <?php $deudas = json_decode($encuesta_negocio['otras_deudas_json'] ?? '[]', true); ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-hand-holding-usd"></i> Otras Deudas Declaradas</div>
                <?php if (!empty($deudas)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" style="font-size:12px;">
                        <thead class="bg-light"><tr><th>Acreedor</th><th>Destino</th><th>Monto Inicial</th><th>Saldo Actual</th><th>Cuota Mes</th></tr></thead>
                        <tbody>
                        <?php foreach ($deudas as $d): if (empty($d['acreedor'])) continue; ?>
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
                <?php else: ?><p class="text-muted" style="font-size:12px;">No se reportaron otras deudas.</p><?php endif; ?>
            </div>

            <?php
            $com_prods = json_decode($encuesta_negocio['comercio_productos_json'] ?? '[]', true);
            if (!empty($com_prods)):
            ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-shopping-basket"></i> Detalle de Productos (Comercio)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" style="font-size:11px;">
                        <thead class="table-light"><tr><th>Producto</th><th>Costo Unit.</th><th>P. Venta</th><th>Cant. Mes</th><th>Venta Mes</th><th>Margen</th><th>Existencias</th></tr></thead>
                        <tbody>
                        <?php foreach ($com_prods as $cp): if (empty($cp['nombre'])) continue; ?>
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

            <?php
            $act_neg = json_decode($encuesta_negocio['activos_negocio_json'] ?? '[]', true);
            $act_hog = json_decode($encuesta_negocio['activos_hogar_json'] ?? '[]', true);
            $all_act = array_merge(
                array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($act_neg)?$act_neg:[]),
                array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($act_hog)?$act_hog:[])
            );
            ?>
            <div class="ficha-subsection">
                <div class="ficha-subtitle"><i class="fas fa-box"></i> Activos Fijos y Herramientas</div>
                <?php if (!empty($all_act)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" style="font-size:11px;">
                        <thead class="bg-light"><tr><th>Tipo</th><th>Descripción</th><th>Marca/Modelo</th><th>Serie</th><th>Valor</th></tr></thead>
                        <tbody>
                        <?php foreach ($all_act as $a): if (empty($a['descripcion'])) continue; ?>
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
                <?php else: ?><p class="text-muted" style="font-size:12px;">No se declararon otros activos fijos.</p><?php endif; ?>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <?php if (!$encuesta && !$encuesta_negocio): ?>
    <div class="section-card">
        <div class="section-body">
            <div class="empty-state">
                <i class="fas fa-cloud-upload-alt"></i>
                Esta tarea todavía no tiene una encuesta subida al servidor. Si el asesor la llenó sin conexión, aparecerá aquí en cuanto el celular sincronice.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── ACUERDO Y CIERRE ── -->
    <div class="section-card">
        <div class="section-header">
            <div class="sec-icon sec-purple"><i class="fas fa-handshake"></i></div>
            <h5>Acuerdo y Cierre</h5>
        </div>
        <div class="section-body">
            <?php [$acuerdoLabel, $acuerdoClass] = venc_acuerdo_label($encuesta['acuerdo_logrado'] ?? null); ?>
            <div class="dato-grid">
                <div class="dato-row">
                    <span class="dato-label">Acuerdo Logrado</span>
                    <span class="dato-val"><span class="acuerdo-badge <?= $acuerdoClass ?>"><?= htmlspecialchars($acuerdoLabel) ?></span></span>
                </div>
                <?= etiq('Observaciones', $tarea['observaciones'] ?? '') ?>
            </div>
        </div>
    </div>

</div>
</body>
</html>

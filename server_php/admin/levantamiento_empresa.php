<?php
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id'];
$asesor_nombre     = $_SESSION['asesor_nombre'] ?? 'Asesor';
$asesor_table_id   = $_SESSION['asesor_table_id'] ?? null;

if (!$asesor_table_id) {
    try {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$asesor_usuario_id]);
        $asesor_table_id = $st->fetchColumn() ?: null;
    } catch (PDOException $e) {}
}

/* sidebar badges */
$tareas_pendientes  = 0;
$alertas_pendientes = 0;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id = ? AND fecha_programada = ? AND estado <> 'completada'");
        $st->execute([$asesor_table_id, date('Y-m-d')]);
        $tareas_pendientes = (int)$st->fetchColumn();
    }
} catch (PDOException $e) {}

$currentPage = 'empresa';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Levantamiento de Empresa — Asesor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
    --brand-yellow:#ffdd00; --brand-yellow-deep:#f4c400;
    --brand-navy:#123a6d;   --brand-navy-deep:#0a2748;
    --brand-gray:#6b7280;   --brand-border:#d7e0ea;
    --brand-bg:#f4f6f9;
    --brand-shadow-sm:0 4px 12px rgba(18,58,109,.06);
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter','Segoe UI',sans-serif;background:var(--brand-bg);display:flex;min-height:100vh;color:var(--brand-navy-deep);}

/* ── SIDEBAR ─────────────────────────────────────────── */
.sidebar{width:230px;background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%);color:#fff;padding:20px 0;overflow-y:auto;position:fixed;height:100vh;left:0;top:0;z-index:100;}
.sidebar-brand{padding:0 20px 24px;font-size:18px;font-weight:800;border-bottom:1px solid rgba(255,221,0,.18);margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.sidebar-brand i{color:var(--brand-yellow);}
.sidebar-section{padding:0 15px;margin-bottom:22px;}
.sidebar-section-title{font-size:11px;text-transform:uppercase;color:rgba(255,255,255,.5);letter-spacing:.6px;padding:0 10px;margin-bottom:10px;font-weight:700;}
.sidebar-link{display:flex;align-items:center;gap:12px;padding:11px 15px;margin-bottom:4px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:14px;border:1px solid transparent;transition:.22s;}
.sidebar-link:hover{background:rgba(255,221,0,.12);color:#fff;padding-left:20px;border-color:rgba(255,221,0,.15);}
.sidebar-link.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);font-weight:700;}
.badge-nav{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:auto;}

/* ── LAYOUT ──────────────────────────────────────────── */
.main-content{flex:1;margin-left:230px;display:flex;flex-direction:column;min-width:0;}
.navbar-custom{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 28px rgba(18,58,109,.18);position:sticky;top:0;z-index:50;}
.navbar-custom h2{margin:0;font-size:19px;font-weight:700;display:flex;align-items:center;gap:10px;}
.navbar-custom h2 i{color:var(--brand-yellow);}
.user-info{display:flex;align-items:center;gap:14px;font-size:13px;}
.btn-logout{background:rgba(255,221,0,.15);color:#fff;border:1px solid rgba(255,221,0,.28);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;}
.content-area{flex:1;padding:24px 30px 60px;}

/* ── SEARCH ──────────────────────────────────────────── */
.search-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:24px;box-shadow:var(--brand-shadow-sm);margin-bottom:20px;}
.search-card h3{font-size:16px;font-weight:800;margin-bottom:6px;display:flex;align-items:center;gap:9px;color:var(--brand-navy-deep);}
.search-card h3 i{color:var(--brand-yellow-deep);}
.search-card .sub{color:var(--brand-gray);font-size:13px;margin-bottom:16px;}
.search-row{display:flex;gap:10px;align-items:stretch;}
.search-row input{flex:1;padding:12px 16px;border:2px solid var(--brand-border);border-radius:12px;font-size:15px;font-family:inherit;transition:.2s;}
.search-row input:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.btn-search{background:var(--brand-yellow);color:var(--brand-navy-deep);border:none;border-radius:12px;padding:12px 22px;font-weight:800;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;}
.btn-search:hover{background:var(--brand-yellow-deep);}
.btn-search:disabled{opacity:.5;cursor:not-allowed;}

/* prospect list */
.prosp-list{list-style:none;margin-top:12px;display:flex;flex-direction:column;gap:8px;}
.prosp-item{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;cursor:pointer;transition:.2s;}
.prosp-item:hover{background:#eff6ff;border-color:#bfdbfe;}
.prosp-item .pi-name{font-weight:700;font-size:14px;color:var(--brand-navy-deep);}
.prosp-item .pi-meta{font-size:12px;color:var(--brand-gray);margin-top:2px;}
.prosp-item .pi-btn{background:var(--brand-navy-deep);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.prosp-item .pi-btn:hover{background:var(--brand-navy);}
.no-results{text-align:center;padding:20px;color:var(--brand-gray);font-size:14px;}

.found-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:30px;font-size:13px;font-weight:700;margin-top:8px;}
.found-chip.found{background:#d1fae5;color:#065f46;}

/* ── STEPPER ─────────────────────────────────────────── */
.stepper{display:flex;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:16px 20px;margin-bottom:18px;box-shadow:var(--brand-shadow-sm);overflow-x:auto;gap:6px;}
.step{display:flex;align-items:center;gap:8px;flex:1;min-width:100px;}
.step .num{width:32px;height:32px;border-radius:50%;background:#e5e7eb;color:#6b7280;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0;transition:.2s;}
.step .lbl{font-size:11px;color:var(--brand-gray);font-weight:700;line-height:1.2;}
.step.active .num{background:var(--brand-yellow);color:var(--brand-navy-deep);}
.step.active .lbl{color:var(--brand-navy-deep);}
.step.done .num{background:#10b981;color:#fff;}
.step.done .lbl{color:#065f46;}
.step .line{flex:1;height:2px;background:#e5e7eb;margin:0 4px;}
.step:last-child .line{display:none;}

/* ── FORM CARDS ──────────────────────────────────────── */
.form-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:22px 24px;box-shadow:var(--brand-shadow-sm);margin-bottom:16px;}
.form-card h3{font-size:17px;font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:10px;}
.form-card h3 i{color:var(--brand-yellow-deep);}
.form-card .sub{color:var(--brand-gray);font-size:13.5px;margin-bottom:18px;}

.fld-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px 18px;}
.fld{display:flex;flex-direction:column;gap:5px;}
.fld label{font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;}
.fld input,.fld select,.fld textarea{padding:10px 12px;border:1.5px solid var(--brand-border);border-radius:10px;font-size:14px;font-family:inherit;background:#fff;transition:.2s;}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.fld textarea{resize:vertical;min-height:70px;}
.fld.full{grid-column:1/-1;}

/* Checkbox labels */
.checkbox-label{display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;color:var(--brand-navy-deep);padding:10px;background:#f8fafc;border:1.5px solid var(--brand-border);border-radius:10px;transition:.2s;user-select:none;}
.checkbox-label input[type="checkbox"]{width:18px;height:18px;cursor:pointer;accent-color:var(--brand-yellow-deep);}
.checkbox-label:hover{background:#eff6ff;border-color:var(--brand-yellow-deep);}
.checkbox-label input[type="checkbox"]:checked ~ span{font-weight:700;color:var(--brand-navy-deep);}

.sub-sec{margin-top:20px;padding-top:16px;border-top:1px dashed #e5e7eb;}
.sub-sec h5{font-size:12px;text-transform:uppercase;color:var(--brand-navy);font-weight:800;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.sub-sec h5 i{color:var(--brand-yellow-deep);}

/* YN toggle */
.yn-group{display:flex;gap:6px;}
.yn-opt{flex:1;padding:10px;text-align:center;border:1.5px solid var(--brand-border);border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;background:#fff;color:#374151;transition:.2s;}
.yn-opt:hover{background:#f3f4f6;}
.yn-opt input{display:none;}
.yn-opt.checked{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-color:transparent;}

/* productos repeater */
.prod-list{display:flex;flex-direction:column;gap:10px;}
.prod-item{background:#fafbfc;border:1px solid #eef2f6;border-radius:10px;padding:14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px 12px;position:relative;}
.prod-item .del-prod{position:absolute;top:8px;right:8px;background:#fef2f2;color:#991b1b;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;font-weight:700;}
.prod-item .del-prod:hover{background:#fee2e2;}
.btn-add-prod{background:#fef9c3;color:#854d0e;border:1.5px dashed #fde68a;padding:10px;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;width:100%;margin-top:10px;display:flex;align-items:center;justify-content:center;gap:6px;}
.btn-add-prod:hover{background:#fef08a;}

/* footer */
.form-footer{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:14px 18px;box-shadow:var(--brand-shadow-sm);position:sticky;bottom:14px;z-index:30;}
.btn{padding:11px 22px;border-radius:11px;font-weight:800;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;}
.btn-yellow{background:var(--brand-yellow);color:var(--brand-navy-deep);}
.btn-yellow:hover{background:var(--brand-yellow-deep);}
.btn-ghost{background:#f3f4f6;color:#374151;}
.btn-ghost:hover{background:#e5e7eb;}
.btn-primary{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;}

.step-pane{display:none;}
.step-pane.active{display:block;animation:fadein .22s ease;}
@keyframes fadein{from{opacity:0;transform:translateY(5px);}to{opacity:1;transform:none;}}

.info-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#1e40af;font-weight:600;margin-bottom:16px;}
.info-banner i{font-size:16px;}

@media(max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .main-content{margin-left:0;}
    .content-area{padding:14px;}
    .step .lbl{display:none;}
    .form-card{padding:16px;}
    .fld-grid{grid-template-columns:1fr;}
}

/* ══ VENTAS/COMPRAS POR DÍA ══ */
.day-block{margin-bottom:22px;}
.day-block-title{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;
    color:#374151;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.day-row{display:flex;align-items:center;gap:12px;padding:6px 0;border-bottom:1px solid #f0f0f0;}
.day-row:last-child{border-bottom:none;}
.day-label{width:90px;font-size:.9rem;color:#374151;flex-shrink:0;}
.day-input-wrap{flex:1;position:relative;}
.day-input-wrap .day-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:.95rem;}
.day-input-wrap input{width:100%;padding:8px 10px 8px 32px;border:1.5px solid var(--brand-border);
    border-radius:8px;font-size:.95rem;background:#fff;}
.day-input-wrap input:focus{outline:none;border-color:var(--brand-yellow);}

/* ══ MES ALTO/BAJO ══ */
.mes-row{display:flex;gap:12px;margin-top:14px;}
.mes-row .fld{flex:1;}
.mes-row select{width:100%;padding:9px 10px;border:1.5px solid var(--brand-border);
    border-radius:8px;font-size:.9rem;}

/* ══ DÍAS DE ATENCIÓN CHIPS ══ */
.dias-chip-grid{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 16px;}
.dia-chip{padding:8px 16px;border-radius:8px;border:2px solid var(--brand-border);
    background:#fff;cursor:pointer;font-size:.88rem;font-weight:600;color:#374151;
    display:flex;align-items:center;gap:5px;transition:all .15s;}
.dia-chip.on{background:#3b82f6;border-color:#3b82f6;color:#fff;}
.dia-chip .dc-check{display:none;}
.dia-chip.on .dc-check{display:inline;}

/* ══ RESUMEN RÁPIDO ══ */
.resumen-box{background:#f9fafb;border:1px solid var(--brand-border);border-radius:10px;
    padding:14px 18px;margin:16px 0;font-size:.9rem;}
.resumen-box .rb-title{font-size:.78rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.05em;color:#6b7280;margin-bottom:8px;}
.resumen-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
.resumen-item{text-align:center;}
.resumen-item .rv-label{font-size:.75rem;color:#6b7280;}
.resumen-item .rv-val{font-size:1.1rem;font-weight:800;color:var(--brand-navy-deep);}

/* ══ SLIDER COBRO ══ */
.cobro-card{background:#fff;border:1.5px solid var(--brand-border);border-radius:12px;padding:16px 18px;margin-top:16px;}
.cobro-badges{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.badge-ef{background:#d1fae5;color:#065f46;padding:4px 10px;border-radius:20px;font-size:.82rem;font-weight:700;}
.badge-dg{background:#fef3c7;color:#92400e;padding:4px 10px;border-radius:20px;font-size:.82rem;font-weight:700;}
.cobro-slider{width:100%;accent-color:#10b981;height:6px;cursor:pointer;}
.cobro-labels{display:flex;justify-content:space-between;font-size:.72rem;color:#9ca3af;margin-top:4px;}

/* ══ CREDITO ACTIVO (top of pane) ══ */
.credito-top-card{background:#fffbeb;border:1.5px solid #fcd34d;border-radius:12px;
    padding:16px 18px;margin-bottom:20px;}
.credito-top-card h5{font-size:.9rem;font-weight:700;color:#92400e;margin-bottom:12px;
    display:flex;align-items:center;gap:7px;}

</style>
</head>
<body>

<?php require __DIR__ . '/_sidebar_asesor.php'; ?>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-building"></i> Levantamiento de Empresa</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($asesor_nombre) ?></strong></div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>

    <div class="content-area">

        <!-- ══ BÚSQUEDA POR NOMBRE ══ -->
        <div class="search-card">
            <h3><i class="fas fa-magnifying-glass"></i>Buscar empresa / prospecto</h3>
            <p class="sub">Escribe el nombre o la razón social de la empresa para cargar el prospecto correspondiente.</p>
            <div class="search-row" style="position:relative;">
                <input type="text" id="inp-nombre" placeholder="Nombre de la empresa o del prospecto…" autocomplete="off">
                <!-- Dropdown dinámico -->
                <div id="empresa-dropdown" style="position:absolute;top:100%;left:0;right:70px;background:#fff;border:1px solid var(--brand-border);border-top:none;border-radius:0 0 8px 0;max-height:250px;overflow-y:auto;z-index:1000;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1);">
                    <div id="empresa-list"></div>
                </div>
                <button class="btn-search" id="btn-buscar" type="button">
                    <i class="fas fa-search"></i> Buscar
                </button>
            </div>
            <div id="search-result" style="display:none;"></div>
        </div>

        <!-- ══ STEPPER ══ -->
        <div class="stepper" id="stepper" style="display:none;">
            <?php $steps = [
                ['Empresa',    'fa-building'],
                ['Ventas',     'fa-chart-bar'],
                ['Productos',  'fa-boxes-stacked'],
                ['Cierre',     'fa-handshake'],
            ];
            foreach ($steps as $i => [$lbl, $ico]): ?>
                <div class="step <?= $i===0?'active':'' ?>" data-step="<?= $i ?>">
                    <div class="num"><i class="fas <?= $ico ?>" style="font-size:11px;"></i></div>
                    <div class="lbl"><?= $lbl ?></div>
                    <div class="line"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ══ FORMULARIO ══ -->
        <form id="formEmpresa" method="post" action="guardar_empresa.php" autocomplete="off"
              style="display:none;">
            <input type="hidden" name="cliente_id"   id="hid-cliente_id">
            <input type="hidden" name="asesor_id"    value="<?= $asesor_table_id ?>">
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">

            <!-- ══════════════════════════════════════
                 PASO 1 — DATOS DE LA EMPRESA
            ═══════════════════════════════════════ -->
            <div class="step-pane active" data-pane="0">
                <div class="form-card">
                    <h3><i class="fas fa-building"></i>Datos de la empresa</h3>
                    <p class="sub">Información general del negocio o empresa visitada.</p>

                    <div id="info-cargado" class="info-banner" style="display:none;">
                        <i class="fas fa-circle-check"></i>
                        <span id="info-cargado-texto">Datos del prospecto cargados. Completa la información de la empresa.</span>
                    </div>

                    <div class="fld-grid">
                        <div class="fld full">
                            <label>Nombre / Razón social *</label>
                            <input type="text" name="nombre_empresa" id="f-nombre_empresa" required
                                   placeholder="Nombre comercial o razón social">
                        </div>
                        <div class="fld">
                            <label>Nombre del propietario / contacto</label>
                            <input type="text" name="nombre_propietario" id="f-nombre_propietario"
                                   placeholder="Nombre y apellido">
                        </div>
                        <div class="fld">
                            <label>Cédula / RUC</label>
                            <input type="text" name="cedula_ruc" id="f-cedula_ruc"
                                   placeholder="Cédula o RUC de la empresa">
                        </div>
                        <div class="fld">
                            <label>Teléfono / Celular</label>
                            <input type="tel" name="telefono_empresa" id="f-telefono_empresa">
                        </div>
                        <div class="fld">
                            <label>Email de la empresa</label>
                            <input type="email" name="email_empresa" placeholder="empresa@ejemplo.com">
                        </div>
                        <div class="fld full">
                            <label>Dirección de la empresa *</label>
                            <input type="text" name="direccion_empresa" required
                                   placeholder="Calle, número, sector, referencia">
                        </div>
                        <div class="fld">
                            <label>Ciudad</label>
                            <input type="text" name="ciudad_empresa" id="f-ciudad"
                                   placeholder="Ciudad">
                        </div>
                        <div class="fld">
                            <label>Sector / Zona</label>
                            <input type="text" name="zona_empresa" id="f-zona"
                                   placeholder="Sector o barrio">
                        </div>
                        <div class="fld">
                            <label>Sector económico *</label>
                            <select name="sector_economico" required>
                                <option value="">— Selecciona —</option>
                                <option value="comercio">Comercio</option>
                                <option value="manufactura">Manufactura</option>
                                <option value="servicios">Servicios</option>
                                <option value="agropecuario">Agropecuario</option>
                                <option value="transporte">Transporte</option>
                                <option value="construccion">Construcción</option>
                                <option value="tecnologia">Tecnología</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Tamaño de la empresa</label>
                            <select name="tamano_empresa">
                                <option value="">— Selecciona —</option>
                                <option value="micro">Microempresa (1-9 empleados)</option>
                                <option value="pequena">Pequeña (10-49)</option>
                                <option value="mediana">Mediana (50-199)</option>
                                <option value="grande">Grande (200+)</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Años de operación</label>
                            <input type="number" name="anos_operacion" min="0" max="200"
                                   placeholder="Ej: 5">
                        </div>
                        <div class="fld">
                            <label>N° empleados aprox.</label>
                            <input type="number" name="num_empleados" min="0"
                                   placeholder="Ej: 12">
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-file-invoice"></i>Régimen tributario</h5>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Tipo de régimen</label>
                                <select name="regimen_tributario">
                                    <option value="">— Selecciona —</option>
                                    <option value="ruc">RUC</option>
                                    <option value="rise">RISE</option>
                                    <option value="ninguno">Ninguno</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>N° RUC (si aplica)</label>
                                <input type="text" name="numero_ruc" placeholder="1234567890001">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 2 — COMPORTAMIENTO DE VENTAS
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="1">
                <div class="form-card">
                    <h3><i class="fas fa-chart-bar"></i> Empresa / Negocio</h3>
                    <p class="sub">Datos aproximados para entender el movimiento del negocio.</p>

                    <!-- ── CRÉDITO ACTIVO (al tope) ── -->
                    <div class="credito-top-card">
                        <h5><i class="fas fa-hand-holding-dollar"></i> Situación financiera actual</h5>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>¿Tiene crédito activo?</label>
                                <div class="yn-group">
                                    <label class="yn-opt"><input type="radio" name="tiene_credito_activo" value="1"> Sí</label>
                                    <label class="yn-opt"><input type="radio" name="tiene_credito_activo" value="0"> No</label>
                                </div>
                            </div>
                            <div class="fld">
                                <label>Institución del crédito</label>
                                <input type="text" name="institucion_credito" placeholder="Banco / Cooperativa">
                            </div>
                            <div class="fld">
                                <label>Monto de crédito (USD)</label>
                                <input type="number" step="0.01" min="0" name="monto_credito_actual" placeholder="0.00">
                            </div>
                            <div class="fld">
                                <label>Destino del crédito</label>
                                <select name="destino_credito">
                                    <option value="">—</option>
                                    <option value="capital_trabajo">Capital de trabajo</option>
                                    <option value="activos_fijos">Activos fijos</option>
                                    <option value="expansion">Expansión</option>
                                    <option value="pago_deudas">Pago de deudas</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ── VENTAS POR DÍA ── -->
                    <div class="day-block">
                        <div class="day-block-title"><i class="fas fa-arrow-trend-up" style="color:#d97706;"></i> Comportamiento de ventas (monto $ al día)</div>
                                    <div class="day-row">
                                        <span class="day-label">Lunes</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_lunes"
                                                   id="vd-lunes" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Martes</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_martes"
                                                   id="vd-martes" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Miércoles</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_miercoles"
                                                   id="vd-miercoles" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Jueves</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_jueves"
                                                   id="vd-jueves" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Viernes</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_viernes"
                                                   id="vd-viernes" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Sábado</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_sabado"
                                                   id="vd-sabado" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Domingo</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">📈</span>
                                            <input type="number" step="0.01" min="0" name="venta_domingo"
                                                   id="vd-domingo" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                        <!-- Mes alto / bajo -->
                        <div class="mes-row">
                            <div class="fld">
                                <label style="font-size:.82rem;">Mes alto (venta)</label>
                                <select name="mes_alto_venta">
                                    <option value="">— Seleccione —</option>
                                        <option value="enero">Enero</option>
                                        <option value="febrero">Febrero</option>
                                        <option value="marzo">Marzo</option>
                                        <option value="abril">Abril</option>
                                        <option value="mayo">Mayo</option>
                                        <option value="junio">Junio</option>
                                        <option value="julio">Julio</option>
                                        <option value="agosto">Agosto</option>
                                        <option value="septiembre">Septiembre</option>
                                        <option value="octubre">Octubre</option>
                                        <option value="noviembre">Noviembre</option>
                                        <option value="diciembre">Diciembre</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label style="font-size:.82rem;">Mes bajo (venta)</label>
                                <select name="mes_bajo_venta">
                                    <option value="">— Seleccione —</option>
                                        <option value="enero">Enero</option>
                                        <option value="febrero">Febrero</option>
                                        <option value="marzo">Marzo</option>
                                        <option value="abril">Abril</option>
                                        <option value="mayo">Mayo</option>
                                        <option value="junio">Junio</option>
                                        <option value="julio">Julio</option>
                                        <option value="agosto">Agosto</option>
                                        <option value="septiembre">Septiembre</option>
                                        <option value="octubre">Octubre</option>
                                        <option value="noviembre">Noviembre</option>
                                        <option value="diciembre">Diciembre</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ── COMPRAS POR DÍA ── -->
                    <div class="day-block">
                        <div class="day-block-title"><i class="fas fa-cart-shopping" style="color:#d97706;"></i> Comportamiento de compras (monto $ al día)</div>
                                    <div class="day-row">
                                        <span class="day-label">Lunes</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_lunes"
                                                   id="cd-lunes" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Martes</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_martes"
                                                   id="cd-martes" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Miércoles</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_miercoles"
                                                   id="cd-miercoles" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Jueves</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_jueves"
                                                   id="cd-jueves" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Viernes</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_viernes"
                                                   id="cd-viernes" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Sábado</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_sabado"
                                                   id="cd-sabado" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                                    <div class="day-row">
                                        <span class="day-label">Domingo</span>
                                        <div class="day-input-wrap">
                                            <span class="day-icon" style="color:#d97706;">🛒</span>
                                            <input type="number" step="0.01" min="0" name="compra_domingo"
                                                   id="cd-domingo" placeholder="0.00" oninput="calcResumen()">
                                        </div>
                                    </div>
                        <!-- Mes alto compra -->
                        <div class="mes-row">
                            <div class="fld">
                                <label style="font-size:.82rem;">Mes alto (compra)</label>
                                <select name="mes_alto_compra">
                                    <option value="">— Seleccione —</option>
                                        <option value="enero">Enero</option>
                                        <option value="febrero">Febrero</option>
                                        <option value="marzo">Marzo</option>
                                        <option value="abril">Abril</option>
                                        <option value="mayo">Mayo</option>
                                        <option value="junio">Junio</option>
                                        <option value="julio">Julio</option>
                                        <option value="agosto">Agosto</option>
                                        <option value="septiembre">Septiembre</option>
                                        <option value="octubre">Octubre</option>
                                        <option value="noviembre">Noviembre</option>
                                        <option value="diciembre">Diciembre</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- ── DÍAS DE ATENCIÓN ── -->
                    <div class="day-block-title"><i class="fas fa-calendar-check" style="color:#3b82f6;"></i> Días de atención</div>
                    <div class="dias-chip-grid">
                                <div class="dia-chip" data-dia="lunes" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Lunes
                                    <input type="checkbox" name="dia_atencion_lunes" value="1" style="display:none;" class="dia-chk">
                                </div>
                                <div class="dia-chip" data-dia="martes" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Martes
                                    <input type="checkbox" name="dia_atencion_martes" value="1" style="display:none;" class="dia-chk">
                                </div>
                                <div class="dia-chip" data-dia="miercoles" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Miércoles
                                    <input type="checkbox" name="dia_atencion_miercoles" value="1" style="display:none;" class="dia-chk">
                                </div>
                                <div class="dia-chip" data-dia="jueves" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Jueves
                                    <input type="checkbox" name="dia_atencion_jueves" value="1" style="display:none;" class="dia-chk">
                                </div>
                                <div class="dia-chip" data-dia="viernes" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Viernes
                                    <input type="checkbox" name="dia_atencion_viernes" value="1" style="display:none;" class="dia-chk">
                                </div>
                                <div class="dia-chip" data-dia="sabado" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Sábado
                                    <input type="checkbox" name="dia_atencion_sabado" value="1" style="display:none;" class="dia-chk">
                                </div>
                                <div class="dia-chip" data-dia="domingo" onclick="toggleDia(this)">
                                    <span class="dc-check">✓</span> Domingo
                                    <input type="checkbox" name="dia_atencion_domingo" value="1" style="display:none;" class="dia-chk">
                                </div>
                    </div>

                    <!-- ── RESUMEN RÁPIDO ── -->
                    <div class="resumen-box">
                        <div class="rb-title">Resumen rápido</div>
                        <div class="resumen-grid">
                            <div class="resumen-item">
                                <div class="rv-label">Ventas semana</div>
                                <div class="rv-val" id="rv-venta-sem">$ 0.00</div>
                            </div>
                            <div class="resumen-item">
                                <div class="rv-label">Compras semana</div>
                                <div class="rv-val" id="rv-compra-sem">$ 0.00</div>
                            </div>
                            <div class="resumen-item">
                                <div class="rv-label">Ventas mes (×4.33)</div>
                                <div class="rv-val" id="rv-venta-mes">$ 0.00</div>
                            </div>
                            <div class="resumen-item">
                                <div class="rv-label">Compras mes (×4.33)</div>
                                <div class="rv-val" id="rv-compra-mes">$ 0.00</div>
                            </div>
                        </div>
                        <!-- hidden totals para guardar -->
                        <input type="hidden" name="cv_venta_promedio_sem" id="hid-venta-sem">
                        <input type="hidden" name="cv_compra_promedio_sem" id="hid-compra-sem">
                        <input type="hidden" name="cv_venta_promedio_mes" id="hid-venta-mes">
                        <input type="hidden" name="cv_compra_promedio_mes" id="hid-compra-mes">
                    </div>

                    <!-- ── FORMA DE COBRO ── -->
                    <div class="day-block-title" style="margin-top:18px;"><i class="fas fa-wallet" style="color:#10b981;"></i> Forma de cobro</div>
                    <div class="cobro-card">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">
                            <i class="fas fa-mobile-screen-button" style="color:#6b7280;font-size:1.4rem;"></i>
                            <div style="flex:1;">
                                <div style="font-size:.88rem;font-weight:600;color:#374151;margin-bottom:6px;">¿Qué % cobra en efectivo?</div>
                                <div class="cobro-badges">
                                    <span class="badge-ef" id="badge-ef">70% efectivo</span>
                                    <span class="badge-dg" id="badge-dg">30% digital</span>
                                </div>
                            </div>
                        </div>
                        <input type="range" min="0" max="100" value="70" class="cobro-slider"
                               id="slider-cobro" oninput="updateCobro(this.value)">
                        <div class="cobro-labels">
                            <span>0% efectivo<br>(todo digital)</span>
                            <span style="text-align:center;">50/50</span>
                            <span style="text-align:right;">100% efectivo<br>(todo contado)</span>
                        </div>
                        <input type="hidden" name="cv_porcentaje_efectivo" id="hid-pct-ef" value="70">
                        <input type="hidden" name="cv_porcentaje_digital"  id="hid-pct-dg" value="30">
                    </div>

                </div>
            </div>


            <!-- ══════════════════════════════════════
                 PASO 3 — PRODUCTOS COMERCIALIZADOS
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="2">
                <div class="form-card">
                    <h3><i class="fas fa-boxes-stacked"></i>Productos que comercializa</h3>
                    <p class="sub">Ingresa los principales productos o servicios que vende la empresa.</p>

                    <div class="prod-list" id="prod-list">
                        <!-- se llena con JS -->
                    </div>
                    <button type="button" class="btn-add-prod" id="btn-add-prod">
                        <i class="fas fa-plus"></i> Agregar producto
                    </button>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 4 — CIERRE Y OBSERVACIONES
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="3">
                <div class="form-card">
                    <h3><i class="fas fa-bank"></i>Productos financieros actuales</h3>
                    <p class="sub">¿Qué productos o servicios financieros ya tiene la empresa?</p>
                    
                    <div class="fld-grid">
                        <!-- Cuenta de ahorros -->
                        <div class="fld">
                            <label class="checkbox-label">
                                <input type="checkbox" name="mantiene_cuenta_ahorros" value="1">
                                <span>Mantiene cuenta de ahorros</span>
                            </label>
                        </div>
                        
                        <!-- Cuenta corriente -->
                        <div class="fld">
                            <label class="checkbox-label">
                                <input type="checkbox" name="mantiene_cuenta_corriente" value="1">
                                <span>Mantiene cuenta corriente</span>
                            </label>
                        </div>
                        
                        <!-- Tiene inversiones -->
                        <div class="fld">
                            <label class="checkbox-label">
                                <input type="checkbox" name="tiene_inversiones" value="1">
                                <span>Tiene inversiones / CDP</span>
                            </label>
                        </div>
                        
                        <!-- Institución de inversiones -->
                        <div class="fld" id="fld-institucion-inv" style="display:none;">
                            <label>¿En qué institución?</label>
                            <input type="text" name="institucion_inversiones" placeholder="Nombre del banco o institución">
                        </div>
                        
                        <!-- Monto inversión -->
                        <div class="fld" id="fld-monto-inv" style="display:none;">
                            <label>Monto aproximado (USD)</label>
                            <input type="number" step="0.01" min="0" name="valor_inversion" placeholder="0.00">
                        </div>
                        
                        <!-- Plazo inversión -->
                        <div class="fld" id="fld-plazo-inv" style="display:none;">
                            <label>Plazo</label>
                            <input type="text" name="plazo_inversion" placeholder="ej: 6 meses, 1 año">
                        </div>
                        
                        <!-- Tiene crédito operaciones -->
                        <div class="fld">
                            <label class="checkbox-label">
                                <input type="checkbox" name="tiene_operaciones_crediticias" value="1">
                                <span>Tiene líneas de crédito activas</span>
                            </label>
                        </div>
                        
                        <!-- Institución crédito -->
                        <div class="fld" id="fld-institucion-cred" style="display:none;">
                            <label>¿En qué institución?</label>
                            <input type="text" name="institucion_credito" placeholder="Nombre del banco o institución">
                        </div>
                    </div>

                    <hr style="margin:24px 0;border:none;border-top:1px solid var(--brand-border);">
                    
                    <h3><i class="fas fa-handshake"></i>Cierre del levantamiento</h3>
                    <p class="sub">Indica el interés detectado y el siguiente paso con la empresa.</p>
                    <div class="fld-grid">
                        <div class="fld">
                            <label>Nivel de interés captado</label>
                            <select name="nivel_interes">
                                <option value="">—</option>
                                <option value="ninguno">Ninguno</option>
                                <option value="bajo">Bajo</option>
                                <option value="alto">Alto</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Producto(s) de interés</label>
                            <select name="producto_interes">
                                <option value="">—</option>
                                <option value="credito">Crédito empresarial</option>
                                <option value="cuenta_corriente">Cuenta corriente</option>
                                <option value="ahorro">Ahorro empresarial</option>
                                <option value="inversion">Inversión / CDP</option>
                                <option value="payroll">Nómina / pago empleados</option>
                                <option value="varios">Varios</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Monto de crédito requerido (USD)</label>
                            <input type="number" step="0.01" min="0" name="monto_credito_requerido"
                                   placeholder="0.00">
                        </div>
                        <div class="fld">
                            <label>Acuerdo logrado</label>
                            <select name="acuerdo_logrado">
                                <option value="">—</option>
                                <option value="nueva_cita_campo">Nueva cita en campo</option>
                                <option value="nueva_cita_oficina">Nueva cita en oficina</option>
                                <option value="reprogramacion">Reprogramación</option>
                                <option value="sin_interes">Sin interés por ahora</option>
                                <option value="solicitud_credito">Solicitud de crédito</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Fecha próximo contacto</label>
                            <input type="date" name="fecha_nuevo_contacto">
                        </div>
                        <div class="fld">
                            <label>Hora</label>
                            <input type="time" name="hora_contacto">
                        </div>
                        <div class="fld full">
                            <label>Observaciones generales</label>
                            <textarea name="observaciones" rows="4"
                                      placeholder="Anota todo lo relevante sobre la empresa, el propietario y la visita…"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="form-footer">
                <button type="button" class="btn btn-ghost" id="btn-prev">
                    <i class="fas fa-arrow-left"></i> Anterior
                </button>
                <div style="font-size:13px;color:var(--brand-gray);">
                    Paso <span id="step-num">1</span> de 4
                </div>
                <div>
                    <button type="button" class="btn btn-yellow" id="btn-next">
                        Siguiente <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="btn btn-primary" id="btn-save" style="display:none;">
                        <i class="fas fa-circle-check"></i> Guardar levantamiento
                    </button>
                </div>
            </div>
        </form>

    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════
   BÚSQUEDA POR NOMBRE CON DROPDOWN DINÁMICO
══════════════════════════════════════════════════════ */
const btnBuscar     = document.getElementById('btn-buscar');
const inpNombre     = document.getElementById('inp-nombre');
const searchRes     = document.getElementById('search-result');
const stepper       = document.getElementById('stepper');
const formEmp       = document.getElementById('formEmpresa');
const dropdown      = document.getElementById('empresa-dropdown');
const dropdownList  = document.getElementById('empresa-list');

let searchTimeout;

btnBuscar.addEventListener('click', buscarPorBoton);
inpNombre.addEventListener('input', debounceSearch);
inpNombre.addEventListener('focus', () => {
    const val = inpNombre.value.trim();
    if (val.length >= 2 && dropdownList.innerHTML) {
        dropdown.style.display = 'block';
    }
});
inpNombre.addEventListener('blur', () => {
    setTimeout(() => {
        dropdown.style.display = 'none';
    }, 200);
});

function debounceSearch() {
    clearTimeout(searchTimeout);
    const q = inpNombre.value.trim();
    if (q.length >= 2) {
        searchTimeout = setTimeout(() => buscarEmpresaDinamico(q), 600);
    } else {
        dropdown.style.display = 'none';
    }
}

async function buscarEmpresaDinamico(q) {
    try {
        const fd = new FormData();
        fd.append('nombre', q);
        fd.append('limit', 10);
        const res = await fetch('../buscar_prospecto_por_nombre.php', { method:'POST', body:fd });
        const data = await res.json();

        if (data.resultados && data.resultados.length > 0) {
            // Construir dropdown
            dropdownList.innerHTML = '';
            data.resultados.forEach(p => {
                const item = document.createElement('div');
                item.style.padding = '12px 14px';
                item.style.borderBottom = '1px solid var(--brand-border)';
                item.style.cursor = 'pointer';
                item.style.transition = '.2s';
                item.innerHTML = `
                    <div style="font-weight:700;color:var(--brand-navy-deep);font-size:14px;">${escHtml(p.nombre_empresa || p.nombre || 'Sin nombre')}</div>
                    <div style="font-size:12px;color:var(--brand-gray);margin-top:2px;">${escHtml(p.nombre || '')} • ${escHtml(p.cedula || '')} • ${escHtml(p.ciudad || '')}</div>
                `;
                item.addEventListener('mouseenter', () => {
                    item.style.background = 'var(--brand-bg)';
                });
                item.addEventListener('mouseleave', () => {
                    item.style.background = 'transparent';
                });
                item.addEventListener('click', () => {
                    seleccionarProspecto(p);
                });
                dropdownList.appendChild(item);
            });
            dropdown.style.display = 'block';
        } else {
            dropdownList.innerHTML = '<div style="padding:12px 14px;color:var(--brand-gray);font-size:13px;"><i class="fas fa-inbox"></i> No hay resultados</div>';
            dropdown.style.display = 'block';
        }
    } catch(err) {
        console.error('Error en búsqueda:', err);
        dropdown.style.display = 'none';
    }
}

async function buscarPorBoton() {
    const q = inpNombre.value.trim();
    if (!q || q.length < 2) { alert('Ingresa al menos 2 caracteres para buscar.'); return; }
    btnBuscar.disabled = true;
    btnBuscar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando…';

    try {
        const fd = new FormData();
        fd.append('nombre', q);
        const res  = await fetch('../buscar_prospecto_por_nombre.php', { method:'POST', body:fd });
        const data = await res.json();

        if (!data.resultados || data.resultados.length === 0) {
            searchRes.innerHTML = `
                <p class="no-results"><i class="fas fa-magnifying-glass"></i> No se encontraron prospectos con ese nombre.<br>
                <small>Puedes continuar igualmente llenando los datos de la empresa.</small></p>`;
            searchRes.style.display = 'block';
            // Mostrar form vacío
            stepper.style.display = 'flex';
            formEmp.style.display = 'block';
            show(0);
        } else {
            // Mostrar lista
            let html = '<ul class="prosp-list">';
            data.resultados.forEach(p => {
                html += `<li class="prosp-item" onclick="seleccionarProspecto(${JSON.stringify(p).replace(/"/g,'&quot;')})">
                    <div>
                        <div class="pi-name">${escHtml(p.nombre || '')}</div>
                        <div class="pi-meta">${escHtml(p.nombre_empresa || '—')} · ${escHtml(p.ciudad || '')} · ${escHtml(p.cedula || '')}</div>
                    </div>
                    <button type="button" class="pi-btn">Seleccionar</button>
                </li>`;
            });
            html += '</ul>';
            searchRes.innerHTML = html;
            searchRes.style.display = 'block';
            dropdown.style.display = 'none';
        }
    } catch (err) {
        searchRes.innerHTML = '<div class="alert alert-danger mt-2" style="font-size:13px;">Error al buscar. Inténtalo de nuevo.</div>';
        searchRes.style.display = 'block';
    } finally {
        btnBuscar.disabled = false;
        btnBuscar.innerHTML = '<i class="fas fa-search"></i> Buscar';
    }
}

function seleccionarProspecto(p) {
    // Llenar campos
    fillField('f-nombre_empresa',    p.nombre_empresa || '');
    fillField('f-nombre_propietario',p.nombre || '');
    fillField('f-cedula_ruc',        p.cedula || '');
    fillField('f-telefono_empresa',  p.celular || p.telefono || '');
    fillField('f-ciudad',            p.ciudad || '');
    fillField('f-zona',              p.zona || '');
    document.getElementById('hid-cliente_id').value = p.id || '';

    // Banner
    const banner = document.getElementById('info-cargado');
    document.getElementById('info-cargado-texto').textContent =
        `Prospecto cargado: ${p.nombre || ''}. Completa los datos de la empresa.`;
    banner.style.display = 'flex';

    // Mostrar resultado
    searchRes.innerHTML = `<div class="found-chip found"><i class="fas fa-circle-check"></i> Seleccionado: <strong>${escHtml(p.nombre||'')}</strong></div>`;

    // Mostrar formulario
    stepper.style.display = 'flex';
    formEmp.style.display = 'block';
    show(0);
    stepper.scrollIntoView({behavior:'smooth', block:'start'});
}

function fillField(id, val) {
    const el = document.getElementById(id);
    if (el && val) el.value = val;
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ══════════════════════════════════════════════════════
   STEPPER
══════════════════════════════════════════════════════ */
const panes   = document.querySelectorAll('.step-pane');
const stepEls = document.querySelectorAll('.step');
let cur = 0;

function show(i) {
    cur = Math.max(0, Math.min(panes.length - 1, i));
    panes.forEach((p,idx) => p.classList.toggle('active', idx === cur));
    stepEls.forEach((s,idx) => {
        s.classList.toggle('active', idx === cur);
        s.classList.toggle('done',   idx < cur);
    });
    document.getElementById('step-num').textContent = cur + 1;
    document.getElementById('btn-prev').style.visibility = cur === 0 ? 'hidden' : 'visible';
    const isLast = cur === panes.length - 1;
    document.getElementById('btn-next').style.display = isLast ? 'none'        : 'inline-flex';
    document.getElementById('btn-save').style.display = isLast ? 'inline-flex' : 'none';
    window.scrollTo({top:0, behavior:'smooth'});
}
document.getElementById('btn-prev').onclick = () => show(cur - 1);
document.getElementById('btn-next').onclick = () => show(cur + 1);
stepEls.forEach((s,idx) => s.addEventListener('click', () => show(idx)));

/* ══ VENTAS/COMPRAS POR DÍA — cálculo automático ══ */
var VDAY_IDS = ['lunes','martes','miercoles','jueves','viernes','sabado','domingo'];

function calcResumen(){
    var vSem = 0, cSem = 0;
    VDAY_IDS.forEach(function(d){
        var vi = document.getElementById('vd-'+d);
        var ci = document.getElementById('cd-'+d);
        vSem += vi ? (parseFloat(vi.value)||0) : 0;
        cSem += ci ? (parseFloat(ci.value)||0) : 0;
    });
    var vMes = vSem * 4.33;
    var cMes = cSem * 4.33;
    var fmt = function(n){ return '$ ' + n.toFixed(2); };
    document.getElementById('rv-venta-sem').textContent  = fmt(vSem);
    document.getElementById('rv-compra-sem').textContent = fmt(cSem);
    document.getElementById('rv-venta-mes').textContent  = fmt(vMes);
    document.getElementById('rv-compra-mes').textContent = fmt(cMes);
    document.getElementById('hid-venta-sem').value  = vSem.toFixed(2);
    document.getElementById('hid-compra-sem').value = cSem.toFixed(2);
    document.getElementById('hid-venta-mes').value  = vMes.toFixed(2);
    document.getElementById('hid-compra-mes').value = cMes.toFixed(2);
}

/* ══ DÍAS DE ATENCIÓN — chip toggle ══ */
function toggleDia(el){
    el.classList.toggle('on');
    var chk = el.querySelector('.dia-chk');
    if(chk) chk.checked = el.classList.contains('on');
}

/* ══ COBRO SLIDER ══ */
function updateCobro(val){
    val = parseInt(val,10);
    var dig = 100 - val;
    document.getElementById('badge-ef').textContent = val + '% efectivo';
    document.getElementById('badge-dg').textContent = dig + '% digital';
    document.getElementById('hid-pct-ef').value = val;
    document.getElementById('hid-pct-dg').value = dig;
}

/* ══════════════════════════════════════════════════════
   YN TOGGLE
══════════════════════════════════════════════════════ */
document.addEventListener('click', function(e){
    var o = e.target.closest('.yn-opt');
    if(!o) return;
    var g = o.closest('.yn-group');
    if(!g) return;
    g.querySelectorAll('.yn-opt').forEach(function(x){ x.classList.remove('checked'); });
    o.classList.add('checked');
    var inp = o.querySelector('input');
    if(inp) inp.checked = true;
});

/* ══════════════════════════════════════════════════════
   BÚSQUEDA DE PROSPECTO POR NOMBRE
══════════════════════════════════════════════════════ */
function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
function fill(id,v){ var el=document.getElementById(id); if(el&&v) el.value=v; }
function setVal(id,v){ var el=document.getElementById(id); if(el) el.value=v; }

const btnBuscarLev  = document.getElementById('btn-buscar');
const inpNombreLev  = document.getElementById('inp-nombre');
const searchResLev  = document.getElementById('search-result');

if(btnBuscarLev){
    btnBuscarLev.addEventListener('click', buscarProspecto);
    inpNombreLev.addEventListener('keydown', function(e){ if(e.key==='Enter') buscarProspecto(); });
}

async function buscarProspecto(){
    var nom = inpNombreLev ? inpNombreLev.value.trim() : '';
    if(nom.length < 2){ return; }
    if(btnBuscarLev){ btnBuscarLev.disabled=true; btnBuscarLev.innerHTML='<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        var fd = new FormData();
        fd.append('nombre', nom);
        var res = await fetch('../buscar_prospecto_por_nombre.php', {method:'POST', body:fd});
        var data = await res.json();
        if(data.status==='found' && data.resultados && data.resultados.length>0){
            var html = '<div style="background:#fff;border:1px solid var(--brand-border);border-radius:10px;overflow:hidden;">';
            data.resultados.forEach(function(p){
                html += '<div class="prosp-result-row" onclick="seleccionarProspecto('+JSON.stringify(p)+')">'
                    + '<strong>'+esc(p.nombre)+'</strong>'
                    + (p.nombre_empresa ? ' &mdash; '+esc(p.nombre_empresa) : '')
                    + ' <small style="color:#9ca3af;">'+esc(p.cedula)+'</small>'
                    + '</div>';
            });
            html += '</div>';
            if(searchResLev) searchResLev.innerHTML = html;
        } else {
            if(searchResLev) searchResLev.innerHTML = '<div style="padding:10px;color:#9ca3af;">Sin resultados</div>';
        }
    } catch(err){
        if(searchResLev) searchResLev.innerHTML = '<div style="color:red;">Error de conexión</div>';
    } finally {
        if(btnBuscarLev){ btnBuscarLev.disabled=false; btnBuscarLev.innerHTML='<i class="fas fa-search"></i> Buscar'; }
    }
}

function seleccionarProspecto(p){
    fill('f-nombre_empresa',  p.nombre_empresa || '');
    fill('f-nombre_propietario', p.nombre || '');
    fill('f-cedula_ruc',      p.cedula || '');
    fill('f-telefono',        p.telefono || '');
    fill('f-email',           p.email || '');
    fill('f-ciudad',          p.ciudad || '');
    setVal('hid-cliente_id',  p.id || '');
    if(searchResLev) searchResLev.innerHTML = '<div style="padding:8px 12px;background:#d1fae5;border-radius:8px;color:#065f46;font-size:.88rem;"><i class="fas fa-check-circle"></i> '+esc(p.nombre)+' seleccionado</div>';
}

</script>
</body>
</html>

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
                    <h3><i class="fas fa-chart-bar"></i>Comportamiento de ventas</h3>
                    <p class="sub">Información sobre el flujo de ventas y compras de la empresa.</p>
                    <div class="fld-grid">
                        <div class="fld">
                            <label>Día(s) de mayor venta</label>
                            <select name="cv_dia_semana">
                                <option value="">— Selecciona —</option>
                                <?php foreach (['lunes','martes','miercoles','jueves','viernes','sabado','domingo'] as $d): ?>
                                    <option value="<?= $d ?>"><?= ucfirst(str_replace(['miercoles','sabado'],['miércoles','sábado'],$d)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Calificación de ventas</label>
                            <select name="cv_calificacion">
                                <option value="">—</option>
                                <option value="bueno">Bueno</option>
                                <option value="regular">Regular</option>
                                <option value="malo">Malo</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Venta diaria promedio (USD)</label>
                            <input type="number" step="0.01" min="0" name="cv_valor_venta"
                                   placeholder="0.00">
                        </div>
                        <div class="fld">
                            <label>Compra diaria promedio (USD)</label>
                            <input type="number" step="0.01" min="0" name="cv_valor_compra"
                                   placeholder="0.00">
                        </div>
                        <div class="fld">
                            <label>Venta mensual promedio (USD)</label>
                            <input type="number" step="0.01" min="0" name="cv_venta_promedio_mes"
                                   placeholder="0.00">
                        </div>
                        <div class="fld">
                            <label>Compra mensual promedio (USD)</label>
                            <input type="number" step="0.01" min="0" name="cv_compra_promedio_mes"
                                   placeholder="0.00">
                        </div>
                        <div class="fld">
                            <label>% Ventas al contado</label>
                            <input type="number" min="0" max="100" name="cv_porcentaje_contado"
                                   value="100" placeholder="100">
                        </div>
                        <div class="fld">
                            <label>% Ventas a crédito</label>
                            <input type="number" min="0" max="100" name="cv_porcentaje_credito"
                                   value="0" placeholder="0">
                        </div>
                        <div class="fld">
                            <label>Días de atención por semana</label>
                            <input type="number" min="0" max="7" name="cv_dias_atencion"
                                   placeholder="Ej: 6">
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-hand-holding-dollar"></i>Situación financiera actual</h5>
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

/* ══════════════════════════════════════════════════════
   YN TOGGLE
══════════════════════════════════════════════════════ */
document.querySelectorAll('.yn-group').forEach(g => {
    g.querySelectorAll('.yn-opt').forEach(o => {
        o.addEventListener('click', () => {
            g.querySelectorAll('.yn-opt').forEach(x => x.classList.remove('checked'));
            o.classList.add('checked');
            o.querySelector('input').checked = true;
        });
    });
});

/* ══════════════════════════════════════════════════════
   PRODUCTOS REPEATER
══════════════════════════════════════════════════════ */
const prodList = document.getElementById('prod-list');
let prodIdx = 0;
function addProducto() {
    const i = prodIdx++;
    const div = document.createElement('div');
    div.className = 'prod-item';
    div.innerHTML = `
        <button type="button" class="del-prod" onclick="this.closest('.prod-item').remove()">× Quitar</button>
        <div class="fld"><label>Nombre del producto / servicio</label>
            <input type="text" name="prod[${i}][nombre]" placeholder="Ej: Arroz 50 kg" required></div>
        <div class="fld"><label>Precio de venta (USD)</label>
            <input type="number" step="0.01" min="0" name="prod[${i}][precio_venta]" placeholder="0.00"></div>
        <div class="fld"><label>Costo unitario (USD)</label>
            <input type="number" step="0.01" min="0" name="prod[${i}][costo]" placeholder="0.00"></div>
        <div class="fld"><label>Unidades vendidas / mes</label>
            <input type="number" min="0" name="prod[${i}][cantidad]" placeholder="0"></div>
        <div class="fld"><label>% Margen aprox.</label>
            <input type="number" step="0.1" min="0" max="100" name="prod[${i}][margen]" placeholder="0"></div>
        <div class="fld"><label>Total ventas / mes (USD)</label>
            <input type="number" step="0.01" min="0" name="prod[${i}][total_venta_mes]" placeholder="0.00"></div>
        <div class="fld"><label>Inventario disponible</label>
            <input type="number" min="0" name="prod[${i}][inventario]" placeholder="0"></div>
        <div class="fld"><label>Compra promedio semanal (USD)</label>
            <input type="number" step="0.01" min="0" name="prod[${i}][compra_sem]" placeholder="0.00"></div>
    `;
    prodList.appendChild(div);
}
document.getElementById('btn-add-prod').addEventListener('click', addProducto);
addProducto(); // uno por defecto

/* ══════════════════════════════════════════════════════
   GEO
══════════════════════════════════════════════════════ */
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        p => {
            document.getElementById('lat').value = p.coords.latitude;
            document.getElementById('lng').value = p.coords.longitude;
        },
        () => {}, { timeout: 5000 }
    );
}
</script>
</body>
</html>

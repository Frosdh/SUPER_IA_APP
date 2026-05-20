<?php
// ============================================================
// levantamiento_empresa.php  —  v2026-05-20
// ------------------------------------------------------------
// Formulario de Levantamiento de Empresa (Negocio) para Asesores.
// ============================================================
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

$currentPage = 'levantamiento';
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
    --brand-shadow-md:0 10px 25px rgba(18,58,109,.1);
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Inter','Segoe UI',sans-serif;background:var(--brand-bg);display:flex;min-height:100vh;color:var(--brand-navy-deep);}

/* SIDEBAR */
.sidebar{width:230px;background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%);color:#fff;padding:20px 0;overflow-y:auto;position:fixed;height:100vh;left:0;top:0;z-index:100;}
.sidebar-brand{padding:0 20px 24px;font-size:18px;font-weight:800;border-bottom:1px solid rgba(255,221,0,.18);margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.sidebar-brand i{color:var(--brand-yellow);}
.sidebar-section{padding:0 15px;margin-bottom:22px;}
.sidebar-section-title{font-size:11px;text-transform:uppercase;color:rgba(255,255,255,.5);letter-spacing:.6px;padding:0 10px;margin-bottom:10px;font-weight:700;}
.sidebar-link{display:flex;align-items:center;gap:12px;padding:11px 15px;margin-bottom:4px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:14px;border:1px solid transparent;transition:.22s;}
.sidebar-link:hover{background:rgba(255,221,0,.12);color:#fff;padding-left:20px;border-color:rgba(255,221,0,.15);}
.sidebar-link.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);font-weight:700;}
.badge-nav{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:auto;}

/* LAYOUT */
.main-content{flex:1;margin-left:230px;display:flex;flex-direction:column;min-width:0;}
.navbar-custom{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 28px rgba(18,58,109,.18);position:sticky;top:0;z-index:50;}
.navbar-custom h2{margin:0;font-size:19px;font-weight:700;display:flex;align-items:center;gap:10px;}
.navbar-custom h2 i{color:var(--brand-yellow);}
.user-info{display:flex;align-items:center;gap:14px;font-size:13px;}
.btn-logout{background:rgba(255,221,0,.15);color:#fff;border:1px solid rgba(255,221,0,.28);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;}
.content-area{flex:1;padding:24px 30px 60px;}

/* SEARCH CONTAINER */
.search-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:24px;box-shadow:var(--brand-shadow-sm);margin-bottom:20px;}
.search-card h3{font-size:16px;font-weight:800;margin-bottom:6px;display:flex;align-items:center;gap:9px;color:var(--brand-navy-deep);}
.search-card h3 i{color:var(--brand-yellow-deep);}
.search-card .sub{color:var(--brand-gray);font-size:13px;margin-bottom:16px;}
.search-row{display:flex;gap:10px;}
.search-row input{flex:1;padding:12px 16px;border:2px solid var(--brand-border);border-radius:12px;font-size:15px;transition:.2s;}
.search-row input:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.btn-search{background:var(--brand-yellow);color:var(--brand-navy-deep);border:none;border-radius:12px;padding:12px 22px;font-weight:800;font-size:14px;cursor:pointer;display:flex;align-items:center;gap:8px;}
.btn-search:hover{background:var(--brand-yellow-deep);}

/* PROSPECT CARDS */
.prospects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:16px;}
.prospect-card{background:#fff;border:1.5px solid var(--brand-border);border-radius:14px;padding:18px;box-shadow:var(--brand-shadow-sm);transition:all .2s;position:relative;}
.prospect-card:hover{transform:translateY(-2px);box-shadow:var(--brand-shadow-md);border-color:var(--brand-navy);}
.prospect-card .pc-title{font-size:15px;font-weight:800;color:var(--brand-navy-deep);margin-bottom:6px;display:flex;align-items:center;gap:8px;}
.prospect-card .pc-company{font-size:13px;color:var(--brand-gray);margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.prospect-card .pc-info{font-size:12.5px;margin-bottom:6px;}
.prospect-card .pc-info span{font-weight:600;}
.prospect-card .pc-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;margin-top:8px;}
.prospect-card .pc-badge.completed{background:#d1fae5;color:#065f46;}
.prospect-card .pc-badge.pending{background:#fee2e2;color:#991b1b;}
.prospect-card .pc-action{margin-top:14px;display:flex;justify-content:flex-end;}
.btn-card-action{background:var(--brand-navy);color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;transition:.15s;}
.btn-card-action:hover{background:var(--brand-navy-deep);}

/* STEPPER */
.stepper{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:16px 20px;margin-bottom:18px;box-shadow:var(--brand-shadow-sm);overflow-x:auto;gap:10px;}
.step{display:flex;align-items:center;gap:8px;flex:1;min-width:110px;cursor:pointer;}
.step .num{width:32px;height:32px;border-radius:50%;background:#e5e7eb;color:#6b7280;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;transition:.2s;}
.step .lbl{font-size:11px;color:var(--brand-gray);font-weight:700;line-height:1.2;}
.step.active .num{background:var(--brand-yellow);color:var(--brand-navy-deep);box-shadow:0 4px 10px rgba(255,221,0,.45);}
.step.active .lbl{color:var(--brand-navy-deep);font-weight:800;}
.step.done .num{background:#10b981;color:#fff;}
.step.done .lbl{color:#065f46;}
.step .line{flex:1;height:2px;background:#e5e7eb;margin:0 4px;}
.step:last-child .line{display:none;}

/* FORM SYSTEM */
.form-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:24px;box-shadow:var(--brand-shadow-sm);margin-bottom:20px;}
.form-card h3{font-size:17px;font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:10px;color:var(--brand-navy-deep);}
.form-card h3 i{color:var(--brand-yellow-deep);}
.form-card .sub{color:var(--brand-gray);font-size:13.5px;margin-bottom:18px;}

/* FIELDS */
.fld-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px 18px;}
.fld{display:flex;flex-direction:column;gap:5px;}
.fld label{font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;}
.fld input,.fld select,.fld textarea{padding:10px 12px;border:1.5px solid var(--brand-border);border-radius:10px;font-size:14px;font-family:inherit;background:#fff;transition:.2s;}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.fld.full{grid-column:1/-1;}

/* TABLES */
.table-responsive{margin-top:14px;border:1px solid var(--brand-border);border-radius:12px;overflow:hidden;}
.table-custom{width:100%;border-collapse:collapse;margin:0;font-size:13.5px;}
.table-custom th{background:var(--brand-navy-deep);color:#fff;padding:12px 14px;font-weight:700;text-align:left;}
.table-custom td{padding:10px 14px;border-bottom:1px solid var(--brand-border);background:#fff;vertical-align:middle;}
.table-custom tr:last-child td{border-bottom:none;}
.table-custom input{width:100%;padding:6px 10px;border:1.5px solid var(--brand-border);border-radius:6px;font-size:13px;}
.table-custom input:focus{border-color:var(--brand-yellow-deep);outline:none;}
.btn-row-del{background:#fee2e2;color:#ef4444;border:none;width:32px;height:32px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;}
.btn-row-del:hover{background:#fecaca;}
.btn-table-add{background:#eff6ff;color:#2563eb;border:1.5px dashed #bfdbfe;border-radius:10px;padding:10px;width:100%;font-weight:700;font-size:13.5px;cursor:pointer;margin-top:12px;display:flex;align-items:center;justify-content:center;gap:6px;transition:.15s;}
.btn-table-add:hover{background:#dbeafe;border-color:#3b82f6;}

/* COMPORTAMIENTO DIARIO CHIPS */
.days-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.day-chip{padding:8px 14px;border-radius:20px;border:1.5px solid var(--brand-border);background:#fff;font-size:12.5px;font-weight:700;cursor:pointer;user-select:none;transition:.15s;display:flex;align-items:center;gap:6px;}
.day-chip.checked{background:var(--brand-navy);border-color:var(--brand-navy);color:#fff;}
.day-chip input{display:none;}

/* SUMMARY CARDS */
.flow-summary-card{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-radius:16px;padding:24px;box-shadow:var(--brand-shadow-sm);margin-bottom:20px;}
.flow-summary-card h4{font-weight:800;font-size:16px;border-bottom:1.5px dashed rgba(255,255,255,.2);padding-bottom:12px;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.flow-summary-card h4 i{color:var(--brand-yellow);}
.sum-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
.sum-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px;}
.sum-box .sb-title{font-size:10px;text-transform:uppercase;color:rgba(255,255,255,.6);font-weight:800;letter-spacing:.5px;}
.sum-box .sb-val{font-size:20px;font-weight:800;margin-top:4px;}
.sum-box.highlight{background:linear-gradient(135deg,rgba(255,221,0,.15),rgba(255,221,0,.05));border-color:rgba(255,221,0,.3);}
.sum-box.highlight .sb-title{color:var(--brand-yellow);font-weight:800;}
.sum-box.highlight .sb-val{color:var(--brand-yellow);font-size:24px;}

/* YES/NO BUTTONS */
.yn-group{display:flex;gap:6px;}
.yn-opt{flex:1;padding:10px;text-align:center;border:1.5px solid var(--brand-border);border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;background:#fff;color:#374151;transition:.2s;}
.yn-opt:hover{background:#f3f4f6;}
.yn-opt input{display:none;}
.yn-opt.checked{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-color:transparent;}

/* FOOTER */
.form-footer{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:14px 18px;box-shadow:var(--brand-shadow-sm);position:sticky;bottom:14px;z-index:30;}
.btn-footer{padding:11px 22px;border-radius:11px;font-weight:800;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none;transition:.15s;}
.btn-yellow{background:var(--brand-yellow);color:var(--brand-navy-deep);}
.btn-yellow:hover{background:var(--brand-yellow-deep);transform:translateY(-1px);}
.btn-ghost{background:#f3f4f6;color:#374151;}
...
.btn-ghost:hover{background:#e5e7eb;}
.btn-navy{background:var(--brand-navy);color:#fff;}
.btn-navy:hover{background:var(--brand-navy-deep);}

.step-pane{display:none;}
.step-pane.active{display:block;animation:fadein .22s ease;}
@keyframes fadein{from{opacity:0;transform:translateY(5px);}to{opacity:1;transform:none;}}

/* BANNER */
.alert-banner{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;margin-bottom:18px;font-size:.95rem;font-weight:600;}
.alert-ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.alert-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

/* RESPONSIVE */
@media(max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .main-content{margin-left:0;}
    .content-area{padding:14px;}
    .stepper{padding:10px;}
    .step .lbl{display:none;}
    .form-card{padding:16px;}
}

/* RÉGIMEN TILES */
.regimen-tiles{display:flex;flex-direction:column;gap:10px;margin-top:12px;}
.regimen-tile{display:flex;align-items:center;gap:12px;border:1.5px solid var(--brand-border);border-radius:12px;padding:14px;cursor:pointer;background:#fff;transition:.18s;}
.regimen-tile .rt-left{width:44px;height:44px;border-radius:10px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:18px;color:#374151;flex-shrink:0;}
.regimen-tile .rt-body{flex:1;}
.regimen-tile .rt-title{font-weight:800;color:var(--brand-navy-deep);font-size:14px;}
.regimen-tile .rt-sub{font-size:12px;color:var(--brand-gray);}
.regimen-tile.selected{border-color:var(--brand-yellow-deep);background:linear-gradient(90deg,#fff8e6,#fffaf0);}
.regimen-tile.selected .rt-left{background:var(--brand-yellow);color:var(--brand-navy-deep);}

/* Q-CARDS (preguntas RUC / RISE) */
.q-cards{display:flex;flex-direction:column;gap:12px;margin-top:14px;padding:14px;background:#f8fafc;border:1px dashed #d7e0ea;border-radius:12px;}
.q-card{background:#fff;border:1px solid var(--brand-border);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:8px;}
.q-label{font-weight:700;color:var(--brand-navy-deep);font-size:13.5px;}
.q-field input{width:100%;padding:10px 12px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:14px;font-family:inherit;}
.q-field input:focus{outline:none;border-color:var(--brand-yellow-deep);}
.q-actions{display:flex;gap:8px;flex-wrap:wrap;}
.q-btn{border:1.5px solid var(--brand-border);background:#fff;padding:8px 16px;border-radius:999px;cursor:pointer;font-weight:700;font-size:13px;color:#374151;transition:.15s;}
.q-btn:hover{background:#f3f4f6;}
.q-btn.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);border-color:transparent;}

/* SUB-SECTIONS */
.sub-sec{margin-top:20px;padding-top:16px;border-top:1px dashed #e5e7eb;}
.sub-sec h5{font-size:12px;text-transform:uppercase;color:var(--brand-navy);font-weight:800;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.sub-sec h5 i{color:var(--brand-yellow-deep);}
.extras{display:none;margin-top:12px;padding:14px;background:#f8fafc;border-radius:10px;border:1px dashed #d7e0ea;}
.extras.show{display:block;}
</style>
</head>
<body>

<?php require __DIR__ . '/_sidebar_asesor.php'; ?>

<div class="main-content">
    <!-- NAVBAR -->
    <div class="navbar-custom">
        <h2><i class="fas fa-building-user"></i> Levantamiento de Empresa</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($asesor_nombre) ?></strong></div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>

    <!-- MAIN BODY -->
    <div class="content-area">
        
        <!-- ALERT BANNER -->
        <div id="alert-zone" style="display:none;"></div>

        <!-- SEARCH SECTION -->
        <div class="search-card" id="section-search">
            <h3><i class="fas fa-magnifying-glass"></i> Buscar Prospecto por Nombre de Empresa</h3>
            <p class="sub">Busca al prospecto o cliente para registrar o actualizar su levantamiento financiero.</p>
            <div class="search-row">
                <input type="text" id="inp-search-empresa" placeholder="Escribe el nombre de la empresa...">
                <button class="btn-search" id="btn-buscar-emp" type="button">
                    <i class="fas fa-search"></i> Buscar
                </button>
            </div>
            
            <div class="prospects-grid" id="search-results-grid"></div>
        </div>

        <!-- FORM CONTAINER (WIZARD) -->
        <div id="section-wizard" style="display:none;">
            <!-- STEPPER -->
            <div class="stepper" id="stepper">
                <?php $steps = [
                    ['Datos del Cliente', 'fa-user-tie'],
                    ['Ventas / Compras', 'fa-cash-register'],
                    ['Inventario / Prod.', 'fa-boxes-packing'],
                    ['Gastos / Ingresos', 'fa-receipt'],
                    ['Activos Fijos',    'fa-truck-ramp-box'],
                    ['Balance Gral.',    'fa-scale-balanced'],
                    ['Flujo de Caja',    'fa-chart-line'],
                    ['Identificación',   'fa-id-card-clip'],
                ];
                foreach ($steps as $i => [$lbl, $ico]): ?>
                    <div class="step <?= $i===0?'active':'' ?>" data-step="<?= $i ?>" onclick="goToStep(<?= $i ?>)">
                        <div class="num"><i class="fas <?= $ico ?>" style="font-size:12px;"></i></div>
                        <div class="lbl"><?= $lbl ?></div>
                        <div class="line"></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- THE SURVEY FORM -->
            <form id="formLevantamiento" method="post" autocomplete="off">
                <!-- HIDDEN DATA -->
                <input type="hidden" name="cliente_id" id="hid-cliente-id">
                <input type="hidden" name="tarea_id"   id="hid-tarea-id">
                <input type="hidden" name="asesor_id"  value="<?= htmlspecialchars($asesor_table_id) ?>">
                <input type="hidden" name="usuario_id" value="<?= htmlspecialchars($asesor_usuario_id) ?>">
                <input type="hidden" name="tipo_tarea" value="levantamiento">
                <input type="hidden" name="fue_encuestado" value="1">
                
                <!-- JSON INPUTS -->
                <input type="hidden" name="productos_json"          id="hid-productos-json">
                <input type="hidden" name="comercio_productos_json" id="hid-comercio-productos-json">
                <input type="hidden" name="activos_negocio_json"    id="hid-activos-negocio-json">
                <input type="hidden" name="activos_hogar_json"      id="hid-activos-hogar-json">
                <input type="hidden" name="vehiculos_negocio_json"  id="hid-vehiculos-negocio-json">
                <input type="hidden" name="inmuebles_negocio_json"  id="hid-inmuebles-negocio-json">
                <input type="hidden" name="otras_deudas_json"       id="hid-otras-deudas-json">

                <!-- Dynamic Prefilled Info Banner -->
                <div class="info-banner" id="client-info-banner">
                    <i class="fas fa-circle-info"></i>
                    <div>
                        Levantamiento financiero para: <strong id="lbl-info-nombre">Cargando...</strong> | Empresa: <strong id="lbl-info-empresa">...</strong> | Cédula: <strong id="lbl-info-cedula">...</strong>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 0: DATOS DEL CLIENTE Y EMPRESA
                ═══════════════════════════════════════ -->
                <div class="step-pane active" data-pane="0">
                    <div class="form-card">
                        <h3><i class="fas fa-user-tie"></i> Datos personales y de la Empresa</h3>
                        <p class="sub">Verifica o completa la información del prospecto. Datos cargados desde la base. Puedes editarlos si es necesario.</p>
                        
                        <div class="fld-grid mb-4">
                            <div class="fld">
                                <label>Nombre completo *</label>
                                <input type="text" name="nombre" id="f-cp-nombre" required>
                            </div>
                            <div class="fld">
                                <label>Cédula *</label>
                                <input type="text" name="cedula" id="f-cp-cedula" required>
                            </div>
                            <div class="fld">
                                <label>Celular *</label>
                                <input type="text" name="celular" id="f-cp-celular" required>
                            </div>
                            <div class="fld">
                                <label>Teléfono convencional</label>
                                <input type="text" name="telefono" id="f-cp-telefono">
                            </div>
                            <div class="fld">
                                <label>Email</label>
                                <input type="email" name="email_cliente" id="f-cp-email">
                            </div>
                            <div class="fld">
                                <label>Dirección</label>
                                <input type="text" name="direccion" id="f-cp-direccion">
                            </div>
                            <div class="fld">
                                <label>Ciudad</label>
                                <input type="text" name="ciudad" id="f-cp-ciudad">
                            </div>
                            <div class="fld">
                                <label>Sector</label>
                                <input type="text" name="sector" id="f-cp-sector">
                            </div>
                        </div>

                        <div class="sub-sec">
                            <h5><i class="fas fa-file-invoice-dollar"></i> Régimen tributario</h5>
                            <p class="sub">Selecciona el régimen bajo el que opera el prospecto.</p>
                            
                            <div class="regimen-tiles">
                                <label class="regimen-tile" data-val="ruc" onclick="selectRegimen(this)">
                                    <input type="radio" name="regimen_type" value="ruc" style="display:none;">
                                    <div class="rt-left"><i class="fas fa-file-invoice"></i></div>
                                    <div class="rt-body">
                                        <div class="rt-title">RUC — Régimen general</div>
                                        <div class="rt-sub">Declara IVA, emite facturas electrónicas</div>
                                    </div>
                                </label>
                                <label class="regimen-tile" data-val="rise" onclick="selectRegimen(this)">
                                    <input type="radio" name="regimen_type" value="rise" style="display:none;">
                                    <div class="rt-left"><i class="fas fa-cube"></i></div>
                                    <div class="rt-body">
                                        <div class="rt-title">RISE — Régimen simplificado</div>
                                        <div class="rt-sub">Paga cuota fija, emite notas de venta</div>
                                    </div>
                                </label>
                                <label class="regimen-tile" data-val="none" onclick="selectRegimen(this)">
                                    <input type="radio" name="regimen_type" value="none" style="display:none;">
                                    <div class="rt-left"><i class="far fa-square"></i></div>
                                    <div class="rt-body">
                                        <div class="rt-title">No está registrado</div>
                                        <div class="rt-sub">Sin RUC ni RISE</div>
                                    </div>
                                </label>
                            </div>

                            <!-- campos compatibilidad backend -->
                            <input type="hidden" name="tiene_ruc"  id="hid-tiene_ruc"  value="0">
                            <input type="hidden" name="tiene_rise" id="hid-tiene_rise" value="0">
                            <input type="hidden" name="actividad"  id="hid-actividad"  value="negocio_propio">

                            <!-- ── PREGUNTAS RUC ── -->
                            <div id="q-ruc" class="q-cards" style="display:none;">
                                <div class="q-card">
                                    <div class="q-label">Número de RUC (opcional)</div>
                                    <div class="q-field"><input type="text" name="ruc_numero" id="f-ruc-numero" placeholder="1234567890001"></div>
                                </div>
                                <div class="q-card">
                                    <div class="q-label">¿Realiza declaraciones de IVA mensualmente?</div>
                                    <div class="q-actions">
                                        <button type="button" class="q-btn" data-hid="hid-ruc_declara_iva" data-val="1">Sí</button>
                                        <button type="button" class="q-btn" data-hid="hid-ruc_declara_iva" data-val="0">No</button>
                                    </div>
                                </div>
                                <div class="q-card">
                                    <div class="q-label">¿Emite facturas electrónicas?</div>
                                    <div class="q-actions">
                                        <button type="button" class="q-btn" data-hid="hid-ruc_emite_facturas" data-val="1">Sí</button>
                                        <button type="button" class="q-btn" data-hid="hid-ruc_emite_facturas" data-val="0">No</button>
                                    </div>
                                </div>
                                <div class="q-card">
                                    <div class="q-label">¿Lleva contabilidad?</div>
                                    <div class="q-actions">
                                        <button type="button" class="q-btn" data-hid="hid-ruc_lleva_contab" data-val="1">Sí</button>
                                        <button type="button" class="q-btn" data-hid="hid-ruc_lleva_contab" data-val="0">No</button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="declara_iva" id="hid-ruc_declara_iva">
                            <input type="hidden" name="emite_facturas" id="hid-ruc_emite_facturas">
                            <input type="hidden" name="lleva_contabilidad" id="hid-ruc_lleva_contab">

                            <!-- ── PREGUNTAS RISE ── -->
                            <div id="q-rise" class="q-cards" style="display:none;">
                                <div class="q-card">
                                    <div class="q-label">Número RISE (opcional)</div>
                                    <div class="q-field"><input type="text" name="rise_numero" id="f-rise-numero" placeholder="Número de comprobante"></div>
                                </div>
                                <div class="q-card">
                                    <div class="q-label">¿Paga su cuota mensual del RISE?</div>
                                    <div class="q-actions">
                                        <button type="button" class="q-btn" data-hid="hid-rise_paga_cuota" data-val="1">Sí</button>
                                        <button type="button" class="q-btn" data-hid="hid-rise_paga_cuota" data-val="0">No</button>
                                    </div>
                                </div>
                                <div class="q-card">
                                    <div class="q-label">¿Emite notas de venta?</div>
                                    <div class="q-actions">
                                        <button type="button" class="q-btn" data-hid="hid-rise_emite_notas" data-val="1">Sí</button>
                                        <button type="button" class="q-btn" data-hid="hid-rise_emite_notas" data-val="0">No</button>
                                    </div>
                                </div>
                                <div class="q-card">
                                    <div class="q-label">¿Conoce el límite de ingresos del RISE?</div>
                                    <div class="q-actions">
                                        <button type="button" class="q-btn" data-hid="hid-rise_conoce_limite" data-val="1">Sí</button>
                                        <button type="button" class="q-btn" data-hid="hid-rise_conoce_limite" data-val="0">No</button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="paga_cuota_rise" id="hid-rise_paga_cuota">
                            <input type="hidden" name="emite_notas_venta" id="hid-rise_emite_notas">
                            <input type="hidden" name="conoce_limite_rise" id="hid-rise_conoce_limite">
                        </div>

                        <div class="sub-sec">
                            <h5><i class="fas fa-shop"></i> ¿Tiene empresa?</h5>
                            <p class="sub">Indica si el prospecto tiene una empresa o negocio registrado.</p>
                            <div class="fld mb-3">
                                <div class="yn-group">
                                    <label class="yn-opt checked" id="opt-emp-si">
                                        <input type="radio" name="tiene_empresa" value="1" checked> Sí
                                    </label>
                                    <label class="yn-opt" id="opt-emp-no">
                                        <input type="radio" name="tiene_empresa" value="0"> No
                                    </label>
                                </div>
                            </div>

                            <!-- SI tiene empresa -->
                            <div id="extras-empresa" class="extras show">
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Nombre de la empresa / negocio *</label>
                                        <input type="text" name="nombre_empresa" id="f-nombre_empresa" placeholder="Razón social o nombre comercial">
                                    </div>
                                    <div class="fld">
                                        <label>Tipo de empresa *</label>
                                        <select name="tipo_empresa" id="f-tipo_empresa">
                                            <option value="">— Seleccione —</option>
                                            <option value="servicio_produccion">Servicio / Producción</option>
                                            <option value="comercio">Comercio</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- NO tiene empresa -->
                            <div id="aviso-sin-empresa" style="display:none;margin-top:12px;">
                                <div class="info-banner">
                                    <i class="fas fa-circle-info"></i>
                                    <span>El prospecto no cuenta con empresa registrada.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 1: VENTAS Y COMPRAS
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="1">
                    <div class="form-card">
                        <h3><i class="fas fa-cash-register"></i> Comportamiento de Ventas y Compras</h3>
                        <p class="sub">Define los días de atención y estima los ingresos y egresos diarios.</p>
                        
                        <div class="fld mb-3">
                            <label>Días de Atención (Selecciona los días activos)</label>
                            <div class="days-chips">
                                <label class="day-chip" id="lbl-dia-lun">
                                    <input type="checkbox" name="dias_atencion_lunes" id="chk-dia-lun" value="1" onchange="toggleDayChip(this)"> Lunes
                                </label>
                                <label class="day-chip" id="lbl-dia-mar">
                                    <input type="checkbox" name="dias_atencion_martes" id="chk-dia-mar" value="1" onchange="toggleDayChip(this)"> Martes
                                </label>
                                <label class="day-chip" id="lbl-dia-mie">
                                    <input type="checkbox" name="dias_atencion_miercoles" id="chk-dia-mie" value="1" onchange="toggleDayChip(this)"> Miércoles
                                </label>
                                <label class="day-chip" id="lbl-dia-jue">
                                    <input type="checkbox" name="dias_atencion_jueves" id="chk-dia-jue" value="1" onchange="toggleDayChip(this)"> Jueves
                                </label>
                                <label class="day-chip" id="lbl-dia-vie">
                                    <input type="checkbox" name="dias_atencion_viernes" id="chk-dia-vie" value="1" onchange="toggleDayChip(this)"> Viernes
                                </label>
                                <label class="day-chip" id="lbl-dia-sab">
                                    <input type="checkbox" name="dias_atencion_sab" id="chk-dia-sab" value="1" onchange="toggleDayChip(this)"> Sábado
                                </label>
                                <label class="day-chip" id="lbl-dia-dom">
                                    <input type="checkbox" name="dias_atencion_dom" id="chk-dia-dom" value="1" onchange="toggleDayChip(this)"> Domingo
                                </label>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Venta Diaria (Lun - Vie)</label>
                                        <input type="number" step="0.01" name="venta_lv" id="f-venta-lv" placeholder="0.00" oninput="calcVentasCompras()">
                                    </div>
                                    <div class="fld">
                                        <label>Venta Sábado</label>
                                        <input type="number" step="0.01" name="venta_sabado" id="f-venta-sab" placeholder="0.00" oninput="calcVentasCompras()">
                                    </div>
                                    <div class="fld">
                                        <label>Venta Domingo</label>
                                        <input type="number" step="0.01" name="venta_domingo" id="f-venta-dom" placeholder="0.00" oninput="calcVentasCompras()">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Compra Diaria (Lun - Vie)</label>
                                        <input type="number" step="0.01" name="compra_lv" id="f-compra-lv" placeholder="0.00" oninput="calcVentasCompras()">
                                    </div>
                                    <div class="fld">
                                        <label>Compra Sábado</label>
                                        <input type="number" step="0.01" name="compra_sabado" id="f-compra-sab" placeholder="0.00" oninput="calcVentasCompras()">
                                    </div>
                                    <div class="col-12 fld">
                                        <label>Compra Domingo</label>
                                        <input type="number" step="0.01" name="compra_domingo" id="f-compra-dom" placeholder="0.00" oninput="calcVentasCompras()">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-3">
                            <div class="col-md-4 fld">
                                <label>Ventas Contado %</label>
                                <input type="number" min="0" max="100" name="pct_contado" id="f-pct-contado" placeholder="%" oninput="validatePctSum()">
                            </div>
                            <div class="col-md-4 fld">
                                <label>Ventas Crédito %</label>
                                <input type="number" min="0" max="100" name="pct_credito" id="f-pct-credito" placeholder="%" oninput="validatePctSum()">
                            </div>
                            <div class="col-md-4 fld">
                                <label>Ventas en Efectivo %</label>
                                <input type="number" min="0" max="100" name="pct_efectivo" id="f-pct-efectivo" placeholder="%" oninput="validatePctSum()">
                            </div>
                        </div>

                        <div class="row g-3 mt-3">
                            <div class="col-md-4 fld">
                                <label>Mes de Alta Venta</label>
                                <select name="mes_alta_venta" id="f-mes-alta-venta">
                                    <option value="">-- Seleccionar --</option>
                                    <option value="Diciembre">Diciembre</option>
                                    <option value="Noviembre">Noviembre</option>
                                    <option value="Junio">Junio</option>
                                    <option value="Mayo">Mayo</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-md-4 fld">
                                <label>Mes de Baja Venta</label>
                                <select name="mes_baja_venta" id="f-mes-baja-venta">
                                    <option value="">-- Seleccionar --</option>
                                    <option value="Enero">Enero</option>
                                    <option value="Marzo">Marzo</option>
                                    <option value="Septiembre">Septiembre</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>
                            <div class="col-md-4 fld">
                                <label>Mes de Alta Compra</label>
                                <select name="mes_alta_compra" id="f-mes-alta-compra">
                                    <option value="">-- Seleccionar --</option>
                                    <option value="Diciembre">Diciembre</option>
                                    <option value="Noviembre">Noviembre</option>
                                    <option value="Mayo">Mayo</option>
                                    <option value="Otro">Otro</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 1: DETALLE DE PRODUCTOS
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="2">
                    <div class="form-card">
                        <h3><i class="fas fa-boxes-packing"></i> Inventario y Detalle de Productos</h3>
                        <p class="sub">Agrega los principales productos comerciales o de producción con sus costos y precios.</p>
                        
                        <div class="fld mb-3">
                            <label>Tipo de Actividad / Categoría de Productos</label>
                            <select id="sel-tipo-productos" onchange="toggleProductTableType()">
                                <option value="comercio">Comercio (Compra y Venta Directa)</option>
                                <option value="produccion">Producción o Servicios (Con Materia Prima / Mano de Obra)</option>
                            </select>
                        </div>

                        <!-- COMMERCE PRODUCTS TABLE -->
                        <div id="panel-prod-comercio">
                            <div class="table-responsive">
                                <table class="table-custom" id="tbl-prod-comercio">
                                    <thead>
                                        <tr>
                                            <th>Producto / Detalle</th>
                                            <th style="width:120px;">Precio Costo</th>
                                            <th style="width:120px;">Precio Venta</th>
                                            <th style="width:120px;">Unidad</th>
                                            <th style="width:120px;">Stock</th>
                                            <th style="width:120px;">Margen ($)</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <button class="btn-table-add" type="button" onclick="addCommerceRow()">
                                <i class="fas fa-plus"></i> Agregar Producto Comercial
                            </button>
                        </div>

                        <!-- PRODUCTION PRODUCTS TABLE -->
                        <div id="panel-prod-produccion" style="display:none;">
                            <div class="table-responsive">
                                <table class="table-custom" id="tbl-prod-produccion">
                                    <thead>
                                        <tr>
                                            <th>Producto / Servicio</th>
                                            <th style="width:100px;">Mat. Prima</th>
                                            <th style="width:100px;">Mano Obra</th>
                                            <th style="width:100px;">Empaque</th>
                                            <th style="width:100px;">Prec. Venta</th>
                                            <th style="width:100px;">Prod. Diaria</th>
                                            <th style="width:100px;">Venta Diaria</th>
                                            <th style="width:100px;">Stock</th>
                                            <th style="width:90px;">Margen ($)</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <button class="btn-table-add" type="button" onclick="addProductionRow()">
                                <i class="fas fa-plus"></i> Agregar Producto de Producción
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 2: GASTOS E INGRESOS
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="3">
                    <div class="form-card">
                        <h3><i class="fas fa-receipt"></i> Gastos del Negocio e Ingresos Familiares</h3>
                        <p class="sub">Ingresa todos los gastos operativos mensuales del negocio y los costos familiares.</p>
                        
                        <div class="sub-sec-title mt-0"><i class="fas fa-store text-primary"></i> Gastos Mensuales del Negocio</div>
                        <div class="fld-grid mb-4">
                            <div class="fld">
                                <label>Sueldos / Salarios</label>
                                <input type="number" step="0.01" name="g_neg_sueldos" id="f-gneg-sueldos" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Arriendo Local</label>
                                <input type="number" step="0.01" name="g_neg_arriendo" id="f-gneg-arriendo" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Servicios Básicos (Agua/Luz)</label>
                                <input type="number" step="0.01" name="g_neg_serv_bas" id="f-gneg-serv-bas" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Transporte / Combustible</label>
                                <input type="number" step="0.01" name="g_neg_transporte" id="f-gneg-transporte" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Mantenimiento / Reparación</label>
                                <input type="number" step="0.01" name="g_neg_mantenimiento" id="f-gneg-mantenimiento" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Imprevistos</label>
                                <input type="number" step="0.01" name="g_neg_imprevistos" id="f-gneg-imprevistos" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Otros Gastos del Negocio</label>
                                <input type="number" step="0.01" name="g_neg_otros" id="f-gneg-otros" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label style="color:var(--brand-navy);font-weight:bold;">Total Gastos Negocio</label>
                                <input type="number" step="0.01" name="gastos_negocio" id="f-gastos-negocio" readonly style="background:#eef2f6;font-weight:700;">
                            </div>
                        </div>

                        <div class="sub-sec-title"><i class="fas fa-home text-success"></i> Gastos Familiares Mensuales</div>
                        <div class="fld-grid mb-4">
                            <div class="fld">
                                <label>Alimentación</label>
                                <input type="number" step="0.01" name="g_fam_alim" id="f-gfam-alim" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Arriendo Casa</label>
                                <input type="number" step="0.01" name="g_fam_arriendo" id="f-gfam-arriendo" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Servicios Básicos Hogar</label>
                                <input type="number" step="0.01" name="g_fam_serv_bas" id="f-gfam-serv-bas" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Educación / Pensiones</label>
                                <input type="number" step="0.01" name="g_fam_educacion" id="f-gfam-educacion" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Salud / Medicina</label>
                                <input type="number" step="0.01" name="g_fam_salud" id="f-gfam-salud" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Imprevistos Hogar</label>
                                <input type="number" step="0.01" name="g_fam_imprevistos" id="f-gfam-imprevistos" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Otros Gastos Familiares</label>
                                <input type="number" step="0.01" name="g_fam_otros" id="f-gfam-otros" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label style="color:#16a34a;font-weight:bold;">Total Gastos Familiares</label>
                                <input type="number" step="0.01" name="gastos_familiares" id="f-gastos-familiares" readonly style="background:#eef2f6;font-weight:700;">
                            </div>
                        </div>

                        <div class="sub-sec-title"><i class="fas fa-hand-holding-dollar text-warning"></i> Otros Ingresos del Hogar</div>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Ingreso del Cónyuge</label>
                                <input type="number" step="0.01" name="o_ing_conyuge" id="f-oing-conyuge" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Arriendos / Alquileres</label>
                                <input type="number" step="0.01" name="o_ing_arriendos" id="f-oing-arriendos" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Jubilación / Pensiones</label>
                                <input type="number" step="0.01" name="o_ing_pensiones" id="f-oing-pensiones" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label>Otros Ingresos Adicionales</label>
                                <input type="number" step="0.01" name="o_ing_otros" id="f-oing-otros" placeholder="0.00" oninput="sumGastos()">
                            </div>
                            <div class="fld">
                                <label style="color:#ca8a04;font-weight:bold;">Total Otros Ingresos</label>
                                <input type="number" step="0.01" name="otros_ingresos" id="f-otros-ingresos" readonly style="background:#eef2f6;font-weight:700;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 3: ACTIVOS FIJOS
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="4">
                    <div class="form-card">
                        <h3><i class="fas fa-truck-ramp-box"></i> Activos Fijos del Negocio y del Hogar</h3>
                        <p class="sub">Detalla la maquinaria, equipos de oficina, vehículos y propiedades que posee el cliente.</p>
                        
                        <!-- BUSINESS FIXED ASSETS -->
                        <div class="sub-sec-title mt-0"><i class="fas fa-screwdrivers text-primary"></i> Activos Fijos del Negocio</div>
                        <div class="table-responsive">
                            <table class="table-custom" id="tbl-activos-negocio">
                                <thead>
                                    <tr>
                                        <th>Descripción del Activo (Maquinaria, etc.)</th>
                                        <th style="width:160px;">Marca</th>
                                        <th style="width:160px;">Modelo</th>
                                        <th style="width:160px;">Serie / Chasis</th>
                                        <th style="width:160px;">Valor Estimado</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button class="btn-table-add mb-4" type="button" onclick="addAssetRow('tbl-activos-negocio')">
                            <i class="fas fa-plus"></i> Agregar Activo del Negocio
                        </button>

                        <!-- HOUSEHOLD FIXED ASSETS -->
                        <div class="sub-sec-title"><i class="fas fa-couch text-success"></i> Activos del Hogar (Electrodomésticos, Muebles)</div>
                        <div class="table-responsive">
                            <table class="table-custom" id="tbl-activos-hogar">
                                <thead>
                                    <tr>
                                        <th>Descripción (Muebles, Hogar)</th>
                                        <th style="width:160px;">Marca</th>
                                        <th style="width:160px;">Modelo</th>
                                        <th style="width:160px;">Serie</th>
                                        <th style="width:160px;">Valor Estimado</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button class="btn-table-add mb-4" type="button" onclick="addAssetRow('tbl-activos-hogar')">
                            <i class="fas fa-plus"></i> Agregar Activo del Hogar
                        </button>

                        <!-- VEHICLES -->
                        <div class="sub-sec-title"><i class="fas fa-car text-warning"></i> Vehículos (Negocio u Hogar)</div>
                        <div class="table-responsive">
                            <table class="table-custom" id="tbl-vehiculos">
                                <thead>
                                    <tr>
                                        <th>Propietario / Dueño</th>
                                        <th style="width:140px;">Marca</th>
                                        <th style="width:140px;">Modelo</th>
                                        <th style="width:100px;">Año</th>
                                        <th style="width:120px;">Placa</th>
                                        <th style="width:140px;">Valor Comercial</th>
                                        <th style="width:140px;">Deuda / Gravamen</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button class="btn-table-add mb-4" type="button" onclick="addVehicleRow()">
                            <i class="fas fa-plus"></i> Agregar Vehículo
                        </button>

                        <!-- PROPERTIES / INMUEBLES -->
                        <div class="sub-sec-title"><i class="fas fa-house-chimney text-danger"></i> Bienes Inmuebles / Propiedades</div>
                        <div class="table-responsive">
                            <table class="table-custom" id="tbl-inmuebles">
                                <thead>
                                    <tr>
                                        <th>Propietario / Dueño</th>
                                        <th style="width:180px;">Tipo de Propiedad</th>
                                        <th>Ubicación / Dirección</th>
                                        <th style="width:160px;">Valor Comercial</th>
                                        <th style="width:160px;">Hipoteca / Deuda</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button class="btn-table-add" type="button" onclick="addInmuebleRow()">
                            <i class="fas fa-plus"></i> Agregar Bien Inmueble
                        </button>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 4: BALANCE GENERAL
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="5">
                    <div class="form-card">
                        <h3><i class="fas fa-scale-balanced"></i> Balance General de la Empresa</h3>
                        <p class="sub">Registra los saldos de activos circulantes y los pasivos actuales de la empresa.</p>
                        
                        <div class="sub-sec-title mt-0"><i class="fas fa-circle-dollar-to-slot text-success"></i> Activos de la Empresa (Saldos / Disponibilidades)</div>
                        <div class="fld-grid mb-4">
                            <div class="fld">
                                <label>Caja Chica / Efectivo en Mano</label>
                                <input type="number" step="0.01" name="caja_efectivo" id="f-caja-efectivo" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Bancos / Saldos en Cuenta</label>
                                <input type="number" step="0.01" name="bancos_saldo" id="f-bancos-saldo" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Cuentas por Cobrar (Clientes)</label>
                                <input type="number" step="0.01" name="cxp_netas" id="f-cxp-netas" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Inventario de Materia Prima</label>
                                <input type="number" step="0.01" name="inv_mat_prima" id="f-inv-mat-prima" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Inventario de Producto en Proceso / Terminado</label>
                                <input type="number" step="0.01" name="inv_prod_proc" id="f-inv-prod-proc" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label style="color:#16a34a;font-weight:bold;">Total Activos Líquidos</label>
                                <input type="number" step="0.01" id="f-total-activos-liq" readonly style="background:#eef2f6;font-weight:700;">
                            </div>
                        </div>

                        <div class="sub-sec-title"><i class="fas fa-hand-holding-hand text-danger"></i> Pasivos y Obligaciones de la Empresa</div>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Créditos por Pagar (Instituciones financieras)</label>
                                <input type="number" step="0.01" name="creditos_pagar" id="f-creditos-pagar" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Proveedores (Cuentas por pagar comerciales)</label>
                                <input type="number" step="0.01" name="proveedores" id="f-proveedores" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Otras Deudas a Corto Plazo</label>
                                <input type="number" step="0.01" name="otras_deudas_cp" id="f-otras-deudas-cp" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label>Pasivos a Largo Plazo</label>
                                <input type="number" step="0.01" name="pasivos_lp" id="f-pasivos-lp" placeholder="0.00" oninput="sumBalance()">
                            </div>
                            <div class="fld">
                                <label style="color:#ef4444;font-weight:bold;">Total Pasivos</label>
                                <input type="number" step="0.01" id="f-total-pasivos" readonly style="background:#eef2f6;font-weight:700;">
                            </div>
                        </div>

                        <!-- OTRAS DEUDAS DETALLADAS -->
                        <div class="sub-sec-title mt-4"><i class="fas fa-credit-card text-warning"></i> Detalle de Otras Deudas / Tarjetas de Crédito</div>
                        <div class="table-responsive">
                            <table class="table-custom" id="tbl-otras-deudas">
                                <thead>
                                    <tr>
                                        <th>Entidad Acreedora</th>
                                        <th style="width:160px;">Monto Inicial</th>
                                        <th style="width:160px;">Saldo Pendiente</th>
                                        <th style="width:160px;">Cuota Mensual</th>
                                        <th style="width:160px;">Fecha Vencimiento</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <button class="btn-table-add" type="button" onclick="addDeudaRow()">
                            <i class="fas fa-plus"></i> Agregar Otra Deuda
                        </button>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 5: FLUJO DE CAJA (RESUMEN)
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="6">
                    <!-- FLASHY READONLY SUMMARY -->
                    <div class="flow-summary-card">
                        <h4><i class="fas fa-chart-pie"></i> Análisis Mensual y Flujo de Caja</h4>
                        <div class="sum-grid">
                            <div class="sum-box">
                                <div class="sb-title">Ventas Totales Mensuales</div>
                                <div class="sb-val" id="lbl-sum-ventas">$0.00</div>
                            </div>
                            <div class="sum-box">
                                <div class="sb-title">Costo de Ventas (Compras)</div>
                                <div class="sb-val" id="lbl-sum-compras">$0.00</div>
                            </div>
                            <div class="sum-box">
                                <div class="sb-title">Utilidad Bruta / Margen</div>
                                <div class="sb-val" id="lbl-sum-margen">$0.00</div>
                            </div>
                            <div class="sum-box">
                                <div class="sb-title">Gastos del Negocio</div>
                                <div class="sb-val" id="lbl-sum-gastos-neg">$0.00</div>
                            </div>
                            <div class="sum-box">
                                <div class="sb-title">Utilidad Operativa Negocio</div>
                                <div class="sb-val" id="lbl-sum-util-oper">$0.00</div>
                            </div>
                            <div class="sum-box">
                                <div class="sb-title">Ingresos Familiares Adicionales</div>
                                <div class="sb-val" id="lbl-sum-ing-fam">$0.00</div>
                            </div>
                            <div class="sum-box">
                                <div class="sb-title">Gastos Familiares</div>
                                <div class="sb-val" id="lbl-sum-gastos-fam">$0.00</div>
                            </div>
                            <div class="sum-box highlight">
                                <div class="sb-title">Superávit Neto (Excedente)</div>
                                <div class="sb-val" id="lbl-sum-excedente">$0.00</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h3><i class="fas fa-hand-holding-dollar"></i> Ajuste Final de Indicadores</h3>
                        <p class="sub">Valores finales consolidados que se guardarán en la ficha.</p>
                        
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Costo de Ventas Declarado ($)</label>
                                <input type="number" step="0.01" name="costos_ventas" id="f-costo-ventas-final" placeholder="0.00">
                            </div>
                            <div class="fld">
                                <label>Recuperación de Cartera / Cobro de Crédito ($)</label>
                                <input type="number" step="0.01" name="recuperacion_credito" id="f-recup-credito" placeholder="0.00">
                            </div>
                            <div class="col-md-12 fld">
                                <label>Observaciones del Flujo Financiero</label>
                                <textarea name="observaciones" id="f-observaciones" placeholder="Describe brevemente la solidez financiera de la empresa y la capacidad de pago observada..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ══════════════════════════════════════
                     STEP 6: IDENTIFICACIÓN INSTITUCIONAL
                ═══════════════════════════════════════ -->
                <div class="step-pane" data-pane="7">
                    <div class="form-card">
                        <h3><i class="fas fa-id-card-clip"></i> Relación e Identificación Institucional</h3>
                        <p class="sub">Preguntas cualitativas sobre el cliente y su opinión de la cooperativa.</p>
                        
                        <div class="sub-sec mt-0">
                            <h5 style="color:var(--brand-navy);font-weight:600;margin-bottom:10px;">¿Conoce nuestra institución?</h5>
                            <div class="yn-group mb-3">
                                <label class="yn-opt" id="lbl-p1-si" onclick="toggleYNOpt('lbl-p1-si', 'p1_conoce_institucion', '1')">
                                    <input type="radio" name="p1_conoce_institucion" id="p1-si" value="1"> Sí
                                </label>
                                <label class="yn-opt" id="lbl-p1-no" onclick="toggleYNOpt('lbl-p1-no', 'p1_conoce_institucion', '0')">
                                    <input type="radio" name="p1_conoce_institucion" id="p1-no" value="0"> No
                                </label>
                            </div>
                            <div class="fld">
                                <label>Observaciones / Detalles</label>
                                <input type="text" name="p1_obs" placeholder="Ej: Vio publicidad, recomendado por amigos, etc.">
                            </div>
                        </div>

                        <div class="sub-sec">
                            <h5 style="color:var(--brand-navy);font-weight:600;margin-bottom:10px;">¿Es o ha sido cliente de nuestra institución?</h5>
                            <div class="yn-group mb-3">
                                <label class="yn-opt" id="lbl-p2-si" onclick="toggleYNOpt('lbl-p2-si', 'p2_es_cliente', '1')">
                                    <input type="radio" name="p2_es_cliente" id="p2-si" value="1"> Sí
                                </label>
                                <label class="yn-opt" id="lbl-p2-no" onclick="toggleYNOpt('lbl-p2-no', 'p2_es_cliente', '0')">
                                    <input type="radio" name="p2_es_cliente" id="p2-no" value="0"> No
                                </label>
                            </div>
                            <div class="fld mb-3">
                                <label>¿Qué productos mantiene / mantuvo?</label>
                                <input type="text" name="p2_producto" placeholder="Ej: Cuenta de ahorro, microcrédito activo, etc.">
                            </div>
                            <div class="fld">
                                <label>Observaciones / Detalles</label>
                                <input type="text" name="p2_obs" placeholder="Comentarios adicionales...">
                            </div>
                        </div>

                        <div class="sub-sec">
                            <h5 style="color:var(--brand-navy);font-weight:600;margin-bottom:10px;">¿Cuál es su nivel de satisfacción o percepción de la institución?</h5>
                            <div class="fld mb-3">
                                <select name="p3_satisfaccion">
                                    <option value="">-- Seleccionar --</option>
                                    <option value="alto">Alto (Muy buena reputación)</option>
                                    <option value="medio">Medio (Normal/Regular)</option>
                                    <option value="bajo">Bajo (Tiene reclamos/quejas)</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Observaciones / Detalles</label>
                                <input type="text" name="p3_obs" placeholder="Detalles de su nivel de satisfacción...">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WIZARD FOOTER NAVIGATION -->
                <div class="form-footer">
                    <button class="btn-footer btn-ghost" type="button" id="btn-wizard-prev" onclick="moveStep(-1)">
                        <i class="fas fa-chevron-left"></i> Anterior
                    </button>
                    <div>
                        <button class="btn-footer btn-ghost me-2" type="button" onclick="cancelSurvey()">
                            Cancelar
                        </button>
                        <button class="btn-footer btn-yellow" type="button" id="btn-wizard-next" onclick="moveStep(1)">
                            Siguiente <i class="fas fa-chevron-right"></i>
                        </button>
                        <button class="btn-footer btn-navy" type="button" id="btn-wizard-save" style="display:none;" onclick="submitSurvey()">
                            <i class="fas fa-floppy-disk"></i> Guardar Levantamiento
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentStep = 0;
const totalSteps = 8;
let selectedClient = null;

// Live search variables
let allCompanies = [];

// Fetch and render initial companies when DOM is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    fetchCompanies();
});

// Perform AJAX search from database
function fetchCompanies(query = '') {
    const grid = document.getElementById('search-results-grid');
    grid.innerHTML = '<div class="col-12 text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2 text-muted">Cargando empresas...</p></div>';
    
    const formData = new FormData();
    formData.append('nombre_empresa', query);
    formData.append('limit', 150);
    
    fetch('../buscar_cliente_por_empresa.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success' && data.items) {
            allCompanies = data.items;
            filterAndRenderCompanies();
        } else {
            grid.innerHTML = '<div class="col-12 alert alert-warning text-center py-4"><i class="fas fa-circle-exclamation fa-lg"></i> Error al cargar las empresas del servidor.</div>';
        }
    })
    .catch(err => {
        console.error(err);
        grid.innerHTML = '<div class="col-12 alert alert-danger text-center py-4"><i class="fas fa-circle-exclamation fa-lg"></i> Error de conexión con el servidor.</div>';
    });
}

// Client-side real-time rendering and matching
function filterAndRenderCompanies() {
    const query = document.getElementById('inp-search-empresa').value.trim().toLowerCase();
    const grid = document.getElementById('search-results-grid');
    grid.innerHTML = '';
    
    const filtered = allCompanies.filter(c => {
        const companyName = (c.nombre_empresa || '').toLowerCase();
        const clientName = (c.nombre || '').toLowerCase();
        const cedula = (c.cedula || '').toLowerCase();
        const phone = (c.celular || c.telefono || '').toLowerCase();
        return companyName.includes(query) || clientName.includes(query) || cedula.includes(query) || phone.includes(query);
    });
    
    if (filtered.length > 0) {
        filtered.forEach(c => {
            const hasSurvey = c.encuesta_negocio && c.encuesta_negocio.id;
            const badge = hasSurvey 
                ? '<span class="pc-badge completed"><i class="fas fa-check-circle"></i> Levantamiento Completado</span>'
                : '<span class="pc-badge pending"><i class="fas fa-triangle-exclamation"></i> Pendiente de Levantamiento</span>';
            
            const btnLabel = hasSurvey ? '<i class="fas fa-pen-to-square"></i> Editar Levantamiento' : '<i class="fas fa-plus"></i> Iniciar Levantamiento';
            
            const card = document.createElement('div');
            card.className = 'prospect-card';
            card.innerHTML = `
                <div class="pc-title"><i class="fas fa-user text-navy"></i> ${c.nombre}</div>
                <div class="pc-company"><i class="fas fa-building text-warning"></i> ${c.nombre_empresa || 'Negocio no especificado'}</div>
                <div class="pc-info"><span>Cédula:</span> ${c.cedula || 'N/A'}</div>
                <div class="pc-info"><span>Celular:</span> ${c.celular || 'N/A'}</div>
                <div class="pc-info"><span>Ciudad:</span> ${c.ciudad || 'N/A'}</div>
                ${badge}
                <div class="pc-action">
                    <button class="btn-card-action" onclick='selectClientForSurvey(${JSON.stringify(c)})'>${btnLabel}</button>
                </div>
            `;
            grid.appendChild(card);
        });
    } else {
        grid.innerHTML = '<div class="col-12 alert alert-warning text-center py-4"><i class="fas fa-circle-exclamation fa-lg"></i> No se encontraron prospectos que coincidan con la búsqueda.</div>';
    }
}

// Attach real-time keystroke filtering to search input
document.getElementById('inp-search-empresa').addEventListener('input', filterAndRenderCompanies);

// Clicking the search button forces a fresh fetch from the server database
document.getElementById('btn-buscar-emp').addEventListener('click', function() {
    const query = document.getElementById('inp-search-empresa').value.trim();
    fetchCompanies(query);
});

// Select client and start wizard
/* REGIMEN TILES */
function selectRegimen(el) {
    document.querySelectorAll('.regimen-tile').forEach(t => t.classList.remove('selected'));
    el.classList.add('selected');
    
    const radio = el.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
    
    const val = el.dataset.val;
    document.getElementById('q-ruc').style.display  = val === 'ruc'  ? 'flex' : 'none';
    document.getElementById('q-rise').style.display = val === 'rise' ? 'flex' : 'none';
    
    document.getElementById('hid-tiene_ruc').value  = val === 'ruc'  ? '1' : '0';
    document.getElementById('hid-tiene_rise').value = val === 'rise' ? '1' : '0';
}

/* SET RADIO BY NAME HELPER */
function setRadioByName(name, val) {
    const radio = document.querySelector(`input[name="${name}"][value="${val}"]`);
    if (radio) {
        radio.checked = true;
        const opt = radio.parentElement;
        if (opt && opt.classList.contains('yn-opt')) {
            const group = opt.closest('.yn-group');
            if (group) {
                group.querySelectorAll('.yn-opt').forEach(x => x.classList.remove('checked'));
            }
            opt.classList.add('checked');
        }
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

// Init click handlers for q-btn cards (Sí / No)
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.q-btn');
    if (!btn) return;
    
    const actions = btn.closest('.q-actions');
    if (!actions) return;
    
    actions.querySelectorAll('.q-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    const hidId = btn.dataset.hid;
    if (hidId) {
        const hidInput = document.getElementById(hidId);
        if (hidInput) {
            hidInput.value = btn.dataset.val;
        }
    }
});

// Init click handler for yn-opt elements
document.addEventListener('click', function(e) {
    const opt = e.target.closest('.yn-opt');
    if (!opt) return;
    
    const group = opt.closest('.yn-group');
    if (!group) return;
    
    group.querySelectorAll('.yn-opt').forEach(x => x.classList.remove('checked'));
    opt.classList.add('checked');
    
    const input = opt.querySelector('input[type="radio"]');
    if (input) {
        input.checked = true;
        
        // Custom logic for tiene_empresa
        if (input.name === 'tiene_empresa') {
            const hasEmp = input.value === '1';
            document.getElementById('extras-empresa').classList.toggle('show', hasEmp);
            document.getElementById('aviso-sin-empresa').style.display = hasEmp ? 'none' : 'block';
            
            // Set required attributes dynamically
            const fNombre = document.getElementById('f-nombre_empresa');
            const fTipo = document.getElementById('f-tipo_empresa');
            if (fNombre && fTipo) {
                if (hasEmp) {
                    fNombre.setAttribute('required', 'required');
                    fTipo.setAttribute('required', 'required');
                } else {
                    fNombre.removeAttribute('required');
                    fTipo.removeAttribute('required');
                }
            }
        }
    }
});

function selectClientForSurvey(client) {
    selectedClient = client;
    document.getElementById('hid-cliente-id').value = client.id;
    document.getElementById('lbl-info-nombre').textContent = client.nombre;
    document.getElementById('lbl-info-empresa').textContent = client.nombre_empresa || 'N/A';
    document.getElementById('lbl-info-cedula').textContent = client.cedula || 'N/A';

    // Populate client personal details in Step 0
    document.getElementById('f-cp-nombre').value = client.nombre || '';
    document.getElementById('f-cp-cedula').value = client.cedula || '';
    document.getElementById('f-cp-celular').value = client.celular || '';
    document.getElementById('f-cp-telefono').value = client.telefono || '';
    document.getElementById('f-cp-email').value = client.email || '';
    document.getElementById('f-cp-direccion').value = client.direccion || '';
    document.getElementById('f-cp-ciudad').value = client.ciudad || '';
    document.getElementById('f-cp-sector').value = client.sector || '';

    // Populate Régimen Tributario
    let regimen = client.regimen_tributario || '';
    if (!regimen) {
        if (parseInt(client.tiene_ruc) === 1) regimen = 'ruc';
        else if (parseInt(client.tiene_rise) === 1) regimen = 'rise';
        else regimen = 'none';
    }
    const regTile = document.querySelector(`.regimen-tile[data-val="${regimen}"]`);
    if (regTile) {
        selectRegimen(regTile);
    }
    
    // Fill specific fields if RUC or RISE
    if (regimen === 'ruc') {
        document.getElementById('f-ruc-numero').value = client.numero_ruc || '';
    } else if (regimen === 'rise') {
        document.getElementById('f-rise-numero').value = client.numero_ruc || '';
    }
    
    // Fill RUC / RISE sub questions
    const qFields = [
        { hid: 'hid-ruc_declara_iva', val: client.declara_iva },
        { hid: 'hid-ruc_emite_facturas', val: client.emite_facturas },
        { hid: 'hid-ruc_lleva_contab', val: client.lleva_contabilidad },
        { hid: 'hid-rise_paga_cuota', val: client.paga_cuota_rise },
        { hid: 'hid-rise_emite_notas', val: client.emite_notas_venta },
        { hid: 'hid-rise_conoce_limite', val: client.conoce_limite_rise }
    ];
    // Reset any previous active class
    document.querySelectorAll('.q-btn').forEach(b => b.classList.remove('active'));
    qFields.forEach(f => {
        const valStr = String(f.val !== null && f.val !== undefined ? f.val : '');
        if (valStr !== '') {
            const btn = document.querySelector(`.q-btn[data-hid="${f.hid}"][data-val="${valStr}"]`);
            if (btn) {
                btn.classList.add('active');
                const hidInput = document.getElementById(f.hid);
                if (hidInput) hidInput.value = valStr;
            }
        } else {
            const hidInput = document.getElementById(f.hid);
            if (hidInput) hidInput.value = '';
        }
    });

    // Populate tiene_empresa
    const tieneEmp = (parseInt(client.tiene_empresa) === 1 || client.tiene_empresa === true || client.tiene_empresa === '1') ? '1' : '0';
    setRadioByName('tiene_empresa', tieneEmp);
    document.getElementById('extras-empresa').classList.toggle('show', tieneEmp === '1');
    document.getElementById('aviso-sin-empresa').style.display = tieneEmp === '1' ? 'none' : 'block';
    
    document.getElementById('f-nombre_empresa').value = client.nombre_empresa || '';
    document.getElementById('f-tipo_empresa').value = client.tipo_empresa || '';
    
    const fNombre = document.getElementById('f-nombre_empresa');
    const fTipo = document.getElementById('f-tipo_empresa');
    if (fNombre && fTipo) {
        if (tieneEmp === '1') {
            fNombre.setAttribute('required', 'required');
            fTipo.setAttribute('required', 'required');
        } else {
            fNombre.removeAttribute('required');
            fTipo.removeAttribute('required');
        }
    }

    // Clear previous dynamic table rows
    document.querySelector('#tbl-prod-comercio tbody').innerHTML = '';
    document.querySelector('#tbl-prod-produccion tbody').innerHTML = '';
    document.querySelector('#tbl-activos-negocio tbody').innerHTML = '';
    document.querySelector('#tbl-activos-hogar tbody').innerHTML = '';
    document.querySelector('#tbl-vehiculos tbody').innerHTML = '';
    document.querySelector('#tbl-inmuebles tbody').innerHTML = '';
    document.querySelector('#tbl-otras-deudas tbody').innerHTML = '';
    
    // Check if the client has an active tarea_id for levantamiento
    const hasSurvey = client.encuesta_negocio && client.encuesta_negocio.id;
    const tId = client.tarea_id || '';
    document.getElementById('hid-tarea-id').value = tId;
    
    if (tId) {
        // Fetch existing completed survey details to edit
        const formData = new FormData();
        formData.append('tarea_id', tId);
        
        fetch('../obtener_encuesta_completa.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(resData => {
            if (resData.status === 'success' && resData.data && resData.data.encuesta_negocio) {
                fillSurveyForm(resData.data.encuesta_negocio);
            }
        })
        .catch(err => console.error("Error loading survey data:", err));
    }
    
    // Show wizard, hide search results
    document.getElementById('section-search').style.display = 'none';
    document.getElementById('section-wizard').style.display = 'block';
    currentStep = 0;
    showStep(0);
}

// Fill form fields with loaded database values
function fillSurveyForm(neg) {
    // Step 0: Sales and purchases
    document.getElementById('f-venta-lv').value = neg.venta_lv || '';
    document.getElementById('f-venta-sab').value = neg.venta_sabado || '';
    document.getElementById('f-venta-dom').value = neg.venta_domingo || '';
    document.getElementById('f-compra-lv').value = neg.compra_lv || '';
    document.getElementById('f-compra-sab').value = neg.compra_sabado || '';
    document.getElementById('f-compra-dom').value = neg.compra_domingo || '';
    
    document.getElementById('f-pct-contado').value = neg.pct_contado || '';
    document.getElementById('f-pct-credito').value = neg.pct_credito || '';
    document.getElementById('f-pct-efectivo').value = neg.pct_efectivo || '';
    
    document.getElementById('f-mes-alta-venta').value = neg.mes_alta_venta || '';
    document.getElementById('f-mes-baja-venta').value = neg.mes_baja_venta || '';
    document.getElementById('f-mes-alta-compra').value = neg.mes_alta_compra || '';
    
    // Attention days checkboxes
    setCheckAndChip('chk-dia-lun', 'lbl-dia-lun', neg.dia_lun);
    setCheckAndChip('chk-dia-mar', 'lbl-dia-mar', neg.dia_mar);
    setCheckAndChip('chk-dia-mie', 'lbl-dia-mie', neg.dia_mie);
    setCheckAndChip('chk-dia-jue', 'lbl-dia-jue', neg.dia_jue);
    setCheckAndChip('chk-dia-vie', 'lbl-dia-vie', neg.dia_vie);
    setCheckAndChip('chk-dia-sab', 'lbl-dia-sab', neg.dia_sab);
    setCheckAndChip('chk-dia-dom', 'lbl-dia-dom', neg.dia_dom);
    
    // Step 1: Products detail
    let prods = [];
    let isProd = false;
    try {
        if (neg.productos_json) {
            prods = JSON.parse(neg.productos_json);
            isProd = true;
        } else if (neg.comercio_productos_json) {
            prods = JSON.parse(neg.comercio_productos_json);
            isProd = false;
        }
    } catch(e) {}
    
    if (isProd) {
        document.getElementById('sel-tipo-productos').value = 'produccion';
        toggleProductTableType();
        prods.forEach(p => addProductionRow(p.nombre, p.costo_materia_prima, p.costo_mano_obra, p.costo_empaque, p.precio_venta, p.produccion_diaria, p.ventas_diarias, p.cantidad_inventario));
    } else {
        document.getElementById('sel-tipo-productos').value = 'comercio';
        toggleProductTableType();
        prods.forEach(p => addCommerceRow(p.nombre, p.precio_costo, p.precio_venta, p.unidad_medida, p.cantidad_inventario));
    }

    // Step 2: Expenses
    document.getElementById('f-gneg-sueldos').value = neg.g_neg_sueldos || '';
    document.getElementById('f-gneg-arriendo').value = neg.g_neg_arriendo || '';
    document.getElementById('f-gneg-serv-bas').value = neg.g_neg_serv_bas || '';
    document.getElementById('f-gneg-transporte').value = neg.g_neg_transporte || '';
    document.getElementById('f-gneg-mantenimiento').value = neg.g_neg_mantenimiento || '';
    document.getElementById('f-gneg-imprevistos').value = neg.g_neg_imprevistos || '';
    document.getElementById('f-gneg-otros').value = neg.g_neg_otros || '';
    
    document.getElementById('f-gfam-alim').value = neg.g_fam_alim || '';
    document.getElementById('f-gfam-arriendo').value = neg.g_fam_arriendo || '';
    document.getElementById('f-gfam-serv-bas').value = neg.g_fam_serv_bas || '';
    document.getElementById('f-gfam-educacion').value = neg.g_fam_educacion || '';
    document.getElementById('f-gfam-salud').value = neg.g_fam_salud || '';
    document.getElementById('f-gfam-imprevistos').value = neg.g_fam_imprevistos || '';
    document.getElementById('f-gfam-otros').value = neg.g_fam_otros || '';
    
    document.getElementById('f-oing-conyuge').value = neg.o_ing_conyuge || '';
    document.getElementById('f-oing-arriendos').value = neg.o_ing_arriendos || '';
    document.getElementById('f-oing-pensiones').value = neg.o_ing_pensiones || '';
    document.getElementById('f-oing-otros').value = neg.o_ing_otros || '';
    sumGastos();

    // Step 3: Assets
    try {
        if (neg.activos_negocio_json) {
            const an = JSON.parse(neg.activos_negocio_json);
            an.forEach(a => addAssetRow('tbl-activos-negocio', a.descripcion, a.marca, a.modelo, a.serie, a.valor_estimado));
        }
        if (neg.activos_hogar_json) {
            const ah = JSON.parse(neg.activos_hogar_json);
            ah.forEach(a => addAssetRow('tbl-activos-hogar', a.descripcion, a.marca, a.modelo, a.serie, a.valor_estimado));
        }
        if (neg.vehiculos_negocio_json) {
            const vh = JSON.parse(neg.vehiculos_negocio_json);
            vh.forEach(v => addVehicleRow(v.propietario, v.marca, v.modelo, v.anio, v.placa, v.valor_comercial, v.deuda_gravamen));
        }
        if (neg.inmuebles_negocio_json) {
            const inmu = JSON.parse(neg.inmuebles_negocio_json);
            inmu.forEach(im => addInmuebleRow(im.propietario, im.tipo_propiedad, im.ubicacion, im.valor_comercial, im.hipoteca_deuda));
        }
    } catch(e) {}

    // Step 4: Balance General
    document.getElementById('f-caja-efectivo').value = neg.caja_efectivo || '';
    document.getElementById('f-bancos-saldo').value = neg.bancos_saldo || '';
    document.getElementById('f-cxp-netas').value = neg.cxp_netas || '';
    document.getElementById('f-inv-mat-prima').value = neg.inv_mat_prima || '';
    document.getElementById('f-inv-prod-proc').value = neg.inv_prod_proc || '';
    
    document.getElementById('f-creditos-pagar').value = neg.creditos_pagar || '';
    document.getElementById('f-proveedores').value = neg.proveedores || '';
    document.getElementById('f-otras-deudas-cp').value = neg.otras_deudas_cp || '';
    document.getElementById('f-pasivos-lp').value = neg.pasivos_lp || '';
    sumBalance();

    try {
        if (neg.otras_deudas_json) {
            const deudas = JSON.parse(neg.otras_deudas_json);
            deudas.forEach(d => addDeudaRow(d.acreedor, d.monto_inicial, d.saldo_pendiente, d.cuota_mensual, d.fecha_vencimiento));
        }
    } catch(e) {}

    // Step 5: Flujo final
    document.getElementById('f-costo-ventas-final').value = neg.costos_ventas || '';
    document.getElementById('f-recup-credito').value = neg.recuperacion_credito || '';
    document.getElementById('f-observaciones').value = neg.observaciones || '';
    
    // Step 6: Identificacion
    setRadioAndChip('p1-si', 'lbl-p1-si', 'p1_conoce_institucion', neg.p1_conoce_institucion, '1');
    setRadioAndChip('p1-no', 'lbl-p1-no', 'p1_conoce_institucion', neg.p1_conoce_institucion, '0');
    document.querySelector('input[name="p1_obs"]').value = neg.p1_obs || '';
    
    setRadioAndChip('p2-si', 'lbl-p2-si', 'p2_es_cliente', neg.p2_es_cliente, '1');
    setRadioAndChip('p2-no', 'lbl-p2-no', 'p2_es_cliente', neg.p2_es_cliente, '0');
    document.querySelector('input[name="p2_producto"]').value = neg.p2_producto || '';
    document.querySelector('input[name="p2_obs"]').value = neg.p2_obs || '';
    
    document.querySelector('select[name="p3_satisfaccion"]').value = neg.p3_satisfaccion || '';
    document.querySelector('input[name="p3_obs"]').value = neg.p3_obs || '';

    calcVentasCompras();
}

function setCheckAndChip(chkId, lblId, val) {
    const chk = document.getElementById(chkId);
    const chip = document.getElementById(lblId);
    if (chk && chip) {
        chk.checked = parseInt(val) === 1;
        if (chk.checked) chip.classList.add('checked');
        else chip.classList.remove('checked');
    }
}

function setRadioAndChip(radId, lblId, name, val, matchVal) {
    const rad = document.getElementById(radId);
    const lbl = document.getElementById(lblId);
    if (rad && lbl) {
        if (val !== null && String(val) === matchVal) {
            rad.checked = true;
            lbl.classList.add('checked');
        } else {
            lbl.classList.remove('checked');
        }
    }
}

function toggleDayChip(input) {
    const lbl = input.parentElement;
    if (input.checked) lbl.classList.add('checked');
    else lbl.classList.remove('checked');
    calcVentasCompras();
}

function toggleYNOpt(lblId, name, value) {
    // Clear other options
    const opts = document.querySelectorAll(`input[name="${name}"]`);
    opts.forEach(opt => {
        opt.parentElement.classList.remove('checked');
        if (opt.value === value) {
            opt.checked = true;
            opt.parentElement.classList.add('checked');
        }
    });
}

// Toggle product table type
function toggleProductTableType() {
    const val = document.getElementById('sel-tipo-productos').value;
    if (val === 'comercio') {
        document.getElementById('panel-prod-comercio').style.display = 'block';
        document.getElementById('panel-prod-produccion').style.display = 'none';
    } else {
        document.getElementById('panel-prod-comercio').style.display = 'none';
        document.getElementById('panel-prod-produccion').style.display = 'block';
    }
    calcVentasCompras();
}

// Commerce row functions
function addCommerceRow(nombre='', cost='', price='', unit='', inventory='') {
    const tbody = document.querySelector('#tbl-prod-comercio tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="pc-nombre" value="${nombre}" placeholder="Ej: Jabón de baño"></td>
        <td><input type="number" step="0.01" class="pc-costo" value="${cost}" placeholder="0.00" oninput="calcCommerceMargin(this)"></td>
        <td><input type="number" step="0.01" class="pc-venta" value="${price}" placeholder="0.00" oninput="calcCommerceMargin(this)"></td>
        <td><input type="text" class="pc-unidad" value="${unit}" placeholder="Ej: Unidad / Caja"></td>
        <td><input type="number" class="pc-stock" value="${inventory}" placeholder="0"></td>
        <td class="pc-margen text-success font-weight-bold">$0.00</td>
        <td><button class="btn-row-del" type="button" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
    calcCommerceMargin(tr.querySelector('.pc-costo'));
}

function calcCommerceMargin(input) {
    const tr = input.parentElement.parentElement;
    const cost = parseFloat(tr.querySelector('.pc-costo').value) || 0;
    const price = parseFloat(tr.querySelector('.pc-venta').value) || 0;
    const margin = price - cost;
    tr.querySelector('.pc-margen').textContent = '$' + margin.toFixed(2);
    calcVentasCompras();
}

// Production row functions
function addProductionRow(nombre='', mp='', mo='', emp='', price='', prod='', sales='', stock='') {
    const tbody = document.querySelector('#tbl-prod-produccion tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="pp-nombre" value="${nombre}" placeholder="Ej: Zapatos deportivos"></td>
        <td><input type="number" step="0.01" class="pp-mp" value="${mp}" placeholder="0.00" oninput="calcProductionMargin(this)"></td>
        <td><input type="number" step="0.01" class="pp-mo" value="${mo}" placeholder="0.00" oninput="calcProductionMargin(this)"></td>
        <td><input type="number" step="0.01" class="pp-emp" value="${emp}" placeholder="0.00" oninput="calcProductionMargin(this)"></td>
        <td><input type="number" step="0.01" class="pp-venta" value="${price}" placeholder="0.00" oninput="calcProductionMargin(this)"></td>
        <td><input type="number" class="pp-prod" value="${prod}" placeholder="0"></td>
        <td><input type="number" class="pp-ventas-dia" value="${sales}" placeholder="0"></td>
        <td><input type="number" class="pp-stock" value="${stock}" placeholder="0"></td>
        <td class="pp-margen text-success font-weight-bold">$0.00</td>
        <td><button class="btn-row-del" type="button" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
    calcProductionMargin(tr.querySelector('.pp-mp'));
}

function calcProductionMargin(input) {
    const tr = input.parentElement.parentElement;
    const mp = parseFloat(tr.querySelector('.pp-mp').value) || 0;
    const mo = parseFloat(tr.querySelector('.pp-mo').value) || 0;
    const emp = parseFloat(tr.querySelector('.pp-emp').value) || 0;
    const price = parseFloat(tr.querySelector('.pp-venta').value) || 0;
    const totalCost = mp + mo + emp;
    const margin = price - totalCost;
    const mCol = tr.querySelector('.pp-margen');
    if (mCol) mCol.textContent = '$' + margin.toFixed(2);
    calcVentasCompras();
}

// General Fixed Asset Table functions
function addAssetRow(tableId, desc='', brand='', model='', serial='', val='') {
    const tbody = document.querySelector(`#${tableId} tbody`);
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="as-desc" value="${desc}" placeholder="Ej: Máquina de coser Singer"></td>
        <td><input type="text" class="as-brand" value="${brand}" placeholder="Marca"></td>
        <td><input type="text" class="as-model" value="${model}" placeholder="Modelo"></td>
        <td><input type="text" class="as-serial" value="${serial}" placeholder="Nº Serie"></td>
        <td><input type="number" step="0.01" class="as-val" value="${val}" placeholder="0.00"></td>
        <td><button class="btn-row-del" type="button" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

// Vehicle Table functions
function addVehicleRow(owner='', brand='', model='', year='', plate='', val='', debt='') {
    const tbody = document.querySelector('#tbl-vehiculos tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="vh-owner" value="${owner}" placeholder="Ej: Cliente"></td>
        <td><input type="text" class="vh-brand" value="${brand}" placeholder="Ej: Chevrolet"></td>
        <td><input type="text" class="vh-model" value="${model}" placeholder="Ej: Sail"></td>
        <td><input type="number" class="vh-year" value="${year}" placeholder="Año"></td>
        <td><input type="text" class="vh-plate" value="${plate}" placeholder="Placa"></td>
        <td><input type="number" step="0.01" class="vh-val" value="${val}" placeholder="0.00"></td>
        <td><input type="number" step="0.01" class="vh-debt" value="${debt}" placeholder="0.00"></td>
        <td><button class="btn-row-del" type="button" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

// Property Table functions
function addInmuebleRow(owner='', type='', loc='', val='', debt='') {
    const tbody = document.querySelector('#tbl-inmuebles tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="im-owner" value="${owner}" placeholder="Ej: Sociedad Cónyuges"></td>
        <td><input type="text" class="im-type" value="${type}" placeholder="Ej: Casa Habitacional"></td>
        <td><input type="text" class="im-loc" value="${loc}" placeholder="Dirección del bien"></td>
        <td><input type="number" step="0.01" class="im-val" value="${val}" placeholder="0.00"></td>
        <td><input type="number" step="0.01" class="im-debt" value="${debt}" placeholder="0.00"></td>
        <td><button class="btn-row-del" type="button" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

// Deudas Table functions
function addDeudaRow(acreedor='', minic='', saldo='', cuota='', venc='') {
    const tbody = document.querySelector('#tbl-otras-deudas tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="db-acreedor" value="${acreedor}" placeholder="Ej: Banco Pichincha"></td>
        <td><input type="number" step="0.01" class="db-minic" value="${minic}" placeholder="0.00"></td>
        <td><input type="number" step="0.01" class="db-saldo" value="${saldo}" placeholder="0.00"></td>
        <td><input type="number" step="0.01" class="db-cuota" value="${cuota}" placeholder="0.00"></td>
        <td><input type="date" class="db-venc" value="${venc}"></td>
        <td><button class="btn-row-del" type="button" onclick="this.parentElement.parentElement.remove()"><i class="fas fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

// Math calculations
function calcVentasCompras() {
    // Compute total sales & purchases days
    const activeDays = [
        document.getElementById('chk-dia-lun').checked,
        document.getElementById('chk-dia-mar').checked,
        document.getElementById('chk-dia-mie').checked,
        document.getElementById('chk-dia-jue').checked,
        document.getElementById('chk-dia-vie').checked
    ].filter(Boolean).length;
    
    const hasSab = document.getElementById('chk-dia-sab').checked;
    const hasDom = document.getElementById('chk-dia-dom').checked;

    const ventaLV = parseFloat(document.getElementById('f-venta-lv').value) || 0;
    const ventaSab = parseFloat(document.getElementById('f-venta-sab').value) || 0;
    const ventaDom = parseFloat(document.getElementById('f-venta-dom').value) || 0;
    
    const compraLV = parseFloat(document.getElementById('f-compra-lv').value) || 0;
    const compraSab = parseFloat(document.getElementById('f-compra-sab').value) || 0;
    const compraDom = parseFloat(document.getElementById('f-compra-dom').value) || 0;

    // Estimated monthly sales & purchases (x4 weeks)
    const monthlySales = ((ventaLV * activeDays) + (hasSab ? ventaSab : 0) + (hasDom ? ventaDom : 0)) * 4;
    const monthlyPurchases = ((compraLV * activeDays) + (hasSab ? compraSab : 0) + (hasDom ? compraDom : 0)) * 4;
    
    // Set flow calculations
    document.getElementById('lbl-sum-ventas').textContent = '$' + monthlySales.toFixed(2);
    document.getElementById('lbl-sum-compras').textContent = '$' + monthlyPurchases.toFixed(2);
    
    const grossMargin = monthlySales - monthlyPurchases;
    document.getElementById('lbl-sum-margen').textContent = '$' + grossMargin.toFixed(2);
    
    // Also autofill Cost of Sales field in final sheet
    document.getElementById('f-costo-ventas-final').value = monthlyPurchases.toFixed(2);
    
    updateSurplus();
}

function sumGastos() {
    // Business expenses
    const sueldos = parseFloat(document.getElementById('f-gneg-sueldos').value) || 0;
    const arriendoNeg = parseFloat(document.getElementById('f-gneg-arriendo').value) || 0;
    const servBasNeg = parseFloat(document.getElementById('f-gneg-serv-bas').value) || 0;
    const transNeg = parseFloat(document.getElementById('f-gneg-transporte').value) || 0;
    const mantNeg = parseFloat(document.getElementById('f-gneg-mantenimiento').value) || 0;
    const impNeg = parseFloat(document.getElementById('f-gneg-imprevistos').value) || 0;
    const otrosNeg = parseFloat(document.getElementById('f-gneg-otros').value) || 0;
    
    const totalNeg = sueldos + arriendoNeg + servBasNeg + transNeg + mantNeg + impNeg + otrosNeg;
    document.getElementById('f-gastos-negocio').value = totalNeg.toFixed(2);
    document.getElementById('lbl-sum-gastos-neg').textContent = '$' + totalNeg.toFixed(2);

    // Family expenses
    const alim = parseFloat(document.getElementById('f-gfam-alim').value) || 0;
    const arriendoFam = parseFloat(document.getElementById('f-gfam-arriendo').value) || 0;
    const servBasFam = parseFloat(document.getElementById('f-gfam-serv-bas').value) || 0;
    const educ = parseFloat(document.getElementById('f-gfam-educacion').value) || 0;
    const salud = parseFloat(document.getElementById('f-gfam-salud').value) || 0;
    const impFam = parseFloat(document.getElementById('f-gfam-imprevistos').value) || 0;
    const otrosFam = parseFloat(document.getElementById('f-gfam-otros').value) || 0;
    
    const totalFam = alim + arriendoFam + servBasFam + educ + salud + impFam + otrosFam;
    document.getElementById('f-gastos-familiares').value = totalFam.toFixed(2);
    document.getElementById('lbl-sum-gastos-fam').textContent = '$' + totalFam.toFixed(2);

    // Household other income
    const conyuge = parseFloat(document.getElementById('f-oing-conyuge').value) || 0;
    const arriendos = parseFloat(document.getElementById('f-oing-arriendos').value) || 0;
    const pensiones = parseFloat(document.getElementById('f-oing-pensiones').value) || 0;
    const otrosIng = parseFloat(document.getElementById('f-oing-otros').value) || 0;
    
    const totalIng = conyuge + arriendos + pensiones + otrosIng;
    document.getElementById('f-otros-ingresos').value = totalIng.toFixed(2);
    document.getElementById('lbl-sum-ing-fam').textContent = '$' + totalIng.toFixed(2);

    updateSurplus();
}

function sumBalance() {
    const caja = parseFloat(document.getElementById('f-caja-efectivo').value) || 0;
    const bancos = parseFloat(document.getElementById('f-bancos-saldo').value) || 0;
    const cxp = parseFloat(document.getElementById('f-cxp-netas').value) || 0;
    const mp = parseFloat(document.getElementById('f-inv-mat-prima').value) || 0;
    const ipp = parseFloat(document.getElementById('f-inv-prod-proc').value) || 0;
    
    const totalAct = caja + bancos + cxp + mp + ipp;
    document.getElementById('f-total-activos-liq').value = totalAct.toFixed(2);

    const cred = parseFloat(document.getElementById('f-creditos-pagar').value) || 0;
    const prov = parseFloat(document.getElementById('f-proveedores').value) || 0;
    const deudasCp = parseFloat(document.getElementById('f-otras-deudas-cp').value) || 0;
    const pasLp = parseFloat(document.getElementById('f-pasivos-lp').value) || 0;
    
    const totalPas = cred + prov + deudasCp + pasLp;
    document.getElementById('f-total-pasivos').value = totalPas.toFixed(2);
}

function updateSurplus() {
    const monthlySales = parseFloat(document.getElementById('lbl-sum-ventas').textContent.replace('$', '')) || 0;
    const monthlyPurchases = parseFloat(document.getElementById('lbl-sum-compras').textContent.replace('$', '')) || 0;
    const grossMargin = monthlySales - monthlyPurchases;
    
    const totalNeg = parseFloat(document.getElementById('f-gastos-negocio').value) || 0;
    const operatingMargin = grossMargin - totalNeg;
    document.getElementById('lbl-sum-util-oper').textContent = '$' + operatingMargin.toFixed(2);
    
    const totalIng = parseFloat(document.getElementById('f-otros-ingresos').value) || 0;
    const totalFam = parseFloat(document.getElementById('f-gastos-familiares').value) || 0;
    
    const surplus = operatingMargin + totalIng - totalFam;
    const excedenteLabel = document.getElementById('lbl-sum-excedente');
    excedenteLabel.textContent = '$' + surplus.toFixed(2);
    
    if (surplus < 0) {
        excedenteLabel.style.color = '#ef4444';
    } else {
        excedenteLabel.style.color = '#ffdd00';
    }
}

function validatePctSum() {
    const contado = parseFloat(document.getElementById('f-pct-contado').value) || 0;
    const credito = parseFloat(document.getElementById('f-pct-credito').value) || 0;
    const efectivo = parseFloat(document.getElementById('f-pct-efectivo').value) || 0;
    
    const total = contado + credito + efectivo;
    if (total > 100) {
        alert('La suma de porcentajes de ventas no puede superar el 100%.');
    }
}

// Navigation flow in wizard
function showStep(stepIndex) {
    const panes = document.querySelectorAll('.step-pane');
    panes.forEach((pane, idx) => {
        if (idx === stepIndex) pane.classList.add('active');
        else pane.classList.remove('active');
    });

    const steps = document.querySelectorAll('.step');
    steps.forEach((step, idx) => {
        step.classList.remove('active', 'done');
        if (idx === stepIndex) step.classList.add('active');
        else if (idx < stepIndex) step.classList.add('done');
    });

    // Toggle Prev/Next/Save buttons
    document.getElementById('btn-wizard-prev').style.display = stepIndex === 0 ? 'none' : 'inline-flex';
    document.getElementById('btn-wizard-next').style.display = stepIndex === (totalSteps - 1) ? 'none' : 'inline-flex';
    document.getElementById('btn-wizard-save').style.display = stepIndex === (totalSteps - 1) ? 'inline-flex' : 'none';
}

function moveStep(direction) {
    const nextStep = currentStep + direction;
    if (nextStep >= 0 && nextStep < totalSteps) {
        currentStep = nextStep;
        showStep(currentStep);
    }
}

function goToStep(stepIndex) {
    currentStep = stepIndex;
    showStep(currentStep);
}

function cancelSurvey() {
    if (confirm('¿Estás seguro de cancelar el levantamiento? Se perderán los cambios no guardados.')) {
        document.getElementById('section-search').style.display = 'block';
        document.getElementById('section-wizard').style.display = 'none';
        document.getElementById('formLevantamiento').reset();
    }
}

// Serialize dynamic tables into JSON before submitting
function serializeDynamicTables() {
    // 1. Products
    const type = document.getElementById('sel-tipo-productos').value;
    const prods = [];
    if (type === 'comercio') {
        const rows = document.querySelectorAll('#tbl-prod-comercio tbody tr');
        rows.forEach(r => {
            prods.push({
                nombre: r.querySelector('.pc-nombre').value,
                precio_costo: parseFloat(r.querySelector('.pc-costo').value) || 0,
                precio_venta: parseFloat(r.querySelector('.pc-venta').value) || 0,
                unidad_medida: r.querySelector('.pc-unidad').value,
                cantidad_inventario: parseInt(r.querySelector('.pc-stock').value) || 0
            });
        });
        document.getElementById('hid-comercio-productos-json').value = JSON.stringify(prods);
        document.getElementById('hid-productos-json').value = '';
    } else {
        const rows = document.querySelectorAll('#tbl-prod-produccion tbody tr');
        rows.forEach(r => {
            prods.push({
                nombre: r.querySelector('.pp-nombre').value,
                costo_materia_prima: parseFloat(r.querySelector('.pp-mp').value) || 0,
                costo_mano_obra: parseFloat(r.querySelector('.pp-mo').value) || 0,
                costo_empaque: parseFloat(r.querySelector('.pp-emp').value) || 0,
                precio_venta: parseFloat(r.querySelector('.pp-venta').value) || 0,
                produccion_diaria: parseInt(r.querySelector('.pp-prod').value) || 0,
                ventas_diarias: parseInt(r.querySelector('.pp-ventas-dia').value) || 0,
                cantidad_inventario: parseInt(r.querySelector('.pp-stock').value) || 0
            });
        });
        document.getElementById('hid-productos-json').value = JSON.stringify(prods);
        document.getElementById('hid-comercio-productos-json').value = '';
    }

    // 2. Business Assets
    const an = [];
    document.querySelectorAll('#tbl-activos-negocio tbody tr').forEach(r => {
        an.push({
            descripcion: r.querySelector('.as-desc').value,
            marca: r.querySelector('.as-brand').value,
            modelo: r.querySelector('.as-model').value,
            serie: r.querySelector('.as-serial').value,
            valor_estimado: parseFloat(r.querySelector('.as-val').value) || 0
        });
    });
    document.getElementById('hid-activos-negocio-json').value = JSON.stringify(an);

    // 3. Household Assets
    const ah = [];
    document.querySelectorAll('#tbl-activos-hogar tbody tr').forEach(r => {
        ah.push({
            descripcion: r.querySelector('.as-desc').value,
            marca: r.querySelector('.as-brand').value,
            modelo: r.querySelector('.as-model').value,
            serie: r.querySelector('.as-serial').value,
            valor_estimado: parseFloat(r.querySelector('.as-val').value) || 0
        });
    });
    document.getElementById('hid-activos-hogar-json').value = JSON.stringify(ah);

    // 4. Vehicles
    const vh = [];
    document.querySelectorAll('#tbl-vehiculos tbody tr').forEach(r => {
        vh.push({
            propietario: r.querySelector('.vh-owner').value,
            marca: r.querySelector('.vh-brand').value,
            modelo: r.querySelector('.vh-model').value,
            anio: parseInt(r.querySelector('.vh-year').value) || 0,
            placa: r.querySelector('.vh-plate').value,
            valor_comercial: parseFloat(r.querySelector('.vh-val').value) || 0,
            deuda_gravamen: parseFloat(r.querySelector('.vh-debt').value) || 0
        });
    });
    document.getElementById('hid-vehiculos-negocio-json').value = JSON.stringify(vh);

    // 5. Properties
    const inmu = [];
    document.querySelectorAll('#tbl-inmuebles tbody tr').forEach(r => {
        inmu.push({
            propietario: r.querySelector('.im-owner').value,
            tipo_propiedad: r.querySelector('.im-type').value,
            ubicacion: r.querySelector('.im-loc').value,
            valor_comercial: parseFloat(r.querySelector('.im-val').value) || 0,
            hipoteca_deuda: parseFloat(r.querySelector('.im-debt').value) || 0
        });
    });
    document.getElementById('hid-inmuebles-negocio-json').value = JSON.stringify(inmu);

    // 6. Other Deudas
    const deudas = [];
    document.querySelectorAll('#tbl-otras-deudas tbody tr').forEach(r => {
        deudas.push({
            acreedor: r.querySelector('.db-acreedor').value,
            monto_inicial: parseFloat(r.querySelector('.db-minic').value) || 0,
            saldo_pendiente: parseFloat(r.querySelector('.db-saldo').value) || 0,
            cuota_mensual: parseFloat(r.querySelector('.db-cuota').value) || 0,
            fecha_vencimiento: r.querySelector('.db-venc').value
        });
    });
    document.getElementById('hid-otras-deudas-json').value = JSON.stringify(deudas);
}

// Submit full survey form to backend
function submitSurvey() {
    const form = document.getElementById('formLevantamiento');
    if (!form.checkValidity()) {
        form.reportValidity();
        goToStep(0);
        return;
    }
    serializeDynamicTables();
    
    const formData = new FormData(form);
    

    
    const zone = document.getElementById('alert-zone');
    zone.style.display = 'none';
    
    document.getElementById('btn-wizard-save').disabled = true;
    
    fetch('../guardar_cliente_encuesta.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('btn-wizard-save').disabled = false;
        if (data.status === 'success') {
            zone.className = 'alert-banner alert-ok';
            zone.innerHTML = '<i class="fas fa-circle-check"></i> Levantamiento financiero de empresa guardado exitosamente.';
            zone.style.display = 'flex';
            
            // Auto scroll to top of viewport
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            // Redirect to dashboard after a delay
            setTimeout(() => {
                window.location.href = 'asesor_index.php';
            }, 2500);
        } else {
            zone.className = 'alert-banner alert-err';
            zone.innerHTML = `<i class="fas fa-triangle-exclamation"></i> Error al guardar levantamiento: ${data.message}`;
            zone.style.display = 'flex';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    })
    .catch(err => {
        document.getElementById('btn-wizard-save').disabled = false;
        console.error(err);
        zone.className = 'alert-banner alert-err';
        zone.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Error de conexión al guardar el formulario.';
        zone.style.display = 'flex';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}
</script>
</body>
</html>

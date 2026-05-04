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

$currentPage = 'encuesta';

/* helper */
function ynBlock(string $label, string $name, $value = null): string {
    $isYes = (string)$value === '1';
    $isNo  = $value !== null && (string)$value === '0';
    return '<div class="fld">
        <label>'.htmlspecialchars($label).'</label>
        <div class="yn-group">
            <label class="yn-opt '.($isYes?'checked':'').'">
                <input type="radio" name="'.$name.'" value="1" '.($isYes?'checked':'').'> Sí
            </label>
            <label class="yn-opt '.($isNo?'checked':'').'">
                <input type="radio" name="'.$name.'" value="0" '.($isNo?'checked':'').'> No
            </label>
        </div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nueva Encuesta — Asesor</title>
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

/* ── SEARCH BOX ──────────────────────────────────────── */
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

/* resultado búsqueda */
.found-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:30px;font-size:13px;font-weight:700;margin-top:12px;}
.found-chip.found{background:#d1fae5;color:#065f46;}
.found-chip.new{background:#fef3c7;color:#92400e;}
.found-chip i{font-size:14px;}

/* ── STEPPER ─────────────────────────────────────────── */
.stepper{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:16px 20px;margin-bottom:18px;box-shadow:var(--brand-shadow-sm);overflow-x:auto;gap:6px;}
.step{display:flex;align-items:center;gap:8px;flex:1;min-width:100px;cursor:pointer;}
.step .num{width:32px;height:32px;border-radius:50%;background:#e5e7eb;color:#6b7280;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0;transition:.2s;}
.step .lbl{font-size:11.5px;color:var(--brand-gray);font-weight:700;line-height:1.2;}
.step.active .num{background:var(--brand-yellow);color:var(--brand-navy-deep);box-shadow:0 4px 10px rgba(255,221,0,.45);}
.step.active .lbl{color:var(--brand-navy-deep);}
.step.done .num{background:#10b981;color:#fff;}
.step.done .lbl{color:#065f46;}
.step .line{flex:1;height:2px;background:#e5e7eb;margin:0 4px;}
.step:last-child .line{display:none;}

/* ── CARDS ───────────────────────────────────────────── */
.form-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:22px 24px;box-shadow:var(--brand-shadow-sm);margin-bottom:16px;}
.form-card h3{font-size:17px;font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:10px;}
.form-card h3 i{color:var(--brand-yellow-deep);}
.form-card .sub{color:var(--brand-gray);font-size:13.5px;margin-bottom:18px;}

/* ── VISIT CARDS ─────────────────────────────────────── */
.visit-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:10px;}
.visit-card{border:2.5px solid var(--brand-border);border-radius:14px;padding:20px 16px;cursor:pointer;text-align:center;transition:.22s;background:#fff;}
.visit-card:hover{border-color:var(--brand-yellow-deep);background:#fffde7;}
.visit-card.selected{border-color:var(--brand-yellow-deep);background:linear-gradient(135deg,#fffde7,#fff9c4);box-shadow:0 6px 20px rgba(255,221,0,.25);}
.visit-card .v-icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:22px;}
.visit-card.frio .v-icon{background:#e0f2fe;color:#0284c7;}
.visit-card.seguimiento .v-icon{background:#dcfce7;color:#16a34a;}
.visit-card.selected.frio .v-icon{background:#0284c7;color:#fff;}
.visit-card.selected.seguimiento .v-icon{background:#16a34a;color:#fff;}
.visit-card h4{font-size:15px;font-weight:800;margin-bottom:4px;color:var(--brand-navy-deep);}
.visit-card p{font-size:12.5px;color:var(--brand-gray);margin:0;}

/* ── ACTIVITY CHIPS ──────────────────────────────────── */
.chip-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;}
.chip{padding:10px 16px;border:2px solid var(--brand-border);border-radius:30px;cursor:pointer;font-size:13px;font-weight:700;color:#374151;background:#fff;transition:.2s;display:flex;align-items:center;gap:6px;}
.chip:hover{border-color:var(--brand-navy);color:var(--brand-navy);}
.chip.selected{background:var(--brand-navy-deep);color:#fff;border-color:var(--brand-navy-deep);}
.chip i{font-size:13px;}

/* ── FIELDS ──────────────────────────────────────────── */
.fld-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px 18px;}
.fld{display:flex;flex-direction:column;gap:5px;}
.fld label{font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;}
.fld input,.fld select,.fld textarea{padding:10px 12px;border:1.5px solid var(--brand-border);border-radius:10px;font-size:14px;font-family:inherit;background:#fff;transition:.2s;}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.fld textarea{resize:vertical;min-height:70px;}
.fld.full{grid-column:1/-1;}
.fld.half{grid-column:span 1;}

/* ── YN TOGGLE ───────────────────────────────────────── */
.yn-group{display:flex;gap:6px;}
.yn-opt{flex:1;padding:10px;text-align:center;border:1.5px solid var(--brand-border);border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;background:#fff;color:#374151;transition:.2s;}
.yn-opt:hover{background:#f3f4f6;}
.yn-opt input{display:none;}
.yn-opt.checked{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-color:transparent;}

/* sub-section divider */
.sub-sec{margin-top:20px;padding-top:16px;border-top:1px dashed #e5e7eb;}
.sub-sec h5{font-size:12px;text-transform:uppercase;color:var(--brand-navy);font-weight:800;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.sub-sec h5 i{color:var(--brand-yellow-deep);}

/* extras toggle */
.extras{display:none;margin-top:12px;padding:14px;background:#f8fafc;border-radius:10px;border:1px dashed #d7e0ea;}
.extras.show{display:block;}

/* info box (filled from search) */
.info-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#1e40af;font-weight:600;margin-bottom:16px;}
.info-banner i{font-size:16px;}

/* ── FOOTER ──────────────────────────────────────────── */
.form-footer{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:14px 18px;box-shadow:var(--brand-shadow-sm);position:sticky;bottom:14px;z-index:30;}
.btn{padding:11px 22px;border-radius:11px;font-weight:800;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn-yellow{background:var(--brand-yellow);color:var(--brand-navy-deep);}
.btn-yellow:hover{background:var(--brand-yellow-deep);}
.btn-ghost{background:#f3f4f6;color:#374151;}
.btn-ghost:hover{background:#e5e7eb;}
.btn-primary{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;}
.btn-primary:hover{opacity:.9;}

/* panes */
.step-pane{display:none;}
.step-pane.active{display:block;animation:fadein .22s ease;}
@keyframes fadein{from{opacity:0;transform:translateY(5px);}to{opacity:1;transform:none;}}

/* interest level */
.level-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:6px;}
.level-card{border:2px solid var(--brand-border);border-radius:12px;padding:12px;cursor:pointer;text-align:center;transition:.2s;}
.level-card:hover{border-color:var(--brand-navy);}
.level-card.selected{border-color:var(--brand-navy-deep);background:var(--brand-navy-deep);color:#fff;}
.level-card .lv-icon{font-size:20px;margin-bottom:4px;}
.level-card span{font-size:12px;font-weight:700;}
.level-card.ninguno.selected{border-color:#dc2626;background:#dc2626;}
.level-card.bajo.selected{border-color:#f59e0b;background:#f59e0b;}
.level-card.alto.selected{border-color:#10b981;background:#10b981;}

/* cuenta box */
.cuenta-box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-top:10px;}
.cuenta-box h6{font-size:11px;text-transform:uppercase;color:var(--brand-navy);font-weight:800;margin-bottom:10px;letter-spacing:.4px;}

@media (max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .main-content{margin-left:0;}
    .content-area{padding:14px;}
    .stepper{padding:10px;}
    .step{min-width:auto;}
    .step .lbl{display:none;}
    .form-card{padding:16px;}
    .fld-grid{grid-template-columns:1fr;}
    .visit-grid{grid-template-columns:1fr;}
    .level-grid{grid-template-columns:repeat(3,1fr);}
}
</style>
</head>
<body>

<?php require __DIR__ . '/_sidebar_asesor.php'; ?>

<div class="main-content">
    <div class="navbar-custom">
        <h2><i class="fas fa-clipboard-list"></i> Nueva Encuesta</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($asesor_nombre) ?></strong></div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>

    <div class="content-area">

        <!-- ══ BÚSQUEDA POR CÉDULA ══ -->
        <div class="search-card">
            <h3><i class="fas fa-magnifying-glass"></i>Buscar prospecto / cliente</h3>
            <p class="sub">Ingresa la cédula para cargar los datos existentes, o llena el formulario si es nuevo.</p>
            <div class="search-row">
                <input type="text" id="inp-cedula" placeholder="Ej: 1712345678" maxlength="13"
                       inputmode="numeric" pattern="[0-9]*">
                <button class="btn-search" id="btn-buscar" type="button">
                    <i class="fas fa-search"></i> Buscar
                </button>
            </div>
            <div id="search-result" style="display:none;"></div>
        </div>

        <!-- ══ STEPPER ══ -->
        <div class="stepper" id="stepper" style="display:none;">
            <?php
            $steps = [
                ['Tipo visita',  'fa-route'],
                ['Datos person.','fa-user'],
                ['Actividad',    'fa-briefcase'],
                ['Sit. financ.', 'fa-piggy-bank'],
                ['Interés',      'fa-star'],
                ['Acuerdo',      'fa-handshake'],
            ];
            foreach ($steps as $i => [$lbl, $ico]): ?>
                <div class="step <?= $i===0?'active':'' ?>" data-step="<?= $i ?>">
                    <div class="num"><i class="fas <?= $ico ?>" style="font-size:12px;"></i></div>
                    <div class="lbl"><?= $lbl ?></div>
                    <div class="line"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- ══ FORMULARIO ══ -->
        <form id="formEncuesta" method="post" action="guardar_encuesta.php" autocomplete="off"
              style="display:none;">
            <input type="hidden" name="cliente_id"     id="hid-cliente_id">
            <input type="hidden" name="tipo_prospecto" id="hid-tipo_prospecto">
            <input type="hidden" name="actividad"      id="hid-actividad">
            <input type="hidden" name="nivel_interes"  id="hid-nivel_interes">
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">
            <input type="hidden" name="asesor_id" value="<?= $asesor_table_id ?>">

            <!-- ══════════════════════════════════════
                 PASO 1 — TIPO DE VISITA
            ═══════════════════════════════════════ -->
            <div class="step-pane active" data-pane="0">
                <div class="form-card">
                    <h3><i class="fas fa-route"></i>Tipo de visita</h3>
                    <p class="sub">¿Es la primera vez que contactas a este prospecto o es un seguimiento previo?</p>
                    <div class="visit-grid">
                        <div class="visit-card frio" data-tipo="frio" onclick="selectVisita(this)">
                            <div class="v-icon"><i class="fas fa-snowflake"></i></div>
                            <h4>Visita en frío</h4>
                            <p>Primer contacto con el prospecto. No hay relación previa.</p>
                        </div>
                        <div class="visit-card seguimiento" data-tipo="seguimiento" onclick="selectVisita(this)">
                            <div class="v-icon"><i class="fas fa-arrows-rotate"></i></div>
                            <h4>Seguimiento</h4>
                            <p>Ya existe contacto o visita anterior con este prospecto.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 2 — DATOS PERSONALES
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="1">
                <div class="form-card">
                    <h3><i class="fas fa-user"></i>Datos personales</h3>
                    <p class="sub">Verifica o completa la información del prospecto. Los campos marcados * son obligatorios.</p>

                    <div id="info-cargado" class="info-banner" style="display:none;">
                        <i class="fas fa-circle-check"></i>
                        <span>Datos cargados desde la base. Puedes editarlos si es necesario.</span>
                    </div>

                    <div class="fld-grid">
                        <div class="fld full">
                            <label>Nombre completo *</label>
                            <input type="text" name="nombre" id="f-nombre" required placeholder="Nombre y apellidos">
                        </div>
                        <div class="fld">
                            <label>Cédula *</label>
                            <input type="text" name="cedula" id="f-cedula" required placeholder="Cédula de identidad">
                        </div>
                        <div class="fld">
                            <label>Cédula cónyuge</label>
                            <input type="text" name="cedula_conyuge" id="f-cedula_conyuge" placeholder="Opcional">
                        </div>
                        <div class="fld">
                            <label>Celular *</label>
                            <input type="tel" name="celular" id="f-celular" required placeholder="09XXXXXXXX">
                        </div>
                        <div class="fld">
                            <label>Teléfono convencional</label>
                            <input type="tel" name="telefono" id="f-telefono" placeholder="02XXXXXXX">
                        </div>
                        <div class="fld">
                            <label>Email</label>
                            <input type="email" name="email" id="f-email" placeholder="correo@ejemplo.com">
                        </div>
                        <div class="fld full">
                            <label>Dirección</label>
                            <input type="text" name="direccion" id="f-direccion" placeholder="Calle, número, referencias">
                        </div>
                        <div class="fld">
                            <label>Ciudad</label>
                            <input type="text" name="ciudad" id="f-ciudad" placeholder="Ciudad">
                        </div>
                        <div class="fld">
                            <label>Zona / Barrio</label>
                            <input type="text" name="zona" id="f-zona" placeholder="Zona o sector">
                        </div>
                        <div class="fld">
                            <label>Estado</label>
                            <select name="estado" id="f-estado">
                                <option value="prospecto">Prospecto</option>
                                <option value="cliente">Cliente</option>
                                <option value="pendiente">Pendiente</option>
                                <option value="descartado">Descartado</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 3 — ACTIVIDAD + RÉGIMEN
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="2">
                <div class="form-card">
                    <h3><i class="fas fa-briefcase"></i>Actividad económica</h3>
                    <p class="sub">Selecciona la actividad principal del prospecto.</p>
                    <div class="chip-grid" id="chips-actividad">
                        <div class="chip" data-val="negocio_propio" onclick="toggleChip(this,'actividad')">
                            <i class="fas fa-store"></i> Negocio propio
                        </div>
                        <div class="chip" data-val="empleado_privado" onclick="toggleChip(this,'actividad')">
                            <i class="fas fa-building"></i> Empleado privado
                        </div>
                        <div class="chip" data-val="empleado_publico" onclick="toggleChip(this,'actividad')">
                            <i class="fas fa-landmark"></i> Empleado público
                        </div>
                        <div class="chip" data-val="profesional" onclick="toggleChip(this,'actividad')">
                            <i class="fas fa-user-tie"></i> Profesional independiente
                        </div>
                        <div class="chip" data-val="otro" onclick="toggleChip(this,'actividad')">
                            <i class="fas fa-ellipsis"></i> Otro
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <h3><i class="fas fa-file-invoice"></i>Régimen tributario</h3>
                    <p class="sub">Indica el régimen bajo el que opera el prospecto.</p>
                    <div class="fld-grid">
                        <?= ynBlock('¿Tiene RUC?',  'tiene_ruc') ?>
                        <?= ynBlock('¿Tiene RISE?', 'tiene_rise') ?>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-shop"></i>Empresa</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Tiene empresa?', 'tiene_empresa') ?>
                        </div>
                        <div id="extras-empresa" class="extras">
                            <div class="fld-grid">
                                <div class="fld">
                                    <label>Nombre de la empresa / negocio</label>
                                    <input type="text" name="nombre_empresa" id="f-nombre_empresa"
                                           placeholder="Razón social o nombre comercial">
                                </div>
                                <div class="fld">
                                    <label>Actividad de la empresa</label>
                                    <input type="text" name="actividad_empresa"
                                           placeholder="Ej: comercio, manufactura…">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 4 — SITUACIÓN FINANCIERA
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="3">
                <div class="form-card">
                    <h3><i class="fas fa-piggy-bank"></i>Situación financiera</h3>
                    <p class="sub">Productos financieros que actualmente tiene el prospecto.</p>

                    <!-- Cuenta de ahorro -->
                    <div class="sub-sec" style="border-top:none;padding-top:0;margin-top:0;">
                        <h5><i class="fas fa-wallet"></i>Cuenta de ahorro</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Mantiene cuenta de ahorro?', 'ec_mantiene_cuenta_ahorro') ?>
                        </div>
                        <div id="extras-ahorro" class="extras">
                            <div class="cuenta-box">
                                <h6><i class="fas fa-bank"></i> Detalle cuenta ahorro</h6>
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Institución</label>
                                        <input type="text" name="ec_institucion_ahorro" placeholder="Banco / Cooperativa">
                                    </div>
                                    <div class="fld">
                                        <label>Saldo aprox. (USD)</label>
                                        <input type="number" step="0.01" min="0" name="ec_saldo_ahorro">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cuenta corriente -->
                    <div class="sub-sec">
                        <h5><i class="fas fa-credit-card"></i>Cuenta corriente</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Mantiene cuenta corriente?', 'ec_mantiene_cuenta_corriente') ?>
                        </div>
                        <div id="extras-corriente" class="extras">
                            <div class="cuenta-box">
                                <h6><i class="fas fa-bank"></i> Detalle cuenta corriente</h6>
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Institución</label>
                                        <input type="text" name="ec_institucion_corriente" placeholder="Banco / Cooperativa">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inversiones -->
                    <div class="sub-sec">
                        <h5><i class="fas fa-chart-line"></i>Inversiones</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Tiene inversiones?', 'ec_tiene_inversiones') ?>
                        </div>
                        <div id="extras-inversiones" class="extras">
                            <div class="cuenta-box">
                                <h6><i class="fas fa-bank"></i> Detalle inversión</h6>
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Institución</label>
                                        <input type="text" name="ec_institucion_inversiones" placeholder="Banco / Cooperativa">
                                    </div>
                                    <div class="fld">
                                        <label>Valor (USD)</label>
                                        <input type="number" step="0.01" min="0" name="ec_valor_inversion">
                                    </div>
                                    <div class="fld">
                                        <label>Plazo</label>
                                        <input type="text" name="ec_plazo_inversion" placeholder="Ej: 6 meses">
                                    </div>
                                    <div class="fld">
                                        <label>Fecha vencimiento</label>
                                        <input type="date" name="ec_fecha_vencimiento_inversion">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Operaciones crediticias -->
                    <div class="sub-sec">
                        <h5><i class="fas fa-hand-holding-dollar"></i>Operaciones crediticias</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Tiene crédito activo?', 'ec_tiene_operaciones_crediticias') ?>
                        </div>
                        <div id="extras-credito" class="extras">
                            <div class="cuenta-box">
                                <h6><i class="fas fa-bank"></i> Detalle crédito activo</h6>
                                <div class="fld-grid">
                                    <div class="fld">
                                        <label>Institución del crédito</label>
                                        <input type="text" name="ec_institucion_credito" placeholder="Banco / Cooperativa">
                                    </div>
                                    <div class="fld">
                                        <label>Monto aprox. (USD)</label>
                                        <input type="number" step="0.01" min="0" name="ec_monto_credito_actual">
                                    </div>
                                    <div class="fld">
                                        <label>Destino del crédito</label>
                                        <select name="ec_destino_credito_actual">
                                            <option value="">—</option>
                                            <option value="capital_trabajo">Capital de trabajo</option>
                                            <option value="activos_fijos">Activos fijos</option>
                                            <option value="consumo">Consumo</option>
                                            <option value="pago_deudas">Pago de deudas</option>
                                            <option value="otro">Otro</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 5 — INTERÉS EN SERVICIOS
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="4">
                <div class="form-card">
                    <h3><i class="fas fa-star"></i>Interés en nuestros servicios</h3>
                    <p class="sub">¿Cuánto interés mostró el prospecto durante la visita?</p>

                    <div class="level-grid" id="level-grid">
                        <div class="level-card ninguno" data-val="ninguno" onclick="selectLevel(this)">
                            <div class="lv-icon">😐</div>
                            <span>Ninguno</span>
                        </div>
                        <div class="level-card bajo" data-val="bajo" onclick="selectLevel(this)">
                            <div class="lv-icon">🙂</div>
                            <span>Bajo</span>
                        </div>
                        <div class="level-card alto" data-val="alto" onclick="selectLevel(this)">
                            <div class="lv-icon">🔥</div>
                            <span>Alto</span>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-tags"></i>Productos de interés</h5>
                        <p style="font-size:13px;color:var(--brand-gray);margin-bottom:12px;">¿En cuáles de nuestros productos mostró interés?</p>
                        <div class="fld-grid">
                            <?= ynBlock('Interés en Cuenta de Ahorro', 'ec_interes_ahorro') ?>
                            <?= ynBlock('Interés en Cuenta Corriente', 'ec_interes_cc') ?>
                            <?= ynBlock('Interés en Inversión / Depósito', 'ec_interes_inversion') ?>
                            <?= ynBlock('Interés en Crédito', 'ec_interes_credito') ?>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-circle-xmark"></i>Razones para no contratar</h5>
                        <p style="font-size:13px;color:var(--brand-gray);margin-bottom:12px;">Si el prospecto no mostró interés, ¿cuál fue la razón?</p>
                        <div class="fld-grid">
                            <?= ynBlock('Ya trabaja con la institución',  'ec_razon_ya_trabaja') ?>
                            <?= ynBlock('Desconfía de los servicios',     'ec_razon_desconfia') ?>
                            <?= ynBlock('Está a gusto con su banco actual','ec_razon_agusto_actual') ?>
                            <?= ynBlock('Mala experiencia previa',        'ec_razon_mala_experiencia') ?>
                            <div class="fld full">
                                <label>Otras razones</label>
                                <textarea name="ec_razon_otros" rows="2" placeholder="Describe brevemente…"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════
                 PASO 6 — ACUERDO Y CIERRE
            ═══════════════════════════════════════ -->
            <div class="step-pane" data-pane="5">
                <div class="form-card">
                    <h3><i class="fas fa-handshake"></i>Acuerdo y cierre</h3>
                    <p class="sub">Indica el resultado de la visita y el próximo paso pactado con el prospecto.</p>
                    <div class="fld-grid">
                        <div class="fld full">
                            <label>Resultado / Acuerdo logrado *</label>
                            <select name="acuerdo_logrado" required>
                                <option value="">— Selecciona el resultado —</option>
                                <option value="nueva_cita_campo">Nueva cita en campo</option>
                                <option value="nueva_cita_oficina">Nueva cita en oficina</option>
                                <option value="reprogramacion">Reprogramación</option>
                                <option value="seguimiento_telefonico">Seguimiento telefónico</option>
                                <option value="solicitud_credito">Solicitud de crédito</option>
                                <option value="apertura_cuenta">Apertura de cuenta</option>
                                <option value="sin_interes">Sin interés por ahora</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div class="fld">
                            <label>Fecha del acuerdo</label>
                            <input type="date" name="fecha_acuerdo">
                        </div>
                        <div class="fld">
                            <label>Hora</label>
                            <input type="time" name="hora_acuerdo">
                        </div>
                        <div class="fld">
                            <label>Fecha próximo contacto</label>
                            <input type="date" name="fecha_nuevo_contacto">
                        </div>
                        <div class="fld full">
                            <label>Observaciones generales</label>
                            <textarea name="observaciones" rows="4"
                                      placeholder="Anota cualquier detalle relevante de la visita…"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ FOOTER NAVEGACIÓN ══ -->
            <div class="form-footer">
                <button type="button" class="btn btn-ghost" id="btn-prev">
                    <i class="fas fa-arrow-left"></i> Anterior
                </button>
                <div style="font-size:13px;color:var(--brand-gray);">
                    Paso <span id="step-num">1</span> de 6
                </div>
                <div>
                    <button type="button" class="btn btn-yellow" id="btn-next">
                        Siguiente <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="btn btn-primary" id="btn-save" style="display:none;">
                        <i class="fas fa-circle-check"></i> Guardar encuesta
                    </button>
                </div>
            </div>
        </form>

    </div><!-- /content-area -->
</div><!-- /main-content -->

<script>
/* ══════════════════════════════════════════════════════
   BÚSQUEDA POR CÉDULA
══════════════════════════════════════════════════════ */
const btnBuscar   = document.getElementById('btn-buscar');
const inpCedula   = document.getElementById('inp-cedula');
const searchRes   = document.getElementById('search-result');
const stepper     = document.getElementById('stepper');
const formEnc     = document.getElementById('formEncuesta');

btnBuscar.addEventListener('click', buscarCedula);
inpCedula.addEventListener('keydown', e => { if (e.key === 'Enter') buscarCedula(); });

async function buscarCedula() {
    const ced = inpCedula.value.trim();
    if (!ced) { showMsg('Ingresa una cédula primero.', 'warning'); return; }
    btnBuscar.disabled = true;
    btnBuscar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando…';

    try {
        const fd = new FormData();
        fd.append('cedula', ced);
        const res  = await fetch('../buscar_prospecto_por_cedula.php', { method:'POST', body:fd });
        const data = await res.json();

        if (data.status === 'found') {
            // Mostrar chip "encontrado"
            searchRes.innerHTML = `
                <div class="found-chip found">
                    <i class="fas fa-circle-check"></i>
                    Encontrado: <strong>${escHtml(data.data.nombre || '')}</strong>
                    &nbsp;·&nbsp; ${data.tipo === 'cliente' ? 'Cliente' : 'Prospecto'}
                </div>`;
            searchRes.style.display = 'block';

            // Pre-llenar campos
            fillField('f-nombre',        data.data.nombre);
            fillField('f-cedula',        data.data.cedula);
            fillField('f-celular',       data.data.celular);
            fillField('f-telefono',      data.data.telefono);
            fillField('f-email',         data.data.email);
            fillField('f-direccion',     data.data.direccion);
            fillField('f-ciudad',        data.data.ciudad);
            fillField('f-zona',          data.data.zona);
            fillField('f-nombre_empresa',data.data.nombre_empresa);
            if (data.data.estado) setSelect('f-estado', data.data.estado);

            // Actividad preseleccionada
            if (data.data.actividad) {
                const chip = document.querySelector(`#chips-actividad [data-val="${data.data.actividad}"]`);
                if (chip) { chip.classList.add('selected'); document.getElementById('hid-actividad').value = data.data.actividad; }
            }

            // id oculto
            document.getElementById('hid-cliente_id').value = data.data.id || '';
            document.getElementById('info-cargado').style.display = 'flex';

        } else {
            // no encontrado — nuevo prospecto
            searchRes.innerHTML = `
                <div class="found-chip new">
                    <i class="fas fa-user-plus"></i>
                    Cédula no registrada. Llena los datos del nuevo prospecto.
                </div>`;
            searchRes.style.display = 'block';
            fillField('f-cedula', ced);
            document.getElementById('hid-cliente_id').value = '';
            document.getElementById('info-cargado').style.display = 'none';
        }

        // Mostrar stepper + form
        stepper.style.display  = 'flex';
        formEnc.style.display  = 'block';
        show(0);
        stepper.scrollIntoView({behavior:'smooth', block:'start'});

    } catch(err) {
        showMsg('Error al buscar. Inténtalo de nuevo.', 'danger');
    } finally {
        btnBuscar.disabled = false;
        btnBuscar.innerHTML = '<i class="fas fa-search"></i> Buscar';
    }
}

function fillField(id, val) {
    const el = document.getElementById(id);
    if (el && val) el.value = val;
}
function setSelect(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val;
}
function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function showMsg(msg, type='info') {
    searchRes.innerHTML = `<div class="alert alert-${type} mt-2" style="font-size:13px;">${msg}</div>`;
    searchRes.style.display = 'block';
}

/* ══════════════════════════════════════════════════════
   STEPPER NAVIGATION
══════════════════════════════════════════════════════ */
const panes = document.querySelectorAll('.step-pane');
const stepEls = document.querySelectorAll('.step');
let cur = 0;

function show(i) {
    cur = Math.max(0, Math.min(panes.length - 1, i));
    panes.forEach((p, idx) => p.classList.toggle('active', idx === cur));
    stepEls.forEach((s, idx) => {
        s.classList.toggle('active', idx === cur);
        s.classList.toggle('done',   idx < cur);
    });
    document.getElementById('step-num').textContent = cur + 1;
    document.getElementById('btn-prev').style.visibility = cur === 0 ? 'hidden' : 'visible';
    const isLast = cur === panes.length - 1;
    document.getElementById('btn-next').style.display = isLast ? 'none' : 'inline-flex';
    document.getElementById('btn-save').style.display = isLast ? 'inline-flex' : 'none';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

document.getElementById('btn-prev').onclick = () => show(cur - 1);
document.getElementById('btn-next').onclick = () => {
    // Validar paso 0: tipo visita seleccionado
    if (cur === 0 && !document.getElementById('hid-tipo_prospecto').value) {
        alert('Selecciona el tipo de visita para continuar.');
        return;
    }
    show(cur + 1);
};
stepEls.forEach((s, idx) => s.addEventListener('click', () => show(idx)));

/* ══════════════════════════════════════════════════════
   TIPO VISITA
══════════════════════════════════════════════════════ */
function selectVisita(el) {
    document.querySelectorAll('.visit-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('hid-tipo_prospecto').value = el.dataset.tipo;
}

/* ══════════════════════════════════════════════════════
   ACTIVITY CHIPS (single-select)
══════════════════════════════════════════════════════ */
function toggleChip(el, field) {
    const container = el.closest('.chip-grid');
    container.querySelectorAll('.chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('hid-' + field).value = el.dataset.val;

    // Mostrar extras empresa si selecciona negocio_propio
    const extraEmp = document.getElementById('extras-empresa');
    if (extraEmp) {
        extraEmp.classList.toggle('show', ['negocio_propio','profesional'].includes(el.dataset.val));
    }
}

/* ══════════════════════════════════════════════════════
   YN TOGGLE
══════════════════════════════════════════════════════ */
document.querySelectorAll('.yn-group').forEach(g => {
    g.querySelectorAll('.yn-opt').forEach(o => {
        o.addEventListener('click', () => {
            g.querySelectorAll('.yn-opt').forEach(x => x.classList.remove('checked'));
            o.classList.add('checked');
            o.querySelector('input').checked = true;

            // Mostrar extras según radio
            const name = o.querySelector('input').name;
            const val  = o.querySelector('input').value;
            const extrasMap = {
                'ec_mantiene_cuenta_ahorro':      'extras-ahorro',
                'ec_mantiene_cuenta_corriente':   'extras-corriente',
                'ec_tiene_inversiones':           'extras-inversiones',
                'ec_tiene_operaciones_crediticias':'extras-credito',
                'tiene_empresa':                  'extras-empresa',
            };
            if (extrasMap[name]) {
                const box = document.getElementById(extrasMap[name]);
                if (box) box.classList.toggle('show', val === '1');
            }
        });
    });
});

/* ══════════════════════════════════════════════════════
   NIVEL DE INTERÉS
══════════════════════════════════════════════════════ */
function selectLevel(el) {
    document.querySelectorAll('.level-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('hid-nivel_interes').value = el.dataset.val;
}

/* ══════════════════════════════════════════════════════
   GEO
══════════════════════════════════════════════════════ */
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        p => {
            document.getElementById('lat').value = p.coords.latitude;
            document.getElementById('lng').value = p.coords.longitude;
        },
        () => {},
        { timeout: 5000 }
    );
}
</script>
</body>
</html>

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

/* Régimen tiles (mobile-like) */
.regimen-tiles{display:flex;flex-direction:column;gap:10px;}
.regimen-tile{display:flex;align-items:center;gap:12px;border:1px solid var(--brand-border);border-radius:12px;padding:14px;cursor:pointer;background:#fff;transition:.18s;}
.regimen-tile .rt-left{width:44px;height:44px;border-radius:10px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:18px;color:#374151}
.regimen-tile .rt-body{flex:1}
.regimen-tile .rt-title{font-weight:800;color:var(--brand-navy-deep);}
.regimen-tile .rt-sub{font-size:13px;color:var(--brand-gray);}
.regimen-tile.selected{border-color:var(--brand-yellow);background:linear-gradient(90deg,#fff8e6,#fffaf0)}

/* Question cards for RUC/RISE */
.q-cards{display:flex;flex-direction:column;gap:12px}
.q-card{background:#fff;border:1px solid var(--brand-border);border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:8px}
.q-label{font-weight:800;color:var(--brand-navy-deep)}
.q-field input{width:100%;padding:10px;border:1px solid #e6eef8;border-radius:10px}
.q-actions{display:flex;gap:8px;flex-wrap:wrap}
.q-btn{border:1px solid var(--brand-border);background:#fff;padding:8px 12px;border-radius:999px;cursor:pointer;font-weight:700}
.q-btn.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);border-color:transparent}

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
            <input type="hidden" name="usuario_id" value="<?= $asesor_usuario_id ?>">

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

                    <!-- Tiles selector (RUC / RISE / No registrado) -->
                    <div class="regimen-tiles" style="margin-top:12px;">
                        <label class="regimen-tile" data-val="ruc">
                            <input type="radio" name="regimen_type" value="ruc" style="display:none;">
                            <div class="rt-left"><i class="fas fa-file-invoice"></i></div>
                            <div class="rt-body">
                                <div class="rt-title">RUC</div>
                                <div class="rt-sub">Régimen general</div>
                            </div>
                        </label>
                        <label class="regimen-tile" data-val="rise">
                            <input type="radio" name="regimen_type" value="rise" style="display:none;">
                            <div class="rt-left"><i class="fas fa-cube"></i></div>
                            <div class="rt-body">
                                <div class="rt-title">RISE</div>
                                <div class="rt-sub">Régimen simplificado</div>
                            </div>
                        </label>
                        <label class="regimen-tile" data-val="none">
                            <input type="radio" name="regimen_type" value="none" style="display:none;">
                            <div class="rt-left"><i class="far fa-square"></i></div>
                            <div class="rt-body">
                                <div class="rt-title">No está registrado</div>
                                <div class="rt-sub">Sin régimen</div>
                            </div>
                        </label>
                    </div>
                    <!-- compatibility hidden flags for backend -->
                    <input type="hidden" name="tiene_ruc" value="">
                    <input type="hidden" name="tiene_rise" value="">

                    <!-- Question-style cards for RUC -->
                    <div id="q-ruc" class="q-cards" style="margin-top:12px; display:none;">
                        <div class="q-card">
                            <div class="q-label">Número de RUC (opcional)</div>
                            <div class="q-field"><input type="text" name="ruc_numero" placeholder="RUC"></div>
                        </div>
                        <div class="q-card">
                            <div class="q-label">¿Declara IVA mensualmente?</div>
                            <div class="q-actions">
                                <button type="button" class="q-btn" data-name="ruc_declara_iva" data-val="1">Sí</button>
                                <button type="button" class="q-btn" data-name="ruc_declara_iva" data-val="0">No</button>
                                <button type="button" class="q-btn" data-name="ruc_declara_iva" data-val="">Sin respuesta</button>
                            </div>
                            <input type="hidden" name="ruc_declara_iva" value="">
                        </div>
                        <div class="q-card">
                            <div class="q-label">¿Emite facturas electrónicas?</div>
                            <div class="q-actions">
                                <button type="button" class="q-btn" data-name="ruc_emite_factura" data-val="1">Sí</button>
                                <button type="button" class="q-btn" data-name="ruc_emite_factura" data-val="0">No</button>
                                <button type="button" class="q-btn" data-name="ruc_emite_factura" data-val="">Sin respuesta</button>
                            </div>
                            <input type="hidden" name="ruc_emite_factura" value="">
                        </div>
                        <div class="q-card">
                            <div class="q-label">¿Lleva contabilidad?</div>
                            <div class="q-actions">
                                <button type="button" class="q-btn" data-name="ruc_lleva_contabilidad" data-val="1">Sí</button>
                                <button type="button" class="q-btn" data-name="ruc_lleva_contabilidad" data-val="0">No</button>
                                <button type="button" class="q-btn" data-name="ruc_lleva_contabilidad" data-val="">Sin respuesta</button>
                            </div>
                            <input type="hidden" name="ruc_lleva_contabilidad" value="">
                        </div>
                    </div>

                    <!-- Question-style cards for RISE -->
                    <div id="q-rise" class="q-cards" style="margin-top:12px; display:none;">
                        <div class="q-card">
                            <div class="q-label">Número RISE (opcional)</div>
                            <div class="q-field"><input type="text" name="rise_numero" placeholder="RISE"></div>
                        </div>
                        <div class="q-card">
                            <div class="q-label">¿Paga su cuota mensual del RISE?</div>
                            <div class="q-actions">
                                <button type="button" class="q-btn" data-name="rise_paga_cuota" data-val="1">Sí</button>
                                <button type="button" class="q-btn" data-name="rise_paga_cuota" data-val="0">No</button>
                                <button type="button" class="q-btn" data-name="rise_paga_cuota" data-val="">Sin respuesta</button>
                            </div>
                            <input type="hidden" name="rise_paga_cuota" value="">
                        </div>
                        <div class="q-card">
                            <div class="q-label">¿Emite notas de venta?</div>
                            <div class="q-actions">
                                <button type="button" class="q-btn" data-name="rise_emite_notas" data-val="1">Sí</button>
                                <button type="button" class="q-btn" data-name="rise_emite_notas" data-val="0">No</button>
                                <button type="button" class="q-btn" data-name="rise_emite_notas" data-val="">Sin respuesta</button>
                            </div>
                            <input type="hidden" name="rise_emite_notas" value="">
                        </div>
                        <div class="q-card">
                            <div class="q-label">¿Conoce el límite de ingresos del RISE?</div>
                            <div class="q-actions">
                                <button type="button" class="q-btn" data-name="rise_conoce_limite" data-val="1">Sí</button>
                                <button type="button" class="q-btn" data-name="rise_conoce_limite" data-val="0">No</button>
                                <button type="button" class="q-btn" data-name="rise_conoce_limite" data-val="">Sin respuesta</button>
                            </div>
                            <input type="hidden" name="rise_conoce_limite" value="">
                        </div>
                        <div class="q-card">
                            <div class="q-label">Ingreso aproximado (RISE) — opcional</div>
                            <div class="q-field"><input type="number" step="0.01" min="0" name="rise_ingreso_aprox" placeholder="USD"></div>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-shop"></i>Empresa</h5>
                        <div class="fld-grid" id="fld-tiene_empresa">
                            <?= ynBlock('¿Tiene empresa?', 'tiene_empresa') ?>
                        </div>
                        <div id="extras-empresa" class="extras">
                            <div class="fld" style="position:relative;">
                                <label>Nombre de la empresa</label>
                                <input type="text" name="nombre_empresa" id="f-nombre_empresa" placeholder="Escribe para buscar empresa...">
                                <!-- Dropdown de búsqueda de empresas -->
                                <div id="empresa-search-dropdown" style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--brand-border);border-top:none;border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;z-index:1000;display:none;box-shadow:0 4px 12px rgba(0,0,0,.1);">
                                    <div id="empresa-search-list" style="list-style:none;margin:0;padding:0;"></div>
                                </div>
                            </div>
                            <div class="fld">
                                <label>Tipo de empresa</label>
                                <select name="actividad_empresa" id="f-actividad_empresa">
                                    <option value="">—</option>
                                    <option value="produccion_servicio">Producción / Servicio</option>
                                    <option value="comercio">Comercio</option>
                                </select>
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
                            <div class="fld-grid">
                                <div class="fld">
                                    <label>Institución</label>
                                    <input type="text" name="ah_institucion_ahorro" placeholder="Banco / Cooperativa">
                                </div>
                                <div class="fld">
                                    <label>Saldo aprox. (USD)</label>
                                    <input type="number" step="0.01" min="0" name="ah_monto_inicial">
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
                            <div class="fld-grid">
                                <div class="fld">
                                    <label>Institución</label>
                                    <input type="text" name="cc_institucion_cc" placeholder="Banco / Cooperativa">
                                </div>
                                <div class="fld">
                                    <label>Saldo aprox. (USD)</label>
                                    <input type="number" step="0.01" min="0" name="cc_monto_deposito_prom">
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
                            <div class="fld-grid">
                                <div class="fld">
                                    <label>Institución</label>
                                    <input type="text" name="inv_institucion_competencia" placeholder="Banco / Cooperativa">
                                </div>
                                <div class="fld">
                                    <label>Monto aproximado (USD)</label>
                                    <input type="number" step="0.01" min="0" name="inv_monto_inversion">
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
                            <div class="fld-grid">
                                <div class="fld">
                                    <label>Institución del crédito</label>
                                    <input type="text" name="cred_institucion" placeholder="Banco / Cooperativa">
                                </div>
                                <div class="fld">
                                    <label>Monto aprox. (USD)</label>
                                    <input type="number" step="0.01" min="0" name="cred_monto_credito">
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

                        <div class="fld full" style="margin-top:16px;">
                            <label style="font-weight:700;">Solicitar apertura / interés operativo</label>
                            <p style="font-size:12px;color:var(--brand-gray);margin:6px 0;">Marcar si el prospecto desea que tramitemos la apertura/solicitud desde la sucursal. Al marcar, se deberá llenar la ficha de solicitud.</p>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;">
                                <label style="font-weight:700;"><input type="checkbox" name="solicitar_ahorro" id="solicitar_ahorro" onchange="toggleFicha('ahorro')"> Apertura cuenta de ahorro</label>
                                <label style="font-weight:700;"><input type="checkbox" name="solicitar_corriente" id="solicitar_corriente" onchange="toggleFicha('corriente')"> Apertura cuenta corriente</label>
                                <label style="font-weight:700;"><input type="checkbox" name="solicitar_inversion" id="solicitar_inversion" onchange="toggleFicha('inversion')"> Solicitar inversión</label>
                                <label style="font-weight:700;"><input type="checkbox" name="solicitar_credito" id="solicitar_credito" onchange="toggleFicha('credito')"> Solicitar crédito</label>
                            </div>
                        </div>
                    </div>

                    <!-- ════════════════════════════════════
                         FICHAS DINÁMICAS DE SOLICITUD
                    ════════════════════════════════════ -->
                    <!-- FICHA AHORRO -->
                    <div id="ficha-ahorro" class="ficha-solicitud" style="display:none;margin-top:20px;padding:18px;background:#f0f9ff;border:1.5px solid #bfdbfe;border-radius:14px;">
                        <h5 style="font-size:14px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:#1e40af;">
                            <i class="fas fa-wallet"></i> Solicitud de Apertura — Cuenta de Ahorro
                        </h5>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Tipo de ahorro</label>
                                <select name="sol_ah_tipo_ahorro">
                                    <option value="">—</option>
                                    <option value="normal">Normal</option>
                                    <option value="programado">Programado</option>
                                    <option value="infantil">Infantil</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Titular (nombre) *</label>
                                <input type="text" name="sol_ah_titular_nombre" placeholder="Nombre completo" required>
                            </div>
                            <div class="fld">
                                <label>Titular (cédula) *</label>
                                <input type="text" name="sol_ah_titular_cedula" placeholder="Cédula" required>
                            </div>
                            <div class="fld">
                                <label>Titular (celular) *</label>
                                <input type="tel" name="sol_ah_titular_celular" placeholder="Celular" required>
                            </div>
                            <div class="fld">
                                <label>Frecuencia de depósito</label>
                                <select name="sol_ah_frecuencia_deposito">
                                    <option value="">—</option>
                                    <option value="diaria">Diaria</option>
                                    <option value="semanal">Semanal</option>
                                    <option value="quincenal">Quincenal</option>
                                    <option value="mensual">Mensual</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Objetivo de ahorro</label>
                                <input type="text" name="sol_ah_objetivo_ahorro" placeholder="Ej: educación, emergencia">
                            </div>
                            <div class="fld">
                                <label>Monto inicial (USD)</label>
                                <input type="number" step="0.01" min="0" name="sol_ah_monto_inicial" placeholder="Monto">
                            </div>
                            <div class="fld full">
                                <label>Observaciones</label>
                                <textarea name="sol_ah_observaciones" rows="2" placeholder="Observaciones adicionales…"></textarea>
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;">Documentos entregados</label>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
                                    <label><input type="checkbox" name="sol_ah_doc_cedula"> Cédula</label>
                                    <label><input type="checkbox" name="sol_ah_doc_papeleta"> Papeleta</label>
                                    <label><input type="checkbox" name="sol_ah_doc_planilla"> Planilla</label>
                                    <label><input type="checkbox" name="sol_ah_doc_deposito"> Comprobante depósito inicial</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FICHA CORRIENTE -->
                    <div id="ficha-corriente" class="ficha-solicitud" style="display:none;margin-top:20px;padding:18px;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:14px;">
                        <h5 style="font-size:14px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:#15803d;">
                            <i class="fas fa-credit-card"></i> Solicitud de Apertura — Cuenta Corriente
                        </h5>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Tipo de cuenta</label>
                                <select name="sol_cc_tipo_cc">
                                    <option value="">—</option>
                                    <option value="personal">Personal</option>
                                    <option value="empresarial">Empresarial</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Titular (nombre) *</label>
                                <input type="text" name="sol_cc_titular_nombre" placeholder="Nombre completo" required>
                            </div>
                            <div class="fld">
                                <label>Titular (cédula) *</label>
                                <input type="text" name="sol_cc_titular_cedula" placeholder="Cédula" required>
                            </div>
                            <div class="fld">
                                <label>Titular (celular) *</label>
                                <input type="tel" name="sol_cc_titular_celular" placeholder="Celular" required>
                            </div>
                            <div class="fld">
                                <label>Monto depósito promedio (USD)</label>
                                <input type="number" step="0.01" min="0" name="sol_cc_monto_deposito_prom" placeholder="Monto">
                            </div>
                            <div class="fld">
                                <label>Propósito principal</label>
                                <input type="text" name="sol_cc_proposito" placeholder="Ej: ingresos, pagos">
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;">Requerimientos</label>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
                                    <label><input type="checkbox" name="sol_cc_usa_cheques"> Usa cheques</label>
                                    <label><input type="checkbox" name="sol_cc_requiere_td"> Requiere tarjeta débito</label>
                                    <label><input type="checkbox" name="sol_cc_tiene_nomina"> Tiene nómina</label>
                                </div>
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;">Documentos entregados</label>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
                                    <label><input type="checkbox" name="sol_cc_doc_cedula"> Cédula</label>
                                    <label><input type="checkbox" name="sol_cc_doc_papeleta"> Papeleta</label>
                                    <label><input type="checkbox" name="sol_cc_doc_planilla"> Planilla</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FICHA INVERSIÓN -->
                    <div id="ficha-inversion" class="ficha-solicitud" style="display:none;margin-top:20px;padding:18px;background:#fdf2f8;border:1.5px solid #fbcfe8;border-radius:14px;">
                        <h5 style="font-size:14px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:#be185d;">
                            <i class="fas fa-chart-line"></i> Solicitud de Inversión / Depósito
                        </h5>
                        <div class="fld-grid">
                            <div class="fld">
                                <label>Tipo de inversión *</label>
                                <select name="sol_inv_tipo_inversion" required>
                                    <option value="">—</option>
                                    <option value="dpf">DPF</option>
                                    <option value="acciones">Acciones</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Monto inversión (USD) *</label>
                                <input type="number" step="0.01" min="0" name="sol_inv_monto_inversion" placeholder="Monto" required>
                            </div>
                            <div class="fld">
                                <label>Plazo (meses) *</label>
                                <input type="number" min="0" name="sol_inv_plazo_meses" placeholder="Meses" required>
                            </div>
                            <div class="fld">
                                <label>Objetivo de inversión</label>
                                <select name="sol_inv_objetivo_inversion">
                                    <option value="">—</option>
                                    <option value="jubilacion">Jubilación</option>
                                    <option value="educacion">Educación</option>
                                    <option value="vivienda">Vivienda</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Renovación automática</label>
                                <select name="sol_inv_renovacion_auto">
                                    <option value="">—</option>
                                    <option value="1">Sí</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Institución competencia actual</label>
                                <input type="text" name="sol_inv_institucion_competencia" placeholder="Banco / Cooperativa">
                            </div>
                            <div class="fld full">
                                <label>Observaciones</label>
                                <textarea name="sol_inv_observaciones" rows="2" placeholder="Observaciones…"></textarea>
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;">Requisitos y documentos</label>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
                                    <label><input type="checkbox" name="sol_inv_req_cuenta_activa"> Req: cuenta activa</label>
                                    <label><input type="checkbox" name="sol_inv_req_monto_minimo"> Req: monto mínimo</label>
                                    <label><input type="checkbox" name="sol_inv_doc_contrato"> Doc: contrato</label>
                                    <label><input type="checkbox" name="sol_inv_doc_origen"> Doc: origen fondos</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- FICHA CRÉDITO -->
                    <div id="ficha-credito" class="ficha-solicitud" style="display:none;margin-top:20px;padding:18px;background:#fef3c7;border:1.5px solid #fcd34d;border-radius:14px;">
                        <h5 style="font-size:14px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:#854d0e;">
                            <i class="fas fa-hand-holding-dollar"></i> Solicitud de Crédito
                        </h5>
                        <div class="fld-grid">
                            <div class="fld full">
                                <label style="font-weight:700;font-size:12px;color:#000;">SOLICITANTE</label>
                            </div>
                            <div class="fld">
                                <label>Nombre completo *</label>
                                <input type="text" name="sol_cred_solicitante_nombre" placeholder="Nombre" required>
                            </div>
                            <div class="fld">
                                <label>Cédula *</label>
                                <input type="text" name="sol_cred_solicitante_cedula" placeholder="Cédula" required>
                            </div>
                            <div class="fld">
                                <label>Celular *</label>
                                <input type="tel" name="sol_cred_solicitante_celular" placeholder="Celular" required>
                            </div>
                            <div class="fld">
                                <label>Estado civil</label>
                                <select name="sol_cred_solicitante_estado_civil">
                                    <option value="">—</option>
                                    <option value="soltero">Soltero/a</option>
                                    <option value="casado">Casado/a</option>
                                    <option value="divorciado">Divorciado/a</option>
                                    <option value="viudo">Viudo/a</option>
                                </select>
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;font-size:12px;color:#000;margin-top:12px;">CÓNYUGE (si aplica)</label>
                            </div>
                            <div class="fld">
                                <label>Nombre cónyuge</label>
                                <input type="text" name="sol_cred_conyuge_nombre" placeholder="Nombre">
                            </div>
                            <div class="fld">
                                <label>Cédula cónyuge</label>
                                <input type="text" name="sol_cred_conyuge_cedula" placeholder="Cédula">
                            </div>
                            <div class="fld">
                                <label>Celular cónyuge</label>
                                <input type="tel" name="sol_cred_conyuge_celular" placeholder="Celular">
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;font-size:12px;color:#000;margin-top:12px;">GARANTE</label>
                            </div>
                            <div class="fld">
                                <label>Nombre garante</label>
                                <input type="text" name="sol_cred_garante_nombre" placeholder="Nombre">
                            </div>
                            <div class="fld">
                                <label>Cédula garante</label>
                                <input type="text" name="sol_cred_garante_cedula" placeholder="Cédula">
                            </div>
                            <div class="fld">
                                <label>Celular garante</label>
                                <input type="tel" name="sol_cred_garante_celular" placeholder="Celular">
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;font-size:12px;color:#000;margin-top:12px;">CRÉDITO</label>
                            </div>
                            <div class="fld">
                                <label>Monto solicitado (USD) *</label>
                                <input type="number" step="0.01" min="0" name="sol_cred_monto_credito" placeholder="Monto" required>
                            </div>
                            <div class="fld">
                                <label>Destino del crédito *</label>
                                <select name="sol_cred_destino_credito" required>
                                    <option value="">— Selecciona —</option>
                                    <option value="capital_trabajo">Capital de trabajo</option>
                                    <option value="activos_fijos">Activos fijos</option>
                                    <option value="consumo">Consumo</option>
                                    <option value="pago_deudas">Pago de deudas</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label>Plazo solicitado (meses) *</label>
                                <input type="number" min="0" name="sol_cred_plazo_credito_meses" placeholder="Meses" required>
                            </div>
                            <div class="fld full">
                                <label>Dirección sitio del negocio/levantamiento</label>
                                <input type="text" name="sol_cred_direccion_sitio" placeholder="Dirección completa">
                            </div>
                            <div class="fld full">
                                <label style="font-weight:700;">Documentos entregados</label>
                                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
                                    <label><input type="checkbox" name="sol_cred_doc_cedula"> Cédula solicitante</label>
                                    <label><input type="checkbox" name="sol_cred_doc_conyuge"> Cédula cónyuge</label>
                                    <label><input type="checkbox" name="sol_cred_doc_garante"> Cédula garante</label>
                                    <label><input type="checkbox" name="sol_cred_doc_planilla"> Planilla de servicios</label>
                                    <label><input type="checkbox" name="sol_cred_doc_solicitud"> Solicitud de crédito</label>
                                </div>
                            </div>
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
                    <!-- hidden placeholders for credito ficha (filled if user opens ficha) -->
                    <div id="extras-fichas-hidden" style="display:none;">
                        <!-- crédito fields prefixed cred_ -->
                        <input type="text" name="cred_requiere_credito" value="">
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
   BÚSQUEDA POR NOMBRE DE EMPRESA (Levantamiento)
══════════════════════════════════════════════════════ */
let searchEmpresaTimeout;
const inpNombreEmpresa = document.getElementById('f-nombre_empresa');
const dropdownEmpresa = document.getElementById('empresa-search-dropdown');
const listEmpresa = document.getElementById('empresa-search-list');

if (inpNombreEmpresa) {
    inpNombreEmpresa.addEventListener('input', debounceSearchEmpresa);
    inpNombreEmpresa.addEventListener('focus', () => {
        const val = inpNombreEmpresa.value.trim();
        if (val.length >= 2) {
            buscarPorEmpresa(val);
        }
    });
    inpNombreEmpresa.addEventListener('blur', () => {
        setTimeout(() => {
            dropdownEmpresa.style.display = 'none';
        }, 200);
    });
}

function debounceSearchEmpresa() {
    clearTimeout(searchEmpresaTimeout);
    const val = inpNombreEmpresa.value.trim();
    if (val.length >= 2) {
        searchEmpresaTimeout = setTimeout(() => buscarPorEmpresa(val), 600);
    } else {
        dropdownEmpresa.style.display = 'none';
    }
}

async function buscarPorEmpresa(nombreEmpresa) {
    try {
        const fd = new FormData();
        fd.append('nombre_empresa', nombreEmpresa);
        fd.append('limit', 10);
        const res = await fetch('../buscar_cliente_por_empresa.php', { method:'POST', body:fd });
        const data = await res.json();

        if (data.status === 'success' && data.items && data.items.length > 0) {
            // Construir dropdown con resultados
            listEmpresa.innerHTML = '';
            data.items.forEach(cliente => {
                const item = document.createElement('div');
                item.style.padding = '10px 14px';
                item.style.borderBottom = '1px solid var(--brand-border)';
                item.style.cursor = 'pointer';
                item.style.fontSize = '14px';
                item.style.transition = '.2s';
                item.innerHTML = `
                    <div style="font-weight:600;color:var(--brand-navy-deep);">${escHtml(cliente.nombre_empresa || 'Sin nombre')}</div>
                    <div style="font-size:12px;color:var(--brand-gray);">${escHtml(cliente.nombre || '')} • ${escHtml(cliente.cedula || '')}</div>
                `;
                item.addEventListener('mouseenter', () => {
                    item.style.background = 'var(--brand-bg)';
                });
                item.addEventListener('mouseleave', () => {
                    item.style.background = 'transparent';
                });
                item.addEventListener('click', () => {
                    seleccionarEmpresa(cliente);
                });
                listEmpresa.appendChild(item);
            });
            dropdownEmpresa.style.display = 'block';
        } else {
            listEmpresa.innerHTML = '<div style="padding:10px 14px;color:var(--brand-gray);font-size:13px;"><i class="fas fa-inbox"></i> No hay resultados</div>';
            dropdownEmpresa.style.display = 'block';
        }
    } catch(err) {
        console.error('Error en búsqueda de empresa:', err);
        dropdownEmpresa.style.display = 'none';
    }
}

function seleccionarEmpresa(cliente) {
    // Pre-llenar campos del cliente
    fillField('f-nombre',          cliente.nombre);
    fillField('f-cedula',          cliente.cedula);
    fillField('f-celular',         cliente.celular);
    fillField('f-telefono',        cliente.telefono);
    fillField('f-email',           cliente.email);
    fillField('f-direccion',       cliente.direccion);
    fillField('f-ciudad',          cliente.ciudad);
    fillField('f-zona',            cliente.zona);
    fillField('f-nombre_empresa',  cliente.nombre_empresa);
    if (cliente.estado) setSelect('f-estado', cliente.estado);
    
    // Guardar ID del cliente
    document.getElementById('hid-cliente_id').value = cliente.id || '';
    
    // Cerrar dropdown
    dropdownEmpresa.style.display = 'none';
}

/* ══════════════════════════════════════════════════════
   FICHAS DINÁMICAS DE SOLICITUD
══════════════════════════════════════════════════════ */
function toggleFicha(producto) {
    const checkbox = document.getElementById('solicitar_' + producto);
    const ficha = document.getElementById('ficha-' + producto);
    
    if (checkbox && ficha) {
        ficha.style.display = checkbox.checked ? 'block' : 'none';
    }
}
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Usa cheques</label>
                    <input type="checkbox" class="sol-campo" data-field="usa_cheques" style="width:18px;height:18px;cursor:pointer;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Monto depósito promedio</label>
                    <input type="number" class="sol-campo" data-field="monto_deposito_prom" placeholder="Monto USD" step="0.01" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                </div>
            `;
        } else if (tipo === 'inversiones') {
            camposDin.innerHTML = `
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Tipo de inversión</label>
                    <select class="sol-campo" data-field="tipo_inversion" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                        <option value="">—</option>
                        <option value="dpf">DPF</option>
                        <option value="acciones">Acciones</option>
                        <option value="otro">Otro</option>
                    </select>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Monto de inversión</label>
                    <input type="number" class="sol-campo" data-field="monto_inversion" placeholder="Monto USD" step="0.01" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Plazo (meses)</label>
                    <input type="number" class="sol-campo" data-field="plazo_meses" placeholder="Meses" min="0" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                </div>
            `;
        } else if (tipo === 'credito') {
            camposDin.innerHTML = `
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">¿Requiere crédito?</label>
                    <select class="sol-campo" data-field="requiere_credito" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                        <option value="">—</option>
                        <option value="1">Sí</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Destino del crédito</label>
                    <select class="sol-campo" data-field="destino_credito" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                        <option value="">—</option>
                        <option value="capital_trabajo">Capital de trabajo</option>
                        <option value="activos_fijos">Activos fijos</option>
                        <option value="consumo">Consumo</option>
                        <option value="pago_deudas">Pago de deudas</option>
                    </select>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;display:block;margin-bottom:6px;">Monto solicitado</label>
                    <input type="number" class="sol-campo" data-field="monto_credito" placeholder="Monto USD" step="0.01" style="width:100%;padding:8px 10px;border:1.5px solid var(--brand-border);border-radius:8px;font-size:13px;">
                </div>
            `;
        }
    });

/* ══════════════════════════════════════════════════════
   FICHAS DINÁMICAS DE SOLICITUD
══════════════════════════════════════════════════════ */
function toggleFicha(producto) {
    const checkbox = document.getElementById('solicitar_' + producto);
    const ficha = document.getElementById('ficha-' + producto);
    
    if (checkbox && ficha) {
        ficha.style.display = checkbox.checked ? 'block' : 'none';
    }
}

/* ══════════════════════════════════════════════════════
   STEPPER NAVIGATION
══════════════════════════════════════════════════════ */
const panes   = document.querySelectorAll('.step-pane');
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

    // Mostrar/ocultar la pregunta "¿Tiene empresa?" según actividad
    const fldHasEmp = document.getElementById('fld-tiene_empresa');
    const extraEmp = document.getElementById('extras-empresa');
    // Para 'negocio_propio' no mostrar la pregunta (implícito que tiene negocio)
    if (fldHasEmp) {
        if (el.dataset.val === 'negocio_propio') {
            fldHasEmp.style.display = 'none';
            // programáticamente marcar que tiene empresa y mostrar extras
            const inpYes = document.querySelector('input[name="tiene_empresa"][value="1"]');
            if (inpYes) inpYes.checked = true;
            if (extraEmp) extraEmp.classList.add('show');
        } else {
            fldHasEmp.style.display = '';
            // dejar la decisión al usuario: si no hay selección, ocultar extras
            const inpYes = document.querySelector('input[name="tiene_empresa"][value="1"]');
            const inpNo  = document.querySelector('input[name="tiene_empresa"][value="0"]');
            if (inpYes && inpNo) {
                // si ninguno seleccionado, asegurar extras ocultos
                const any = inpYes.checked || inpNo.checked;
                if (!any && extraEmp) extraEmp.classList.remove('show');
            }
        }
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

// Régimen tiles behavior — show RUC/RISE question cards
document.querySelectorAll('.regimen-tile').forEach(tile => {
    tile.addEventListener('click', () => {
        // mark selected
        document.querySelectorAll('.regimen-tile').forEach(t => t.classList.remove('selected'));
        tile.classList.add('selected');
        const val = tile.dataset.val;
        // set radio
        const r = tile.querySelector('input[type="radio"]');
        if (r) r.checked = true;
        // toggle visible cards
        document.getElementById('q-ruc').style.display = val === 'ruc' ? 'block' : 'none';
        document.getElementById('q-rise').style.display = val === 'rise' ? 'block' : 'none';
        // set backend-compatible flags
        const hidRuc = document.querySelector('input[name="tiene_ruc"]');
        const hidRise = document.querySelector('input[name="tiene_rise"]');
        if (hidRuc) hidRuc.value = val === 'ruc' ? '1' : '0';
        if (hidRise) hidRise.value = val === 'rise' ? '1' : '0';
    });
});

// q-btn behavior: set hidden inputs and mark active
document.addEventListener('click', function(e){
    const btn = e.target.closest('.q-btn');
    if (!btn) return;
    const name = btn.dataset.name;
    const val  = btn.dataset.val;
    // find related hidden input(s)
    const hid = document.querySelector('input[name="'+name+'"]');
    if (hid) hid.value = val;
    // toggle active class among siblings
    const parent = btn.parentElement;
    if (parent) {
        parent.querySelectorAll('.q-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }
});

// Initialize: if page loaded with pre-filled radio, show corresponding card
const pre = document.querySelector('input[name="regimen_type"]:checked');
if (pre) {
    const tile = document.querySelector('.regimen-tile[data-val="'+pre.value+'"]');
    if (tile) tile.classList.add('selected');
    document.getElementById('q-ruc').style.display = pre.value === 'ruc' ? 'block' : 'none';
    document.getElementById('q-rise').style.display = pre.value === 'rise' ? 'block' : 'none';
    const hidRuc = document.querySelector('input[name="tiene_ruc"]');
    const hidRise = document.querySelector('input[name="tiene_rise"]');
    if (hidRuc) hidRuc.value = pre.value === 'ruc' ? '1' : '0';
    if (hidRise) hidRise.value = pre.value === 'rise' ? '1' : '0';
}

// Mostrar opciones de apertura según interés (sin obligar)
function toggleSolicitudesFromInteres() {
    const intAhorro = document.querySelector('input[name="ec_interes_ahorro"][value="1"]')?.checked;
    const intCorriente = document.querySelector('input[name="ec_interes_cc"][value="1"]')?.checked;
    const intInv = document.querySelector('input[name="ec_interes_inversion"][value="1"]')?.checked;
    const intCred = document.querySelector('input[name="ec_interes_credito"][value="1"]')?.checked;
    // auto-check the solicitar checkboxes when interest is Yes
    if (document.getElementById('solicitar_ahorro')) document.getElementById('solicitar_ahorro').checked = !!intAhorro || document.getElementById('solicitar_ahorro').checked;
    if (document.getElementById('solicitar_corriente')) document.getElementById('solicitar_corriente').checked = !!intCorriente || document.getElementById('solicitar_corriente').checked;
    if (document.getElementById('solicitar_inversion')) document.getElementById('solicitar_inversion').checked = !!intInv || document.getElementById('solicitar_inversion').checked;
    if (document.getElementById('solicitar_credito')) document.getElementById('solicitar_credito').checked = !!intCred || document.getElementById('solicitar_credito').checked;
    // Mostrar los formularios detallados asociados al interés (igual que en la app móvil)
    const extrasMap = {
        ahorro: 'extras-ahorro',
        corriente: 'extras-corriente',
        inversion: 'extras-inversiones',
        credito: 'extras-credito'
    };
    const boxA = document.getElementById('extras-ahorro'); if (boxA) boxA.classList.toggle('show', !!intAhorro);
    const boxC = document.getElementById('extras-corriente'); if (boxC) boxC.classList.toggle('show', !!intCorriente);
    const boxI = document.getElementById('extras-inversiones'); if (boxI) boxI.classList.toggle('show', !!intInv);
    const boxCr = document.getElementById('extras-credito'); if (boxCr) boxCr.classList.toggle('show', !!intCred);
}
// attach listeners to interest radios
['ec_interes_ahorro','ec_interes_cc','ec_interes_inversion','ec_interes_credito'].forEach(name => {
    document.querySelectorAll('input[name="'+name+'"]').forEach(i => i.addEventListener('change', toggleSolicitudesFromInteres));
});

// Validación antes de enviar: si prospecto tiene empresa y solicita crédito vía acuerdo, exigir datos de levantamiento mínimo
formEnc.addEventListener('submit', function(e){
    // antes de enviar, sincronizar campos con prefijo `ec_` hacia los nombres que espera el backend
    const ecFields = document.querySelectorAll('[name^="ec_"]');
    ecFields.forEach(function(el){
        const name = el.getAttribute('name');
        const target = name.replace(/^ec_/, '');
        // radio groups: get checked value
        if (el.type === 'radio') {
            const checked = document.querySelector('[name="'+name+'"]:checked');
            const val = checked ? checked.value : '';
            let hid = document.querySelector('input[name="'+target+'"]');
            if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.name=target; formEnc.appendChild(hid); }
            hid.value = val;
            return;
        }
        // checkbox
        if (el.type === 'checkbox') {
            let hid = document.querySelector('input[name="'+target+'"]');
            if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.name=target; formEnc.appendChild(hid); }
            hid.value = el.checked ? '1' : '';
            return;
        }
        // other inputs/selects
        let hid = document.querySelector('input[name="'+target+'"]');
        if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.name=target; formEnc.appendChild(hid); }
        hid.value = el.value;
    });
    // Enviar fichas de producto (si hay interés/solicitud) antes de enviar la encuesta principal
    (async function(){
        // evitar reentrada si ya guardamos fichas
        if (formEnc.dataset.fichasSaved === '1') return;
        e.preventDefault();
        const usuario_id = document.querySelector('input[name="usuario_id"]').value || '';
        const asesor_id = document.querySelector('input[name="asesor_id"]').value || '';
        const cliente_cedula = document.querySelector('input[name="cedula"]') ? document.querySelector('input[name="cedula"]').value.trim() : document.getElementById('inp-cedula').value.trim();
        const cliente_nombre = document.querySelector('input[name="nombre"]') ? document.querySelector('input[name="nombre"]').value.trim() : '';
        const lat = document.getElementById('lat').value || '';
        const lng = document.getElementById('lng').value || '';
        const hora_gps = '';

        // helpers
        const postForm = async (bodyObj) => {
            const form = new URLSearchParams();
            for (const k in bodyObj) form.append(k, bodyObj[k] ?? '');
            try {
                const resp = await fetch('guardar_ficha_producto.php', { method:'POST', headers: {'ngrok-skip-browser-warning':'true','Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
                const data = await resp.json();
                return data && data.status === 'success';
            } catch (err) { return false; }
        };

        // Ahorro
        const intAhorro = document.querySelector('input[name="ec_interes_ahorro"][value="1"]')?.checked;
        if (intAhorro || document.getElementById('solicitar_ahorro')?.checked) {
            const body = {
                usuario_id, asesor_id, producto_tipo: 'cuenta_ahorros', cliente_cedula, cliente_nombre, latitud: lat, longitud: lng, hora_gps,
                tipo_ahorro: document.querySelector('[name="ah_tipo_ahorro"]')?.value || '',
                titular_nombre: document.querySelector('[name="ah_titular_nombre"]')?.value || '',
                titular_cedula: document.querySelector('[name="ah_titular_cedula"]')?.value || '',
                titular_celular: document.querySelector('[name="ah_titular_celular"]')?.value || '',
                monto_inicial: document.querySelector('[name="ah_monto_inicial"]')?.value || '',
                frecuencia_deposito: document.querySelector('[name="ah_frecuencia_deposito"]')?.value || '',
                objetivo_ahorro: document.querySelector('[name="ah_objetivo_ahorro"]')?.value || '',
                institucion_ahorro: document.querySelector('[name="ah_institucion_ahorro"]')?.value || document.querySelector('[name="ec_institucion_ahorro"]')?.value || '',
                observaciones: document.querySelector('[name="ah_observaciones"]')?.value || '',
                doc_cedula: document.querySelector('[name="ah_doc_cedula"]')?.checked ? '1' : '0',
                doc_papeleta: document.querySelector('[name="ah_doc_papeleta"]')?.checked ? '1' : '0',
                doc_planilla: document.querySelector('[name="ah_doc_planilla"]')?.checked ? '1' : '0',
                doc_deposito_inicial: document.querySelector('[name="ah_doc_deposito_inicial"]')?.checked ? '1' : '0',
            };
            const ok = await postForm(body);
            if (!ok) { alert('Error guardando ficha Ahorro'); return; }
        }

        // Cuenta corriente
        const intCC = document.querySelector('input[name="ec_interes_cc"][value="1"]')?.checked;
        if (intCC || document.getElementById('solicitar_corriente')?.checked) {
            const body = {
                usuario_id, asesor_id, producto_tipo: 'cuenta_corriente', cliente_cedula, cliente_nombre, latitud: lat, longitud: lng, hora_gps,
                tipo_cc: document.querySelector('[name="cc_tipo_cc"]')?.value || '',
                titular_nombre: document.querySelector('[name="cc_titular_nombre"]')?.value || '',
                titular_cedula: document.querySelector('[name="cc_titular_cedula"]')?.value || '',
                titular_celular: document.querySelector('[name="cc_titular_celular"]')?.value || '',
                monto_deposito_prom: document.querySelector('[name="cc_monto_deposito_prom"]')?.value || '',
                usa_cheques: document.querySelector('[name="cc_usa_cheques"]')?.checked ? '1' : '0',
                requiere_td: document.querySelector('[name="cc_requiere_td"]')?.checked ? '1' : '0',
                tiene_nomina: document.querySelector('[name="cc_tiene_nomina"]')?.checked ? '1' : '0',
                institucion_cc: document.querySelector('[name="cc_institucion_cc"]')?.value || document.querySelector('[name="cc_institucion_cc"]')?.value || '',
                observaciones: document.querySelector('[name="cc_observaciones"]')?.value || '',
                doc_cedula: document.querySelector('[name="cc_doc_cedula"]')?.checked ? '1' : '0',
                doc_papeleta: document.querySelector('[name="cc_doc_papeleta"]')?.checked ? '1' : '0',
                doc_planilla: document.querySelector('[name="cc_doc_planilla"]')?.checked ? '1' : '0',
            };
            const ok = await postForm(body);
            if (!ok) { alert('Error guardando ficha Cuenta Corriente'); return; }
        }

        // Inversiones
        const intInv = document.querySelector('input[name="ec_interes_inversion"][value="1"]')?.checked;
        if (intInv || document.getElementById('solicitar_inversion')?.checked) {
            const body = {
                usuario_id, asesor_id, producto_tipo: 'inversiones', cliente_cedula, cliente_nombre, latitud: lat, longitud: lng, hora_gps,
                tipo_inversion: document.querySelector('[name="inv_tipo_inversion"]')?.value || '',
                monto_inversion: document.querySelector('[name="inv_monto_inversion"]')?.value || '',
                plazo_meses: document.querySelector('[name="inv_plazo_meses"]')?.value || '',
                objetivo_inversion: document.querySelector('[name="inv_objetivo_inversion"]')?.value || '',
                institucion_competencia: document.querySelector('[name="inv_institucion_competencia"]')?.value || document.querySelector('[name="ec_institucion_inversiones"]')?.value || '',
                renovacion_auto: document.querySelector('[name="inv_renovacion_auto"]')?.checked ? '1' : '0',
                req_cuenta_activa: document.querySelector('[name="inv_req_cuenta_activa"]')?.checked ? '1' : '0',
            };
            const ok = await postForm(body);
            if (!ok) { alert('Error guardando ficha Inversiones'); return; }
        }

        // Crédito
        const intCred = document.querySelector('input[name="ec_interes_credito"][value="1"]')?.checked;
        if (intCred || document.getElementById('solicitar_credito')?.checked) {
            const body = {
                usuario_id, asesor_id, producto_tipo: 'credito', cliente_cedula, cliente_nombre, latitud: lat, longitud: lng, hora_gps,
                requiere_credito: document.querySelector('[name="cred_requiere_credito"]')?.value || '',
                destino_credito: document.querySelector('[name="cred_destino_credito"]')?.value || '',
                dest_otros_detalle: document.querySelector('[name="cred_dest_otros_detalle"]')?.value || '',
                monto_credito: document.querySelector('[name="cred_monto_credito"]')?.value || '',
                plazo_credito_meses: document.querySelector('[name="cred_plazo_credito_meses"]')?.value || '',
                solicitante_nombre: document.querySelector('[name="cred_solicitante_nombre"]')?.value || '',
                solicitante_cedula: document.querySelector('[name="cred_solicitante_cedula"]')?.value || '',
                solicitante_celular: document.querySelector('[name="cred_solicitante_celular"]')?.value || '',
                garante_nombre: document.querySelector('[name="cred_garante_nombre"]')?.value || '',
                garante_cedula: document.querySelector('[name="cred_garante_cedula"]')?.value || '',
                direccion_sitio: document.querySelector('[name="cred_direccion_sitio"]')?.value || '',
                doc_cedula: document.querySelector('[name="cred_doc_cedula"]')?.checked ? '1' : '0',
            };
            const ok = await postForm(body);
            if (!ok) { alert('Error guardando ficha Crédito'); return; }
        }

        // marcar fichas guardadas y reenviar el formulario principal
        formEnc.dataset.fichasSaved = '1';
        formEnc.submit();
    })();
    const tieneEmpresa = document.querySelector('input[name="tiene_empresa"][value="1"]')?.checked;
    const acuerdo = formEnc.querySelector('select[name="acuerdo_logrado"]')?.value;
    if (tieneEmpresa && (acuerdo === 'solicitud_credito' || document.getElementById('solicitar_credito')?.checked)) {
        const nombreEmp = document.getElementById('f-nombre_empresa')?.value.trim();
        if (!nombreEmp) {
            e.preventDefault();
            alert('El prospecto tiene empresa: primero complete el levantamiento de empresa (nombre, actividad) antes de solicitar crédito.');
            show(2); // llevar al paso de empresa
            return false;
        }
    }
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

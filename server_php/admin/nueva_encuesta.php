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

/* SEARCH */
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
.found-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:30px;font-size:13px;font-weight:700;margin-top:12px;}
.found-chip.found{background:#d1fae5;color:#065f46;}
.found-chip.new-prosp{background:#fef3c7;color:#92400e;}

/* STEPPER */
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

/* CARDS */
.form-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:22px 24px;box-shadow:var(--brand-shadow-sm);margin-bottom:16px;}
.form-card h3{font-size:17px;font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:10px;}
.form-card h3 i{color:var(--brand-yellow-deep);}
.form-card .sub{color:var(--brand-gray);font-size:13.5px;margin-bottom:18px;}

/* VISIT CARDS */
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

/* CHIPS */
.chip-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;}
.chip{padding:10px 16px;border:2px solid var(--brand-border);border-radius:30px;cursor:pointer;font-size:13px;font-weight:700;color:#374151;background:#fff;transition:.2s;display:flex;align-items:center;gap:6px;}
.chip:hover{border-color:var(--brand-navy);color:var(--brand-navy);}
.chip.selected{background:var(--brand-navy-deep);color:#fff;border-color:var(--brand-navy-deep);}

/* FIELDS */
.fld-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px 18px;}
.fld{display:flex;flex-direction:column;gap:5px;}
.fld label{font-size:11px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;}
.fld input,.fld select,.fld textarea{padding:10px 12px;border:1.5px solid var(--brand-border);border-radius:10px;font-size:14px;font-family:inherit;background:#fff;transition:.2s;}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.fld textarea{resize:vertical;min-height:70px;}
.fld.full{grid-column:1/-1;}

/* YN TOGGLE */
.yn-group{display:flex;gap:6px;}
.yn-opt{flex:1;padding:10px;text-align:center;border:1.5px solid var(--brand-border);border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;background:#fff;color:#374151;transition:.2s;}
.yn-opt:hover{background:#f3f4f6;}
.yn-opt input{display:none;}
.yn-opt.checked{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-color:transparent;}

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

/* INFO BANNER */
.info-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#1e40af;font-weight:600;margin-bottom:16px;}
.info-banner i{font-size:16px;}
.warn-banner{background:#fef9c3;border:1px solid #fde68a;border-radius:12px;padding:12px 16px;display:flex;align-items:flex-start;gap:10px;font-size:13px;color:#854d0e;font-weight:600;margin-top:12px;}
.warn-banner i{font-size:15px;margin-top:1px;flex-shrink:0;}
.warn-banner a{color:#854d0e;text-decoration:underline;}

/* CUENTA BOX */
.cuenta-box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-top:10px;}
.cuenta-box h6{font-size:11px;text-transform:uppercase;color:var(--brand-navy);font-weight:800;margin-bottom:10px;letter-spacing:.4px;}

/* INTERÉS LEVEL */
.level-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:6px;}
.level-card{border:2px solid var(--brand-border);border-radius:12px;padding:12px;cursor:pointer;text-align:center;transition:.2s;}
.level-card:hover{border-color:var(--brand-navy);}
.level-card.selected{border-color:var(--brand-navy-deep);background:var(--brand-navy-deep);color:#fff;}
.level-card .lv-icon{font-size:20px;margin-bottom:4px;}
.level-card span{font-size:12px;font-weight:700;}
.level-card.ninguno.selected{border-color:#dc2626;background:#dc2626;}
.level-card.bajo.selected{border-color:#f59e0b;background:#f59e0b;}
.level-card.alto.selected{border-color:#10b981;background:#10b981;}

/* PRODUCT CARDS (interés) */
.prod-interest-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-top:10px;}
.prod-card{border:2px solid var(--brand-border);border-radius:14px;padding:16px 12px;cursor:pointer;text-align:center;transition:.22s;background:#fff;position:relative;}
.prod-card:hover{border-color:var(--brand-navy);transform:translateY(-2px);}
.prod-card.selected{border-color:var(--brand-navy-deep);background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;}
.prod-card .pc-icon{font-size:26px;margin-bottom:8px;display:block;}
.prod-card .pc-name{font-size:13px;font-weight:800;line-height:1.2;}
.prod-card .pc-check{position:absolute;top:8px;right:8px;width:20px;height:20px;border-radius:50%;background:#10b981;color:#fff;display:none;align-items:center;justify-content:center;font-size:11px;}
.prod-card.selected .pc-check{display:flex;}

/* FOOTER */
.form-footer{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:14px 18px;box-shadow:var(--brand-shadow-sm);position:sticky;bottom:14px;z-index:30;}
.btn{padding:11px 22px;border-radius:11px;font-weight:800;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn-yellow{background:var(--brand-yellow);color:var(--brand-navy-deep);}
.btn-yellow:hover{background:var(--brand-yellow-deep);}
.btn-ghost{background:#f3f4f6;color:#374151;}
.btn-ghost:hover{background:#e5e7eb;}
.btn-primary{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;}
.btn-primary:hover{opacity:.9;}
.alert-banner{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;margin-bottom:18px;font-size:.95rem;font-weight:600;}
.alert-ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.alert-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}


.step-pane{display:none;}
.step-pane.active{display:block;animation:fadein .22s ease;}
@keyframes fadein{from{opacity:0;transform:translateY(5px);}to{opacity:1;transform:none;}}

@media(max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .main-content{margin-left:0;}
    .content-area{padding:14px;}
    .stepper{padding:10px;}
    .step .lbl{display:none;}
    .form-card{padding:16px;}
    .fld-grid{grid-template-columns:1fr;}
    .visit-grid{grid-template-columns:1fr;}
    .level-grid{grid-template-columns:repeat(3,1fr);}
    .prod-interest-grid{grid-template-columns:1fr 1fr;}
}

/* ══ FICHA PANELS ══ */
.ficha-panel{
    background:#fff;
    border:2px solid var(--brand-border);
    border-radius:14px;
    margin-top:18px;
    overflow:hidden;
    animation:fadein .25s ease;
}
.ficha-header{
    display:flex;align-items:center;gap:14px;
    padding:16px 20px;
    border-bottom:1px solid var(--brand-border);
    background:#f9fafb;
}
.ficha-icon{
    width:46px;height:46px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.35rem;flex-shrink:0;
}
.ficha-title{font-weight:700;font-size:1rem;color:var(--brand-navy-deep);}
.ficha-sub{font-size:.8rem;color:#6b7280;margin-top:2px;}
.ficha-body{padding:18px 20px;}
.ficha-sec-title{
    font-size:.78rem;font-weight:700;color:#374151;
    text-transform:uppercase;letter-spacing:.06em;
    margin:16px 0 8px;
    display:flex;align-items:center;gap:6px;
}
.ficha-sec-title:first-child{margin-top:0;}
.fld-full{grid-column:1/-1;}

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

        <?php if(!empty($_GET['enc_status'])): ?>
        <div class="alert-banner <?= $_GET['enc_status']==='ok' ? 'alert-ok' : 'alert-err' ?>">
            <i class="fas <?= $_GET['enc_status']==='ok' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            <?= htmlspecialchars($_GET['enc_msg'] ?? '') ?>
        </div>
        <?php endif; ?>

        <!-- ══ BÚSQUEDA POR CÉDULA ══ -->
        <div class="search-card">
            <h3><i class="fas fa-magnifying-glass"></i>Buscar prospecto / cliente</h3>
            <p class="sub">Ingresa la cédula para cargar los datos existentes, o llena el formulario si es nuevo.</p>
            <div class="search-row">
                <input type="text" id="inp-cedula" placeholder="Ej: 1712345678"
                       maxlength="13" inputmode="numeric" pattern="[0-9]*">
                <button class="btn-search" id="btn-buscar" type="button">
                    <i class="fas fa-search"></i> Buscar
                </button>
            </div>
            <div id="search-result" style="display:none;"></div>
        </div>

        <!-- ══ STEPPER ══ -->
        <div class="stepper" id="stepper" style="display:none;">
            <?php $steps = [
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
            <input type="hidden" name="prod_interes"   id="hid-prod_interes">
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">
            <input type="hidden" name="asesor_id"  value="<?= $asesor_table_id ?>">
            <input type="hidden" name="usuario_id" value="<?= $asesor_usuario_id ?>">
            <!-- campos q-card ocultos -->
            <input type="hidden" name="ruc_declara_iva"    id="hid-ruc_declara_iva">
            <input type="hidden" name="ruc_emite_facturas" id="hid-ruc_emite_facturas">
            <input type="hidden" name="ruc_lleva_contab"   id="hid-ruc_lleva_contab">
            <input type="hidden" name="rise_paga_cuota"    id="hid-rise_paga_cuota">
            <input type="hidden" name="rise_emite_notas"   id="hid-rise_emite_notas">
            <input type="hidden" name="rise_conoce_limite" id="hid-rise_conoce_limite">

            <!-- ══════════════════════════════════════
                 PASO 1 — TIPO DE VISITA
            ═══════════════════════════════════════ -->
            <div class="step-pane active" data-pane="0">
                <div class="form-card">
                    <h3><i class="fas fa-route"></i>Tipo de visita</h3>
                    <p class="sub">¿Es la primera vez que contactas a este prospecto o es un seguimiento?</p>
                    <div class="visit-grid">
                        <div class="visit-card frio" data-tipo="frio" onclick="selectVisita(this)">
                            <div class="v-icon"><i class="fas fa-snowflake"></i></div>
                            <h4>Visita en frío</h4>
                            <p>Primer contacto. No hay relación previa.</p>
                        </div>
                        <div class="visit-card seguimiento" data-tipo="seguimiento" onclick="selectVisita(this)">
                            <div class="v-icon"><i class="fas fa-arrows-rotate"></i></div>
                            <h4>Seguimiento</h4>
                            <p>Ya existe contacto o visita anterior.</p>
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
                    <p class="sub">Verifica o completa la información del prospecto.</p>

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
                            <input type="text" name="zona" id="f-zona" placeholder="Sector">
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

                <!-- Actividad económica -->
                <div class="form-card">
                    <h3><i class="fas fa-briefcase"></i>Actividad económica</h3>
                    <p class="sub">Selecciona la actividad principal del prospecto.</p>
                    <div class="chip-grid" id="chips-actividad">
                        <div class="chip" data-val="negocio_propio"   onclick="toggleChip(this,'actividad')"><i class="fas fa-store"></i> Negocio propio</div>
                        <div class="chip" data-val="empleado_privado" onclick="toggleChip(this,'actividad')"><i class="fas fa-building"></i> Empleado privado</div>
                        <div class="chip" data-val="empleado_publico" onclick="toggleChip(this,'actividad')"><i class="fas fa-landmark"></i> Empleado público</div>
                        <div class="chip" data-val="profesional"      onclick="toggleChip(this,'actividad')"><i class="fas fa-user-tie"></i> Profesional independiente</div>
                        <div class="chip" data-val="otro"             onclick="toggleChip(this,'actividad')"><i class="fas fa-ellipsis"></i> Otro</div>
                    </div>
                </div>

                <!-- Régimen tributario -->
                <div class="form-card">
                    <h3><i class="fas fa-file-invoice"></i>Régimen tributario</h3>
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

                    <!-- ── PREGUNTAS RUC ── -->
                    <div id="q-ruc" class="q-cards" style="display:none;">
                        <div class="q-card">
                            <div class="q-label">Número de RUC (opcional)</div>
                            <div class="q-field"><input type="text" name="ruc_numero" placeholder="1234567890001"></div>
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

                    <!-- ── PREGUNTAS RISE ── -->
                    <div id="q-rise" class="q-cards" style="display:none;">
                        <div class="q-card">
                            <div class="q-label">Número RISE (opcional)</div>
                            <div class="q-field"><input type="text" name="rise_numero" placeholder="Número de comprobante"></div>
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
                        <div class="q-card">
                            <div class="q-label">Ingreso aproximado (RISE) — opcional</div>
                            <div class="q-field"><input type="number" step="0.01" min="0" name="rise_ingreso_aprox" placeholder="USD 0.00"></div>
                        </div>
                    </div>
                </div>

                <!-- Empresa -->
                <div class="form-card">
                    <h3><i class="fas fa-shop"></i>¿Tiene empresa?</h3>
                    <p class="sub">Indica si el prospecto tiene una empresa o negocio registrado.</p>
                    <div class="fld-grid">
                        <?= ynBlock('¿Tiene empresa?', 'tiene_empresa') ?>
                    </div>

                    <!-- SI tiene empresa -->
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
                        <div class="warn-banner" style="margin-top:14px;">
                            <i class="fas fa-triangle-exclamation"></i>
                            <div>
                                Para solicitar un <strong>crédito empresarial</strong>, primero debes completar el
                                <a href="levantamiento_empresa.php" target="_blank">Levantamiento de Empresa</a>.
                                El supervisor revisará y aprobará la solicitud antes de proceder.
                            </div>
                        </div>
                    </div>

                    <!-- NO tiene empresa (puede pedir crédito normal) -->
                    <div id="aviso-sin-empresa" style="display:none;margin-top:12px;">
                        <div class="info-banner">
                            <i class="fas fa-circle-info"></i>
                            <span>Sin empresa registrada. El prospecto puede solicitar un crédito personal directamente.</span>
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

                    <!-- Cuenta ahorro -->
                    <div class="sub-sec" style="border-top:none;padding-top:0;margin-top:0;">
                        <h5><i class="fas fa-wallet"></i>Cuenta de ahorro</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Mantiene cuenta de ahorro?', 'ec_mantiene_cuenta_ahorro') ?>
                        </div>
                        <div id="extras-ahorro" class="extras">
                            <div class="cuenta-box">
                                <h6>Detalle cuenta ahorro</h6>
                                <div class="fld-grid">
                                    <div class="fld"><label>Institución</label>
                                        <input type="text" name="ec_institucion_ahorro" placeholder="Banco / Cooperativa">
                                    </div>
                                    <div class="fld"><label>Saldo aprox. (USD)</label>
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
                                <h6>Detalle cuenta corriente</h6>
                                <div class="fld-grid">
                                    <div class="fld"><label>Institución</label>
                                        <input type="text" name="ec_institucion_corriente" placeholder="Banco / Cooperativa">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inversiones -->
                    <div class="sub-sec">
                        <h5><i class="fas fa-chart-line"></i>Inversiones / Depósitos</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Tiene inversiones?', 'ec_tiene_inversiones') ?>
                        </div>
                        <div id="extras-inversiones" class="extras">
                            <div class="cuenta-box">
                                <h6>Detalle inversión</h6>
                                <div class="fld-grid">
                                    <div class="fld"><label>Institución</label>
                                        <input type="text" name="ec_institucion_inversiones" placeholder="Banco / Cooperativa">
                                    </div>
                                    <div class="fld"><label>Valor (USD)</label>
                                        <input type="number" step="0.01" min="0" name="ec_valor_inversion">
                                    </div>
                                    <div class="fld"><label>Plazo</label>
                                        <input type="text" name="ec_plazo_inversion" placeholder="Ej: 6 meses">
                                    </div>
                                    <div class="fld"><label>Vencimiento</label>
                                        <input type="date" name="ec_fecha_vencimiento_inversion">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Crédito activo -->
                    <div class="sub-sec">
                        <h5><i class="fas fa-hand-holding-dollar"></i>Crédito activo</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Tiene crédito activo?', 'ec_tiene_operaciones_crediticias') ?>
                        </div>
                        <div id="extras-credito" class="extras">
                            <div class="cuenta-box">
                                <h6>Detalle crédito activo</h6>
                                <div class="fld-grid">
                                    <div class="fld"><label>Institución</label>
                                        <input type="text" name="ec_institucion_credito" placeholder="Banco / Cooperativa">
                                    </div>
                                    <div class="fld"><label>Monto aprox. (USD)</label>
                                        <input type="number" step="0.01" min="0" name="ec_monto_credito_actual">
                                    </div>
                                    <div class="fld"><label>Destino del crédito</label>
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
                    <h3><i class="fas fa-star"></i>¿Le interesa trabajar con nosotros?</h3>
                    <p class="sub">¿Cuánto interés mostró el prospecto en nuestros productos?</p>

                    <div class="level-grid">
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
                        <p style="font-size:13px;color:var(--brand-gray);margin-bottom:12px;">
                            Selecciona todos los productos en los que mostró interés:
                        </p>
                        <div class="prod-interest-grid">
                            <div class="prod-card" data-prod="ahorro" onclick="toggleProd(this)">
                                <div class="pc-check"><i class="fas fa-check"></i></div>
                                <span class="pc-icon">🏦</span>
                                <div class="pc-name">Cuenta de Ahorro</div>
                            </div>
                            <div class="prod-card" data-prod="corriente" onclick="toggleProd(this)">
                                <div class="pc-check"><i class="fas fa-check"></i></div>
                                <span class="pc-icon">💳</span>
                                <div class="pc-name">Cuenta Corriente</div>
                            </div>
                            <div class="prod-card" data-prod="inversion" onclick="toggleProd(this)">
                                <div class="pc-check"><i class="fas fa-check"></i></div>
                                <span class="pc-icon">📈</span>
                                <div class="pc-name">Inversión / Depósito</div>
                            </div>
                            <div class="prod-card" data-prod="credito" onclick="toggleProd(this)">
                                <div class="pc-check"><i class="fas fa-check"></i></div>
                                <span class="pc-icon">💰</span>
                                <div class="pc-name">Crédito</div>
                            </div>
                        </div>

                        <!-- ── FICHA: CUENTA DE AHORRO ── -->
                        <div id="ficha-ahorro" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#d1fae5;color:#065f46;"><i class="fas fa-piggy-bank"></i></div>
                                <div>
                                    <div class="ficha-title">Ficha: Cuenta de Ahorro</div>
                                    <div class="ficha-sub">Completa los datos para la solicitud</div>
                                </div>
                            </div>
                            <div class="ficha-body">
                                <div class="ficha-sec-title"><i class="fas fa-user"></i> Datos del Titular</div>
                                <div class="fld-grid" style="margin-bottom:14px;">
                                    <div class="fld fld-full"><label>Nombre completo</label>
                                        <input type="text" name="fa_nombre" id="fa-nombre" placeholder="Nombre del titular"></div>
                                    <div class="fld"><label>Cédula</label>
                                        <input type="text" name="fa_cedula" id="fa-cedula" placeholder="Cédula"></div>
                                    <div class="fld"><label>Celular</label>
                                        <input type="tel" name="fa_celular" id="fa-celular" placeholder="09XXXXXXXX"></div>
                                </div>
                                <div class="ficha-sec-title"><i class="fas fa-heart"></i> Estado civil</div>
                                <div class="chip-grid" id="chips-estado-civil" style="margin-bottom:14px;">
                                    <div class="chip" data-val="soltero" onclick="chipSingle(this,'fa_estado_civil')">Soltero/a</div>
                                    <div class="chip" data-val="casado" onclick="chipSingle(this,'fa_estado_civil')">Casado/a</div>
                                    <div class="chip" data-val="union_libre" onclick="chipSingle(this,'fa_estado_civil')">Unión libre</div>
                                    <div class="chip" data-val="divorciado" onclick="chipSingle(this,'fa_estado_civil')">Divorciado/a</div>
                                    <div class="chip" data-val="viudo" onclick="chipSingle(this,'fa_estado_civil')">Viudo/a</div>
                                </div>
                                <input type="hidden" name="fa_estado_civil" id="fa_estado_civil">
                                <div class="ficha-sec-title"><i class="fas fa-piggy-bank"></i> Datos de Cuenta de Ahorro</div>
                                <div class="chip-grid" id="chips-tipo-ahorro" style="margin-bottom:14px;">
                                    <div class="chip" data-val="normal" onclick="chipSingle(this,'fa_tipo_ahorro')">Normal</div>
                                    <div class="chip" data-val="programado" onclick="chipSingle(this,'fa_tipo_ahorro')" id="chip-ahorro-prog">Programado</div>
                                    <div class="chip" data-val="infantil" onclick="chipSingle(this,'fa_tipo_ahorro')">Infantil</div>
                                    <div class="chip" data-val="otro" onclick="chipSingle(this,'fa_tipo_ahorro')">Otro</div>
                                </div>
                                <input type="hidden" name="fa_tipo_ahorro" id="fa_tipo_ahorro">
                                <div class="fld-grid">
                                    <div class="fld"><label>Monto inicial estimado (USD)</label>
                                        <input type="number" step="0.01" min="0" name="fa_monto_inicial" placeholder="0.00"></div>
                                    <div class="fld" id="fa-frecuencia-wrap" style="display:none;"><label>Frecuencia de depósito</label>
                                        <select name="fa_frecuencia">
                                            <option value="">—</option>
                                            <option value="diaria">Diaria</option>
                                            <option value="semanal">Semanal</option>
                                            <option value="mensual">Mensual</option>
                                        </select>
                                    </div>
                                    <div class="fld"><label>Observaciones</label>
                                        <textarea name="fa_observaciones" rows="2" placeholder="Notas adicionales..."></textarea></div>
                                </div>
                            </div>
                        </div>

                        <!-- ── FICHA: CUENTA CORRIENTE ── -->
                        <div id="ficha-corriente" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="fas fa-credit-card"></i></div>
                                <div>
                                    <div class="ficha-title">Ficha: Cuenta Corriente</div>
                                    <div class="ficha-sub">Completa los datos para la solicitud</div>
                                </div>
                            </div>
                            <div class="ficha-body">
                                <div class="ficha-sec-title"><i class="fas fa-user"></i> Datos del Titular</div>
                                <div class="fld-grid" style="margin-bottom:14px;">
                                    <div class="fld fld-full"><label>Nombre completo</label>
                                        <input type="text" name="fc_nombre" id="fc-nombre" placeholder="Nombre del titular"></div>
                                    <div class="fld"><label>Cédula</label>
                                        <input type="text" name="fc_cedula" id="fc-cedula" placeholder="Cédula"></div>
                                    <div class="fld"><label>Celular</label>
                                        <input type="tel" name="fc_celular" id="fc-celular" placeholder="09XXXXXXXX"></div>
                                </div>
                                <div class="ficha-sec-title"><i class="fas fa-heart"></i> Estado civil</div>
                                <div class="chip-grid" style="margin-bottom:14px;">
                                    <div class="chip" data-val="soltero"    onclick="chipSingle(this,'fc_estado_civil')">Soltero/a</div>
                                    <div class="chip" data-val="casado"     onclick="chipSingle(this,'fc_estado_civil')">Casado/a</div>
                                    <div class="chip" data-val="union_libre" onclick="chipSingle(this,'fc_estado_civil')">Unión libre</div>
                                    <div class="chip" data-val="divorciado" onclick="chipSingle(this,'fc_estado_civil')">Divorciado/a</div>
                                    <div class="chip" data-val="viudo"      onclick="chipSingle(this,'fc_estado_civil')">Viudo/a</div>
                                </div>
                                <input type="hidden" name="fc_estado_civil" id="fc_estado_civil">
                                <div class="ficha-sec-title"><i class="fas fa-credit-card"></i> Datos de Cuenta Corriente</div>
                                <div class="chip-grid" style="margin-bottom:14px;">
                                    <div class="chip" data-val="personal"   onclick="chipSingle(this,'fc_tipo')">Personal</div>
                                    <div class="chip" data-val="empresarial" onclick="chipSingle(this,'fc_tipo')">Empresarial</div>
                                </div>
                                <input type="hidden" name="fc_tipo" id="fc_tipo">
                                <div class="fld-grid">
                                    <div class="fld"><label>Monto inicial estimado (USD)</label>
                                        <input type="number" step="0.01" min="0" name="fc_monto_inicial" placeholder="0.00"></div>
                                    <div class="fld"><label>Observaciones</label>
                                        <textarea name="fc_observaciones" rows="2" placeholder="Notas adicionales..."></textarea></div>
                                </div>
                            </div>
                        </div>

                        <!-- ── FICHA: INVERSIÓN ── -->
                        <div id="ficha-inversion" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-chart-line"></i></div>
                                <div>
                                    <div class="ficha-title">Ficha: Inversión / Depósito</div>
                                    <div class="ficha-sub">Completa los datos para la solicitud</div>
                                </div>
                            </div>
                            <div class="ficha-body">
                                <div class="ficha-sec-title"><i class="fas fa-tags"></i> Tipo de inversión</div>
                                <div class="chip-grid" style="margin-bottom:14px;">
                                    <div class="chip" data-val="dpf"       onclick="chipSingle(this,'fi_tipo')">DPF</div>
                                    <div class="chip" data-val="acciones"  onclick="chipSingle(this,'fi_tipo')">Acciones</div>
                                    <div class="chip" data-val="fondos"    onclick="chipSingle(this,'fi_tipo')">Fondos</div>
                                    <div class="chip" data-val="otro"      onclick="chipSingle(this,'fi_tipo')">Otro</div>
                                </div>
                                <input type="hidden" name="fi_tipo" id="fi_tipo">
                                <div class="fld-grid" style="margin-bottom:14px;">
                                    <div class="fld"><label>Monto a invertir (USD)</label>
                                        <input type="number" step="0.01" min="0" name="fi_monto" placeholder="0.00"></div>
                                    <div class="fld"><label>Plazo deseado</label>
                                        <input type="text" name="fi_plazo" placeholder="Ej: 6 meses, 1 año"></div>
                                </div>
                                <div class="ficha-sec-title"><i class="fas fa-bullseye"></i> Objetivo de inversión</div>
                                <div class="chip-grid" style="margin-bottom:14px;">
                                    <div class="chip" data-val="rendimiento_fijo" onclick="chipSingle(this,'fi_objetivo')">Rendimiento fijo</div>
                                    <div class="chip" data-val="capitalizacion"   onclick="chipSingle(this,'fi_objetivo')">Capitalización</div>
                                    <div class="chip" data-val="crecimiento"      onclick="chipSingle(this,'fi_objetivo')">Crecimiento</div>
                                    <div class="chip" data-val="otro"             onclick="chipSingle(this,'fi_objetivo')">Otro</div>
                                </div>
                                <input type="hidden" name="fi_objetivo" id="fi_objetivo">
                                <div class="fld-grid">
                                    <div class="fld"><label>¿Inversiones en otra institución?</label>
                                        <div class="yn-group">
                                            <label class="yn-opt"><input type="radio" name="fi_otra_institucion" value="1"> Sí</label>
                                            <label class="yn-opt"><input type="radio" name="fi_otra_institucion" value="0"> No</label>
                                        </div>
                                    </div>
                                    <div class="fld"><label>¿Acepta renovación automática?</label>
                                        <div class="yn-group">
                                            <label class="yn-opt"><input type="radio" name="fi_renovacion_automatica" value="1"> Sí</label>
                                            <label class="yn-opt"><input type="radio" name="fi_renovacion_automatica" value="0"> No</label>
                                        </div>
                                    </div>
                                    <div class="fld fld-full"><label>Observaciones</label>
                                        <textarea name="fi_observaciones" rows="2" placeholder="Notas adicionales..."></textarea></div>
                                </div>
                            </div>
                        </div>

                        <!-- ── FICHA: CRÉDITO ── -->
                        <div id="ficha-credito" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-hand-holding-dollar"></i></div>
                                <div>
                                    <div class="ficha-title">Ficha: Evaluación de Crédito</div>
                                    <div class="ficha-sub">Completa los datos para la solicitud</div>
                                </div>
                            </div>
                            <div class="ficha-body">
                                <div class="ficha-sec-title"><i class="fas fa-circle-question"></i> ¿Requiere crédito?</div>
                                <div class="yn-group" style="max-width:200px;margin-bottom:14px;">
                                    <label class="yn-opt"><input type="radio" name="fk_requiere_credito" value="1"> Sí</label>
                                    <label class="yn-opt"><input type="radio" name="fk_requiere_credito" value="0"> No</label>
                                </div>
                                <div class="ficha-sec-title"><i class="fas fa-bullseye"></i> Destino del crédito</div>
                                <div class="chip-grid" style="margin-bottom:14px;">
                                    <div class="chip" data-val="capital_trabajo"    onclick="chipSingle(this,'fk_destino')"><i class="fas fa-briefcase"></i> Capital de trabajo</div>
                                    <div class="chip" data-val="activos_fijos"      onclick="chipSingle(this,'fk_destino')"><i class="fas fa-boxes-stacked"></i> Activos fijos</div>
                                    <div class="chip" data-val="pago_deudas"        onclick="chipSingle(this,'fk_destino')"><i class="fas fa-ban-smoking"></i> Pago de deudas</div>
                                    <div class="chip" data-val="consolidacion"      onclick="chipSingle(this,'fk_destino')"><i class="fas fa-layer-group"></i> Consolidación de deudas</div>
                                    <div class="chip" data-val="vehiculo"           onclick="chipSingle(this,'fk_destino')"><i class="fas fa-car"></i> Compra de vehículo</div>
                                    <div class="chip" data-val="vivienda"           onclick="chipSingle(this,'fk_destino')"><i class="fas fa-house"></i> Compra de vivienda</div>
                                    <div class="chip" data-val="remodelacion"       onclick="chipSingle(this,'fk_destino')"><i class="fas fa-hammer"></i> Arreglos de vivienda</div>
                                    <div class="chip" data-val="educacion"          onclick="chipSingle(this,'fk_destino')"><i class="fas fa-graduation-cap"></i> Educación</div>
                                    <div class="chip" data-val="viajes"             onclick="chipSingle(this,'fk_destino')"><i class="fas fa-plane"></i> Viajes</div>
                                    <div class="chip" data-val="otros"              onclick="chipSingle(this,'fk_destino')"><i class="fas fa-ellipsis"></i> Otros</div>
                                </div>
                                <input type="hidden" name="fk_destino" id="fk_destino">
                                <div class="fld-grid">
                                    <div class="fld"><label>Monto aproximado (USD)</label>
                                        <input type="number" step="0.01" min="0" name="fk_monto" placeholder="0.00"></div>
                                    <div class="fld"><label>Plazo (meses)</label>
                                        <input type="number" min="1" name="fk_plazo" placeholder="Ej: 24"></div>
                                    <div class="fld fld-full"><label>Observaciones</label>
                                        <textarea name="fk_observaciones" rows="2" placeholder="Notas adicionales..."></textarea></div>
                                </div>
                            </div>
                        </div>

                        <div id="aviso-credito-empresa" class="warn-banner" style="display:none;margin-top:14px;">
                            <i class="fas fa-triangle-exclamation"></i>
                            <div>
                                El prospecto tiene empresa. Para solicitar un crédito empresarial, el asesor debe
                                completar primero el <a href="levantamiento_empresa.php" target="_blank">Levantamiento de Empresa</a>.
                                El supervisor deberá aprobarlo antes de continuar.
                            </div>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-circle-xmark"></i>Razones para no contratar</h5>
                        <p style="font-size:13px;color:var(--brand-gray);margin-bottom:12px;">Si no mostró interés, ¿cuál fue la razón?</p>
                        <div class="fld-grid">
                            <?= ynBlock('Ya trabaja con la institución',   'ec_razon_ya_trabaja') ?>
                            <?= ynBlock('Desconfía de los servicios',      'ec_razon_desconfia') ?>
                            <?= ynBlock('Está a gusto con su banco actual','ec_razon_agusto_actual') ?>
                            <?= ynBlock('Mala experiencia previa',         'ec_razon_mala_experiencia') ?>
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

            <!-- FOOTER NAVEGACIÓN -->
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
   BÚSQUEDA POR CÉDULA — bug fix: siempre muestra stepper+form
══════════════════════════════════════════════════════ */
const btnBuscar = document.getElementById('btn-buscar');
const inpCedula = document.getElementById('inp-cedula');
const searchRes = document.getElementById('search-result');
const stepper   = document.getElementById('stepper');
const formEnc   = document.getElementById('formEncuesta');

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
            searchRes.innerHTML = `
                <div class="found-chip found">
                    <i class="fas fa-circle-check"></i>
                    Encontrado: <strong>${esc(data.data.nombre||'')}</strong>
                    &nbsp;·&nbsp; ${data.tipo === 'cliente' ? 'Cliente' : 'Prospecto'}
                </div>`;

            // Pre-llenar campos
            fill('f-nombre',         data.data.nombre);
            fill('f-cedula',         data.data.cedula);
            fill('f-celular',        data.data.celular);
            fill('f-telefono',       data.data.telefono);
            fill('f-email',          data.data.email);
            fill('f-direccion',      data.data.direccion);
            fill('f-ciudad',         data.data.ciudad);
            fill('f-zona',           data.data.zona);
            fill('f-nombre_empresa', data.data.nombre_empresa);
            setVal('f-estado',       data.data.estado_db || 'prospecto');
            setVal('hid-cliente_id', data.data.id);

            // chip actividad
            if (data.data.actividad) {
                const chip = document.querySelector(`#chips-actividad [data-val="${data.data.actividad}"]`);
                if (chip) { chip.classList.add('selected'); setVal('hid-actividad', data.data.actividad); }
            }

            document.getElementById('info-cargado').style.display = 'flex';

        } else {
            // NO ENCONTRADO — igual mostramos el formulario como nuevo
            searchRes.innerHTML = `
                <div class="found-chip new-prosp">
                    <i class="fas fa-user-plus"></i>
                    Cédula no registrada — llena los datos del nuevo prospecto.
                </div>`;
            fill('f-cedula', ced);
            setVal('hid-cliente_id', '');
            document.getElementById('info-cargado').style.display = 'none';
        }

        // ── SIEMPRE mostrar stepper y formulario ──
        searchRes.style.display = 'block';
        stepper.style.display   = 'flex';
        formEnc.style.display   = 'block';
        show(0);
        stepper.scrollIntoView({ behavior: 'smooth', block: 'start' });

    } catch (err) {
        showMsg('Error al conectar. Inténtalo de nuevo.', 'danger');
        // También mostramos el formulario aunque haya error de red
        stepper.style.display = 'flex';
        formEnc.style.display = 'block';
        show(0);
    } finally {
        btnBuscar.disabled = false;
        btnBuscar.innerHTML = '<i class="fas fa-search"></i> Buscar';
    }
}

function fill(id, val) { const e = document.getElementById(id); if (e && val) e.value = val; }
function setVal(id, val) { const e = document.getElementById(id); if (e) e.value = val||''; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function showMsg(msg, type) {
    searchRes.innerHTML = `<div class="alert alert-${type} mt-2" style="font-size:13px;">${msg}</div>`;
    searchRes.style.display = 'block';
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
    document.getElementById('btn-next').style.display = isLast ? 'none'        : 'inline-flex';
    document.getElementById('btn-save').style.display = isLast ? 'inline-flex' : 'none';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
document.getElementById('btn-prev').onclick = () => show(cur - 1);
document.getElementById('btn-next').onclick = () => {
    if (cur === 0 && !document.getElementById('hid-tipo_prospecto').value) {
        alert('Selecciona el tipo de visita para continuar.');
        return;
    }
    show(cur + 1);
};
stepEls.forEach((s, idx) => s.addEventListener('click', () => { if (idx <= cur) show(idx); }));

/* TIPO VISITA */
function selectVisita(el){
    document.querySelectorAll('.visit-card').forEach(c=>c.classList.remove('selected'));
    el.classList.add('selected');
    setVal('hid-tipo_prospecto', el.dataset.tipo);
}

/* ACTIVIDAD CHIPS */
function toggleChip(el,field){
    el.closest('.chip-grid').querySelectorAll('.chip').forEach(c=>c.classList.remove('selected'));
    el.classList.add('selected');
    setVal('hid-'+field, el.dataset.val);
}

/* REGIMEN TILES */
function selectRegimen(el){
    document.querySelectorAll('.regimen-tile').forEach(t=>t.classList.remove('selected'));
    el.classList.add('selected');
    el.querySelector('input[type=radio]').checked = true;
    var val = el.dataset.val;
    document.getElementById('q-ruc').style.display  = val==='ruc'  ? 'flex':'none';
    document.getElementById('q-rise').style.display = val==='rise' ? 'flex':'none';
    setVal('hid-tiene_ruc',  val==='ruc'  ? '1':'0');
    setVal('hid-tiene_rise', val==='rise' ? '1':'0');
}

/* Q-BTN preguntas RUC/RISE */
document.querySelectorAll('.q-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        btn.closest('.q-actions').querySelectorAll('.q-btn').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        if(btn.dataset.hid) setVal(btn.dataset.hid, btn.dataset.val);
    });
});

/* YN TOGGLE — handles both main form and ficha panels */
var tieneEmpresa = false;
document.addEventListener('click', function(e){
    var o = e.target.closest('.yn-opt');
    if(!o) return;
    var g = o.closest('.yn-group');
    if(!g) return;
    g.querySelectorAll('.yn-opt').forEach(function(x){ x.classList.remove('checked'); });
    o.classList.add('checked');
    var inp = o.querySelector('input');
    if(inp){ inp.checked = true; }
    var name = inp ? inp.name : '';
    var val  = inp ? inp.value : '';
    var map = {
        'ec_mantiene_cuenta_ahorro':       'extras-ahorro',
        'ec_mantiene_cuenta_corriente':    'extras-corriente',
        'ec_tiene_inversiones':            'extras-inversiones',
        'ec_tiene_operaciones_crediticias':'extras-credito'
    };
    if(map[name]) document.getElementById(map[name]).classList.toggle('show', val==='1');
    if(name==='tiene_empresa'){
        tieneEmpresa = (val==='1');
        document.getElementById('extras-empresa').classList.toggle('show', tieneEmpresa);
        var s = document.getElementById('aviso-sin-empresa');
        if(s) s.style.display = tieneEmpresa ? 'none':'block';
        actualizarAvisoCredito();
    }
});
/* Legacy static .yn-group init (keeps original bindings intact) */
document.querySelectorAll('.yn-group').forEach(function(g){
    g.querySelectorAll('.yn-opt').forEach(function(o){
        o.addEventListener('click', function(){
            g.querySelectorAll('.yn-opt').forEach(function(x){ x.classList.remove('checked'); });
            o.classList.add('checked');
            o.querySelector('input').checked = true;
            var name = o.querySelector('input').name;
            var val  = o.querySelector('input').value;
            var map = {
                'ec_mantiene_cuenta_ahorro':       'extras-ahorro',
                'ec_mantiene_cuenta_corriente':    'extras-corriente',
                'ec_tiene_inversiones':            'extras-inversiones',
                'ec_tiene_operaciones_crediticias':'extras-credito'
            };
            if(map[name]) document.getElementById(map[name]).classList.toggle('show', val==='1');
            if(name==='tiene_empresa'){
                tieneEmpresa = (val==='1');
                document.getElementById('extras-empresa').classList.toggle('show', tieneEmpresa);
                var s = document.getElementById('aviso-sin-empresa');
                if(s) s.style.display = tieneEmpresa ? 'none':'block';
                actualizarAvisoCredito();
            }
        });
    });
});

/* PRODUCTOS DE INTERES */
var prodSeleccionados = new Set();
/* single-select chip helper */
function chipSingle(el, hidId){
    var parent = el.parentElement;
    parent.querySelectorAll('.chip').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    var hid = document.getElementById(hidId);
    if(hid) hid.value = el.dataset.val;
    /* special: ahorro programado → show frecuencia */
    if(hidId === 'fa_tipo_ahorro'){
        var fw = document.getElementById('fa-frecuencia-wrap');
        if(fw) fw.style.display = (el.dataset.val === 'programado') ? 'block':'none';
    }
}

function toggleProd(el){
    var prod = el.dataset.prod;
    el.classList.toggle('selected');
    var isOn = el.classList.contains('selected');
    if(isOn) prodSeleccionados.add(prod);
    else prodSeleccionados.delete(prod);
    setVal('hid-prod_interes', Array.from(prodSeleccionados).join(','));
    actualizarAvisoCredito();

    /* show/hide ficha panel */
    var fichaMap = {ahorro:'ficha-ahorro', corriente:'ficha-corriente', inversion:'ficha-inversion', credito:'ficha-credito'};
    var fichaId = fichaMap[prod];
    if(fichaId){
        var fp = document.getElementById(fichaId);
        if(fp){
            fp.style.display = isOn ? 'block':'none';
            /* autofill titular if opening */
            if(isOn && window._prospectoData){
                var d = window._prospectoData;
                if(prod === 'ahorro'){
                    var fn_ = document.getElementById('fa-nombre'); if(fn_) fn_.value = d.nombre||'';
                    var fc_ = document.getElementById('fa-cedula'); if(fc_) fc_.value = d.cedula||'';
                    var fl_ = document.getElementById('fa-celular'); if(fl_) fl_.value = d.celular||d.telefono||'';
                }
                if(prod === 'corriente'){
                    var fn2 = document.getElementById('fc-nombre'); if(fn2) fn2.value = d.nombre||'';
                    var fc2 = document.getElementById('fc-cedula'); if(fc2) fc2.value = d.cedula||'';
                    var fl2 = document.getElementById('fc-celular'); if(fl2) fl2.value = d.celular||d.telefono||'';
                }
            }
            if(isOn) fp.scrollIntoView({behavior:'smooth',block:'nearest'});
        }
    }
}
function actualizarAvisoCredito(){
    var a = document.getElementById('aviso-credito-empresa');
    if(a) a.style.display = (tieneEmpresa && prodSeleccionados.has('credito')) ? 'flex':'none';
}

/* NIVEL INTERES */
function selectLevel(el){
    document.querySelectorAll('.level-card').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    setVal('hid-nivel_interes', el.dataset.val);
}

/* GEO */
if(navigator.geolocation){
    navigator.geolocation.getCurrentPosition(
        function(p){ setVal('lat',p.coords.latitude); setVal('lng',p.coords.longitude); },
        function(){}, {timeout:5000}
    );
}
</script>
</body>
</html>

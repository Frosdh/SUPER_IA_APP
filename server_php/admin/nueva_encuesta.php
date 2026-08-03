<?php
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id'];
$asesor_nombre     = $_SESSION['asesor_nombre'] ?? 'Asesor';
$asesor_table_id   = $_SESSION['asesor_table_id'] ?? null;

// Obtener lista de cooperativas/bancos: unidad_bancaria + seps_cooperativas (siempre combinadas)
$unidades_bancarias = [];
try {
    $stmt = $pdo->query("SELECT nombre FROM unidad_bancaria ORDER BY nombre ASC");
    $unidades_bancarias = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    error_log("Error cargando unidades bancarias: " . $e->getMessage());
}
// Siempre agregar SEPS encima (no solo como fallback)
try {
    $stmt2 = $pdo->query("SELECT razon_social AS nombre FROM seps_cooperativas WHERE activo = 1 ORDER BY razon_social ASC LIMIT 500");
    $seps = $stmt2->fetchAll(PDO::FETCH_COLUMN) ?: [];
    // Combinar sin duplicados (insensible a mayúsculas)
    $existentes = array_map('mb_strtolower', $unidades_bancarias);
    foreach ($seps as $s) {
        if (!in_array(mb_strtolower($s), $existentes, true)) {
            $unidades_bancarias[] = $s;
        }
    }
} catch (Exception $e2) { /* tabla aún no existe */ }
// Ordenar alfabéticamente
usort($unidades_bancarias, fn($a, $b) => mb_strtolower($a) <=> mb_strtolower($b));

if (!$asesor_table_id) {
    try {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$asesor_usuario_id]);
        $asesor_table_id = $st->fetchColumn() ?: null;
    } catch (PDOException $e) {}
}

// Obtener el historial de encuestas del asesor
$historico_encuestas = [];
if ($asesor_table_id) {
    try {
        $st = $pdo->prepare("
            SELECT 
                t.id AS tarea_id,
                t.tipo_tarea AS tipo_tarea,
                t.fecha_realizada,
                t.hora_realizada,
                cp.id AS cliente_id,
                cp.nombre AS cliente_nombre,
                cp.cedula AS cliente_cedula,
                ec.acuerdo_logrado,
                ec.nivel_interes_captado,
                ec.interes_ahorro,
                ec.interes_cc,
                ec.interes_inversion,
                ec.interes_credito
            FROM tarea t
            JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            JOIN encuesta_comercial ec ON ec.tarea_id = t.id
            WHERE t.asesor_id = ? AND t.estado = 'completada'
            ORDER BY t.fecha_realizada DESC, t.hora_realizada DESC
        ");
        $st->execute([$asesor_table_id]);
        $historico_encuestas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // En caso de que no existan las columnas o tablas, fallar silenciosamente
    }
}

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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
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
.skip-row{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap;}
.btn-skip{background:#fff;color:var(--brand-navy);border:1.5px dashed var(--brand-border);border-radius:12px;padding:10px 18px;font-weight:700;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:.2s;}
.btn-skip:hover{background:#f3f4f6;border-color:var(--brand-navy);color:var(--brand-navy-deep);}
.skip-hint{font-size:12px;color:var(--brand-gray);}
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
.p2-prod-opt.checked{background:linear-gradient(135deg,#fffde7,#fff9c4) !important;color:var(--brand-navy-deep) !important;border-color:var(--brand-yellow-deep) !important;box-shadow:0 4px 12px rgba(255,221,0,.25) !important;}

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

.resultado-direccion{padding:8px 12px;font-size:13px;cursor:pointer;border-bottom:1px solid var(--brand-border);}
.resultado-direccion:last-child{border-bottom:none;}
.resultado-direccion:hover{background:var(--brand-bg);}
.resultado-direccion i{color:var(--brand-navy);margin-right:4px;}
#mapa-ubicacion{cursor:pointer;}
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

/* ── BUSCA INSTITUCI├ôN ── */
.busca-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-top:4px;}
.busca-item{cursor:pointer;}
.busca-item input[type="checkbox"]{display:none;}
.busca-card{border:2px solid var(--brand-border);border-radius:12px;padding:14px 10px;text-align:center;font-size:12px;font-weight:700;color:#374151;transition:.2s;background:#fff;display:flex;flex-direction:column;align-items:center;gap:6px;}
.busca-card:hover{border-color:var(--brand-navy);background:#f0f4ff;}
.busca-icon{font-size:22px;}
.busca-item input:checked + .busca-card{border-color:var(--brand-yellow-deep);background:linear-gradient(135deg,#fef9c3,#fde68a);color:var(--brand-navy-deep);}

/* ── FICHA PANELS ── */
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


/* ── DOC CHECKLIST ── */
.doc-check-list{display:flex;flex-direction:column;gap:8px;margin:8px 0;}
.doc-item{display:flex;align-items:center;gap:12px;padding:10px 14px;
    border:1.5px solid var(--brand-border);border-radius:10px;cursor:pointer;
    transition:all .15s;background:#fff;user-select:none;}
.doc-item.checked{background:#eff6ff;border-color:#3b82f6;}
.doc-item .di-icon{width:34px;height:34px;border-radius:8px;background:#f3f4f6;
    display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;color:#374151;}
.doc-item.checked .di-icon{background:#dbeafe;color:#1d4ed8;}
.doc-item .di-text{flex:1;}
.doc-item .di-label{font-size:.88rem;font-weight:600;color:#1f2937;}
.doc-item .di-sub{font-size:.75rem;color:#6b7280;}
.doc-item .di-chk{width:22px;height:22px;border-radius:6px;border:2px solid #d1d5db;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.7rem;color:transparent;}
.doc-item.checked .di-chk{background:#3b82f6;border-color:#3b82f6;color:#fff;}
/* ── INSTITUTION PICKER ── */
.inst-picker{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px 0 12px;}
.inst-chip{padding:5px 12px;border-radius:20px;border:1.5px solid var(--brand-border);
    background:#fff;font-size:.8rem;cursor:pointer;transition:all .12s;}
.inst-chip.sel{background:var(--brand-navy);border-color:var(--brand-navy);color:#fff;}
/* ── GARANTE / C├ôNYUGE ── */
.subsec-divider{border:none;border-top:1.5px dashed var(--brand-border);margin:14px 0;}
.subsec-title{font-size:.84rem;font-weight:700;color:#374151;margin:10px 0 8px;
    display:flex;align-items:center;gap:6px;}
.conyuge-wrap{background:#f9fafb;border:1px solid var(--brand-border);
    border-radius:10px;padding:12px;margin-top:8px;}
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



        <!-- ── B├ÜSQUEDA POR CÉDULA ── -->
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
            <div class="skip-row">
                <button class="btn-skip" id="btn-omitir" type="button" onclick="omitirBusqueda()">
                    <i class="fas fa-forward"></i> Continuar sin buscar / cédula
                </button>
                <span class="skip-hint">Inicia la encuesta como prospecto nuevo sin necesidad de cédula.</span>
            </div>
            <div id="search-result" style="display:none;"></div>
        </div>

        <!-- ── STEPPER ── -->
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

        <!-- ── FORMULARIO ── -->
        <form id="formEncuesta" method="post" autocomplete="off" style="display:none;">
            <input type="hidden" name="tarea_id"      id="hid-tarea_id">
            <input type="hidden" name="cliente_id"     id="hid-cliente_id">
            <input type="hidden" name="tipo_prospecto" id="hid-tipo_prospecto">
            <input type="hidden" name="actividad"      id="hid-actividad">
            <input type="hidden" name="nivel_interes"  id="hid-nivel_interes">
            <input type="hidden" name="prod_interes"   id="hid-prod_interes">
            <input type="hidden" name="latitud_inicio" id="latitud_inicio">
            <input type="hidden" name="longitud_inicio" id="longitud_inicio">
            <input type="hidden" name="asesor_id"  value="<?= $asesor_table_id ?>">
            <input type="hidden" name="usuario_id" value="<?= $asesor_usuario_id ?>">
            <!-- campos q-card ocultos -->
            <input type="hidden" name="ruc_declara_iva"    id="hid-ruc_declara_iva">
            <input type="hidden" name="ruc_emite_facturas" id="hid-ruc_emite_facturas">
            <input type="hidden" name="ruc_lleva_contab"   id="hid-ruc_lleva_contab">
            <input type="hidden" name="rise_paga_cuota"    id="hid-rise_paga_cuota">
            <input type="hidden" name="rise_emite_notas"   id="hid-rise_emite_notas">
            <input type="hidden" name="rise_conoce_limite" id="hid-rise_conoce_limite">

            <!-- ──────────────────────────────────────
                 PASO 1 — TIPO DE VISITA
            ─────────────────────────────────────── -->
            <!-- ──────────────────────────────────────
                 PASO 0 — IDENTIFICACIÓN INSTITUCIONAL
            ─────────────────────────────────────── -->
            <div class="step-pane active" data-pane="0">
                <div class="form-card">
                    <h3><i class="fas fa-id-card"></i> Identificación Institucional</h3>
                    <p class="sub">Preguntas iniciales de contacto.</p>

                    <!-- Pregunta 1 -->
                    <div class="sub-sec" style="margin-bottom:20px; border-bottom: 1px solid var(--brand-border); padding-bottom: 20px;">
                        <h5 style="color: var(--brand-navy); font-weight: 600; margin-bottom: 10px;">¿Conoce nuestra institución?</h5>
                        <div class="yn-group" style="margin-bottom: 12px;">
                            <label class="yn-opt" id="p1-si">
                                <input type="radio" name="p1_conoce_institucion" value="1"> Sí
                            </label>
                            <label class="yn-opt" id="p1-no">
                                <input type="radio" name="p1_conoce_institucion" value="0"> No
                            </label>
                        </div>
                        <div class="fld full">
                            <label>Observaciones / Detalles</label>
                            <input type="text" name="p1_obs" id="f-p1_obs" placeholder="Ej: Escuchó en radio, recomendación, etc.">
                        </div>
                    </div>

                    <!-- Pregunta 2 -->
                    <div class="sub-sec" style="margin-bottom:20px; border-bottom: 1px solid var(--brand-border); padding-bottom: 20px;">
                        <h5 style="color: var(--brand-navy); font-weight: 600; margin-bottom: 10px;">¿Es o ha sido cliente de nuestra institución?</h5>
                        <div class="yn-group" style="margin-bottom: 12px;">
                            <label class="yn-opt" id="p2-si">
                                <input type="radio" name="p2_es_cliente" value="1"> Sí
                            </label>
                            <label class="yn-opt" id="p2-no">
                                <input type="radio" name="p2_es_cliente" value="0"> No
                            </label>
                        </div>
                        <div class="fld full" id="p2-extra" style="display:none; margin-bottom: 10px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500;">Productos que mantiene o mantuvo (Selección múltiple)</label>
                            <div class="p2-prod-chips" id="grp-p2-productos" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <label class="p2-prod-opt" data-val="ahorro" onclick="toggleP2Prod(this)" style="padding: 8px 16px; border-radius: 20px; border: 1.5px solid var(--brand-border); background: #fff; cursor: pointer; transition: 0.15s; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="far fa-square" style="font-size:12px;"></i> Cuenta de Ahorro
                                </label>
                                <label class="p2-prod-opt" data-val="corriente" onclick="toggleP2Prod(this)" style="padding: 8px 16px; border-radius: 20px; border: 1.5px solid var(--brand-border); background: #fff; cursor: pointer; transition: 0.15s; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="far fa-square" style="font-size:12px;"></i> Cuenta Corriente
                                </label>
                                <label class="p2-prod-opt" data-val="inversion" onclick="toggleP2Prod(this)" style="padding: 8px 16px; border-radius: 20px; border: 1.5px solid var(--brand-border); background: #fff; cursor: pointer; transition: 0.15s; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="far fa-square" style="font-size:12px;"></i> Inversión / Depósito
                                </label>
                                <label class="p2-prod-opt" data-val="credito" onclick="toggleP2Prod(this)" style="padding: 8px 16px; border-radius: 20px; border: 1.5px solid var(--brand-border); background: #fff; cursor: pointer; transition: 0.15s; font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="far fa-square" style="font-size:12px;"></i> Crédito
                                </label>
                            </div>
                            <input type="hidden" name="p2_producto" id="f-p2_producto">
                        </div>
                        <div class="fld full">
                            <label>Observaciones / Detalles</label>
                            <input type="text" name="p2_obs" id="f-p2_obs" placeholder="Detalles de su relación previa">
                        </div>
                    </div>

                    <!-- Pregunta 3 (solo si Pregunta 2 = Sí) -->
                    <div class="sub-sec" id="p3-sec" style="display:none;">
                        <h5 style="color: var(--brand-navy); font-weight: 600; margin-bottom: 10px;">¿Cuál es su nivel de satisfacción o percepción de la institución?</h5>
                        <p style="font-size:13px;color:var(--brand-gray);margin-bottom:12px;">
                            Selecciona una opción:
                        </p>
                        <div class="chip-grid small" id="chips-p3-satisfaccion" style="margin-bottom: 12px;">
                            <div class="chip" data-val="excelente" onclick="selectSatisfaccion(this)">Excelente</div>
                            <div class="chip" data-val="buena"     onclick="selectSatisfaccion(this)">Buena</div>
                            <div class="chip" data-val="regular"   onclick="selectSatisfaccion(this)">Regular</div>
                            <div class="chip" data-val="mala"      onclick="selectSatisfaccion(this)">Mala</div>
                        </div>
                        <input type="hidden" name="p3_satisfaccion" id="f-p3_satisfaccion">
                        
                        <div class="fld full" style="margin-top:10px;">
                            <label>Observaciones / Detalles</label>
                            <input type="text" name="p3_obs" id="f-p3_obs" placeholder="Comentarios sobre su percepción">
                        </div>
                    </div>

                </div>
            </div>

            <!-- ──────────────────────────────────────
                 PASO 1 — TIPO DE VISITA
            ─────────────────────────────────────── -->
            <div class="step-pane" data-pane="1">
                <div class="form-card">
                    <h3><i class="fas fa-route"></i>Tipo de visita</h3>
                    <p class="sub">¿Cuál es el tipo de prospecto o contacto?</p>
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
                        <div class="visit-card cliente" data-tipo="cliente" onclick="selectVisita(this)">
                            <div class="v-icon"><i class="fas fa-user-check"></i></div>
                            <h4>Cliente</h4>
                            <p>Ya es cliente de nuestra institución.</p>
                        </div>
                        <div class="visit-card leads" data-tipo="leads_llamadas" onclick="selectVisita(this)">
                            <div class="v-icon"><i class="fas fa-phone"></i></div>
                            <h4>Leads/Llamadas</h4>
                            <p>Contacto por links o llamadas.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ──────────────────────────────────────
                 PASO 2 — DATOS PERSONALES
            ─────────────────────────────────────── -->
            <div class="step-pane" data-pane="2">
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
                            <input type="text" name="nombre" id="f-nombre" placeholder="Nombre y apellidos">
                        </div>
                        <div class="fld">
                            <label>Cédula *</label>
                            <input type="text" name="cedula" id="f-cedula" placeholder="Cédula de identidad">
                        </div>
                        
                        <div class="fld">
                            <label>Celular *</label>
                            <input type="tel" name="celular" id="f-celular" placeholder="09XXXXXXXX">
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
                        <div class="fld fld-full" style="grid-column:1/-1;">
                            <label><i class="fas fa-map-marker-alt"></i> Ubicación de la vivienda / negocio</label>
                            <p class="sub" style="margin-bottom:8px;">Busca la calle o sector, o arrastra el pin sobre el mapa para marcar el punto exacto.</p>
                            <div style="display:flex; gap:8px; position:relative;">
                                <input type="text" id="buscador-direccion-mapa" placeholder="Ej: Av. Ordóñez Lasso y ..., Cuenca" style="flex:1;" onkeydown="if(event.key==='Enter'){event.preventDefault();buscarDireccionMapa();}">
                                <button type="button" id="btn-buscar-direccion" class="btn-skip" style="white-space:nowrap;" onclick="buscarDireccionMapa()"><i class="fas fa-search"></i> Buscar</button>
                                <button type="button" class="btn-skip" style="white-space:nowrap;" onclick="usarMiUbicacion()" title="Usar mi ubicación GPS"><i class="fas fa-crosshairs"></i></button>
                                <div id="resultados-busqueda-direccion" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--brand-border); border-radius:8px; box-shadow:var(--brand-shadow-sm); z-index:1000; max-height:220px; overflow-y:auto;"></div>
                            </div>
                            <div id="mapa-ubicacion" style="height:260px; border-radius:10px; margin-top:10px; border:1px solid var(--brand-border);"></div>
                            <div id="ubicacion-info" style="margin-top:6px; font-size:12px; color:var(--brand-gray);">Sin ubicación confirmada aún.</div>
                        </div>
                        <div class="fld">
                            <label>Ciudad</label>
                            <input type="text" name="ciudad" id="f-ciudad" placeholder="Ciudad">
                        </div>
                        <div class="fld">
                            <label>Sector</label>
                            <input type="text" name="zona" id="f-zona" placeholder="Sector o barrio">
                        </div>
                        <div class="fld">
                            <label>Estado</label>
                            <input type="text" id="f-estado" readonly class="form-control-plaintext text-capitalize fw-bold" style="color: var(--brand-navy); background: #f3f4f6; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--brand-border); cursor: not-allowed;" value="prospecto">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ──────────────────────────────────────
                 PASO 3 — ACTIVIDAD + RÉGIMEN
            ─────────────────────────────────────── -->
            <div class="step-pane" data-pane="3">

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
                                <label>Tipo de empresa</label>
                                <select name="tipo_empresa" id="f-tipo_empresa">
                                    <option value="">— Seleccione —</option>
                                    <option value="servicio_produccion">Servicio / Producción</option>
                                    <option value="comercio">Comercio</option>
                                </select>
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

            <!-- ──────────────────────────────────────
                 PASO 4 — SITUACI├ôN FINANCIERA
            ─────────────────────────────────────── -->
            <div class="step-pane" data-pane="4">
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
                                    <div class="fld full" style="width: 100%;"><label>Institución</label>
                                        <select name="ec_institucion_ahorro" style="width: 100%;" data-searchable="true" data-placeholder="Buscar institución (ahorro)...">
                                            <option value="">— Seleccione —</option>
                                            <?php foreach ($unidades_bancarias as $ub): ?>
                                                <option value="<?= htmlspecialchars($ub) ?>"><?= htmlspecialchars($ub) ?></option>
                                            <?php endforeach; ?>
                                        </select>
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
                                        <select name="ec_institucion_corriente" data-searchable="true" data-placeholder="Buscar institución (corriente)...">
                                            <option value="">— Seleccione —</option>
                                            <?php foreach ($unidades_bancarias as $ub): ?>
                                                <option value="<?= htmlspecialchars($ub) ?>"><?= htmlspecialchars($ub) ?></option>
                                            <?php endforeach; ?>
                                        </select>
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
                                        <select name="ec_institucion_inversiones" data-searchable="true" data-placeholder="Buscar institución (inversiones)...">
                                            <option value="">— Seleccione —</option>
                                            <?php foreach ($unidades_bancarias as $ub): ?>
                                                <option value="<?= htmlspecialchars($ub) ?>"><?= htmlspecialchars($ub) ?></option>
                                            <?php endforeach; ?>
                                        </select>
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
                        <div id="sec-propuesta-vencimiento" style="display: none; margin-top: 12px;">
                            <div class="cuenta-box" style="border: 1.5px solid var(--brand-yellow-deep); background: #fffcf0;">
                                <h6 style="color: var(--brand-navy-deep);"><i class="fas fa-handshake"></i> Propuesta de Inversión</h6>
                                <p style="font-size: 12.5px; color: var(--brand-gray); margin-bottom: 8px;">¿Le interesaría que le hagamos una propuesta previa al vencimiento?</p>
                                
                                <div class="yn-group" id="grp-crear-tarea-venc" style="margin-top: 6px; margin-bottom: 12px;">
                                    <label class="yn-opt" id="venc-si" onclick="toggleVencProposal(1)">
                                        <input type="radio" name="crear_tarea_prev_venc" value="1"> Sí
                                    </label>
                                    <label class="yn-opt" id="venc-no" onclick="toggleVencProposal(0)">
                                        <input type="radio" name="crear_tarea_prev_venc" value="0"> No
                                    </label>
                                </div>

                                <div id="extras-propuesta-vencimiento" style="display: none; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; margin-top: 10px;">
                                    <div class="fld" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label style="margin-bottom: 6px; display: block; font-size: 13px; font-weight: 600; color: #374151;">Fecha de contacto para propuesta</label>
                                        <input type="date" name="fecha_previa_vencimiento" id="f-fecha_previa_vencimiento" style="width: 100%;">
                                    </div>
                                    <div class="fld" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label style="margin-bottom: 6px; display: block; font-size: 13px; font-weight: 600; color: #374151;">Hora de contacto</label>
                                        <input type="time" name="hora_previa_vencimiento" id="f-hora_previa_vencimiento" style="width: 100%;">
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
                                    <div class="fld full" style="width: 100%;"><label>Institución</label>
                                        <select name="ec_institucion_credito" style="width: 100%;" data-searchable="true" data-placeholder="Buscar institución (crédito)...">
                                            <option value="">— Seleccione —</option>
                                            <?php foreach ($unidades_bancarias as $ub): ?>
                                                <option value="<?= htmlspecialchars($ub) ?>"><?= htmlspecialchars($ub) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ──────────────────────────────────────
                 PASO 5 — INTERÉS EN SERVICIOS
            ─────────────────────────────────────── -->
            <div class="step-pane" data-pane="5">
                <div class="form-card">
                    <h3><i class="fas fa-star"></i>¿Le interesa adquirir o trabajar con alguno de nuestros productos?</h3>

                    <!-- ¿Está interesado en nuestros productos? -->
                    <div class="sub-sec" style="border-top:none;padding-top:0;margin-top:0;margin-bottom:15px;">
                        <h5 style="margin-bottom: 8px;"><i class="fas fa-heart"></i>¿Está interesado en nuestros productos?</h5>
                        <div class="yn-group" id="grp-interesado-productos" style="margin-top: 6px;">
                            <label class="yn-opt" id="opt-interes-si" onclick="toggleInteresProductos(1)">
                                <input type="radio" name="interesado_productos" value="1"> Sí
                            </label>
                            <label class="yn-opt" id="opt-interes-no" onclick="toggleInteresProductos(0)">
                                <input type="radio" name="interesado_productos" value="0"> No
                            </label>
                        </div>
                    </div>

                    <div id="sec-interes-detalles" style="display: none; margin-top: 15px;">

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
                                <div><div class="ficha-title">Ficha: Cuenta de Ahorro</div><div class="ficha-sub">Completa los datos para la solicitud</div></div>
                            </div>
                            <div class="ficha-body">
                                <div class="ficha-sec-title"><i class="fas fa-user"></i> Datos del Titular</div>
                                <div class="fld-grid" style="margin-bottom:10px;">
                                    <div class="fld fld-full"><label>Nombre completo</label><input type="text" name="fa_nombre" id="fa-nombre" placeholder="Nombre del titular"></div>
                                    <div class="fld"><label>C&eacute;dula</label><input type="text" name="fa_cedula" id="fa-cedula" placeholder="C&eacute;dula" inputmode="numeric"></div>
                                    <div class="fld"><label>Celular</label><input type="tel" name="fa_celular" id="fa-celular" placeholder="09XXXXXXXX"></div>
                                </div>
                                <div class="ficha-sec-title" style="margin-top:0;"><i class="fas fa-heart"></i> Estado civil</div>
                                                                <div class="chip-grid" style="margin-bottom:10px;">
                                    <div class="chip" data-val="soltero"     onclick="chipSingle(this,'fa_estado_civil')">Soltero/a</div>
                                    <div class="chip" data-val="casado"      onclick="chipSingle(this,'fa_estado_civil')">Casado/a</div>
                                    <div class="chip" data-val="union_libre" onclick="chipSingle(this,'fa_estado_civil')">Uni&oacute;n libre</div>
                                    <div class="chip" data-val="divorciado"  onclick="chipSingle(this,'fa_estado_civil')">Divorciado/a</div>
                                </div>
                                <input type="hidden" name="fa_estado_civil" id="fa_estado_civil">

                                <div class="ficha-sec-title"><i class="fas fa-piggy-bank"></i> Datos de Cuenta de Ahorros</div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Tipo de ahorro</div>
                                <div class="chip-grid" style="margin-bottom:12px;">
                                    <div class="chip" data-val="normal"     onclick="chipSingle(this,'fa_tipo_ahorro')">Normal</div>
                                    <div class="chip" data-val="programado" onclick="chipSingle(this,'fa_tipo_ahorro')">Programado</div>
                                    <div class="chip" data-val="infantil"   onclick="chipSingle(this,'fa_tipo_ahorro')">Infantil</div>
                                    <div class="chip" data-val="otro"       onclick="chipSingle(this,'fa_tipo_ahorro')">Otro</div>
                                </div>
                                <input type="hidden" name="fa_tipo_ahorro" id="fa_tipo_ahorro">
                                <div class="fld fld-full" style="margin-bottom:12px;"><label>Monto inicial estimado ($)</label>
                                    <input type="number" step="0.01" min="0" name="fa_monto_inicial" placeholder="0.00"></div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Frecuencia de dep&oacute;sito</div>
                                <div class="chip-grid" style="margin-bottom:12px;" id="fa-frecuencia-wrap">
                                    <div class="chip" data-val="diaria"    onclick="chipSingle(this,'fa_frecuencia')">Diaria</div>
                                    <div class="chip" data-val="semanal"   onclick="chipSingle(this,'fa_frecuencia')">Semanal</div>
                                    <div class="chip" data-val="quincenal" onclick="chipSingle(this,'fa_frecuencia')">Quincenal</div>
                                    <div class="chip" data-val="mensual"   onclick="chipSingle(this,'fa_frecuencia')">Mensual</div>
                                </div>
                                <input type="hidden" name="fa_frecuencia" id="fa_frecuencia">
                                <div class="fld fld-full" style="margin-bottom:12px;"><label>Objetivo del ahorro</label>
                                    <textarea name="fa_objetivo" rows="2" placeholder="Ej: ahorro para educaci&oacute;n, emergencias..."></textarea></div>

                                <div class="ficha-sec-title"><i class="fas fa-list-check" style="color:#3b82f6;"></i> Documentos (Cuenta de Ahorros)</div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:10px;">Toca cada documento que el prospecto entreg&oacute;:</p>
                                <div class="doc-check-list">
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fa_doc_cedula">
                                        <div class="di-icon"><i class="fas fa-id-card"></i></div>
                                        <div class="di-text"><div class="di-label">C&eacute;dula de identidad</div><div class="di-sub">Original y copia</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fa_doc_cedula" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fa_doc_papeleta">
                                        <div class="di-icon"><i class="fas fa-check-to-slot"></i></div>
                                        <div class="di-text"><div class="di-label">Papeleta de votaci&oacute;n</div><div class="di-sub">Obligatoria (Ecuador)</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fa_doc_papeleta" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fa_doc_planilla">
                                        <div class="di-icon"><i class="fas fa-file-invoice"></i></div>
                                        <div class="di-text"><div class="di-label">Planilla de servicios b&aacute;sicos</div><div class="di-sub">Luz, agua o tel&eacute;fono</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fa_doc_planilla" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fa_doc_correo">
                                        <div class="di-icon"><i class="fas fa-envelope"></i></div>
                                        <div class="di-text"><div class="di-label">Correo electr&oacute;nico</div><div class="di-sub">Para notificaciones</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fa_doc_correo" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fa_doc_celular">
                                        <div class="di-icon"><i class="fas fa-phone"></i></div>
                                        <div class="di-text"><div class="di-label">N&uacute;mero de celular</div><div class="di-sub">Contacto del titular</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fa_doc_celular" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fa_doc_deposito">
                                        <div class="di-icon"><i class="fas fa-dollar-sign"></i></div>
                                        <div class="di-text"><div class="di-label">Dep&oacute;sito inicial</div><div class="di-sub">Seg&uacute;n entidad (ej. $5&ndash;$50)</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fa_doc_deposito" value="0" class="doc-hidden">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="ficha-corriente" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="fas fa-credit-card"></i></div>
                                <div><div class="ficha-title">Ficha: Cuenta Corriente</div><div class="ficha-sub">Completa los datos para la solicitud</div></div>
                            </div>
                            <div class="ficha-body">
                                <!-- Tipo de cuenta corriente -->
                                <div class="ficha-sec-title" style="margin-top:0;"><i class="fas fa-building"></i> Tipo de cuenta</div>
                                <div class="chip-grid" style="margin-bottom:14px;">
                                    <div class="chip" data-val="personal"   onclick="chipSingle(this,'fc_tipo_cc')">&#x1F464; Personal</div>
                                    <div class="chip" data-val="empresarial" onclick="chipSingle(this,'fc_tipo_cc')">&#x1F3E2; Empresarial</div>
                                </div>
                                <input type="hidden" name="fc_tipo_cc" id="fc_tipo_cc">

                                <div class="ficha-sec-title"><i class="fas fa-user"></i> Datos del Titular</div>
                                <div class="fld-grid" style="margin-bottom:10px;">
                                    <div class="fld fld-full"><label>Nombre completo</label><input type="text" name="fc_nombre" id="fc-nombre" placeholder="Nombre del titular"></div>
                                    <div class="fld"><label>C&eacute;dula</label><input type="text" name="fc_cedula" id="fc-cedula" placeholder="C&eacute;dula" inputmode="numeric"></div>
                                    <div class="fld"><label>Celular</label><input type="tel" name="fc_celular" id="fc-celular" placeholder="09XXXXXXXX"></div>
                                </div>
                                <div class="ficha-sec-title" style="margin-top:0;"><i class="fas fa-heart"></i> Estado civil</div>
                                                                <div class="chip-grid" style="margin-bottom:10px;">
                                    <div class="chip" data-val="soltero"     onclick="chipSingle(this,'fc_estado_civil')">Soltero/a</div>
                                    <div class="chip" data-val="casado"      onclick="chipSingle(this,'fc_estado_civil')">Casado/a</div>
                                    <div class="chip" data-val="union_libre" onclick="chipSingle(this,'fc_estado_civil')">Uni&oacute;n libre</div>
                                    <div class="chip" data-val="divorciado"  onclick="chipSingle(this,'fc_estado_civil')">Divorciado/a</div>
                                </div>
                                <input type="hidden" name="fc_estado_civil" id="fc_estado_civil">

                                <div class="ficha-sec-title"><i class="fas fa-credit-card"></i> Datos de Cuenta Corriente</div>
                                <div class="fld fld-full" style="margin-bottom:10px;"><label>Prop&oacute;sito principal de la cuenta</label>
                                    <textarea name="fc_proposito" rows="2" placeholder="Ej: pagos a proveedores, n&oacute;mina..."></textarea></div>
                                <div class="fld-grid" style="margin-bottom:10px;">
                                    <div class="fld"><label>Monto dep&oacute;sito mensual promedio ($)</label>
                                        <input type="number" step="0.01" min="0" name="fc_monto_deposito" placeholder="0.00"></div>
                                    <div class="fld"><label>Ingreso mensual estimado ($)</label>
                                        <input type="number" step="0.01" min="0" name="fc_ingreso_mensual" placeholder="0.00"></div>
                                </div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">&iquest;Usa cheques frecuentemente?</div>
                                                                <div class="yn-group" style="margin-bottom:12px;">
                                    <label class="yn-opt"><input type="radio" name="fc_usa_cheques" value="1"> S&iacute;</label>
                                    <label class="yn-opt"><input type="radio" name="fc_usa_cheques" value="0"> No</label>
                                </div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">&iquest;Requiere tarjeta de d&eacute;bito?</div>
                                                                <div class="yn-group" style="margin-bottom:12px;">
                                    <label class="yn-opt"><input type="radio" name="fc_requiere_td" value="1"> S&iacute;</label>
                                    <label class="yn-opt"><input type="radio" name="fc_requiere_td" value="0"> No</label>
                                </div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">&iquest;Tiene n&oacute;mina / sueldo fijo?</div>
                                                                <div class="yn-group" style="margin-bottom:12px;">
                                    <label class="yn-opt"><input type="radio" name="fc_tiene_nomina" value="1"> S&iacute;</label>
                                    <label class="yn-opt"><input type="radio" name="fc_tiene_nomina" value="0"> No</label>
                                </div>
                                <div class="fld fld-full" style="margin-bottom:14px;"><label>Observaciones</label>
                                    <textarea name="fc_observaciones" rows="2" placeholder="Notas adicionales..."></textarea></div>

                                <div class="ficha-sec-title"><i class="fas fa-list-check" style="color:#3b82f6;"></i> Documentos (Cuenta Corriente)</div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:10px;">Toca cada documento que el prospecto entreg&oacute;:</p>
                                <div class="doc-check-list">
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fc_doc_cedula">
                                        <div class="di-icon"><i class="fas fa-id-card"></i></div>
                                        <div class="di-text"><div class="di-label">C&eacute;dula de identidad</div><div class="di-sub">Original y copia</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fc_doc_cedula" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fc_doc_papeleta">
                                        <div class="di-icon"><i class="fas fa-check-to-slot"></i></div>
                                        <div class="di-text"><div class="di-label">Papeleta de votaci&oacute;n</div><div class="di-sub">Obligatoria (Ecuador)</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fc_doc_papeleta" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fc_doc_planilla">
                                        <div class="di-icon"><i class="fas fa-file-invoice"></i></div>
                                        <div class="di-text"><div class="di-label">Planilla de servicios b&aacute;sicos</div><div class="di-sub">Luz, agua o tel&eacute;fono</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fc_doc_planilla" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fc_doc_correo">
                                        <div class="di-icon"><i class="fas fa-envelope"></i></div>
                                        <div class="di-text"><div class="di-label">Correo electr&oacute;nico</div><div class="di-sub">Para notificaciones</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fc_doc_correo" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fc_doc_celular">
                                        <div class="di-icon"><i class="fas fa-phone"></i></div>
                                        <div class="di-text"><div class="di-label">N&uacute;mero de celular</div><div class="di-sub">Contacto del titular</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fc_doc_celular" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fc_doc_deposito">
                                        <div class="di-icon"><i class="fas fa-dollar-sign"></i></div>
                                        <div class="di-text"><div class="di-label">Dep&oacute;sito inicial</div><div class="di-sub">Seg&uacute;n entidad</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fc_doc_deposito" value="0" class="doc-hidden">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="ficha-inversion" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#ede9fe;color:#5b21b6;"><i class="fas fa-chart-line"></i></div>
                                <div><div class="ficha-title">Ficha: Inversiones</div><div class="ficha-sub">Completa los datos para la solicitud</div></div>
                            </div>
                            <div class="ficha-body">
                                <div class="ficha-sec-title"><i class="fas fa-trending-up"></i> Datos de Inversi&oacute;n</div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Tipo de inversi&oacute;n</div>
                                <div class="chip-grid" style="margin-bottom:12px;">
                                    <div class="chip" data-val="dpf"      onclick="chipSingle(this,'fi_tipo')">DPF</div>
                                    <div class="chip" data-val="acciones" onclick="chipSingle(this,'fi_tipo')">Acciones</div>
                                    <div class="chip" data-val="otro"     onclick="chipSingle(this,'fi_tipo')">Otro</div>
                                </div>
                                <input type="hidden" name="fi_tipo" id="fi_tipo">
                                <div class="fld-grid" style="margin-bottom:12px;">
                                    <div class="fld"><label>Monto a invertir ($)</label><input type="number" step="0.01" min="0" name="fi_monto" placeholder="0.00"></div>
                                    <div class="fld"><label>Plazo deseado (meses)</label><input type="number" min="1" name="fi_plazo" placeholder="Ej: 12"></div>
                                </div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Objetivo de inversi&oacute;n</div>
                                <div class="chip-grid" style="margin-bottom:12px;">
                                    <div class="chip" data-val="rendimiento_fijo" onclick="chipSingle(this,'fi_objetivo')">Rendimiento fijo</div>
                                    <div class="chip" data-val="capitalizacion"   onclick="chipSingle(this,'fi_objetivo')">Capitalizaci&oacute;n</div>
                                    <div class="chip" data-val="crecimiento"      onclick="chipSingle(this,'fi_objetivo')">Crecimiento</div>
                                    <div class="chip" data-val="otro"             onclick="chipSingle(this,'fi_objetivo')">Otro</div>
                                </div>
                                <input type="hidden" name="fi_objetivo" id="fi_objetivo">
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">&iquest;Acepta renovaci&oacute;n autom&aacute;tica?</div>
                                                                <div class="yn-group" style="margin-bottom:12px;">
                                    <label class="yn-opt"><input type="radio" name="fi_renovacion_automatica" value="1"> S&iacute;</label>
                                    <label class="yn-opt"><input type="radio" name="fi_renovacion_automatica" value="0"> No</label>
                                </div>
                                <div class="fld fld-full" style="margin-bottom:14px;"><label>Observaciones</label>
                                    <textarea name="fi_observaciones" rows="2" placeholder="Notas adicionales..."></textarea></div>

                                <div class="ficha-sec-title"><i class="fas fa-list-check" style="color:#3b82f6;"></i> Requisitos (Inversiones)</div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:10px;">Toca cada requisito que ya est&aacute; listo:</p>
                                <div class="doc-check-list">
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fi_req_cuenta">
                                        <div class="di-icon"><i class="fas fa-university"></i></div>
                                        <div class="di-text"><div class="di-label">Cuenta activa</div><div class="di-sub">Debe tener cuenta en la entidad</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fi_req_cuenta" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fi_req_monto">
                                        <div class="di-icon"><i class="fas fa-coins"></i></div>
                                        <div class="di-text"><div class="di-label">Monto m&iacute;nimo</div><div class="di-sub">Ej. $100 / $500 / $1000</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fi_req_monto" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fi_req_contrato">
                                        <div class="di-icon"><i class="fas fa-file-signature"></i></div>
                                        <div class="di-text"><div class="di-label">Contrato de inversi&oacute;n</div><div class="di-sub">Firmado</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fi_req_contrato" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fi_req_fondos">
                                        <div class="di-icon"><i class="fas fa-shield-alt"></i></div>
                                        <div class="di-text"><div class="di-label">Declaraci&oacute;n de origen de fondos</div><div class="di-sub">En algunos casos</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fi_req_fondos" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fi_req_kyc">
                                        <div class="di-icon"><i class="fas fa-user-check"></i></div>
                                        <div class="di-text"><div class="di-label">Actualizaci&oacute;n de datos (KYC)</div><div class="di-sub">Conoce a tu cliente</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fi_req_kyc" value="0" class="doc-hidden">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="ficha-credito" class="ficha-panel" style="display:none;">
                            <div class="ficha-header">
                                <div class="ficha-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-hand-holding-dollar"></i></div>
                                <div><div class="ficha-title">Ficha: Evaluaci&oacute;n de Cr&eacute;dito</div><div class="ficha-sub">Completa los datos para la solicitud</div></div>
                            </div>
                            <div class="ficha-body">
                                <!-- ¿Le gustaría adquirir un crédito? -->
                                <div class="sub-sec" style="border-top:none;padding-top:0;margin-top:0;margin-bottom:15px;">
                                    <h5 style="margin-bottom: 8px;"><i class="fas fa-question-circle"></i> ¿Le gustar&iacute;a adquirir un cr&eacute;dito?</h5>
                                    <div class="yn-group" id="grp-requiere-credito" style="margin-top: 6px;">
                                        <label class="yn-opt" id="opt-reqcredito-si" onclick="toggleRequiereCredito(1)">
                                            <input type="radio" name="fk_requiere_credito" value="1"> S&iacute;
                                        </label>
                                        <label class="yn-opt" id="opt-reqcredito-no" onclick="toggleRequiereCredito(0)">
                                            <input type="radio" name="fk_requiere_credito" value="0"> No
                                        </label>
                                    </div>
                                </div>

                                <div id="fk-evaluacion-completa" style="display: none; margin-top: 15px;">
                                    <div class="ficha-sec-title"><i class="fas fa-credit-score"></i> Evaluaci&oacute;n de Cr&eacute;dito</div>
                                    <div id="fk-detalle-wrap">
                                    <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Destino del cr&eacute;dito</div>
                                    <div class="chip-grid" style="margin-bottom:10px;">
                                        <div class="chip" data-val="capital_trabajo" onclick="chipSingle(this,'fk_destino')"><i class="fas fa-briefcase"></i> Capital de trabajo</div>
                                        <div class="chip" data-val="activos_fijos"   onclick="chipSingle(this,'fk_destino')"><i class="fas fa-boxes-stacked"></i> Activos fijos</div>
                                        <div class="chip" data-val="pago_deudas"     onclick="chipSingle(this,'fk_destino')"><i class="fas fa-ban"></i> Pago de deudas</div>
                                        <div class="chip" data-val="consolidacion"   onclick="chipSingle(this,'fk_destino')"><i class="fas fa-layer-group"></i> Consolidaci&oacute;n</div>
                                        <div class="chip" data-val="vehiculo"        onclick="chipSingle(this,'fk_destino')"><i class="fas fa-car"></i> Veh&iacute;culo</div>
                                        <div class="chip" data-val="vivienda"        onclick="chipSingle(this,'fk_destino')"><i class="fas fa-house"></i> Vivienda</div>
                                        <div class="chip" data-val="remodelacion"    onclick="chipSingle(this,'fk_destino')"><i class="fas fa-hammer"></i> Remodelaci&oacute;n</div>
                                        <div class="chip" data-val="educacion"       onclick="chipSingle(this,'fk_destino')"><i class="fas fa-graduation-cap"></i> Educaci&oacute;n</div>
                                        <div class="chip" data-val="viajes"          onclick="chipSingle(this,'fk_destino')"><i class="fas fa-plane"></i> Viajes</div>
                                        <div class="chip" data-val="otros"           onclick="chipSingle(this,'fk_destino')"><i class="fas fa-ellipsis"></i> Otros</div>
                                    </div>
                                    <input type="hidden" name="fk_destino" id="fk_destino">
                                    <div id="fk-otros-wrap" style="display:none;margin-bottom:10px;">
                                        <div class="fld fld-full"><label>Especifique otro destino</label>
                                            <input type="text" name="fk_destino_otros" placeholder="Detalle..."></div>
                                    </div>
                                    <div class="fld-grid" style="margin-bottom:14px;">
                                        <div class="fld"><label>Monto aproximado ($)</label><input type="number" step="0.01" min="0" name="fk_monto" placeholder="0.00"></div>
                                        <div class="fld"><label>Plazo (meses)</label><input type="number" min="1" name="fk_plazo" placeholder="Ej: 24"></div>
                                    </div>
                                </div>

                                <div class="ficha-sec-title"><i class="fas fa-people-group"></i> Datos del Solicitante y Garante</div>
                                <div class="fld fld-full" style="margin-bottom:10px;"><label>Direcci&oacute;n levantada en sitio</label>
                                    <textarea name="fk_direccion_sitio" rows="2" placeholder="Direcci&oacute;n del negocio / domicilio"></textarea></div>
                                <hr class="subsec-divider">
                                <div class="subsec-title"><i class="fas fa-user"></i> Solicitante (Deudor)</div>
                                <div class="fld-grid" style="margin-bottom:8px;">
                                    <div class="fld fld-full"><label>Nombre completo</label><input type="text" name="fk_sol_nombre" id="fk-sol-nombre" placeholder="Nombre del solicitante"></div>
                                    <div class="fld"><label>C&eacute;dula</label><input type="text" name="fk_sol_cedula" id="fk-sol-cedula" placeholder="C&eacute;dula" inputmode="numeric"></div>
                                    <div class="fld"><label>Celular</label><input type="tel" name="fk_sol_celular" id="fk-sol-celular" placeholder="09XXXXXXXX"></div>
                                </div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Estado civil del solicitante</div>
                                                                <div class="chip-grid" style="margin-bottom:10px;">
                                    <div class="chip" data-val="soltero"     onclick="chipSingle(this,'fk_sol_ec');toggleConyuge('fk')">Soltero/a</div>
                                    <div class="chip" data-val="casado"      onclick="chipSingle(this,'fk_sol_ec');toggleConyuge('fk')">Casado/a</div>
                                    <div class="chip" data-val="union_libre" onclick="chipSingle(this,'fk_sol_ec');toggleConyuge('fk')">Uni&oacute;n libre</div>
                                    <div class="chip" data-val="divorciado"  onclick="chipSingle(this,'fk_sol_ec');toggleConyuge('fk')">Divorciado/a</div>
                                </div>
                                <input type="hidden" name="fk_sol_ec" id="fk_sol_ec">
                                <div id="fk-conyuge-wrap" class="conyuge-wrap" style="display:none;margin-bottom:12px;">
                                    <div class="subsec-title" style="margin-top:0;"><i class="fas fa-user-plus"></i> C&oacute;nyuge del solicitante</div>
                                    <div class="fld fld-full" style="margin-bottom:8px;"><label>Nombre completo del c&oacute;nyuge</label>
                                        <input type="text" name="fk_sol_conyuge_nombre" placeholder="Nombre del c&oacute;nyuge"></div>
                                    <div class="fld-grid">
                                        <div class="fld"><label>C&eacute;dula del c&oacute;nyuge</label><input type="text" name="fk_sol_conyuge_cedula" placeholder="C&eacute;dula" inputmode="numeric"></div>
                                        <div class="fld"><label>Celular del c&oacute;nyuge</label><input type="tel" name="fk_sol_conyuge_celular" placeholder="09XXXXXXXX"></div>
                                    </div>
                                </div>
                                <hr class="subsec-divider">
                                <div class="subsec-title"><i class="fas fa-user-shield"></i> Garante (opcional)</div>
                                <div class="fld-grid" style="margin-bottom:8px;">
                                    <div class="fld fld-full"><label>Nombre completo del garante</label><input type="text" name="fk_gar_nombre" placeholder="Nombre del garante"></div>
                                    <div class="fld"><label>C&eacute;dula</label><input type="text" name="fk_gar_cedula" placeholder="C&eacute;dula" inputmode="numeric"></div>
                                    <div class="fld"><label>Celular</label><input type="tel" name="fk_gar_celular" placeholder="09XXXXXXXX"></div>
                                </div>
                                <div style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;">Estado civil del garante</div>
                                                                <div class="chip-grid" style="margin-bottom:10px;">
                                    <div class="chip" data-val="soltero"     onclick="chipSingle(this,'fk_gar_ec');toggleConyugeGar()">Soltero/a</div>
                                    <div class="chip" data-val="casado"      onclick="chipSingle(this,'fk_gar_ec');toggleConyugeGar()">Casado/a</div>
                                    <div class="chip" data-val="union_libre" onclick="chipSingle(this,'fk_gar_ec');toggleConyugeGar()">Uni&oacute;n libre</div>
                                    <div class="chip" data-val="divorciado"  onclick="chipSingle(this,'fk_gar_ec');toggleConyugeGar()">Divorciado/a</div>
                                </div>
                                <input type="hidden" name="fk_gar_ec" id="fk_gar_ec">
                                <div id="fk-conyuge-gar-wrap" class="conyuge-wrap" style="display:none;margin-bottom:12px;">
                                    <div class="subsec-title" style="margin-top:0;"><i class="fas fa-user-plus"></i> C&oacute;nyuge del garante</div>
                                    <div class="fld fld-full" style="margin-bottom:8px;"><label>Nombre completo del c&oacute;nyuge</label>
                                        <input type="text" name="fk_gar_conyuge_nombre" placeholder="Nombre del c&oacute;nyuge"></div>
                                    <div class="fld-grid">
                                        <div class="fld"><label>C&eacute;dula del c&oacute;nyuge</label><input type="text" name="fk_gar_conyuge_cedula" placeholder="C&eacute;dula" inputmode="numeric"></div>
                                        <div class="fld"><label>Celular del c&oacute;nyuge</label><input type="tel" name="fk_gar_conyuge_celular" placeholder="09XXXXXXXX"></div>
                                    </div>
                                </div>

                                <div class="ficha-sec-title"><i class="fas fa-list-check" style="color:#3b82f6;"></i> Documentos para Cr&eacute;dito</div>
                                <p style="font-size:.78rem;color:#6b7280;margin-bottom:10px;">Toca cada documento que el prospecto entreg&oacute;:</p>
                                <div class="doc-check-list">
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_cedula">
                                        <div class="di-icon"><i class="fas fa-id-card"></i></div>
                                        <div class="di-text"><div class="di-label">C&eacute;dula de identidad</div><div class="di-sub">Deudor y c&oacute;nyuge</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_cedula" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_planilla">
                                        <div class="di-icon"><i class="fas fa-file-invoice"></i></div>
                                        <div class="di-text"><div class="di-label">Planilla de servicios</div><div class="di-sub">Agua, luz o tel&eacute;fono</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_planilla" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_ruc">
                                        <div class="di-icon"><i class="fas fa-file-alt"></i></div>
                                        <div class="di-text"><div class="di-label">RUC / RISE</div><div class="di-sub">Registro tributario</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_ruc" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_estados">
                                        <div class="di-icon"><i class="fas fa-university"></i></div>
                                        <div class="di-text"><div class="di-label">Estados de cuenta</div><div class="di-sub">&Uacute;ltimos 3 meses</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_estados" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_declaraciones">
                                        <div class="di-icon"><i class="fas fa-clipboard-list"></i></div>
                                        <div class="di-text"><div class="di-label">Declaraciones IVA / IR</div><div class="di-sub">&Uacute;ltimas declaraciones</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_declaraciones" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_matricula">
                                        <div class="di-icon"><i class="fas fa-store"></i></div>
                                        <div class="di-text"><div class="di-label">Matr&iacute;cula del negocio</div><div class="di-sub">Patente municipal</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_matricula" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_foto_negocio">
                                        <div class="di-icon"><i class="fas fa-camera"></i></div>
                                        <div class="di-text"><div class="di-label">Foto del negocio</div><div class="di-sub">Fachada y local</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_foto_negocio" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_solicitud">
                                        <div class="di-icon"><i class="fas fa-file-contract"></i></div>
                                        <div class="di-text"><div class="di-label">Solicitud de cr&eacute;dito</div><div class="di-sub">Formulario firmado</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_solicitud" value="0" class="doc-hidden">
                                    </div>
                                                                        <div class="doc-item" onclick="toggleDoc(this)" data-field="fk_doc_foto_cliente">
                                        <div class="di-icon"><i class="fas fa-portrait"></i></div>
                                        <div class="di-text"><div class="di-label">Foto del prospecto</div><div class="di-sub">Foto para expediente</div></div>
                                        <div class="di-chk"><i class="fas fa-check"></i></div>
                                        <input type="hidden" name="fk_doc_foto_cliente" value="0" class="doc-hidden">
                                    </div>
                                </div>
                                </div> <!-- Fin de fk-evaluacion-completa -->
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

                    </div> <!-- Fin de sec-interes-detalles -->

                    <div id="sec-razones-no-interes" style="display: none; margin-top: 15px;">
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
                                    <textarea name="ec_razon_otros" rows="2" placeholder="Describe brevemente..."></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ──────────────────────────────────────
                 PASO 6 — ACUERDO Y CIERRE
            ─────────────────────────────────────── -->
            <div class="step-pane" data-pane="6">
                <div class="form-card">
                    <h3><i class="fas fa-handshake"></i>Acuerdo y cierre</h3>
                    <p class="sub">Indica el resultado de la visita y el próximo paso pactado con el prospecto.</p>

                    <!-- ¿Qué busca de una institución? -->
                    <div class="sub-sec" style="margin-bottom:20px;">
                        <h5><i class="fas fa-magnifying-glass"></i>&iquest;Qu&eacute; busca de una instituci&oacute;n financiera?</h5>
                        <p style="font-size:13px;color:var(--brand-gray);margin-bottom:14px;">
                            Selecciona todo lo que el prospecto mencion&oacute; como importante:
                        </p>
                        <div class="busca-grid">
                            <label class="busca-item">
                                <input type="checkbox" name="busca_agilidad" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x26A1;</span>
                                    <span>Agilidad</span>
                                </div>
                            </label>
                            <label class="busca-item">
                                <input type="checkbox" name="busca_cajeros" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x1F3E7;</span>
                                    <span>Cajeros</span>
                                </div>
                            </label>
                            <label class="busca-item">
                                <input type="checkbox" name="busca_banca_online" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x1F4BB;</span>
                                    <span>Banca en l&iacute;nea</span>
                                </div>
                            </label>
                            <label class="busca-item">
                                <input type="checkbox" name="busca_agencias" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x1F4CD;</span>
                                    <span>Agencias en su sector</span>
                                </div>
                            </label>
                            <label class="busca-item">
                                <input type="checkbox" name="busca_credito_rapido" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x1F4B8;</span>
                                    <span>Cr&eacute;dito r&aacute;pido</span>
                                </div>
                            </label>
                            <label class="busca-item">
                                <input type="checkbox" name="busca_tarjeta_debito" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x1F4B3;</span>
                                    <span>Tarjeta d&eacute;bito</span>
                                </div>
                            </label>
                            <label class="busca-item">
                                <input type="checkbox" name="busca_tarjeta_credito" value="1">
                                <div class="busca-card">
                                    <span class="busca-icon">&#x2B50;</span>
                                    <span>Tarjeta cr&eacute;dito</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="fld-grid">
                        <div class="fld full">
                            <label>Resultado / Acuerdo logrado *</label>
                            <select name="acuerdo_logrado">
                                <option value="ninguno" selected>Ninguno / No es necesario</option>
                                <option value="nueva_cita_campo">Nueva cita en campo</option>
                                <option value="nueva_cita_oficina">Nueva cita en oficina / Solicitud</option>
                                <option value="recolectar_documentacion">Recolectar documentación</option>
                                <option value="levantamiento_campo">Levantamiento en campo</option>
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
                                      placeholder="Anota cualquier detalle relevante de la visita..."></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FOOTER NAVEGACI├ôN -->
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
                    <button type="button" class="btn btn-primary" id="btn-save" onclick="guardarEncuestaFetch()" style="display:none;">
                        <i class="fas fa-circle-check"></i> Guardar encuesta
                    </button>
                </div>
            </div>
        </form>

        <!-- SECCIÓN HISTORIAL DE ENCUESTAS -->
        <div class="search-card mt-5" id="historial-encuestas-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-3 border-bottom pb-2">
                <div>
                    <h3 class="m-0"><i class="fas fa-history" style="color: var(--brand-navy-deep);"></i> Mis Encuestas Realizadas</h3>
                    <p class="sub m-0 mt-1">Listado de encuestas completadas ordenadas por fecha. Puedes filtrar por cliente o por fecha.</p>
                </div>
                <div class="badge px-3 py-2 fs-6" style="background-color: var(--brand-navy-deep); color: white;">
                    Total: <span id="cant-encuestas"><?= count($historico_encuestas) ?></span>
                </div>
            </div>

            <!-- Filtros -->
            <div class="row g-3 align-items-end mb-4">
                <div class="col-md-5">
                    <label class="form-label font-weight-bold text-secondary text-uppercase" style="font-size:11px; letter-spacing:0.5px; display: block; margin-bottom: 6px;">Buscar por Cliente</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-2 border-end-0" style="border-color: var(--brand-border);"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="filtro-cliente" class="form-control border-2 border-start-0" placeholder="Nombre o cédula del cliente..." style="font-size:14px; padding:10px; border-color: var(--brand-border);">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label font-weight-bold text-secondary text-uppercase" style="font-size:11px; letter-spacing:0.5px; display: block; margin-bottom: 6px;">Filtrar por Fecha</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-2 border-end-0" style="border-color: var(--brand-border);"><i class="fas fa-calendar-alt text-muted"></i></span>
                        <input type="date" id="filtro-fecha" class="form-control border-2 border-start-0" style="font-size:14px; padding:10px; border-color: var(--brand-border);">
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-ghost w-100 border-2" id="btn-limpiar-filtros" style="padding:11px; font-weight:700; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <i class="fas fa-eraser"></i> Limpiar Filtros
                    </button>
                </div>
            </div>

            <!-- Tabla -->
            <div class="table-responsive" style="border-radius:12px; border:1px solid var(--brand-border); overflow: hidden;">
                <table class="table table-hover align-middle mb-0" id="tabla-encuestas">
                    <thead style="background: linear-gradient(135deg, var(--brand-navy-deep), var(--brand-navy)); color: white;">
                        <tr>
                            <th scope="col" class="py-3 px-3 border-0" style="font-size:12px; font-weight:700; text-transform:uppercase; color: white;">Fecha / Hora</th>
                            <th scope="col" class="py-3 px-3 border-0" style="font-size:12px; font-weight:700; text-transform:uppercase; color: white;">Cliente / Cédula</th>
                            <th scope="col" class="py-3 px-3 border-0" style="font-size:12px; font-weight:700; text-transform:uppercase; color: white;">Tipo Visita</th>
                            <th scope="col" class="py-3 px-3 border-0" style="font-size:12px; font-weight:700; text-transform:uppercase; color: white;">Interés & Productos</th>
                            <th scope="col" class="py-3 px-3 border-0" style="font-size:12px; font-weight:700; text-transform:uppercase; color: white;">Acuerdo</th>
                            <th scope="col" class="py-3 px-3 border-0 text-center" style="font-size:12px; font-weight:700; text-transform:uppercase; color: white;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($historico_encuestas)): ?>
                            <tr id="fila-vacia">
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fas fa-clipboard-question fa-3x mb-3 text-warning"></i>
                                    <p class="m-0 fw-bold">Aún no has registrado ninguna encuesta.</p>
                                    <p class="m-0 text-sm">Usa el buscador de cédula superior para registrar una nueva encuesta.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($historico_encuestas as $row): 
                                // Formatear tipo visita
                                $tipo_visita_lbl = 'Desconocido';
                                $tipo_visita_class = 'bg-secondary text-white';
                                switch ($row['tipo_tarea']) {
                                    case 'visita_frio':
                                    case 'frio':
                                        $tipo_visita_lbl = 'Visita en Frío';
                                        $tipo_visita_class = 'bg-info text-dark';
                                        break;
                                    case 'evaluacion':
                                    case 'seguimiento':
                                        $tipo_visita_lbl = 'Seguimiento';
                                        $tipo_visita_class = 'bg-success text-white';
                                        break;
                                    case 'prospecto_nuevo':
                                        $tipo_visita_lbl = 'Prospecto Nuevo';
                                        $tipo_visita_class = 'bg-warning text-dark';
                                        break;
                                    default:
                                        $tipo_visita_lbl = ucwords(str_replace('_', ' ', $row['tipo_tarea']));
                                        break;
                                }

                                // Formatear nivel de interés
                                $nivel_interes = $row['nivel_interes_captado'] ?? 'ninguno';
                                $nivel_class = 'bg-danger text-white';
                                if ($nivel_interes === 'bajo') $nivel_class = 'bg-warning text-dark';
                                elseif ($nivel_interes === 'alto') $nivel_class = 'bg-success text-white';

                                // Formatear productos
                                $productos_arr = [];
                                if (!empty($row['interes_ahorro'])) $productos_arr[] = 'ahorro';
                                if (!empty($row['interes_cc'])) $productos_arr[] = 'corriente';
                                if (!empty($row['interes_inversion'])) $productos_arr[] = 'inversion';
                                if (!empty($row['interes_credito'])) $productos_arr[] = 'credito';
                                $productos_badges = [];
                                foreach ($productos_arr as $prod) {
                                    $prod_name = match ($prod) {
                                        'ahorro'    => 'Ahorro',
                                        'corriente' => 'Corriente',
                                        'inversion' => 'Inversión',
                                        'credito'   => 'Crédito',
                                        default     => ucfirst($prod)
                                    };
                                    $productos_badges[] = '<span class="badge border border-primary text-primary bg-light me-1 mb-1">' . $prod_name . '</span>';
                                }
                                $productos_html = implode('', $productos_badges);
                                if (empty($productos_html)) $productos_html = '<span class="text-muted">Ninguno</span>';

                                // Formatear acuerdo
                                $acuerdo_lbl = 'Ninguno';
                                switch ($row['acuerdo_logrado']) {
                                    case 'nueva_cita_campo':
                                        $acuerdo_lbl = 'Nueva cita en campo';
                                        break;
                                    case 'nueva_cita_oficina':
                                        $acuerdo_lbl = 'Nueva cita en oficina';
                                        break;
                                    case 'recolectar_documentacion':
                                        $acuerdo_lbl = 'Recolectar documentación';
                                        break;
                                    case 'levantamiento_campo':
                                        $acuerdo_lbl = 'Levantamiento en campo';
                                        break;
                                    case 'ninguno':
                                    case '':
                                    case null:
                                        $acuerdo_lbl = 'Sin acuerdo';
                                        break;
                                }
                            ?>
                                <tr class="fila-encuesta" 
                                    data-cliente="<?= htmlspecialchars(strtolower($row['cliente_nombre'] . ' ' . $row['cliente_cedula'])) ?>"
                                    data-fecha="<?= htmlspecialchars($row['fecha_realizada']) ?>">
                                    <td class="px-3">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['fecha_realizada']) ?></div>
                                        <div class="text-muted" style="font-size: 11px;"><i class="far fa-clock"></i> <?= htmlspecialchars($row['hora_realizada']) ?></div>
                                    </td>
                                    <td class="px-3">
                                        <div class="fw-bold text-navy" style="color: var(--brand-navy);"><?= htmlspecialchars($row['cliente_nombre']) ?></div>
                                        <div class="text-secondary" style="font-size: 12px;"><i class="far fa-id-card"></i> <?= htmlspecialchars($row['cliente_cedula']) ?></div>
                                    </td>
                                    <td class="px-3">
                                        <span class="badge <?= $tipo_visita_class ?> px-2 py-1" style="font-size: 11px; font-weight: 700;"><?= $tipo_visita_lbl ?></span>
                                    </td>
                                    <td class="px-3">
                                        <div class="mb-1">
                                            <span class="badge <?= $nivel_class ?> px-2 py-1" style="font-size: 10px;">Interés: <?= ucfirst($nivel_interes) ?></span>
                                        </div>
                                        <div class="d-flex flex-wrap"><?= $productos_html ?></div>
                                    </td>
                                    <td class="px-3">
                                        <div class="fw-semibold text-secondary" style="font-size: 13px;"><i class="fas fa-handshake text-muted me-1"></i> <?= $acuerdo_lbl ?></div>
                                    </td>
                                    <td class="px-3 text-center">
                                        <a href="nueva_encuesta.php?tarea_id=<?= urlencode($row['tarea_id']) ?>" class="btn btn-sm btn-yellow py-1 px-3 fw-bold" style="font-size: 12px; border-radius: 6px;">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <!-- Fila informativa cuando no hay resultados de búsqueda -->
                        <tr id="no-results-row" style="display: none;">
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-search-minus fa-3x mb-3 text-warning"></i>
                                <p class="m-0 fw-bold">No se encontraron encuestas con los filtros aplicados.</p>
                                <p class="m-0 text-sm">Prueba ajustando el nombre del cliente o la fecha seleccionada.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content-area -->
</div><!-- /main-content -->

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* ──────────────────────────────────────────────────────
   SOPORTE PARA EDITAR ENCUESTAS EXISTENTES
────────────────────────────────────────────────────── */
async function cargarEncuestaParaEditar() {
    const params  = new URLSearchParams(window.location.search);
    const tareaId = params.get('tarea_id');
    if (!tareaId) {
        toggleRequiereCredito(1);
        return;   // No es modo edición
    }

    try {
        const url = `obtener_encuesta_para_editar.php?tarea_id=${encodeURIComponent(tareaId)}`;
        const res = await fetch(url, { method: 'GET' });
        
        // Clone response into two independent clones to avoid consuming the same stream twice
        const resCloneForError = res.clone();
        const resCloneForJson  = res.clone();

        // Verificar si la respuesta es OK en HTTP
        if (!res.ok) {
            const errorText = await resCloneForError.text();
            console.error('Error HTTP:', res.status, errorText);
            alert('Error al cargar la encuesta (HTTP ' + res.status + '). Detalles: ' + errorText);
            return;
        }

        let data;
        try {
            data = await resCloneForJson.json();
        } catch (parseErr) {
            console.error('Error parseando JSON:', parseErr);
            // Leer texto crudo desde el otro clone (no consumido aún)
            const responseText = await resCloneForError.text();
            console.error('Respuesta recibida:', responseText);
            alert('Error: La respuesta del servidor no es válida.\n\nDetalles: ' + responseText);
            return;
        }

        if (!data || data.status !== 'ok') {
            const msg = data?.message || 'Respuesta inválida del servidor';
            console.error('Error en response:', msg);
            alert('Error al cargar la encuesta: ' + msg);
            return;
        }

        const cliente  = data.cliente  || {};
        const encuesta = data.encuesta || {};
        const tarea    = data.tarea    || {};

        /* ── Helpers locales ─────────────────────────────────── */
        function svById(id, val) {
            const el = document.getElementById(id);
            if (el) el.value = val ?? '';
        }
        function svByName(name, val) {
            const el = document.querySelector(`[name="${name}"]`);
            if (el) {
                el.value = val ?? '';
                if (el.tagName === 'SELECT' && typeof syncSearchableDisplay === 'function') syncSearchableDisplay(el);
            }
        }
        function setRadioByName(name, val) {
            document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                r.checked = (String(r.value) === String(val));
                r.closest('.yn-opt')?.classList.toggle('checked', r.checked);
            });
        }

        /* ── IDs ocultos ─────────────────────────────────────── */
        svById('hid-tarea_id',   tareaId);
        svById('hid-cliente_id', cliente.id || '');
        // Si la tarea pertenece a otro asesor/usuario, actualizar los hidden inputs
        const asesorInput = document.querySelector('input[name="asesor_id"]');
        if (asesorInput && (tarea.asesor_id || tarea.asesorId)) {
            asesorInput.value = tarea.asesor_id || tarea.asesorId;
        }
        const usuarioInput = document.querySelector('input[name="usuario_id"]');
        if (usuarioInput && (tarea.usuario_id || tarea.usuarioId)) {
            usuarioInput.value = tarea.usuario_id || tarea.usuarioId;
        }

        /* Datos del prospecto para autocompletar fichas */
        window._prospectoData = {
            nombre:   cliente.nombre   || '',
            cedula:   cliente.cedula   || '',
            celular:  cliente.celular  || cliente.telefono2 || '',
            telefono: cliente.telefono || ''
        };

        /* ── Datos personales (IDs reales: f-*) ──────────────── */
        svById('f-nombre',         cliente.nombre         || '');
        svById('f-cedula',         cliente.cedula         || '');
        svById('f-celular',        cliente.celular        || cliente.telefono2 || '');
        svById('f-telefono',       cliente.telefono       || '');
        svById('f-email',          cliente.email          || '');
        svById('f-ciudad',         cliente.ciudad         || '');
        svById('f-zona',           cliente.zona           || '');
        svById('f-direccion',      cliente.direccion      || '');
        svById('f-estado',         cliente.estado         || 'prospecto');
        
        const infoBanner = document.getElementById('info-cargado');
        if (infoBanner) infoBanner.style.display = 'flex';

        /* ── Tipo de visita ───────────────────────────────────── */
        if (tarea.tipo_tarea) {
            const tipoMap = { visita_frio:'frio', frio:'frio', seguimiento:'seguimiento' };
            const key = tipoMap[tarea.tipo_tarea] || tarea.tipo_tarea;
            const vc  = document.querySelector(`.visit-card[data-tipo="${key}"]`);
            if (vc) selectVisita(vc);
        }

        /* ── Identificación Institucional ── */
        const p1Conoce = encuesta.p1_conoce_institucion ? '1' : '0';
        const p2Cliente = encuesta.p2_es_cliente ? '1' : '0';
        setRadioByName('p1_conoce_institucion', p1Conoce);
        setRadioByName('p2_es_cliente', p2Cliente);
        
        document.getElementById('p1-si')?.classList.toggle('checked', p1Conoce === '1');
        document.getElementById('p1-no')?.classList.toggle('checked', p1Conoce === '0');
        document.getElementById('p2-si')?.classList.toggle('checked', p2Cliente === '1');
        document.getElementById('p2-no')?.classList.toggle('checked', p2Cliente === '0');
        document.getElementById('p2-extra').style.display = p2Cliente === '1' ? 'block' : 'none';
        document.getElementById('p3-sec').style.display = p2Cliente === '1' ? 'block' : 'none';

        svById('f-p1_obs', encuesta.p1_obs || '' );
        const p2Products = encuesta.p2_producto ? encuesta.p2_producto.split(',') : [];
        document.querySelectorAll('#grp-p2-productos .p2-prod-opt').forEach(opt => {
            const isChecked = p2Products.includes(opt.dataset.val);
            opt.classList.toggle('checked', isChecked);
            const icon = opt.querySelector('i');
            if (icon) {
                icon.className = isChecked ? 'fas fa-check-square' : 'far fa-square';
            }
        });
        svById('f-p2_producto', encuesta.p2_producto || '');
        svById('f-p2_obs', encuesta.p2_obs || '');
        svById('f-p3_obs', encuesta.p3_obs || '');
        
        if (encuesta.p3_satisfaccion) {
            document.querySelectorAll('#chips-p3-satisfaccion .chip').forEach(c => {
                c.classList.toggle('selected', c.dataset.val === encuesta.p3_satisfaccion);
            });
            svById('f-p3_satisfaccion', encuesta.p3_satisfaccion);
        }

        /* ── Datos de negocio y Régimen Tributario (Paso 3) ── */
        if (cliente.actividad) {
            const chip = document.querySelector(`#chips-actividad [data-val="${cliente.actividad}"]`);
            if (chip) { 
                document.querySelectorAll('#chips-actividad .chip').forEach(c => c.classList.remove('selected'));
                chip.classList.add('selected'); 
                const hidAct = document.getElementById('hid-actividad');
                if (hidAct) hidAct.value = cliente.actividad; 
            }
        }

        let regimen = cliente.regimen_tributario || '';
        if (!regimen) {
            if (parseInt(cliente.tiene_ruc) === 1) regimen = 'ruc';
            else if (parseInt(cliente.tiene_rise) === 1) regimen = 'rise';
            else regimen = 'none';
        }
        const regTile = document.querySelector(`.regimen-tile[data-val="${regimen}"]`);
        if (regTile && typeof selectRegimen === 'function') {
            selectRegimen(regTile);
        }

        if (regimen === 'ruc') {
            const inpRuc = document.querySelector('input[name="ruc_numero"]');
            if (inpRuc) inpRuc.value = cliente.numero_ruc || '';
        } else if (regimen === 'rise') {
            const inpRise = document.querySelector('input[name="rise_numero"]');
            if (inpRise) inpRise.value = cliente.numero_ruc || '';
        }
        
        const qFields = [
            { hid: 'hid-ruc_declara_iva', val: cliente.declara_iva },
            { hid: 'hid-ruc_emite_facturas', val: cliente.emite_facturas },
            { hid: 'hid-ruc_lleva_contab', val: cliente.lleva_contabilidad },
            { hid: 'hid-rise_paga_cuota', val: cliente.paga_cuota_rise },
            { hid: 'hid-rise_emite_notas', val: cliente.emite_notas_venta },
            { hid: 'hid-rise_conoce_limite', val: cliente.conoce_limite_rise }
        ];
        qFields.forEach(f => {
            const valStr = String(f.val ?? '');
            if (valStr !== '') {
                const btn = document.querySelector(`.q-btn[data-hid="${f.hid}"][data-val="${valStr}"]`);
                if (btn) btn.click();
            }
        });

        const tieneEmp = (parseInt(cliente.tiene_empresa) === 1 || cliente.tiene_empresa === true) ? '1' : '0';
        setRadioByName('tiene_empresa', tieneEmp);
        const extEmp = document.getElementById('extras-empresa');
        if (extEmp) extEmp.classList.toggle('show', tieneEmp === '1');
        const avSinEmp = document.getElementById('aviso-sin-empresa');
        if (avSinEmp) avSinEmp.style.display = tieneEmp === '1' ? 'none' : 'block';
        if (typeof actualizarAvisoCredito === 'function') actualizarAvisoCredito();

        svById('f-nombre_empresa', cliente.nombre_empresa || '');
        svById('f-tipo_empresa',   cliente.tipo_empresa   || '');

        const tieneInvPrev = encuesta.tiene_inversiones ? '1' : '0';
        const crearTareaVenc = encuesta.interes_propuesta_previa ? '1' : '0';
        
        document.getElementById('sec-propuesta-vencimiento').style.display = tieneInvPrev === '1' ? 'block' : 'none';
        
        document.getElementById('venc-si')?.classList.toggle('checked', crearTareaVenc === '1');
        document.getElementById('venc-no')?.classList.toggle('checked', crearTareaVenc === '0');
        document.getElementById('extras-propuesta-vencimiento').style.display = crearTareaVenc === '1' ? 'flex' : 'none';
        
        svById('f-propuesta_inversion', encuesta.propuesta_inversion || '');
        svById('f-fecha_previa_vencimiento', encuesta.fecha_previa_vencimiento || '');
        svById('f-hora_previa_vencimiento', encuesta.hora_previa_vencimiento || '');
        svById('f-fecha_vencimiento_cdp', encuesta.fecha_vencimiento_cdp || '');

        /* ── Situación financiera (radio Y/N) ────────────────── */
        const mantAhorro = encuesta.mantiene_cuenta_ahorro        ? '1' : '0';
        const mantCorr   = encuesta.mantiene_cuenta_corriente     ? '1' : '0';
        const tienInv    = encuesta.tiene_inversiones             ? '1' : '0';
        const tienCred   = encuesta.tiene_operaciones_crediticias ? '1' : '0';

        setRadioByName('ec_mantiene_cuenta_ahorro',        mantAhorro);
        setRadioByName('ec_mantiene_cuenta_corriente',     mantCorr);
        setRadioByName('ec_tiene_inversiones',             tienInv);
        setRadioByName('ec_tiene_operaciones_crediticias', tienCred);

        /* Mostrar paneles "extras" según el valor */
        document.getElementById('extras-ahorro')?.classList.toggle('show',     mantAhorro === '1');
        document.getElementById('extras-corriente')?.classList.toggle('show',  mantCorr   === '1');
        document.getElementById('extras-inversiones')?.classList.toggle('show',tienInv    === '1');
        document.getElementById('extras-credito')?.classList.toggle('show',    tienCred   === '1');

        /* Detalles financieros (por name, sin id) */
        svByName('ec_institucion_ahorro',          encuesta.institucion_ahorro          || encuesta.banco_ahorro          || '');
        svByName('ec_saldo_ahorro',                encuesta.saldo_ahorro                || '');
        svByName('ec_institucion_corriente',       encuesta.institucion_corriente       || encuesta.banco_corriente       || '');
        svByName('ec_institucion_inversiones',     encuesta.institucion_inversiones     || '');
        svByName('ec_valor_inversion',             encuesta.valor_inversion             || '');
        svByName('ec_plazo_inversion',             encuesta.plazo_inversion             || '');
        svByName('ec_fecha_vencimiento_inversion', encuesta.fecha_vencimiento_inversion || '');
        svByName('ec_institucion_credito',         encuesta.institucion_credito         || '');
        svByName('ec_monto_credito_actual',        encuesta.monto_credito_actual        || '');
        svByName('ec_destino_credito_actual',      encuesta.destino_credito_actual      || '');

        /* Razones para no contratar */
        function toYN(val) {
            if (val === null || val === undefined || val === '') return '';
            const s = String(val).trim();
            return (s === '1' || s === 'true') ? '1' : '0';
        }

        const yaTrabaja = (encuesta.razon_ya_trabaja !== undefined && encuesta.razon_ya_trabaja !== null) 
            ? encuesta.razon_ya_trabaja 
            : (encuesta.razon_ya_trabaja_institucion ?? '');

        const desconfia = (encuesta.razon_desconfia !== undefined && encuesta.razon_desconfia !== null) 
            ? encuesta.razon_desconfia 
            : (encuesta.razon_desconfia_servicios ?? '');

        setRadioByName('ec_razon_ya_trabaja',       toYN(yaTrabaja));
        setRadioByName('ec_razon_desconfia',        toYN(desconfia));
        setRadioByName('ec_razon_agusto_actual',    toYN(encuesta.razon_agusto_actual));
        setRadioByName('ec_razon_mala_experiencia', toYN(encuesta.razon_mala_experiencia));
        svByName('ec_razon_otros', encuesta.razon_otros || '');

        /* ── Productos de interés (prod-card[data-prod]) ─────── */
        const fichaMap = { ahorro:'ficha-ahorro', corriente:'ficha-corriente', inversion:'ficha-inversion', credito:'ficha-credito' };
        const prodFlags = {
            ahorro:    parseInt(encuesta.interes_ahorro)    || 0,
            corriente: parseInt(encuesta.interes_cc)        || 0,
            inversion: parseInt(encuesta.interes_inversion) || 0,
            credito:   parseInt(encuesta.interes_credito)   || 0,
        };
        const interesesArr = [];
        Object.entries(prodFlags).forEach(([prod, active]) => {
            if (!active) return;
            interesesArr.push(prod);
            prodSeleccionados.add(prod);
            const card = document.querySelector(`.prod-card[data-prod="${prod}"]`);
            if (card) card.classList.add('selected');
            const fp = document.getElementById(fichaMap[prod]);
            if (fp) fp.style.display = 'block';
        });
        svById('hid-prod_interes', interesesArr.join(','));

        /* ── Nivel de interés (level-card[data-val]) ─────────── */
        if (encuesta.nivel_interes_captado) {
            document.querySelectorAll('.level-card').forEach(c => {
                c.classList.toggle('selected', c.dataset.val === encuesta.nivel_interes_captado);
            });
            svById('hid-nivel_interes', encuesta.nivel_interes_captado);
        }

        /* ── Cargar la pregunta "¿Le interesa adquirir o trabajar
              con alguno de nuestros productos?" ──────────────────
           Se usa el valor REAL guardado en la encuesta
           (interes_conocer_productos). Si hay productos marcados,
           el interés es necesariamente "Sí". ── */
        let interesVal;
        const icpVal = encuesta.interes_conocer_productos;
        if (icpVal !== null && icpVal !== undefined && String(icpVal) !== '') {
            interesVal = (parseInt(icpVal) === 1) ? 1 : 0;
        } else {
            interesVal = ((encuesta.nivel_interes_captado && encuesta.nivel_interes_captado !== 'ninguno') || interesesArr.length > 0) ? 1 : 0;
        }
        if (interesesArr.length > 0) interesVal = 1;

        toggleInteresProductos(interesVal);

        /* Re-aplicar los productos guardados: toggleInteresProductos
           pudo haber reseteado el grid de productos. */
        if (interesVal === 1) {
            prodSeleccionados.clear();
            interesesArr.forEach(prod => {
                prodSeleccionados.add(prod);
                const card = document.querySelector(`.prod-card[data-prod="${prod}"]`);
                if (card) {
                    card.classList.add('selected');
                    const chk = card.querySelector('.pc-check');
                    if (chk) chk.style.display = 'flex';
                }
                const fp = document.getElementById(fichaMap[prod]);
                if (fp) fp.style.display = 'block';
            });
            svById('hid-prod_interes', interesesArr.join(','));
        }

        const radInteresSi = document.querySelector('input[name="interesado_productos"][value="1"]');
        const radInteresNo = document.querySelector('input[name="interesado_productos"][value="0"]');
        if (radInteresSi && radInteresNo) {
            radInteresSi.checked = (interesVal === 1);
            radInteresNo.checked = (interesVal === 0);
            radInteresSi.closest('.yn-opt')?.classList.toggle('checked', interesVal === 1);
            radInteresNo.closest('.yn-opt')?.classList.toggle('checked', interesVal === 0);
        }

        /* ── Acuerdo y cierre (select + inputs por name) ─────── */
        svByName('acuerdo_logrado',      encuesta.acuerdo_logrado      || '');
        svByName('fecha_acuerdo',        encuesta.fecha_acuerdo        || '');
        svByName('hora_acuerdo',         encuesta.hora_acuerdo         || '');
        svByName('fecha_nuevo_contacto', encuesta.fecha_nuevo_contacto || '');
        svByName('observaciones',        encuesta.observaciones        || '');

        /* ── ¿Qué busca de una institución? Checkboxes ── */
        function setCheckboxByName(name, val) {
            const cb = document.querySelector(`input[name="${name}"]`);
            if (cb) {
                cb.checked = (String(val) === '1' || val === true);
            }
        }
        setCheckboxByName('busca_agilidad',        encuesta.que_busca_agilidad);
        setCheckboxByName('busca_cajeros',         encuesta.que_busca_cajeros);
        setCheckboxByName('busca_banca_online',    encuesta.que_busca_banca_linea || encuesta.que_busca_banca_online);
        setCheckboxByName('busca_agencias',        encuesta.que_busca_agencias);
        setCheckboxByName('busca_credito_rapido',  encuesta.que_busca_credito_rapido);
        setCheckboxByName('busca_tarjeta_debito',  encuesta.que_busca_tarjeta_debito);
        setCheckboxByName('busca_tarjeta_credito', encuesta.que_busca_tarjeta_credito);

        /* ── PREPOPULATE CREDIT SHEET (ficha_credito) ─────────── */
        if (data.fichas && data.fichas.length > 0) {
            const fc = data.fichas.find(f => f.producto_tipo === 'credito');
            if (fc) {
                const reqVal = (fc.requiere_credito !== null && fc.requiere_credito !== undefined) ? parseInt(fc.requiere_credito) : 1;
                toggleRequiereCredito(reqVal);
                
                if (fc.destino_credito) {
                    const chip = document.querySelector(`.chip[data-val="${fc.destino_credito}"]`);
                    if (chip) toggleChip(chip, 'fk_destino');
                }
                svByName('fk_destino_otros', fc.dest_otros_detalle || '');
                svByName('fk_monto', fc.monto_credito || '');
                svByName('fk_plazo', fc.plazo_credito_meses || '');
                
                svByName('fk_sol_nombre', fc.solicitante_nombre || '');
                svByName('fk_sol_cedula', fc.solicitante_cedula || '');
                svByName('fk_sol_celular', fc.solicitante_celular || '');
                
                if (fc.solicitante_estado_civil) {
                    const chip = document.querySelector(`.chip[data-val="${fc.solicitante_estado_civil}"]`);
                    if (chip) toggleChip(chip, 'fk_sol_ec');
                }
                
                svByName('fk_sol_conyuge_nombre', fc.solicitante_conyuge_nombre || '');
                svByName('fk_sol_conyuge_cedula', fc.solicitante_conyuge_cedula || '');
                svByName('fk_sol_conyuge_celular', fc.solicitante_conyuge_celular || '');
                
                svByName('fk_gar_nombre', fc.garante_nombre || '');
                svByName('fk_gar_cedula', fc.garante_cedula || '');
                svByName('fk_gar_celular', fc.garante_celular || '');
                
                if (fc.garante_estado_civil) {
                    const chip = document.querySelector(`.chip[data-val="${fc.garante_estado_civil}"]`);
                    if (chip) toggleChip(chip, 'fk_gar_ec');
                }
                
                svByName('fk_gar_conyuge_nombre', fc.garante_conyuge_nombre || '');
                svByName('fk_gar_conyuge_cedula', fc.garante_conyuge_cedula || '');
                svByName('fk_gar_conyuge_celular', fc.garante_conyuge_celular || '');
                
                svByName('fk_direccion_sitio', fc.direccion_sitio || '');
                
                const docs = [
                    'doc_cedula', 'doc_planilla', 'doc_ruc_rise', 'doc_estados_cuenta', 
                    'doc_declaraciones', 'doc_matricula', 'doc_foto_negocio', 
                    'doc_solicitud_credito', 'doc_foto_cliente'
                ];
                docs.forEach(d => {
                    const isChecked = parseInt(fc[d]) === 1;
                    const docItem = document.querySelector(`.doc-item[data-field="fk_${d}"]`);
                    if (docItem) {
                        docItem.classList.toggle('checked', isChecked);
                        const hid = docItem.querySelector('.doc-hidden');
                        if (hid) hid.value = isChecked ? '1' : '0';
                    }
                });
            } else {
                toggleRequiereCredito(1);
            }
        } else {
            toggleRequiereCredito(1);
        }

        /* ── PREPOBLAR FICHAS: Ahorro / Corriente / Inversión ───
           Carga los datos guardados de cada ficha de producto que
           el cliente/prospecto solicitó en esta encuesta. ── */
        function setFichaChip(hidId, val) {
            if (val === null || val === undefined || String(val) === '') return;
            const chip = document.querySelector(`.chip[onclick*="'${hidId}'"][data-val="${val}"]`);
            if (chip && typeof chipSingle === 'function') chipSingle(chip, hidId);
        }
        function setFichaDoc(field, val) {
            const item = document.querySelector(`.doc-item[data-field="${field}"]`);
            if (!item) return;
            const on = (parseInt(val) === 1);
            item.classList.toggle('checked', on);
            const hid = item.querySelector('.doc-hidden');
            if (hid) hid.value = on ? '1' : '0';
        }
        function marcarProductoFicha(prod) {
            prodSeleccionados.add(prod);
            const card = document.querySelector(`.prod-card[data-prod="${prod}"]`);
            if (card) {
                card.classList.add('selected');
                const chk = card.querySelector('.pc-check');
                if (chk) chk.style.display = 'flex';
            }
            const fp = document.getElementById(fichaMap[prod]);
            if (fp) fp.style.display = 'block';
            const hid = document.getElementById('hid-prod_interes');
            if (hid) hid.value = Array.from(prodSeleccionados).join(',');
        }

        if (Array.isArray(data.fichas) && data.fichas.length > 0) {
            // Si hay fichas guardadas, el interés es necesariamente "Sí"
            toggleInteresProductos(1);
            const rSi = document.querySelector('input[name="interesado_productos"][value="1"]');
            const rNo = document.querySelector('input[name="interesado_productos"][value="0"]');
            if (rSi && rNo) {
                rSi.checked = true; rNo.checked = false;
                rSi.closest('.yn-opt')?.classList.add('checked');
                rNo.closest('.yn-opt')?.classList.remove('checked');
            }

            // Ficha: Cuenta de Ahorro
            const fAhorro = data.fichas.find(f => f.producto_tipo === 'cuenta_ahorros');
            if (fAhorro) {
                marcarProductoFicha('ahorro');
                svByName('fa_nombre',        fAhorro.titular_nombre  || '');
                svByName('fa_cedula',        fAhorro.titular_cedula  || '');
                svByName('fa_celular',       fAhorro.titular_celular || '');
                svByName('fa_monto_inicial', fAhorro.monto_inicial   || '');
                svByName('fa_objetivo',      fAhorro.objetivo_ahorro || '');
                setFichaChip('fa_estado_civil', fAhorro.titular_estado_civil);
                setFichaChip('fa_tipo_ahorro',  fAhorro.tipo_ahorro);
                setFichaChip('fa_frecuencia',   fAhorro.frecuencia_deposito);
                setFichaDoc('fa_doc_cedula',   fAhorro.doc_cedula);
                setFichaDoc('fa_doc_papeleta', fAhorro.doc_papeleta);
                setFichaDoc('fa_doc_planilla', fAhorro.doc_planilla);
                setFichaDoc('fa_doc_correo',   fAhorro.doc_correo);
                setFichaDoc('fa_doc_celular',  fAhorro.doc_celular);
                setFichaDoc('fa_doc_deposito', fAhorro.doc_deposito_inicial);
            }

            // Ficha: Cuenta Corriente
            const fCorr = data.fichas.find(f => f.producto_tipo === 'cuenta_corriente');
            if (fCorr) {
                marcarProductoFicha('corriente');
                svByName('fc_nombre',          fCorr.titular_nombre      || '');
                svByName('fc_cedula',          fCorr.titular_cedula      || '');
                svByName('fc_celular',         fCorr.titular_celular     || '');
                svByName('fc_proposito',       fCorr.proposito           || '');
                svByName('fc_monto_deposito',  fCorr.monto_deposito_prom || '');
                svByName('fc_ingreso_mensual', fCorr.ingreso_mensual     || '');
                svByName('fc_observaciones',   fCorr.observaciones       || '');
                setFichaChip('fc_tipo_cc',      fCorr.tipo_cc);
                setFichaChip('fc_estado_civil', fCorr.titular_estado_civil);
                setRadioByName('fc_usa_cheques',  fCorr.usa_cheques);
                setRadioByName('fc_requiere_td',  fCorr.requiere_td);
                setRadioByName('fc_tiene_nomina', fCorr.tiene_nomina);
                setFichaDoc('fc_doc_cedula',   fCorr.doc_cedula);
                setFichaDoc('fc_doc_papeleta', fCorr.doc_papeleta);
                setFichaDoc('fc_doc_planilla', fCorr.doc_planilla);
                setFichaDoc('fc_doc_correo',   fCorr.doc_correo);
                setFichaDoc('fc_doc_celular',  fCorr.doc_celular);
                setFichaDoc('fc_doc_deposito', fCorr.doc_deposito_inicial);
            }

            // Ficha: Inversiones
            const fInv = data.fichas.find(f => f.producto_tipo === 'inversiones');
            if (fInv) {
                marcarProductoFicha('inversion');
                svByName('fi_monto',         fInv.monto_inversion || '');
                svByName('fi_plazo',         fInv.plazo_meses     || '');
                svByName('fi_observaciones', fInv.observaciones   || '');
                setFichaChip('fi_tipo',     fInv.tipo_inversion);
                setFichaChip('fi_objetivo', fInv.objetivo_inversion);
                setRadioByName('fi_renovacion_automatica', fInv.renovacion_auto);
                setFichaDoc('fi_req_cuenta',   fInv.req_cuenta_activa);
                setFichaDoc('fi_req_monto',    fInv.req_monto_minimo);
                setFichaDoc('fi_req_contrato', fInv.doc_contrato_inversion);
                setFichaDoc('fi_req_fondos',   fInv.doc_origen_fondos);
                setFichaDoc('fi_req_kyc',      fInv.doc_actualizacion_kyc);
            }
        }

        /* Banner de edición */
        const titulo = document.querySelector('.navbar-custom h2');
        if (titulo) titulo.innerHTML = '<i class="fas fa-pen-to-square"></i> Editando encuesta';

        /* Mostrar formulario y stepper */
        stepper.style.display = 'flex';
        formEnc.style.display = 'block';
        show(0);
        stepper.scrollIntoView({ behavior: 'smooth', block: 'start' });

    } catch (e) {
        console.error('Error cargando encuesta:', e);
        alert('Error inesperado al cargar la encuesta. Recarga la página e intenta de nuevo.\n\nDetalles: ' + e.message);
    }
}

// Ejecutar al cargar la página
window.addEventListener('DOMContentLoaded', cargarEncuestaParaEditar);

/* ──────────────────────────────────────────────────────
   B├ÜSQUEDA POR CÉDULA — bug fix: siempre muestra stepper+form
────────────────────────────────────────────────────── */
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
    
    // Asegurar que al buscar se cree una NUEVA encuesta/tarea sin modificar el historial
    setVal('hid-tarea_id', '');

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

            // Pre-llenar campos personales
            fill('f-nombre',         data.data.nombre);
            fill('f-cedula',         data.data.cedula);
            fill('f-celular',        data.data.celular);
            fill('f-telefono',       data.data.telefono);
            fill('f-email',          data.data.email);
            fill('f-direccion',      data.data.direccion);
            fill('f-ciudad',         data.data.ciudad);
            fill('f-zona',           data.data.zona);
            fill('f-nombre_empresa', data.data.nombre_empresa);
            fill('f-tipo_empresa',   data.data.tipo_empresa);
            setVal('f-estado',       data.data.estado_db || 'prospecto');
            setVal('hid-cliente_id', data.data.id);

            // Buscar por cédula SIEMPRE genera una ENCUESTA NUEVA, aunque
            // el cliente ya exista en la base. El tarea_id se deja vacío
            // para que al guardar se cree una tarea/encuesta nueva (y
            // productos nuevos). La modificación solo ocurre al entrar
            // por el botón "Editar" (nueva_encuesta.php?tarea_id=...).
            setVal('hid-tarea_id', '');

            // chip actividad
            if (data.data.actividad) {
                const chip = document.querySelector(`#chips-actividad [data-val="${data.data.actividad}"]`);
                if (chip) { 
                    document.querySelectorAll('#chips-actividad .chip').forEach(c => c.classList.remove('selected'));
                    chip.classList.add('selected'); 
                    setVal('hid-actividad', data.data.actividad); 
                }
            }

            function localSetRadioByName(name, val) {
                document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                    r.checked = (String(r.value) === String(val));
                    r.closest('.yn-opt')?.classList.toggle('checked', r.checked);
                });
            }

            // Régimen tributario y sub-preguntas (Paso 3)
            let regimen = data.data.regimen_tributario || '';
            if (!regimen) {
                if (data.data.tiene_ruc === 1) regimen = 'ruc';
                else if (data.data.tiene_rise === 1) regimen = 'rise';
                else regimen = 'none';
            }
            const regTile = document.querySelector(`.regimen-tile[data-val="${regimen}"]`);
            if (regTile) selectRegimen(regTile);

            if (regimen === 'ruc') {
                const inpRuc = document.querySelector('input[name="ruc_numero"]');
                if (inpRuc) inpRuc.value = data.data.numero_ruc || '';
            } else if (regimen === 'rise') {
                const inpRise = document.querySelector('input[name="rise_numero"]');
                if (inpRise) inpRise.value = data.data.numero_ruc || '';
            }
            
            const qFields = [
                { hid: 'hid-ruc_declara_iva', val: data.data.declara_iva },
                { hid: 'hid-ruc_emite_facturas', val: data.data.emite_facturas },
                { hid: 'hid-ruc_lleva_contab', val: data.data.lleva_contabilidad },
                { hid: 'hid-rise_paga_cuota', val: data.data.paga_cuota_rise },
                { hid: 'hid-rise_emite_notas', val: data.data.emite_notas_venta },
                { hid: 'hid-rise_conoce_limite', val: data.data.conoce_limite_rise }
            ];
            qFields.forEach(f => {
                const valStr = String(f.val ?? '');
                if (valStr !== '') {
                    const btn = document.querySelector(`.q-btn[data-hid="${f.hid}"][data-val="${valStr}"]`);
                    if (btn) btn.click();
                }
            });

            // tiene_empresa toggle
            const tieneEmp = data.data.tiene_empresa === 1 ? '1' : '0';
            localSetRadioByName('tiene_empresa', tieneEmp);
            const extEmp = document.getElementById('extras-empresa');
            if (extEmp) extEmp.classList.toggle('show', tieneEmp === '1');
            const avSinEmp = document.getElementById('aviso-sin-empresa');
            if (avSinEmp) avSinEmp.style.display = tieneEmp === '1' ? 'none' : 'block';
            if (typeof actualizarAvisoCredito === 'function') actualizarAvisoCredito();

            // Limpiar e inicializar tipo de visita
            document.querySelectorAll('.visit-card').forEach(c => c.classList.remove('selected'));
            setVal('hid-tipo_prospecto', '');

            // Limpiar intereses de productos previos (deben elegirse de nuevo)
            if (typeof prodSeleccionados !== 'undefined' && prodSeleccionados.clear) {
                prodSeleccionados.clear();
            }
            document.querySelectorAll('.prod-card').forEach(c => c.classList.remove('selected'));
            document.querySelectorAll('.ficha-producto').forEach(fp => fp.style.display = 'none');
            setVal('hid-prod_interes', '');
            setVal('hid-nivel_interes', '');
            
            const radInteresSi = document.querySelector('input[name="interesado_productos"][value="1"]');
            const radInteresNo = document.querySelector('input[name="interesado_productos"][value="0"]');
            if (radInteresSi && radInteresNo) {
                radInteresSi.checked = false;
                radInteresNo.checked = true;
                radInteresSi.closest('.yn-opt')?.classList.remove('checked');
                radInteresNo.closest('.yn-opt')?.classList.add('checked');
            }
            if (typeof toggleInteresProductos === 'function') toggleInteresProductos(0);

            // Pre-llenar tipo de visita desde origen_prospecto o la última tarea si existe (Paso 1)
            const origenVisita = data.data.origen_prospecto || (data.tarea ? data.tarea.tipo_tarea : '');
            if (origenVisita) {
                const tipoMap = {
                    'visita_frio': 'frio',
                    'frio': 'frio',
                    'evaluacion': 'seguimiento',
                    'seguimiento': 'seguimiento',
                    'prospecto_nuevo': 'frio',
                    'leads_llamadas': 'leads_llamadas',
                    'cliente': 'cliente'
                };
                const key = tipoMap[origenVisita] || origenVisita;
                const vc = document.querySelector(`.visit-card[data-tipo="${key}"]`);
                if (vc) {
                    if (typeof selectVisita === 'function') {
                        selectVisita(vc);
                    } else {
                        vc.classList.add('selected');
                        setVal('hid-tipo_prospecto', key);
                    }
                }
            }

            // Pre-llenar datos de la encuesta comercial anterior (excepto productos)
            if (data.encuesta) {
                const encuesta = data.encuesta;

                // 1. Identificación Institucional (Paso 0)
                const p1Conoce = (encuesta.p1_conoce_institucion !== null && encuesta.p1_conoce_institucion !== undefined) ? String(encuesta.p1_conoce_institucion) : '';
                const p2Cliente = (encuesta.p2_es_cliente !== null && encuesta.p2_es_cliente !== undefined) ? String(encuesta.p2_es_cliente) : '';
                
                if (p1Conoce !== '') {
                    localSetRadioByName('p1_conoce_institucion', p1Conoce);
                    document.getElementById('p1-si')?.classList.toggle('checked', p1Conoce === '1');
                    document.getElementById('p1-no')?.classList.toggle('checked', p1Conoce === '0');
                }
                
                if (p2Cliente !== '') {
                    localSetRadioByName('p2_es_cliente', p2Cliente);
                    document.getElementById('p2-si')?.classList.toggle('checked', p2Cliente === '1');
                    document.getElementById('p2-no')?.classList.toggle('checked', p2Cliente === '0');
                    const p2ExtraEl = document.getElementById('p2-extra');
                    if (p2ExtraEl) p2ExtraEl.style.display = p2Cliente === '1' ? 'block' : 'none';
                    const p3SecEl2 = document.getElementById('p3-sec');
                    if (p3SecEl2) p3SecEl2.style.display = p2Cliente === '1' ? 'block' : 'none';
                }

                fill('f-p1_obs', encuesta.p1_obs || '');
                
                // Productos que ya posee
                const p2Products = encuesta.p2_producto ? encuesta.p2_producto.split(',') : [];
                document.querySelectorAll('#grp-p2-productos .p2-prod-opt').forEach(opt => {
                    const isChecked = p2Products.includes(opt.dataset.val);
                    opt.classList.toggle('checked', isChecked);
                    const icon = opt.querySelector('i');
                    if (icon) {
                        icon.className = isChecked ? 'fas fa-check-square' : 'far fa-square';
                    }
                });
                fill('f-p2_producto', encuesta.p2_producto || '');
                fill('f-p2_obs', encuesta.p2_obs || '');
                fill('f-p3_obs', encuesta.p3_obs || '');
                
                if (encuesta.p3_satisfaccion) {
                    document.querySelectorAll('#chips-p3-satisfaccion .chip').forEach(c => {
                        c.classList.toggle('selected', c.dataset.val === encuesta.p3_satisfaccion);
                    });
                    fill('f-p3_satisfaccion', encuesta.p3_satisfaccion);
                }

                // Cargar interés en conocer productos (radio e inicializador de productos)
                const interesConocer = (encuesta.interes_conocer_productos !== null && encuesta.interes_conocer_productos !== undefined) ? String(encuesta.interes_conocer_productos) : '';
                if (interesConocer !== '') {
                    const radIntSi = document.querySelector('input[name="interesado_productos"][value="1"]');
                    const radIntNo = document.querySelector('input[name="interesado_productos"][value="0"]');
                    if (radIntSi && radIntNo) {
                        radIntSi.checked = (interesConocer === '1');
                        radIntNo.checked = (interesConocer === '0');
                        radIntSi.closest('.yn-opt')?.classList.toggle('checked', radIntSi.checked);
                        radIntNo.closest('.yn-opt')?.classList.toggle('checked', radIntNo.checked);
                    }
                    if (typeof toggleInteresProductos === 'function') toggleInteresProductos(parseInt(interesConocer));
                }

                // 3. Situación financiera (Paso 4 - radio Y/N)
                function toYN(val) {
                    if (val === null || val === undefined || val === '') return '';
                    const s = String(val).trim();
                    return (s === '1' || s === 'true') ? '1' : '0';
                }

                const mantAhorro = toYN(encuesta.mantiene_cuenta_ahorro);
                const mantCorr   = toYN(encuesta.mantiene_cuenta_corriente);
                const tienInv    = toYN(encuesta.tiene_inversiones);
                const tienCred   = toYN(encuesta.tiene_operaciones_crediticias);

                localSetRadioByName('ec_mantiene_cuenta_ahorro',        mantAhorro);
                localSetRadioByName('ec_mantiene_cuenta_corriente',     mantCorr);
                localSetRadioByName('ec_tiene_inversiones',             tienInv);
                localSetRadioByName('ec_tiene_operaciones_crediticias', tienCred);

                document.getElementById('extras-ahorro')?.classList.toggle('show',     mantAhorro === '1');
                document.getElementById('extras-corriente')?.classList.toggle('show',  mantCorr   === '1');
                document.getElementById('extras-inversiones')?.classList.toggle('show',tienInv    === '1');
                document.getElementById('extras-credito')?.classList.toggle('show',    tienCred   === '1');

                function localSvByName(name, val) {
                    const el = document.querySelector(`[name="${name}"]`);
                    if (el) {
                        if (el.tagName === 'SELECT' && val) {
                            let exists = false;
                            for (let i = 0; i < el.options.length; i++) {
                                if (el.options[i].value === val) {
                                    exists = true;
                                    break;
                                }
                            }
                            if (!exists) {
                                const opt = document.createElement('option');
                                opt.value = val;
                                opt.textContent = val;
                                el.appendChild(opt);
                            }
                        }
                        el.value = val ?? '';
                        if (el.tagName === 'SELECT' && typeof syncSearchableDisplay === 'function') syncSearchableDisplay(el);
                    }
                }
                
                localSvByName('ec_institucion_ahorro',          encuesta.institucion_ahorro          || encuesta.banco_ahorro          || '');
                localSvByName('ec_saldo_ahorro',                encuesta.saldo_ahorro                || '');
                localSvByName('ec_institucion_corriente',       encuesta.institucion_corriente       || encuesta.banco_corriente       || '');
                localSvByName('ec_institucion_inversiones',     encuesta.institucion_inversiones     || '');
                localSvByName('ec_valor_inversion',             encuesta.valor_inversion             || '');
                localSvByName('ec_plazo_inversion',             encuesta.plazo_inversion             || '');
                localSvByName('ec_fecha_vencimiento_inversion', encuesta.fecha_vencimiento_inversion || '');
                localSvByName('ec_institucion_credito',         encuesta.institucion_credito         || '');
                localSvByName('ec_monto_credito_actual',        encuesta.monto_credito_actual        || '');
                localSvByName('ec_destino_credito_actual',      encuesta.destino_credito_actual      || '');

                // Propuesta de vencimiento
                const tieneInvPrev = toYN(encuesta.tiene_inversiones);
                const crearTareaVenc = toYN(encuesta.interes_propuesta_previa);
                
                const secPropVenc = document.getElementById('sec-propuesta-vencimiento');
                if (secPropVenc) secPropVenc.style.display = tieneInvPrev === '1' ? 'block' : 'none';
                
                document.getElementById('venc-si')?.classList.toggle('checked', crearTareaVenc === '1');
                document.getElementById('venc-no')?.classList.toggle('checked', crearTareaVenc === '0');
                
                const extPropVenc = document.getElementById('extras-propuesta-vencimiento');
                if (extPropVenc) extPropVenc.style.display = crearTareaVenc === '1' ? 'flex' : 'none';
                
                fill('f-propuesta_inversion', encuesta.propuesta_inversion || '');
                fill('f-fecha_previa_vencimiento', encuesta.fecha_previa_vencimiento || '');
                fill('f-hora_previa_vencimiento', encuesta.hora_previa_vencimiento || '');
                fill('f-fecha_vencimiento_cdp', encuesta.fecha_vencimiento_cdp || '');

                // Razones para no contratar
                localSetRadioByName('ec_razon_ya_trabaja',       toYN(encuesta.razon_ya_trabaja));
                localSetRadioByName('ec_razon_desconfia',        toYN(encuesta.razon_desconfia));
                localSetRadioByName('ec_razon_agusto_actual',    toYN(encuesta.razon_agusto_actual));
                localSetRadioByName('ec_razon_mala_experiencia', toYN(encuesta.razon_mala_experiencia));
                localSvByName('ec_razon_otros', encuesta.razon_otros || '');

                // ¿Qué busca de una institución? Checkboxes
                function localSetCheckbox(name, val) {
                    const cb = document.querySelector(`input[name="${name}"]`);
                    if (cb) {
                        cb.checked = (String(val) === '1' || val === true);
                    }
                }
                localSetCheckbox('busca_agilidad',        encuesta.que_busca_agilidad);
                localSetCheckbox('busca_cajeros',         encuesta.que_busca_cajeros);
                localSetCheckbox('busca_banca_online',    encuesta.que_busca_banca_linea);
                localSetCheckbox('busca_agencias',        encuesta.que_busca_agencias);
                localSetCheckbox('busca_credito_rapido',  encuesta.que_busca_credito_rapido);
                localSetCheckbox('busca_tarjeta_debito',  encuesta.que_busca_tarjeta_debito);
                localSetCheckbox('busca_tarjeta_credito', encuesta.que_busca_tarjeta_credito);

                // Cierre y Acuerdo
                localSvByName('acuerdo_logrado',          encuesta.acuerdo_logrado || 'ninguno');
                localSvByName('fecha_acuerdo',            encuesta.fecha_acuerdo || '');
                localSvByName('hora_acuerdo',             encuesta.hora_acuerdo || '');
                localSvByName('fecha_nuevo_contacto',     encuesta.fecha_nuevo_contacto || '');
                localSvByName('observaciones',            encuesta.observaciones || '');
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
        if (data.status === 'found') {
            show(2); // Ir directo a Datos personales
        } else {
            show(0); // Nuevo prospecto - Ir a Tipo visita
        }
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

function toggleP2Prod(el) {
    el.classList.toggle('checked');
    const checkedOpts = el.parentNode.querySelectorAll('.p2-prod-opt.checked');
    const vals = [];
    checkedOpts.forEach(opt => vals.push(opt.dataset.val));
    document.getElementById('f-p2_producto').value = vals.join(',');
    
    const icon = el.querySelector('i');
    if (icon) {
        if (el.classList.contains('checked')) {
            icon.className = 'fas fa-check-square';
        } else {
            icon.className = 'far fa-square';
        }
    }
}

function toggleVencProposal(val) {
    document.getElementById('venc-si').classList.toggle('checked', val === 1);
    document.getElementById('venc-no').classList.toggle('checked', val === 0);
    document.getElementById('extras-propuesta-vencimiento').style.display = val === 1 ? 'flex' : 'none';
}

function selectSatisfaccion(el) {
    el.parentNode.querySelectorAll('.chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('f-p3_satisfaccion').value = el.dataset.val;
}

function omitirBusqueda() {
    searchRes.innerHTML = `
        <div class="found-chip new-prosp">
            <i class="fas fa-user-plus"></i>
            Nuevo Prospecto — Llena los datos en el formulario.
        </div>`;
    
    // Clear search & form fields
    inpCedula.value = '';
    document.getElementById('formEncuesta').reset();
    
    // Reset hidden inputs & states
    setVal('hid-cliente_id', '');
    setVal('hid-tarea_id', '');
    setVal('hid-tipo_prospecto', '');
    setVal('hid-actividad', '');
    setVal('hid-tiene_ruc', '0');
    setVal('hid-tiene_rise', '0');
    setVal('hid-prod_interes', '');
    setVal('hid-nivel_interes', '');
    setVal('f-p3_satisfaccion', '');
    document.getElementById('p3-sec').style.display = 'none';
    document.getElementById('p2-extra').style.display = 'none';
    setVal('f-estado', 'prospecto');
    setVal('f-tipo_empresa', '');
    setVal('f-nombre_empresa', '');
    setVal('f-propuesta_inversion', '');
    setVal('f-fecha_previa_vencimiento', '');
    setVal('f-hora_previa_vencimiento', '');
    setVal('f-fecha_vencimiento_cdp', '');
    document.getElementById('sec-propuesta-vencimiento').style.display = 'none';
    document.getElementById('extras-propuesta-vencimiento').style.display = 'none';
    
    // Hide info banner
    document.getElementById('info-cargado').style.display = 'none';
    
    // Reset custom chip groups, level selections, and YN groups
    document.querySelectorAll('.visit-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('#chips-actividad .chip').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.level-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('#chips-p3-satisfaccion .chip').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.yn-opt').forEach(o => o.classList.remove('checked'));

    // Reset de interés de productos (Omitir búsqueda)
    toggleInteresProductos(0);
    toggleRequiereCredito(1);
    const radInteresNo = document.querySelector('input[name="interesado_productos"][value="0"]');
    if (radInteresNo) {
        radInteresNo.checked = true;
        const opt = radInteresNo.closest('.yn-opt');
        if (opt) opt.classList.add('checked');
    }
    document.getElementById('p2-extra').style.display = 'none';
    
    // Show stepper and form
    searchRes.style.display = 'block';
    stepper.style.display   = 'flex';
    formEnc.style.display   = 'block';
    
    // Go to step 0 (Identificación)
    show(0);
    stepper.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function fill(id, val) { const e = document.getElementById(id); if (e && val) e.value = val; }
function setVal(id, val) { const e = document.getElementById(id); if (e) e.value = val||''; }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function showMsg(msg, type) {
    searchRes.innerHTML = `<div class="alert alert-${type} mt-2" style="font-size:13px;">${msg}</div>`;
    searchRes.style.display = 'block';
}

/* ──────────────────────────────────────────────────────
   STEPPER NAVIGATION
────────────────────────────────────────────────────── */
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
    if (cur === 2) {
        initMapaUbicacion();
        setTimeout(function(){ if (mapaUbicacion) mapaUbicacion.invalidateSize(); }, 250);
    }
}
document.getElementById('btn-prev').onclick = () => show(cur - 1);
document.getElementById('btn-next').onclick = () => {
    if (cur === 1 && !document.getElementById('hid-tipo_prospecto').value) {
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
    if(name==='p2_es_cliente'){
        document.getElementById('p2-extra').style.display = val==='1' ? 'block':'none';
        const p3SecEl = document.getElementById('p3-sec');
        if (p3SecEl) p3SecEl.style.display = val==='1' ? 'block':'none';
    }
    if(name==='ec_tiene_inversiones'){
        document.getElementById('sec-propuesta-vencimiento').style.display = val==='1' ? 'block':'none';
    }
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
            if(name==='p2_es_cliente'){
        document.getElementById('p2-extra').style.display = val==='1' ? 'block':'none';
        const p3SecEl = document.getElementById('p3-sec');
        if (p3SecEl) p3SecEl.style.display = val==='1' ? 'block':'none';
    }
    if(name==='ec_tiene_inversiones'){
        document.getElementById('sec-propuesta-vencimiento').style.display = val==='1' ? 'block':'none';
    }
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

/* REQUIERE CREDITO TOGGLE */
function toggleRequiereCredito(val) {
    const siOpt = document.getElementById('opt-reqcredito-si');
    const noOpt = document.getElementById('opt-reqcredito-no');
    if (siOpt) siOpt.classList.toggle('checked', val === 1);
    if (noOpt) noOpt.classList.toggle('checked', val === 0);
    
    const radios = document.getElementsByName('fk_requiere_credito');
    radios.forEach(r => {
        if (parseInt(r.value) === val) r.checked = true;
    });
    
    const blockEval = document.getElementById('fk-evaluacion-completa');
    if (blockEval) {
        blockEval.style.display = val === 1 ? 'block' : 'none';
    }
}

/* PRODUCTOS DE INTERES */
var prodSeleccionados = new Set();
/* single-select chip helper */
function chipSingle(el, hidId){
    var parent = el.parentElement;
    parent.querySelectorAll('.chip').forEach(function(c){ c.classList.remove('selected'); });
    el.classList.add('selected');
    var hid = document.getElementById(hidId);
    if(hid) hid.value = el.dataset.val;
    /* special: ahorro programado ÔåÆ show frecuencia */
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
            /* Autocompletar nombre/cédula/celular del titular o solicitante
               con los datos del prospecto ya capturados arriba (f-nombre,
               f-cedula, f-celular), o los del prospecto cargado
               (window._prospectoData) si venimos de una tarea existente.
               Solo se rellena si el campo destino está vacío, para no pisar
               algo que el asesor ya haya editado a mano; el campo sigue
               siendo editable normalmente. */
            if(isOn){
                var base = window._prospectoData || {};
                var elNombre  = document.getElementById('f-nombre');
                var elCedula  = document.getElementById('f-cedula');
                var elCelular = document.getElementById('f-celular');
                var bNombre  = (elNombre  && elNombre.value)  || base.nombre  || '';
                var bCedula  = (elCedula  && elCedula.value)  || base.cedula  || '';
                var bCelular = (elCelular && elCelular.value) || base.celular || base.telefono || '';
                var fillIfEmpty = function(id, val){
                    var f = document.getElementById(id);
                    if(f && !f.value) f.value = val;
                };
                if(prod === 'ahorro'){
                    fillIfEmpty('fa-nombre', bNombre);
                    fillIfEmpty('fa-cedula', bCedula);
                    fillIfEmpty('fa-celular', bCelular);
                }
                if(prod === 'corriente'){
                    fillIfEmpty('fc-nombre', bNombre);
                    fillIfEmpty('fc-cedula', bCedula);
                    fillIfEmpty('fc-celular', bCelular);
                }
                if(prod === 'credito'){
                    fillIfEmpty('fk-sol-nombre', bNombre);
                    fillIfEmpty('fk-sol-cedula', bCedula);
                    fillIfEmpty('fk-sol-celular', bCelular);
                }
            }
        }
    }
}
function actualizarAvisoCredito(){
    var a = document.getElementById('aviso-credito-empresa');
    if(a) a.style.display = (tieneEmpresa && prodSeleccionados.has('credito')) ? 'flex':'none';
}

/* TOGGLE INTERES PRODUCTOS (SÍ/NO) */
function toggleInteresProductos(val) {
    const blockInteres = document.getElementById('sec-interes-detalles');
    const blockNoInteres = document.getElementById('sec-razones-no-interes');
    if (blockInteres) {
        blockInteres.style.display = val === 1 ? 'block' : 'none';
    }
    if (blockNoInteres) {
        blockNoInteres.style.display = val === 0 ? 'block' : 'none';
    }
    if (val === 0) {
        // Limpiar selección de nivel
        document.querySelectorAll('.level-card').forEach(c => c.classList.remove('selected'));
        const hidNivel = document.getElementById('hid-nivel_interes');
        if (hidNivel) hidNivel.value = '';
        
        // Limpiar productos de interés seleccionados
        document.querySelectorAll('.prod-interest-grid .prod-card').forEach(c => {
            c.classList.remove('selected');
            const check = c.querySelector('.pc-check');
            if (check) check.style.display = 'none';
        });
        const hidProd = document.getElementById('hid-prod_interes');
        if (hidProd) hidProd.value = '';
        prodSeleccionados.clear();
        
        // Ocultar fichas de productos activas
        document.querySelectorAll('.ficha-panel').forEach(p => p.style.display = 'none');
        actualizarAvisoCredito();
    } else {
        // Limpiar razones para no contratar si cambia a "Sí"
        document.querySelectorAll('#sec-razones-no-interes input[type="radio"]').forEach(r => {
            r.checked = false;
            const opt = r.closest('.yn-opt');
            if (opt) opt.classList.remove('checked');
        });
        const textarea = document.querySelector('#sec-razones-no-interes textarea');
        if (textarea) textarea.value = '';
    }
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
        function(p){ setUbicacion(p.coords.latitude, p.coords.longitude, true); },
        function(){}, {timeout:5000}
    );
}

/* MAPA UBICACIÓN (dirección del cliente) */
var mapaUbicacion = null, marcadorUbicacion = null;
var UBIC_DEFAULT_LAT = -2.1894, UBIC_DEFAULT_LNG = -78.9233; // centro aprox. Ecuador

function initMapaUbicacion() {
    if (mapaUbicacion || typeof L === 'undefined') return;
    var mapEl = document.getElementById('mapa-ubicacion');
    if (!mapEl) return;
    var latVal = parseFloat(document.getElementById('latitud_inicio').value);
    var lngVal = parseFloat(document.getElementById('longitud_inicio').value);
    var tieneUbic = !isNaN(latVal) && !isNaN(lngVal);
    var lat = tieneUbic ? latVal : UBIC_DEFAULT_LAT;
    var lng = tieneUbic ? lngVal : UBIC_DEFAULT_LNG;

    mapaUbicacion = L.map('mapa-ubicacion').setView([lat, lng], tieneUbic ? 17 : 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(mapaUbicacion);

    marcadorUbicacion = L.marker([lat, lng], {draggable:true}).addTo(mapaUbicacion);
    marcadorUbicacion.on('dragend', function(e){
        var pos = e.target.getLatLng();
        setUbicacion(pos.lat, pos.lng, false);
    });
    mapaUbicacion.on('click', function(e){
        setUbicacion(e.latlng.lat, e.latlng.lng, false);
    });

    if (tieneUbic) actualizarUbicacionInfo(lat, lng);
}

function actualizarUbicacionInfo(lat, lng) {
    var info = document.getElementById('ubicacion-info');
    if (info) info.innerHTML = '<i class="fas fa-check-circle" style="color:#059669;"></i> Ubicación confirmada: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
}

function setUbicacion(lat, lng, centrarMapa) {
    setVal('latitud_inicio', lat);
    setVal('longitud_inicio', lng);
    actualizarUbicacionInfo(lat, lng);
    if (!mapaUbicacion) return;
    if (marcadorUbicacion) marcadorUbicacion.setLatLng([lat, lng]);
    if (centrarMapa) mapaUbicacion.setView([lat, lng], Math.max(mapaUbicacion.getZoom(), 16));
}

function usarMiUbicacion() {
    if (!navigator.geolocation) { alert('Tu navegador no soporta geolocalización.'); return; }
    navigator.geolocation.getCurrentPosition(
        function(p){ setUbicacion(p.coords.latitude, p.coords.longitude, true); },
        function(){ alert('No se pudo obtener tu ubicación GPS. Usa el buscador de dirección o marca el punto en el mapa.'); },
        {timeout:8000, enableHighAccuracy:true}
    );
}

function buscarDireccionMapa() {
    var input = document.getElementById('buscador-direccion-mapa');
    var q = input ? input.value.trim() : '';
    var resultsEl = document.getElementById('resultados-busqueda-direccion');
    if (!q) return;
    var btn = document.getElementById('btn-buscar-direccion');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=6&countrycodes=ec&addressdetails=0&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Buscar'; }
            if (!resultsEl) return;
            if (!data || !data.length) {
                resultsEl.innerHTML = '<div style="padding:10px;font-size:13px;color:var(--brand-gray);">Sin resultados. Prueba con más detalle (calle, sector, ciudad).</div>';
                resultsEl.style.display = 'block';
                return;
            }
            resultsEl.innerHTML = data.map(function(r){
                return '<div class="resultado-direccion" data-lat="'+r.lat+'" data-lng="'+r.lon+'"><i class="fas fa-map-marker-alt"></i>' + esc(r.display_name) + '</div>';
            }).join('');
            resultsEl.style.display = 'block';
        })
        .catch(function(){
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Buscar'; }
            if (resultsEl) { resultsEl.innerHTML = '<div style="padding:10px;font-size:13px;color:#991b1b;">Error al buscar. Intenta de nuevo.</div>'; resultsEl.style.display='block'; }
        });
}

document.addEventListener('click', function(e){
    var item = e.target.closest && e.target.closest('.resultado-direccion');
    if (item) {
        var lat = parseFloat(item.dataset.lat), lng = parseFloat(item.dataset.lng);
        setUbicacion(lat, lng, true);
        var resultsEl = document.getElementById('resultados-busqueda-direccion');
        if (resultsEl) resultsEl.style.display = 'none';
        var input = document.getElementById('buscador-direccion-mapa');
        return;
    }
    var resultsEl2 = document.getElementById('resultados-busqueda-direccion');
    var buscador = document.getElementById('buscador-direccion-mapa');
    if (resultsEl2 && resultsEl2.style.display === 'block' && e.target !== buscador && !e.target.closest('#resultados-busqueda-direccion')) {
        resultsEl2.style.display = 'none';
    }
});

/* ── DOC CHECKLIST ── */
function toggleDoc(el){
    el.classList.toggle('checked');
    var h = el.querySelector('.doc-hidden');
    if(h) h.value = el.classList.contains('checked') ? '1':'0';
}
/* ── INSTITUTION PICKER ── */
function selInst(el, hidId){
    el.closest('.inst-picker').querySelectorAll('.inst-chip').forEach(function(x){ x.classList.remove('sel'); });
    el.classList.add('sel');
    var h = document.getElementById(hidId); if(h) h.value = el.dataset.val;
}
/* ── C├ôNYUGE VISIBILITY ── */
function toggleConyuge(prefix){
    var val = (document.getElementById(prefix+'_sol_ec')||{}).value||'';
    var w = document.getElementById(prefix+'-conyuge-wrap');
    if(w) w.style.display = (val==='casado'||val==='union_libre') ? 'block':'none';
}
function toggleConyugeGar(){
    var val = (document.getElementById('fk_gar_ec')||{}).value||'';
    var w = document.getElementById('fk-conyuge-gar-wrap');
    if(w) w.style.display = (val==='casado'||val==='union_libre') ? 'block':'none';
}
/* ── RADIO CHANGE ÔåÆ show/hide panels ── */
document.addEventListener('change', function(e){
    var inp = e.target; if(!inp||inp.type!=='radio') return;
    var n=inp.name, v=inp.value;
    var pairs = {
    };
    if(pairs[n]){ var w=document.getElementById(pairs[n]); if(w) w.style.display=v==='1'?'block':'none'; }
});

/* FILTRADO INTERACTIVO DE HISTORIAL DE ENCUESTAS */
document.addEventListener('DOMContentLoaded', function() {
    const filtroCliente = document.getElementById('filtro-cliente');
    const filtroFecha = document.getElementById('filtro-fecha');
    const btnLimpiar = document.getElementById('btn-limpiar-filtros');
    const filas = document.querySelectorAll('.fila-encuesta');
    const noResultsRow = document.getElementById('no-results-row');
    const cantEncuestas = document.getElementById('cant-encuestas');
    const filaVacia = document.getElementById('fila-vacia');

    function filtrar() {
        if (!filas.length) return;

        const valCliente = filtroCliente.value.trim().toLowerCase();
        const valFecha = filtroFecha.value;

        let visibles = 0;

        filas.forEach(f => {
            const matchCliente = !valCliente || f.dataset.cliente.includes(valCliente);
            const matchFecha = !valFecha || f.dataset.fecha === valFecha;

            if (matchCliente && matchFecha) {
                f.style.display = '';
                visibles++;
            } else {
                f.style.display = 'none';
            }
        });

        // Actualizar contador de encuestas visibles
        if (cantEncuestas) cantEncuestas.textContent = visibles;

        // Mostrar u ocultar mensaje "no results"
        if (noResultsRow) {
            noResultsRow.style.display = (visibles === 0) ? '' : 'none';
        }
    }

    if (filtroCliente) filtroCliente.addEventListener('input', filtrar);
    if (filtroFecha) filtroFecha.addEventListener('change', filtrar);

    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function() {
            if (filtroCliente) filtroCliente.value = '';
            if (filtroFecha) filtroFecha.value = '';
            filtrar();
        });
    }
});

function validarFormulario() {
    const fCed = document.getElementById('f-cedula');
    const inpCed = document.getElementById('inp-cedula');
    if (fCed && inpCed && inpCed.value.trim() && !fCed.value.trim()) {
        fCed.value = inpCed.value.trim();
    }

    const nombre = document.getElementById('f-nombre').value.trim();
    const cedula = document.getElementById('f-cedula').value.trim();
    const celular = document.getElementById('f-celular').value.trim();

    if (!nombre) {
        alert('Por favor, ingresa el nombre completo del prospecto/cliente.');
        if (typeof show === 'function') show(2); // Ir a la pestaña de Datos personales
        document.getElementById('f-nombre').focus();
        return false;
    }
    if (!cedula) {
        alert('Por favor, ingresa la cédula del prospecto/cliente.');
        if (typeof show === 'function') show(2); // Ir a la pestaña de Datos personales
        document.getElementById('f-cedula').focus();
        return false;
    }
    if (!celular) {
        alert('Por favor, ingresa el celular del prospecto/cliente.');
        if (typeof show === 'function') show(2); // Ir a la pestaña de Datos personales
        document.getElementById('f-celular').focus();
        return false;
    }
    return true;
}

/* ──────────────────────────────────────────────────────
   GUARDAR ENCUESTA CON FETCH — Replica mobile (NuevaEncuestaScreen.dart)
────────────────────────────────────────────────────── */
const formEnc_ref = document.getElementById('formEncuesta');
if (formEnc_ref) {
    formEnc_ref.addEventListener('submit', async function(e) {
        e.preventDefault();
        if (!validarFormulario()) return;
        
        await guardarEncuestaFetch();
    });
}

let guardandoEncuesta = false;

async function guardarEncuestaFetch() {
    if (guardandoEncuesta) return;
    guardandoEncuesta = true;
    
    const btnSave = document.getElementById('btn-save');
    if (btnSave) {
        btnSave.disabled = true;
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando…';
    }
    
    try {
        // Recopilar datos del formulario
        const formData = new FormData(document.getElementById('formEncuesta'));
        
        // Determinar endpoint: si hay tarea_id, es edición
        const tareaId = formData.get('tarea_id') || '';
        // Para coincidir con el patrón móvil, usar el endpoint de actualización en edición
        const endpoint = tareaId ? '../actualizar_encuesta_completa.php' : '../guardar_cliente_encuesta.php';

        /* ── Normalizar productos de interés ────────────────────
           El formulario maneja los productos como una lista CSV
           (prod_interes) y un radio (interesado_productos), pero el
           servidor espera campos separados. Se convierten aquí para
           que los productos modificados SÍ se guarden en la base. */
        const prodCsv  = (formData.get('prod_interes') || '').toString();
        const prodList = prodCsv.split(',').map(s => s.trim()).filter(Boolean);
        formData.set('interes_ahorro',    prodList.includes('ahorro')    ? '1' : '0');
        formData.set('interes_cc',        prodList.includes('corriente') ? '1' : '0');
        formData.set('interes_inversion', prodList.includes('inversion') ? '1' : '0');
        formData.set('interes_credito',   prodList.includes('credito')   ? '1' : '0');

        const interesadoRadio = document.querySelector('input[name="interesado_productos"]:checked');
        formData.set('interes_conocer_productos', interesadoRadio ? interesadoRadio.value : '0');

        // Enviar con fetch
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData
        });
        
        // Clonar respuesta para poder leerla múltiples veces
        const responseClone = response.clone();
        
        if (!response.ok) {
            const errorText = await responseClone.text();
            console.error('Error HTTP:', response.status, errorText);
            mostrarErrorGuardado(errorText || `Error HTTP ${response.status}`);
            return;
        }
        
        // Intentar parsear JSON
        let data;
        try {
            data = await responseClone.json();
        } catch (parseErr) {
            console.error('Error parseando JSON:', parseErr);
            const rawText = await responseClone.text();
            console.error('Respuesta recibida:', rawText);
            mostrarErrorGuardado('La respuesta del servidor no es válida JSON');
            return;
        }
        
        // Validar estructura de respuesta
        if (!data || data.status === 'error') {
            const msg = data?.message || 'Error desconocido al guardar';
            console.error('Error en respuesta:', msg);
            mostrarErrorGuardado(msg);
            return;
        }
        
        // ÉXITO: mostrar diálogo según tipo (edición vs nueva)
        if (tareaId) {
            // Modo edición: mostrar "Cambios guardados"
            mostrarDialogoModificacionOk();
        } else {
            // Modo creación: mostrar "Tarea finalizada"
            mostrarDialogoFinalizadoOk();
        }
        
    } catch (err) {
        console.error('Error en guardar:', err);
        mostrarErrorGuardado('No se pudo guardar en el servidor: ' + err.message);
    } finally {
        guardandoEncuesta = false;
        if (btnSave) {
            btnSave.disabled = false;
            btnSave.innerHTML = '<i class="fas fa-circle-check"></i> Guardar encuesta';
        }
    }
}

function mostrarErrorGuardado(mensaje) {
    // Modal de error (similar a mobile)
    const div = document.createElement('div');
    div.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;
    
    div.innerHTML = `
        <div style="
            background: white;
            border-radius: 20px;
            padding: 28px;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        ">
            <div style="
                width: 64px;
                height: 64px;
                background: #fee2e2;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 18px;
                font-size: 32px;
            ">
                <i class="fas fa-exclamation-circle" style="color: #dc2626;"></i>
            </div>
            <h3 style="color: #1f2937; font-size: 18px; font-weight: 700; margin-bottom: 10px;">
                Error al guardar
            </h3>
            <p style="color: #6b7280; font-size: 14px; margin-bottom: 24px; line-height: 1.5;">
                ${mensaje}
            </p>
            <button onclick="this.closest('div').remove()" style="
                background: #dc2626;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 10px;
                font-weight: 700;
                cursor: pointer;
                width: 100%;
                font-size: 14px;
            ">
                Entendido
            </button>
        </div>
    `;
    document.body.appendChild(div);
}

function mostrarDialogoModificacionOk() {
    const div = document.createElement('div');
    div.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;
    
    div.innerHTML = `
        <div style="
            background: white;
            border-radius: 20px;
            padding: 28px;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        ">
            <div style="
                width: 64px;
                height: 64px;
                background: linear-gradient(135deg, #10b981, #123a6d);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 18px;
                font-size: 32px;
            ">
                <i class="fas fa-save" style="color: white;"></i>
            </div>
            <h3 style="color: #1f2937; font-size: 18px; font-weight: 700; margin-bottom: 10px;">
                Cambios guardados
            </h3>
            <p style="color: #6b7280; font-size: 14px; margin-bottom: 24px;">
                Los datos de la encuesta se actualizaron correctamente.
            </p>
            <button onclick="window.location.href='nueva_encuesta.php'" style="
                background: #ffdd00;
                color: #123a6d;
                border: none;
                padding: 12px 24px;
                border-radius: 10px;
                font-weight: 700;
                cursor: pointer;
                width: 100%;
                font-size: 14px;
            ">
                Volver
            </button>
        </div>
    `;
    document.body.appendChild(div);
}

function mostrarDialogoFinalizadoOk() {
    const div = document.createElement('div');
    div.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    `;
    
    div.innerHTML = `
        <div style="
            background: white;
            border-radius: 20px;
            padding: 28px;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        ">
            <div style="
                width: 64px;
                height: 64px;
                background: linear-gradient(135deg, #ffdd00, #123a6d);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 18px;
                font-size: 32px;
            ">
                <i class="fas fa-check" style="color: white;"></i>
            </div>
            <h3 style="color: #1f2937; font-size: 18px; font-weight: 700; margin-bottom: 10px;">
                Tarea Finalizada
            </h3>
            <p style="color: #6b7280; font-size: 14px; margin-bottom: 24px;">
                Encuesta y datos del prospecto guardados correctamente.
            </p>
            <button onclick="window.location.href='nueva_encuesta.php'" style="
                background: linear-gradient(135deg, #123a6d, #0a2748);
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 10px;
                font-weight: 700;
                cursor: pointer;
                width: 100%;
                font-size: 14px;
            ">
                Volver a Nueva Encuesta
            </button>
        </div>
    `;
    document.body.appendChild(div);
}

/* ── Searchable select ──────────────────────────────────────────────────── */
// Antes esto solo escondía las <option> que no calzaban con lo escrito
// DENTRO del <select> nativo, que seguía cerrado (una sola línea, "—
// Seleccione —") hasta que el usuario lo abría con un clic aparte: por eso
// se veían "dos controles" (la caja de texto arriba + el select cerrado
// abajo) en lugar de un solo autocompletado que muestra la lista al tipear.
// Ahora el <select> original se mantiene oculto (solo para que el formulario
// siga enviando su .value tal cual) y la lista visible/filtrada es un <div>
// desplegable propio que aparece debajo del input al escribir o al hacer
// focus, y se cierra al elegir una opción o al hacer clic afuera.
function initSearchableSelects() {
    document.querySelectorAll('select[data-searchable="true"]').forEach(function(sel) {
        if (sel.parentElement && sel.parentElement.classList.contains('srch-wrap')) return;

        var placeholder = sel.getAttribute('data-placeholder') || 'Buscar...';

        var wrap = document.createElement('div');
        wrap.className = 'srch-wrap';
        wrap.style.cssText = 'position:relative;width:100%;';

        var inp = document.createElement('input');
        inp.type        = 'text';
        inp.placeholder = placeholder;
        inp.autocomplete = 'off';
        inp.style.cssText = 'width:100%;box-sizing:border-box;padding:7px 30px 7px 10px;' +
            'border:1px solid #d1d5db;border-radius:6px;font-size:13px;';

        // El <select> original queda oculto: sigue siendo la "fuente de
        // verdad" para el envío del formulario (name + value), pero ya no
        // se muestra como control aparte.
        sel.style.cssText = (sel.getAttribute('style') || '') + ';display:none;';

        var list = document.createElement('div');
        list.className = 'srch-list';
        list.style.cssText = 'position:absolute;left:0;right:0;top:100%;z-index:60;' +
            'max-height:220px;overflow-y:auto;background:#fff;border:1px solid #d1d5db;' +
            'border-radius:0 0 8px 8px;box-shadow:0 8px 18px rgba(15,23,42,.12);display:none;';

        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(inp);
        wrap.appendChild(sel);
        wrap.appendChild(list);

        function currentOptions() {
            return Array.from(sel.options).filter(function(o) { return o.value !== ''; });
        }

        function selectOption(opt) {
            sel.value = opt ? opt.value : '';
            inp.value = opt ? opt.text : '';
            list.style.display = 'none';
            // Notificar a cualquier listener que dependa del 'change' nativo
            // del <select> (código legado de esta misma página).
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Igual que selectOption(null) pero SIN tocar el texto del input:
        // se usa mientras el usuario está escribiendo, para invalidar la
        // selección previa sin borrarle lo que acaba de tipear.
        function clearSelectionKeepText() {
            if (!sel.value) return;
            sel.value = '';
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function renderList(filterText) {
            var q = (filterText || '').trim().toLowerCase();
            var matches = currentOptions().filter(function(o) {
                return q === '' || o.text.toLowerCase().indexOf(q) > -1;
            });
            list.innerHTML = '';
            if (matches.length === 0) {
                var empty = document.createElement('div');
                empty.textContent = 'Sin resultados';
                empty.style.cssText = 'padding:10px 12px;color:#9ca3af;font-size:13px;text-align:center;';
                list.appendChild(empty);
            } else {
                matches.slice(0, 300).forEach(function(o) {
                    var item = document.createElement('div');
                    item.textContent = o.text;
                    item.style.cssText = 'padding:8px 12px;font-size:13px;cursor:pointer;';
                    item.addEventListener('mouseenter', function() { item.style.background = '#f3f4f6'; });
                    item.addEventListener('mouseleave', function() { item.style.background = ''; });
                    // mousedown (no click) para que dispare ANTES del blur del input
                    item.addEventListener('mousedown', function(ev) {
                        ev.preventDefault();
                        selectOption(o);
                    });
                    list.appendChild(item);
                });
            }
            list.style.display = 'block';
        }

        inp.addEventListener('input', function() {
            clearSelectionKeepText(); // el usuario vuelve a escribir: se invalida la selección previa
            renderList(inp.value);
        });

        inp.addEventListener('focus', function() {
            renderList(inp.value);
        });

        inp.addEventListener('blur', function() {
            // Pequeño delay para que el mousedown de un item alcance a procesarse
            setTimeout(function() { list.style.display = 'none'; }, 150);
        });

        // Si el <select> ya trae un valor precargado (modo edición / datos
        // cargados por cédula), reflejarlo en el input visible.
        syncSearchableDisplay(sel);
    });
}

/// Mantiene sincronizado el input visible de un select buscable con su
/// valor real. Se usa tanto al inicializar (arriba) como cada vez que
/// algún código de la página asigna `.value` directamente al <select>
/// (por ejemplo svByName/localSvByName al cargar datos de un cliente).
function syncSearchableDisplay(sel) {
    if (!sel || sel.tagName !== 'SELECT') return;
    if (!sel.parentElement || !sel.parentElement.classList.contains('srch-wrap')) return;
    var inp = sel.parentElement.querySelector('input[type="text"]');
    if (!inp) return;
    var opt = sel.options[sel.selectedIndex];
    inp.value = (opt && opt.value) ? opt.text : '';
}

document.addEventListener('DOMContentLoaded', function() {
    initSearchableSelects();
    document.addEventListener('click', function(e) {
        if (e.target.closest && e.target.closest('.yn-opt')) {
            setTimeout(initSearchableSelects, 150);
        }
    });
});
</script>
</body>
</html>

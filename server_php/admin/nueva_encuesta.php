<?php
require_once 'db_admin.php';

if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    header('Location: login.php?role=asesor');
    exit;
}

$asesor_usuario_id = $_SESSION['asesor_id'];
$asesor_nombre     = $_SESSION['asesor_nombre'] ?? 'Asesor';

$asesor_table_id = null;
try {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$asesor_usuario_id]);
    $asesor_table_id = $st->fetchColumn() ?: null;
} catch (PDOException $e) {}

/* ---------- Si llega tarea_id, precargar cliente y tarea ---------- */
$tarea_id   = $_GET['tarea_id'] ?? '';
$cliente_id = $_GET['cliente_id'] ?? '';
$tarea = null;
$cliente = null;

if ($tarea_id) {
    try {
        $st = $pdo->prepare("SELECT t.*, cp.* , t.id AS tarea_id_real, cp.id AS cliente_id_real
                             FROM tarea t
                             LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                             WHERE t.id = ? LIMIT 1");
        $st->execute([$tarea_id]);
        $tarea = $st->fetch();
        if ($tarea) {
            $cliente_id = $tarea['cliente_id_real'] ?? '';
        }
    } catch (PDOException $e) {}
}
if (!$tarea && $cliente_id) {
    try {
        $st = $pdo->prepare("SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1");
        $st->execute([$cliente_id]);
        $cliente = $st->fetch();
    } catch (PDOException $e) {}
} elseif ($tarea) {
    $cliente = $tarea;
}

/* sidebar badges */
$tareas_pendientes = 0;
$alertas_pendientes = 0;
try {
    if ($asesor_table_id) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM tarea WHERE asesor_id = ? AND fecha_programada = ? AND estado <> 'completada'");
        $st->execute([$asesor_table_id, date('Y-m-d')]);
        $tareas_pendientes = (int)$st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) FROM alerta_modificacion WHERE asesor_id = ? AND vista_supervisor = 0");
        $st->execute([$asesor_table_id]);
        $alertas_pendientes = (int)$st->fetchColumn();
    }
} catch (PDOException $e) {}

$currentPage = 'encuesta';
$v = fn($k, $default = '') => htmlspecialchars((string)($cliente[$k] ?? $default));
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
.sidebar{width:230px;background:linear-gradient(180deg,var(--brand-navy-deep) 0%,var(--brand-navy) 100%);color:#fff;padding:20px 0;overflow-y:auto;position:fixed;height:100vh;left:0;top:0;z-index:100;}
.sidebar-brand{padding:0 20px 24px;font-size:18px;font-weight:800;border-bottom:1px solid rgba(255,221,0,.18);margin-bottom:20px;display:flex;align-items:center;gap:10px;}
.sidebar-brand i{color:var(--brand-yellow);}
.sidebar-section{padding:0 15px;margin-bottom:22px;}
.sidebar-section-title{font-size:11px;text-transform:uppercase;color:rgba(255,255,255,.5);letter-spacing:.6px;padding:0 10px;margin-bottom:10px;font-weight:700;}
.sidebar-link{display:flex;align-items:center;gap:12px;padding:11px 15px;margin-bottom:4px;border-radius:10px;color:rgba(255,255,255,.82);text-decoration:none;font-size:14px;border:1px solid transparent;transition:.22s;}
.sidebar-link:hover{background:rgba(255,221,0,.12);color:#fff;padding-left:20px;border-color:rgba(255,221,0,.15);}
.sidebar-link.active{background:linear-gradient(90deg,var(--brand-yellow),var(--brand-yellow-deep));color:var(--brand-navy-deep);font-weight:700;}
.badge-nav{background:#dc2626;color:#fff;font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;margin-left:auto;}

.main-content{flex:1;margin-left:230px;display:flex;flex-direction:column;min-width:0;}
.navbar-custom{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;padding:14px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 12px 28px rgba(18,58,109,.18);position:sticky;top:0;z-index:50;}
.navbar-custom h2{margin:0;font-size:19px;font-weight:700;display:flex;align-items:center;gap:10px;}
.navbar-custom h2 i{color:var(--brand-yellow);}
.user-info{display:flex;align-items:center;gap:14px;font-size:13px;}
.btn-logout{background:rgba(255,221,0,.15);color:#fff;border:1px solid rgba(255,221,0,.28);padding:7px 14px;border-radius:10px;text-decoration:none;font-weight:600;font-size:13px;}

.content-area{flex:1;padding:24px 30px 40px;}

/* Stepper */
.stepper{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:18px 20px;margin-bottom:18px;box-shadow:var(--brand-shadow-sm);overflow-x:auto;gap:10px;}
.step{display:flex;align-items:center;gap:10px;flex:1;min-width:140px;}
.step .num{width:34px;height:34px;border-radius:50%;background:#e5e7eb;color:#6b7280;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0;}
.step .lbl{font-size:12.5px;color:var(--brand-gray);font-weight:700;line-height:1.2;}
.step.active .num{background:var(--brand-yellow);color:var(--brand-navy-deep);box-shadow:0 4px 10px rgba(255,221,0,.45);}
.step.active .lbl{color:var(--brand-navy-deep);}
.step.done .num{background:#10b981;color:#fff;}
.step.done .lbl{color:#065f46;}
.step .line{flex:1;height:2px;background:#e5e7eb;margin:0 6px;}
.step:last-child .line{display:none;}

/* Card */
.form-card{background:#fff;border:1px solid var(--brand-border);border-radius:16px;padding:22px 24px;box-shadow:var(--brand-shadow-sm);margin-bottom:16px;}
.form-card h3{font-size:18px;font-weight:800;margin-bottom:4px;display:flex;align-items:center;gap:10px;}
.form-card h3 i{color:var(--brand-yellow-deep);}
.form-card .sub{color:var(--brand-gray);font-size:13.5px;margin-bottom:18px;}

.fld-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px 18px;}
.fld{display:flex;flex-direction:column;gap:5px;}
.fld label{font-size:11.5px;color:var(--brand-gray);text-transform:uppercase;font-weight:800;letter-spacing:.3px;}
.fld input,.fld select,.fld textarea{padding:10px 12px;border:1.5px solid var(--brand-border);border-radius:10px;font-size:14px;font-family:inherit;background:#fff;transition:.2s;}
.fld input:focus,.fld select:focus,.fld textarea:focus{outline:none;border-color:var(--brand-yellow-deep);box-shadow:0 0 0 3px rgba(255,221,0,.15);}
.fld textarea{resize:vertical;min-height:70px;}
.fld.full{grid-column:1/-1;}
.fld .hint{font-size:11.5px;color:var(--brand-gray);font-weight:500;margin-top:2px;}

/* YN toggle */
.yn-group{display:flex;gap:6px;}
.yn-opt{flex:1;padding:10px;text-align:center;border:1.5px solid var(--brand-border);border-radius:10px;cursor:pointer;font-weight:700;font-size:13px;background:#fff;color:#374151;transition:.2s;}
.yn-opt:hover{background:#f3f4f6;}
.yn-opt input{display:none;}
.yn-opt.checked{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;border-color:transparent;}

/* Sub-section */
.sub-sec{margin-top:18px;padding-top:14px;border-top:1px dashed #e5e7eb;}
.sub-sec h5{font-size:12.5px;text-transform:uppercase;color:var(--brand-navy);font-weight:800;letter-spacing:.4px;margin-bottom:10px;display:flex;align-items:center;gap:7px;}
.sub-sec h5 i{color:var(--brand-yellow-deep);}

/* Productos repeater */
.prod-list{display:flex;flex-direction:column;gap:10px;}
.prod-item{background:#fafbfc;border:1px solid #eef2f6;border-radius:10px;padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px 12px;position:relative;}
.prod-item .del{position:absolute;top:8px;right:8px;background:#fef2f2;color:#991b1b;border:none;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer;font-weight:700;}
.prod-item .del:hover{background:#fee2e2;}
.btn-add-prod{background:#fef9c3;color:#854d0e;border:1.5px dashed #fde68a;padding:10px;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;width:100%;}
.btn-add-prod:hover{background:#fef08a;}

/* Footer */
.form-footer{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid var(--brand-border);border-radius:14px;padding:14px 18px;box-shadow:var(--brand-shadow-sm);position:sticky;bottom:14px;z-index:30;}
.btn{padding:11px 22px;border-radius:11px;font-weight:800;font-size:14px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;text-decoration:none;}
.btn-yellow{background:var(--brand-yellow);color:var(--brand-navy-deep);}
.btn-yellow:hover{background:var(--brand-yellow-deep);}
.btn-ghost{background:#f3f4f6;color:#374151;}
.btn-ghost:hover{background:#e5e7eb;}
.btn-primary{background:linear-gradient(135deg,var(--brand-navy-deep),var(--brand-navy));color:#fff;}

.step-pane{display:none;}
.step-pane.active{display:block;animation:fadein .2s ease;}
@keyframes fadein{from{opacity:0;transform:translateY(4px);}to{opacity:1;transform:none;}}

@media (max-width:768px){
    .sidebar{transform:translateX(-100%);}
    .main-content{margin-left:0;}
    .content-area{padding:14px;}
    .stepper{padding:12px;}
    .step{min-width:auto;}
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
        <h2><i class="fas fa-clipboard-list"></i> Nueva Encuesta</h2>
        <div class="user-info">
            <div><strong><?= htmlspecialchars($asesor_nombre) ?></strong></div>
            <a href="logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i>Salir</a>
        </div>
    </div>

    <div class="content-area">

        <!-- Stepper -->
        <div class="stepper" id="stepper">
            <?php
            $steps = [
                ['Cliente',     'fa-user'],
                ['Negocio',     'fa-store'],
                ['E. Comercial','fa-clipboard-list'],
                ['E. Crediticia','fa-credit-card'],
                ['Productos',   'fa-tag'],
                ['Acuerdo',     'fa-handshake'],
            ];
            foreach ($steps as $i => [$lbl, $ico]): ?>
                <div class="step <?= $i===0?'active':'' ?>" data-step="<?= $i ?>">
                    <div class="num"><?= $i+1 ?></div>
                    <div class="lbl"><?= $lbl ?></div>
                    <div class="line"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <form id="formEncuesta" method="post" action="guardar_encuesta.php" autocomplete="off">
            <input type="hidden" name="tarea_id"   value="<?= htmlspecialchars($tarea_id) ?>">
            <input type="hidden" name="cliente_id" value="<?= htmlspecialchars($cliente_id) ?>">
            <input type="hidden" name="lat" id="lat">
            <input type="hidden" name="lng" id="lng">

            <!-- ====== STEP 1: CLIENTE ====== -->
            <div class="step-pane active" data-pane="0">
                <div class="form-card">
                    <h3><i class="fas fa-user"></i>Datos del cliente / prospecto</h3>
                    <div class="sub">Verifica o completa la información personal antes de empezar la encuesta.</div>
                    <div class="fld-grid">
                        <div class="fld"><label>Nombre completo *</label><input type="text" name="nombre" required value="<?= $v('nombre') ?>"></div>
                        <div class="fld"><label>Cédula *</label><input type="text" name="cedula" required value="<?= $v('cedula') ?>"></div>
                        <div class="fld"><label>Cédula cónyuge</label><input type="text" name="cedula_conyuge" value="<?= $v('cedula_conyuge') ?>"></div>
                        <div class="fld"><label>Teléfono *</label><input type="tel" name="telefono" required value="<?= $v('telefono') ?>"></div>
                        <div class="fld"><label>Teléfono 2</label><input type="tel" name="telefono2" value="<?= $v('telefono2') ?>"></div>
                        <div class="fld"><label>Email</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
                        <div class="fld full"><label>Dirección</label><input type="text" name="direccion" value="<?= $v('direccion') ?>"></div>
                        <div class="fld"><label>Ciudad</label><input type="text" name="ciudad" value="<?= $v('ciudad') ?>"></div>
                        <div class="fld"><label>Zona</label><input type="text" name="zona" value="<?= $v('zona') ?>"></div>
                        <div class="fld"><label>Subzona</label><input type="text" name="subzona" value="<?= $v('subzona') ?>"></div>
                        <div class="fld"><label>Estado</label>
                            <select name="estado">
                                <?php foreach (['prospecto','cliente','pendiente','descartado'] as $e): ?>
                                    <option value="<?= $e ?>" <?= ($cliente['estado'] ?? 'prospecto')===$e?'selected':'' ?>><?= ucfirst($e) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== STEP 2: NEGOCIO ====== -->
            <div class="step-pane" data-pane="1">
                <div class="form-card">
                    <h3><i class="fas fa-store"></i>Información del negocio</h3>
                    <div class="sub">Datos económicos del cliente y su empresa o actividad.</div>
                    <div class="fld-grid">
                        <div class="fld"><label>Actividad económica</label>
                            <select name="actividad">
                                <option value="">Seleccione</option>
                                <?php foreach (['negocio_propio'=>'Negocio propio','empleado_privado'=>'Empleado privado','empleado_publico'=>'Empleado público','independiente'=>'Independiente','otro'=>'Otro'] as $k=>$lab): ?>
                                    <option value="<?= $k ?>" <?= ($cliente['actividad'] ?? '')===$k?'selected':'' ?>><?= $lab ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fld"><label>Nombre de la empresa / negocio</label><input type="text" name="nombre_empresa" value="<?= $v('nombre_empresa') ?>"></div>
                        <?= ynBlock('Tiene RUC',  'tiene_ruc',  $cliente['tiene_ruc'] ?? null) ?>
                        <?= ynBlock('Tiene RISE', 'tiene_rise', $cliente['tiene_rise'] ?? null) ?>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-chart-line"></i>Comportamiento de ventas semanal</h5>
                        <div class="fld-grid">
                            <div class="fld"><label>Día de la semana</label>
                                <select name="cv_dia_semana">
                                    <option value="">Seleccione</option>
                                    <?php foreach (['lunes','martes','miércoles','jueves','viernes','sábado','domingo'] as $d): ?>
                                        <option value="<?= $d ?>"><?= ucfirst($d) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld"><label>Calificación</label>
                                <select name="cv_calificacion">
                                    <option value="">—</option>
                                    <option value="bueno">Bueno</option>
                                    <option value="regular">Regular</option>
                                    <option value="malo">Malo</option>
                                </select>
                            </div>
                            <div class="fld"><label>Valor venta promedio (USD)</label><input type="number" step="0.01" name="cv_valor_venta"></div>
                            <div class="fld"><label>Valor compra promedio (USD)</label><input type="number" step="0.01" name="cv_valor_compra"></div>
                            <div class="fld"><label>Venta promedio mensual</label><input type="number" step="0.01" name="cv_venta_promedio_mes"></div>
                            <div class="fld"><label>Compra promedio mensual</label><input type="number" step="0.01" name="cv_compra_promedio_mes"></div>
                            <div class="fld"><label>% Contado</label><input type="number" min="0" max="100" name="cv_porcentaje_contado" value="100"></div>
                            <div class="fld"><label>% Crédito</label><input type="number" min="0" max="100" name="cv_porcentaje_credito" value="0"></div>
                            <div class="fld"><label>Días atención por semana</label><input type="number" min="0" max="7" name="cv_dias_atencion"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== STEP 3: ENCUESTA COMERCIAL ====== -->
            <div class="step-pane" data-pane="2">
                <div class="form-card">
                    <h3><i class="fas fa-clipboard-list"></i>Encuesta Comercial</h3>
                    <div class="sub">Productos financieros que ya tiene y nivel de interés del prospecto.</div>

                    <div class="sub-sec" style="border-top:none;padding-top:0;margin-top:0;">
                        <h5><i class="fas fa-piggy-bank"></i>Productos que ya posee</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Mantiene cuenta de ahorro?',     'ec_mantiene_cuenta_ahorro') ?>
                            <?= ynBlock('¿Mantiene cuenta corriente?',     'ec_mantiene_cuenta_corriente') ?>
                            <?= ynBlock('¿Tiene inversiones?',             'ec_tiene_inversiones') ?>
                            <div class="fld"><label>Institución de inversiones</label><input type="text" name="ec_institucion_inversiones"></div>
                            <div class="fld"><label>Valor inversión (USD)</label><input type="number" step="0.01" name="ec_valor_inversion"></div>
                            <div class="fld"><label>Plazo inversión</label><input type="text" name="ec_plazo_inversion"></div>
                            <div class="fld"><label>Vencimiento inversión</label><input type="date" name="ec_fecha_vencimiento_inversion"></div>
                            <?= ynBlock('¿Tiene operaciones crediticias?', 'ec_tiene_operaciones_crediticias') ?>
                            <div class="fld"><label>Institución del crédito actual</label><input type="text" name="ec_institucion_credito"></div>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-bullhorn"></i>Nivel de interés</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Interés en propuesta previa?',  'ec_interes_propuesta_previa') ?>
                            <div class="fld"><label>Fecha próximo contacto</label><input type="date" name="ec_fecha_nuevo_contacto"></div>
                            <?= ynBlock('¿Interés en conocer productos?', 'ec_interes_conocer_productos') ?>
                            <div class="fld"><label>Nivel de interés captado</label>
                                <select name="ec_nivel_interes_captado">
                                    <option value="">—</option>
                                    <option value="ninguno">Ninguno</option>
                                    <option value="bajo">Bajo</option>
                                    <option value="alto">Alto</option>
                                </select>
                            </div>
                            <?= ynBlock('Interés cuenta corriente', 'ec_interes_cc') ?>
                            <?= ynBlock('Interés ahorro',           'ec_interes_ahorro') ?>
                            <?= ynBlock('Interés inversión',        'ec_interes_inversion') ?>
                            <?= ynBlock('Interés crédito',          'ec_interes_credito') ?>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-circle-question"></i>Razones de no contratar</h5>
                        <div class="fld-grid">
                            <?= ynBlock('Ya trabaja con la institución', 'ec_razon_ya_trabaja_institucion') ?>
                            <?= ynBlock('Desconfía de los servicios',    'ec_razon_desconfia_servicios') ?>
                            <?= ynBlock('Está a gusto con su actual',    'ec_razon_agusto_actual') ?>
                            <?= ynBlock('Mala experiencia previa',       'ec_razon_mala_experiencia') ?>
                            <div class="fld full"><label>Otras razones</label><textarea name="ec_razon_otros"></textarea></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== STEP 4: ENCUESTA CREDITICIA ====== -->
            <div class="step-pane" data-pane="3">
                <div class="form-card">
                    <h3><i class="fas fa-credit-card"></i>Encuesta Crediticia</h3>
                    <div class="sub">Necesidad de crédito y qué busca el cliente al elegir una institución.</div>

                    <div class="sub-sec" style="border-top:none;padding-top:0;margin-top:0;">
                        <h5><i class="fas fa-building-columns"></i>Productos que ya posee</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Tiene producto financiero?',  'ek_tiene_producto_financiero') ?>
                            <div class="fld"><label>Institución actual</label><input type="text" name="ek_institucion_actual"></div>
                            <div class="fld"><label>Tipo de producto actual</label><input type="text" name="ek_tipo_producto_actual"></div>
                            <?= ynBlock('¿Tiene CDP?',                 'ek_tiene_cdp') ?>
                            <div class="fld"><label>Vencimiento CDP</label><input type="date" name="ek_fecha_vencimiento_cdp"></div>
                            <?= ynBlock('¿Interés en propuesta CDP?',  'ek_interes_propuesta_cdp') ?>
                            <div class="fld"><label>Fecha contacto CDP</label><input type="date" name="ek_fecha_contacto_cdp"></div>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-money-bill-trend-up"></i>Necesidad de crédito</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Requiere crédito?', 'ek_requiere_credito') ?>
                            <div class="fld"><label>Destino del crédito</label>
                                <select name="ek_destino_credito">
                                    <option value="">—</option>
                                    <?php foreach (['capital_trabajo'=>'Capital de trabajo','activos_fijos'=>'Activos fijos','pago_deudas'=>'Pago de deudas','consumo'=>'Consumo','otro'=>'Otro'] as $k=>$lab): ?>
                                        <option value="<?= $k ?>"><?= $lab ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fld"><label>Monto solicitado (USD)</label><input type="number" step="0.01" name="ek_monto_solicitado"></div>
                            <div class="fld"><label>Institución de crédito actual</label><input type="text" name="ek_institucion_credito_actual"></div>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-magnifying-glass-dollar"></i>¿Qué busca el cliente?</h5>
                        <div class="fld-grid">
                            <?= ynBlock('Agilidad en la atención', 'ek_que_busca_agilidad') ?>
                            <?= ynBlock('Cajeros disponibles',     'ek_que_busca_cajeros') ?>
                            <?= ynBlock('Banca en línea',          'ek_que_busca_banca_linea') ?>
                            <?= ynBlock('Más agencias',            'ek_que_busca_agencias') ?>
                            <?= ynBlock('Crédito rápido',          'ek_que_busca_credito_rapido') ?>
                            <?= ynBlock('Tarjeta de débito',       'ek_que_busca_tarjeta_debito') ?>
                            <?= ynBlock('Tarjeta de crédito',      'ek_que_busca_tarjeta_credito') ?>
                            <div class="fld full"><label>Otras búsquedas</label><textarea name="ek_que_busca_otros"></textarea></div>
                        </div>
                    </div>

                    <div class="sub-sec">
                        <h5><i class="fas fa-bullhorn"></i>Interés del prospecto</h5>
                        <div class="fld-grid">
                            <?= ynBlock('¿Interés en productos?', 'ek_interes_productos') ?>
                            <div class="fld"><label>Nivel de interés</label>
                                <select name="ek_nivel_interes">
                                    <option value="">—</option>
                                    <option value="ninguno">Ninguno</option>
                                    <option value="bajo">Bajo</option>
                                    <option value="alto">Alto</option>
                                </select>
                            </div>
                            <?= ynBlock('Interés CC',         'ek_interes_cc') ?>
                            <?= ynBlock('Interés ahorro',     'ek_interes_ahorro') ?>
                            <?= ynBlock('Interés inversión',  'ek_interes_inversion') ?>
                            <?= ynBlock('Interés crédito',    'ek_interes_credito') ?>
                            <div class="fld full"><label>Otros intereses</label><textarea name="ek_interes_otros"></textarea></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ====== STEP 5: PRODUCTOS COMERCIALIZADOS ====== -->
            <div class="step-pane" data-pane="4">
                <div class="form-card">
                    <h3><i class="fas fa-tag"></i>Productos que comercializa el negocio</h3>
                    <div class="sub">Agrega los productos principales que vende. Esto luego aparece en la aleta del cliente.</div>

                    <div class="prod-list" id="prod-list">
                        <!-- los productos se inyectan con JS -->
                    </div>
                    <button type="button" class="btn-add-prod" id="btn-add-prod" style="margin-top:10px;">
                        <i class="fas fa-plus"></i> Agregar producto
                    </button>
                </div>
            </div>

            <!-- ====== STEP 6: ACUERDO ====== -->
            <div class="step-pane" data-pane="5">
                <div class="form-card">
                    <h3><i class="fas fa-handshake"></i>Acuerdo logrado y observaciones</h3>
                    <div class="sub">Indica el siguiente paso pactado con el cliente.</div>
                    <div class="fld-grid">
                        <div class="fld"><label>Tipo de acuerdo</label>
                            <select name="acuerdo_logrado">
                                <option value="">—</option>
                                <?php foreach (['nueva_cita_campo'=>'Nueva cita en campo','nueva_cita_oficina'=>'Nueva cita en oficina','reprogramacion'=>'Reprogramación','seguimiento'=>'Seguimiento','otro'=>'Otro'] as $k=>$lab): ?>
                                    <option value="<?= $k ?>"><?= $lab ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fld"><label>Fecha del acuerdo</label><input type="date" name="fecha_acuerdo"></div>
                        <div class="fld"><label>Hora</label><input type="time" name="hora_acuerdo"></div>
                        <div class="fld full"><label>Observaciones generales</label><textarea name="observaciones" rows="4"></textarea></div>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <button type="button" class="btn btn-ghost" id="btn-prev"><i class="fas fa-arrow-left"></i> Anterior</button>
                <div style="font-size:13px;color:var(--brand-gray);">
                    Paso <span id="step-num">1</span> de <?= count($steps) ?>
                </div>
                <button type="button" class="btn btn-yellow" id="btn-next">Siguiente <i class="fas fa-arrow-right"></i></button>
                <button type="submit" class="btn btn-primary" id="btn-save" style="display:none;">
                    <i class="fas fa-circle-check"></i> Guardar encuesta
                </button>
            </div>
        </form>

    </div>
</div>

<script>
/* Stepper */
const panes = document.querySelectorAll('.step-pane');
const steps = document.querySelectorAll('.step');
let cur = 0;
function show(i){
    cur = Math.max(0, Math.min(panes.length-1, i));
    panes.forEach((p,idx)=>p.classList.toggle('active', idx===cur));
    steps.forEach((s,idx)=>{
        s.classList.toggle('active', idx===cur);
        s.classList.toggle('done',   idx<cur);
    });
    document.getElementById('step-num').textContent = (cur+1);
    document.getElementById('btn-prev').style.visibility = cur===0 ? 'hidden' : 'visible';
    document.getElementById('btn-next').style.display = cur===panes.length-1 ? 'none' : 'inline-flex';
    document.getElementById('btn-save').style.display = cur===panes.length-1 ? 'inline-flex' : 'none';
    window.scrollTo({top:0,behavior:'smooth'});
}
document.getElementById('btn-prev').onclick = ()=>show(cur-1);
document.getElementById('btn-next').onclick = ()=>show(cur+1);
steps.forEach((s,idx)=>s.addEventListener('click', ()=>show(idx)));

/* YN toggle */
document.querySelectorAll('.yn-group').forEach(g => {
    g.querySelectorAll('.yn-opt').forEach(o => {
        o.addEventListener('click', () => {
            g.querySelectorAll('.yn-opt').forEach(x => x.classList.remove('checked'));
            o.classList.add('checked');
            o.querySelector('input').checked = true;
        });
    });
});

/* Productos repeater */
const prodList = document.getElementById('prod-list');
let prodIdx = 0;
function addProducto() {
    const i = prodIdx++;
    const div = document.createElement('div');
    div.className = 'prod-item';
    div.innerHTML = `
        <button type="button" class="del" title="Quitar">×</button>
        <div class="fld"><label>Nombre del producto</label><input type="text" name="prod[${i}][nombre]" required></div>
        <div class="fld"><label>Precio venta</label><input type="number" step="0.01" name="prod[${i}][precio_venta]"></div>
        <div class="fld"><label>Costo unidad</label><input type="number" step="0.01" name="prod[${i}][costo]"></div>
        <div class="fld"><label>Vendidos / mes</label><input type="number" name="prod[${i}][cantidad]"></div>
        <div class="fld"><label>% Margen</label><input type="number" step="0.01" name="prod[${i}][margen]"></div>
        <div class="fld"><label>Total venta mes</label><input type="number" step="0.01" name="prod[${i}][total_venta_mes]"></div>
        <div class="fld"><label>Inventario</label><input type="number" name="prod[${i}][inventario]"></div>
        <div class="fld"><label>Compra prom. semanal</label><input type="number" step="0.01" name="prod[${i}][compra_sem]"></div>
    `;
    div.querySelector('.del').onclick = () => div.remove();
    prodList.appendChild(div);
}
document.getElementById('btn-add-prod').onclick = addProducto;
addProducto();

/* Geo (opcional, no bloquea) */
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        p => { document.getElementById('lat').value = p.coords.latitude; document.getElementById('lng').value = p.coords.longitude; },
        () => {}, { timeout:4000 }
    );
}
</script>

<?php
/* Helper inline para renderizar bloques YN */
function ynBlock(string $label, string $name, $value = null): string {
    $isYes = (string)$value === '1';
    $isNo  = $value !== null && (string)$value === '0';
    return '
    <div class="fld">
        <label>'.htmlspecialchars($label).'</label>
        <div class="yn-group" data-name="'.$name.'">
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

</body>
</html>

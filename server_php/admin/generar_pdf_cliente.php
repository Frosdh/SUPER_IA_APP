<?php
// ============================================================
// admin/generar_pdf_cliente.php — Super_IA Logan
// Generador de Vista de Impresión (PDF) del Cliente - PREMIUM
// ============================================================
require_once 'db_admin.php'; // PDO

if (isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true) {
    $user_role = 'super_admin';
} elseif (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $user_role = 'admin';
} elseif (isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true) {
    $user_role = 'supervisor';
} elseif (isset($_SESSION['asesor_logged_in']) && $_SESSION['asesor_logged_in'] === true) {
    $user_role = 'asesor';
} else {
    die("No autorizado.");
}

$cliente_id = $_GET['id'] ?? '';
$secciones_raw = $_GET['sec'] ?? 'datos';
$secciones = explode(',', $secciones_raw);

if (!$cliente_id) die("ID de cliente requerido.");

// ── 1. Datos básicos del cliente ─────────────────────────────
$cliente = null;
try {
    $st = $pdo->prepare("
        SELECT cp.*, u.nombre AS asesor_nombre, u.email AS asesor_email
        FROM cliente_prospecto cp
        LEFT JOIN asesor a ON a.id = cp.asesor_id
        LEFT JOIN usuario u ON u.id = a.usuario_id
        WHERE cp.id = ? LIMIT 1
    ");
    $st->execute([$cliente_id]);
    $cliente = $st->fetch();
} catch (PDOException $e) {}

if (!$cliente) die("Cliente no encontrado.");

// ── Helpers ──────────────────────────────────────────────────
function dato($v, string $suffix = '') {
    if ($v === null || trim((string)$v) === '') return '<span class="text-muted">—</span>';
    return htmlspecialchars($v) . ($suffix ? " $suffix" : '');
}
function yn($v, $si = 'Sí', $no = 'No') {
    if ($v === null || $v === '') return '<span class="text-muted">—</span>';
    return (intval($v) === 1 || $v === 'si' || $v === 'true' || $v === 1) ? $si : $no;
}

// ── Cargar Datos ─────────────────────────────────────────────
$encuesta = null;
$encuesta_negocio = null;
$en_tot_v_sem = 0; $en_tot_c_sem = 0; $en_tot_v_mes = 0; $en_tot_c_mes = 0;
$tareas = [];
$alertas_cliente = [];
$fichas = [];
$tramites_credito = [];
$ficha_credito = null; $ficha_corriente = null; $ficha_ahorros = null; $ficha_inversiones = null;

if (in_array('encuesta', $secciones)) {
    try {
        $st = $pdo->prepare("SELECT * FROM encuesta_comercial WHERE cliente_prospecto_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$cliente_id]);
        $encuesta = $st->fetch();
    } catch (PDOException $e) {}
}

if (in_array('empresa', $secciones)) {
    try {
        $st = $pdo->prepare("
            SELECT en.* FROM encuesta_negocio en
            JOIN tarea t ON t.id = en.tarea_id
            WHERE t.cliente_prospecto_id = ?
            ORDER BY en.created_at DESC LIMIT 1
        ");
        $st->execute([$cliente_id]);
        $encuesta_negocio = $st->fetch();
        if ($encuesta_negocio) {
            $en_tot_v_sem = ($encuesta_negocio['venta_lunes'] ?? 0) + ($encuesta_negocio['venta_martes'] ?? 0) + ($encuesta_negocio['venta_miercoles'] ?? 0) + ($encuesta_negocio['venta_jueves'] ?? 0) + ($encuesta_negocio['venta_viernes'] ?? 0) + ($encuesta_negocio['venta_sabado'] ?? 0) + ($encuesta_negocio['venta_domingo'] ?? 0);
            if ($en_tot_v_sem <= 0) $en_tot_v_sem = ($encuesta_negocio['venta_lv'] ?? 0) + ($encuesta_negocio['venta_sabado'] ?? 0) + ($encuesta_negocio['venta_domingo'] ?? 0);
            $en_tot_c_sem = ($encuesta_negocio['compra_lunes'] ?? 0) + ($encuesta_negocio['compra_martes'] ?? 0) + ($encuesta_negocio['compra_miercoles'] ?? 0) + ($encuesta_negocio['compra_jueves'] ?? 0) + ($encuesta_negocio['compra_viernes'] ?? 0) + ($encuesta_negocio['compra_sabado'] ?? 0) + ($encuesta_negocio['compra_domingo'] ?? 0);
            if ($en_tot_c_sem <= 0) $en_tot_c_sem = ($encuesta_negocio['compra_lv'] ?? 0) + ($encuesta_negocio['compra_sabado'] ?? 0) + ($encuesta_negocio['compra_domingo'] ?? 0);
            $en_tot_v_mes = $en_tot_v_sem * 4.33;
            $en_tot_c_mes = $en_tot_c_sem * 4.33;
        }
    } catch (PDOException $e) {}
}

if (in_array('operaciones', $secciones)) {
    try {
        $st = $pdo->prepare("SELECT * FROM ficha_producto WHERE cliente_cedula = ? ORDER BY created_at DESC");
        $st->execute([$cliente['cedula'] ?? '']);
        $fichas = $st->fetchAll();
        
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
            } catch (PDOException $e) {}
        }
        
        $st = $pdo->prepare("SELECT cp.*, u.nombre AS asesor_nombre FROM credito_proceso cp LEFT JOIN asesor a ON a.id = cp.asesor_id LEFT JOIN usuario u ON u.id = a.usuario_id WHERE cp.cliente_prospecto_id = ? ORDER BY cp.created_at DESC");
        $st->execute([$cliente_id]);
        $tramites_credito = $st->fetchAll();
    } catch (PDOException $e) {}
}

if (in_array('historial', $secciones)) {
    try {
        $st = $pdo->prepare("SELECT t.*, u.nombre AS asesor_nombre, av.acuerdo AS av_tipo, av.fecha_acuerdo AS av_fecha FROM tarea t LEFT JOIN asesor a ON a.id = t.asesor_id LEFT JOIN usuario u ON u.id = a.usuario_id LEFT JOIN acuerdo_visita av ON av.tarea_id = t.id WHERE t.cliente_prospecto_id = ? ORDER BY t.fecha_programada DESC");
        $st->execute([$cliente_id]);
        $tareas = $st->fetchAll();
    } catch (PDOException $e) {}
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte_Cliente_<?= htmlspecialchars($cliente['cedula'] ?? $cliente_id) ?></title>
    <!-- Google Fonts para estilo moderno tipo App -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f172a;
            --primary-light: #1e293b;
            --accent: #3b82f6;
            --bg: #f8fafc;
            --surface: #ffffff;
            --text: #334155;
            --text-light: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 30px;
            font-size: 11px;
            line-height: 1.5;
            -webkit-print-color-adjust: exact; /* Importante para Chrome/Safari */
            print-color-adjust: exact;
        }

        /* Contenedor principal estilo "Hoja" / App */
        .page-container {
            max-width: 1000px;
            margin: 0 auto;
            background: var(--surface);
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        /* Header Premium */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid var(--border);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header-logo {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
        }
        .header-logo span {
            color: var(--accent);
        }

        .header-info {
            text-align: right;
        }
        .header-info h1 {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: var(--primary-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header-info p {
            margin: 0;
            color: var(--text-light);
            font-size: 11px;
        }

        /* Tarjetas de Sección (Section Cards) */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 25px;
            overflow: hidden;
            page-break-inside: avoid;
        }

        .section-header {
            background: #f1f5f9;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section-body {
            padding: 20px;
        }

        /* Subtítulos dentro de las tarjetas */
        .subsection-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--primary-light);
            margin: 20px 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
        }
        .section-body > .subsection-title:first-child {
            margin-top: 0;
        }

        /* Grillas */
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }

        /* Campos de datos */
        .field {
            display: flex;
            flex-direction: column;
        }
        .field-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .field-value {
            font-size: 12px;
            font-weight: 500;
            color: var(--primary);
        }

        /* Listas */
        .list-items {
            margin: 0;
            padding-left: 15px;
            list-style-type: disc;
            color: var(--primary);
            font-weight: 500;
        }
        .list-items li { margin-bottom: 4px; }

        /* Tablas Premium */
        .table-premium {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 10px;
            margin-bottom: 15px;
        }
        .table-premium th, .table-premium td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .table-premium th {
            background-color: #f8fafc;
            color: var(--text-light);
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-premium td {
            font-size: 11px;
            font-weight: 500;
            color: var(--primary-light);
        }
        .table-premium tr:last-child th,
        .table-premium tr:last-child td {
            border-bottom: none;
        }
        .table-premium tbody tr:nth-child(even) {
            background-color: #f8fafc; /* zebra striping suave */
        }

        /* Badges / Chips */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-neutral { background: #f1f5f9; color: #475569; }

        .text-muted { color: #94a3b8 !important; font-weight: normal; }

        /* Contenedores de resumen financiero */
        .finance-summary {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 15px;
        }

        /* Control de Impresión */
        .no-print {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
        }
        .btn-print {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-print:hover { background: #2563eb; }

        @media print {
            body { 
                padding: 0; 
                background: white; 
                font-size: 10.5px;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            .no-print { display: none !important; }
            @page { margin: 1cm; size: A4 portrait; }
            
            /* Asegurar que los colores de fondo se impriman (chips, headers) */
            .section-header, .table-premium th, .badge, .finance-summary {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <div>
            <strong>Vista de Impresión Premium:</strong> Genera un documento corporativo detallado y estructurado.
        </div>
        <button onclick="window.print()" class="btn-print">Imprimir / Guardar PDF</button>
    </div>

    <div class="page-container">
        
        <div class="header">
            <div class="header-logo">
                SUPER<span>_IA</span>
            </div>
            <div class="header-info">
                <h1>Expediente Detallado de Cliente</h1>
                <p>Generado el <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($user_role) ?></p>
            </div>
        </div>

        <!-- 1. DATOS PERSONALES -->
        <div class="section-card">
            <div class="section-header">
                <h2 class="section-title">1. Datos Personales y Contacto</h2>
            </div>
            <div class="section-body grid-4">
                <div class="field" style="grid-column: span 2;">
                    <span class="field-label">Nombre Completo</span>
                    <span class="field-value"><?= dato($cliente['nombre']) ?></span>
                </div>
                <div class="field">
                    <span class="field-label">Cédula / RUC</span>
                    <span class="field-value"><?= dato($cliente['cedula']) ?></span>
                </div>
                <div class="field">
                    <span class="field-label">Estado</span>
                    <span class="field-value badge badge-info"><?= dato(ucfirst($cliente['estado'])) ?></span>
                </div>
                
                <div class="field"><span class="field-label">Género</span><span class="field-value"><?= dato($cliente['genero'] ?? '') ?></span></div>
                <div class="field"><span class="field-label">Estado Civil</span><span class="field-value"><?= dato($cliente['estado_civil'] ?? '') ?></span></div>
                <div class="field"><span class="field-label">Educación</span><span class="field-value"><?= dato($cliente['nivel_educacion'] ?? '') ?></span></div>
                <div class="field"><span class="field-label">Dependientes</span><span class="field-value"><?= dato($cliente['num_dependientes'] ?? '') ?></span></div>
                
                <div class="field"><span class="field-label">Celular</span><span class="field-value"><?= dato($cliente['celular'] ?? $cliente['telefono2']) ?></span></div>
                <div class="field"><span class="field-label">Teléfono Fijo</span><span class="field-value"><?= dato($cliente['telefono']) ?></span></div>
                <div class="field" style="grid-column: span 2;"><span class="field-label">Email</span><span class="field-value"><?= dato($cliente['email']) ?></span></div>
                
                <div class="field" style="grid-column: span 2;"><span class="field-label">Dirección / Vivienda</span><span class="field-value"><?= dato($cliente['direccion']) ?> (<?= dato($cliente['tipo_vivienda'] ?? '') ?>)</span></div>
                <div class="field"><span class="field-label">Zona</span><span class="field-value"><?= dato($cliente['zona']) ?></span></div>
                <div class="field"><span class="field-label">Ciudad</span><span class="field-value"><?= dato($cliente['ciudad']) ?></span></div>
                
                <div class="field" style="grid-column: span 2;"><span class="field-label">Actividad Económica</span><span class="field-value"><?= dato($cliente['actividad']) ?></span></div>
                <div class="field" style="grid-column: span 2;"><span class="field-label">Nombre Empresa</span><span class="field-value"><?= dato($cliente['nombre_empresa']) ?></span></div>
                
                <div class="field"><span class="field-label">Tiene RUC</span><span class="field-value"><?= yn($cliente['tiene_ruc']) ?></span></div>
                <div class="field"><span class="field-label">Tiene RISE</span><span class="field-value"><?= yn($cliente['tiene_rise']) ?></span></div>
                <div class="field"><span class="field-label">Asesor Asignado</span><span class="field-value"><?= dato($cliente['asesor_nombre']) ?></span></div>
                <div class="field"><span class="field-label">Fecha Registro</span><span class="field-value"><?= date('d/m/Y', strtotime($cliente['created_at'])) ?></span></div>
            </div>
        </div>

        <!-- 2. ENCUESTA COMERCIAL -->
        <?php if ($encuesta && in_array('encuesta', $secciones)): ?>
        <div class="section-card">
            <div class="section-header">
                <h2 class="section-title">2. Encuesta Comercial y Relación Institucional</h2>
            </div>
            <div class="section-body">
                
                <h3 class="subsection-title">Productos en Otras Instituciones</h3>
                <div class="grid-3 mb-3">
                    <div class="field"><span class="field-label">Cuenta de Ahorros</span><span class="field-value"><?= yn($encuesta['mantiene_cuenta_ahorro']) ?></span></div>
                    <div class="field"><span class="field-label">Cuenta Corriente</span><span class="field-value"><?= yn($encuesta['mantiene_cuenta_corriente']) ?></span></div>
                    <div class="field"><span class="field-label">Inversiones</span><span class="field-value"><?= yn($encuesta['tiene_inversiones']) ?> <?= $encuesta['tiene_inversiones'] ? "({$encuesta['institucion_inversiones']} - $ {$encuesta['valor_inversion']})" : '' ?></span></div>
                    <div class="field" style="grid-column: span 3;"><span class="field-label">Crédito Activo</span><span class="field-value"><?= yn($encuesta['tiene_operaciones_crediticias']) ?> <?= $encuesta['tiene_operaciones_crediticias'] ? "({$encuesta['institucion_credito']} / {$encuesta['institucion_producto_financiero']})" : '' ?></span></div>
                </div>
                
                <h3 class="subsection-title">Interés en Nuestros Productos</h3>
                <div class="grid-4 mb-3">
                    <div class="field"><span class="field-label">Cuenta Corriente</span><span class="field-value"><?= yn($encuesta['interes_cc']) ?></span></div>
                    <div class="field"><span class="field-label">Cuenta de Ahorros</span><span class="field-value"><?= yn($encuesta['interes_ahorro']) ?></span></div>
                    <div class="field"><span class="field-label">Inversiones</span><span class="field-value"><?= yn($encuesta['interes_inversion']) ?></span></div>
                    <div class="field"><span class="field-label">Créditos</span><span class="field-value"><?= yn($encuesta['interes_credito']) ?></span></div>
                    <div class="field" style="grid-column: span 4;"><span class="field-label">Nivel de Interés Global</span><span class="field-value badge badge-success"><?= dato(ucfirst($encuesta['nivel_interes'])) ?></span></div>
                </div>

                <div class="grid-2 mt-4" style="margin-top: 15px;">
                    <div class="finance-summary">
                        <span class="field-label">¿Qué busca en un producto financiero?</span>
                        <ul class="list-items" style="margin-top:5px;">
                            <?php if ($encuesta['busca_agilidad']) echo "<li>Agilidad ({$encuesta['que_busca_agilidad']})</li>"; ?>
                            <?php if ($encuesta['busca_cajeros']) echo "<li>Cajeros ({$encuesta['que_busca_cajeros']})</li>"; ?>
                            <?php if ($encuesta['busca_banca']) echo "<li>Banca en línea ({$encuesta['que_busca_banca_linea']})</li>"; ?>
                            <?php if ($encuesta['busca_agencias']) echo "<li>Agencias ({$encuesta['que_busca_agencias']})</li>"; ?>
                            <?php if ($encuesta['busca_credito']) echo "<li>Crédito rápido ({$encuesta['que_busca_credito_rapido']})</li>"; ?>
                            <?php if ($encuesta['busca_td']) echo "<li>Tarjeta de Débito</li>"; ?>
                            <?php if ($encuesta['busca_tc']) echo "<li>Tarjeta de Crédito</li>"; ?>
                            <?php if (!$encuesta['busca_agilidad'] && !$encuesta['busca_cajeros'] && !$encuesta['busca_banca'] && !$encuesta['busca_agencias'] && !$encuesta['busca_credito']) echo "<li><span class='text-muted'>No especificado</span></li>"; ?>
                        </ul>
                    </div>
                    <div class="finance-summary">
                        <span class="field-label">Razones de NO interés</span>
                        <ul class="list-items" style="margin-top:5px;">
                            <?php if ($encuesta['razon_ya_trabaja']) echo "<li>Ya trabaja con otra institución</li>"; ?>
                            <?php if ($encuesta['razon_desconfia']) echo "<li>Desconfía</li>"; ?>
                            <?php if ($encuesta['razon_agusto']) echo "<li>Está a gusto con banco actual</li>"; ?>
                            <?php if ($encuesta['razon_mala_exp']) echo "<li>Mala experiencia previa</li>"; ?>
                            <?php if ($encuesta['razon_otros']) echo "<li>Otro: {$encuesta['razon_otros']}</li>"; ?>
                            <?php if (!$encuesta['razon_ya_trabaja'] && !$encuesta['razon_desconfia'] && !$encuesta['razon_agusto'] && !$encuesta['razon_mala_exp'] && !$encuesta['razon_otros']) echo "<li><span class='text-muted'>No especificado</span></li>"; ?>
                        </ul>
                    </div>
                </div>
                
                <?php $inst = $encuesta_negocio ?: $encuesta; ?>
                <h3 class="subsection-title">Información Institucional Actual</h3>
                <div class="grid-3">
                    <div class="field"><span class="field-label">Banco Ahorro Actual</span><span class="field-value"><?= dato($encuesta['banco_ahorro'] ?? '') ?></span></div>
                    <div class="field"><span class="field-label">Banco Corriente Actual</span><span class="field-value"><?= dato($encuesta['banco_corriente'] ?? '') ?></span></div>
                    <div class="field"><span class="field-label">Conoce la institución</span><span class="field-value"><?= yn($inst['p1_conoce_institucion'] ?? '') ?></span></div>
                    <div class="field"><span class="field-label">Es cliente actualmente</span><span class="field-value"><?= yn($inst['p2_es_cliente'] ?? '') ?></span></div>
                    <div class="field"><span class="field-label">Producto que posee</span><span class="field-value"><?= dato($inst['p2_producto'] ?? '') ?></span></div>
                    <div class="field"><span class="field-label">Satisfacción General</span><span class="field-value"><?= dato($inst['p3_satisfaccion'] ?? '') ?></span></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 3. LEVANTAMIENTO DE EMPRESA -->
        <?php if ($encuesta_negocio && in_array('empresa', $secciones)): ?>
        <div class="section-card">
            <div class="section-header">
                <h2 class="section-title">3. Levantamiento de Empresa y Situación Económica</h2>
            </div>
            <div class="section-body">
                
                <h3 class="subsection-title">Flujo de Ventas y Compras</h3>
                <table class="table-premium">
                    <thead>
                        <tr>
                            <th>Concepto</th><th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th><th>SEMANAL</th><th>MENSUAL EST.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Ventas</strong></td>
                            <td>$<?= number_format($encuesta_negocio['venta_lunes'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['venta_martes'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['venta_miercoles'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['venta_jueves'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['venta_viernes'] ?? (($encuesta_negocio['venta_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['venta_sabado'] ?? 0, 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['venta_domingo'] ?? 0, 2) ?></td>
                            <td style="background:#f1f5f9; color:#0f172a;"><strong>$<?= number_format($en_tot_v_sem, 2) ?></strong></td>
                            <td style="background:#d1fae5; color:#065f46;"><strong>$<?= number_format($en_tot_v_mes, 2) ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>Compras</strong></td>
                            <td>$<?= number_format($encuesta_negocio['compra_lunes'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['compra_martes'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['compra_miercoles'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['compra_jueves'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['compra_viernes'] ?? (($encuesta_negocio['compra_lv'] ?? 0)/5), 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['compra_sabado'] ?? 0, 2) ?></td>
                            <td>$<?= number_format($encuesta_negocio['compra_domingo'] ?? 0, 2) ?></td>
                            <td style="background:#f1f5f9; color:#0f172a;"><strong>$<?= number_format($en_tot_c_sem, 2) ?></strong></td>
                            <td style="background:#fee2e2; color:#991b1b;"><strong>$<?= number_format($en_tot_c_mes, 2) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="grid-4" style="margin-top: 15px;">
                    <div class="field"><span class="field-label">Mes Alta Venta</span><span class="field-value"><?= dato($encuesta_negocio['mes_alta_venta']) ?></span></div>
                    <div class="field"><span class="field-label">Mes Baja Venta</span><span class="field-value"><?= dato($encuesta_negocio['mes_baja_venta']) ?></span></div>
                    <div class="field"><span class="field-label">Mes Alta Compra</span><span class="field-value"><?= dato($encuesta_negocio['mes_alta_compra']) ?></span></div>
                    <div class="field"><span class="field-label">Días Atención</span><span class="field-value badge badge-neutral">
                        <?= ($encuesta_negocio['dia_lunes']??$encuesta_negocio['dia_lv']??0)?'L ':'' ?>
                        <?= ($encuesta_negocio['dia_martes']??$encuesta_negocio['dia_lv']??0)?'M ':'' ?>
                        <?= ($encuesta_negocio['dia_miercoles']??$encuesta_negocio['dia_lv']??0)?'X ':'' ?>
                        <?= ($encuesta_negocio['dia_jueves']??$encuesta_negocio['dia_lv']??0)?'J ':'' ?>
                        <?= ($encuesta_negocio['dia_viernes']??$encuesta_negocio['dia_lv']??0)?'V ':'' ?>
                        <?= ($encuesta_negocio['dia_sab']??0)?'S ':'' ?>
                        <?= ($encuesta_negocio['dia_dom']??0)?'D ':'' ?>
                    </span></div>
                </div>

                <div class="grid-3" style="margin-top: 15px;">
                    <div class="field"><span class="field-label">Ventas al Contado</span><span class="field-value badge badge-success"><?= dato($encuesta_negocio['pct_contado'] ?? '0') ?>%</span></div>
                    <div class="field"><span class="field-label">Ventas al Crédito</span><span class="field-value badge badge-warning"><?= dato($encuesta_negocio['pct_credito'] ?? '0') ?>%</span></div>
                    <div class="field"><span class="field-label">Uso Efectivo / Recup. Cartera</span><span class="field-value"><?= dato($encuesta_negocio['pct_efectivo'] ?? '0') ?>% | $<?= dato($encuesta_negocio['recuperacion_credito'] ?? '0') ?></span></div>
                </div>

                <h3 class="subsection-title" style="margin-top:25px;">Estructura de Gastos e Ingresos</h3>
                <div class="grid-3">
                    <div class="finance-summary">
                        <span class="field-label mb-2" style="font-size:11px;">Gastos del Negocio</span>
                        <div class="grid-2">
                            <div class="field"><span class="field-label">Costos Venta</span><span class="field-value">$<?= dato($encuesta_negocio['costos_ventas']) ?></span></div>
                            <div class="field"><span class="field-label">Sueldos</span><span class="field-value">$<?= dato($encuesta_negocio['g_neg_sueldos']) ?></span></div>
                            <div class="field"><span class="field-label">Arriendo</span><span class="field-value">$<?= dato($encuesta_negocio['g_neg_arriendo']) ?></span></div>
                            <div class="field"><span class="field-label">Serv. Bás.</span><span class="field-value">$<?= dato($encuesta_negocio['g_neg_serv_bas']) ?></span></div>
                            <div class="field"><span class="field-label">Transporte</span><span class="field-value">$<?= dato($encuesta_negocio['g_neg_transporte']) ?></span></div>
                            <div class="field"><span class="field-label">Mantenimiento</span><span class="field-value">$<?= dato($encuesta_negocio['g_neg_mantenimiento']) ?></span></div>
                            <div class="field" style="grid-column: span 2; margin-top:10px; border-top:1px solid #cbd5e1; padding-top:5px;">
                                <span class="field-label">Total G. Negocio</span><span class="field-value" style="font-size:14px; color:var(--danger);"><strong>$<?= dato($encuesta_negocio['gastos_negocio']) ?></strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="finance-summary">
                        <span class="field-label mb-2" style="font-size:11px;">Gastos Familiares</span>
                        <div class="grid-2">
                            <div class="field"><span class="field-label">Alimentación</span><span class="field-value">$<?= dato($encuesta_negocio['g_fam_alim']) ?></span></div>
                            <div class="field"><span class="field-label">Arriendo Casa</span><span class="field-value">$<?= dato($encuesta_negocio['g_fam_arriendo']) ?></span></div>
                            <div class="field"><span class="field-label">Servicios Bás.</span><span class="field-value">$<?= dato($encuesta_negocio['g_fam_serv_bas']) ?></span></div>
                            <div class="field"><span class="field-label">Educación</span><span class="field-value">$<?= dato($encuesta_negocio['g_fam_educacion']) ?></span></div>
                            <div class="field"><span class="field-label">Salud</span><span class="field-value">$<?= dato($encuesta_negocio['g_fam_salud']) ?></span></div>
                            <div class="field"><span class="field-label">Otros Gastos</span><span class="field-value">$<?= dato($encuesta_negocio['g_fam_otros']) ?></span></div>
                            <div class="field" style="grid-column: span 2; margin-top:10px; border-top:1px solid #cbd5e1; padding-top:5px;">
                                <span class="field-label">Total G. Fam.</span><span class="field-value" style="font-size:14px; color:var(--danger);"><strong>$<?= dato($encuesta_negocio['gastos_familiares']) ?></strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="finance-summary">
                        <span class="field-label mb-2" style="font-size:11px;">Otros Ingresos</span>
                        <div class="field" style="margin-bottom:8px;"><span class="field-label">Ingresos Cónyuge</span><span class="field-value">$<?= dato($encuesta_negocio['o_ing_conyuge']) ?></span></div>
                        <div class="field" style="margin-bottom:8px;"><span class="field-label">Arriendos / Pensiones</span><span class="field-value">$<?= dato($encuesta_negocio['o_ing_arriendos']) ?> / $<?= dato($encuesta_negocio['o_ing_pensiones']) ?></span></div>
                        <div class="field" style="margin-bottom:8px;"><span class="field-label">Otros</span><span class="field-value">$<?= dato($encuesta_negocio['o_ing_otros']) ?></span></div>
                        <div class="field" style="margin-top:10px; border-top:1px solid #cbd5e1; padding-top:5px;">
                            <span class="field-label">Total O. Ingresos</span><span class="field-value" style="font-size:14px; color:var(--success);"><strong>$<?= dato($encuesta_negocio['otros_ingresos']) ?></strong></span>
                        </div>
                    </div>
                </div>
                
                <h3 class="subsection-title">Saldos y Situación Financiera Actual</h3>
                <div class="grid-4 finance-summary">
                    <div class="field"><span class="field-label">Caja Efectivo</span><span class="field-value badge badge-success">$<?= dato($encuesta_negocio['caja_efectivo']) ?></span></div>
                    <div class="field"><span class="field-label">Saldo Bancos</span><span class="field-value badge badge-success">$<?= dato($encuesta_negocio['bancos_saldo']) ?></span></div>
                    <div class="field"><span class="field-label">Cuentas x Cobrar</span><span class="field-value badge badge-success">$<?= dato($encuesta_negocio['cxp_netas']) ?></span></div>
                    <div class="field"><span class="field-label">Inv. Materia Prima</span><span class="field-value">$<?= dato($encuesta_negocio['inv_mat_prima']) ?></span></div>
                    <div class="field"><span class="field-label">Inv. Prod. Proceso</span><span class="field-value">$<?= dato($encuesta_negocio['inv_prod_proc']) ?></span></div>
                    <div class="field"><span class="field-label">Créditos x Pagar</span><span class="field-value badge badge-danger">$<?= dato($encuesta_negocio['creditos_pagar']) ?></span></div>
                    <div class="field"><span class="field-label">Proveedores</span><span class="field-value badge badge-warning">$<?= dato($encuesta_negocio['proveedores']) ?></span></div>
                    <div class="field"><span class="field-label">Pasivos LP</span><span class="field-value badge badge-danger">$<?= dato($encuesta_negocio['pasivos_lp']) ?></span></div>
                </div>

                <?php 
                $veh_neg = json_decode($encuesta_negocio['vehiculos_negocio_json'] ?? '[]', true);
                $veh_hog = json_decode($encuesta_negocio['vehiculos_hogar_json'] ?? '[]', true);
                $all_veh = array_merge(
                    array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($veh_neg)?$veh_neg:[]),
                    array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($veh_hog)?$veh_hog:[])
                );
                if (!empty($all_veh)):
                ?>
                <h3 class="subsection-title">Vehículos</h3>
                <table class="table-premium">
                    <thead><tr><th>Tipo</th><th>Descripción</th><th>Marca/Modelo</th><th>Año</th><th>Valor Estimado</th></tr></thead>
                    <tbody>
                        <?php foreach ($all_veh as $v): if(empty($v['descripcion']) && empty($v['marca'])) continue; ?>
                        <tr>
                            <td><span class="badge <?= $v['tipo']=='Negocio'?'badge-info':'badge-neutral' ?>"><?= $v['tipo'] ?></span></td>
                            <td><?= htmlspecialchars($v['descripcion'] ?? '') ?></td>
                            <td><?= htmlspecialchars($v['marca'] ?? '') ?> <?= htmlspecialchars($v['modelo'] ?? '') ?></td>
                            <td><?= htmlspecialchars($v['anio'] ?? '') ?></td>
                            <td><strong>$<?= number_format((float)($v['valor'] ?? 0), 2) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php 
                $inm_neg = json_decode($encuesta_negocio['inmuebles_negocio_json'] ?? '[]', true);
                $inm_hog = json_decode($encuesta_negocio['inmuebles_hogar_json'] ?? '[]', true);
                $all_inm = array_merge(
                    array_map(fn($v) => array_merge($v, ['tipo' => 'Negocio']), is_array($inm_neg)?$inm_neg:[]),
                    array_map(fn($v) => array_merge($v, ['tipo' => 'Hogar']), is_array($inm_hog)?$inm_hog:[])
                );
                if (!empty($all_inm)):
                ?>
                <h3 class="subsection-title">Inmuebles / Propiedades</h3>
                <table class="table-premium">
                    <thead><tr><th>Tipo</th><th>Descripción</th><th>Ubicación</th><th>Área</th><th>Valor Estimado</th></tr></thead>
                    <tbody>
                        <?php foreach ($all_inm as $i): if(empty($i['descripcion'])) continue; ?>
                        <tr>
                            <td><span class="badge <?= $i['tipo']=='Negocio'?'badge-info':'badge-neutral' ?>"><?= $i['tipo'] ?></span></td>
                            <td><?= htmlspecialchars($i['descripcion'] ?? '') ?></td>
                            <td><?= htmlspecialchars($i['ubicacion'] ?? '') ?></td>
                            <td><?= htmlspecialchars($i['area'] ?? '') ?></td>
                            <td><strong>$<?= number_format((float)($i['valor'] ?? 0), 2) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php 
                $com_prods = json_decode($encuesta_negocio['comercio_productos_json'] ?? '[]', true);
                if (!empty($com_prods)):
                ?>
                <h3 class="subsection-title">Inventario de Productos (Comercio)</h3>
                <table class="table-premium">
                    <thead><tr><th>Producto</th><th>Costo Unit.</th><th>P. Venta</th><th>Cant. Mes</th><th>Venta Mes</th><th>Margen</th><th>Existencias</th></tr></thead>
                    <tbody>
                        <?php foreach ($com_prods as $cp): if (empty($cp['nombre'])) continue; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($cp['nombre']) ?></strong></td>
                            <td>$<?= number_format((float)($cp['costo_unitario'] ?? 0), 2) ?></td>
                            <td>$<?= number_format((float)($cp['precio_venta_unitario'] ?? $cp['precio_venta_unidad'] ?? 0), 2) ?></td>
                            <td><?= (float)($cp['cantidad_vendida_mes'] ?? 0) ?></td>
                            <td style="color:var(--success);"><strong>$<?= number_format((float)($cp['venta_mes'] ?? 0), 2) ?></strong></td>
                            <td><span class="badge badge-info"><?= (float)($cp['margen_utilidad'] ?? 0) ?>%</span></td>
                            <td><?= (float)($cp['unidades_existentes'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <?php 
                $prods = json_decode($encuesta_negocio['productos_json'] ?? '[]', true);
                if (!empty($prods)):
                ?>
                <h3 class="subsection-title">Estructura de Producción / Servicios</h3>
                <?php foreach ($prods as $p): if (empty($p['nombre'])) continue; ?>
                <div class="finance-summary mb-3">
                    <h4 style="margin:0 0 10px 0; color:var(--primary); font-size:13px; text-transform:uppercase;">Producto: <?= htmlspecialchars($p['nombre']) ?></h4>
                    <div class="grid-4">
                        <div class="field"><span class="field-label">Total Mat. Prima</span><span class="field-value">$<?= number_format((float)($p['total_materia_prima'] ?? 0), 2) ?></span></div>
                        <div class="field"><span class="field-label">Mano de Obra / Otros</span><span class="field-value">$<?= number_format((float)($p['mano_obra']??0) + (float)($p['empaques']??0) + (float)($p['otros_costos']??0), 2) ?></span></div>
                        <div class="field"><span class="field-label">Costo Unitario</span><span class="field-value badge badge-warning">$<?= number_format((float)($p['costo_unitario'] ?? 0), 2) ?></span></div>
                        <div class="field"><span class="field-label">Precio Unitario</span><span class="field-value badge badge-success">$<?= number_format((float)($p['precio_unitario']??0), 2) ?></span></div>
                        
                        <div class="field"><span class="field-label">Ventas al Mes</span><span class="field-value" style="color:var(--success);"><strong>$<?= number_format((float)($p['ventas_mensuales']??0), 2) ?></strong></span></div>
                        <div class="field"><span class="field-label">Costo Ventas</span><span class="field-value" style="color:var(--danger);">$<?= number_format((float)($p['costo_ventas']??0), 2) ?></span></div>
                        <div class="field"><span class="field-label">Inventario</span><span class="field-value">$<?= number_format((float)($p['inventarios']??0), 2) ?></span></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- 4. OPERACIONES Y FICHAS -->
        <?php if (in_array('operaciones', $secciones) && (!empty($fichas) || !empty($tramites_credito))): ?>
        <div class="section-card">
            <div class="section-header">
                <h2 class="section-title">4. Fichas de Operaciones y Solicitudes</h2>
            </div>
            <div class="section-body">
                
                <?php if ($ficha_credito): ?>
                <div class="finance-summary mb-3" style="border-left: 4px solid var(--warning);">
                    <h3 class="subsection-title" style="margin-top:0; border:none; padding:0;">FICHA DE CRÉDITO <span class="text-muted" style="font-size:10px; font-weight:normal; float:right;"><?= date('d/m/Y H:i', strtotime($ficha_credito['created_at'])) ?></span></h3>
                    <div class="grid-4 mt-2">
                        <div class="field"><span class="field-label">Monto Solicitado</span><span class="field-value badge badge-success" style="font-size:13px;">$<?= dato($ficha_credito['monto_credito']) ?></span></div>
                        <div class="field"><span class="field-label">Plazo</span><span class="field-value"><?= dato($ficha_credito['plazo_credito_meses']) ?> meses</span></div>
                        <div class="field" style="grid-column: span 2;"><span class="field-label">Solicitante</span><span class="field-value"><?= dato($ficha_credito['solicitante_nombre']) ?> (<?= dato($ficha_credito['solicitante_cedula']) ?>)</span></div>
                        <div class="field" style="grid-column: span 4;"><span class="field-label">Garante</span><span class="field-value"><?= dato($ficha_credito['garante_nombre']) ?> (<?= dato($ficha_credito['garante_cedula']) ?>)</span></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($ficha_corriente): ?>
                <div class="finance-summary mb-3" style="border-left: 4px solid #0d9488;">
                    <h3 class="subsection-title" style="margin-top:0; border:none; padding:0;">FICHA DE CUENTA CORRIENTE <span class="text-muted" style="font-size:10px; font-weight:normal; float:right;"><?= date('d/m/Y H:i', strtotime($ficha_corriente['created_at'])) ?></span></h3>
                    <div class="grid-4 mt-2">
                        <div class="field"><span class="field-label">Tipo / Uso</span><span class="field-value"><?= dato($ficha_corriente['tipo_cc']) ?> / <?= dato($ficha_corriente['frecuencia_uso']) ?></span></div>
                        <div class="field"><span class="field-label">Depósito Prom.</span><span class="field-value badge badge-success">$<?= dato($ficha_corriente['monto_deposito_prom']) ?></span></div>
                        <div class="field"><span class="field-label">Cheques/mes</span><span class="field-value"><?= yn($ficha_corriente['maneja_cheques']) ?> (<?= dato($ficha_corriente['num_cheques_mes']) ?>)</span></div>
                        <div class="field"><span class="field-label">Origen Fondos</span><span class="field-value"><?= dato($ficha_corriente['origen_fondos']) ?></span></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($ficha_ahorros): ?>
                <div class="finance-summary mb-3" style="border-left: 4px solid var(--success);">
                    <h3 class="subsection-title" style="margin-top:0; border:none; padding:0;">FICHA DE AHORROS <span class="text-muted" style="font-size:10px; font-weight:normal; float:right;"><?= date('d/m/Y H:i', strtotime($ficha_ahorros['created_at'])) ?></span></h3>
                    <div class="grid-4 mt-2">
                        <div class="field"><span class="field-label">Tipo</span><span class="field-value"><?= dato($ficha_ahorros['tipo_ahorro']) ?></span></div>
                        <div class="field"><span class="field-label">Ahorro Mensual</span><span class="field-value badge badge-success">$<?= dato($ficha_ahorros['monto_ahorro_mensual']) ?></span></div>
                        <div class="field"><span class="field-label">Meta / Plazo</span><span class="field-value"><?= dato($ficha_ahorros['meta_ahorro']) ?> / <?= dato($ficha_ahorros['plazo_meta']) ?></span></div>
                        <div class="field"><span class="field-label">Origen Fondos</span><span class="field-value"><?= dato($ficha_ahorros['origen_fondos']) ?></span></div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($ficha_inversiones): ?>
                <div class="finance-summary mb-3" style="border-left: 4px solid #7c3aed;">
                    <h3 class="subsection-title" style="margin-top:0; border:none; padding:0;">FICHA DE INVERSIONES <span class="text-muted" style="font-size:10px; font-weight:normal; float:right;"><?= date('d/m/Y H:i', strtotime($ficha_inversiones['created_at'])) ?></span></h3>
                    <div class="grid-4 mt-2">
                        <div class="field"><span class="field-label">Tipo Inversión</span><span class="field-value"><?= dato($ficha_inversiones['tipo_inversion']) ?></span></div>
                        <div class="field"><span class="field-label">Monto</span><span class="field-value badge badge-success" style="font-size:13px;">$<?= dato($ficha_inversiones['monto_inversion']) ?></span></div>
                        <div class="field"><span class="field-label">Plazo / Tasa</span><span class="field-value"><?= dato($ficha_inversiones['plazo_inversion']) ?> / <span class="badge badge-info"><?= dato($ficha_inversiones['tasa_referencia']) ?>%</span></span></div>
                        <div class="field"><span class="field-label">Perfil Riesgo</span><span class="field-value"><?= dato($ficha_inversiones['perfil_riesgo']) ?></span></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($tramites_credito)): ?>
                <h3 class="subsection-title mt-4">Trámites Formales de Crédito en Proceso</h3>
                <table class="table-premium">
                    <thead>
                        <tr>
                            <th>Estado</th><th>Monto Sol.</th><th>Monto Aprob.</th><th>Destino</th><th>Microcrédito</th><th>Asesor</th><th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tramites_credito as $tc): ?>
                        <tr>
                            <td><span class="badge badge-info"><?= htmlspecialchars(strtoupper($tc['estado_credito'] ?? $tc['estado'] ?? '')) ?></span></td>
                            <td>$<?= number_format($tc['monto_solicitado'] ?? 0, 2) ?></td>
                            <td><strong style="color:var(--success);">$<?= number_format($tc['monto_aprobado'] ?? 0, 2) ?></strong></td>
                            <td><?= htmlspecialchars($tc['destino_credito'] ?? $tc['actividad'] ?? '') ?></td>
                            <td><?= yn($tc['es_microcredito']) ?></td>
                            <td><?= htmlspecialchars($tc['asesor_nombre'] ?? '') ?></td>
                            <td><?= date('d/m/Y', strtotime($tc['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 5. HISTORIAL TAREAS -->
        <?php if (in_array('historial', $secciones) && !empty($tareas)): ?>
        <div class="section-card">
            <div class="section-header">
                <h2 class="section-title">5. Agenda y Registro de Visitas / Tareas</h2>
            </div>
            <div class="section-body" style="padding:0;">
                <table class="table-premium" style="border:none; margin:0; border-radius:0;">
                    <thead>
                        <tr>
                            <th style="border-top:none;">Fecha / Hora</th>
                            <th style="border-top:none;">Tipo Tarea</th>
                            <th style="border-top:none;">Estado</th>
                            <th style="border-top:none;">Acuerdo Logrado</th>
                            <th style="border-top:none;">Observaciones Completas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tareas as $t): 
                            $estado = strtolower($t['estado'] ?? '');
                            $bg = 'badge-neutral';
                            if ($estado == 'completada') $bg = 'badge-success';
                            if ($estado == 'cancelada') $bg = 'badge-danger';
                            if ($estado == 'pendiente') $bg = 'badge-warning';
                        ?>
                        <tr>
                            <td style="white-space:nowrap;">
                                <strong><?= date('d/m/Y', strtotime($t['fecha_programada'] ?? $t['created_at'])) ?></strong><br>
                                <span class="text-muted"><?= $t['hora_programada'] ?? '' ?></span>
                            </td>
                            <td><strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $t['tipo_tarea']))) ?></strong></td>
                            <td><span class="badge <?= $bg ?>"><?= htmlspecialchars(ucfirst($t['estado'])) ?></span></td>
                            <td>
                                <?php if($t['av_tipo']): ?>
                                    <span class="badge badge-info"><i class="fas fa-handshake"></i> <?= htmlspecialchars($t['av_tipo']) ?></span>
                                <?php else: ?>
                                    <?= dato($t['acuerdo_logrado']) ?>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:10.5px; line-height:1.4; color:var(--text);">
                                <?= htmlspecialchars($t['observaciones'] ?? '—') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div> <!-- page-container -->

    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 800);
        };
    </script>
</body>
</html>

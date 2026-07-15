<?php
// ============================================================
// generar_solicitud_credito_excel.php
// Genera un Excel (SpreadsheetML) que replica el formato físico
// "SOLICITUD DE CRÉDITO" (tipo Yantzaza Coop), llenado con los
// datos reales ya capturados del cliente/prospecto. Los campos
// que la app no captura hoy se dejan en blanco.
//
// Uso (mobile): GET/POST generar_solicitud_credito_excel.php?cliente_id=XXXX
// Responde el archivo binario .xls listo para descargar/compartir.
// ============================================================

require_once __DIR__ . '/db_config.php';

$cliente_id = trim($_REQUEST['cliente_id'] ?? '');
if ($cliente_id === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'cliente_id requerido']);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────
function xesc($v): string {
    if ($v === null) return '';
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}
function n2($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
}
function money($v): string {
    return number_format(n2($v), 2, '.', '');
}

try {
    // ── 1. Cliente / Prospecto ────────────────────────────────
    $st = $conn->prepare("SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1");
    $st->bind_param('s', $cliente_id);
    $st->execute();
    $cliente = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$cliente) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
        exit;
    }
    $cedula = (string)($cliente['cedula'] ?? '');

    // ── 2. Ficha de Crédito (solicitud formal más reciente) ───
    $ficha_credito = null;
    if ($cedula !== '') {
        $st = $conn->prepare("
            SELECT fc.*
            FROM ficha_credito fc
            INNER JOIN ficha_producto fp ON fp.id = fc.ficha_id
            WHERE fp.cliente_cedula = ? AND fp.producto_tipo = 'credito'
            ORDER BY fp.created_at DESC LIMIT 1
        ");
        $st->bind_param('s', $cedula);
        $st->execute();
        $ficha_credito = $st->get_result()->fetch_assoc();
        $st->close();
    }

    // ── 3. Encuesta de Negocio (levantamiento de empresa) ─────
    $encuesta_negocio = null;
    $st = $conn->prepare("
        SELECT en.*
        FROM encuesta_negocio en
        INNER JOIN tarea t ON t.id = en.tarea_id
        WHERE t.cliente_prospecto_id = ?
        ORDER BY en.created_at DESC LIMIT 1
    ");
    $st->bind_param('s', $cliente_id);
    $st->execute();
    $encuesta_negocio = $st->get_result()->fetch_assoc();
    $st->close();

    // ── 4. Crédito en Proceso (estado / monto aprobado) ───────
    $credito_proceso = null;
    $st = $conn->prepare("SELECT * FROM credito_proceso WHERE cliente_prospecto_id = ? ORDER BY created_at DESC LIMIT 1");
    $st->bind_param('s', $cliente_id);
    $st->execute();
    $credito_proceso = $st->get_result()->fetch_assoc();
    $st->close();

    // ── Cálculos de ventas/compras semanales y mensuales ──────
    $en = $encuesta_negocio ?: [];
    $tot_v_sem = n2($en['venta_lunes'] ?? 0) + n2($en['venta_martes'] ?? 0) + n2($en['venta_miercoles'] ?? 0)
        + n2($en['venta_jueves'] ?? 0) + n2($en['venta_viernes'] ?? 0) + n2($en['venta_sabado'] ?? 0) + n2($en['venta_domingo'] ?? 0);
    if ($tot_v_sem <= 0) $tot_v_sem = n2($en['venta_lv'] ?? 0) + n2($en['venta_sabado'] ?? 0) + n2($en['venta_domingo'] ?? 0);
    $tot_c_sem = n2($en['compra_lunes'] ?? 0) + n2($en['compra_martes'] ?? 0) + n2($en['compra_miercoles'] ?? 0)
        + n2($en['compra_jueves'] ?? 0) + n2($en['compra_viernes'] ?? 0) + n2($en['compra_sabado'] ?? 0) + n2($en['compra_domingo'] ?? 0);
    if ($tot_c_sem <= 0) $tot_c_sem = n2($en['compra_lv'] ?? 0) + n2($en['compra_sabado'] ?? 0) + n2($en['compra_domingo'] ?? 0);
    $ventas_mes  = $tot_v_sem * 4.33;

    // ── Decodificar JSONs de activos ──────────────────────────
    $veh_neg = json_decode($en['vehiculos_negocio_json'] ?? '[]', true) ?: [];
    $veh_hog = json_decode($en['vehiculos_hogar_json'] ?? '[]', true) ?: [];
    $inm_neg = json_decode($en['inmuebles_negocio_json'] ?? '[]', true) ?: [];
    $inm_hog = json_decode($en['inmuebles_hogar_json'] ?? '[]', true) ?: [];
    $act_neg = json_decode($en['activos_negocio_json'] ?? '[]', true) ?: [];
    $act_hog = json_decode($en['activos_hogar_json'] ?? '[]', true) ?: [];

    $tot_veh = 0; foreach (array_merge($veh_neg, $veh_hog) as $v) $tot_veh += n2($v['valor'] ?? 0);
    $tot_inm = 0; foreach (array_merge($inm_neg, $inm_hog) as $i) $tot_inm += n2($i['valor'] ?? 0);
    $tot_oa  = 0;
    foreach (array_merge($act_neg, $act_hog) as $a) {
        $cu = n2($a['valor_unitario'] ?? $a['valor_comercial'] ?? $a['valor'] ?? 0);
        $ct = n2($a['cantidad'] ?? 1); if ($ct <= 0) $ct = 1;
        $tot_oa += n2($a['valor_total'] ?? ($cu * $ct));
    }
    $tot_inventario = n2($en['inv_mat_prima'] ?? 0) + n2($en['inv_prod_proc'] ?? 0);

    $total_activos = n2($en['caja_efectivo'] ?? 0) + n2($en['bancos_saldo'] ?? 0) + n2($en['cxp_netas'] ?? 0)
        + $tot_inventario + $tot_veh + $tot_inm + $tot_oa;
    $total_pasivos = n2($en['creditos_pagar'] ?? 0) + n2($en['proveedores'] ?? 0)
        + n2($en['otras_deudas_cp'] ?? 0) + n2($en['pasivos_lp'] ?? 0);
    $patrimonio = $total_activos - $total_pasivos;

    $ing_extra = n2($en['o_ing_conyuge'] ?? 0) + n2($en['o_ing_arriendos'] ?? 0) + n2($en['o_ing_pensiones'] ?? 0) + n2($en['o_ing_otros'] ?? 0);
    $ing_total = $ventas_mes + $ing_extra;
    $gas_total = n2($en['gastos_negocio'] ?? 0) + n2($en['gastos_familiares'] ?? 0);
    $excedente = $ing_total - $gas_total;

    $tieneEmpresa = ((int)($cliente['tiene_ruc'] ?? 0) === 1) || !empty($cliente['nombre_empresa']) || !empty($en);

    $fc = $ficha_credito ?: [];
    $cp = $credito_proceso ?: [];

    // ── Datos generales de encabezado ─────────────────────────
    $fechaHoy = date('d/m/Y');
    $nombreCliente = (string)($cliente['nombre'] ?? $fc['solicitante_nombre'] ?? '');

    // ================================================================
    //  Construcción del XML (SpreadsheetML / Excel 2003 XML)
    // ================================================================
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
     xmlns:o="urn:schemas-microsoft-com:office:office"
     xmlns:x="urn:schemas-microsoft-com:office:excel"
     xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
     xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

    // ── Estilos ────────────────────────────────────────────────
    $xml .= <<<STYLES
<Styles>
 <Style ss:ID="Default" ss:Name="Normal">
  <Font ss:FontName="Calibri" ss:Size="10"/>
 </Style>
 <Style ss:ID="sTitle">
  <Font ss:FontName="Calibri" ss:Size="16" ss:Bold="1" ss:Color="#FFFFFF"/>
  <Interior ss:Color="#C00000" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Borders>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
  </Borders>
 </Style>
 <Style ss:ID="sSubTitle">
  <Font ss:FontName="Calibri" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/>
  <Interior ss:Color="#C00000" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
 </Style>
 <Style ss:ID="sSection">
  <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>
  <Interior ss:Color="#C00000" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:Indent="1"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
  </Borders>
 </Style>
 <Style ss:ID="sLabel">
  <Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="#000000"/>
  <Interior ss:Color="#D9D9D9" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1" ss:Indent="1"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
  </Borders>
 </Style>
 <Style ss:ID="sInput">
  <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#00339C"/>
  <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Left" ss:Vertical="Center" ss:WrapText="1" ss:Indent="1"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
  </Borders>
 </Style>
 <Style ss:ID="sInputC">
  <Font ss:FontName="Calibri" ss:Size="9" ss:Color="#00339C"/>
  <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
  </Borders>
 </Style>
 <Style ss:ID="sTh">
  <Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="#000000"/>
  <Interior ss:Color="#BFBFBF" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
  </Borders>
 </Style>
 <Style ss:ID="sTotal">
  <Font ss:FontName="Calibri" ss:Size="9" ss:Bold="1" ss:Color="#000000"/>
  <Interior ss:Color="#F2F2F2" ss:Pattern="Solid"/>
  <Alignment ss:Horizontal="Right" ss:Vertical="Center" ss:Indent="1"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#000000"/>
  </Borders>
 </Style>
 <Style ss:ID="sBlank">
  <Font ss:FontName="Calibri" ss:Size="9"/>
  <Interior ss:Color="#FFFFFF" ss:Pattern="Solid"/>
  <Borders>
   <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
   <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#808080"/>
  </Borders>
 </Style>
 <Style ss:ID="sNote">
  <Font ss:FontName="Calibri" ss:Size="8" ss:Italic="1" ss:Color="#666666"/>
  <Alignment ss:Horizontal="Left" ss:Vertical="Top" ss:WrapText="1"/>
 </Style>
</Styles>

STYLES;

    // ── Helpers de celda ───────────────────────────────────────
    function cell(string $val, string $style = 'sInput', int $merge = 0): string {
        $m = $merge > 0 ? " ss:MergeAcross=\"$merge\"" : '';
        $v = xesc($val);
        return "<Cell ss:StyleID=\"$style\"$m><Data ss:Type=\"String\">$v</Data></Cell>";
    }
    function row(array $cells): string {
        return "<Row>" . implode('', $cells) . "</Row>\n";
    }
    // Etiqueta + valor en un solo renglón (label ocupa $lw, valor ocupa el resto hasta $totalCols)
    function lv(string $label, $valor, int $labelSpan = 0, int $valSpan = 0): array {
        $out = [];
        $out[] = cell($label, 'sLabel', $labelSpan);
        $out[] = cell($valor === null ? '' : (string)$valor, 'sInput', $valSpan);
        return $out;
    }

    $TOTAL_COLS = 8; // columnas totales de la hoja (índices 0..7)

    $xml .= '<Worksheet ss:Name="Solicitud de Credito">' . "\n";
    $xml .= '<Table ss:DefaultColumnWidth="70">' . "\n";
    $xml .= '<Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="90"/><Column ss:Width="90"/>' . "\n";

    // ── Título ──────────────────────────────────────────────────
    $xml .= row([cell('SOLICITUD DE CRÉDITO', 'sTitle', $TOTAL_COLS - 1)]);
    $xml .= row([cell('Fecha de generación: ' . $fechaHoy . '   |   Cliente: ' . $nombreCliente . '   |   Cédula: ' . $cedula, 'sSubTitle', $TOTAL_COLS - 1)]);
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 1. INFORMACIÓN DEL CRÉDITO ─────────────────────────────
    $xml .= row([cell('INFORMACIÓN DEL CRÉDITO', 'sSection', $TOTAL_COLS - 1)]);
    $destino = (string)($fc['destino_credito'] ?? '');
    if ($destino !== '' && strtolower($destino) === 'otros' && !empty($fc['dest_otros_detalle'])) {
        $destino .= ' - ' . $fc['dest_otros_detalle'];
    }
    $xml .= row(array_merge(
        lv('Monto Solicitado ($)', $fc['monto_credito'] ?? '', 1, 1),
        lv('Plazo (meses)', $fc['plazo_credito_meses'] ?? '', 1, 1),
        lv('Tipo de Crédito', '', 1, 1)
    ));
    $xml .= row(array_merge(
        lv('Destino del Crédito', $destino, 1, 1),
        lv('Cuotas', '', 1, 1),
        lv('Periodicidad', '', 1, 1)
    ));
    $xml .= row(array_merge(
        lv('Fecha de Pago', '', 1, 1),
        lv('Monto Aprobado ($)', isset($cp['monto_aprobado']) ? money($cp['monto_aprobado']) : '', 1, 1),
        lv('Estado', $cp['estado_credito'] ?? 'levantamiento', 1, 1)
    ));
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 2. INFORMACIÓN PERSONAL ─────────────────────────────────
    $xml .= row([cell('INFORMACIÓN PERSONAL', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row(array_merge(
        lv('Nombre Completo', $nombreCliente, 1, 3),
        lv('Cédula', $cedula, 1, 1)
    ));
    $celular = $fc['solicitante_celular'] ?? $cliente['celular'] ?? $cliente['telefono2'] ?? '';
    $xml .= row(array_merge(
        lv('Celular', $celular, 1, 1),
        lv('Teléfono Fijo', $cliente['telefono'] ?? '', 1, 1),
        lv('Email', $cliente['email'] ?? '', 1, 1)
    ));
    $estadoCivil = $fc['solicitante_estado_civil'] ?? $cliente['estado_civil'] ?? '';
    $xml .= row(array_merge(
        lv('Estado Civil', $estadoCivil, 1, 1),
        lv('Género', $cliente['genero'] ?? '', 1, 1),
        lv('Nivel Educación', $cliente['nivel_educacion'] ?? '', 1, 1)
    ));
    $xml .= row(array_merge(
        lv('N° Dependientes', $cliente['num_dependientes'] ?? '', 1, 1),
        lv('Dirección Domicilio', $cliente['direccion'] ?? '', 1, 3)
    ));
    $xml .= row(array_merge(
        lv('Tipo de Vivienda', $cliente['tipo_vivienda'] ?? '', 1, 1),
        lv('Zona', $cliente['zona'] ?? '', 1, 1),
        lv('Ciudad', $cliente['ciudad'] ?? '', 1, 1)
    ));
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 3. ACTIVIDAD ECONÓMICA DEUDOR (DEPENDIENTE) ────────────
    $xml .= row([cell('INFORMACIÓN DE ACTIVIDAD ECONÓMICA DEUDOR (DEPENDIENTE)', 'sSection', $TOTAL_COLS - 1)]);
    if (!$tieneEmpresa) {
        $xml .= row(array_merge(lv('Actividad / Ocupación', $cliente['actividad'] ?? '', 1, 3), lv('Antigüedad', '', 1, 1)));
        $xml .= row(array_merge(lv('Empresa donde Trabaja', '', 1, 3), lv('Cargo', '', 1, 1)));
        $xml .= row(array_merge(lv('Teléfono Empresa', '', 1, 1), lv('Sueldo Mensual ($)', '', 1, 1), lv('Ingreso Adicional ($)', '', 1, 1)));
    } else {
        $xml .= row([cell('No aplica (el cliente registra actividad económica independiente, ver sección siguiente)', 'sNote', $TOTAL_COLS - 1)]);
    }
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 4. ACTIVIDAD ECONÓMICA (INDEPENDIENTE) ─────────────────
    $xml .= row([cell('INFORMACIÓN DE ACTIVIDAD ECONÓMICA (INDEPENDIENTE)', 'sSection', $TOTAL_COLS - 1)]);
    if ($tieneEmpresa) {
        $xml .= row(array_merge(lv('Nombre del Negocio', $cliente['nombre_empresa'] ?? '', 1, 3), lv('Actividad', $cliente['actividad'] ?? '', 1, 1)));
        $xml .= row(array_merge(
            lv('N° RUC', $cliente['numero_ruc'] ?? $cliente['ruc_val'] ?? '', 1, 1),
            lv('N° RISE', $cliente['rise_val'] ?? '', 1, 1),
            lv('Tipo Empresa', $cliente['tipo_empresa'] ?? '', 1, 1)
        ));
        $xml .= row(array_merge(
            lv('Régimen Tributario', $cliente['regimen_tributario'] ?? '', 1, 1),
            lv('Lleva Contabilidad', (isset($cliente['lleva_contabilidad']) ? ((int)$cliente['lleva_contabilidad'] === 1 ? 'Sí' : 'No') : ''), 1, 1),
            lv('Declara IVA', (isset($cliente['declara_iva']) ? ((int)$cliente['declara_iva'] === 1 ? 'Sí' : 'No') : ''), 1, 1)
        ));
        $xml .= row([cell('Ventas y Compras Semanales', 'sTh', $TOTAL_COLS - 1)]);
        $xml .= row([
            cell('Concepto', 'sTh'), cell('Lunes', 'sTh'), cell('Martes', 'sTh'), cell('Miércoles', 'sTh'),
            cell('Jueves', 'sTh'), cell('Viernes', 'sTh'), cell('Sábado', 'sTh'), cell('Domingo', 'sTh'),
        ]);
        $xml .= row([
            cell('Ventas ($)', 'sLabel'),
            cell(money($en['venta_lunes'] ?? 0), 'sInputC'), cell(money($en['venta_martes'] ?? 0), 'sInputC'),
            cell(money($en['venta_miercoles'] ?? 0), 'sInputC'), cell(money($en['venta_jueves'] ?? 0), 'sInputC'),
            cell(money($en['venta_viernes'] ?? 0), 'sInputC'), cell(money($en['venta_sabado'] ?? 0), 'sInputC'),
            cell(money($en['venta_domingo'] ?? 0), 'sInputC'),
        ]);
        $xml .= row([
            cell('Compras ($)', 'sLabel'),
            cell(money($en['compra_lunes'] ?? 0), 'sInputC'), cell(money($en['compra_martes'] ?? 0), 'sInputC'),
            cell(money($en['compra_miercoles'] ?? 0), 'sInputC'), cell(money($en['compra_jueves'] ?? 0), 'sInputC'),
            cell(money($en['compra_viernes'] ?? 0), 'sInputC'), cell(money($en['compra_sabado'] ?? 0), 'sInputC'),
            cell(money($en['compra_domingo'] ?? 0), 'sInputC'),
        ]);
        $xml .= row(array_merge(
            lv('Total Ventas Semana ($)', money($tot_v_sem), 1, 1),
            lv('Total Compras Semana ($)', money($tot_c_sem), 1, 1),
            lv('Ventas Mensuales Estimadas ($)', money($ventas_mes), 1, 1)
        ));
    } else {
        $xml .= row([cell('No aplica (el cliente no registra actividad económica independiente)', 'sNote', $TOTAL_COLS - 1)]);
    }
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 5. INFORMACIÓN DEL CÓNYUGE ─────────────────────────────
    $xml .= row([cell('INFORMACIÓN DEL CÓNYUGE', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row(array_merge(
        lv('Nombre Cónyuge', $fc['solicitante_conyuge_nombre'] ?? '', 1, 3),
        lv('Cédula', $fc['solicitante_conyuge_cedula'] ?? '', 1, 1)
    ));
    $xml .= row(array_merge(
        lv('Celular', $fc['solicitante_conyuge_celular'] ?? '', 1, 1),
        lv('Ingreso Mensual Cónyuge ($)', isset($en['o_ing_conyuge']) ? money($en['o_ing_conyuge']) : '', 1, 1),
        lv('Ocupación', '', 1, 1)
    ));
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 6. REFERENCIAS PERSONALES ──────────────────────────────
    $xml .= row([cell('REFERENCIAS PERSONALES', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row([cell('Nombre', 'sTh', 2), cell('', 'sTh'), cell('Cédula', 'sTh'), cell('Celular', 'sTh'), cell('Parentesco/Relación', 'sTh', 1)]);
    $garanteNombre = $fc['garante_nombre'] ?? '';
    $xml .= row([
        cell($garanteNombre, 'sInput', 2), cell('', 'sInput'),
        cell($fc['garante_cedula'] ?? '', 'sInputC'),
        cell($fc['garante_celular'] ?? '', 'sInputC'),
        cell($garanteNombre !== '' ? 'Garante' : '', 'sInputC', 1),
    ]);
    for ($i = 0; $i < 2; $i++) {
        $xml .= row([cell('', 'sBlank', 2), cell('', 'sBlank'), cell('', 'sBlank'), cell('', 'sBlank'), cell('', 'sBlank', 1)]);
    }
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 7. REFERENCIAS BANCARIAS ────────────────────────────────
    $xml .= row([cell('REFERENCIAS BANCARIAS', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row([cell('Institución', 'sTh', 2), cell('', 'sTh'), cell('Tipo de Cuenta', 'sTh'), cell('N° Cuenta', 'sTh'), cell('Antigüedad', 'sTh', 1)]);
    for ($i = 0; $i < 2; $i++) {
        $xml .= row([cell('', 'sBlank', 2), cell('', 'sBlank'), cell('', 'sBlank'), cell('', 'sBlank'), cell('', 'sBlank', 1)]);
    }
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 8. REFERENCIAS COMERCIALES ──────────────────────────────
    $xml .= row([cell('REFERENCIAS COMERCIALES', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row([cell('Nombre / Empresa', 'sTh', 2), cell('', 'sTh'), cell('Cédula/RUC', 'sTh'), cell('Teléfono', 'sTh'), cell('Relación Comercial', 'sTh', 1)]);
    for ($i = 0; $i < 2; $i++) {
        $xml .= row([cell('', 'sBlank', 2), cell('', 'sBlank'), cell('', 'sBlank'), cell('', 'sBlank'), cell('', 'sBlank', 1)]);
    }
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 9. INGRESOS MENSUALES (Ingresos / Gastos) ───────────────
    $xml .= row([cell('INGRESOS MENSUALES', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row([cell('INGRESOS', 'sTh', 3), cell('', 'sTh'), cell('', 'sTh'), cell('', 'sTh'), cell('GASTOS', 'sTh', 3), cell('', 'sTh')]);
    $ingresosRows = [
        ['Ventas Mensuales (negocio)', money($ventas_mes)],
        ['Ingreso Cónyuge', money($en['o_ing_conyuge'] ?? 0)],
        ['Arriendos', money($en['o_ing_arriendos'] ?? 0)],
        ['Pensiones', money($en['o_ing_pensiones'] ?? 0)],
        ['Otros Ingresos', money($en['o_ing_otros'] ?? 0)],
    ];
    $gastosRows = [
        ['Costos de Venta', money($en['costos_ventas'] ?? 0)],
        ['Sueldos (negocio)', money($en['g_neg_sueldos'] ?? 0)],
        ['Arriendo (negocio)', money($en['g_neg_arriendo'] ?? 0)],
        ['Servicios Básicos (negocio)', money($en['g_neg_serv_bas'] ?? 0)],
        ['Transporte (negocio)', money($en['g_neg_transporte'] ?? 0)],
        ['Alimentación (familiar)', money($en['g_fam_alim'] ?? 0)],
        ['Arriendo (familiar)', money($en['g_fam_arriendo'] ?? 0)],
        ['Educación (familiar)', money($en['g_fam_educacion'] ?? 0)],
        ['Salud (familiar)', money($en['g_fam_salud'] ?? 0)],
        ['Otros Gastos (familiar)', money($en['g_fam_otros'] ?? 0)],
    ];
    $maxRows = max(count($ingresosRows), count($gastosRows));
    for ($i = 0; $i < $maxRows; $i++) {
        $ing = $ingresosRows[$i] ?? ['', ''];
        $gas = $gastosRows[$i] ?? ['', ''];
        $xml .= row([
            cell($ing[0], 'sLabel', 2), cell('', 'sLabel'), cell($ing[1], 'sInputC'),
            cell($gas[0], 'sLabel', 2), cell('', 'sLabel'), cell($gas[1], 'sInputC'),
        ]);
    }
    $xml .= row([
        cell('TOTAL INGRESOS ($)', 'sTotal', 2), cell('', 'sTotal'), cell(money($ing_total), 'sInputC'),
        cell('TOTAL GASTOS ($)', 'sTotal', 2), cell('', 'sTotal'), cell(money($gas_total), 'sInputC'),
    ]);
    $xml .= row([cell('EXCEDENTE / SALDO FINAL MENSUAL ($)', 'sTotal', 5), cell(money($excedente), 'sInputC')]);
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 10. ESTADO DE SITUACIÓN PERSONAL (Activos / Pasivos) ────
    $xml .= row([cell('ESTADO DE SITUACIÓN PERSONAL', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row([cell('ACTIVOS', 'sTh', 3), cell('', 'sTh'), cell('', 'sTh'), cell('PASIVOS', 'sTh', 3), cell('', 'sTh')]);
    $activosRows = [
        ['Caja / Efectivo', money($en['caja_efectivo'] ?? 0)],
        ['Bancos / Ahorros', money($en['bancos_saldo'] ?? 0)],
        ['Cuentas por Cobrar (Netas)', money($en['cxp_netas'] ?? 0)],
        ['Inventario (Mat. Prima/Producción)', money($tot_inventario)],
        ['Vehículos', money($tot_veh)],
        ['Inmuebles / Propiedades', money($tot_inm)],
        ['Maquinaria / Enseres / Otros', money($tot_oa)],
    ];
    $pasivosRows = [
        ['Créditos por Pagar (C.P.)', money($en['creditos_pagar'] ?? 0)],
        ['Proveedores', money($en['proveedores'] ?? 0)],
        ['Otras Deudas C.P.', money($en['otras_deudas_cp'] ?? 0)],
        ['Pasivos L.P. (Hipotecas/Otros)', money($en['pasivos_lp'] ?? 0)],
    ];
    $maxRows2 = max(count($activosRows), count($pasivosRows));
    for ($i = 0; $i < $maxRows2; $i++) {
        $a = $activosRows[$i] ?? ['', ''];
        $p = $pasivosRows[$i] ?? ['', ''];
        $xml .= row([
            cell($a[0], 'sLabel', 2), cell('', 'sLabel'), cell($a[1], 'sInputC'),
            cell($p[0], 'sLabel', 2), cell('', 'sLabel'), cell($p[1], 'sInputC'),
        ]);
    }
    $xml .= row([
        cell('TOTAL ACTIVOS ($)', 'sTotal', 2), cell('', 'sTotal'), cell(money($total_activos), 'sInputC'),
        cell('TOTAL PASIVOS ($)', 'sTotal', 2), cell('', 'sTotal'), cell(money($total_pasivos), 'sInputC'),
    ]);
    $xml .= row([cell('PATRIMONIO (CAPITAL) ($)', 'sTotal', 5), cell(money($patrimonio), 'sInputC')]);
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 11. Declaración Legal / Firmas ──────────────────────────
    $xml .= row([cell('DECLARACIÓN Y FIRMAS', 'sSection', $TOTAL_COLS - 1)]);
    $xml .= row([cell(
        'Declaro que la información contenida en esta solicitud es verídica y autorizo a la institución a verificarla ' .
        'en centrales de riesgo y demás fuentes que considere necesarias para el análisis de la presente solicitud de crédito.',
        'sNote', $TOTAL_COLS - 1
    )]);
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);
    $xml .= row(array_merge(
        [cell('Firma Solicitante: _______________________', 'sBlank', 3)],
        [cell('Firma Garante: _______________________', 'sBlank', 3)]
    ));
    $xml .= row([cell('Fecha: ' . $fechaHoy, 'sBlank', $TOTAL_COLS - 1)]);
    $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);

    // ── 12. Croquis del domicilio / negocio ─────────────────────
    $xml .= row([cell('CROQUIS DEL DOMICILIO / NEGOCIO', 'sSection', $TOTAL_COLS - 1)]);
    for ($i = 0; $i < 6; $i++) {
        $xml .= row([cell('', 'sBlank', $TOTAL_COLS - 1)]);
    }
    $refUbic = trim(($cliente['direccion'] ?? '') . ' ' . (isset($cliente['latitud'], $cliente['longitud']) ? '(GPS: ' . $cliente['latitud'] . ', ' . $cliente['longitud'] . ')' : ''));
    $xml .= row([cell('Referencia de ubicación registrada: ' . $refUbic, 'sNote', $TOTAL_COLS - 1)]);

    $xml .= '</Table>' . "\n";
    $xml .= '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup>
    <Layout x:Orientation="Portrait"/>
    <PageMargins x:Bottom="0.5" x:Left="0.4" x:Right="0.4" x:Top="0.5"/>
   </PageSetup>
   <FitToPage/>
   <Print>
    <FitWidth>1</FitWidth>
    <FitHeight>0</FitHeight>
   </Print>
  </WorksheetOptions>' . "\n";
    $xml .= '</Worksheet>' . "\n";
    $xml .= '</Workbook>';

    $filenameSafe = 'Solicitud_Credito_' . preg_replace('/[^A-Za-z0-9_-]/', '', $cedula ?: $cliente_id) . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameSafe . '"');
    header('Cache-Control: max-age=0');
    echo $xml;
    exit;

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Error generando el archivo: ' . $e->getMessage()]);
    exit;
} finally {
    if (isset($conn)) {
        try { $conn->close(); } catch (Throwable $_) {}
    }
}

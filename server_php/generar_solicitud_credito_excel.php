<?php
// ============================================================
// generar_solicitud_credito_excel.php
// ------------------------------------------------------------
// Genera un archivo .xlsx REAL (OOXML, vía ZipArchive) que
// replica exactamente la plantilla física "SOLICITUD DE CRÉDITO"
// de Yantzaza Coop (hoja "Solicitud def" del archivo subido por
// el usuario), incluyendo los 2 logos incrustados, colores,
// merges y anchos/alturas originales — y la llena con los datos
// reales ya capturados del cliente/prospecto.
//
// Los datos de estructura (texto de etiquetas, merges, alturas
// de fila) se extrajeron una sola vez de la plantilla real y se
// guardaron en:
//   - plantilla_solicitud_cells.php       (677 celdas con estilo)
//   - plantilla_solicitud_merges.php      (189 rangos combinados)
//   - plantilla_solicitud_row_heights.php (alto real por fila)
//   - plantilla_solicitud_col_widths.php  (ancho real A-N)
// No editar esos archivos a mano.
//
// Uso (mobile): GET/POST generar_solicitud_credito_excel.php?cliente_id=XXXX
// Responde el archivo binario .xlsx listo para descargar/compartir.
// ============================================================

require_once __DIR__ . '/db_config.php';

$cliente_id = trim($_REQUEST['cliente_id'] ?? '');
if ($cliente_id === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'cliente_id requerido']);
    exit;
}

// ── Helpers numéricos/texto ─────────────────────────────────
function n2($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function money($v): string { return number_format(n2($v), 2, '.', ''); }
function xesc($v): string {
    if ($v === null) return '';
    $s = (string)$v;
    return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
}
function colLetter(int $idx): string {
    $s = '';
    while ($idx > 0) {
        $r = ($idx - 1) % 26;
        $s = chr(65 + $r) . $s;
        $idx = intdiv($idx - 1, 26);
    }
    return $s;
}
// Heurística de nombre (Ecuador: 1-2 nombres + 1-2 apellidos).
// Devuelve [nombres, apellidoPaterno, apellidoMaterno].
function splitNombre(string $full): array {
    $full = trim(preg_replace('/\s+/', ' ', $full));
    if ($full === '') return ['', '', ''];
    $tok = explode(' ', $full);
    $n = count($tok);
    if ($n === 1) return [$tok[0], '', ''];
    if ($n === 2) return [$tok[0], $tok[1], ''];
    if ($n === 3) return [$tok[0], $tok[1], $tok[2]];
    // 4+: últimos 2 son apellidos, resto son nombres
    $apMat = array_pop($tok);
    $apPat = array_pop($tok);
    return [implode(' ', $tok), $apPat, $apMat];
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

    // ── 2. Ficha de Crédito ────────────────────────────────────
    // (TRIM en la comparación por si la cédula quedó con espacios en
    // algún lado; cliente_cedula se guarda tal cual la escribió/tenía
    // el asesor al llenar la encuesta de producto "crédito".)
    $ficha_credito = null;
    if ($cedula !== '') {
        $st = $conn->prepare("
            SELECT fc.*
            FROM ficha_credito fc
            INNER JOIN ficha_producto fp ON fp.id = fc.ficha_id
            WHERE TRIM(fp.cliente_cedula) = TRIM(?) AND fp.producto_tipo = 'credito'
            ORDER BY fp.created_at DESC LIMIT 1
        ");
        $st->bind_param('s', $cedula);
        $st->execute();
        $ficha_credito = $st->get_result()->fetch_assoc();
        $st->close();
    }

    // ── 3. Encuesta de Negocio (levantamiento de empresa) ─────
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

    // ── 4. Crédito en Proceso ──────────────────────────────────
    $st = $conn->prepare("SELECT * FROM credito_proceso WHERE cliente_prospecto_id = ? ORDER BY created_at DESC LIMIT 1");
    $st->bind_param('s', $cliente_id);
    $st->execute();
    $credito_proceso = $st->get_result()->fetch_assoc();
    $st->close();

    // ── 5. Nombre del asesor ────────────────────────────────────
    $asesorNombre = '';
    if (!empty($cliente['asesor_id'])) {
        $st = $conn->prepare("SELECT u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ? LIMIT 1");
        $st->bind_param('s', $cliente['asesor_id']);
        $st->execute();
        $rowA = $st->get_result()->fetch_assoc();
        $st->close();
        $asesorNombre = $rowA['nombre'] ?? '';
    }

    $en = $encuesta_negocio ?: [];
    $fc = $ficha_credito ?: [];
    $cp = $credito_proceso ?: [];

    // ── Cálculos financieros (mismas fórmulas que la versión anterior) ──
    $tot_v_sem = n2($en['venta_lunes'] ?? 0) + n2($en['venta_martes'] ?? 0) + n2($en['venta_miercoles'] ?? 0)
        + n2($en['venta_jueves'] ?? 0) + n2($en['venta_viernes'] ?? 0) + n2($en['venta_sabado'] ?? 0) + n2($en['venta_domingo'] ?? 0);
    if ($tot_v_sem <= 0) $tot_v_sem = n2($en['venta_lv'] ?? 0) + n2($en['venta_sabado'] ?? 0) + n2($en['venta_domingo'] ?? 0);
    $ventas_mes = $tot_v_sem * 4.33;

    $veh_neg = json_decode($en['vehiculos_negocio_json'] ?? '[]', true) ?: [];
    $veh_hog = json_decode($en['vehiculos_hogar_json'] ?? '[]', true) ?: [];
    $inm_neg = json_decode($en['inmuebles_negocio_json'] ?? '[]', true) ?: [];
    $inm_hog = json_decode($en['inmuebles_hogar_json'] ?? '[]', true) ?: [];
    $act_neg = json_decode($en['activos_negocio_json'] ?? '[]', true) ?: [];
    $act_hog = json_decode($en['activos_hogar_json'] ?? '[]', true) ?: [];

    $tot_veh = 0; foreach (array_merge($veh_neg, $veh_hog) as $v) $tot_veh += n2($v['valor'] ?? 0);
    $tot_inm = 0; foreach (array_merge($inm_neg, $inm_hog) as $i) $tot_inm += n2($i['valor'] ?? 0);
    $tot_oa = 0;
    foreach (array_merge($act_neg, $act_hog) as $a) {
        $cu = n2($a['valor_unitario'] ?? $a['valor_comercial'] ?? $a['valor'] ?? 0);
        $ct = n2($a['cantidad'] ?? 1); if ($ct <= 0) $ct = 1;
        $tot_oa += n2($a['valor_total'] ?? ($cu * $ct));
    }
    $tot_inventario = n2($en['inv_mat_prima'] ?? 0) + n2($en['inv_prod_proc'] ?? 0);

    // Ingresos (columna izquierda del bloque "INGRESOS MENSUALES")
    $ing_sueldo   = 0.0; // no capturado (sección dependiente no existe en la app)
    $ing_negocio  = $ventas_mes;
    $ing_honorar  = 0.0;
    $ing_agric    = 0.0;
    $ing_rentabr  = 0.0;
    $ing_rentainv = 0.0;
    // Ingresos (columna central)
    $ing_remesas  = 0.0;
    $ing_conyuge  = n2($en['o_ing_conyuge'] ?? 0);
    $ing_otros    = n2($en['o_ing_arriendos'] ?? 0) + n2($en['o_ing_pensiones'] ?? 0) + n2($en['o_ing_otros'] ?? 0);
    $tot_ingresos = $ing_sueldo + $ing_negocio + $ing_honorar + $ing_agric + $ing_rentabr + $ing_rentainv
        + $ing_remesas + $ing_conyuge + $ing_otros;

    // Gastos (columna derecha del bloque)
    $gas_familiares = n2($en['g_fam_alim'] ?? 0) + n2($en['g_fam_educacion'] ?? 0) + n2($en['g_fam_salud'] ?? 0);
    $gas_negocio    = n2($en['g_neg_sueldos'] ?? 0) + n2($en['g_neg_serv_bas'] ?? 0) + n2($en['g_neg_transporte'] ?? 0) + n2($en['costos_ventas'] ?? 0);
    $gas_arriendo   = n2($en['g_fam_arriendo'] ?? 0) + n2($en['g_neg_arriendo'] ?? 0);
    $gas_financ     = 0.0; // no capturado distinto de créditos ya registrados
    $gas_imprevistos = 0.0; // no capturado
    $gas_otros      = n2($en['g_fam_otros'] ?? 0);
    $tot_gastos = $gas_familiares + $gas_negocio + $gas_arriendo + $gas_financ + $gas_imprevistos + $gas_otros;

    $tot_activo_neto = $tot_ingresos - $tot_gastos;

    // Estado de situación personal (Activos / Pasivos)
    $act_efectivo = n2($en['caja_efectivo'] ?? 0);
    $act_bancos   = n2($en['bancos_saldo'] ?? 0);
    $act_cxc      = n2($en['cxp_netas'] ?? 0);
    $act_fijos    = $tot_veh + $tot_inm + $tot_oa;
    $act_otros    = $tot_inventario;
    $tot_activos  = $act_efectivo + $act_bancos + $act_cxc + $act_fijos + $act_otros;

    $pas_proveedores = n2($en['proveedores'] ?? 0);
    $pas_cxp         = n2($en['creditos_pagar'] ?? 0);
    $pas_cortoplazo  = n2($en['otras_deudas_cp'] ?? 0);
    $pas_largoplazo  = n2($en['pasivos_lp'] ?? 0);
    $pas_otros       = 0.0;
    $tot_pasivos = $pas_proveedores + $pas_cxp + $pas_cortoplazo + $pas_largoplazo + $pas_otros;

    $patrimonio = $tot_activos - $tot_pasivos;

    // ── Nombre cliente y destino de crédito ───────────────────
    $nombreCliente = (string)($cliente['nombre'] ?? $fc['solicitante_nombre'] ?? '');
    [$nomN, $nomP, $nomM] = splitNombre($nombreCliente);

    $nombreConyuge = (string)($fc['solicitante_conyuge_nombre'] ?? '');
    [$conN, $conP, $conM] = splitNombre($nombreConyuge);

    $destino = (string)($fc['destino_credito'] ?? '');
    if ($destino !== '' && strtolower($destino) === 'otros' && !empty($fc['dest_otros_detalle'])) {
        $destino .= ' - ' . $fc['dest_otros_detalle'];
    }

    $celular = (string)($fc['solicitante_celular'] ?? $cliente['celular'] ?? $cliente['telefono2'] ?? '');
    $telefonoFijo = (string)($cliente['telefono'] ?? '');
    $telefonoDisponible = $celular !== '' ? $celular : $telefonoFijo;

    // "Lugar de trabajo" del deudor (sección DEPENDIENTE): no se captura el
    // nombre del empleador para empleados en relación de dependencia, así
    // que se usa el sector/zona (campo libre "Sector o barrio" de la
    // encuesta) como referencia; si el asesor no lo llenó, se cae a la
    // ciudad del cliente (sí se captura siempre) para no dejar la celda vacía.
    $sectorTrabajoDep = (string)($cliente['zona'] ?? '');
    if ($sectorTrabajoDep === '') $sectorTrabajoDep = (string)($cliente['ciudad'] ?? '');

    // "Profesión" del deudor: no hay un campo de título/profesión propio,
    // se usa la actividad económica capturada en la encuesta (empleado
    // privado/público/negocio propio/profesional) como mejor aproximación.
    // Si el valor no coincide con ninguna de las opciones conocidas del
    // formulario (p. ej. "otro" o un valor futuro), se muestra tal cual en
    // vez de dejar la celda vacía.
    $actividadLabels = [
        'negocio_propio'   => 'Negocio propio',
        'empleado_privado' => 'Empleado privado',
        'empleado_publico' => 'Empleado público',
        'profesional'      => 'Profesional',
    ];
    $actividadRaw = (string)($cliente['actividad'] ?? '');
    $profesionDep = $actividadLabels[$actividadRaw]
        ?? ($actividadRaw !== '' ? ucfirst(str_replace('_', ' ', $actividadRaw)) : null);

    // Cuotas = plazo en meses ya capturado en la encuesta de producto (crédito).
    $cuotas = isset($fc['plazo_credito_meses']) && $fc['plazo_credito_meses'] !== ''
        ? (string)((int)$fc['plazo_credito_meses'])
        : null;
    // Fecha de pago: el mes siguiente a la fecha en que se emite/descarga la solicitud.
    $fechaPago = date('d/m/Y', strtotime('+1 month'));

    // ================================================================
    //  Mapa de valores reales -> coordenadas de la plantilla (celda
    //  superior-izquierda de cada casilla de INPUT identificada en la
    //  plantilla real, filas 9-98). Las celdas no listadas aquí se
    //  quedan en blanco (campo no capturado por la app hoy).
    // ================================================================
    $overrides = [
        // INFORMACIÓN DEL CRÉDITO (fila 12-15)
        'C12' => 'Normal',
        'C13' => (isset($fc['monto_credito']) && $fc['monto_credito'] !== '') ? money($fc['monto_credito']) : null,
        'J12' => $cuotas,
        'J14' => $fechaPago,
        'C15' => $destino ?: null,

        // INFORMACIÓN PERSONAL (fila 18-27)
        'A19' => $nomN ?: null, 'F19' => $nomP ?: null, 'J19' => $nomM ?: null,
        'M19' => $cliente['email'] ?? null,
        'A21' => $cedula ?: null,
        // Marca la casilla M/F correcta (D21/E21 son etiquetas fijas en la
        // plantilla; se le agrega un check a la que corresponda).
        'D21' => (($cliente['sexo'] ?? null) === 'M') ? 'M ✓' : null,
        'E21' => (($cliente['sexo'] ?? null) === 'F') ? 'F ✓' : null,
        // estado_civil se captura en la ficha de crédito (solicitante_estado_civil),
        // no en cliente_prospecto (esa tabla no tiene esa columna).
        'A23' => $fc['solicitante_estado_civil'] ?? null,
        // num_dependientes: no se captura en ningún lado todavía (queda en blanco).
        'A25' => $cliente['direccion'] ?? null,
        'K25' => $telefonoFijo ?: null,
        'M25' => $celular ?: null,
        'A27' => $cliente['ciudad'] ?? null,
        'C27' => $cliente['zona'] ?? null,

        // ACTIVIDAD ECONÓMICA DEUDOR (DEPENDIENTE) (fila 29-35)
        // Lugar de trabajo actual: se usa sector/zona (georeferenciado) porque
        // no se captura el nombre del empleador para empleados en relación
        // de dependencia. Dirección de trabajo: dirección georeferenciada del
        // domicilio (única dirección geo-capturada hoy para este perfil).
        'A31' => $sectorTrabajoDep ?: null,
        'E31' => $cliente['direccion'] ?? null,
        'L31' => $telefonoDisponible ?: null,
        'A33' => $profesionDep,
        // Antigüedad, cargo que desempeña y actividad de la empresa: no se
        // capturan todavía en la encuesta -> quedan en blanco.
        // Lugar de trabajo anterior / cargo anterior / antigüedad anterior /
        // teléfono anterior: no se captura -> quedan en blanco.

        // ACTIVIDAD ECONÓMICA (INDEPENDIENTE) (fila 38-45)
        'A39' => $cliente['nombre_empresa'] ?? null,
        'E39' => $cliente['actividad'] ?? null,
        'E41' => $cliente['ciudad'] ?? null,
        'K41' => $telefonoFijo ?: null,

        // INFORMACIÓN DEL CÓNYUGE (fila 48-55)
        'A49' => $conN ?: null,
        'F49' => $conP ?: null,
        'A51' => $fc['solicitante_conyuge_cedula'] ?? null,

        // INGRESOS MENSUALES (fila 79-87) — valores ya numéricos "0.00" por defecto en la plantilla
        'C79' => money($ing_sueldo), 'C80' => money($ing_negocio), 'C81' => money($ing_honorar),
        'C82' => money($ing_agric), 'C83' => money($ing_rentabr), 'C84' => money($ing_rentainv),
        'H79' => money($ing_remesas), 'H80' => money($ing_conyuge), 'H81' => money($ing_otros),
        'M79' => money($gas_familiares), 'M80' => money($gas_negocio), 'M81' => money($gas_arriendo),
        'M82' => money($gas_financ), 'M83' => money($gas_imprevistos), 'M84' => money($gas_otros),
        'C85' => money($tot_ingresos), 'M85' => money($tot_gastos),
        'D87' => money($tot_activo_neto),

        // ESTADO DE SITUACIÓN PERSONAL (fila 92-98)
        'B92' => money($act_efectivo), 'B93' => money($act_bancos), 'B94' => money($act_cxc),
        'B95' => money($act_fijos), 'B96' => money($act_otros),
        'K92' => money($pas_proveedores), 'K93' => money($pas_cxp), 'K94' => money($pas_cortoplazo),
        'K95' => money($pas_largoplazo), 'K96' => money($pas_otros),
        'B97' => money($tot_activos), 'K97' => money($tot_pasivos),
        'D98' => money($patrimonio),
    ];
    // Limpia nulos (deja el placeholder original de la plantilla, p. ej. "0" en celdas numéricas)
    $overrides = array_filter($overrides, fn($v) => $v !== null && $v !== '');

    // ================================================================
    //  Construcción del .xlsx real (OOXML) vía ZipArchive
    // ================================================================
    $cellsRaw   = require __DIR__ . '/plantilla_solicitud_cells.php';        // [r1,c1,r2,c2,text,fill,bold]
    $mergesRaw  = require __DIR__ . '/plantilla_solicitud_merges.php';       // [r1,c1,r2,c2]
    $rowHeights = require __DIR__ . '/plantilla_solicitud_row_heights.php';  // row => height
    $colWidths  = require __DIR__ . '/plantilla_solicitud_col_widths.php';   // 'A'..'N' => width
    $rowHeightsTop = [1 => 19.8, 2 => 16.2, 3 => 7.5, 4 => 24.0, 5 => 16.5, 6 => 10.5, 7 => 6.0, 8 => 6.0];

    // Agrupar celdas de la plantilla por fila
    $byRow = [];
    foreach ($cellsRaw as $c) {
        [$r1, $c1, $c2r2, $c2, $text, $fill, $bold] = $c;
        $byRow[$r1][] = [$c1, $c2, $text, $fill, $bold];
    }

    // ── Estilos (fonts / fills / borders / cellXfs) ───────────
    $fonts = [
        '<font><sz val="10"/><name val="Calibri"/></font>',                                   // 0 default
        '<font><b/><sz val="14"/><color rgb="FF003366"/><name val="Verdana"/></font>',        // 1 title
        '<font><b/><sz val="9"/><color rgb="FFFFFFFF"/><name val="Verdana"/></font>',         // 2 red header
        '<font><sz val="8"/><color rgb="FF003366"/><name val="Verdana"/></font>',             // 3 label (no bold)
        '<font><b/><sz val="8"/><color rgb="FF003366"/><name val="Verdana"/></font>',         // 4 label bold
        '<font><b/><sz val="9"/><color rgb="FF000000"/><name val="Verdana"/></font>',         // 5 input
        '<font><sz val="7.5"/><color rgb="FF003366"/><name val="Verdana"/></font>',           // 6 plain
    ];
    $fills = [
        '<fill><patternFill patternType="none"/></fill>',                                              // 0
        '<fill><patternFill patternType="gray125"/></fill>',                                            // 1 (reservado, requerido por spec)
        '<fill><patternFill patternType="solid"><fgColor rgb="FFC00000"/><bgColor indexed="64"/></patternFill></fill>', // 2 RED
        '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill>', // 3 GRAY
        '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>', // 4 WHITE
    ];
    $thinBorder = '<border><left style="thin"><color rgb="FF999999"/></left><right style="thin"><color rgb="FF999999"/></right><top style="thin"><color rgb="FF999999"/></top><bottom style="thin"><color rgb="FF999999"/></bottom></border>';
    $noBorder = '<border><left/><right/><top/><bottom/></border>';
    $borders = [$noBorder, $thinBorder];

    // xf: [fontIdx, fillIdx, borderIdx, halign, valign, wrap]
    $xfs = [];
    $addXf = function ($fontIdx, $fillIdx, $borderIdx, $halign = 'left', $valign = 'center', $wrap = false) use (&$xfs) {
        $xfs[] = [$fontIdx, $fillIdx, $borderIdx, $halign, $valign, $wrap];
        return count($xfs) - 1;
    };
    $XF_DEFAULT  = $addXf(0, 0, 0, 'left', 'bottom', false);
    $XF_TITLE    = $addXf(1, 0, 0, 'center', 'center', true);
    $XF_HDRLABEL = $addXf(4, 4, 0, 'left', 'center', false);
    $XF_RED      = $addXf(2, 2, 1, 'left', 'center', false);
    // Sin wrap: las etiquetas largas (p. ej. "LUGAR DE TRABAJO (NOMBRE DE
    // EMPRESA O EMPLEADOR)") viven en una sola celda angosta sin fusionar,
    // con la altura de fila real de la plantilla (pensada para una sola
    // línea). Con wrapText=true el texto se partía en varias líneas dentro
    // de esa altura fija y se veía cortado/superpuesto con la fila de abajo.
    // Sin wrap, el texto sobreflota visualmente sobre las celdas vacías de
    // la derecha (comportamiento normal de Excel), igual que en la plantilla real.
    $XF_LABEL    = $addXf(4, 4, 1, 'left', 'center', false);
    $XF_INPUT_W  = $addXf(5, 4, 1, 'left', 'center', false);
    $XF_INPUT_G  = $addXf(5, 3, 1, 'left', 'center', false);
    $XF_PLAIN    = $addXf(6, 0, 0, 'left', 'top', true);

    // La clasificación label/input se basa en si la plantilla ya traía TEXTO
    // en esa celda (etiqueta de campo) o estaba en blanco (casilla a llenar),
    // no en si estaba en negrita — la plantilla real mezcla ambos casos.
    // Los párrafos largos (declaración legal, notas del croquis) usan un
    // estilo aparte (sin el look de "etiqueta de campo").
    $styleFor = function ($fill, $isLabel, $isParagraph = false) use ($XF_RED, $XF_LABEL, $XF_INPUT_W, $XF_INPUT_G, $XF_PLAIN, $XF_DEFAULT) {
        if ($isParagraph) return $XF_PLAIN;
        if ($fill === 'RED') return $XF_RED;
        if ($fill === 'WHITE') return $isLabel ? $XF_LABEL : $XF_INPUT_W;
        if ($fill === 'GRAY') return $isLabel ? $XF_LABEL : $XF_INPUT_G;
        return $XF_DEFAULT;
    };

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $stylesXml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $stylesXml .= '<numFmts count="0"/>';
    $stylesXml .= '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>';
    $stylesXml .= '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>';
    $stylesXml .= '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>';
    $stylesXml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $cellXfsXml = [];
    foreach ($xfs as [$fontIdx, $fillIdx, $borderIdx, $halign, $valign, $wrap]) {
        $align = '<alignment horizontal="' . $halign . '" vertical="' . $valign . '" wrapText="' . ($wrap ? 1 : 0) . '"/>';
        $cellXfsXml[] = '<xf numFmtId="0" fontId="' . $fontIdx . '" fillId="' . $fillIdx . '" borderId="' . $borderIdx . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">' . $align . '</xf>';
    }
    $stylesXml .= '<cellXfs count="' . count($cellXfsXml) . '">' . implode('', $cellXfsXml) . '</cellXfs>';
    $stylesXml .= '</styleSheet>';

    // ── Detectar filas "banner" (RED completo, todas las celdas rojas) para fusionarlas en una sola celda ──
    $bannerRows = [];
    foreach ($byRow as $r => $items) {
        $fillsSet = array_unique(array_column($items, 3));
        if ($fillsSet === ['RED']) $bannerRows[] = $r;
    }

    // Rangos que YA vienen fusionados en la plantilla real (para no
    // duplicar merges sobre celdas que ya son parte de uno).
    $existingMergeStarts = [];
    foreach ($mergesRaw as [$mr1, $mc1, $mr2, $mc2]) {
        $existingMergeStarts[$mr1 . '_' . $mc1] = true;
    }

    // ── Construir filas del sheet (9..142) ────────────────────
    // Además de pintar cada celda, esta pasada:
    //  1) Distingue etiqueta (texto propio de la plantilla) de casilla de
    //     input (blanco a llenar) para aplicar el estilo correcto.
    //  2) A las etiquetas de una sola columna que tienen espacio en blanco
    //     a su derecha (antes de la siguiente celda con contenido) las
    //     fusiona con ese espacio, para que el texto no se corte contra ni
    //     se sobreponga a la celda vecina.
    //  3) A los párrafos largos (declaración legal, notas del croquis) los
    //     trata aparte (estilo plano) y fusiona toda la fila.
    //  4) Calcula la altura de fila necesaria para que el texto envuelto
    //     ("wrap") quepa completo sin invadir la fila de abajo.
    $maxRow = 142;
    $rowsXml = array_fill(1, $maxRow, '');
    $dynamicMerges = [];
    for ($r = 9; $r <= $maxRow; $r++) {
        $rowCellsXml = '';
        $neededHeight = 0.0;
        $rowIsParagraph = false;
        if (isset($byRow[$r])) {
            $items = $byRow[$r];
            usort($items, fn($a, $b) => $a[0] <=> $b[0]);
            $n = count($items);
            foreach ($items as $idx => [$c1, $c2, $text, $fill, $bold]) {
                $ref = colLetter($c1) . $r;
                $isParagraph = ($fill === 'WHITE') && !empty($text) && mb_strlen($text) > 55;
                $isLabel = !empty($text) && !$isParagraph;
                if ($isParagraph) $rowIsParagraph = true;

                $val = $overrides[$ref] ?? $text;
                $sidx = $styleFor($fill, $isLabel, $isParagraph);
                if ($val === null || $val === '') {
                    $rowCellsXml .= '<c r="' . $ref . '" s="' . $sidx . '"/>';
                } else {
                    $sval = (string)$val;
                    if (strpos($sval, '=') === 0) {
                        $rowCellsXml .= '<c r="' . $ref . '" s="' . $sidx . '"><f>' . xesc(substr($sval, 1)) . '</f></c>';
                    } else {
                        $rowCellsXml .= '<c r="' . $ref . '" s="' . $sidx . '" t="inlineStr"><is><t xml:space="preserve">' . xesc($sval) . '</t></is></c>';
                    }
                }

                $endCol = $c2;
                if (($isLabel || $isParagraph) && $c1 === $c2 && !isset($existingMergeStarts[$r . '_' . $c1])) {
                    $nextC1 = ($idx + 1 < $n) ? $items[$idx + 1][0] : 15;
                    if ($nextC1 - 1 > $c1) {
                        $dynamicMerges[] = [$r, $c1, $r, $nextC1 - 1];
                        $endCol = $nextC1 - 1;
                    }
                }

                if ($isLabel && $text) {
                    $widthUnits = 0.0;
                    for ($cc = $c1; $cc <= $endCol; $cc++) $widthUnits += $colWidths[colLetter($cc)] ?? 10.0;
                    $charsPerLine = max((int)($widthUnits * 1.6), 8);
                    $estLines = (int)ceil(mb_strlen($text) / $charsPerLine);
                    if ($estLines > 1) {
                        $neededHeight = max($neededHeight, $estLines * 12.0 + 6.0);
                    }
                }
            }
        }
        $h = $rowHeights[$r] ?? 15.0;
        if ($rowIsParagraph) $h = max($h, 30.0);
        if ($neededHeight > 0) $h = max($h, $neededHeight);
        $rowsXml[$r] = '<row r="' . $r . '" ht="' . $h . '" customHeight="1">' . $rowCellsXml . '</row>';
    }

    // ── Filas 1-8: título, logos, asesor, DEUDOR/GARANTE ──────
    $mkCell = function ($ref, $text, $sidx) {
        if ($text === null || $text === '') return '<c r="' . $ref . '" s="' . $sidx . '"/>';
        return '<c r="' . $ref . '" s="' . $sidx . '" t="inlineStr"><is><t xml:space="preserve">' . xesc($text) . '</t></is></c>';
    };
    $topRows = [
        5 => $mkCell('D5', 'SOLICITUD DE CRÉDITO', $XF_TITLE) . $mkCell('M5', 'DEUDOR', $XF_HDRLABEL),
        6 => $mkCell('H6', 'ASESOR:', $XF_HDRLABEL) . $mkCell('I6', $asesorNombre, $XF_HDRLABEL) . $mkCell('M6', 'GARANTE', $XF_HDRLABEL),
    ];
    for ($r = 1; $r <= 8; $r++) {
        $h = $rowHeightsTop[$r] ?? 15.0;
        $rowsXml[$r] = '<row r="' . $r . '" ht="' . $h . '" customHeight="1">' . ($topRows[$r] ?? '') . '</row>';
    }

    $sheetData = implode('', $rowsXml);

    // ── Merges ──────────────────────────────────────────────
    $mergesAll = [[5, 4, 5, 11], [5, 13, 5, 14], [6, 13, 6, 14]];
    foreach ($mergesRaw as $m) $mergesAll[] = $m;
    foreach ($bannerRows as $r) $mergesAll[] = [$r, 1, $r, 14];
    foreach ($dynamicMerges as $m) $mergesAll[] = $m;
    $mergeXml = '';
    foreach ($mergesAll as [$r1, $c1, $r2, $c2]) {
        $mergeXml .= '<mergeCell ref="' . colLetter($c1) . $r1 . ':' . colLetter($c2) . $r2 . '"/>';
    }

    // ── Anchos de columna ───────────────────────────────────
    $colsXml = '';
    for ($i = 1; $i <= 14; $i++) {
        $letter = colLetter($i);
        $w = $colWidths[$letter] ?? 10.0;
        $colsXml .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:N' . $maxRow . '"/>'
        . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $colsXml . '</cols>'
        . '<sheetData>' . $sheetData . '</sheetData>'
        . '<mergeCells count="' . count($mergesAll) . '">' . $mergeXml . '</mergeCells>'
        . '<drawing r:id="rId1"/>'
        . '</worksheet>';

    $sheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
        . '</Relationships>';

    // ── Dibujo (logos incrustados, anclas 2 celdas tomadas de la plantilla real) ──
    $anchor = function ($idx, $name, $fromCol, $fromColOff, $fromRow, $fromRowOff, $toCol, $toColOff, $toRow, $toRowOff) {
        return '<xdr:twoCellAnchor xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing">'
            . '<xdr:from><xdr:col>' . $fromCol . '</xdr:col><xdr:colOff>' . $fromColOff . '</xdr:colOff><xdr:row>' . $fromRow . '</xdr:row><xdr:rowOff>' . $fromRowOff . '</xdr:rowOff></xdr:from>'
            . '<xdr:to><xdr:col>' . $toCol . '</xdr:col><xdr:colOff>' . $toColOff . '</xdr:colOff><xdr:row>' . $toRow . '</xdr:row><xdr:rowOff>' . $toRowOff . '</xdr:rowOff></xdr:to>'
            . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . ($idx + 1) . '" name="' . $name . '"/><xdr:cNvPicPr/></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" r:embed="rId' . ($idx + 1) . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></a:xfrm><a:prstGeom xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic></xdr:twoCellAnchor>';
    };
    $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . $anchor(0, 'logo_texto', 4, 336600, 0, 15840, 10, 72000, 3, 106920)
        . $anchor(1, 'logo_icono', 1, 7560, 0, 137520, 3, 305640, 4, 167760)
        . '</xdr:wsDr>';

    $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.jpeg"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image2.jpeg"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Solicitud" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $imgTexto = @file_get_contents(__DIR__ . '/assets/plantilla_logo_texto.jpg');
    $imgIcono = @file_get_contents(__DIR__ . '/assets/plantilla_logo_icono.jpg');

    $tmpPath = tempnam(sys_get_temp_dir(), 'sol_credito_') . '.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('No se pudo crear el archivo .xlsx temporal');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheetRels);
    $zip->addFromString('xl/drawings/drawing1.xml', $drawingXml);
    $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $drawingRels);
    if ($imgTexto !== false) $zip->addFromString('xl/media/image1.jpeg', $imgTexto);
    if ($imgIcono !== false) $zip->addFromString('xl/media/image2.jpeg', $imgIcono);
    $zip->close();

    $filenameSafe = 'Solicitud_Credito_' . preg_replace('/[^A-Za-z0-9_-]/', '', $cedula ?: $cliente_id) . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameSafe . '"');
    header('Content-Length: ' . filesize($tmpPath));
    header('Cache-Control: max-age=0');
    readfile($tmpPath);
    unlink($tmpPath);
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

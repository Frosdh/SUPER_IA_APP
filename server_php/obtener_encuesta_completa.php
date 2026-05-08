<?php
// ============================================================
// obtener_encuesta_completa.php  —  v2026-04-21a
// ------------------------------------------------------------
// Devuelve TODOS los datos de una tarea completada para poder
// cargar el formulario de la encuesta en modo "modificación".
//
// Input  (POST):
//   tarea_id      (obligatorio) UUID de la tarea a consultar.
//   usuario_id    (opcional)    para validar pertenencia.
//   asesor_id     (opcional)    para validar pertenencia.
//
// Output (JSON):
//   { status: 'success', data: {
//       cliente: {...}, tarea: {...},
//       encuesta_comercial: {...} | null,
//       encuesta_negocio:   {...} | null,
//       acuerdo_visita:     {...} | null
//   } }
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-04-21a';

function respond_json($code, $payload) {
    global $API_BUILD;
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (is_array($payload)) $payload['build'] = $API_BUILD;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status'=>'ok','message'=>'obtener_encuesta_completa alive','build'=>$API_BUILD]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ── Migración de esquema (Idempotente) ───────────────────────
try {
    // cliente_prospecto
    $cols_cp = [
        "ruc_val"            => "ALTER TABLE cliente_prospecto ADD COLUMN ruc_val VARCHAR(20) DEFAULT NULL",
        "rise_val"           => "ALTER TABLE cliente_prospecto ADD COLUMN rise_val VARCHAR(20) DEFAULT NULL",
        "tipo_empresa"       => "ALTER TABLE cliente_prospecto ADD COLUMN tipo_empresa VARCHAR(50) DEFAULT NULL",
        "regimen_tributario" => "ALTER TABLE cliente_prospecto ADD COLUMN regimen_tributario VARCHAR(20) DEFAULT NULL",
        "numero_ruc"         => "ALTER TABLE cliente_prospecto ADD COLUMN numero_ruc VARCHAR(20) DEFAULT NULL",
        "declara_iva"        => "ALTER TABLE cliente_prospecto ADD COLUMN declara_iva TINYINT(1) DEFAULT NULL",
        "emite_facturas"     => "ALTER TABLE cliente_prospecto ADD COLUMN emite_facturas TINYINT(1) DEFAULT NULL",
        "lleva_contabilidad" => "ALTER TABLE cliente_prospecto ADD COLUMN lleva_contabilidad TINYINT(1) DEFAULT NULL",
        "paga_cuota_rise"    => "ALTER TABLE cliente_prospecto ADD COLUMN paga_cuota_rise TINYINT(1) DEFAULT NULL",
        "emite_notas_venta"  => "ALTER TABLE cliente_prospecto ADD COLUMN emite_notas_venta TINYINT(1) DEFAULT NULL",
        "conoce_limite_rise" => "ALTER TABLE cliente_prospecto ADD COLUMN conoce_limite_rise TINYINT(1) DEFAULT NULL",
        "origen_prospecto"   => "ALTER TABLE cliente_prospecto ADD COLUMN origen_prospecto VARCHAR(20) DEFAULT NULL"
    ];
    foreach ($cols_cp as $col => $sql) {
        $chk = $conn->query("SHOW COLUMNS FROM cliente_prospecto LIKE '$col'");
        if ($chk && $chk->num_rows === 0) $conn->query($sql);
    }
    // encuesta_comercial
    $cols_ec = [
        "banco_ahorro"    => "ALTER TABLE encuesta_comercial ADD COLUMN banco_ahorro VARCHAR(100) DEFAULT NULL",
        "banco_corriente" => "ALTER TABLE encuesta_comercial ADD COLUMN banco_corriente VARCHAR(100) DEFAULT NULL"
    ];
    foreach ($cols_ec as $col => $sql) {
        $chk = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE '$col'");
        if ($chk && $chk->num_rows === 0) $conn->query($sql);
    }
} catch (\Throwable $eMig) {
    error_log('[obtener_encuesta_completa] Error en migración: ' . $eMig->getMessage());
}

$tarea_id   = trim($_POST['tarea_id']   ?? '');
$usuario_id = trim($_POST['usuario_id'] ?? '');
$asesor_id  = trim($_POST['asesor_id']  ?? '');

if ($tarea_id === '') {
    respond_json(200, ['status'=>'error','message'=>'tarea_id requerido']);
    exit;
}

try {
    // ── 1. Tarea + cliente_prospecto ─────────────────────────────
    $sql = "
        SELECT
            t.id                          AS tarea_id,
            t.asesor_id                   AS tarea_asesor_id,
            t.cliente_prospecto_id        AS cliente_id,
            t.tipo_tarea,
            t.estado,
            t.fecha_programada,
            t.hora_programada,
            t.fecha_realizada,
            t.hora_realizada,
            t.latitud_inicio,
            t.longitud_inicio,
            t.latitud_fin,
            t.longitud_fin,
            t.observaciones               AS tarea_observaciones,
            cp.id                         AS cp_id,
            cp.nombre                     AS cp_nombre,
            cp.cedula                     AS cp_cedula,
            cp.telefono                   AS cp_telefono,
            cp.telefono2                  AS cp_celular,
            cp.email                      AS cp_email,
            cp.direccion                  AS cp_direccion,
            cp.ciudad                     AS cp_ciudad,
            cp.actividad                  AS cp_actividad,
            cp.nombre_empresa             AS cp_nombre_empresa,
            cp.tiene_ruc                  AS cp_tiene_ruc,
            cp.tiene_rise                 AS cp_tiene_rise,
            cp.ruc_val                    AS cp_ruc_val,
            cp.rise_val                   AS cp_rise_val,
            cp.tipo_empresa               AS cp_tipo_empresa,
            cp.regimen_tributario         AS cp_regimen_tributario,
            cp.numero_ruc                 AS cp_numero_ruc,
            cp.declara_iva                AS cp_declara_iva,
            cp.emite_facturas             AS cp_emite_facturas,
            cp.lleva_contabilidad         AS cp_lleva_contabilidad,
            cp.paga_cuota_rise            AS cp_paga_cuota_rise,
            cp.emite_notas_venta          AS cp_emite_notas_venta,
            cp.conoce_limite_rise         AS cp_conoce_limite_rise,
            cp.origen_prospecto           AS cp_origen_prospecto,
            cp.estado                     AS cp_estado,
            cp.latitud                    AS cp_latitud,
            cp.longitud                   AS cp_longitud
        FROM tarea t
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE t.id = ?
        LIMIT 1
    ";

    $st = $conn->prepare($sql);
    $st->bind_param('s', $tarea_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        respond_json(200, ['status'=>'error','message'=>'Tarea no encontrada']);
        exit;
    }

    // Validar pertenencia opcional al asesor
    if ($asesor_id !== '' && $row['tarea_asesor_id'] !== $asesor_id) {
        respond_json(200, ['status'=>'error','message'=>'La tarea no pertenece a este asesor']);
        exit;
    }

    $cliente = [
        'id'               => (string)($row['cp_id']               ?? ''),
        'nombre'           => (string)($row['cp_nombre']           ?? ''),
        'cedula'           => (string)($row['cp_cedula']           ?? ''),
        'telefono'         => (string)($row['cp_telefono']         ?? ''),
        'celular'          => (string)($row['cp_celular']          ?? ''),
        'email'            => (string)($row['cp_email']            ?? ''),
        'direccion'        => (string)($row['cp_direccion']        ?? ''),
        'ciudad'           => (string)($row['cp_ciudad']           ?? ''),
        'actividad'        => (string)($row['cp_actividad']        ?? ''),
        'nombre_empresa'   => (string)($row['cp_nombre_empresa']   ?? ''),
        'tiene_ruc'        => (int)   ($row['cp_tiene_ruc']        ?? 0),
        'tiene_rise'       => (int)   ($row['cp_tiene_rise']       ?? 0),
        'ruc_val'          => (string)($row['cp_ruc_val']         ?? ''),
        'rise_val'         => (string)($row['cp_rise_val']        ?? ''),
        'tipo_empresa'     => (string)($row['cp_tipo_empresa']    ?? ''),
        'regimen_tributario' => (string)($row['cp_regimen_tributario'] ?? ''),
        'numero_ruc'       => (string)($row['cp_numero_ruc']       ?? ''),
        'declara_iva'      => isset($row['cp_declara_iva']) ? (int)$row['cp_declara_iva'] : null,
        'emite_facturas'   => isset($row['cp_emite_facturas']) ? (int)$row['cp_emite_facturas'] : null,
        'lleva_contabilidad' => isset($row['cp_lleva_contabilidad']) ? (int)$row['cp_lleva_contabilidad'] : null,
        'paga_cuota_rise'  => isset($row['cp_paga_cuota_rise']) ? (int)$row['cp_paga_cuota_rise'] : null,
        'emite_notas_venta' => isset($row['cp_emite_notas_venta']) ? (int)$row['cp_emite_notas_venta'] : null,
        'conoce_limite_rise' => isset($row['cp_conoce_limite_rise']) ? (int)$row['cp_conoce_limite_rise'] : null,
        'origen_prospecto' => (string)($row['cp_origen_prospecto'] ?? ''),
        'estado'           => (string)($row['cp_estado']           ?? ''),
        'latitud'          => $row['cp_latitud']  !== null ? (float)$row['cp_latitud']  : null,
        'longitud'         => $row['cp_longitud'] !== null ? (float)$row['cp_longitud'] : null,
    ];

    $tarea = [
        'id'               => (string)($row['tarea_id'] ?? ''),
        'asesor_id'        => (string)($row['tarea_asesor_id'] ?? ''),
        'cliente_id'       => (string)($row['cliente_id'] ?? ''),
        'tipo_tarea'       => (string)($row['tipo_tarea'] ?? ''),
        'estado'           => (string)($row['estado'] ?? ''),
        'fecha_programada' => (string)($row['fecha_programada'] ?? ''),
        'hora_programada'  => (string)($row['hora_programada']  ?? ''),
        'fecha_realizada'  => (string)($row['fecha_realizada']  ?? ''),
        'hora_realizada'   => (string)($row['hora_realizada']   ?? ''),
        'latitud_inicio'   => $row['latitud_inicio']  !== null ? (float)$row['latitud_inicio']  : null,
        'longitud_inicio'  => $row['longitud_inicio'] !== null ? (float)$row['longitud_inicio'] : null,
        'latitud_fin'      => $row['latitud_fin']     !== null ? (float)$row['latitud_fin']     : null,
        'longitud_fin'     => $row['longitud_fin']    !== null ? (float)$row['longitud_fin']    : null,
        'observaciones'    => (string)($row['tarea_observaciones'] ?? ''),
    ];

    // ── 2. Encuesta comercial ────────────────────────────────────
    $encuesta_comercial = null;
    try {
        $st = $conn->prepare("SELECT * FROM encuesta_comercial WHERE tarea_id = ? ORDER BY id DESC LIMIT 1");
        $st->bind_param('s', $tarea_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r) {
            $encuesta_comercial = [
                'id'                            => (string)($r['id'] ?? ''),
                'mantiene_cuenta_ahorro'        => (int)($r['mantiene_cuenta_ahorro']        ?? 0),
                'mantiene_cuenta_corriente'     => (int)($r['mantiene_cuenta_corriente']     ?? 0),
                'tiene_inversiones'             => isset($r['tiene_inversiones']) && $r['tiene_inversiones'] !== null ? (int)$r['tiene_inversiones'] : null,
                'institucion_inversiones'       => (string)($r['institucion_inversiones']    ?? ''),
                'valor_inversion'               => $r['valor_inversion'] !== null ? (float)$r['valor_inversion'] : null,
                'plazo_inversion'               => (string)($r['plazo_inversion']            ?? ''),
                'fecha_vencimiento_inversion'   => (string)($r['fecha_vencimiento_inversion']?? ''),
                'interes_propuesta_previa'      => isset($r['interes_propuesta_previa']) && $r['interes_propuesta_previa'] !== null ? (int)$r['interes_propuesta_previa'] : null,
                'fecha_nuevo_contacto'          => (string)($r['fecha_nuevo_contacto']       ?? ''),
                'tiene_operaciones_crediticias' => isset($r['tiene_operaciones_crediticias']) && $r['tiene_operaciones_crediticias'] !== null ? (int)$r['tiene_operaciones_crediticias'] : null,
                'institucion_credito'           => (string)($r['institucion_credito']        ?? ''),
                'interes_conocer_productos'     => isset($r['interes_conocer_productos']) && $r['interes_conocer_productos'] !== null ? (int)$r['interes_conocer_productos'] : null,
                'nivel_interes_captado'         => (string)($r['nivel_interes_captado']      ?? ''),
                'interes_cc'                    => (int)($r['interes_cc']                    ?? 0),
                'interes_ahorro'                => (int)($r['interes_ahorro']                ?? 0),
                'interes_inversion'             => (int)($r['interes_inversion']             ?? 0),
                'interes_credito'               => (int)($r['interes_credito']               ?? 0),
                'razon_ya_trabaja_institucion'  => (int)($r['razon_ya_trabaja_institucion']  ?? 0),
                'razon_desconfia_servicios'     => (int)($r['razon_desconfia_servicios']     ?? 0),
                'razon_agusto_actual'           => (int)($r['razon_agusto_actual']           ?? 0),
                'razon_mala_experiencia'        => (int)($r['razon_mala_experiencia']        ?? 0),
                'razon_otros'                   => (string)($r['razon_otros']                ?? ''),
                'acuerdo_logrado'               => (string)($r['acuerdo_logrado']            ?? 'ninguno'),
                'fecha_acuerdo'                 => (string)($r['fecha_acuerdo']              ?? ''),
                'hora_acuerdo'                  => (string)($r['hora_acuerdo']               ?? ''),
                'observaciones'                 => (string)($r['observaciones']              ?? ''),
                'que_busca_agilidad'            => (int)($r['que_busca_agilidad']            ?? 0),
                'que_busca_cajeros'             => (int)($r['que_busca_cajeros']             ?? 0),
                'que_busca_banca_linea'         => (int)($r['que_busca_banca_linea']         ?? 0),
                'que_busca_agencias'            => (int)($r['que_busca_agencias']            ?? 0),
                'que_busca_credito_rapido'      => (int)($r['que_busca_credito_rapido']      ?? 0),
                'que_busca_tarjeta_debito'      => (int)($r['que_busca_tarjeta_debito']      ?? 0),
                'que_busca_tarjeta_credito'     => (int)($r['que_busca_tarjeta_credito']     ?? 0),
                'interes_trabajar_institucion'  => isset($r['interes_trabajar_institucion']) && $r['interes_trabajar_institucion'] !== null ? (int)$r['interes_trabajar_institucion'] : null,
                'fecha_vencimiento_cdp'         => (string)($r['fecha_vencimiento_cdp']      ?? ''),
                'banco_ahorro'                  => (string)($r['banco_ahorro']               ?? ''),
                'banco_corriente'               => (string)($r['banco_corriente']            ?? ''),
            ];
        }
    } catch (\Throwable $eEc) {
        error_log('[obtener_encuesta_completa] encuesta_comercial: ' . $eEc->getMessage());
    }

    // ── 3. Encuesta negocio ──────────────────────────────────────
    $encuesta_negocio = null;
    try {
        $st = $conn->prepare("SELECT * FROM encuesta_negocio WHERE tarea_id = ? ORDER BY id DESC LIMIT 1");
        $st->bind_param('s', $tarea_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r) {
            $encuesta_negocio = [
                'id'                   => (string)($r['id'] ?? ''),
                'venta_lv'             => $r['venta_lv']      !== null ? (float)$r['venta_lv']      : null,
                'venta_sabado'         => $r['venta_sabado']  !== null ? (float)$r['venta_sabado']  : null,
                'venta_domingo'        => $r['venta_domingo'] !== null ? (float)$r['venta_domingo'] : null,
                'mes_alta_venta'       => (string)($r['mes_alta_venta']   ?? ''),
                'mes_baja_venta'       => (string)($r['mes_baja_venta']   ?? ''),
                'compra_lv'            => $r['compra_lv']      !== null ? (float)$r['compra_lv']      : null,
                'compra_sabado'        => $r['compra_sabado']  !== null ? (float)$r['compra_sabado']  : null,
                'compra_domingo'       => $r['compra_domingo'] !== null ? (float)$r['compra_domingo'] : null,
                'mes_alta_compra'      => (string)($r['mes_alta_compra']   ?? ''),
                'dia_lv'               => (int)($r['dia_lv']  ?? 0),
                'dia_sab'              => (int)($r['dia_sab'] ?? 0),
                'dia_dom'              => (int)($r['dia_dom'] ?? 0),
                'pct_contado'          => isset($r['pct_contado']) && $r['pct_contado'] !== null ? (int)$r['pct_contado'] : null,
                'pct_credito'          => isset($r['pct_credito'])  && $r['pct_credito']  !== null ? (int)$r['pct_credito']  : null,
                'pct_efectivo'         => isset($r['pct_efectivo']) && $r['pct_efectivo'] !== null ? (int)$r['pct_efectivo'] : null,
                'recuperacion_credito' => $r['recuperacion_credito'] !== null ? (float)$r['recuperacion_credito'] : null,
                'costos_ventas'        => $r['costos_ventas']        !== null ? (float)$r['costos_ventas']        : null,
                'gastos_negocio'       => $r['gastos_negocio']       !== null ? (float)$r['gastos_negocio']       : null,
                'otros_ingresos'       => $r['otros_ingresos']       !== null ? (float)$r['otros_ingresos']       : null,
                'gastos_familiares'    => $r['gastos_familiares']    !== null ? (float)$r['gastos_familiares']    : null,
                'g_neg_sueldos'        => isset($r['g_neg_sueldos'])       ? (float)($r['g_neg_sueldos']       ?? 0) : null,
                'g_neg_arriendo'       => isset($r['g_neg_arriendo'])      ? (float)($r['g_neg_arriendo']      ?? 0) : null,
                'g_neg_serv_bas'       => isset($r['g_neg_serv_bas'])      ? (float)($r['g_neg_serv_bas']      ?? 0) : null,
                'g_neg_transporte'     => isset($r['g_neg_transporte'])    ? (float)($r['g_neg_transporte']    ?? 0) : null,
                'g_neg_mantenimiento'  => isset($r['g_neg_mantenimiento']) ? (float)($r['g_neg_mantenimiento'] ?? 0) : null,
                'g_neg_otros'          => isset($r['g_neg_otros'])         ? (float)($r['g_neg_otros']         ?? 0) : null,
                'g_neg_imprevistos'    => isset($r['g_neg_imprevistos'])   ? (float)($r['g_neg_imprevistos']   ?? 0) : null,
                'o_ing_conyuge'        => isset($r['o_ing_conyuge'])       ? (float)($r['o_ing_conyuge']       ?? 0) : null,
                'o_ing_arriendos'      => isset($r['o_ing_arriendos'])     ? (float)($r['o_ing_arriendos']     ?? 0) : null,
                'o_ing_pensiones'      => isset($r['o_ing_pensiones'])     ? (float)($r['o_ing_pensiones']     ?? 0) : null,
                'o_ing_otros'          => isset($r['o_ing_otros'])         ? (float)($r['o_ing_otros']         ?? 0) : null,
                'g_fam_alim'           => isset($r['g_fam_alim'])          ? (float)($r['g_fam_alim']          ?? 0) : null,
                'g_fam_arriendo'       => isset($r['g_fam_arriendo'])      ? (float)($r['g_fam_arriendo']      ?? 0) : null,
                'g_fam_serv_bas'       => isset($r['g_fam_serv_bas'])      ? (float)($r['g_fam_serv_bas']      ?? 0) : null,
                'g_fam_educacion'      => isset($r['g_fam_educacion'])     ? (float)($r['g_fam_educacion']     ?? 0) : null,
                'g_fam_salud'          => isset($r['g_fam_salud'])         ? (float)($r['g_fam_salud']         ?? 0) : null,
                'g_fam_otros'          => isset($r['g_fam_otros'])         ? (float)($r['g_fam_otros']         ?? 0) : null,
                'g_fam_imprevistos'    => isset($r['g_fam_imprevistos'])   ? (float)($r['g_fam_imprevistos']   ?? 0) : null,
                'otras_deudas_json'       => $r['otras_deudas_json']      ?? null,
                'vehiculos_negocio_json'  => $r['vehiculos_negocio_json'] ?? null,
                'vehiculos_hogar_json'    => $r['vehiculos_hogar_json']   ?? null,
                'inmuebles_negocio_json'  => $r['inmuebles_negocio_json'] ?? null,
                'inmuebles_hogar_json'    => $r['inmuebles_hogar_json']   ?? null,
                // Ventas/compras/días por día individual
                'venta_lunes'            => isset($r['venta_lunes'])     ? (float)$r['venta_lunes']     : null,
                'venta_martes'           => isset($r['venta_martes'])    ? (float)$r['venta_martes']    : null,
                'venta_miercoles'        => isset($r['venta_miercoles']) ? (float)$r['venta_miercoles'] : null,
                'venta_jueves'           => isset($r['venta_jueves'])    ? (float)$r['venta_jueves']    : null,
                'venta_viernes'          => isset($r['venta_viernes'])   ? (float)$r['venta_viernes']   : null,
                'compra_lunes'           => isset($r['compra_lunes'])    ? (float)$r['compra_lunes']    : null,
                'compra_martes'          => isset($r['compra_martes'])   ? (float)$r['compra_martes']   : null,
                'compra_miercoles'       => isset($r['compra_miercoles'])? (float)$r['compra_miercoles']: null,
                'compra_jueves'          => isset($r['compra_jueves'])   ? (float)$r['compra_jueves']   : null,
                'compra_viernes'         => isset($r['compra_viernes'])  ? (float)$r['compra_viernes']  : null,
                // Claves cortas que Flutter lee: n['dia_lun'], n['dia_mar'], etc.
                'dia_lun'                => isset($r['dia_lunes'])    ? (int)$r['dia_lunes']    : 0,
                'dia_mar'                => isset($r['dia_martes'])   ? (int)$r['dia_martes']   : 0,
                'dia_mie'                => isset($r['dia_miercoles'])? (int)$r['dia_miercoles']: 0,
                'dia_jue'                => isset($r['dia_jueves'])   ? (int)$r['dia_jueves']   : 0,
                'dia_vie'                => isset($r['dia_viernes'])  ? (int)$r['dia_viernes']  : 0,
                // Productos, activos y comercio (JSON)
                'comercio_productos_json' => $r['comercio_productos_json'] ?? null,
                'productos_json'          => $r['productos_json']          ?? null,
                'activos_negocio_json'    => $r['activos_negocio_json']    ?? null,
                'activos_hogar_json'      => $r['activos_hogar_json']      ?? null,
                // Balance General
                'caja_efectivo'           => isset($r['caja_efectivo']) ? (float)$r['caja_efectivo'] : 0,
                'bancos_saldo'            => isset($r['bancos_saldo'])  ? (float)$r['bancos_saldo']  : 0,
                'cxp_netas'               => isset($r['cxp_netas'])     ? (float)$r['cxp_netas']     : 0,
                'inv_mat_prima'           => isset($r['inv_mat_prima']) ? (float)$r['inv_mat_prima'] : 0,
                'inv_prod_proc'           => isset($r['inv_prod_proc']) ? (float)$r['inv_prod_proc'] : 0,
                // Pasivo de la empresa
                'creditos_pagar'          => isset($r['creditos_pagar'])   ? (float)$r['creditos_pagar']   : 0,
                'proveedores'             => isset($r['proveedores'])       ? (float)$r['proveedores']       : 0,
                'otras_deudas_cp'         => isset($r['otras_deudas_cp'])   ? (float)$r['otras_deudas_cp']   : 0,
                'pasivos_lp'              => isset($r['pasivos_lp'])        ? (float)$r['pasivos_lp']        : 0,
                // Preguntas de identificación institucional
                'p1_conoce_institucion'   => isset($r['p1_conoce_institucion']) ? (int)$r['p1_conoce_institucion'] : null,
                'p1_obs'                  => $r['p1_obs'] ?? '',
                'p2_es_cliente'           => isset($r['p2_es_cliente']) ? (int)$r['p2_es_cliente'] : null,
                'p2_producto'             => $r['p2_producto'] ?? '',
                'p2_obs'                  => $r['p2_obs'] ?? '',
                'p3_satisfaccion'         => $r['p3_satisfaccion'] ?? '',
                'p3_obs'                  => $r['p3_obs'] ?? '',
            ];
        }
    } catch (\Throwable $eEn) {
        error_log('[obtener_encuesta_completa] encuesta_negocio: ' . $eEn->getMessage());
    }

    // ── 4. Acuerdo de visita ─────────────────────────────────────
    $acuerdo_visita = null;
    try {
        $st = $conn->prepare("SELECT * FROM acuerdo_visita WHERE tarea_id = ? ORDER BY id DESC LIMIT 1");
        $st->bind_param('s', $tarea_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r) {
            $acuerdo_visita = [
                'id'          => (string)($r['id'] ?? ''),
                'tipo_acuerdo'=> (string)($r['tipo_acuerdo'] ?? ''),
                'fecha'       => (string)($r['fecha'] ?? ''),
                'hora'        => (string)($r['hora']  ?? ''),
            ];
        }
    } catch (\Throwable $eAv) {
        error_log('[obtener_encuesta_completa] acuerdo_visita: ' . $eAv->getMessage());
    }

    respond_json(200, [
        'status' => 'success',
        'data'   => [
            'cliente'            => $cliente,
            'tarea'              => $tarea,
            'encuesta_comercial' => $encuesta_comercial,
            'encuesta_negocio'   => $encuesta_negocio,
            'acuerdo_visita'     => $acuerdo_visita,
        ],
    ]);

} catch (\Throwable $e) {
    error_log('[obtener_encuesta_completa] ' . $e->getMessage());
    respond_json(200, [
        'status'  => 'error',
        'message' => 'Error del servidor: ' . substr($e->getMessage(), 0, 200),
    ]);
} finally {
    if (isset($conn)) { try { $conn->close(); } catch (\Throwable $_) {} }
}
?>

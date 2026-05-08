<?php
// ============================================================
// guardar_cliente_encuesta.php  —  v2026-04-14b  (FIXED)
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-05-07g';
$GLOBALS['phase'] = 'BOOT';

// ── Helpers JSON y UUID ──────────────────────────────────────
function respond_json($code, $payload) {
    global $API_BUILD;
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (is_array($payload)) $payload['build'] = $API_BUILD;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function genUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

function strOrNull($v): ?string {
    $v = trim((string)$v);
    return $v !== '' ? $v : null;
}
function intOrNull($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}
function floatOrNull($v): ?float {
    if ($v === null || $v === '') return null;
    return (float)$v;
}

// ── Shutdown: nunca devolver body vacío ──────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type']??0), [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR])) return;
    $phase = $GLOBALS['phase'] ?? 'UNKNOWN';
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    $msg = substr($err['message']??'', 0, 180);
    echo json_encode(['status'=>'error','message'=>"Error interno [$phase]: $msg",'phase'=>$phase]);
});

// ── Ping GET ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status'=>'ok','message'=>'guardar_cliente_encuesta alive','build'=>$API_BUILD,'php'=>PHP_VERSION]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

// ── Migración de ENUMs ────────────────────────────────────────────────────────
// Corre ANTES de begin_transaction(). PHP 8.1+ activa mysqli_report STRICT por
// defecto, así que usamos try/catch (@ no suprime excepciones de mysqli).
// Paso 1: limpiar filas con valores antiguos que ya no estarán en el ENUM.
// Paso 2: ampliar el ENUM para aceptar los 5 valores válidos.
$_ev = "'nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro'";
$_ed = "ENUM($_ev)";
// Limpiar filas con valores inválidos antes de ampliar el ENUM (tablas nullable)
try { $conn->query("UPDATE encuesta_comercial  SET acuerdo_logrado=NULL WHERE acuerdo_logrado  IS NOT NULL AND acuerdo_logrado  NOT IN ($_ev)"); } catch (\Throwable $e) {}
try { $conn->query("UPDATE encuesta_crediticia SET acuerdo_logrado=NULL WHERE acuerdo_logrado  IS NOT NULL AND acuerdo_logrado  NOT IN ($_ev)"); } catch (\Throwable $e) {}
// Ampliar ENUM de las tablas nullable
try { $conn->query("ALTER TABLE encuesta_comercial  MODIFY COLUMN acuerdo_logrado $_ed NULL"); } catch (\Throwable $e) {}
try { $conn->query("ALTER TABLE encuesta_crediticia MODIFY COLUMN acuerdo_logrado $_ed NULL"); } catch (\Throwable $e) {}
// acuerdo_visita: convertir tipo_acuerdo a VARCHAR(30) para evitar conflictos de ENUM
// (VARCHAR acepta cualquier valor, es idempotente correrlo varias veces)
try { $conn->query("ALTER TABLE acuerdo_visita MODIFY COLUMN tipo_acuerdo VARCHAR(30) NOT NULL"); } catch (\Throwable $e) {}

// ── Migración ENUM tarea.tipo_tarea ──────────────────────────────────────────
// Ampliar el ENUM para soportar los tipos de seguimiento que genera la encuesta.
try {
    $conn->query("ALTER TABLE tarea MODIFY COLUMN tipo_tarea ENUM(
        'prospecto_nuevo','visita_frio','evaluacion','recuperacion',
        'post_venta','represtamo','documentos_pendientes',
        'nueva_cita_campo','nueva_cita_oficina','nueva_cita_inversion',
        'levantamiento','seguimiento'
    ) NOT NULL DEFAULT 'prospecto_nuevo'");
} catch (\Throwable $e) { 
    error_log("[MIGRACION] Falló ALTER TABLE tarea: " . $e->getMessage());
}

// ── Migración de Colación en encuesta_negocio ────────────────────────────────
// Prevenir error: Illegal mix of collations (utf8mb4_general_ci vs utf8mb4_unicode_ci)
try {
    $conn->query("ALTER TABLE encuesta_negocio CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (\Throwable $e) {
    error_log("[MIGRACION] Falló ALTER COLLATION encuesta_negocio: " . $e->getMessage());
}

// ── Migración segura de columnas en cliente_prospecto ─────────
$cols_cp = [
    'ruc_val'            => "VARCHAR(20) DEFAULT NULL",
    'rise_val'           => "VARCHAR(20) DEFAULT NULL",
    'tipo_empresa'       => "VARCHAR(50) DEFAULT NULL",
    'regimen_tributario' => "VARCHAR(20) DEFAULT NULL",
    'numero_ruc'         => "VARCHAR(20) DEFAULT NULL",
    'nombre_empresa'     => "VARCHAR(150) DEFAULT NULL",
    'declara_iva'        => "TINYINT(1) DEFAULT NULL",
    'emite_facturas'     => "TINYINT(1) DEFAULT NULL",
    'lleva_contabilidad' => "TINYINT(1) DEFAULT NULL",
    'paga_cuota_rise'    => "TINYINT(1) DEFAULT NULL",
    'emite_notas_venta'  => "TINYINT(1) DEFAULT NULL",
    'conoce_limite_rise' => "TINYINT(1) DEFAULT NULL",
    'tiene_empresa'      => "TINYINT(1) DEFAULT 0",
];
foreach ($cols_cp as $col => $def) {
    $r = $conn->query("SHOW COLUMNS FROM cliente_prospecto LIKE '$col'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN $col $def");
}

// ── Migración segura de columnas en encuesta_comercial ────────
$cols_ec = [
    'que_busca_agilidad'           => "TINYINT(1) NOT NULL DEFAULT 0",
    'que_busca_cajeros'            => "TINYINT(1) NOT NULL DEFAULT 0",
    'que_busca_banca_linea'        => "TINYINT(1) NOT NULL DEFAULT 0",
    'que_busca_agencias'           => "TINYINT(1) NOT NULL DEFAULT 0",
    'que_busca_credito_rapido'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'que_busca_tarjeta_debito'     => "TINYINT(1) NOT NULL DEFAULT 0",
    'que_busca_tarjeta_credito'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'interes_trabajar_institucion' => "TINYINT(1) DEFAULT NULL",
    'interes_conocer_servicios'    => "TINYINT(1) DEFAULT NULL",
    'banco_ahorro'                 => "VARCHAR(200) DEFAULT NULL",
    'banco_corriente'              => "VARCHAR(200) DEFAULT NULL",
    'fecha_vencimiento_cdp'        => "DATE DEFAULT NULL",
    // Preguntas de identificación institucional
    'p1_conoce_institucion'        => "TINYINT(1) DEFAULT NULL",
    'p1_obs'                       => "TEXT DEFAULT NULL",
    'p2_es_cliente'                => "TINYINT(1) DEFAULT NULL",
    'p2_producto'                  => "VARCHAR(200) DEFAULT NULL",
    'p2_obs'                       => "TEXT DEFAULT NULL",
    'p3_satisfaccion'              => "VARCHAR(50) DEFAULT NULL",
    'p3_obs'                       => "TEXT DEFAULT NULL",
];
foreach ($cols_ec as $col => $def) {
    $r = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE '$col'");
    if ($r && $r->num_rows === 0) $conn->query("ALTER TABLE encuesta_comercial ADD COLUMN $col $def");
}
// Ampliar ENUM acuerdo_logrado si falta tasas_competitivas
try {
    $colAc = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE 'acuerdo_logrado'")->fetch_assoc();
    if ($colAc && strpos($colAc['Type'], "'tasas_competitivas'") === false) {
        $conn->query("ALTER TABLE encuesta_comercial MODIFY COLUMN acuerdo_logrado
            ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion',
                 'seguimiento','otro','tasas_competitivas') DEFAULT NULL");
    }
} catch (\Throwable $e) {}

// ── Leer parámetros ──────────────────────────────────────────
$usuario_id      = trim($_POST['usuario_id']   ?? '');
$asesor_id_in    = trim($_POST['asesor_id']    ?? '');
$tipo_tarea      = trim($_POST['tipo_tarea']   ?? 'prospecto_nuevo');
$fue_encuestado  = (int)($_POST['fue_encuestado'] ?? 1);

// Cliente
$nombre          = trim($_POST['nombre']    ?? '');
$apellidos       = trim($_POST['apellidos'] ?? '');
$nombre_completo = trim("$nombre $apellidos");
if ($nombre_completo === '') $nombre_completo = $nombre;
$cedula          = strOrNull($_POST['cedula']        ?? '');
$telefono        = strOrNull($_POST['telefono']       ?? '');
$celular         = strOrNull($_POST['celular']        ?? '');
$email_c         = strOrNull($_POST['email_cliente']  ?? '');
$direccion       = strOrNull($_POST['direccion']      ?? '');
$ciudad          = strOrNull($_POST['ciudad']         ?? '');
$actividad       = strOrNull($_POST['actividad']      ?? '');
$tiene_ruc       = (int)($_POST['tiene_ruc']          ?? 0);
$tiene_rise      = (int)($_POST['tiene_rise']         ?? 0);
$ruc_val         = strOrNull($_POST['ruc_val']        ?? '');
$rise_val        = strOrNull($_POST['rise_val']       ?? '');
$tipo_empresa    = strOrNull($_POST['tipo_empresa']    ?? '');
$nombre_empresa  = strOrNull($_POST['nombre_empresa'] ?? '');

$regimen_tributario = strOrNull($_POST['regimen_tributario'] ?? '');
$numero_ruc         = strOrNull($_POST['numero_ruc']         ?? '');
$declara_iva        = intOrNull($_POST['declara_iva']        ?? null);
$emite_facturas     = intOrNull($_POST['emite_facturas']     ?? null);
$lleva_contabilidad = intOrNull($_POST['lleva_contabilidad'] ?? null);
$paga_cuota_rise    = intOrNull($_POST['paga_cuota_rise']    ?? null);
$emite_notas_venta  = intOrNull($_POST['emite_notas_venta']  ?? null);
$conoce_limite_rise = intOrNull($_POST['conoce_limite_rise'] ?? null);

// Origen del prospecto
$origen_prospecto = strOrNull($_POST['origen_prospecto'] ?? '');
if ($origen_prospecto !== null) {
    $origen_prospecto = strtolower($origen_prospecto);
    $origen_ok = ['frio','seguidor'];
    if (!in_array($origen_prospecto, $origen_ok, true)) $origen_prospecto = null;
}

// Validar actividad (debe coincidir con ENUM de cliente_prospecto.actividad)
$acts_ok = ['negocio_propio','empleado_privado','empleado_publico','profesional'];
if ($actividad !== null && !in_array($actividad, $acts_ok, true)) $actividad = null;

// GPS (se guardan como float nullable)
$lat_ini = floatOrNull($_POST['latitud_inicio']  ?? '');
$lng_ini = floatOrNull($_POST['longitud_inicio'] ?? '');
$lat_fin = floatOrNull($_POST['latitud_fin']     ?? '');
$lng_fin = floatOrNull($_POST['longitud_fin']    ?? '');

// Encuesta
$mantiene_ahorro    = (int)($_POST['mantiene_cuenta_ahorro']    ?? 0);
$mantiene_corriente = (int)($_POST['mantiene_cuenta_corriente'] ?? 0);
$tiene_inversiones  = intOrNull($_POST['tiene_inversiones']     ?? null);
$inst_inv           = strOrNull($_POST['institucion_inversiones'] ?? '');
$valor_inv          = floatOrNull($_POST['valor_inversion']     ?? '');
$plazo_inv          = strOrNull($_POST['plazo_inversion']       ?? '');
$fecha_venc_inv     = strOrNull($_POST['fecha_vencimiento_inversion'] ?? '');
$tiene_ops_cred     = intOrNull($_POST['tiene_operaciones_crediticias'] ?? null);
$inst_cred          = strOrNull($_POST['institucion_credito']   ?? '');
$inst_prod_fin      = strOrNull($_POST['institucion_producto_financiero'] ?? '');
$interes_conocer    = intOrNull($_POST['interes_conocer_productos'] ?? null);
$nivel_interes      = strOrNull($_POST['nivel_interes'] ?? '') ?? 'ninguno';
$interes_cc         = (int)($_POST['interes_cc']        ?? 0);
$interes_ahorro     = (int)($_POST['interes_ahorro']    ?? 0);
$interes_inv        = (int)($_POST['interes_inversion'] ?? 0);
$interes_cred       = (int)($_POST['interes_credito']   ?? 0);
$razon_ya_trabaja   = (int)($_POST['razon_ya_trabaja_institucion'] ?? 0);
$razon_desconfia    = (int)($_POST['razon_desconfia_servicios']   ?? 0);
$razon_agusto       = (int)($_POST['razon_agusto_actual']          ?? 0);
$razon_mala_exp     = (int)($_POST['razon_mala_experiencia']       ?? 0);
$razon_otros        = strOrNull($_POST['razon_otros'] ?? '');
$busca_agilidad     = (int)($_POST['que_busca_agilidad']       ?? 0);
$busca_cajeros      = (int)($_POST['que_busca_cajeros']         ?? 0);
$busca_banca        = (int)($_POST['que_busca_banca_linea']     ?? 0);
$busca_agencias     = (int)($_POST['que_busca_agencias']        ?? 0);
$busca_credito      = (int)($_POST['que_busca_credito_rapido']  ?? 0);
$busca_td           = (int)($_POST['que_busca_tarjeta_debito']  ?? 0);
$busca_tc           = (int)($_POST['que_busca_tarjeta_credito'] ?? 0);
$fecha_venc_cdp     = strOrNull($_POST['fecha_vencimiento_cdp'] ?? '');
$interes_trabajar   = intOrNull($_POST['interes_trabajar_institucion'] ?? null);
$_acuerdo_raw       = strOrNull($_POST['acuerdo_logrado'] ?? '');
$acuerdo            = in_array($_acuerdo_raw, ['nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro','tasas_competitivas']) ? $_acuerdo_raw : null;
$fecha_acuerdo      = strOrNull($_POST['fecha_acuerdo']   ?? '');
$hora_acuerdo       = strOrNull($_POST['hora_acuerdo']    ?? '');
$observaciones      = strOrNull($_POST['observaciones']   ?? '');
$banco_ahorro       = strOrNull($_POST['banco_ahorro']    ?? '');
$banco_corriente    = strOrNull($_POST['banco_corriente'] ?? '');

// Propuesta previa al vencimiento / propuesta inversion
$propuesta_inversion = strOrNull($_POST['propuesta_inversion'] ?? '');
$fecha_previa_venc   = strOrNull($_POST['fecha_previa_vencimiento'] ?? '');
$hora_previa_venc    = strOrNull($_POST['hora_previa_vencimiento'] ?? '');
$crear_tarea_prev_venc = (int)($_POST['crear_tarea_prev_venc'] ?? 0);

// Empresa / Negocio (levantamiento suave)
$tiene_empresa_post = (int)($_POST['tiene_empresa'] ?? 0);
$venta_lv           = floatOrNull($_POST['venta_lv'] ?? '');
$venta_sabado       = floatOrNull($_POST['venta_sabado'] ?? '');
$venta_domingo      = floatOrNull($_POST['venta_domingo'] ?? '');
$mes_alta_venta     = strOrNull($_POST['mes_alta_venta'] ?? '');
$mes_baja_venta     = strOrNull($_POST['mes_baja_venta'] ?? '');
$compra_lv          = floatOrNull($_POST['compra_lv'] ?? '');
$compra_sabado      = floatOrNull($_POST['compra_sabado'] ?? '');
$compra_domingo     = floatOrNull($_POST['compra_domingo'] ?? '');
$mes_alta_compra    = strOrNull($_POST['mes_alta_compra'] ?? '');
$dia_lv             = (int)($_POST['dias_atencion_lv'] ?? 0);
$dia_sab            = (int)($_POST['dias_atencion_sab'] ?? 0);
$dia_dom            = (int)($_POST['dias_atencion_dom'] ?? 0);
// Ventas/compras/días individuales por día (Flutter los envía separados)
$venta_lunes     = floatOrNull($_POST['venta_lunes']     ?? '');
$venta_martes    = floatOrNull($_POST['venta_martes']    ?? '');
$venta_miercoles = floatOrNull($_POST['venta_miercoles'] ?? '');
$venta_jueves    = floatOrNull($_POST['venta_jueves']    ?? '');
$venta_viernes   = floatOrNull($_POST['venta_viernes']   ?? '');
$compra_lunes    = floatOrNull($_POST['compra_lunes']    ?? '');
$compra_martes   = floatOrNull($_POST['compra_martes']   ?? '');
$compra_miercoles= floatOrNull($_POST['compra_miercoles']?? '');
$compra_jueves   = floatOrNull($_POST['compra_jueves']   ?? '');
$compra_viernes  = floatOrNull($_POST['compra_viernes']  ?? '');
$dia_lunes       = (int)($_POST['dias_atencion_lunes']     ?? 0);
$dia_martes      = (int)($_POST['dias_atencion_martes']    ?? 0);
$dia_miercoles   = (int)($_POST['dias_atencion_miercoles'] ?? 0);
$dia_jueves      = (int)($_POST['dias_atencion_jueves']    ?? 0);
$dia_viernes     = (int)($_POST['dias_atencion_viernes']   ?? 0);
$pct_contado        = intOrNull($_POST['pct_contado'] ?? null);
$pct_credito        = intOrNull($_POST['pct_credito'] ?? null);
$pct_efectivo       = intOrNull($_POST['pct_efectivo'] ?? null);
$recuperacion_credito = floatOrNull($_POST['recuperacion_credito'] ?? '');
$costos_ventas        = floatOrNull($_POST['costos_ventas'] ?? '');
$gastos_negocio       = floatOrNull($_POST['gastos_negocio'] ?? '');
$otros_ingresos       = floatOrNull($_POST['otros_ingresos'] ?? '');
$gastos_familiares    = floatOrNull($_POST['gastos_familiares'] ?? '');
// Gastos negocio desglosados
$g_neg_sueldos       = floatOrNull($_POST['g_neg_sueldos']       ?? '');
$g_neg_arriendo      = floatOrNull($_POST['g_neg_arriendo']      ?? '');
$g_neg_serv_bas      = floatOrNull($_POST['g_neg_serv_bas']      ?? '');
$g_neg_transporte    = floatOrNull($_POST['g_neg_transporte']    ?? '');
$g_neg_mantenimiento = floatOrNull($_POST['g_neg_mantenimiento'] ?? '');
$g_neg_otros         = floatOrNull($_POST['g_neg_otros']         ?? '');
$g_neg_imprevistos   = floatOrNull($_POST['g_neg_imprevistos']   ?? '');
// Otros ingresos desglosados
$o_ing_conyuge    = floatOrNull($_POST['o_ing_conyuge']   ?? '');
$o_ing_arriendos  = floatOrNull($_POST['o_ing_arriendos'] ?? '');
$o_ing_pensiones  = floatOrNull($_POST['o_ing_pensiones'] ?? '');
$o_ing_otros      = floatOrNull($_POST['o_ing_otros']     ?? '');
// Gastos familiares desglosados
$g_fam_alim        = floatOrNull($_POST['g_fam_alim']        ?? '');
$g_fam_arriendo    = floatOrNull($_POST['g_fam_arriendo']    ?? '');
$g_fam_serv_bas    = floatOrNull($_POST['g_fam_serv_bas']    ?? '');
$g_fam_educacion   = floatOrNull($_POST['g_fam_educacion']   ?? '');
$g_fam_salud       = floatOrNull($_POST['g_fam_salud']       ?? '');
$g_fam_otros       = floatOrNull($_POST['g_fam_otros']       ?? '');
$g_fam_imprevistos = floatOrNull($_POST['g_fam_imprevistos'] ?? '');
// Vehículos e inmuebles (JSON)
$vehiculos_negocio_json  = $_POST['vehiculos_negocio_json']  ?? null;
$vehiculos_hogar_json    = $_POST['vehiculos_hogar_json']    ?? null;
$inmuebles_negocio_json  = $_POST['inmuebles_negocio_json']  ?? null;
$inmuebles_hogar_json    = $_POST['inmuebles_hogar_json']    ?? null;
// Otras deudas (JSON)
$otras_deudas_json = $_POST['otras_deudas_json'] ?? null;
// Productos y activos fijos (JSON)
$comercio_productos_json = $_POST['comercio_productos_json'] ?? null;
$productos_json          = $_POST['productos_json']          ?? null;
$activos_hogar_json      = $_POST['activos_hogar_json']      ?? null;

// Balance General / Saldos (Nuevos campos v2026-05-08)
$caja_efectivo    = floatOrNull($_POST['caja_efectivo']     ?? '');
$bancos_saldo     = floatOrNull($_POST['bancos_saldo']      ?? '');
$cxp_netas        = floatOrNull($_POST['cxp_netas']         ?? '');
$inv_mat_prima    = floatOrNull($_POST['inv_mat_prima']     ?? '');
$inv_prod_proc    = floatOrNull($_POST['inv_prod_proc']     ?? '');
$creditos_pagar   = floatOrNull($_POST['creditos_pagar']    ?? '');
$proveedores      = floatOrNull($_POST['proveedores']       ?? '');
$otras_deudas_cp  = floatOrNull($_POST['otras_deudas_cp']   ?? '');
$pasivos_lp       = floatOrNull($_POST['pasivos_lp']        ?? '');
$p1_conoce        = ($_POST['p1_conoce_institucion'] ?? '') === '1' ? 1 : (($_POST['p1_conoce_institucion'] ?? '') === '0' ? 0 : null);
$p1_obs           = $_POST['p1_obs'] ?? null;
$p2_es_cliente    = ($_POST['p2_es_cliente'] ?? '') === '1' ? 1 : (($_POST['p2_es_cliente'] ?? '') === '0' ? 0 : null);
$p2_producto      = $_POST['p2_producto'] ?? null;
$p2_obs           = $_POST['p2_obs'] ?? null;
$p3_satisfaccion  = $_POST['p3_satisfaccion'] ?? null;
$p3_obs           = $_POST['p3_obs'] ?? null;

// Normalize/validate acuerdo: accept frontend variants and map to DB enum values
// Use the raw incoming value so we can map human-friendly labels sent by the app
$incoming_acuerdo = $_acuerdo_raw;
// helper to normalize a human label into a token-like form
function normalize_token(string $s): string {
    $s = mb_strtolower(trim($s));
    // replace common separators with underscore
    $s = str_replace([' ', '-', '/', '\\'], '_', $s);
    // remove accents (basic mapping)
    $s = strtr($s, "ÀÁÂÃÄÅàáâãäåÈÉÊËèéêëÌÍÎÏìíîïÒÓÔÕÖòóôõöÙÚÛÜùúûüÑñÇç",
                       "AAAAAAaaaaaaEEEEeeeeIIIIiiiiOOOOOoooooUUUUuuuuNnCc");
    // keep only a-z0-9 and underscore
    $s = preg_replace('/[^a-z0-9_]/u', '', $s);
    return $s;
}

$acuerdo_map = [
    // valores del frontend → valor DB ENUM válido
    'nueva_cita_campo'            => 'nueva_cita_campo',
    'nueva_cita_oficina'          => 'nueva_cita_oficina',
    'reprogramacion'              => 'reprogramacion',
    'seguimiento'                 => 'seguimiento',
    'tasas_competitivas'          => 'tasas_competitivas',
    // variantes antiguas / alias
    'recolectar_documentacion'    => 'seguimiento',
    'recoleccion_documentacion'   => 'seguimiento',
    'recoleccionar_documentacion' => 'seguimiento',
    'documentos_pendientes'       => 'seguimiento',
    'levantamiento'               => 'otro',
    'levantamiento_campo'         => 'otro',
    // "Ninguno" → null (no registrar acuerdo)
    'ninguno'                     => null,
];
$db_acuerdos_allowed = ['nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro', 'tasas_competitivas'];

$incoming_tok = null;
// $acuerdo ya fue asignado arriba; aquí lo sobreescribimos con el mapeo normalizado
$acuerdo = null;   // por defecto: sin acuerdo
if ($incoming_acuerdo !== null) {
    $tok = normalize_token($incoming_acuerdo);
    $incoming_tok = $tok;
    if (array_key_exists($tok, $acuerdo_map)) {
        $acuerdo = $acuerdo_map[$tok];           // puede ser null si es 'ninguno'
    } elseif (in_array($tok, $db_acuerdos_allowed, true)) {
        $acuerdo = $tok;
    }
    // si no se reconoce Y tiene contenido → 'otro' como último recurso
    if ($acuerdo === null && $tok !== '' && $tok !== 'ninguno') {
        $acuerdo = 'otro';
    }
}

// Validar tipo_tarea contra ENUM real de tarea.tipo_tarea
$tipos_ok = ['prospecto_nuevo','visita_frio','evaluacion','recuperacion',
             'documentos_pendientes','post_venta','nueva_cita_campo','nueva_cita_oficina','levantamiento', 'tasas_competitivas'];
if (!in_array($tipo_tarea, $tipos_ok)) $tipo_tarea = 'prospecto_nuevo';

// ── Validaciones básicas ─────────────────────────────────────
if ($usuario_id === '' && $asesor_id_in === '') {
    respond_json(200, ['status'=>'error','message'=>'usuario_id o asesor_id requerido']);
    exit;
}
if ($nombre_completo === '' && $fue_encuestado) {
    respond_json(200, ['status'=>'error','message'=>'El nombre del cliente es requerido']);
    exit;
}

$tarea_followup_id   = null;
$tarea_followup_tipo = null;
$tarea_followup_fecha = null;
$tarea_followup_hora  = null;
// Tarea 2: cita de inversión
$tarea_inv_id    = null;
$tarea_inv_tipo  = null;
$tarea_inv_fecha = null;
$tarea_inv_hora  = null;

// ── DDL fuera de la transacción (DDL causa commit implícito en MySQL) ──────────
// Ejecutar ANTES de begin_transaction() para no interrumpir la transacción.
$negocio_id   = null;   // se asignará a UUID en el bloque de empresa
$negocio_err  = null;   // captura errores del INSERT de encuesta_negocio

try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN ruc_val VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN rise_val VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN tipo_empresa VARCHAR(50) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN regimen_tributario VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN numero_ruc VARCHAR(20) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN declara_iva TINYINT(1) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN emite_facturas TINYINT(1) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN lleva_contabilidad TINYINT(1) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN paga_cuota_rise TINYINT(1) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN emite_notas_venta TINYINT(1) DEFAULT NULL"); } catch (\Throwable $_) {}
try { $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN conoce_limite_rise TINYINT(1) DEFAULT NULL"); } catch (\Throwable $_) {}

// Pre-crear la tabla encuesta_negocio (si no existe) y migrar columnas faltantes.
// Aquí, FUERA de la transacción, para que el CREATE TABLE/ALTER TABLE no hagan
// commit implícito en medio del INSERT de tarea/encuesta.
if ($tiene_empresa_post === 1) {
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS encuesta_negocio (
                id                   CHAR(36)      NOT NULL PRIMARY KEY,
                tarea_id             CHAR(36)      NOT NULL,
                venta_lv             DECIMAL(12,2) DEFAULT NULL,
                venta_sabado         DECIMAL(12,2) DEFAULT NULL,
                venta_domingo        DECIMAL(12,2) DEFAULT NULL,
                mes_alta_venta       VARCHAR(20)   DEFAULT NULL,
                mes_baja_venta       VARCHAR(20)   DEFAULT NULL,
                compra_lv            DECIMAL(12,2) DEFAULT NULL,
                compra_sabado        DECIMAL(12,2) DEFAULT NULL,
                compra_domingo       DECIMAL(12,2) DEFAULT NULL,
                mes_alta_compra      VARCHAR(20)   DEFAULT NULL,
                dia_lv               TINYINT(1)    NOT NULL DEFAULT 0,
                dia_sab              TINYINT(1)    NOT NULL DEFAULT 0,
                dia_dom              TINYINT(1)    NOT NULL DEFAULT 0,
                pct_contado          INT           DEFAULT NULL,
                pct_credito          INT           DEFAULT NULL,
                pct_efectivo         INT           DEFAULT NULL,
                recuperacion_credito DECIMAL(12,2) DEFAULT NULL,
                costos_ventas        DECIMAL(12,2) DEFAULT NULL,
                gastos_negocio       DECIMAL(12,2) DEFAULT NULL,
                otros_ingresos       DECIMAL(12,2) DEFAULT NULL,
                gastos_familiares    DECIMAL(12,2) DEFAULT NULL,
                g_neg_sueldos        DECIMAL(12,2) DEFAULT NULL,
                g_neg_arriendo       DECIMAL(12,2) DEFAULT NULL,
                g_neg_serv_bas       DECIMAL(12,2) DEFAULT NULL,
                g_neg_transporte     DECIMAL(12,2) DEFAULT NULL,
                g_neg_mantenimiento  DECIMAL(12,2) DEFAULT NULL,
                g_neg_otros          DECIMAL(12,2) DEFAULT NULL,
                g_neg_imprevistos    DECIMAL(12,2) DEFAULT NULL,
                o_ing_conyuge        DECIMAL(12,2) DEFAULT NULL,
                o_ing_arriendos      DECIMAL(12,2) DEFAULT NULL,
                o_ing_pensiones      DECIMAL(12,2) DEFAULT NULL,
                o_ing_otros          DECIMAL(12,2) DEFAULT NULL,
                g_fam_alim           DECIMAL(12,2) DEFAULT NULL,
                g_fam_arriendo       DECIMAL(12,2) DEFAULT NULL,
                g_fam_serv_bas       DECIMAL(12,2) DEFAULT NULL,
                g_fam_educacion      DECIMAL(12,2) DEFAULT NULL,
                g_fam_salud          DECIMAL(12,2) DEFAULT NULL,
                g_fam_otros          DECIMAL(12,2) DEFAULT NULL,
                g_fam_imprevistos    DECIMAL(12,2) DEFAULT NULL,
                otras_deudas_json       LONGTEXT      DEFAULT NULL,
                vehiculos_negocio_json  LONGTEXT      DEFAULT NULL,
                vehiculos_hogar_json    LONGTEXT      DEFAULT NULL,
                inmuebles_negocio_json  LONGTEXT      DEFAULT NULL,
                inmuebles_hogar_json    LONGTEXT      DEFAULT NULL,
                created_at              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_en_tarea (tarea_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (\Throwable $_) {}

    // Migración: agregar columnas que pueden faltar en tablas pre-existentes
    $cols_faltantes = [
        "pct_efectivo"         => "ALTER TABLE encuesta_negocio ADD COLUMN pct_efectivo INT DEFAULT NULL AFTER pct_credito",
        "recuperacion_credito" => "ALTER TABLE encuesta_negocio ADD COLUMN recuperacion_credito DECIMAL(12,2) DEFAULT NULL",
        "costos_ventas"        => "ALTER TABLE encuesta_negocio ADD COLUMN costos_ventas DECIMAL(12,2) DEFAULT NULL",
        "gastos_negocio"       => "ALTER TABLE encuesta_negocio ADD COLUMN gastos_negocio DECIMAL(12,2) DEFAULT NULL",
        "otros_ingresos"       => "ALTER TABLE encuesta_negocio ADD COLUMN otros_ingresos DECIMAL(12,2) DEFAULT NULL",
        "gastos_familiares"    => "ALTER TABLE encuesta_negocio ADD COLUMN gastos_familiares DECIMAL(12,2) DEFAULT NULL",
        "g_neg_sueldos"        => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_sueldos DECIMAL(12,2) DEFAULT NULL",
        "g_neg_arriendo"       => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_arriendo DECIMAL(12,2) DEFAULT NULL",
        "g_neg_serv_bas"       => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_serv_bas DECIMAL(12,2) DEFAULT NULL",
        "g_neg_transporte"     => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_transporte DECIMAL(12,2) DEFAULT NULL",
        "g_neg_mantenimiento"  => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_mantenimiento DECIMAL(12,2) DEFAULT NULL",
        "g_neg_otros"          => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_otros DECIMAL(12,2) DEFAULT NULL",
        "g_neg_imprevistos"    => "ALTER TABLE encuesta_negocio ADD COLUMN g_neg_imprevistos DECIMAL(12,2) DEFAULT NULL",
        "o_ing_conyuge"        => "ALTER TABLE encuesta_negocio ADD COLUMN o_ing_conyuge DECIMAL(12,2) DEFAULT NULL",
        "o_ing_arriendos"      => "ALTER TABLE encuesta_negocio ADD COLUMN o_ing_arriendos DECIMAL(12,2) DEFAULT NULL",
        "o_ing_pensiones"      => "ALTER TABLE encuesta_negocio ADD COLUMN o_ing_pensiones DECIMAL(12,2) DEFAULT NULL",
        "o_ing_otros"          => "ALTER TABLE encuesta_negocio ADD COLUMN o_ing_otros DECIMAL(12,2) DEFAULT NULL",
        "g_fam_alim"           => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_alim DECIMAL(12,2) DEFAULT NULL",
        "g_fam_arriendo"       => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_arriendo DECIMAL(12,2) DEFAULT NULL",
        "g_fam_serv_bas"       => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_serv_bas DECIMAL(12,2) DEFAULT NULL",
        "g_fam_educacion"      => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_educacion DECIMAL(12,2) DEFAULT NULL",
        "g_fam_salud"          => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_salud DECIMAL(12,2) DEFAULT NULL",
        "g_fam_otros"          => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_otros DECIMAL(12,2) DEFAULT NULL",
        "g_fam_imprevistos"    => "ALTER TABLE encuesta_negocio ADD COLUMN g_fam_imprevistos DECIMAL(12,2) DEFAULT NULL",
        "otras_deudas_json"       => "ALTER TABLE encuesta_negocio ADD COLUMN otras_deudas_json LONGTEXT DEFAULT NULL",
        "vehiculos_negocio_json"  => "ALTER TABLE encuesta_negocio ADD COLUMN vehiculos_negocio_json LONGTEXT DEFAULT NULL",
        "vehiculos_hogar_json"    => "ALTER TABLE encuesta_negocio ADD COLUMN vehiculos_hogar_json LONGTEXT DEFAULT NULL",
        "inmuebles_negocio_json"  => "ALTER TABLE encuesta_negocio ADD COLUMN inmuebles_negocio_json LONGTEXT DEFAULT NULL",
        "inmuebles_hogar_json"    => "ALTER TABLE encuesta_negocio ADD COLUMN inmuebles_hogar_json LONGTEXT DEFAULT NULL",
        // Columnas individuales por día (Flutter las envía separadas)
        "venta_lunes"     => "ALTER TABLE encuesta_negocio ADD COLUMN venta_lunes DECIMAL(12,2) DEFAULT NULL",
        "venta_martes"    => "ALTER TABLE encuesta_negocio ADD COLUMN venta_martes DECIMAL(12,2) DEFAULT NULL",
        "venta_miercoles" => "ALTER TABLE encuesta_negocio ADD COLUMN venta_miercoles DECIMAL(12,2) DEFAULT NULL",
        "venta_jueves"    => "ALTER TABLE encuesta_negocio ADD COLUMN venta_jueves DECIMAL(12,2) DEFAULT NULL",
        "venta_viernes"   => "ALTER TABLE encuesta_negocio ADD COLUMN venta_viernes DECIMAL(12,2) DEFAULT NULL",
        "compra_lunes"    => "ALTER TABLE encuesta_negocio ADD COLUMN compra_lunes DECIMAL(12,2) DEFAULT NULL",
        "compra_martes"   => "ALTER TABLE encuesta_negocio ADD COLUMN compra_martes DECIMAL(12,2) DEFAULT NULL",
        "compra_miercoles"=> "ALTER TABLE encuesta_negocio ADD COLUMN compra_miercoles DECIMAL(12,2) DEFAULT NULL",
        "compra_jueves"   => "ALTER TABLE encuesta_negocio ADD COLUMN compra_jueves DECIMAL(12,2) DEFAULT NULL",
        "compra_viernes"  => "ALTER TABLE encuesta_negocio ADD COLUMN compra_viernes DECIMAL(12,2) DEFAULT NULL",
        "dia_lunes"       => "ALTER TABLE encuesta_negocio ADD COLUMN dia_lunes TINYINT(1) NOT NULL DEFAULT 0",
        "dia_martes"      => "ALTER TABLE encuesta_negocio ADD COLUMN dia_martes TINYINT(1) NOT NULL DEFAULT 0",
        "dia_miercoles"   => "ALTER TABLE encuesta_negocio ADD COLUMN dia_miercoles TINYINT(1) NOT NULL DEFAULT 0",
        "dia_jueves"      => "ALTER TABLE encuesta_negocio ADD COLUMN dia_jueves TINYINT(1) NOT NULL DEFAULT 0",
        "dia_viernes"     => "ALTER TABLE encuesta_negocio ADD COLUMN dia_viernes TINYINT(1) NOT NULL DEFAULT 0",
        // Productos y activos fijos
        "comercio_productos_json" => "ALTER TABLE encuesta_negocio ADD COLUMN comercio_productos_json LONGTEXT DEFAULT NULL",
        "productos_json"          => "ALTER TABLE encuesta_negocio ADD COLUMN productos_json LONGTEXT DEFAULT NULL",
        "activos_negocio_json"    => "ALTER TABLE encuesta_negocio ADD COLUMN activos_negocio_json LONGTEXT DEFAULT NULL",
        "activos_hogar_json"      => "ALTER TABLE encuesta_negocio ADD COLUMN activos_hogar_json LONGTEXT DEFAULT NULL",
        // Balance General (v2026-05-08)
        "caja_efectivo"           => "ALTER TABLE encuesta_negocio ADD COLUMN caja_efectivo DECIMAL(12,2) DEFAULT NULL",
        "bancos_saldo"            => "ALTER TABLE encuesta_negocio ADD COLUMN bancos_saldo DECIMAL(12,2) DEFAULT NULL",
        "cxp_netas"               => "ALTER TABLE encuesta_negocio ADD COLUMN cxp_netas DECIMAL(12,2) DEFAULT NULL",
        "inv_mat_prima"           => "ALTER TABLE encuesta_negocio ADD COLUMN inv_mat_prima DECIMAL(12,2) DEFAULT NULL",
        "inv_prod_proc"           => "ALTER TABLE encuesta_negocio ADD COLUMN inv_prod_proc DECIMAL(12,2) DEFAULT NULL",
        // Pasivo de la empresa (v2026-05-08)
        "creditos_pagar"          => "ALTER TABLE encuesta_negocio ADD COLUMN creditos_pagar DECIMAL(12,2) DEFAULT NULL",
        "proveedores"             => "ALTER TABLE encuesta_negocio ADD COLUMN proveedores DECIMAL(12,2) DEFAULT NULL",
        "otras_deudas_cp"         => "ALTER TABLE encuesta_negocio ADD COLUMN otras_deudas_cp DECIMAL(12,2) DEFAULT NULL",
        "pasivos_lp"              => "ALTER TABLE encuesta_negocio ADD COLUMN pasivos_lp DECIMAL(12,2) DEFAULT NULL",
        // Preguntas de identificación institucional (v2026-05-08)
        "p1_conoce_institucion"   => "ALTER TABLE encuesta_negocio ADD COLUMN p1_conoce_institucion TINYINT(1) DEFAULT NULL",
        "p1_obs"                  => "ALTER TABLE encuesta_negocio ADD COLUMN p1_obs TEXT DEFAULT NULL",
        "p2_es_cliente"           => "ALTER TABLE encuesta_negocio ADD COLUMN p2_es_cliente TINYINT(1) DEFAULT NULL",
        "p2_producto"             => "ALTER TABLE encuesta_negocio ADD COLUMN p2_producto VARCHAR(255) DEFAULT NULL",
        "p2_obs"                  => "ALTER TABLE encuesta_negocio ADD COLUMN p2_obs TEXT DEFAULT NULL",
        "p3_satisfaccion"         => "ALTER TABLE encuesta_negocio ADD COLUMN p3_satisfaccion VARCHAR(50) DEFAULT NULL",
        "p3_obs"                  => "ALTER TABLE encuesta_negocio ADD COLUMN p3_obs TEXT DEFAULT NULL",
    ];
    foreach ($cols_faltantes as $col => $sql) {
        $chk = $conn->query("SHOW COLUMNS FROM encuesta_negocio LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            try { $conn->query($sql); } catch (\Throwable $_) {}
        }
    }
}

// Pre-crear columnas faltantes en encuesta_comercial para asegurar persistencia de checkboxes
$cols_faltantes_comercial = [
    "que_busca_agilidad" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_agilidad TINYINT(1) DEFAULT 0",
    "que_busca_cajeros" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_cajeros TINYINT(1) DEFAULT 0",
    "que_busca_banca_linea" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_banca_linea TINYINT(1) DEFAULT 0",
    "que_busca_agencias" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_agencias TINYINT(1) DEFAULT 0",
    "que_busca_credito_rapido" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_credito_rapido TINYINT(1) DEFAULT 0",
    "que_busca_tarjeta_debito" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_tarjeta_debito TINYINT(1) DEFAULT 0",
    "que_busca_tarjeta_credito" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_tarjeta_credito TINYINT(1) DEFAULT 0",
    "interes_trabajar_institucion" => "ALTER TABLE encuesta_comercial ADD COLUMN interes_trabajar_institucion TINYINT(1) DEFAULT NULL",
    "fecha_vencimiento_cdp" => "ALTER TABLE encuesta_comercial ADD COLUMN fecha_vencimiento_cdp DATE DEFAULT NULL",
    "banco_ahorro" => "ALTER TABLE encuesta_comercial ADD COLUMN banco_ahorro VARCHAR(100) DEFAULT NULL",
    "banco_corriente" => "ALTER TABLE encuesta_comercial ADD COLUMN banco_corriente VARCHAR(100) DEFAULT NULL",
];
foreach ($cols_faltantes_comercial as $col => $sql) {
    $chk = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        try { $conn->query($sql); } catch (\Throwable $_) {}
    }
}

// Pre-crear tabla alerta_modificacion para evitar DDL dentro de la transacción
try {
    $conn->query("CREATE TABLE IF NOT EXISTS alerta_modificacion (
        id               CHAR(36)     NOT NULL PRIMARY KEY,
        tarea_id         CHAR(36)     NOT NULL,
        asesor_id        CHAR(36)     NOT NULL,
        supervisor_id    CHAR(36)     DEFAULT NULL,
        campo_modificado VARCHAR(120) DEFAULT 'visita_cliente',
        valor_anterior   TEXT         DEFAULT NULL,
        valor_nuevo      TEXT         DEFAULT NULL,
        vista_supervisor TINYINT(1)   NOT NULL DEFAULT 0,
        vista_at         DATETIME     DEFAULT NULL,
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_am_asesor (asesor_id),
        KEY idx_am_supervisor (supervisor_id),
        KEY idx_am_no_vista (vista_supervisor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $_) {}

// Pre-asegurar que tarea.tipo_tarea incluye 'nueva_cita_inversion'
// (DDL fuera de la transacción para evitar commit implícito en MySQL)
try {
    $colT = $conn->query("SHOW COLUMNS FROM tarea LIKE 'tipo_tarea'")->fetch_assoc();
    if ($colT && strpos($colT['Type'], "'nueva_cita_inversion'") === false) {
        preg_match("/enum\((.+)\)/i", $colT['Type'], $m);
        $currentVals = isset($m[1]) ? $m[1] : "'prospecto_nuevo'";
        $newVals = rtrim($currentVals, ')') . ",'nueva_cita_inversion'";
        $conn->query("ALTER TABLE tarea MODIFY COLUMN tipo_tarea ENUM($newVals) NOT NULL DEFAULT 'prospecto_nuevo'");
    }
} catch (\Throwable $_) {}
// ────────────────────────────────────────────────────────────────────────────

try {
    // ── 1. Resolver asesor_id ────────────────────────────────
    $GLOBALS['phase'] = 'ASESOR_RESOLVE';
    $asesor_id = null;

    if ($asesor_id_in !== '') {
        $st = $conn->prepare('SELECT id FROM asesor WHERE id = ? LIMIT 1');
        $st->bind_param('s', $asesor_id_in);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) $asesor_id = $row['id'];
        $st->close();
    }
    if ($asesor_id === null && $usuario_id !== '') {
        $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->bind_param('s', $usuario_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row) {
            respond_json(200, ['status'=>'error','message'=>'Asesor no encontrado para este usuario. Verifique que la cuenta tenga rol asesor.']);
            exit;
        }
        $asesor_id = $row['id'];
        $st->close();
    }
    if ($asesor_id === null) {
        respond_json(200, ['status'=>'error','message'=>'No se pudo resolver asesor_id.']);
        exit;
    }

    $conn->begin_transaction();

    // ── 2. Crear o actualizar cliente_prospecto ──────────────
    $GLOBALS['phase'] = 'CLIENTE';
    $cliente_id = null;

    if ($cedula !== null) {
        $st = $conn->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
        $st->bind_param('s', $cedula);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) $cliente_id = $row['id'];
        $st->close();
    }

    $es_cliente_existente = ($cliente_id !== null); // true = UPDATE, false = INSERT

    if ($cliente_id === null) {
        $cliente_id = genUUID();
        // ── 1. Intentar INSERT completo ──
        $st = $conn->prepare(
            "INSERT INTO cliente_prospecto
             (id, nombre, cedula, telefono, telefono2, email, direccion, ciudad,
              actividad, nombre_empresa, tiene_ruc, tiene_rise, ruc_val, rise_val, tipo_empresa,
              regimen_tributario, numero_ruc, declara_iva, emite_facturas, lleva_contabilidad,
              paga_cuota_rise, emite_notas_venta, conoce_limite_rise,
              asesor_id, latitud, longitud, origen_prospecto, estado)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'prospecto')"
        );

        if ($st) {
            $ins_vals = [
                $cliente_id, $nombre_completo, $cedula, $telefono, $celular, $email_c, $direccion, $ciudad,
                $actividad, $nombre_empresa, $tiene_ruc, $tiene_rise, $ruc_val, $rise_val, $tipo_empresa,
                $regimen_tributario, $numero_ruc, $declara_iva, $emite_facturas, $lleva_contabilidad,
                $paga_cuota_rise, $emite_notas_venta, $conoce_limite_rise, $asesor_id,
                $lat_ini, $lng_ini, $origen_prospecto
            ];
            $types = str_repeat('s', 10) . 'ii' . str_repeat('s', 5) . str_repeat('i', 6) . 's' . 'dd' . 's';
            $st->bind_param($types, ...$ins_vals);
            $st->execute();
            $st->close();
        } else {
            // ── 2. Fallback INSERT (si fallan columnas nuevas) ──
            $st = $conn->prepare(
                "INSERT INTO cliente_prospecto
                 (id, nombre, cedula, telefono, telefono2, email, direccion, ciudad,
                  actividad, nombre_empresa, tiene_ruc, tiene_rise, tiene_empresa,
                  asesor_id, latitud, longitud, origen_prospecto, estado)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'prospecto')"
            );
            if ($st) {
                $st->bind_param(str_repeat('s', 10) . 'iii' . 's' . 'dd' . 's',
                    $cliente_id, $nombre_completo, $cedula, $telefono, $celular, $email_c, $direccion, $ciudad,
                    $actividad, $nombre_empresa, $tiene_ruc, $tiene_rise, $tiene_empresa_post,
                    $asesor_id, $lat_ini, $lng_ini, $origen_prospecto
                );
                $st->execute();
                $st->close();
            }
            
            // Actualizar columnas adicionales si existen
            try {
                $upd_extra = $conn->prepare(
                    "UPDATE cliente_prospecto 
                     SET ruc_val=?, rise_val=?, tipo_empresa=?, regimen_tributario=?, numero_ruc=?, 
                         declara_iva=?, emite_facturas=?, lleva_contabilidad=?, paga_cuota_rise=?, 
                         emite_notas_venta=?, conoce_limite_rise=?, tiene_empresa=?
                     WHERE id=?"
                );
                if ($upd_extra) {
                    $upd_extra->bind_param('sssssiiiiiiiis', 
                        $ruc_val, $rise_val, $tipo_empresa, $regimen_tributario, $numero_ruc,
                        $declara_iva, $emite_facturas, $lleva_contabilidad, $paga_cuota_rise,
                        $emite_notas_venta, $conoce_limite_rise, $tiene_empresa_post, $cliente_id
                    );
                    $upd_extra->execute();
                    $upd_extra->close();
                }
            } catch (\Throwable $_) {}
        }
    } else {
        // ── 3. UPDATE cliente existente ──
        $st = $conn->prepare(
            "UPDATE cliente_prospecto
             SET nombre=?, telefono=?, telefono2=?, email=?, direccion=?, ciudad=?,
                 actividad=?, nombre_empresa=?, tiene_ruc=?, tiene_rise=?,
                 ruc_val=?, rise_val=?, tipo_empresa=?,
                 regimen_tributario=?, numero_ruc=?, declara_iva=?, emite_facturas=?, lleva_contabilidad=?,
                 paga_cuota_rise=?, emite_notas_venta=?, conoce_limite_rise=?, tiene_empresa=?,
                 asesor_id=?, origen_prospecto=?, estado=estado
             WHERE id=?"
        );
        if ($st) {
            $upd_types = str_repeat('s', 8) . 'ii' . str_repeat('s', 5) . str_repeat('i', 7) . str_repeat('s', 3);
            $st->bind_param($upd_types,
                $nombre_completo, $telefono, $celular, $email_c, $direccion, $ciudad,
                $actividad, $nombre_empresa, $tiene_ruc, $tiene_rise,
                $ruc_val, $rise_val, $tipo_empresa,
                $regimen_tributario, $numero_ruc, $declara_iva, $emite_facturas, $lleva_contabilidad,
                $paga_cuota_rise, $emite_notas_venta, $conoce_limite_rise, $tiene_empresa_post,
                $asesor_id, $origen_prospecto, $cliente_id
            );
            $st->execute();
            $st->close();
        }
    }

    // ── 3. Crear o Actualizar tarea ──────────────────────────────
    $GLOBALS['phase'] = 'TAREA';
    $tarea_id_post = strOrNull($_POST['tarea_id'] ?? '');
    $is_update_task = ($tarea_id_post !== null);
    
    if ($is_update_task) {
        $tarea_id = $tarea_id_post;
    } else {
        $tarea_id = genUUID();
    }

    $fecha_hoy = date('Y-m-d');
    $hora_hoy    = date('H:i:s');
    $obs_tarea   = $observaciones ?? ($fue_encuestado ? '' : 'Cliente no quiso ser encuestado');
    $est_tarea   = 'completada';   // ← variable requerida (PHP 8 no acepta literales en bind_param)
    $fecha_prog  = $fecha_hoy;
    $hora_prog   = $hora_hoy;

    if ($is_update_task) {
        // Actualizar tarea existente
        $st = $conn->prepare(
            "UPDATE tarea 
             SET estado = ?, 
                 fecha_realizada = ?, 
                 hora_realizada = ?, 
                 observaciones = COALESCE(?, observaciones),
                 latitud_inicio = COALESCE(?, latitud_inicio),
                 longitud_inicio = COALESCE(?, longitud_inicio),
                 latitud_fin = COALESCE(?, latitud_fin),
                 longitud_fin = COALESCE(?, longitud_fin)
             WHERE id = ?"
        );
        $st->bind_param('ssssdddds',
            $est_tarea, $fecha_hoy, $hora_hoy, $obs_tarea,
            $lat_ini, $lng_ini, $lat_fin, $lng_fin,
            $tarea_id
        );
        $st->execute();
        $st->close();
    } else {
        // INSERT de nueva tarea (comportamiento original)
        $st = $conn->prepare(
            "INSERT INTO tarea
             (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
              fecha_programada, hora_programada, fecha_realizada, hora_realizada,
              latitud_inicio, longitud_inicio, latitud_fin, longitud_fin, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->bind_param('sssssssssdddds',
            $tarea_id, $asesor_id, $cliente_id, $tipo_tarea, $est_tarea,
            $fecha_prog, $hora_prog, $fecha_hoy, $hora_hoy,
            $lat_ini, $lng_ini, $lat_fin, $lng_fin, $obs_tarea
        );
        $st->execute();
        $st->close();
    } // end if ($is_update_task)

    // ── 3c. Guardar levantamiento Empresa/Negocio (si aplica) ──
    // Nota: CREATE TABLE / ALTER TABLE se corrieron ANTES de begin_transaction()
    // para evitar que el DDL cause un commit implícito en MySQL y rompa la tx.
    //
    // IMPORTANTE: solo guardar encuesta_negocio cuando el tipo_tarea es 'levantamiento'.
    // Flutter envía tipo_tarea='levantamiento' únicamente desde LevantarEmpresaScreen.
    // La encuesta inicial (NuevaEncuestaScreen) usa tipo_tarea='prospecto_nuevo' u otro valor
    // y solo guarda nombre/RUC/tipo empresa, sin datos financieros reales.
    // Si se creara encuesta_negocio en esa fase, buscar_cliente_por_empresa.php
    // mostraría "✓ Levantamiento completado" de forma prematura.
    if ($tiene_empresa_post === 1 && $tipo_tarea === 'levantamiento') {
        $GLOBALS['phase'] = 'NEGOCIO';
        try {
            // Normalizar null -> 0 para evitar warnings en bind_param numérico
            $venta_lv_n  = $venta_lv  ?? 0.0;
            $venta_sab_n = $venta_sabado ?? 0.0;
            $venta_dom_n = $venta_domingo ?? 0.0;
            $compra_lv_n  = $compra_lv  ?? 0.0;
            $compra_sab_n = $compra_sabado ?? 0.0;
            $compra_dom_n = $compra_domingo ?? 0.0;
            $pct_cont_n  = $pct_contado  ?? 0;
            $pct_cred_n  = $pct_credito  ?? 0;
            $pct_efec_n  = $pct_efectivo ?? 70;
            $recup_n  = $recuperacion_credito ?? 0.0;
            $costos_n = $costos_ventas        ?? 0.0;
            $gastos_n = $gastos_negocio       ?? 0.0;
            $otros_n  = $otros_ingresos       ?? 0.0;
            $gfam_n   = $gastos_familiares    ?? 0.0;
            $gns_n = $g_neg_sueldos      ?? 0.0; $gna_n = $g_neg_arriendo    ?? 0.0;
            $gnb_n = $g_neg_serv_bas     ?? 0.0; $gnt_n = $g_neg_transporte  ?? 0.0;
            $gnm_n = $g_neg_mantenimiento?? 0.0; $gno_n = $g_neg_otros       ?? 0.0;
            $gni_n = $g_neg_imprevistos  ?? 0.0;
            $oic_n = $o_ing_conyuge   ?? 0.0; $oia_n = $o_ing_arriendos ?? 0.0;
            $oip_n = $o_ing_pensiones ?? 0.0; $oio_n = $o_ing_otros     ?? 0.0;
            $gfa_n = $g_fam_alim      ?? 0.0; $gfar_n = $g_fam_arriendo  ?? 0.0;
            $gfb_n = $g_fam_serv_bas  ?? 0.0; $gfe_n  = $g_fam_educacion ?? 0.0;
            $gfs_n = $g_fam_salud     ?? 0.0; $gfo_n  = $g_fam_otros     ?? 0.0;
            $gfi_n = $g_fam_imprevistos ?? 0.0;
            // Días individuales normalizados
            $vln_n = $venta_lunes     ?? 0.0; $vmr_n = $venta_martes    ?? 0.0;
            $vmi_n = $venta_miercoles ?? 0.0; $vju_n = $venta_jueves    ?? 0.0;
            $vvi_n = $venta_viernes   ?? 0.0;
            $cln_n = $compra_lunes    ?? 0.0; $cmr_n = $compra_martes   ?? 0.0;
            $cmi_n = $compra_miercoles?? 0.0; $cju_n = $compra_jueves   ?? 0.0;
            $cvi_n = $compra_viernes  ?? 0.0;
            // Balance General
            $caja_n   = $caja_efectivo   ?? 0.0; $banco_n = $bancos_saldo  ?? 0.0;
            $cxp_n    = $cxp_netas       ?? 0.0; $imp_n   = $inv_mat_prima ?? 0.0;
            $ipp_n    = $inv_prod_proc   ?? 0.0;
            // Pasivo de la empresa
            $credpag_n = $creditos_pagar  ?? 0.0;
            $prov_n    = $proveedores     ?? 0.0;
            $otrcp_n   = $otras_deudas_cp ?? 0.0;
            $paslp_n   = $pasivos_lp      ?? 0.0;

            // ── ¿Existe ya una fila para esta tarea? ──
            $stChkN = $conn->prepare('SELECT id FROM encuesta_negocio WHERE tarea_id = ? LIMIT 1');
            $stChkN->bind_param('s', $tarea_id);
            $stChkN->execute();
            $rowN = $stChkN->get_result()->fetch_assoc();
            $stChkN->close();

            if ($rowN) {
                $negocio_id = $rowN['id'];
                $stN = $conn->prepare(
                    "UPDATE encuesta_negocio
                     SET venta_lv=?, venta_sabado=?, venta_domingo=?, mes_alta_venta=?, mes_baja_venta=?,
                         compra_lv=?, compra_sabado=?, compra_domingo=?, mes_alta_compra=?,
                         dia_lv=?, dia_sab=?, dia_dom=?,
                         pct_contado=?, pct_credito=?, pct_efectivo=?,
                         recuperacion_credito=?, costos_ventas=?, gastos_negocio=?, otros_ingresos=?, gastos_familiares=?,
                         g_neg_sueldos=?, g_neg_arriendo=?, g_neg_serv_bas=?, g_neg_transporte=?, g_neg_mantenimiento=?, g_neg_otros=?, g_neg_imprevistos=?,
                         o_ing_conyuge=?, o_ing_arriendos=?, o_ing_pensiones=?, o_ing_otros=?,
                         g_fam_alim=?, g_fam_arriendo=?, g_fam_serv_bas=?, g_fam_educacion=?, g_fam_salud=?, g_fam_otros=?, g_fam_imprevistos=?,
                         otras_deudas_json=?, vehiculos_negocio_json=?, vehiculos_hogar_json=?, inmuebles_negocio_json=?, inmuebles_hogar_json=?,
                         venta_lunes=?, venta_martes=?, venta_miercoles=?, venta_jueves=?, venta_viernes=?,
                         compra_lunes=?, compra_martes=?, compra_miercoles=?, compra_jueves=?, compra_viernes=?,
                         dia_lunes=?, dia_martes=?, dia_miercoles=?, dia_jueves=?, dia_viernes=?,
                         comercio_productos_json=?, productos_json=?, activos_negocio_json=?, activos_hogar_json=?,
                         caja_efectivo=?, bancos_saldo=?, cxp_netas=?, inv_mat_prima=?, inv_prod_proc=?,
                         creditos_pagar=?, proveedores=?, otras_deudas_cp=?, pasivos_lp=?,
                         p1_conoce_institucion=?, p1_obs=?, p2_es_cliente=?, p2_producto=?, p2_obs=?, p3_satisfaccion=?, p3_obs=?
                     WHERE id = ?"
                );
                // 79 params
                $stN->bind_param(
                    'dddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssddddddddisiss ss s',
                    $venta_lv_n, $venta_sab_n, $venta_dom_n, $mes_alta_venta, $mes_baja_venta,
                    $compra_lv_n, $compra_sab_n, $compra_dom_n, $mes_alta_compra,
                    $dia_lv, $dia_sab, $dia_dom,
                    $pct_cont_n, $pct_cred_n, $pct_efec_n,
                    $recup_n, $costos_n, $gastos_n, $otros_n, $gfam_n,
                    $gns_n, $gna_n, $gnb_n, $gnt_n, $gnm_n, $gno_n, $gni_n,
                    $oic_n, $oia_n, $oip_n, $oio_n,
                    $gfa_n, $gfar_n, $gfb_n, $gfe_n, $gfs_n, $gfo_n, $gfi_n,
                    $otras_deudas_json, $vehiculos_negocio_json, $vehiculos_hogar_json, $inmuebles_negocio_json, $inmuebles_hogar_json,
                    $vln_n, $vmr_n, $vmi_n, $vju_n, $vvi_n,
                    $cln_n, $cmr_n, $cmi_n, $cju_n, $cvi_n,
                    $dia_lunes, $dia_martes, $dia_miercoles, $dia_jueves, $dia_viernes,
                    $comercio_productos_json, $productos_json, $activos_negocio_json, $activos_hogar_json,
                    $caja_n, $banco_n, $cxp_n, $imp_n, $ipp_n,
                    $credpag_n, $prov_n, $otrcp_n, $paslp_n,
                    $p1_conoce, $p1_obs, $p2_es_cliente, $p2_producto, $p2_obs, $p3_satisfaccion, $p3_obs,
                    $negocio_id
                );
            } else {
                $negocio_id = genUUID();
                $stN = $conn->prepare(
                    "INSERT INTO encuesta_negocio
                     (id, tarea_id,
                      venta_lv, venta_sabado, venta_domingo, mes_alta_venta, mes_baja_venta,
                      compra_lv, compra_sabado, compra_domingo, mes_alta_compra,
                      dia_lv, dia_sab, dia_dom,
                      pct_contado, pct_credito, pct_efectivo,
                      recuperacion_credito, costos_ventas, gastos_negocio, otros_ingresos, gastos_familiares,
                      g_neg_sueldos, g_neg_arriendo, g_neg_serv_bas, g_neg_transporte, g_neg_mantenimiento, g_neg_otros, g_neg_imprevistos,
                      o_ing_conyuge, o_ing_arriendos, o_ing_pensiones, o_ing_otros,
                      g_fam_alim, g_fam_arriendo, g_fam_serv_bas, g_fam_educacion, g_fam_salud, g_fam_otros, g_fam_imprevistos,
                      otras_deudas_json, vehiculos_negocio_json, vehiculos_hogar_json, inmuebles_negocio_json, inmuebles_hogar_json,
                      venta_lunes, venta_martes, venta_miercoles, venta_jueves, venta_viernes,
                      compra_lunes, compra_martes, compra_miercoles, compra_jueves, compra_viernes,
                      dia_lunes, dia_martes, dia_miercoles, dia_jueves, dia_viernes,
                      comercio_productos_json, productos_json, activos_negocio_json, activos_hogar_json,
                      caja_efectivo, bancos_saldo, cxp_netas, inv_mat_prima, inv_prod_proc,
                      creditos_pagar, proveedores, otras_deudas_cp, pasivos_lp,
                      p1_conoce_institucion, p1_obs, p2_es_cliente, p2_producto, p2_obs, p3_satisfaccion, p3_obs)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                // 80 params
                $stN->bind_param(
                    'ssdddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisiss ss ',
                    $negocio_id, $tarea_id,
                    $venta_lv_n, $venta_sab_n, $venta_dom_n, $mes_alta_venta, $mes_baja_venta,
                    $compra_lv_n, $compra_sab_n, $compra_dom_n, $mes_alta_compra,
                    $dia_lv, $dia_sab, $dia_dom,
                    $pct_cont_n, $pct_cred_n, $pct_efec_n,
                    $recup_n, $costos_n, $gastos_n, $otros_n, $gfam_n,
                    $gns_n, $gna_n, $gnb_n, $gnt_n, $gnm_n, $gno_n, $gni_n,
                    $oic_n, $oia_n, $oip_n, $oio_n,
                    $gfa_n, $gfar_n, $gfb_n, $gfe_n, $gfs_n, $gfo_n, $gfi_n,
                    $otras_deudas_json, $vehiculos_negocio_json, $vehiculos_hogar_json, $inmuebles_negocio_json, $inmuebles_hogar_json,
                    $vln_n, $vmr_n, $vmi_n, $vju_n, $vvi_n,
                    $cln_n, $cmr_n, $cmi_n, $cju_n, $cvi_n,
                    $dia_lunes, $dia_martes, $dia_miercoles, $dia_jueves, $dia_viernes,
                    $comercio_productos_json, $productos_json, $activos_negocio_json, $activos_hogar_json,
                    $caja_n, $banco_n, $cxp_n, $imp_n, $ipp_n,
                    $credpag_n, $prov_n, $otrcp_n, $paslp_n,
                    $p1_conoce, $p1_obs, $p2_es_cliente, $p2_producto, $p2_obs, $p3_satisfaccion, $p3_obs
                );
            }

            $stN->execute();
            $stN->close();
        } catch (\Throwable $eN) {
            // Capturar el error para incluirlo en la respuesta (no bloquea el flujo)
            $negocio_id  = null;
            $negocio_err = substr($eN->getMessage(), 0, 300);
            error_log('[guardar_encuesta][NEGOCIO] ERROR: ' . $eN->getMessage() . ' | phase=' . ($GLOBALS['phase'] ?? '?'));
        }
    }

    // ── 3b. Alerta de modificación (solo si el cliente ya existía) ──
    if ($es_cliente_existente && $asesor_id !== null) {
        try {
            // Tabla ya creada antes de begin_transaction() — solo insertar alerta

            // Obtener supervisor_id del asesor
            $sup_id = null;
            $stSup = $conn->prepare('SELECT supervisor_id FROM asesor WHERE id = ? LIMIT 1');
            if ($stSup) {
                $stSup->bind_param('s', $asesor_id);
                $stSup->execute();
                $rowSup = $stSup->get_result()->fetch_assoc();
                if ($rowSup) $sup_id = $rowSup['supervisor_id'] ?: null;
                $stSup->close();
            }

            // Obtener nombre del asesor para el resumen
            $asesor_nombre_alerta = '';
            $stNm = $conn->prepare(
                'SELECT u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ? LIMIT 1'
            );
            if ($stNm) {
                $stNm->bind_param('s', $asesor_id);
                $stNm->execute();
                $rowNm = $stNm->get_result()->fetch_assoc();
                if ($rowNm) $asesor_nombre_alerta = $rowNm['nombre'];
                $stNm->close();
            }

            $campo_mod  = 'Nueva visita a cliente existente';
            $val_ant    = null;
            $val_nuevo  = "Asesor: $asesor_nombre_alerta | Cliente: $nombre_completo (cédula: $cedula) | Tipo: $tipo_tarea | Fecha: " . date('d/m/Y H:i');
            $alerta_id  = genUUID();

            // Prevent duplicate alerts: if an alert for this tarea_id was created very recently, skip
            $skip_insert = false;
            try {
                $stChk = $conn->prepare("SELECT COUNT(*) AS cnt FROM alerta_modificacion WHERE tarea_id = ? AND created_at >= (NOW() - INTERVAL 2 MINUTE)");
                if ($stChk) {
                    $stChk->bind_param('s', $tarea_id);
                    $stChk->execute();
                    $rchk = $stChk->get_result()->fetch_assoc();
                    $stChk->close();
                    if (!empty($rchk) && (int)$rchk['cnt'] > 0) {
                        $skip_insert = true;
                    }
                }
            } catch (\Throwable $_) { /* ignore check failures, proceed to insert */ }

            if (!$skip_insert) {
                $stAl = $conn->prepare(
                    "INSERT INTO alerta_modificacion
                     (id, tarea_id, asesor_id, supervisor_id, campo_modificado, valor_anterior, valor_nuevo)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                if ($stAl) {
                    $stAl->bind_param('sssssss',
                        $alerta_id, $tarea_id, $asesor_id, $sup_id,
                        $campo_mod, $val_ant, $val_nuevo
                    );
                    $stAl->execute();
                    $stAl->close();
                }
            }
        } catch (\Throwable $eAl) {
            // No bloquear el flujo principal por un error de alerta
            error_log('[guardar_encuesta] Error creando alerta: ' . $eAl->getMessage());
        }
    }

    // ── 4. Encuesta comercial ────────────────────────────────
    if ($fue_encuestado) {
        $GLOBALS['phase'] = 'ENCUESTA';

        // Consolidar institución
        if ($inst_cred === null && $inst_prod_fin !== null) $inst_cred = $inst_prod_fin;

        // Recalcular nivel interés
        if ($interes_cc || $interes_ahorro || $interes_inv || $interes_cred) {
            $nivel_interes = 'alto';
        } elseif ($interes_conocer) {
            $nivel_interes = 'bajo';
        } else {
            $nivel_interes = 'ninguno';
        }

        // Extras en observaciones
        $extras = [];
        if ($busca_agilidad) $extras[] = 'Agilidad';
        if ($busca_cajeros)  $extras[] = 'Cajeros';
        if ($busca_banca)    $extras[] = 'Banca en línea';
        if ($busca_agencias) $extras[] = 'Agencias';
        if ($busca_credito)  $extras[] = 'Crédito rápido';
        if ($busca_td)       $extras[] = 'T. Débito';
        if ($busca_tc)       $extras[] = 'T. Crédito';
        if ($interes_trabajar !== null)
            $extras[] = 'Interés trabajar: ' . ($interes_trabajar ? 'Sí' : 'No');
        if ($fecha_venc_cdp !== null)
            $extras[] = 'CDP vence: ' . $fecha_venc_cdp;

        $obs_final = $observaciones ?? '';
        if (!empty($extras))
            $obs_final = trim($obs_final . "\n" . implode(', ', $extras));

        $enc_id  = genUUID();
        $f_nuevo = $fecha_acuerdo;  // fecha_nuevo_contacto
        // interes_propuesta_previa: crear tarea propuesta previa al vencimiento
        $int_pro = $crear_tarea_prev_venc ? 1 : 0;

        // 28 params correctos:
        // ss ii i s d ss i s i s i s iiii iiii s ssss
        // 1:enc_id(s) 2:tarea_id(s)
        // 3:mant_ahorro(i) 4:mant_corriente(i)
        // 5:tiene_inv(i) 6:inst_inv(s) 7:valor_inv(d)
        // 8:plazo_inv(s) 9:fecha_venc_inv(s)
        // 10:interes_propuesta(i) 11:f_nuevo(s)
        // 12:tiene_ops_cred(i) 13:inst_cred(s)
        // 14:interes_conocer(i) 15:nivel_interes(s)
        // 16:int_cc(i) 17:int_ahorro(i) 18:int_inv(i) 19:int_cred(i)
        // 20:razon_ya(i) 21:razon_des(i) 22:razon_ag(i) 23:razon_mal(i)
        // 24:razon_otros(s)
        // 25:acuerdo(s) 26:fecha_ac(s) 27:hora_ac(s) 28:obs_final(s)
        // Asegurar que el ENUM incluya 'tasas_competitivas'
        try {
            $col = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE 'acuerdo_logrado'")->fetch_assoc();
            if ($col && strpos($col['Type'], "'tasas_competitivas'") === false) {
                $conn->query("ALTER TABLE encuesta_comercial MODIFY COLUMN acuerdo_logrado ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro','tasas_competitivas') NULL");
            }
            $col2 = $conn->query("SHOW COLUMNS FROM acuerdo_visita LIKE 'tipo_acuerdo'")->fetch_assoc();
            if ($col2 && strpos($col2['Type'], "'tasas_competitivas'") === false) {
                $conn->query("ALTER TABLE acuerdo_visita MODIFY COLUMN tipo_acuerdo ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro','tasas_competitivas') NOT NULL");
            }
        } catch (\Throwable $_) {}

        $st = $conn->prepare(
            "INSERT INTO encuesta_comercial
             (id, tarea_id,
              mantiene_cuenta_ahorro, mantiene_cuenta_corriente,
              tiene_inversiones, institucion_inversiones, valor_inversion,
              plazo_inversion, fecha_vencimiento_inversion,
              interes_propuesta_previa, fecha_nuevo_contacto,
              tiene_operaciones_crediticias, institucion_credito,
              interes_conocer_productos, nivel_interes_captado,
              interes_cc, interes_ahorro, interes_inversion, interes_credito,
              razon_ya_trabaja_institucion, razon_desconfia_servicios,
              razon_agusto_actual, razon_mala_experiencia, razon_otros,
              acuerdo_logrado, fecha_acuerdo, hora_acuerdo, observaciones,
              que_busca_agilidad, que_busca_cajeros, que_busca_banca_linea,
              que_busca_agencias, que_busca_credito_rapido, que_busca_tarjeta_debito,
              que_busca_tarjeta_credito, interes_trabajar_institucion, fecha_vencimiento_cdp,
              banco_ahorro, banco_corriente)
             VALUES (?,?, ?,?, ?,?,?, ?,?, ?,?, ?,?, ?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?)"
        );
        // Array de 39 valores → type string generado dinámicamente
        $ec_vals = [
            $enc_id, $tarea_id,                              // s s
            $mantiene_ahorro, $mantiene_corriente,           // i i
            $tiene_inversiones, $inst_inv, $valor_inv,       // i s d
            $plazo_inv, $fecha_venc_inv,                     // s s
            $int_pro, $f_nuevo,                              // i s
            $tiene_ops_cred, $inst_cred,                     // i s
            $interes_conocer, $nivel_interes,                // i s
            $interes_cc, $interes_ahorro, $interes_inv, $interes_cred, // i i i i
            $razon_ya_trabaja, $razon_desconfia, $razon_agusto, $razon_mala_exp, // i i i i
            $razon_otros,                                    // s
            $acuerdo, $fecha_acuerdo, $hora_acuerdo, $obs_final, // s s s s
            $busca_agilidad, $busca_cajeros, $busca_banca,   // i i i
            $busca_agencias, $busca_credito, $busca_td,      // i i i
            $busca_tc, $interes_trabajar, $fecha_venc_cdp,   // i i s
            $banco_ahorro, $banco_corriente,                 // s s
        ];
        $ec_types = 'ss' . 'ii' . 'isd' . 'ss' . 'is' . 'is' . 'is' . 'iiii' . 'iiii' . 's' . 'ssss' . 'iii' . 'iii' . 'iis' . 'ss';
        $st->bind_param($ec_types, ...$ec_vals);
        $st->execute();
        $st->close();

        // ── 5. Acuerdo de visita + tarea de seguimiento ─────
        if ($acuerdo !== null && $fecha_acuerdo !== null) {
            $GLOBALS['phase'] = 'ACUERDO';

            // Registrar acuerdo
            $av_id = genUUID();
            $st = $conn->prepare(
                'INSERT INTO acuerdo_visita (id, tarea_id, tipo_acuerdo, fecha, hora)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $st->bind_param('sssss', $av_id, $tarea_id, $acuerdo, $fecha_acuerdo, $hora_acuerdo);
            $st->execute();
            $st->close();

            // Marcar cliente como pendiente (tiene algo por hacer luego)
            $st = $conn->prepare("UPDATE cliente_prospecto SET estado='pendiente' WHERE id=?");
            $st->bind_param('s', $cliente_id);
            $st->execute();
            $st->close();

            // Crear TAREA 1: seguimiento según acuerdo logrado al final de la encuesta
            // Mapa acuerdo → tipo_tarea (ENUM ya ampliado en migración)
            $tipo_followup_map = [
                'nueva_cita_campo'   => 'nueva_cita_campo',    // visita en campo
                'nueva_cita_oficina' => 'nueva_cita_oficina',  // visita en oficina
                'reprogramacion'     => 'evaluacion',           // reagendar = evaluación
                'seguimiento'        => 'documentos_pendientes',// seguimiento = recolectar docs
                'tasas_competitivas' => 'evaluacion',
                'otro'               => 'evaluacion',
            ];
            $tipo_followup = $acuerdo !== null ? ($tipo_followup_map[$acuerdo] ?? 'evaluacion') : null;

            if ($tipo_followup !== null) {
                $GLOBALS['phase'] = 'TAREA_FOLLOWUP';
                $tarea_followup_id    = genUUID();
                $tarea_followup_tipo  = $tipo_followup;
                $tarea_followup_fecha = $fecha_acuerdo;
                $tarea_followup_hora  = $hora_acuerdo;

                $est_follow = 'programada';
                // Etiqueta legible en observaciones
                $acuerdo_labels = [
                    'nueva_cita_campo'    => 'Nueva cita en campo',
                    'nueva_cita_oficina'  => 'Nueva cita en oficina',
                    'nueva_cita_inversion' => '💰 Nueva cita de inversión',
                    'reprogramacion'      => 'Reprogramación',
                    'seguimiento'         => 'Recolectar documentación',
                    'tasas_competitivas'  => 'Tasas competitivas',
                    'otro'                => 'Seguimiento',
                ];
                
                // Si fue override a inversión, usamos esa etiqueta
                $label_key = ($tipo_followup === 'nueva_cita_inversion') ? 'nueva_cita_inversion' : $acuerdo;
                $obs_follow = trim(($acuerdo_labels[$label_key] ?? ucfirst(str_replace('_', ' ', $label_key))));

                $st = $conn->prepare(
                    "INSERT INTO tarea
                     (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                      fecha_programada, hora_programada, observaciones)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if ($st) {
                    $st->bind_param('ssssssss',
                        $tarea_followup_id, $asesor_id, $cliente_id, $tarea_followup_tipo,
                        $est_follow, $tarea_followup_fecha, $tarea_followup_hora, $obs_follow
                    );
                    $st->execute();
                    $st->close();
                }
            }
        }

        // ── Crear TAREA 2: Nueva Cita de Inversión ──────────────────────────────────
        $debe_crear_tarea_inv = false;
        $fecha_inv_task = $fecha_previa_venc; 
        $hora_inv_task  = $hora_previa_venc;

        // Si el usuario marcó que hay propuesta o interés, forzamos creación si hay fecha
        if (($tiene_inversiones == 1 || $crear_tarea_prev_venc == 1 || ($propuesta_inversion !== null && $propuesta_inversion !== ''))) {
            if (!$fecha_inv_task) {
                $fecha_inv_task = $fecha_acuerdo;
                $hora_inv_task = $hora_acuerdo;
            }
            if ($fecha_inv_task) {
                $debe_crear_tarea_inv = true;
            }
        }

        if ($debe_crear_tarea_inv && $fecha_inv_task !== null) {
            try {
                $GLOBALS['phase'] = 'TAREA_INVERSION';

                // Variables temporales: NO asignar a $tarea_inv_* hasta confirmar INSERT exitoso
                $_inv_id    = genUUID();
                $_inv_tipo  = 'nueva_cita_inversion';
                $_inv_fecha = $fecha_inv_task;
                $_inv_hora  = $hora_inv_task ?? '';
                $est_inv    = 'programada';
                $obs_inv    = trim('Cita de inversión' .
                                ($propuesta_inversion ? ': ' . $propuesta_inversion : '') .
                                ($fecha_venc_inv ? ' (CDP vence: ' . $fecha_venc_inv . ')' : ''));

                $stp = $conn->prepare(
                    "INSERT INTO tarea
                     (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                      fecha_programada, hora_programada, observaciones)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if ($stp) {
                    $stp->bind_param('ssssssss',
                        $_inv_id, $asesor_id, $cliente_id, $_inv_tipo,
                        $est_inv, $_inv_fecha, $_inv_hora, $obs_inv
                    );
                    $execOk = $stp->execute();
                    $affectedRows = $stp->affected_rows;
                    $stpError = $stp->error;
                    $stp->close();

                    if ($execOk && $affectedRows > 0) {
                        // INSERT exitoso: ahora sí asignar a las variables de respuesta
                        $tarea_inv_id    = $_inv_id;
                        $tarea_inv_tipo  = $_inv_tipo;
                        $tarea_inv_fecha = $_inv_fecha;
                        $tarea_inv_hora  = $_inv_hora;
                        $conn->query("UPDATE cliente_prospecto SET estado='pendiente' WHERE id='$cliente_id' AND estado='prospecto'");
                    } else {
                        // INSERT falló silenciosamente — registrar el error real
                        error_log('[guardar_encuesta][TAREA_INVERSION] execute failed (affected=' . $affectedRows . '): ' . $stpError);
                        // $tarea_inv_id sigue siendo null → la respuesta no miente al cliente
                    }
                } else {
                    error_log('[guardar_encuesta][TAREA_INVERSION] prepare failed: ' . $conn->error);
                }
            } catch (\Throwable $eInv) {
                error_log('[guardar_encuesta][TAREA_INVERSION] ERROR: ' . $eInv->getMessage());
                // Resetear para que la respuesta no mienta al cliente
                $tarea_inv_id   = null;
                $tarea_inv_tipo = null;
                $tarea_inv_fecha = null;
                $tarea_inv_hora  = null;
            }
        }
    }

    // ── Si es levantamiento: actualizar credito_proceso.estado_credito ──────────
    // Registra que el levantamiento de empresa quedó completo, permitiendo que
    // el supervisor apruebe el crédito desde el panel web.
    if ($tipo_tarea === 'levantamiento' && $tiene_empresa_post === 1 && $cliente_id) {
        $GLOBALS['phase'] = 'CREDITO_LEVANTAMIENTO';
        try {
            // Verificar si ya existe un proceso de crédito para este cliente
            $stcr = $conn->prepare(
                "SELECT id FROM credito_proceso WHERE cliente_prospecto_id = ? ORDER BY created_at DESC LIMIT 1"
            );
            $stcr->bind_param('s', $cliente_id);
            $stcr->execute();
            $rcr = $stcr->get_result()->fetch_assoc();
            $stcr->close();

            if ($rcr) {
                // Actualizar estado a 'levantamiento' si estaba en etapa anterior
                $upd = $conn->prepare(
                    "UPDATE credito_proceso
                     SET estado_credito = 'levantamiento',
                         fecha_levantamiento = CURDATE(),
                         updated_at = NOW()
                     WHERE id = ?
                       AND estado_credito IN ('prospectado','entrevista_venta')"
                );
                $upd->bind_param('s', $rcr['id']);
                $upd->execute();
                $upd->close();
            } else {
                // No existe proceso aún: crear uno en estado 'levantamiento'
                $nuevo_cp_id = genUUID();
                $ins = $conn->prepare(
                    "INSERT INTO credito_proceso
                     (id, cliente_prospecto_id, asesor_id, estado_credito, fecha_levantamiento, created_at)
                     VALUES (?, ?, ?, 'levantamiento', CURDATE(), NOW())"
                );
                $ins->bind_param('sss', $nuevo_cp_id, $cliente_id, $asesor_id);
                $ins->execute();
                $ins->close();
            }
        } catch (\Throwable $_) {
            // No bloquear el flujo principal si falla este paso opcional
        }
    }

    $conn->commit();
    $GLOBALS['phase'] = 'DONE';

    respond_json(200, [
        'status'     => 'success',
        'message'    => $fue_encuestado ? 'Encuesta guardada correctamente' : 'Tarea registrada (sin encuesta)',
        'tarea_id'   => $tarea_id,
        'cliente_id' => $cliente_id,
        'encuesta_negocio_id' => $negocio_id,
        // Debug: ayuda a diagnosticar si tiene_empresa llega correctamente desde Flutter
        'dbg_tiene_empresa' => $tiene_empresa_post,
        'dbg_negocio_err'   => $negocio_err ?? null,
        // Tarea 1: seguimiento del acuerdo logrado al final de la encuesta
        'tarea_followup_id'    => $tarea_followup_id,
        'tarea_followup_tipo'  => $tarea_followup_tipo,
        'tarea_followup_fecha' => $tarea_followup_fecha,
        'tarea_followup_hora'  => $tarea_followup_hora,
        // Tarea 2: cita de inversión
        'tarea_inversion_id'    => $tarea_inv_id,
        'tarea_inversion_tipo'  => $tarea_inv_tipo,
        'tarea_inversion_fecha' => $tarea_inv_fecha,
        'tarea_inversion_hora'  => $tarea_inv_hora,
    ]);

} catch (\Throwable $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        try { $conn->rollback(); } catch (\Throwable $_) {}
    }
    $phase = $GLOBALS['phase'] ?? 'UNKNOWN';
    error_log('[guardar_cliente_encuesta][phase=' . $phase . '] ' . $e);
    // Attach debug info about acuerdo to help diagnose ENUM truncation
    $dbg_resp = [
        'incoming_raw' => $_acuerdo_raw ?? null,
        'incoming_tok' => $incoming_tok ?? null,
        'mapped'       => $acuerdo ?? null,
    ];
    error_log('[guardar_cliente_encuesta][acuerdo_debug] ' . json_encode($dbg_resp, JSON_UNESCAPED_UNICODE));
    respond_json(200, [
        'status'  => 'error',
        'message' => 'Error del servidor [' . $phase . ']: ' . substr($e->getMessage(), 0, 200),
        'phase'   => $phase,
        'acuerdo_debug' => $dbg_resp,
    ]);
} finally {
    if (isset($conn)) { try { $conn->close(); } catch (\Throwable $_) {} }
}
?>

<?php
// ============================================================
// actualizar_encuesta_completa.php  —  v2026-04-21a
// ------------------------------------------------------------
// Modifica una encuesta ya finalizada (tarea completada):
//   * Actualiza cliente_prospecto (NO cambia la cédula).
//   * Actualiza tarea (observaciones / GPS). Mantiene estado.
//   * Actualiza/inserta encuesta_comercial (upsert por tarea_id).
//   * Actualiza/inserta encuesta_negocio  (upsert por tarea_id).
//   * Actualiza/inserta acuerdo_visita    (upsert por tarea_id).
//
// NO crea una nueva tarea de seguimiento ni cierra segmentos.
// Registra una alerta_modificacion para que el supervisor la vea.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-05-06c';
$GLOBALS['phase'] = 'BOOT';

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

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type']??0), [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR])) return;
    $phase = $GLOBALS['phase'] ?? 'UNKNOWN';
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    $msg = substr($err['message']??'', 0, 180);
    echo json_encode(['status'=>'error','message'=>"Error interno [$phase]: $msg",'phase'=>$phase]);
});

// --- Diagnostic log (minimal, appended) ---------------------------------
// We log method, headers and a trimmed POST/raw body to help diagnose
// hosting/redirect issues (HTTP 302 without body). This is safe short-term
// and can be removed after debugging.
try {
    $diagFile = __DIR__ . '/diag_actualizar_encuesta.log';
    $h = function_exists('getallheaders') ? getallheaders() : [];
    $raw = @file_get_contents('php://input');
    $entry = date('c') . " METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? '') . "\n";
    $entry .= "HEADERS=" . json_encode($h, JSON_UNESCAPED_UNICODE) . "\n";
    $entry .= "POST_KEYS=" . json_encode(array_keys($_POST), JSON_UNESCAPED_UNICODE) . "\n";
    $entry .= "RAW_LEN=" . strlen($raw) . "\n\n";
    @file_put_contents($diagFile, $entry, FILE_APPEND | LOCK_EX);
} catch (\Throwable $_) {}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status'=>'ok','message'=>'actualizar_encuesta_completa alive','build'=>$API_BUILD]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// NOTA: para migrar los ENUMs de acuerdo en producción,
// corre UNA sola vez: http://tu-servidor/SUPER_IA/server_php/fix_acuerdo_enum.php

// ── Leer parámetros (mismos nombres que guardar_cliente_encuesta.php) ──
$tarea_id       = trim($_POST['tarea_id']       ?? '');
$usuario_id     = trim($_POST['usuario_id']     ?? '');
$asesor_id_in   = trim($_POST['asesor_id']      ?? '');
$fue_encuestado = (int)($_POST['fue_encuestado'] ?? 1);

if ($tarea_id === '') {
    respond_json(200, ['status'=>'error','message'=>'tarea_id requerido']);
    exit;
}

// Cliente (cedula NO se acepta para modificación — es solo lectura)
$nombre          = trim($_POST['nombre']    ?? '');
$apellidos       = trim($_POST['apellidos'] ?? '');
$nombre_completo = trim("$nombre $apellidos");
if ($nombre_completo === '') $nombre_completo = $nombre;
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

$origen_prospecto = strOrNull($_POST['origen_prospecto'] ?? '');
if ($origen_prospecto !== null) {
    $origen_prospecto = strtolower($origen_prospecto);
    if (!in_array($origen_prospecto, ['frio','seguidor','cliente','leads_llamadas'], true)) $origen_prospecto = null;
}

// Validar actividad (debe coincidir con ENUM de cliente_prospecto.actividad)
$acts_ok = ['negocio_propio','empleado_privado','empleado_publico','profesional'];
if ($actividad !== null && !in_array($actividad, $acts_ok, true)) $actividad = null;

// GPS (opcionales — solo se actualizan si vienen)
$lat_ini = floatOrNull($_POST['latitud_inicio']  ?? '');
$lng_ini = floatOrNull($_POST['longitud_inicio'] ?? '');
$lat_fin = floatOrNull($_POST['latitud_fin']     ?? '');
$lng_fin = floatOrNull($_POST['longitud_fin']    ?? '');

// Encuesta comercial
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
$busca_tc           = (int)($_POST['que_busca_tarjeta_credito']    ?? 0);
$busca_tasas        = (int)($_POST['que_busca_tasas_competitivas'] ?? 0);
$busca_otro         = (int)($_POST['que_busca_otro']               ?? 0);
$busca_otro_texto   = strOrNull($_POST['que_busca_otro_texto']     ?? '');
$fecha_venc_cdp     = strOrNull($_POST['fecha_vencimiento_cdp']    ?? '');
$interes_trabajar   = intOrNull($_POST['interes_trabajar_institucion'] ?? null);
$_acuerdo_raw       = strOrNull($_POST['acuerdo_logrado'] ?? '');
$acuerdo            = in_array($_acuerdo_raw, ['nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','levantamiento','otro']) ? $_acuerdo_raw : null;
$fecha_acuerdo      = strOrNull($_POST['fecha_acuerdo']   ?? '');
$hora_acuerdo       = strOrNull($_POST['hora_acuerdo']    ?? '');
$observaciones      = strOrNull($_POST['observaciones']   ?? '');
$banco_ahorro       = strOrNull($_POST['banco_ahorro']    ?? '');
$banco_corriente    = strOrNull($_POST['banco_corriente'] ?? '');

// Empresa / Negocio
$tiene_empresa_post = (int)($_POST['tiene_empresa'] ?? 0);
$venta_lv           = floatOrNull($_POST['venta_lv']      ?? '');
$venta_sabado       = floatOrNull($_POST['venta_sabado']  ?? '');
$venta_domingo      = floatOrNull($_POST['venta_domingo'] ?? '');
$mes_alta_venta     = strOrNull($_POST['mes_alta_venta']  ?? '');
$mes_baja_venta     = strOrNull($_POST['mes_baja_venta']  ?? '');
$compra_lv          = floatOrNull($_POST['compra_lv']     ?? '');
$compra_sabado      = floatOrNull($_POST['compra_sabado'] ?? '');
$compra_domingo     = floatOrNull($_POST['compra_domingo']?? '');
$mes_alta_compra    = strOrNull($_POST['mes_alta_compra'] ?? '');
$dia_lv             = (int)($_POST['dias_atencion_lv']    ?? 0);
$dia_sab            = (int)($_POST['dias_atencion_sab']   ?? 0);
$dia_dom            = (int)($_POST['dias_atencion_dom']   ?? 0);
$pct_contado        = intOrNull($_POST['pct_contado']     ?? null);
$pct_credito        = intOrNull($_POST['pct_credito']     ?? null);
$pct_efectivo       = intOrNull($_POST['pct_efectivo']    ?? null);
$recuperacion_credito = floatOrNull($_POST['recuperacion_credito'] ?? '');
$costos_ventas        = floatOrNull($_POST['costos_ventas']        ?? '');
$gastos_negocio       = floatOrNull($_POST['gastos_negocio']       ?? '');
$otros_ingresos       = floatOrNull($_POST['otros_ingresos']       ?? '');
$gastos_familiares    = floatOrNull($_POST['gastos_familiares']    ?? '');
$g_neg_sueldos       = floatOrNull($_POST['g_neg_sueldos']       ?? '');
$g_neg_arriendo      = floatOrNull($_POST['g_neg_arriendo']      ?? '');
$g_neg_serv_bas      = floatOrNull($_POST['g_neg_serv_bas']      ?? '');
$g_neg_transporte    = floatOrNull($_POST['g_neg_transporte']    ?? '');
$g_neg_mantenimiento = floatOrNull($_POST['g_neg_mantenimiento'] ?? '');
$g_neg_otros         = floatOrNull($_POST['g_neg_otros']         ?? '');
$g_neg_imprevistos   = floatOrNull($_POST['g_neg_imprevistos']   ?? '');
$o_ing_conyuge    = floatOrNull($_POST['o_ing_conyuge']   ?? '');
$o_ing_arriendos  = floatOrNull($_POST['o_ing_arriendos'] ?? '');
$o_ing_pensiones  = floatOrNull($_POST['o_ing_pensiones'] ?? '');
$o_ing_otros      = floatOrNull($_POST['o_ing_otros']     ?? '');
$g_fam_alim        = floatOrNull($_POST['g_fam_alim']        ?? '');
$g_fam_arriendo    = floatOrNull($_POST['g_fam_arriendo']    ?? '');
$g_fam_serv_bas    = floatOrNull($_POST['g_fam_serv_bas']    ?? '');
$g_fam_educacion   = floatOrNull($_POST['g_fam_educacion']   ?? '');
$g_fam_salud       = floatOrNull($_POST['g_fam_salud']       ?? '');
$g_fam_otros       = floatOrNull($_POST['g_fam_otros']       ?? '');
$g_fam_imprevistos = floatOrNull($_POST['g_fam_imprevistos'] ?? '');
$otras_deudas_json       = $_POST['otras_deudas_json']       ?? null;
$vehiculos_negocio_json  = $_POST['vehiculos_negocio_json']  ?? null;
$vehiculos_hogar_json    = $_POST['vehiculos_hogar_json']    ?? null;
$inmuebles_negocio_json  = $_POST['inmuebles_negocio_json']  ?? null;
$inmuebles_hogar_json    = $_POST['inmuebles_hogar_json']    ?? null;
$comercio_productos_json = $_POST['comercio_productos_json'] ?? null;
$productos_json          = $_POST['productos_json']          ?? null;
$activos_negocio_json    = $_POST['activos_negocio_json']    ?? null;
$activos_hogar_json      = $_POST['activos_hogar_json']      ?? null;
// Campos por día individual (nuevos desde app v2)
$venta_lunes     = floatOrNull($_POST['venta_lunes']     ?? '');
$venta_martes    = floatOrNull($_POST['venta_martes']    ?? '');
$venta_miercoles = floatOrNull($_POST['venta_miercoles'] ?? '');
$venta_jueves    = floatOrNull($_POST['venta_jueves']    ?? '');
$venta_viernes   = floatOrNull($_POST['venta_viernes']   ?? '');
$compra_lunes     = floatOrNull($_POST['compra_lunes']     ?? '');
$compra_martes    = floatOrNull($_POST['compra_martes']    ?? '');
$compra_miercoles = floatOrNull($_POST['compra_miercoles'] ?? '');
$compra_jueves    = floatOrNull($_POST['compra_jueves']    ?? '');
$compra_viernes   = floatOrNull($_POST['compra_viernes']   ?? '');
$dia_lunes     = (int)($_POST['dias_atencion_lunes']     ?? 0);
$dia_martes    = (int)($_POST['dias_atencion_martes']    ?? 0);
$dia_miercoles = (int)($_POST['dias_atencion_miercoles'] ?? 0);
$dia_jueves    = (int)($_POST['dias_atencion_jueves']    ?? 0);
$dia_viernes   = (int)($_POST['dias_atencion_viernes']   ?? 0);

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

// Normalize/validate acuerdo similar to guardar_cliente_encuesta
function normalize_token_act(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = str_replace([' ', '-', '/', '\\'], '_', $s);
    $s = strtr($s, "ÀÁÂÃÄÅàáâãäåÈÉÊËèéêëÌÍÎÏìíîïÒÓÔÕÖòóôõöÙÚÛÜùúûüÑñÇç",
                       "AAAAAAaaaaaaEEEEeeeeIIIIiiiiOOOOOoooooUUUUuuuuNnCc");
    $s = preg_replace('/[^a-z0-9_]/u', '', $s);
    return $s;
}

$incoming_acuerdo = $_acuerdo_raw;
$acuerdo_map = [
    'documentos_pendientes' => 'otro',
    'recolectar_documentacion' => 'otro',
    'recoleccion_documentacion' => 'otro',
    'recoleccionar_documentacion' => 'otro',
    'levantamiento' => 'levantamiento',
    'levantamiento_empresa' => 'levantamiento',
    'levantamiento_campo' => 'levantamiento',
    'nueva_cita_campo' => 'nueva_cita_campo',
    'nueva_cita_oficina' => 'nueva_cita_oficina',
    'reprogramacion' => 'reprogramacion',
    'seguimiento' => 'seguimiento',
    'tasas_competitivas' => 'tasas_competitivas',
    'otro' => 'otro',
];
$db_acuerdos_allowed = ['nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','levantamiento','otro', 'tasas_competitivas'];
$mapped = null;
$incoming_tok = null;
if ($incoming_acuerdo !== null) {
    $tok = normalize_token_act($incoming_acuerdo);
    $incoming_tok = $tok;
    if (isset($acuerdo_map[$tok])) $mapped = $acuerdo_map[$tok];
    elseif (in_array($tok, $db_acuerdos_allowed, true)) $mapped = $tok;
    elseif (strpos($tok, 'doc') !== false || strpos($tok, 'document') !== false) $mapped = 'otro';
}
$acuerdo = $mapped ?? 'otro';

try {
    $GLOBALS['phase'] = 'LOAD_TAREA';

    // ── 1. Recuperar tarea y cliente actual ─────────────────────
    $st = $conn->prepare('SELECT asesor_id, cliente_prospecto_id, estado, tipo_tarea FROM tarea WHERE id = ? LIMIT 1');
    $st->bind_param('s', $tarea_id);
    $st->execute();
    $rowT = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$rowT) {
        respond_json(200, ['status'=>'error','message'=>'Tarea no encontrada']);
        exit;
    }
    $asesor_id   = (string)$rowT['asesor_id'];
    $cliente_id  = (string)$rowT['cliente_prospecto_id'];
    $estadoPrev  = (string)$rowT['estado'];
    $tipo_tarea_db = (string)$rowT['tipo_tarea'];

    // Validar que quien edita sea el asesor dueño (si se envió)
    if ($asesor_id_in !== '' && $asesor_id_in !== $asesor_id) {
        respond_json(200, ['status'=>'error','message'=>'La tarea no pertenece a este asesor']);
        exit;
    }
    // Validar por usuario_id también
    if ($asesor_id_in === '' && $usuario_id !== '') {
        $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->bind_param('s', $usuario_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row || $row['id'] !== $asesor_id) {
            respond_json(200, ['status'=>'error','message'=>'No autorizado para modificar esta tarea']);
            exit;
        }
    }

    $conn->begin_transaction();
    // ── 2. Actualizar cliente_prospecto (NO se toca la cédula) ─

    // ── SNAPSHOT: obtener estado previo de cliente/encuestas/acuerdo ─
    $GLOBALS['phase'] = 'SNAP_PREV';
    $prev_snapshot = [
        'cliente' => null,
        'encuesta_comercial' => null,
        'encuesta_negocio' => null,
        'acuerdo_visita' => null,
    ];
    if ($cliente_id !== '') {
        try {
            $stC = $conn->prepare('SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1');
            $stC->bind_param('s', $cliente_id);
            $stC->execute();
            $prev_snapshot['cliente'] = $stC->get_result()->fetch_assoc() ?: null;
            $stC->close();
        } catch (\Throwable $_) { $prev_snapshot['cliente'] = null; }
    }
    try {
        $stE = $conn->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
        $stE->bind_param('s', $tarea_id);
        $stE->execute();
        $prev_snapshot['encuesta_comercial'] = $stE->get_result()->fetch_assoc() ?: null;
        $stE->close();
    } catch (\Throwable $_) { $prev_snapshot['encuesta_comercial'] = null; }
    try {
        $stN = $conn->prepare('SELECT * FROM encuesta_negocio WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
        $stN->bind_param('s', $tarea_id);
        $stN->execute();
        $prev_snapshot['encuesta_negocio'] = $stN->get_result()->fetch_assoc() ?: null;
        $stN->close();
    } catch (\Throwable $_) { $prev_snapshot['encuesta_negocio'] = null; }
    try {
        $stA = $conn->prepare('SELECT * FROM acuerdo_visita WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
        $stA->bind_param('s', $tarea_id);
        $stA->execute();
        $prev_snapshot['acuerdo_visita'] = $stA->get_result()->fetch_assoc() ?: null;
        $stA->close();
    } catch (\Throwable $_) { $prev_snapshot['acuerdo_visita'] = null; }

    $conn->begin_transaction();
    
    // Ensure table exists (so we can insert provisional alert)
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

    // Try to determine supervisor and asesor nombre early so we can create a provisional alert
    $sup_id = null;
    try {
        $stSup = $conn->prepare('SELECT supervisor_id FROM asesor WHERE id = ? LIMIT 1');
        if ($stSup) {
            $stSup->bind_param('s', $asesor_id);
            $stSup->execute();
            $rowSup = $stSup->get_result()->fetch_assoc();
            if ($rowSup) $sup_id = $rowSup['supervisor_id'] ?: null;
            $stSup->close();
        }
    } catch (\Throwable $_) { $sup_id = null; }

    $asesor_nombre_alerta = '';
    try {
        $stNm = $conn->prepare('SELECT u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ? LIMIT 1');
        if ($stNm) {
            $stNm->bind_param('s', $asesor_id);
            $stNm->execute();
            $rowNm = $stNm->get_result()->fetch_assoc();
            if ($rowNm) $asesor_nombre_alerta = $rowNm['nombre'];
            $stNm->close();
        }
    } catch (\Throwable $_) { $asesor_nombre_alerta = ''; }

    $conn->begin_transaction();

    // ── SNAPSHOT: Crear alerta solo si es un levantamiento de empresa ──
    if ($tipo_tarea_db === 'levantamiento') {
        try {
            $campo_mod = 'Modificación de levantamiento de empresa';
            $alerta_id = genUUID();
        // Prefer partial output on error so we keep as much snapshot as possible.
        $val_ant_json = @json_encode($prev_snapshot, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        // If encoding still fails, store a short textual fallback summary
        if ($val_ant_json === false || $val_ant_json === null) {
            $summary = [];
            if (!empty($prev_snapshot['cliente']) && is_array($prev_snapshot['cliente'])) {
                $p = $prev_snapshot['cliente'];
                $name = (($p['nombre'] ?? '') ?: ($p['nombre_completo'] ?? ''));
                $phone = ($p['telefono'] ?? $p['telefono2'] ?? '');
                $email = ($p['email'] ?? $p['email_cliente'] ?? '');
                $parts = [];
                if ($name) $parts[] = 'Cliente: ' . $name . ' (id=' . ($p['id'] ?? '') . ')';
                if ($phone) $parts[] = 'Tel: ' . $phone;
                if ($email) $parts[] = 'Email: ' . $email;
                if (!empty($parts)) $summary[] = implode(' | ', $parts);
            }
            if (!empty($prev_snapshot['encuesta_comercial'])) $summary[] = 'Encuesta comercial: existente';
            if (!empty($prev_snapshot['encuesta_negocio'])) $summary[] = 'Encuesta negocio: existente';
            if (!empty($prev_snapshot['acuerdo_visita'])) $summary[] = 'Acuerdo visita: existente';
            $val_ant_json = json_encode(['summary' => implode(' | ', $summary), 'partial' => true], JSON_UNESCAPED_UNICODE);
        }
        $stAlPrep = $conn->prepare(
            "INSERT INTO alerta_modificacion (id, tarea_id, asesor_id, supervisor_id, campo_modificado, valor_anterior, valor_nuevo)
             VALUES (?, ?, ?, ?, ?, ?, NULL)"
        );
        if ($stAlPrep) {
            $stAlPrep->bind_param('ssssss', $alerta_id, $tarea_id, $asesor_id, $sup_id, $campo_mod, $val_ant_json);
            $stAlPrep->execute();
            $stAlPrep->close();
        }
    } catch (\Throwable $_) {
        // non-fatal, continue
    }
    $GLOBALS['phase'] = 'UPDATE_CLIENTE';
    if ($cliente_id !== '') {
        // ── Migración segura: solo agrega columna si no existe ──
        $cols_cp = [
            'ruc_val'           => "VARCHAR(20) DEFAULT NULL",
            'rise_val'          => "VARCHAR(20) DEFAULT NULL",
            'tipo_empresa'      => "VARCHAR(50) DEFAULT NULL",
            'regimen_tributario'=> "VARCHAR(20) DEFAULT NULL",
            'numero_ruc'        => "VARCHAR(20) DEFAULT NULL",
            'declara_iva'       => "TINYINT(1) DEFAULT NULL",
            'emite_facturas'    => "TINYINT(1) DEFAULT NULL",
            'lleva_contabilidad'=> "TINYINT(1) DEFAULT NULL",
            'paga_cuota_rise'   => "TINYINT(1) DEFAULT NULL",
            'emite_notas_venta' => "TINYINT(1) DEFAULT NULL",
            'conoce_limite_rise'=> "TINYINT(1) DEFAULT NULL",
        ];
        foreach ($cols_cp as $col => $def) {
            $res = $conn->query("SHOW COLUMNS FROM cliente_prospecto LIKE '$col'");
            if ($res && $res->num_rows === 0) {
                $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN $col $def");
            }
        }

        // Array de 23 valores → type string de 23 chars: 8s + 2i + 13s
        $upd_vals = [
            $nombre_completo, $telefono, $celular, $email_c, $direccion, $ciudad,
            $actividad, $nombre_empresa,                              // 8 strings
            $tiene_ruc, $tiene_rise,                                  // 2 ints
            $ruc_val, $rise_val, $tipo_empresa, $regimen_tributario,
            $numero_ruc, $declara_iva, $emite_facturas, $lleva_contabilidad,
            $paga_cuota_rise, $emite_notas_venta, $conoce_limite_rise,
            $origen_prospecto, $cliente_id,                           // 13 strings
        ];
        $upd_types = str_repeat('s', 8) . 'ii' . str_repeat('s', 13); // = "ssssssssiissssssssssss s" sin espacios = 23
        $sql_upd   = "UPDATE cliente_prospecto SET nombre=?, telefono=?, telefono2=?, email=?, direccion=?, ciudad=?, actividad=?, nombre_empresa=?, tiene_ruc=?, tiene_rise=?, ruc_val=?, rise_val=?, tipo_empresa=?, regimen_tributario=?, numero_ruc=?, declara_iva=?, emite_facturas=?, lleva_contabilidad=?, paga_cuota_rise=?, emite_notas_venta=?, conoce_limite_rise=?, origen_prospecto=? WHERE id=?";
        $st = $conn->prepare($sql_upd);
        if (!$st) { throw new \RuntimeException("prepare UC: " . $conn->error); }
        $st->bind_param($upd_types, ...$upd_vals);
        $st->execute();
        $st->close();
    }

    // ── 3. Actualizar tarea (observaciones y GPS) ───────────────
    $GLOBALS['phase'] = 'UPDATE_TAREA';
    $obs_tarea = $observaciones ?? '';

    // Solo actualiza GPS si vienen explícitamente.
    // Si la tarea AÚN NO estaba completada, esta llamada es la actividad
    // (encuesta / levantamiento / recolección de documentos / etc.) que el
    // asesor acaba de realizar → se finaliza automáticamente sin importar
    // el tipo_tarea. Si ya estaba 'completada', esto es una edición
    // posterior de datos (botón "Modificar datos") y NO se toca el estado.
    $debe_finalizar = !in_array($estadoPrev, ['completada', 'cancelada'], true);
    if ($lat_ini !== null || $lng_ini !== null || $lat_fin !== null || $lng_fin !== null) {
        if ($debe_finalizar) {
            $fecha_hoy = date('Y-m-d');
            $hora_hoy  = date('H:i:s');
            $st = $conn->prepare(
                "UPDATE tarea
                 SET observaciones=?, estado='completada', fecha_realizada=?, hora_realizada=?,
                     latitud_inicio = COALESCE(?, latitud_inicio),
                     longitud_inicio = COALESCE(?, longitud_inicio),
                     latitud_fin = COALESCE(?, latitud_fin),
                     longitud_fin = COALESCE(?, longitud_fin)
                 WHERE id=?"
            );
            $st->bind_param('sssdddds', $obs_tarea, $fecha_hoy, $hora_hoy, $lat_ini, $lng_ini, $lat_fin, $lng_fin, $tarea_id);
        } else {
            $st = $conn->prepare(
                "UPDATE tarea
                 SET observaciones=?,
                     latitud_inicio = COALESCE(?, latitud_inicio),
                     longitud_inicio = COALESCE(?, longitud_inicio),
                     latitud_fin = COALESCE(?, latitud_fin),
                     longitud_fin = COALESCE(?, longitud_fin)
                 WHERE id=?"
            );
            $st->bind_param('sdddds', $obs_tarea, $lat_ini, $lng_ini, $lat_fin, $lng_fin, $tarea_id);
        }
        $st->execute();
        $st->close();
    } else {
        if ($debe_finalizar) {
            $fecha_hoy = date('Y-m-d');
            $hora_hoy  = date('H:i:s');
            $st = $conn->prepare("UPDATE tarea SET observaciones=?, estado='completada', fecha_realizada=?, hora_realizada=? WHERE id=?");
            $st->bind_param('ssss', $obs_tarea, $fecha_hoy, $hora_hoy, $tarea_id);
        } else {
            $st = $conn->prepare("UPDATE tarea SET observaciones=? WHERE id=?");
            $st->bind_param('ss', $obs_tarea, $tarea_id);
        }
        $st->execute();
        $st->close();
    }

    // ── 4. Upsert encuesta_negocio (si tiene_empresa=1) ─────────
    if ($tiene_empresa_post === 1) {
        $GLOBALS['phase'] = 'UPSERT_NEGOCIO';

        // Asegurar tabla (con nuevas columnas)
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
                otras_deudas_json    LONGTEXT      DEFAULT NULL,
                created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_en_tarea (tarea_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Agregar columnas nuevas si ya existe la tabla (retrocompatibilidad)
        $newCols = ['pct_efectivo INT DEFAULT NULL','g_neg_sueldos DECIMAL(12,2) DEFAULT NULL',
            'g_neg_arriendo DECIMAL(12,2) DEFAULT NULL','g_neg_serv_bas DECIMAL(12,2) DEFAULT NULL',
            'g_neg_transporte DECIMAL(12,2) DEFAULT NULL','g_neg_mantenimiento DECIMAL(12,2) DEFAULT NULL',
            'g_neg_otros DECIMAL(12,2) DEFAULT NULL','g_neg_imprevistos DECIMAL(12,2) DEFAULT NULL',
            'o_ing_conyuge DECIMAL(12,2) DEFAULT NULL','o_ing_arriendos DECIMAL(12,2) DEFAULT NULL',
            'o_ing_pensiones DECIMAL(12,2) DEFAULT NULL','o_ing_otros DECIMAL(12,2) DEFAULT NULL',
            'g_fam_alim DECIMAL(12,2) DEFAULT NULL','g_fam_arriendo DECIMAL(12,2) DEFAULT NULL',
            'g_fam_serv_bas DECIMAL(12,2) DEFAULT NULL','g_fam_educacion DECIMAL(12,2) DEFAULT NULL',
            'g_fam_salud DECIMAL(12,2) DEFAULT NULL','g_fam_otros DECIMAL(12,2) DEFAULT NULL',
            'g_fam_imprevistos DECIMAL(12,2) DEFAULT NULL','otras_deudas_json LONGTEXT DEFAULT NULL',
            'vehiculos_negocio_json LONGTEXT DEFAULT NULL','vehiculos_hogar_json LONGTEXT DEFAULT NULL',
            'inmuebles_negocio_json LONGTEXT DEFAULT NULL','inmuebles_hogar_json LONGTEXT DEFAULT NULL',
            'venta_lunes DECIMAL(12,2) DEFAULT NULL','venta_martes DECIMAL(12,2) DEFAULT NULL',
            'venta_miercoles DECIMAL(12,2) DEFAULT NULL','venta_jueves DECIMAL(12,2) DEFAULT NULL',
            'venta_viernes DECIMAL(12,2) DEFAULT NULL',
            'compra_lunes DECIMAL(12,2) DEFAULT NULL','compra_martes DECIMAL(12,2) DEFAULT NULL',
            'compra_miercoles DECIMAL(12,2) DEFAULT NULL','compra_jueves DECIMAL(12,2) DEFAULT NULL',
            'compra_viernes DECIMAL(12,2) DEFAULT NULL',
            'dia_lunes TINYINT(1) DEFAULT 0','dia_martes TINYINT(1) DEFAULT 0',
            'dia_miercoles TINYINT(1) DEFAULT 0','dia_jueves TINYINT(1) DEFAULT 0',
            'dia_viernes TINYINT(1) DEFAULT 0',
            'comercio_productos_json LONGTEXT DEFAULT NULL',
            'productos_json LONGTEXT DEFAULT NULL',
            'activos_negocio_json LONGTEXT DEFAULT NULL',
            'activos_hogar_json LONGTEXT DEFAULT NULL',
            'caja_efectivo DECIMAL(12,2) DEFAULT NULL',
            'bancos_saldo DECIMAL(12,2) DEFAULT NULL',
            'cxp_netas DECIMAL(12,2) DEFAULT NULL',
            'inv_mat_prima DECIMAL(12,2) DEFAULT NULL',
            'inv_prod_proc DECIMAL(12,2) DEFAULT NULL',
            'creditos_pagar DECIMAL(12,2) DEFAULT NULL',
            'proveedores DECIMAL(12,2) DEFAULT NULL',
            'otras_deudas_cp DECIMAL(12,2) DEFAULT NULL',
            'pasivos_lp DECIMAL(12,2) DEFAULT NULL',
            'p1_conoce_institucion TINYINT(1) DEFAULT NULL',
            'p1_obs TEXT DEFAULT NULL',
            'p2_es_cliente TINYINT(1) DEFAULT NULL',
            'p2_producto VARCHAR(255) DEFAULT NULL',
            'p2_obs TEXT DEFAULT NULL',
            'p3_satisfaccion VARCHAR(50) DEFAULT NULL',
            'p3_obs TEXT DEFAULT NULL'];
        foreach ($newCols as $colDef) {
            $colName = explode(' ', $colDef)[0];
            @$conn->query("ALTER TABLE encuesta_negocio ADD COLUMN IF NOT EXISTS $colDef");
        }

        // Pre-crear columnas faltantes en encuesta_comercial para asegurar persistencia de checkboxes
        $cols_faltantes_comercial = [
            "que_busca_agilidad" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_agilidad TINYINT(1) DEFAULT 0",
            "que_busca_cajeros" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_cajeros TINYINT(1) DEFAULT 0",
            "que_busca_banca_linea" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_banca_linea TINYINT(1) DEFAULT 0",
            "que_busca_agencias" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_agencias TINYINT(1) DEFAULT 0",
            "que_busca_credito_rapido" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_credito_rapido TINYINT(1) DEFAULT 0",
            "que_busca_tarjeta_debito" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_tarjeta_debito TINYINT(1) DEFAULT 0",
            "que_busca_tarjeta_credito"    => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_tarjeta_credito TINYINT(1) DEFAULT 0",
            "que_busca_tasas_competitivas" => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_tasas_competitivas TINYINT(1) DEFAULT 0",
            "que_busca_otro"               => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_otro TINYINT(1) DEFAULT 0",
            "que_busca_otro_texto"         => "ALTER TABLE encuesta_comercial ADD COLUMN que_busca_otro_texto TEXT DEFAULT NULL",
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

        // Normalizar null → 0
        $venta_lv_n   = $venta_lv      ?? 0.0;
        $venta_sab_n  = $venta_sabado  ?? 0.0;
        $venta_dom_n  = $venta_domingo ?? 0.0;
        $compra_lv_n  = $compra_lv     ?? 0.0;
        $compra_sab_n = $compra_sabado ?? 0.0;
        $compra_dom_n = $compra_domingo?? 0.0;
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
        $gfa_n  = $g_fam_alim      ?? 0.0; $gfar_n = $g_fam_arriendo  ?? 0.0;
        $gfb_n  = $g_fam_serv_bas  ?? 0.0; $gfe_n  = $g_fam_educacion ?? 0.0;
        $gfs_n  = $g_fam_salud     ?? 0.0; $gfo_n  = $g_fam_otros     ?? 0.0;
        $gfi_n  = $g_fam_imprevistos ?? 0.0;
        // Balance General
        $caja_n    = $caja_efectivo   ?? 0.0; $banco_n = $bancos_saldo  ?? 0.0;
        $cxp_n     = $cxp_netas       ?? 0.0; $imp_n   = $inv_mat_prima ?? 0.0;
        $ipp_n     = $inv_prod_proc   ?? 0.0;
        // Pasivo de la empresa
        $credpag_n = $creditos_pagar  ?? 0.0;
        $prov_n    = $proveedores     ?? 0.0;
        $otrcp_n   = $otras_deudas_cp ?? 0.0;
        $paslp_n   = $pasivos_lp      ?? 0.0;

        // ¿Existe ya una fila para esta tarea?
        $st = $conn->prepare('SELECT id FROM encuesta_negocio WHERE tarea_id = ? LIMIT 1');
        $st->bind_param('s', $tarea_id);
        $st->execute();
        $rowN = $st->get_result()->fetch_assoc();
        $st->close();

        if ($rowN) {
            $stN = $conn->prepare(
                "UPDATE encuesta_negocio
                 SET venta_lv=?, venta_sabado=?, venta_domingo=?,
                     mes_alta_venta=?, mes_baja_venta=?,
                     compra_lv=?, compra_sabado=?, compra_domingo=?, mes_alta_compra=?,
                     dia_lv=?, dia_sab=?, dia_dom=?,
                     pct_contado=?, pct_credito=?, pct_efectivo=?,
                     recuperacion_credito=?, costos_ventas=?, gastos_negocio=?, otros_ingresos=?, gastos_familiares=?,
                     g_neg_sueldos=?, g_neg_arriendo=?, g_neg_serv_bas=?, g_neg_transporte=?, g_neg_mantenimiento=?, g_neg_otros=?, g_neg_imprevistos=?,
                     o_ing_conyuge=?, o_ing_arriendos=?, o_ing_pensiones=?, o_ing_otros=?,
                     g_fam_alim=?, g_fam_arriendo=?, g_fam_serv_bas=?, g_fam_educacion=?, g_fam_salud=?, g_fam_otros=?, g_fam_imprevistos=?,
                     otras_deudas_json=?,
                     vehiculos_negocio_json=?, vehiculos_hogar_json=?,
                     inmuebles_negocio_json=?, inmuebles_hogar_json=?,
                     venta_lunes=?, venta_martes=?, venta_miercoles=?, venta_jueves=?, venta_viernes=?,
                     compra_lunes=?, compra_martes=?, compra_miercoles=?, compra_jueves=?, compra_viernes=?,
                     dia_lunes=?, dia_martes=?, dia_miercoles=?, dia_jueves=?, dia_viernes=?,
                     comercio_productos_json=?, productos_json=?, activos_negocio_json=?, activos_hogar_json=?,
                     caja_efectivo=?, bancos_saldo=?, cxp_netas=?, inv_mat_prima=?, inv_prod_proc=?,
                     creditos_pagar=?, proveedores=?, otras_deudas_cp=?, pasivos_lp=?,
                     p1_conoce_institucion=?, p1_obs=?, p2_es_cliente=?, p2_producto=?, p2_obs=?, p3_satisfaccion=?, p3_obs=?
                 WHERE tarea_id = ?"
            );
            // 79 params
            $stN->bind_param(
                'dddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisisssss',
                $venta_lv_n, $venta_sab_n, $venta_dom_n,
                $mes_alta_venta, $mes_baja_venta,
                $compra_lv_n, $compra_sab_n, $compra_dom_n, $mes_alta_compra,
                $dia_lv, $dia_sab, $dia_dom,
                $pct_cont_n, $pct_cred_n, $pct_efec_n,
                $recup_n, $costos_n, $gastos_n, $otros_n, $gfam_n,
                $gns_n, $gna_n, $gnb_n, $gnt_n, $gnm_n, $gno_n, $gni_n,
                $oic_n, $oia_n, $oip_n, $oio_n,
                $gfa_n, $gfar_n, $gfb_n, $gfe_n, $gfs_n, $gfo_n, $gfi_n,
                $otras_deudas_json,
                $vehiculos_negocio_json, $vehiculos_hogar_json,
                $inmuebles_negocio_json, $inmuebles_hogar_json,
                $venta_lunes, $venta_martes, $venta_miercoles, $venta_jueves, $venta_viernes,
                $compra_lunes, $compra_martes, $compra_miercoles, $compra_jueves, $compra_viernes,
                $dia_lunes, $dia_martes, $dia_miercoles, $dia_jueves, $dia_viernes,
                $comercio_productos_json, $productos_json, $activos_negocio_json, $activos_hogar_json,
                $caja_n, $banco_n, $cxp_n, $imp_n, $ipp_n,
                $credpag_n, $prov_n, $otrcp_n, $paslp_n,
                $p1_conoce, $p1_obs, $p2_es_cliente, $p2_producto, $p2_obs, $p3_satisfaccion, $p3_obs,
                $tarea_id
            );
            $stN->execute();
            $stN->close();
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
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            // 73 params -> 80
            $stN->bind_param(
                'ssdddssdddsiiiiiidddddddddddddddddddddddsssssddddddddddiiiiissssdddddddddisissss',
                $negocio_id, $tarea_id,
                $venta_lv_n, $venta_sab_n, $venta_dom_n, $mes_alta_venta, $mes_baja_venta,
                $compra_lv_n, $compra_sab_n, $compra_dom_n, $mes_alta_compra,
                $dia_lv, $dia_sab, $dia_dom,
                $pct_cont_n, $pct_cred_n, $pct_efec_n,
                $recup_n, $costos_n, $gastos_n, $otros_n, $gfam_n,
                $gns_n, $gna_n, $gnb_n, $gnt_n, $gnm_n, $gno_n, $gni_n,
                $oic_n, $oia_n, $oip_n, $oio_n,
                $gfa_n, $gfar_n, $gfb_n, $gfe_n, $gfs_n, $gfo_n, $gfi_n,
                $otras_deudas_json, $vehiculos_negocio_json, $vehiculos_hogar_json,
                $inmuebles_negocio_json, $inmuebles_hogar_json,
                $venta_lunes, $venta_martes, $venta_miercoles, $venta_jueves, $venta_viernes,
                $compra_lunes, $compra_martes, $compra_miercoles, $compra_jueves, $compra_viernes,
                $dia_lunes, $dia_martes, $dia_miercoles, $dia_jueves, $dia_viernes,
                $comercio_productos_json, $productos_json, $activos_negocio_json, $activos_hogar_json,
                $caja_n, $banco_n, $cxp_n, $imp_n, $ipp_n,
                $credpag_n, $prov_n, $otrcp_n, $paslp_n,
                $p1_conoce, $p1_obs, $p2_es_cliente, $p2_producto, $p2_obs, $p3_satisfaccion, $p3_obs
            );
            $stN->execute();
            $stN->close();
        }
    }

    // ── 5. Upsert encuesta_comercial ────────────────────────────
    if ($fue_encuestado) {
        $GLOBALS['phase'] = 'UPSERT_ENCUESTA';

        if ($inst_cred === null && $inst_prod_fin !== null) $inst_cred = $inst_prod_fin;

        if ($interes_cc || $interes_ahorro || $interes_inv || $interes_cred) {
            $nivel_interes = 'alto';
        } elseif ($interes_conocer) {
            $nivel_interes = 'bajo';
        } else {
            $nivel_interes = 'ninguno';
        }

        $extras = [];
        if ($busca_agilidad) $extras[] = 'Agilidad';
        if ($busca_cajeros)  $extras[] = 'Cajeros';
        if ($busca_banca)    $extras[] = 'Banca en línea';
        if ($busca_agencias) $extras[] = 'Agencias';
        if ($busca_credito)  $extras[] = 'Crédito rápido';
        if ($busca_td)       $extras[] = 'T. Débito';
        if ($busca_tc)       $extras[] = 'T. Crédito';
        if ($interes_trabajar !== null) $extras[] = 'Interés trabajar: ' . ($interes_trabajar ? 'Sí' : 'No');
        if ($fecha_venc_cdp !== null)   $extras[] = 'CDP vence: ' . $fecha_venc_cdp;

        $obs_final = $observaciones ?? '';
        if (!empty($extras)) $obs_final = trim($obs_final . "\n" . implode(', ', $extras));

        $f_nuevo = $fecha_acuerdo;
        $int_pro = null;

        // ¿Existe ya la encuesta comercial?
        $st = $conn->prepare('SELECT id FROM encuesta_comercial WHERE tarea_id = ? LIMIT 1');
        $st->bind_param('s', $tarea_id);
        $st->execute();
        $rowE = $st->get_result()->fetch_assoc();
        $st->close();

        if ($rowE) {
            $st = $conn->prepare(
                "UPDATE encuesta_comercial SET
                     mantiene_cuenta_ahorro=?, mantiene_cuenta_corriente=?,
                     tiene_inversiones=?, institucion_inversiones=?, valor_inversion=?,
                     plazo_inversion=?, fecha_vencimiento_inversion=?,
                     interes_propuesta_previa=?, fecha_nuevo_contacto=?,
                     tiene_operaciones_crediticias=?, institucion_credito=?,
                     interes_conocer_productos=?, nivel_interes_captado=?,
                     interes_cc=?, interes_ahorro=?, interes_inversion=?, interes_credito=?,
                     razon_ya_trabaja_institucion=?, razon_desconfia_servicios=?,
                     razon_agusto_actual=?, razon_mala_experiencia=?, razon_otros=?,
                     acuerdo_logrado=?, fecha_acuerdo=?, hora_acuerdo=?, observaciones=?,
                     que_busca_agilidad=?, que_busca_cajeros=?, que_busca_banca_linea=?,
                     que_busca_agencias=?, que_busca_credito_rapido=?, que_busca_tarjeta_debito=?,
                     que_busca_tarjeta_credito=?, que_busca_tasas_competitivas=?, que_busca_otro=?, que_busca_otro_texto=?,
                     interes_trabajar_institucion=?, fecha_vencimiento_cdp=?,
                     banco_ahorro=?, banco_corriente=?
                 WHERE tarea_id = ?"
            );
            // Array dinámico — imposible de desajustar
            $ec_upd_vals = [
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
                $busca_tc, $busca_tasas, $busca_otro, $busca_otro_texto, // i i i s
                $interes_trabajar, $fecha_venc_cdp,              // i s
                $banco_ahorro, $banco_corriente,                 // s s
                $tarea_id,                                       // s (WHERE)
            ];
            $ec_upd_types = 'ii' . 'isd' . 'ss' . 'is' . 'is' . 'is' . 'iiii' . 'iiii' . 's' . 'ssss' . 'iii' . 'iii' . 'iiis' . 'is' . 'ss' . 's';
            $st->bind_param($ec_upd_types, ...$ec_upd_vals);
            // Ensure DB ENUMs include expected values (avoid Data truncated errors).
            // If ALTER fails or lacks privileges, FALLBACK the value to 'otro' when the current
            // $acuerdo is not present in the column definition (prevents Data truncated errors).
            try {
                $col = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE 'acuerdo_logrado'")->fetch_assoc();
                if ($col && (strpos($col['Type'], "'tasas_competitivas'") === false || strpos($col['Type'], "'levantamiento'") === false)) {
                    $conn->query("ALTER TABLE encuesta_comercial MODIFY COLUMN acuerdo_logrado ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','levantamiento','otro','tasas_competitivas') NULL");
                }
                $col2 = $conn->query("SHOW COLUMNS FROM acuerdo_visita LIKE 'tipo_acuerdo'")->fetch_assoc();
                if ($col2 && (strpos($col2['Type'], "'tasas_competitivas'") === false || strpos($col2['Type'], "'levantamiento'") === false)) {
                    $conn->query("ALTER TABLE acuerdo_visita MODIFY COLUMN tipo_acuerdo ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','levantamiento','otro','tasas_competitivas') NOT NULL");
                }

                // Defensive: if the $acuerdo token is not present in the encuesta_comercial enum,
                // fallback to 'otro' to avoid INSERT/UPDATE truncation errors.
                $tmpLogDir = __DIR__ . '/tmp'; if (!is_dir($tmpLogDir)) @mkdir($tmpLogDir, 0777, true);
                $colType = $col['Type'] ?? '';
                if (!empty($colType) && $acuerdo !== null && strpos($colType, "'" . $acuerdo . "'") === false) {
                    $prev = $acuerdo;
                    $acuerdo = 'otro';
                    @file_put_contents($tmpLogDir . '/acuerdo_debug.log', json_encode(['ts'=>date('c'),'tarea_id'=>$tarea_id,'fall_back_from'=>$prev,'to'=>$acuerdo,'reason'=>'enum_missing_update']) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            } catch (\Throwable $_) {
                // non-fatal attempt to self-heal DB; if we cannot inspect, defensively fallback
                $tmpLogDir = __DIR__ . '/tmp'; if (!is_dir($tmpLogDir)) @mkdir($tmpLogDir, 0777, true);
                if ($acuerdo !== null && $acuerdo !== 'otro') {
                    $prev = $acuerdo;
                    $acuerdo = 'otro';
                    @file_put_contents($tmpLogDir . '/acuerdo_debug.log', json_encode(['ts'=>date('c'),'tarea_id'=>$tarea_id,'fall_back_from'=>$prev,'to'=>$acuerdo,'reason'=>'enum_check_failed']) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }

            // debug: log incoming/mapped acuerdo values before update
            $tmpLogDir = __DIR__ . '/tmp'; if (!is_dir($tmpLogDir)) @mkdir($tmpLogDir, 0777, true);
            $dbg = ['ts'=>date('c'),'tarea_id'=>$tarea_id,'incoming_raw'=>$_acuerdo_raw,'incoming_tok'=>$incoming_tok ?? null,'mapped'=>$acuerdo,'op'=>'update'];
            @file_put_contents($tmpLogDir . '/acuerdo_debug.log', json_encode($dbg, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
            $st->execute();
            $st->close();
        } else {
            $enc_id = genUUID();
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
                  que_busca_tarjeta_credito, que_busca_tasas_competitivas, que_busca_otro, que_busca_otro_texto,
                  interes_trabajar_institucion, fecha_vencimiento_cdp,
                  banco_ahorro, banco_corriente)
                 VALUES (?,?, ?,?, ?,?,?, ?,?, ?,?, ?,?, ?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            // Array dinámico — imposible de desajustar
            $ec_ins_vals = [
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
                $busca_tc, $busca_tasas, $busca_otro, $busca_otro_texto, // i i i s
                $interes_trabajar, $fecha_venc_cdp,              // i s
                $banco_ahorro, $banco_corriente,                 // s s
            ];
            $ec_ins_types = 'ss' . 'ii' . 'isd' . 'ss' . 'is' . 'is' . 'is' . 'iiii' . 'iiii' . 's' . 'ssss' . 'iii' . 'iii' . 'iiis' . 'is' . 'ss';
            $st->bind_param($ec_ins_types, ...$ec_ins_vals);
            // Ensure DB ENUMs include expected values (avoid Data truncated errors).
            // If ALTER fails or lacks privileges, FALLBACK the value to 'otro' when the current
            // $acuerdo is not present in the column definition (prevents Data truncated errors).
            try {
                $col = $conn->query("SHOW COLUMNS FROM encuesta_comercial LIKE 'acuerdo_logrado'")->fetch_assoc();
                if ($col && (strpos($col['Type'], "'tasas_competitivas'") === false || strpos($col['Type'], "'levantamiento'") === false)) {
                    $conn->query("ALTER TABLE encuesta_comercial MODIFY COLUMN acuerdo_logrado ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','levantamiento','otro','tasas_competitivas') NULL");
                }
                $col2 = $conn->query("SHOW COLUMNS FROM acuerdo_visita LIKE 'tipo_acuerdo'")->fetch_assoc();
                if ($col2 && (strpos($col2['Type'], "'tasas_competitivas'") === false || strpos($col2['Type'], "'levantamiento'") === false)) {
                    $conn->query("ALTER TABLE acuerdo_visita MODIFY COLUMN tipo_acuerdo ENUM('nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','levantamiento','otro','tasas_competitivas') NOT NULL");
                }

                // Defensive: if the $acuerdo token is not present in the encuesta_comercial enum,
                // fallback to 'otro' to avoid INSERT/UPDATE truncation errors.
                $tmpLogDir = __DIR__ . '/tmp'; if (!is_dir($tmpLogDir)) @mkdir($tmpLogDir, 0777, true);
                $colType = $col['Type'] ?? '';
                if (!empty($colType) && $acuerdo !== null && strpos($colType, "'" . $acuerdo . "'") === false) {
                    $prev = $acuerdo;
                    $acuerdo = 'otro';
                    @file_put_contents($tmpLogDir . '/acuerdo_debug.log', json_encode(['ts'=>date('c'),'tarea_id'=>$tarea_id,'fall_back_from'=>$prev,'to'=>$acuerdo,'reason'=>'enum_missing_insert']) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            } catch (\Throwable $_) {
                // non-fatal attempt to self-heal DB; if we cannot inspect, defensively fallback
                $tmpLogDir = __DIR__ . '/tmp'; if (!is_dir($tmpLogDir)) @mkdir($tmpLogDir, 0777, true);
                if ($acuerdo !== null && $acuerdo !== 'otro') {
                    $prev = $acuerdo;
                    $acuerdo = 'otro';
                    @file_put_contents($tmpLogDir . '/acuerdo_debug.log', json_encode(['ts'=>date('c'),'tarea_id'=>$tarea_id,'fall_back_from'=>$prev,'to'=>$acuerdo,'reason'=>'enum_check_failed_insert']) . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }

            // debug: log incoming/mapped acuerdo values before insert
            $tmpLogDir = __DIR__ . '/tmp'; if (!is_dir($tmpLogDir)) @mkdir($tmpLogDir, 0777, true);
            $dbg = ['ts'=>date('c'),'tarea_id'=>$tarea_id,'incoming_raw'=>$_acuerdo_raw,'incoming_tok'=>$incoming_tok ?? null,'mapped'=>$acuerdo,'op'=>'insert'];
            @file_put_contents($tmpLogDir . '/acuerdo_debug.log', json_encode($dbg, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
            $st->execute();
            $st->close();
        }

        // ── 6. Acuerdo de visita (upsert) ──────────────────────
        if ($acuerdo !== null && $fecha_acuerdo !== null) {
            $GLOBALS['phase'] = 'UPSERT_ACUERDO';

            $st = $conn->prepare('SELECT id FROM acuerdo_visita WHERE tarea_id = ? LIMIT 1');
            $st->bind_param('s', $tarea_id);
            $st->execute();
            $rowA = $st->get_result()->fetch_assoc();
            $st->close();

            if ($rowA) {
                $st = $conn->prepare(
                    'UPDATE acuerdo_visita SET tipo_acuerdo=?, fecha=?, hora=? WHERE tarea_id=?'
                );
                $st->bind_param('ssss', $acuerdo, $fecha_acuerdo, $hora_acuerdo, $tarea_id);
                $st->execute();
                $st->close();
            } else {
                $av_id = genUUID();
                $st = $conn->prepare(
                    'INSERT INTO acuerdo_visita (id, tarea_id, tipo_acuerdo, fecha, hora)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $st->bind_param('sssss', $av_id, $tarea_id, $acuerdo, $fecha_acuerdo, $hora_acuerdo);
                $st->execute();
                $st->close();
            }
        } else {
            // Si no hay acuerdo (null/vacío), borrar cualquier acuerdo previo
            $st = $conn->prepare('DELETE FROM acuerdo_visita WHERE tarea_id = ?');
            $st->bind_param('s', $tarea_id);
            $st->execute();
            $st->close();
        }
    }

    // ── 7. Alerta de modificación ───────────────────────────────
    $GLOBALS['phase'] = 'ALERTA';
    try {
        $conn->query("
            CREATE TABLE IF NOT EXISTS alerta_modificacion (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $sup_id = null;
        $stSup = $conn->prepare('SELECT supervisor_id FROM asesor WHERE id = ? LIMIT 1');
        if ($stSup) {
            $stSup->bind_param('s', $asesor_id);
            $stSup->execute();
            $rowSup = $stSup->get_result()->fetch_assoc();
            if ($rowSup) $sup_id = $rowSup['supervisor_id'] ?: null;
            $stSup->close();
        }

        $asesor_nombre_alerta = '';
        $stNm = $conn->prepare('SELECT u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ? LIMIT 1');
        if ($stNm) {
            $stNm->bind_param('s', $asesor_id);
            $stNm->execute();
            $rowNm = $stNm->get_result()->fetch_assoc();
            if ($rowNm) $asesor_nombre_alerta = $rowNm['nombre'];
            $stNm->close();
        }

        // --- SNAPSHOT: estado posterior (nuevo) ---
        $GLOBALS['phase'] = 'SNAP_NEW';
        $new_snapshot = [
            'cliente' => null,
            'encuesta_comercial' => null,
            'encuesta_negocio' => null,
            'acuerdo_visita' => null,
        ];
        try {
            if ($cliente_id !== '') {
                $s = $conn->prepare('SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1');
                $s->bind_param('s', $cliente_id);
                $s->execute();
                $new_snapshot['cliente'] = $s->get_result()->fetch_assoc() ?: null;
                $s->close();
            }
            $s = $conn->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
            $s->bind_param('s', $tarea_id);
            $s->execute();
            $new_snapshot['encuesta_comercial'] = $s->get_result()->fetch_assoc() ?: null;
            $s->close();

            $s = $conn->prepare('SELECT * FROM encuesta_negocio WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
            $s->bind_param('s', $tarea_id);
            $s->execute();
            $new_snapshot['encuesta_negocio'] = $s->get_result()->fetch_assoc() ?: null;
            $s->close();

            $s = $conn->prepare('SELECT * FROM acuerdo_visita WHERE tarea_id = ? ORDER BY id DESC LIMIT 1');
            $s->bind_param('s', $tarea_id);
            $s->execute();
            $new_snapshot['acuerdo_visita'] = $s->get_result()->fetch_assoc() ?: null;
            $s->close();
        } catch (\Throwable $_) {
            // ignore non-fatal snapshot failures
        }

        // Prepare JSON values (fallback to short summary if encoding fails)
        $val_new_json = @json_encode($new_snapshot, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($val_new_json === false || $val_new_json === null) {
            // try to extract phone/email from new snapshot cliente
            $cphone = '';
            $cemail = '';
            if (!empty($new_snapshot['cliente']) && is_array($new_snapshot['cliente'])) {
                $c = $new_snapshot['cliente'];
                $cphone = $c['telefono'] ?? $c['telefono2'] ?? '';
                $cemail = $c['email'] ?? $c['email_cliente'] ?? '';
                $cliente_name_new = ($c['nombre'] ?? $c['nombre_completo'] ?? $nombre_completo);
            } else {
                $cliente_name_new = $nombre_completo;
            }
            $parts = ["Asesor: $asesor_nombre_alerta", "Cliente: $cliente_name_new", "Tarea: $tarea_id", "Fecha: " . date('d/m/Y H:i')];
            if ($cphone) $parts[] = 'Tel: ' . $cphone;
            if ($cemail) $parts[] = 'Email: ' . $cemail;
            $val_new_json = json_encode(['summary' => implode(' | ', $parts), 'partial' => true], JSON_UNESCAPED_UNICODE);
        }

        // Update the provisional alerta record setting valor_nuevo
        try {
            if (isset($alerta_id)) {
                $stUpd = $conn->prepare('UPDATE alerta_modificacion SET valor_nuevo = ? WHERE id = ?');
                if ($stUpd) {
                    $stUpd->bind_param('ss', $val_new_json, $alerta_id);
                    $stUpd->execute();
                    $stUpd->close();
                }
            }
        } catch (\Throwable $_) {
            // ignore update failure
        }
    } catch (\Throwable $eAl) {
        error_log('[actualizar_encuesta_completa] Alerta: ' . $eAl->getMessage());
    }
}

    $conn->commit();
    $GLOBALS['phase'] = 'DONE';

    respond_json(200, [
        'status'          => 'success',
        'message'         => $debe_finalizar ? 'Tarea finalizada correctamente' : 'Encuesta actualizada correctamente',
        'tarea_id'        => $tarea_id,
        'cliente_id'      => $cliente_id,
        'finalizada_ahora'=> $debe_finalizar,
        'estado_previo'   => $estadoPrev,
    ]);

} catch (\Throwable $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        try { $conn->rollback(); } catch (\Throwable $_) {}
    }
    $phase = $GLOBALS['phase'] ?? 'UNKNOWN';
    error_log('[actualizar_encuesta_completa][phase=' . $phase . '] ' . $e);
    $dbg_resp = [
        'incoming_raw' => $_acuerdo_raw ?? null,
        'incoming_tok' => $incoming_tok ?? null,
        'mapped'       => $acuerdo ?? null,
    ];
    error_log('[actualizar_encuesta_completa][acuerdo_debug] ' . json_encode($dbg_resp, JSON_UNESCAPED_UNICODE));
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

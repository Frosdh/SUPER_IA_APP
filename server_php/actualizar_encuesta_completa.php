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

$API_BUILD = '2026-04-21a';
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
$nombre_empresa  = strOrNull($_POST['nombre_empresa'] ?? '');
$origen_prospecto = strOrNull($_POST['origen_prospecto'] ?? '');
if ($origen_prospecto !== null) {
    $origen_prospecto = strtolower($origen_prospecto);
    if (!in_array($origen_prospecto, ['frio','seguidor'], true)) $origen_prospecto = null;
}

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
$busca_tc           = (int)($_POST['que_busca_tarjeta_credito'] ?? 0);
$fecha_venc_cdp     = strOrNull($_POST['fecha_vencimiento_cdp'] ?? '');
$interes_trabajar   = intOrNull($_POST['interes_trabajar_institucion'] ?? null);
$acuerdo            = strOrNull($_POST['acuerdo_logrado'] ?? '') ?? 'ninguno';
$fecha_acuerdo      = strOrNull($_POST['fecha_acuerdo']   ?? '');
$hora_acuerdo       = strOrNull($_POST['hora_acuerdo']    ?? '');
$observaciones      = strOrNull($_POST['observaciones']   ?? '');

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
$recuperacion_credito = floatOrNull($_POST['recuperacion_credito'] ?? '');
$costos_ventas        = floatOrNull($_POST['costos_ventas']        ?? '');
$gastos_negocio       = floatOrNull($_POST['gastos_negocio']       ?? '');
$otros_ingresos       = floatOrNull($_POST['otros_ingresos']       ?? '');
$gastos_familiares    = floatOrNull($_POST['gastos_familiares']    ?? '');

// Validar acuerdo
$acuerdos_ok = ['nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo'];
if (!in_array($acuerdo, $acuerdos_ok)) $acuerdo = 'ninguno';

try {
    $GLOBALS['phase'] = 'LOAD_TAREA';

    // ── 1. Recuperar tarea y cliente actual ─────────────────────
    $st = $conn->prepare('SELECT asesor_id, cliente_prospecto_id, estado FROM tarea WHERE id = ? LIMIT 1');
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

    // Insert provisional alerta with valor_anterior so admin can see backup even before updates finish
    try {
        $campo_mod = 'Modificación de encuesta finalizada';
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
        $st = $conn->prepare(
            "UPDATE cliente_prospecto
             SET nombre=?, telefono=?, telefono2=?, email=?, direccion=?, ciudad=COALESCE(?, ciudad),
                 actividad=?, nombre_empresa=?, tiene_ruc=?, tiene_rise=?,
                 origen_prospecto=COALESCE(?, origen_prospecto)
             WHERE id=?"
        );
        $st->bind_param('ssssssssiiss',
            $nombre_completo, $telefono, $celular, $email_c, $direccion, $ciudad,
            $actividad, $nombre_empresa, $tiene_ruc, $tiene_rise, $origen_prospecto, $cliente_id
        );
        $st->execute();
        $st->close();
    }

    // ── 3. Actualizar tarea (observaciones y GPS) ───────────────
    $GLOBALS['phase'] = 'UPDATE_TAREA';
    $obs_tarea = $observaciones ?? '';

    // Solo actualiza GPS si vienen explícitamente
    if ($lat_ini !== null || $lng_ini !== null || $lat_fin !== null || $lng_fin !== null) {
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
        $st->execute();
        $st->close();
    } else {
        $st = $conn->prepare("UPDATE tarea SET observaciones=? WHERE id=?");
        $st->bind_param('ss', $obs_tarea, $tarea_id);
        $st->execute();
        $st->close();
    }

    // ── 4. Upsert encuesta_negocio (si tiene_empresa=1) ─────────
    if ($tiene_empresa_post === 1) {
        $GLOBALS['phase'] = 'UPSERT_NEGOCIO';

        // Asegurar tabla
        $conn->query(
            "CREATE TABLE IF NOT EXISTS encuesta_negocio (
                id                 CHAR(36)   NOT NULL PRIMARY KEY,
                tarea_id           CHAR(36)   NOT NULL,
                venta_lv           DECIMAL(12,2) DEFAULT NULL,
                venta_sabado       DECIMAL(12,2) DEFAULT NULL,
                venta_domingo      DECIMAL(12,2) DEFAULT NULL,
                mes_alta_venta     VARCHAR(20) DEFAULT NULL,
                mes_baja_venta     VARCHAR(20) DEFAULT NULL,
                compra_lv          DECIMAL(12,2) DEFAULT NULL,
                compra_sabado      DECIMAL(12,2) DEFAULT NULL,
                compra_domingo     DECIMAL(12,2) DEFAULT NULL,
                mes_alta_compra    VARCHAR(20) DEFAULT NULL,
                dia_lv             TINYINT(1)  NOT NULL DEFAULT 0,
                dia_sab            TINYINT(1)  NOT NULL DEFAULT 0,
                dia_dom            TINYINT(1)  NOT NULL DEFAULT 0,
                pct_contado        INT DEFAULT NULL,
                pct_credito        INT DEFAULT NULL,
                recuperacion_credito DECIMAL(12,2) DEFAULT NULL,
                costos_ventas        DECIMAL(12,2) DEFAULT NULL,
                gastos_negocio       DECIMAL(12,2) DEFAULT NULL,
                otros_ingresos       DECIMAL(12,2) DEFAULT NULL,
                gastos_familiares    DECIMAL(12,2) DEFAULT NULL,
                created_at         DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_en_tarea (tarea_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Normalizar null → 0 para bind_param
        $venta_lv_n   = $venta_lv      ?? 0.0;
        $venta_sab_n  = $venta_sabado  ?? 0.0;
        $venta_dom_n  = $venta_domingo ?? 0.0;
        $compra_lv_n  = $compra_lv     ?? 0.0;
        $compra_sab_n = $compra_sabado ?? 0.0;
        $compra_dom_n = $compra_domingo?? 0.0;
        $pct_cont_n   = $pct_contado   ?? 0;
        $pct_cred_n   = $pct_credito   ?? 0;
        $recup_n      = $recuperacion_credito ?? 0.0;
        $costos_n     = $costos_ventas    ?? 0.0;
        $gastos_n     = $gastos_negocio   ?? 0.0;
        $otros_n      = $otros_ingresos   ?? 0.0;
        $gfam_n       = $gastos_familiares?? 0.0;

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
                     pct_contado=?, pct_credito=?,
                     recuperacion_credito=?, costos_ventas=?, gastos_negocio=?, otros_ingresos=?, gastos_familiares=?
                 WHERE tarea_id = ?"
            );
            // 19 columnas + tarea_id = 20 params
            // ddd ss ddd s iii ii ddddd s
            $stN->bind_param(
                'dddssdddsiiiiiddddds',
                $venta_lv_n, $venta_sab_n, $venta_dom_n,
                $mes_alta_venta, $mes_baja_venta,
                $compra_lv_n, $compra_sab_n, $compra_dom_n, $mes_alta_compra,
                $dia_lv, $dia_sab, $dia_dom,
                $pct_cont_n, $pct_cred_n,
                $recup_n, $costos_n, $gastos_n, $otros_n, $gfam_n,
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
                  pct_contado, pct_credito,
                  recuperacion_credito, costos_ventas, gastos_negocio, otros_ingresos, gastos_familiares)
                 VALUES (?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?, ?,?,?,?,?)"
            );
            $stN->bind_param(
                'ssdddssdddsiiiiiddddd',
                $negocio_id, $tarea_id,
                $venta_lv_n, $venta_sab_n, $venta_dom_n, $mes_alta_venta, $mes_baja_venta,
                $compra_lv_n, $compra_sab_n, $compra_dom_n, $mes_alta_compra,
                $dia_lv, $dia_sab, $dia_dom,
                $pct_cont_n, $pct_cred_n,
                $recup_n, $costos_n, $gastos_n, $otros_n, $gfam_n
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
                     acuerdo_logrado=?, fecha_acuerdo=?, hora_acuerdo=?, observaciones=?
                 WHERE tarea_id = ?"
            );
            // 26 cols + tarea_id = 27 params
            // ii isdss isis is iiii iiii s ssss s
            $st->bind_param(
                'iiisdssisisisiiiiiiiisssss' . 's',
                $mantiene_ahorro, $mantiene_corriente,
                $tiene_inversiones, $inst_inv, $valor_inv,
                $plazo_inv, $fecha_venc_inv,
                $int_pro, $f_nuevo,
                $tiene_ops_cred, $inst_cred,
                $interes_conocer, $nivel_interes,
                $interes_cc, $interes_ahorro, $interes_inv, $interes_cred,
                $razon_ya_trabaja, $razon_desconfia, $razon_agusto, $razon_mala_exp,
                $razon_otros,
                $acuerdo, $fecha_acuerdo, $hora_acuerdo, $obs_final,
                $tarea_id
            );
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
                  acuerdo_logrado, fecha_acuerdo, hora_acuerdo, observaciones)
                 VALUES (?,?, ?,?, ?,?,?, ?,?, ?,?, ?,?, ?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?,?)"
            );
            $st->bind_param('ssiiisdssisisisiiiiiiiisssss',
                $enc_id, $tarea_id,
                $mantiene_ahorro, $mantiene_corriente,
                $tiene_inversiones, $inst_inv, $valor_inv,
                $plazo_inv, $fecha_venc_inv,
                $int_pro, $f_nuevo,
                $tiene_ops_cred, $inst_cred,
                $interes_conocer, $nivel_interes,
                $interes_cc, $interes_ahorro, $interes_inv, $interes_cred,
                $razon_ya_trabaja, $razon_desconfia, $razon_agusto, $razon_mala_exp,
                $razon_otros,
                $acuerdo, $fecha_acuerdo, $hora_acuerdo, $obs_final
            );
            $st->execute();
            $st->close();
        }

        // ── 6. Acuerdo de visita (upsert) ──────────────────────
        if ($acuerdo !== 'ninguno' && $fecha_acuerdo !== null) {
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
            // Si el acuerdo actual queda "ninguno", borrar cualquier acuerdo previo
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

    $conn->commit();
    $GLOBALS['phase'] = 'DONE';

    respond_json(200, [
        'status'    => 'success',
        'message'   => 'Encuesta actualizada correctamente',
        'tarea_id'  => $tarea_id,
        'cliente_id'=> $cliente_id,
    ]);

} catch (\Throwable $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        try { $conn->rollback(); } catch (\Throwable $_) {}
    }
    $phase = $GLOBALS['phase'] ?? 'UNKNOWN';
    error_log('[actualizar_encuesta_completa][phase=' . $phase . '] ' . $e);
    respond_json(200, [
        'status'  => 'error',
        'message' => 'Error del servidor [' . $phase . ']: ' . substr($e->getMessage(), 0, 200),
        'phase'   => $phase,
    ]);
} finally {
    if (isset($conn)) { try { $conn->close(); } catch (\Throwable $_) {} }
}
?>

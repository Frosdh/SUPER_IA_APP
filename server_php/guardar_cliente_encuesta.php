<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Compatibilidad (hosts antiguos)
if (!defined('JSON_UNESCAPED_UNICODE')) {
    define('JSON_UNESCAPED_UNICODE', 0);
}

if (!function_exists('http_response_code')) {
    function http_response_code($code = null) {
        if ($code !== null) {
            header('X-PHP-Response-Code: ' . (int)$code, true, (int)$code);
        }
        return null;
    }
}

$API_BUILD = '2026-04-14a';
$GLOBALS['API_BUILD'] = $API_BUILD;

function respond_json($code, $payload) {
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (is_array($payload) && !isset($payload['build'])) {
        $payload['build'] = isset($GLOBALS['API_BUILD']) ? $GLOBALS['API_BUILD'] : null;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

// Ping rápido para verificar despliegue en hosting
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, array(
        'status' => 'ok',
        'message' => 'guardar_cliente_encuesta.php alive',
        'build' => $API_BUILD,
        'php' => PHP_VERSION,
    ));
    exit;
}

function newErrorRef() {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(4));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes(4));
    }
    return substr(md5(uniqid('', true)), 0, 8);
}

// Capturar fatales (parse/error, undefined function, etc.) para que nunca quede body vacío.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) return;

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $errType = isset($err['type']) ? (int)$err['type'] : 0;
    if (!in_array($errType, $fatalTypes, true)) return;

    $ref = newErrorRef();
    $phaseSafe = isset($GLOBALS['phase']) ? $GLOBALS['phase'] : 'UNKNOWN';

    // Log para diagnóstico en el hosting
    $errMsg = isset($err['message']) ? $err['message'] : '';
    $errFile = isset($err['file']) ? $err['file'] : '';
    $errLine = isset($err['line']) ? $err['line'] : '';
    @error_log('[guardar_cliente_encuesta][FATAL][phase=' . $phaseSafe . '][ref=' . $ref . '] ' . $errMsg . ' in ' . $errFile . ':' . $errLine);

    // Intentar devolver JSON en caso de fatal
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    $msg = (string)$errMsg;
    if (strlen($msg) > 180) {
        $msg = substr($msg, 0, 180) . '...';
    }

    echo json_encode(array(
        'status' => 'error',
        'message' => 'Error interno del servidor (ref: ' . $ref . ')',
        'phase' => $phaseSafe,
        'last_error' => $msg,
        'build' => isset($GLOBALS['API_BUILD']) ? $GLOBALS['API_BUILD'] : null,
    ), JSON_UNESCAPED_UNICODE);
});

set_exception_handler(function ($e) {
    $ref = newErrorRef();
    $phaseSafe = isset($GLOBALS['phase']) ? $GLOBALS['phase'] : 'UNKNOWN';
    @error_log('[guardar_cliente_encuesta][UNCAUGHT][phase=' . $phaseSafe . '][ref=' . $ref . '] ' . $e);
    respond_json(200, array(
        'status' => 'error',
        'message' => 'Error interno del servidor (ref: ' . $ref . ')',
        'phase' => $phaseSafe,
    ));
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

// Forzar errores de MySQLi como excepciones (capturables)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function genUUID() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function strOrNull($v) {
    $v = trim((string)$v);
    return $v !== '' ? $v : null;
}

function intOrNull($v) {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function floatOrNull($v) {
    if ($v === null || $v === '') return null;
    return (float)$v;
}

// ── Inputs ──────────────────────────────────────────────────
$usuario_id = isset($_POST['usuario_id']) ? trim($_POST['usuario_id']) : '';
$asesor_id_in = isset($_POST['asesor_id']) ? trim($_POST['asesor_id']) : '';
$tipo_tarea = isset($_POST['tipo_tarea']) ? trim($_POST['tipo_tarea']) : 'prospecto_nuevo';
$fue_encuestado = isset($_POST['fue_encuestado']) ? (int)$_POST['fue_encuestado'] : 1;

// Cliente
$nombre    = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$apellidos = isset($_POST['apellidos']) ? trim($_POST['apellidos']) : '';
$nombre_completo = trim("$nombre $apellidos");
if ($nombre_completo === '') $nombre_completo = $nombre;
$cedula   = strOrNull(isset($_POST['cedula']) ? $_POST['cedula'] : '');
$telefono = strOrNull(isset($_POST['telefono']) ? $_POST['telefono'] : '');
$celular  = strOrNull(isset($_POST['celular']) ? $_POST['celular'] : '');
$email_c  = strOrNull(isset($_POST['email_cliente']) ? $_POST['email_cliente'] : '');
$direccion = strOrNull(isset($_POST['direccion']) ? $_POST['direccion'] : '');
$actividad = strOrNull(isset($_POST['actividad']) ? $_POST['actividad'] : '');
$tiene_ruc = isset($_POST['tiene_ruc']) ? (int)$_POST['tiene_ruc'] : 0;
$tiene_rise = isset($_POST['tiene_rise']) ? (int)$_POST['tiene_rise'] : 0;
$tiene_empresa = isset($_POST['tiene_empresa']) ? (int)$_POST['tiene_empresa'] : 0;
$nombre_empresa = strOrNull(isset($_POST['nombre_empresa']) ? $_POST['nombre_empresa'] : '');

// Validar actividad enum (cliente_prospecto.actividad)
$actividades_validas = ['negocio_propio', 'empleado_privado', 'empleado_publico', 'profesional'];
if ($actividad !== null && !in_array($actividad, $actividades_validas, true)) {
    $actividad = null;
}

// GPS
$lat_ini = floatOrNull(isset($_POST['latitud_inicio']) ? $_POST['latitud_inicio'] : '');
$lng_ini = floatOrNull(isset($_POST['longitud_inicio']) ? $_POST['longitud_inicio'] : '');
$lat_fin = floatOrNull(isset($_POST['latitud_fin']) ? $_POST['latitud_fin'] : '');
$lng_fin = floatOrNull(isset($_POST['longitud_fin']) ? $_POST['longitud_fin'] : '');

// Para bind_param es más seguro enviar coords/decimales como string (o NULL)
$lat_ini_s = $lat_ini !== null ? (string)$lat_ini : null;
$lng_ini_s = $lng_ini !== null ? (string)$lng_ini : null;
$lat_fin_s = $lat_fin !== null ? (string)$lat_fin : null;
$lng_fin_s = $lng_fin !== null ? (string)$lng_fin : null;

// Encuesta comercial
$mantiene_ahorro = isset($_POST['mantiene_cuenta_ahorro']) ? (int)$_POST['mantiene_cuenta_ahorro'] : 0;
$mantiene_corriente = isset($_POST['mantiene_cuenta_corriente']) ? (int)$_POST['mantiene_cuenta_corriente'] : 0;
$tiene_inversiones = intOrNull(isset($_POST['tiene_inversiones']) ? $_POST['tiene_inversiones'] : null);
$inst_inv = strOrNull(isset($_POST['institucion_inversiones']) ? $_POST['institucion_inversiones'] : '');
$valor_inv = floatOrNull(isset($_POST['valor_inversion']) ? $_POST['valor_inversion'] : '');
$plazo_inv = strOrNull(isset($_POST['plazo_inversion']) ? $_POST['plazo_inversion'] : '');
$fecha_venc_inv = strOrNull(isset($_POST['fecha_vencimiento_inversion']) ? $_POST['fecha_vencimiento_inversion'] : '');
$tiene_ops_cred = intOrNull(isset($_POST['tiene_operaciones_crediticias']) ? $_POST['tiene_operaciones_crediticias'] : null);
$inst_cred = strOrNull(isset($_POST['institucion_credito']) ? $_POST['institucion_credito'] : '');
$mantiene_prod_fin = intOrNull(isset($_POST['mantiene_producto_financiero']) ? $_POST['mantiene_producto_financiero'] : null);
$inst_prod_fin = strOrNull(isset($_POST['institucion_producto_financiero']) ? $_POST['institucion_producto_financiero'] : '');
$interes_conocer = intOrNull(isset($_POST['interes_conocer_productos']) ? $_POST['interes_conocer_productos'] : null);
$nivel_interes_in = strOrNull(isset($_POST['nivel_interes']) ? $_POST['nivel_interes'] : '');
$nivel_interes = ($nivel_interes_in !== null) ? $nivel_interes_in : 'ninguno';
$interes_cc = isset($_POST['interes_cc']) ? (int)$_POST['interes_cc'] : 0;
$interes_ahorro = isset($_POST['interes_ahorro']) ? (int)$_POST['interes_ahorro'] : 0;
$interes_inv = isset($_POST['interes_inversion']) ? (int)$_POST['interes_inversion'] : 0;
$interes_cred = isset($_POST['interes_credito']) ? (int)$_POST['interes_credito'] : 0;
$razon_ya_trabaja = isset($_POST['razon_ya_trabaja_institucion']) ? (int)$_POST['razon_ya_trabaja_institucion'] : 0;
$razon_desconfia = isset($_POST['razon_desconfia_servicios']) ? (int)$_POST['razon_desconfia_servicios'] : 0;
$razon_agusto = isset($_POST['razon_agusto_actual']) ? (int)$_POST['razon_agusto_actual'] : 0;
$razon_mala_exp = isset($_POST['razon_mala_experiencia']) ? (int)$_POST['razon_mala_experiencia'] : 0;
$razon_otros = strOrNull(isset($_POST['razon_otros']) ? $_POST['razon_otros'] : '');
$interes_trabajar = intOrNull(isset($_POST['interes_trabajar_institucion']) ? $_POST['interes_trabajar_institucion'] : null);
$busca_agilidad = isset($_POST['que_busca_agilidad']) ? (int)$_POST['que_busca_agilidad'] : 0;
$busca_cajeros = isset($_POST['que_busca_cajeros']) ? (int)$_POST['que_busca_cajeros'] : 0;
$busca_banca = isset($_POST['que_busca_banca_linea']) ? (int)$_POST['que_busca_banca_linea'] : 0;
$busca_agencias = isset($_POST['que_busca_agencias']) ? (int)$_POST['que_busca_agencias'] : 0;
$busca_credito = isset($_POST['que_busca_credito_rapido']) ? (int)$_POST['que_busca_credito_rapido'] : 0;
$busca_td = isset($_POST['que_busca_tarjeta_debito']) ? (int)$_POST['que_busca_tarjeta_debito'] : 0;
$busca_tc = isset($_POST['que_busca_tarjeta_credito']) ? (int)$_POST['que_busca_tarjeta_credito'] : 0;
$fecha_venc_cdp = strOrNull(isset($_POST['fecha_vencimiento_cdp']) ? $_POST['fecha_vencimiento_cdp'] : '');
$acuerdo_in = strOrNull(isset($_POST['acuerdo_logrado']) ? $_POST['acuerdo_logrado'] : '');
$acuerdo = ($acuerdo_in !== null) ? $acuerdo_in : 'ninguno';
$fecha_acuerdo = strOrNull(isset($_POST['fecha_acuerdo']) ? $_POST['fecha_acuerdo'] : '');
$hora_acuerdo = strOrNull(isset($_POST['hora_acuerdo']) ? $_POST['hora_acuerdo'] : '');
$observaciones = strOrNull(isset($_POST['observaciones']) ? $_POST['observaciones'] : '');

$valor_inv_s = $valor_inv !== null ? (string)$valor_inv : null;

// Validar acuerdo enum
$acuerdos_validos = ['nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo'];
if (!in_array($acuerdo, $acuerdos_validos)) $acuerdo = 'ninguno';

// Validar tipo_tarea enum
$tipos_validos = ['prospecto_nuevo','visita_frio','evaluacion','recuperacion','documentos_pendientes','post_venta','nueva_cita_campo','nueva_cita_oficina','levantamiento'];
if (!in_array($tipo_tarea, $tipos_validos)) $tipo_tarea = 'prospecto_nuevo';

// ── Validaciones básicas ─────────────────────────────────────
if ($usuario_id === '' && $asesor_id_in === '') {
    respond_json(200, ['status' => 'error', 'message' => 'usuario_id o asesor_id requerido']);
    exit;
}
if ($nombre_completo === '' && $fue_encuestado) {
    respond_json(200, ['status' => 'error', 'message' => 'El nombre del cliente es requerido']);
    exit;
}

try {
    $phase = 'INIT';
    $GLOBALS['phase'] = $phase;

    // 1. Resolver asesor_id (preferir asesor_id explícito; fallback a usuario_id)
    $phase = 'ASESOR_RESOLVE';
    $GLOBALS['phase'] = $phase;
    $asesor_id = null;

    if ($asesor_id_in !== '') {
        $stmt = $conn->prepare("SELECT id FROM asesor WHERE id = ? LIMIT 1");
        $stmt->bind_param('s', $asesor_id_in);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $asesor_id = $res->fetch_assoc()['id'];
        }
        $stmt->close();
    }

    if ($asesor_id === null) {
        if ($usuario_id === '') {
            respond_json(200, ['status' => 'error', 'message' => 'No se pudo resolver asesor (usuario_id vacío y asesor_id inválido).']);
            exit;
        }
        $stmt = $conn->prepare("SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1");
        $stmt->bind_param('s', $usuario_id);
        $stmt->execute();
        $res = $stmt->get_result();

        if (!$res || $res->num_rows === 0) {
            respond_json(200, [
                'status' => 'error',
                'message' => 'Asesor no encontrado para este usuario. Verifique que la cuenta tenga rol asesor.',
            ]);
            exit;
        }
        $asesor_id  = $res->fetch_assoc()['id'];
        $stmt->close();
    }

    // Guardar todo o nada
    $conn->begin_transaction();

    // 2. Crear o actualizar cliente_prospecto
    $cliente_id = null;

    if ($cedula !== null) {
        $phase = 'CLIENTE_SELECT';
        $GLOBALS['phase'] = $phase;
        $stmt = $conn->prepare("SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1");
        $stmt->bind_param('s', $cedula);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $cliente_id = $res->fetch_assoc()['id'];
        }
        $stmt->close();
    }

    if ($cliente_id === null) {
        $phase = 'CLIENTE_INSERT';
        $GLOBALS['phase'] = $phase;
        $cliente_id = genUUID();
        $stmt = $conn->prepare(
            "INSERT INTO cliente_prospecto
             (id, nombre, cedula, telefono, telefono2, email, direccion, actividad, nombre_empresa, tiene_ruc, tiene_rise, asesor_id, latitud, longitud, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'prospecto')"
        );
        // 9 strings + 2 ints + 3 strings = 14 params
        $stmt->bind_param(
            'sssssssssiisss',
            $cliente_id,
            $nombre_completo,
            $cedula,
            $telefono,
            $celular,
            $email_c,
            $direccion,
            $actividad,
            $nombre_empresa,
            $tiene_ruc,
            $tiene_rise,
            $asesor_id,
            $lat_ini_s,
            $lng_ini_s
        );
        $stmt->execute();
        $stmt->close();
    } else {
        // Actualizar datos existentes
        $phase = 'CLIENTE_UPDATE';
        $GLOBALS['phase'] = $phase;
        $stmt = $conn->prepare(
            "UPDATE cliente_prospecto
             SET nombre=?, telefono=?, telefono2=?, email=?, direccion=?,
                 actividad=?, nombre_empresa=?, tiene_ruc=?, tiene_rise=?, asesor_id=?
             WHERE id=?"
        );
        // Tipos: s=string, i=int. asesor_id es UUID string → 's'
        $stmt->bind_param('sssssssiiss',
            $nombre_completo, $telefono, $celular, $email_c, $direccion,
            $actividad, $nombre_empresa, $tiene_ruc, $tiene_rise, $asesor_id, $cliente_id
        );
        $stmt->execute();
        $stmt->close();
    }

    // 3. Crear tarea
    $phase = 'TAREA_INSERT';
    $GLOBALS['phase'] = $phase;
    $tarea_id    = genUUID();
    $fecha_hoy   = date('Y-m-d');
    $hora_hoy    = date('H:i:s');
    $est_tarea   = 'completada';
    $obs_tarea = ($observaciones !== null) ? $observaciones : ($fue_encuestado ? '' : 'Cliente no quiso ser encuestado');

    $stmt = $conn->prepare(
        "INSERT INTO tarea
         (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
          fecha_programada, hora_programada, fecha_realizada, hora_realizada,
          latitud_inicio, longitud_inicio, latitud_fin, longitud_fin, observaciones)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    // Enviar coords como string/NULL para evitar ValueError en PHP 8.x
    $stmt->bind_param(
        'ssssssssssssss',
        $tarea_id,
        $asesor_id,
        $cliente_id,
        $tipo_tarea,
        $est_tarea,
        $fecha_hoy,
        $hora_hoy,
        $fecha_hoy,
        $hora_hoy,
        $lat_ini_s,
        $lng_ini_s,
        $lat_fin_s,
        $lng_fin_s,
        $obs_tarea
    );
    $stmt->execute();
    $stmt->close();

    // 4. Crear encuesta_comercial si fue encuestado
    if ($fue_encuestado) {
        $phase = 'ENCUESTA_INSERT';
        $GLOBALS['phase'] = $phase;
        $enc_id = genUUID();

        // Consolidar institución (mantiene_producto_financiero y cuenta)
        // inst_cred puede ser inst_prod_fin si se llenó ese campo
        if ($inst_cred === null && $inst_prod_fin !== null) {
            $inst_cred = $inst_prod_fin;
        }

        // nivel_interes derivado de los checkboxes de interés
        if ($interes_cc || $interes_ahorro || $interes_inv || $interes_cred) {
            $nivel_interes = 'alto';
        } elseif ($interes_conocer) {
            $nivel_interes = 'bajo';
        } else {
            $nivel_interes = 'ninguno';
        }

        // Agregar "qué busca" en observaciones si hay datos
        $extra_obs = [];
        if ($busca_agilidad)  $extra_obs[] = 'Agilidad';
        if ($busca_cajeros)   $extra_obs[] = 'Cajeros';
        if ($busca_banca)     $extra_obs[] = 'Banca en línea';
        if ($busca_agencias)  $extra_obs[] = 'Agencias en sector';
        if ($busca_credito)   $extra_obs[] = 'Crédito rápido';
        if ($busca_td)        $extra_obs[] = 'Tarjeta débito';
        if ($busca_tc)        $extra_obs[] = 'Tarjeta crédito';
        if ($interes_trabajar !== null) {
            $extra_obs[] = 'Interés trabajar con institución: ' . ($interes_trabajar ? 'Sí' : 'No');
        }
        if ($fecha_venc_cdp !== null) {
            $extra_obs[] = 'CDP vence: ' . $fecha_venc_cdp;
        }

        $obs_final = ($observaciones !== null) ? $observaciones : '';
        if (!empty($extra_obs)) {
            $obs_final = trim($obs_final . "\n" . implode(', ', $extra_obs));
        }

        $stmt = $conn->prepare(
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
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $interes_propuesta = null;
        $fecha_nuevo_c     = $fecha_acuerdo;

        // 28 params
        // Tipos (28): ss + iii + ssss + i + s + i + s + i + s + iiiiiiii + sssss
        // Nota: el 4to campo tras los ids es institucion_inversiones (string), no int.
        $stmt->bind_param(
            'ssiiissssisisisiiiiiiiisssss',
    $enc_id,
    $tarea_id,
    $mantiene_ahorro,
    $mantiene_corriente,
    $tiene_inversiones,
    $inst_inv,
    $valor_inv_s,
    $plazo_inv,
    $fecha_venc_inv,
    $interes_propuesta,
    $fecha_nuevo_c,
    $tiene_ops_cred,
    $inst_cred,
    $interes_conocer,
    $nivel_interes,
    $interes_cc,
    $interes_ahorro,
    $interes_inv,
    $interes_cred,
    $razon_ya_trabaja,
    $razon_desconfia,
    $razon_agusto,
    $razon_mala_exp,
    $razon_otros,
    $acuerdo,
    $fecha_acuerdo,
    $hora_acuerdo,
    $obs_final
        );
        $stmt->execute();
        $stmt->close();

        // 5. Crear acuerdo_visita si hay acuerdo != ninguno
        if ($acuerdo !== 'ninguno' && $fecha_acuerdo !== null) {
            $phase = 'ACUERDO_INSERT';
            $GLOBALS['phase'] = $phase;
            $av_id = genUUID();
            $stmt = $conn->prepare(
                "INSERT INTO acuerdo_visita (id, tarea_id, tipo_acuerdo, fecha, hora)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('sssss', $av_id, $tarea_id, $acuerdo, $fecha_acuerdo, $hora_acuerdo);
            $stmt->execute();
            $stmt->close();
        }
    }

    // Confirmar primero, luego responder (evita decir "success" si el commit falla)
    $conn->commit();

    respond_json(200, [
        'status'     => 'success',
        'message'    => $fue_encuestado ? 'Encuesta guardada correctamente' : 'Tarea registrada (sin encuesta)',
        'tarea_id'   => $tarea_id,
        'cliente_id' => $cliente_id,
    ]);

} catch (Exception $e) {
    // No exponer detalles internos al cliente (evita filtrar SQL/paths).
    $ref = newErrorRef();
    $phaseSafe = isset($phase) ? $phase : 'UNKNOWN';
    @error_log('[guardar_cliente_encuesta][' . $phaseSafe . '][ref=' . $ref . '] ' . $e);

    $errno = null;
    $sqlstate = null;
    if (class_exists('mysqli_sql_exception') && ($e instanceof mysqli_sql_exception)) {
        $errno = (int)$e->getCode();
        if (method_exists($e, 'getSqlState')) {
            $sqlstate = $e->getSqlState();
        }
    }

    if (isset($conn)) {
        @mysqli_rollback($conn);
    }

    respond_json(200, array(
        'status' => 'error',
        'message' => 'Error interno del servidor (ref: ' . $ref . ')',
        'phase' => $phaseSafe,
        'errno' => $errno,
        'sqlstate' => $sqlstate,
    ));
} finally {
    if (isset($conn)) $conn->close();
}
?>

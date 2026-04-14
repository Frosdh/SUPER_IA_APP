<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

function genUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function strOrNull(string $v): ?string {
    $v = trim($v);
    return $v !== '' ? $v : null;
}

function intOrNull(?string $v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function floatOrNull(?string $v): ?float {
    if ($v === null || $v === '') return null;
    return (float)$v;
}

// ── Inputs ──────────────────────────────────────────────────
$usuario_id   = trim($_POST['usuario_id']  ?? '');
$tipo_tarea   = trim($_POST['tipo_tarea']  ?? 'prospecto_nuevo');
$fue_encuestado = (int)($_POST['fue_encuestado'] ?? 1);

// Cliente
$nombre       = trim($_POST['nombre']    ?? '');
$apellidos    = trim($_POST['apellidos'] ?? '');
$nombre_completo = trim("$nombre $apellidos");
if ($nombre_completo === '') $nombre_completo = $nombre;
$cedula       = strOrNull($_POST['cedula']   ?? '');
$telefono     = strOrNull($_POST['telefono'] ?? '');
$celular      = strOrNull($_POST['celular']  ?? '');
$email_c      = strOrNull($_POST['email_cliente'] ?? '');
$direccion    = strOrNull($_POST['direccion']      ?? '');
$actividad    = strOrNull($_POST['actividad']      ?? '');
$tiene_ruc    = (int)($_POST['tiene_ruc']  ?? 0);
$tiene_rise   = (int)($_POST['tiene_rise'] ?? 0);
$tiene_empresa = (int)($_POST['tiene_empresa'] ?? 0);
$nombre_empresa = strOrNull($_POST['nombre_empresa'] ?? '');

// GPS
$lat_ini  = floatOrNull($_POST['latitud_inicio']  ?? '');
$lng_ini  = floatOrNull($_POST['longitud_inicio'] ?? '');
$lat_fin  = floatOrNull($_POST['latitud_fin']     ?? '');
$lng_fin  = floatOrNull($_POST['longitud_fin']    ?? '');

// Encuesta comercial
$mantiene_ahorro      = (int)($_POST['mantiene_cuenta_ahorro']    ?? 0);
$mantiene_corriente   = (int)($_POST['mantiene_cuenta_corriente'] ?? 0);
$tiene_inversiones    = intOrNull($_POST['tiene_inversiones']     ?? null);
$inst_inv             = strOrNull($_POST['institucion_inversiones'] ?? '');
$valor_inv            = floatOrNull($_POST['valor_inversion']     ?? '');
$plazo_inv            = strOrNull($_POST['plazo_inversion']       ?? '');
$fecha_venc_inv       = strOrNull($_POST['fecha_vencimiento_inversion'] ?? '');
$tiene_ops_cred       = intOrNull($_POST['tiene_operaciones_crediticias'] ?? null);
$inst_cred            = strOrNull($_POST['institucion_credito']   ?? '');
$mantiene_prod_fin    = intOrNull($_POST['mantiene_producto_financiero'] ?? null);
$inst_prod_fin        = strOrNull($_POST['institucion_producto_financiero'] ?? '');
$interes_conocer      = intOrNull($_POST['interes_conocer_productos'] ?? null);
$nivel_interes        = strOrNull($_POST['nivel_interes'] ?? '') ?? 'ninguno';
$interes_cc           = (int)($_POST['interes_cc']       ?? 0);
$interes_ahorro       = (int)($_POST['interes_ahorro']   ?? 0);
$interes_inv          = (int)($_POST['interes_inversion'] ?? 0);
$interes_cred         = (int)($_POST['interes_credito']  ?? 0);
$razon_ya_trabaja     = (int)($_POST['razon_ya_trabaja_institucion'] ?? 0);
$razon_desconfia      = (int)($_POST['razon_desconfia_servicios']   ?? 0);
$razon_agusto         = (int)($_POST['razon_agusto_actual']          ?? 0);
$razon_mala_exp       = (int)($_POST['razon_mala_experiencia']       ?? 0);
$razon_otros          = strOrNull($_POST['razon_otros'] ?? '');
$interes_trabajar     = intOrNull($_POST['interes_trabajar_institucion'] ?? null);
$busca_agilidad       = (int)($_POST['que_busca_agilidad']      ?? 0);
$busca_cajeros        = (int)($_POST['que_busca_cajeros']        ?? 0);
$busca_banca          = (int)($_POST['que_busca_banca_linea']    ?? 0);
$busca_agencias       = (int)($_POST['que_busca_agencias']       ?? 0);
$busca_credito        = (int)($_POST['que_busca_credito_rapido'] ?? 0);
$busca_td             = (int)($_POST['que_busca_tarjeta_debito'] ?? 0);
$busca_tc             = (int)($_POST['que_busca_tarjeta_credito'] ?? 0);
$fecha_venc_cdp       = strOrNull($_POST['fecha_vencimiento_cdp'] ?? '');
$acuerdo              = strOrNull($_POST['acuerdo_logrado'] ?? '') ?? 'ninguno';
$fecha_acuerdo        = strOrNull($_POST['fecha_acuerdo']   ?? '');
$hora_acuerdo         = strOrNull($_POST['hora_acuerdo']    ?? '');
$observaciones        = strOrNull($_POST['observaciones']   ?? '');

// Validar acuerdo enum
$acuerdos_validos = ['nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo'];
if (!in_array($acuerdo, $acuerdos_validos)) $acuerdo = 'ninguno';

// Validar tipo_tarea enum
$tipos_validos = ['prospecto_nuevo','visita_frio','evaluacion','recuperacion','documentos_pendientes','post_venta','nueva_cita_campo','nueva_cita_oficina','levantamiento'];
if (!in_array($tipo_tarea, $tipos_validos)) $tipo_tarea = 'prospecto_nuevo';

// ── Validaciones básicas ─────────────────────────────────────
if ($usuario_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id requerido']);
    exit;
}
if ($nombre_completo === '' && $fue_encuestado) {
    echo json_encode(['status' => 'error', 'message' => 'El nombre del cliente es requerido']);
    exit;
}

try {
    // 1. Obtener asesor_id desde usuario_id
    $stmt = $conn->prepare("SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1");
    $stmt->bind_param('s', $usuario_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Asesor no encontrado para este usuario. Verifique que la cuenta tenga rol asesor.']);
        exit;
    }
    $asesor_row = $res->fetch_assoc();
    $asesor_id  = $asesor_row['id'];
    $stmt->close();

    // 2. Crear o actualizar cliente_prospecto
    $cliente_id = null;

    if ($cedula !== null) {
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
        $cliente_id = genUUID();
        $stmt = $conn->prepare(
            "INSERT INTO cliente_prospecto
             (id, nombre, cedula, telefono, telefono2, email, direccion, actividad, nombre_empresa, tiene_ruc, tiene_rise, asesor_id, latitud, longitud, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'prospecto')"
        );
        $stmt->bind_param('sssssssssiiisdd',
            $cliente_id, $nombre_completo, $cedula, $telefono, $celular,
            $email_c, $direccion, $actividad, $nombre_empresa,
            $tiene_ruc, $tiene_rise, $asesor_id, $lat_ini, $lng_ini
        );
        $stmt->execute();
        $stmt->close();
    } else {
        // Actualizar datos existentes
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
    $tarea_id    = genUUID();
    $fecha_hoy   = date('Y-m-d');
    $hora_hoy    = date('H:i:s');
    $est_tarea   = 'completada';
    $obs_tarea   = $observaciones ?? ($fue_encuestado ? '' : 'Cliente no quiso ser encuestado');

    $stmt = $conn->prepare(
        "INSERT INTO tarea
         (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
          fecha_programada, hora_programada, fecha_realizada, hora_realizada,
          latitud_inicio, longitud_inicio, latitud_fin, longitud_fin, observaciones)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    // id, asesor_id, cliente_id, tipo, estado, f_prog, h_prog, f_real, h_real = 9 strings
    // lat_ini, lng_ini, lat_fin, lng_fin = 4 doubles, obs = string
    $stmt->bind_param('sssssssssddds',
        $tarea_id, $asesor_id, $cliente_id, $tipo_tarea, $est_tarea,
        $fecha_hoy, $hora_hoy, $fecha_hoy, $hora_hoy,
        $lat_ini, $lng_ini, $lat_fin, $lng_fin, $obs_tarea
    );
    $stmt->execute();
    $stmt->close();

    // 4. Crear encuesta_comercial si fue encuestado
    if ($fue_encuestado) {
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

        $obs_final = $observaciones ?? '';
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

        $stmt->bind_param('ssiiiisdssissiiiiiiiiiiissss',
            $enc_id, $tarea_id,
            $mantiene_ahorro, $mantiene_corriente,
            $tiene_inversiones, $inst_inv, $valor_inv,
            $plazo_inv, $fecha_venc_inv,
            $interes_propuesta, $fecha_nuevo_c,
            $tiene_ops_cred, $inst_cred,
            $interes_conocer, $nivel_interes,
            $interes_cc, $interes_ahorro, $interes_inv, $interes_cred,
            $razon_ya_trabaja, $razon_desconfia, $razon_agusto, $razon_mala_exp,
            $razon_otros, $acuerdo, $fecha_acuerdo, $hora_acuerdo, $obs_final
        );
        $stmt->execute();
        $stmt->close();

        // 5. Crear acuerdo_visita si hay acuerdo != ninguno
        if ($acuerdo !== 'ninguno' && $fecha_acuerdo !== null) {
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

    echo json_encode([
        'status'     => 'success',
        'message'    => $fue_encuestado ? 'Encuesta guardada correctamente' : 'Tarea registrada (sin encuesta)',
        'tarea_id'   => $tarea_id,
        'cliente_id' => $cliente_id,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error del servidor: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) $conn->close();
}
?>

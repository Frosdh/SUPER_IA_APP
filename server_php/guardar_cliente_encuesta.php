<?php
// ============================================================
// guardar_cliente_encuesta.php  —  v2026-04-14b  (FIXED)
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-04-14b';
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
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
$nombre_empresa  = strOrNull($_POST['nombre_empresa'] ?? '');

// Origen del prospecto (solo aplica a prospecto nuevo; se almacena en cliente_prospecto)
$origen_prospecto = strOrNull($_POST['origen_prospecto'] ?? '');
if ($origen_prospecto !== null) {
    $origen_prospecto = strtolower($origen_prospecto);
    $origen_ok = ['frio','seguidor'];
    if (!in_array($origen_prospecto, $origen_ok, true)) $origen_prospecto = null;
}

// Validar actividad
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
$acuerdo            = strOrNull($_POST['acuerdo_logrado'] ?? '') ?? 'ninguno';
$fecha_acuerdo      = strOrNull($_POST['fecha_acuerdo']   ?? '');
$hora_acuerdo       = strOrNull($_POST['hora_acuerdo']    ?? '');
$observaciones      = strOrNull($_POST['observaciones']   ?? '');

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
$pct_contado        = intOrNull($_POST['pct_contado'] ?? null);
$pct_credito        = intOrNull($_POST['pct_credito'] ?? null);
$recuperacion_credito = floatOrNull($_POST['recuperacion_credito'] ?? '');
$costos_ventas        = floatOrNull($_POST['costos_ventas'] ?? '');
$gastos_negocio       = floatOrNull($_POST['gastos_negocio'] ?? '');
$otros_ingresos       = floatOrNull($_POST['otros_ingresos'] ?? '');
$gastos_familiares    = floatOrNull($_POST['gastos_familiares'] ?? '');

// Validar enums
$acuerdos_ok = ['nueva_cita_campo','nueva_cita_oficina','recolectar_documentacion','ninguno','levantamiento_campo'];
if (!in_array($acuerdo, $acuerdos_ok)) $acuerdo = 'ninguno';
$tipos_ok = ['prospecto_nuevo','visita_frio','evaluacion','recuperacion','documentos_pendientes','post_venta','nueva_cita_campo','nueva_cita_oficina','levantamiento'];
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

try {
    // ── 1. Resolver asesor_id ────────────────────────────────
    $GLOBALS['phase'] = 'ASESOR_RESOLVE';
    $asesor_id = null;

    // Asegurar columna para origen de prospecto
    try {
        $conn->query("ALTER TABLE cliente_prospecto ADD COLUMN origen_prospecto VARCHAR(20) DEFAULT NULL");
    } catch (\Throwable $e) {
        // ignorar si ya existe
    }

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
        // INSERT: 15 parámetros
        // id(s) nombre(s) cedula(s) telefono(s) telefono2(s) email(s)
        // direccion(s) ciudad(s) actividad(s) nombre_empresa(s)
        // tiene_ruc(i) tiene_rise(i) asesor_id(s) latitud(d) longitud(d)
        $cliente_id = genUUID();
        $st = $conn->prepare(
            "INSERT INTO cliente_prospecto
             (id, nombre, cedula, telefono, telefono2, email, direccion, ciudad,
              actividad, nombre_empresa, tiene_ruc, tiene_rise, asesor_id,
              latitud, longitud, origen_prospecto, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'prospecto')"
        );
        $st->bind_param('ssssssssssiisdds',
            $cliente_id, $nombre_completo, $cedula, $telefono, $celular,
            $email_c, $direccion, $ciudad, $actividad, $nombre_empresa,
            $tiene_ruc, $tiene_rise, $asesor_id, $lat_ini, $lng_ini, $origen_prospecto
        );
        $st->execute();
        $st->close();
    } else {
        // UPDATE cliente — 12 params: s×8 + i×2 + s×2 = 'ssssssssiiss'
        $st = $conn->prepare(
            "UPDATE cliente_prospecto
             SET nombre=?, telefono=?, telefono2=?, email=?, direccion=?, ciudad=COALESCE(?, ciudad),
                 actividad=?, nombre_empresa=?, tiene_ruc=?, tiene_rise=?, asesor_id=?,
                 origen_prospecto=COALESCE(?, origen_prospecto)
             WHERE id=?"
        );
        $st->bind_param('ssssssssiisss',
            $nombre_completo, $telefono, $celular, $email_c, $direccion, $ciudad,
            $actividad, $nombre_empresa, $tiene_ruc, $tiene_rise, $asesor_id, $origen_prospecto, $cliente_id
        );
        $st->execute();
        $st->close();
    }

    // ── 3. Crear tarea ───────────────────────────────────────
    $GLOBALS['phase'] = 'TAREA';
    $tarea_id  = genUUID();
    $fecha_hoy = date('Y-m-d');
    $hora_hoy    = date('H:i:s');
    $obs_tarea   = $observaciones ?? ($fue_encuestado ? '' : 'Cliente no quiso ser encuestado');
    $est_tarea   = 'completada';   // ← variable requerida (PHP 8 no acepta literales en bind_param)
    $fecha_prog  = $fecha_hoy;
    $hora_prog   = $hora_hoy;

    // 14 params: 9×s + 4×d + 1×s
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

    // ── 3c. Guardar levantamiento Empresa/Negocio (si aplica) ──
    if ($tiene_empresa_post === 1) {
        $GLOBALS['phase'] = 'NEGOCIO';
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

        $negocio_id = genUUID();
        $stN = $conn->prepare(
            "INSERT INTO encuesta_negocio
             (id, tarea_id,
              venta_lv, venta_sabado, venta_domingo, mes_alta_venta, mes_baja_venta,
              compra_lv, compra_sabado, compra_domingo, mes_alta_compra,
              dia_lv, dia_sab, dia_dom,
              pct_contado, pct_credito,
              recuperacion_credito, costos_ventas, gastos_negocio, otros_ingresos, gastos_familiares)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        // Normalizar null -> 0 para evitar warnings en bind_param numérico
        $venta_lv_n = $venta_lv ?? 0.0;
        $venta_sab_n = $venta_sabado ?? 0.0;
        $venta_dom_n = $venta_domingo ?? 0.0;
        $compra_lv_n = $compra_lv ?? 0.0;
        $compra_sab_n = $compra_sabado ?? 0.0;
        $compra_dom_n = $compra_domingo ?? 0.0;
        $pct_cont_n = $pct_contado ?? 0;
        $pct_cred_n = $pct_credito ?? 0;
        $recup_n = $recuperacion_credito ?? 0.0;
        $costos_n = $costos_ventas ?? 0.0;
        $gastos_n = $gastos_negocio ?? 0.0;
        $otros_n = $otros_ingresos ?? 0.0;
        $gfam_n = $gastos_familiares ?? 0.0;

        // types: ss ddd ss ddd s iiiii ddddd
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

    // ── 3b. Alerta de modificación (solo si el cliente ya existía) ──
    if ($es_cliente_existente && $asesor_id !== null) {
        try {
            // Asegurar tabla
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
        $int_pro = null;            // interes_propuesta_previa

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
        // Formato 28 params (CORRECTO):
        // ss=enc_id,tarea_id  iii=mant_ahorro,mant_corriente,tiene_inv
        // s=inst_inv  d=valor_inv  ss=plazo_inv,fecha_venc_inv
        // i=int_pro  s=f_nuevo  i=tiene_ops_cred  s=inst_cred
        // i=interes_conocer  s=nivel_interes  iiiiiiii=int_cc,ahorro,inv,cred,razon×4
        // sssss=razon_otros,acuerdo,fecha_ac,hora_ac,obs_final
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

        // ── 5. Acuerdo de visita + tarea de seguimiento ─────
        if ($acuerdo !== 'ninguno' && $fecha_acuerdo !== null) {
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

            // Crear una NUEVA tarea programada para el seguimiento
            $tipo_followup = null;
            if ($acuerdo === 'nueva_cita_campo') $tipo_followup = 'nueva_cita_campo';
            elseif ($acuerdo === 'nueva_cita_oficina') $tipo_followup = 'nueva_cita_oficina';
            elseif ($acuerdo === 'recolectar_documentacion') $tipo_followup = 'documentos_pendientes';
            elseif ($acuerdo === 'levantamiento_campo') $tipo_followup = 'levantamiento';

            if ($tipo_followup !== null) {
                $GLOBALS['phase'] = 'TAREA_FOLLOWUP';
                $tarea_followup_id   = genUUID();
                $tarea_followup_tipo = $tipo_followup;
                $tarea_followup_fecha = $fecha_acuerdo;
                $tarea_followup_hora  = $hora_acuerdo;

                $est_follow = 'programada';
                $obs_follow = trim('Seguimiento: ' . str_replace('_', ' ', $acuerdo));

                $st = $conn->prepare(
                    "INSERT INTO tarea
                     (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                      fecha_programada, hora_programada, observaciones)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $st->bind_param('ssssssss',
                    $tarea_followup_id, $asesor_id, $cliente_id, $tarea_followup_tipo,
                    $est_follow, $tarea_followup_fecha, $tarea_followup_hora, $obs_follow
                );
                $st->execute();
                $st->close();
            }
        }
    }

    $conn->commit();
    $GLOBALS['phase'] = 'DONE';

    respond_json(200, [
        'status'     => 'success',
        'message'    => $fue_encuestado ? 'Encuesta guardada correctamente' : 'Tarea registrada (sin encuesta)',
        'tarea_id'   => $tarea_id,
        'cliente_id' => $cliente_id,
        'tarea_followup_id'   => $tarea_followup_id,
        'tarea_followup_tipo' => $tarea_followup_tipo,
        'tarea_followup_fecha'=> $tarea_followup_fecha,
        'tarea_followup_hora' => $tarea_followup_hora,
    ]);

} catch (\Throwable $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        try { $conn->rollback(); } catch (\Throwable $_) {}
    }
    $phase = $GLOBALS['phase'] ?? 'UNKNOWN';
    error_log('[guardar_cliente_encuesta][phase=' . $phase . '] ' . $e);
    respond_json(200, [
        'status'  => 'error',
        'message' => 'Error del servidor [' . $phase . ']: ' . substr($e->getMessage(), 0, 200),
        'phase'   => $phase,
    ]);
} finally {
    if (isset($conn)) { try { $conn->close(); } catch (\Throwable $_) {} }
}
?>

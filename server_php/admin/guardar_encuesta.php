<?php
// ============================================================
// guardar_encuesta.php  —  v2026-05-05  (FIXED)
// Recibe el POST de nueva_encuesta.php y guarda:
//   1. cliente_prospecto   (upsert por cédula o id)
//   2. tarea               (insert nueva o update existente) ← TRANSACTION
//   3. encuesta_comercial  (UPSERT real: insert o update)
//   4. fichas de producto  (ahorro / corriente / inversión / crédito)
//
// BUGS CORREGIDOS:
//   - asesor_id se resolvía como usuario_id → FK inválida → tarea_id null
//   - modo edición hacía UPDATE encuesta aunque no existiera (0 rows afectadas)
//   - sin transacción: tarea se guardaba aunque encuesta fallara
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Auth ─────────────────────────────────────────────────────
if (empty($_SESSION['asesor_logged_in'])) {
    header('Location: login.php?e=session');
    exit;
}

require_once __DIR__ . '/db_admin.php'; // $pdo  (PDO, ERRMODE_EXCEPTION)

// ── Helpers ──────────────────────────────────────────────────
function p(string $k): string  { return trim((string)($_POST[$k] ?? '')); }
function pn(string $k): ?string { $v = p($k); return $v !== '' ? $v : null; }
function pb(string $k): int    { return p($k) === '1' ? 1 : 0; }
function pin(string $k): ?int { $v = trim((string)($_POST[$k] ?? '')); return $v !== '' ? (int)$v : null; }
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ── Redirect helper ───────────────────────────────────────────
function redirect(string $status, string $msg, ?string $tarea_id = null): void {
    $params = ['enc_status' => $status, 'enc_msg' => $msg];
    if ($tarea_id !== null && $tarea_id !== '') {
        $params['tarea_id'] = $tarea_id;
    }
    $q = http_build_query($params);
    header("Location: nueva_encuesta.php?$q");
    exit;
}

// ── Leer datos del formulario ─────────────────────────────────
// Identificadores
$tarea_id_edicion = pn('tarea_id');   // Viene en modo edición
$modo_edicion     = !empty($tarea_id_edicion);
$cliente_id_post  = pn('cliente_id'); // Puede venir del formulario

// Datos personales del prospecto
$nombre      = pn('nombre');
$apellidos   = pn('apellidos');
$nombre_full = trim("$nombre $apellidos");
if ($nombre_full === '') $nombre_full = $nombre;
$cedula      = pn('cedula');
$celular     = pn('celular');
$telefono    = pn('telefono');
$email       = pn('email');
$direccion   = pn('direccion');
$ciudad      = pn('ciudad');
$zona        = pn('zona');
$actividad   = pn('actividad');
$nombre_emp  = pn('nombre_empresa');
$tiene_ruc   = pb('tiene_ruc');
$tiene_rise  = pb('tiene_rise');
$tipo_empresa = pn('tipo_empresa');

$regimen_tributario = pn('regimen_type'); // 'ruc' | 'rise' | 'none'
$numero_ruc = null;
if ($regimen_tributario === 'ruc') {
    $numero_ruc = pn('ruc_numero');
} elseif ($regimen_tributario === 'rise') {
    $numero_ruc = pn('rise_numero');
}

$declara_iva        = pin('ruc_declara_iva');
$emite_facturas     = pin('ruc_emite_facturas');
$lleva_contabilidad = pin('ruc_lleva_contab');

$paga_cuota_rise    = pin('rise_paga_cuota');
$emite_notas_venta  = pin('rise_emite_notas');
$conoce_limite_rise = pin('rise_conoce_limite');

// Propuesta de inversión previa al vencimiento (CDP)
$crear_tarea_prev_venc    = pb('crear_tarea_prev_venc');
$propuesta_inversion      = pn('propuesta_inversion');
$fecha_previa_vencimiento = pn('fecha_previa_vencimiento');
$hora_previa_vencimiento  = pn('hora_previa_vencimiento');
$fecha_vencimiento_cdp    = pn('fecha_vencimiento_cdp');
$lat         = pn('lat');
$lng         = pn('lng');
$tipo_visita = pn('tipo_prospecto');   // frio | seguimiento | null

// Datos de encuesta
$ec_mantiene_ahorro = pb('ec_mantiene_cuenta_ahorro');
$ec_inst_ahorro     = pn('ec_institucion_ahorro');
$ec_saldo_ahorro    = pn('ec_saldo_ahorro');
$ec_mantiene_cc     = pb('ec_mantiene_cuenta_corriente');
$ec_inst_cc         = pn('ec_institucion_corriente');
$ec_inv             = pb('ec_tiene_inversiones');
$ec_inst_inv        = pn('ec_institucion_inversiones');
$ec_valor_inv       = pn('ec_valor_inversion');
$ec_plazo_inv       = pn('ec_plazo_inversion');
$ec_fecha_inv       = pn('ec_fecha_vencimiento_inversion');
$ec_credito         = pb('ec_tiene_operaciones_crediticias');
$ec_inst_cred       = pn('ec_institucion_credito');
$ec_monto_cred      = pn('ec_monto_credito_actual');
$ec_destino_cred    = pn('ec_destino_credito_actual');

// Identificación Institucional (Paso 0)
$p1_conoce        = ($_POST['p1_conoce_institucion'] ?? '') === '1' ? 1 : (($_POST['p1_conoce_institucion'] ?? '') === '0' ? 0 : null);
$p1_obs           = pn('p1_obs');
$p2_es_cliente    = ($_POST['p2_es_cliente'] ?? '') === '1' ? 1 : (($_POST['p2_es_cliente'] ?? '') === '0' ? 0 : null);
$p2_producto      = pn('p2_producto');
$p2_obs           = pn('p2_obs');
$p3_satisfaccion  = pn('p3_satisfaccion');
$p3_obs           = pn('p3_obs');
$interes_conocer  = ($_POST['interesado_productos'] ?? '') === '1' ? 1 : (($_POST['interesado_productos'] ?? '') === '0' ? 0 : null);

// Intereses
$prod_interes  = pn('prod_interes') ?? '';  // comma-sep: ahorro,corriente,inversion,credito
$nivel_interes = pn('nivel_interes');
$int_cc        = (str_contains($prod_interes, 'corriente') ? 1 : 0);
$int_ahorro    = (str_contains($prod_interes, 'ahorro')    ? 1 : 0);
$int_inv       = (str_contains($prod_interes, 'inversion') ? 1 : 0);
$int_cred      = (str_contains($prod_interes, 'credito')   ? 1 : 0);

// Acuerdo y fechas
$raw_acuerdo = pn('acuerdo_logrado') ?? pn('tipo_acuerdo');
$acuerdo_logrado_raw = $raw_acuerdo;

// Map the stored $acuerdo_logrado to strictly match the DB ENUM options of encuesta_comercial/encuesta_crediticia:
// 'nueva_cita_campo','nueva_cita_oficina','reprogramacion','seguimiento','otro','tasas_competitivas'
$valid_db_enums = ['nueva_cita_campo', 'nueva_cita_oficina', 'reprogramacion', 'seguimiento', 'otro', 'tasas_competitivas'];
if ($raw_acuerdo === null || $raw_acuerdo === '' || $raw_acuerdo === 'ninguno') {
    $acuerdo_logrado = null;
} elseif (in_array($raw_acuerdo, $valid_db_enums)) {
    $acuerdo_logrado = $raw_acuerdo;
} elseif ($raw_acuerdo === 'recolectar_documentacion' || $raw_acuerdo === 'levantamiento_campo') {
    $acuerdo_logrado = 'otro';
} else {
    // Map form fallbacks if any
    $acuerdo_map_form = [
        'reprogramacion'         => 'reprogramacion',
        'seguimiento_telefonico' => 'seguimiento',
        'solicitud_credito'      => 'nueva_cita_oficina',
        'apertura_cuenta'        => 'nueva_cita_oficina',
        'sin_interes'            => null,
        'otro'                   => 'otro',
    ];
    $acuerdo_logrado = $acuerdo_map_form[$raw_acuerdo] ?? null;
}

$fecha_acuerdo = pn('fecha_acuerdo');
$hora_acuerdo  = pn('hora_acuerdo');
$fecha_nc      = pn('fecha_nuevo_contacto');
$observaciones = pn('observaciones');

// Qué busca de una institución financiera
$qb_agilidad = isset($_POST['busca_agilidad']) ? 1 : 0;
$qb_cajeros  = isset($_POST['busca_cajeros']) ? 1 : 0;
$qb_banca    = isset($_POST['busca_banca_online']) ? 1 : 0;
$qb_agencias = isset($_POST['busca_agencias']) ? 1 : 0;
$qb_credito  = isset($_POST['busca_credito_rapido']) ? 1 : 0;
$qb_debito   = isset($_POST['busca_tarjeta_debito']) ? 1 : 0;
$qb_cred_t   = isset($_POST['busca_tarjeta_credito']) ? 1 : 0;

// Validación básica
if (!$cedula && !$cliente_id_post) {
    redirect('error', 'La cédula o ID del cliente es obligatoria.');
}

// ═══════════════════════════════════════════════════════════
// PASO 0 — Resolver asesor_id CORRECTAMENTE
//   Prioridad: POST → session(asesor_table_id) → DB lookup por usuario_id
// ═══════════════════════════════════════════════════════════
$asesor_id = null;

// 1) Desde el campo oculto del formulario (más confiable)
$asesor_id_post = pn('asesor_id');
if ($asesor_id_post) {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE id = ? LIMIT 1');
    $st->execute([$asesor_id_post]);
    if ($st->fetchColumn()) $asesor_id = $asesor_id_post;
}

// 2) Desde sesión (asesor_table_id = asesor.id)
if (!$asesor_id && !empty($_SESSION['asesor_table_id'])) {
    $st = $pdo->prepare('SELECT id FROM asesor WHERE id = ? LIMIT 1');
    $st->execute([$_SESSION['asesor_table_id']]);
    if ($st->fetchColumn()) $asesor_id = $_SESSION['asesor_table_id'];
}

// 3) Lookup por usuario_id (si solo tenemos el usuario.id en sesión)
if (!$asesor_id) {
    $uid = $_SESSION['asesor_id'] ?? $_SESSION['usuario_id'] ?? '';
    if ($uid) {
        $st = $pdo->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->execute([$uid]);
        $found = $st->fetchColumn();
        if ($found) {
            $asesor_id = $found;
            $_SESSION['asesor_table_id'] = $found; // guardar para proximas veces
        }
    }
}

if (!$asesor_id) {
    redirect('error', 'No se pudo identificar al asesor. Por favor cierra sesión y vuelve a entrar.');
}

// ═══════════════════════════════════════════════════════════
// INICIO DE TRANSACCIÓN
// ═══════════════════════════════════════════════════════════
try {
    $pdo->beginTransaction();

    // ────────────────────────────────────────────────────────
    // Helpers DB: columnas existentes (evita fallos por esquemas distintos)
    // ────────────────────────────────────────────────────────
    $get_cols = function (string $table) use ($pdo): array {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        // Whitelist básica
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return $cache[$table] = [];
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $cols = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['Field'])) $cols[$r['Field']] = true;
            }
            return $cache[$table] = $cols;
        } catch (PDOException $e) {
            return $cache[$table] = [];
        }
    };

    // ────────────────────────────────────────────────────────
    // PASO 1 — Upsert cliente_prospecto
    // ────────────────────────────────────────────────────────
    $cliente_id = null;

    // Buscar por cédula primero
    if ($cedula) {
        $st = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
        $st->execute([$cedula]);
        $cliente_id = $st->fetchColumn() ?: null;
    }

    // Respaldo: usar id enviado en POST
    if (!$cliente_id && $cliente_id_post) {
        $st = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE id = ? LIMIT 1');
        $st->execute([$cliente_id_post]);
        $cliente_id = $st->fetchColumn() ?: null;
    }

    if ($cliente_id) {
        // UPDATE con mapeo dinámico de columnas para máxima inmunidad de esquema
        $cp_payload = [
            'nombre'             => $nombre_full,
            'telefono'           => $telefono,
            'telefono2'          => $celular,
            'email'              => $email,
            'direccion'          => $direccion,
            'ciudad'             => $ciudad,
            'zona'               => $zona,
            'actividad'          => $actividad,
            'nombre_empresa'     => $nombre_emp,
            'tiene_ruc'          => $tiene_ruc,
            'tiene_rise'         => $tiene_rise,
            'tipo_empresa'       => $tipo_empresa,
            'regimen_tributario' => $regimen_tributario,
            'numero_ruc'         => $numero_ruc,
            'declara_iva'        => $declara_iva,
            'emite_facturas'     => $emite_facturas,
            'lleva_contabilidad' => $lleva_contabilidad,
            'paga_cuota_rise'    => $paga_cuota_rise,
            'emite_notas_venta'  => $emite_notas_venta,
            'conoce_limite_rise' => $conoce_limite_rise,
            'origen_prospecto'   => $tipo_visita,
            'latitud'            => ($lat && is_numeric($lat) ? (float)$lat : null),
            'longitud'           => ($lng && is_numeric($lng) ? (float)$lng : null),
        ];

        $cp_cols = $get_cols('cliente_prospecto');
        $cp_set_cols = [];
        $cp_set_vals = [];
        foreach ($cp_payload as $col => $val) {
            if (isset($cp_cols[$col])) {
                $cp_set_cols[] = $col;
                $cp_set_vals[] = $val;
            }
        }

        $set = implode(', ', array_map(fn($c) => "`$c` = ?", $cp_set_cols));
        if ($set !== '') {
            $pdo->prepare("UPDATE cliente_prospecto SET $set WHERE id = ?")
                ->execute(array_merge($cp_set_vals, [$cliente_id]));
        }
    } else {
        // INSERT nuevo prospecto con mapeo dinámico de columnas
        $cliente_id = uuid4();
        $cp_payload = [
            'nombre'             => $nombre_full,
            'telefono'           => $telefono,
            'telefono2'          => $celular,
            'email'              => $email,
            'direccion'          => $direccion,
            'ciudad'             => $ciudad,
            'zona'               => $zona,
            'actividad'          => $actividad,
            'nombre_empresa'     => $nombre_emp,
            'tiene_ruc'          => $tiene_ruc,
            'tiene_rise'         => $tiene_rise,
            'tipo_empresa'       => $tipo_empresa,
            'regimen_tributario' => $regimen_tributario,
            'numero_ruc'         => $numero_ruc,
            'declara_iva'        => $declara_iva,
            'emite_facturas'     => $emite_facturas,
            'lleva_contabilidad' => $lleva_contabilidad,
            'paga_cuota_rise'    => $paga_cuota_rise,
            'emite_notas_venta'  => $emite_notas_venta,
            'conoce_limite_rise' => $conoce_limite_rise,
            'origen_prospecto'   => $tipo_visita,
            'latitud'            => ($lat && is_numeric($lat) ? (float)$lat : null),
            'longitud'           => ($lng && is_numeric($lng) ? (float)$lng : null),
        ];

        $cp_cols = $get_cols('cliente_prospecto');
        $cols = ['id', 'estado'];
        $vals = [$cliente_id, 'prospecto'];
        if (isset($cp_cols['asesor_id'])) {
            $cols[] = 'asesor_id';
            $vals[] = $asesor_id;
        }
        if ($cedula && isset($cp_cols['cedula'])) {
            $cols[] = 'cedula';
            $vals[] = $cedula;
        }
        foreach ($cp_payload as $col => $val) {
            if ($col === 'id' || $col === 'estado' || $col === 'asesor_id' || $col === 'cedula') continue;
            if (isset($cp_cols[$col])) {
                $cols[] = $col;
                $vals[] = $val;
            }
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $pdo->prepare("INSERT INTO cliente_prospecto ($colList) VALUES ($ph)")
            ->execute($vals);
    }

    // ────────────────────────────────────────────────────────
    // PASO 2 — Crear o actualizar TAREA
    // ────────────────────────────────────────────────────────
    $hoy  = date('Y-m-d');
    $hora = date('H:i:s');

    if ($modo_edicion) {
        // Verificar que la tarea existe y pertenece a este asesor
        $st = $pdo->prepare('SELECT id FROM tarea WHERE id = ? AND asesor_id = ? LIMIT 1');
        $st->execute([$tarea_id_edicion, $asesor_id]);
        if (!$st->fetchColumn()) {
            $pdo->rollBack();
            redirect('error', 'Tarea no encontrada o sin permiso para editarla.');
        }
        $tarea_id = $tarea_id_edicion;

        // Actualizar tarea existente (mantener estado, actualizar cliente si cambió)
        $pdo->prepare("
            UPDATE tarea SET
                cliente_prospecto_id = ?,
                fecha_realizada      = COALESCE(fecha_realizada, ?),
                hora_realizada       = COALESCE(hora_realizada, ?),
                modificada           = 1,
                modificada_at        = NOW(),
                modificada_por       = (SELECT usuario_id FROM asesor WHERE id = ? LIMIT 1)
            WHERE id = ?
        ")->execute([$cliente_id, $hoy, $hora, $asesor_id, $tarea_id]);

    } else {
        // Crear tarea nueva (completada, fecha = hoy)
        $tarea_id = uuid4();
        $tipo_tarea_map = ['frio' => 'visita_frio', 'seguimiento' => 'evaluacion'];
        $tipo_tarea = $tipo_tarea_map[$tipo_visita] ?? 'prospecto_nuevo';
        $pdo->prepare("
            INSERT INTO tarea
                (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                 fecha_programada, hora_programada,
                 fecha_realizada,  hora_realizada,
                 latitud_inicio, longitud_inicio,
                 observaciones)
            VALUES (?,?,?,?,'completada',?,?,?,?,?,?,?)
        ")->execute([
            $tarea_id, $asesor_id, $cliente_id, $tipo_tarea,
            $hoy, $hora, $hoy, $hora,
            ($lat && is_numeric($lat) ? (float)$lat : null),
            ($lng && is_numeric($lng) ? (float)$lng : null),
            $observaciones,
        ]);
    }

    // Verificación crítica — nunca debe ser null aquí
    if (!$tarea_id) {
        $pdo->rollBack();
        redirect('error', 'Error interno: tarea_id no fue generado. Contacte soporte.');
    }

    // ────────────────────────────────────────────────────────
    // PASO 3 — UPSERT encuesta_comercial (INSERT o UPDATE real)
    // ────────────────────────────────────────────────────────
    $enc_cols = $get_cols('encuesta_comercial');
    $st = $pdo->prepare('SELECT id FROM encuesta_comercial WHERE tarea_id = ? LIMIT 1');
    $st->execute([$tarea_id]);
    $enc_existente = $st->fetchColumn();

    $enc_payload = [
        // Identidad / trazabilidad (si existen columnas)
        'cliente_id'         => $cliente_id,
        'cliente_cedula'     => $cedula,
        'asesor_id'          => $asesor_id,
        'usuario_id'         => ($_SESSION['usuario_id'] ?? null),
        // Identificación Institucional (Paso 0)
        'p1_conoce_institucion'     => $p1_conoce,
        'p1_obs'                    => $p1_obs,
        'p2_es_cliente'             => $p2_es_cliente,
        'p2_producto'               => $p2_producto,
        'p2_obs'                    => $p2_obs,
        'p3_satisfaccion'           => $p3_satisfaccion,
        'p3_obs'                    => $p3_obs,
        'interes_conocer_productos' => $interes_conocer,
        // Situación financiera
        'mantiene_cuenta_ahorro'    => $ec_mantiene_ahorro,
        'institucion_ahorro'        => $ec_inst_ahorro,
        'banco_ahorro'              => $ec_inst_ahorro,
        'saldo_ahorro'              => $ec_saldo_ahorro,
        'mantiene_cuenta_corriente' => $ec_mantiene_cc,
        'institucion_corriente'     => $ec_inst_cc,
        'banco_corriente'           => $ec_inst_cc,
        'tiene_inversiones'         => $ec_inv,
        'institucion_inversiones'   => $ec_inst_inv,
        'valor_inversion'           => $ec_valor_inv,
        'plazo_inversion'           => $ec_plazo_inv,
        'fecha_vencimiento_inversion'=> $ec_fecha_inv,
        'tiene_operaciones_crediticias' => $ec_credito,
        'institucion_credito'       => $ec_inst_cred,
        'monto_credito_actual'      => $ec_monto_cred,
        'destino_credito_actual'    => $ec_destino_cred,
        // Interés productos (selecciones)
        'prod_interes'              => $prod_interes,
        'interes_cc'                => $int_cc,
        'interes_ahorro'            => $int_ahorro,
        'interes_inversion'         => $int_inv,
        'interes_credito'           => $int_cred,
        'nivel_interes_captado'     => $nivel_interes,
        // Razones por las que no firmó / no está interesado
        'razon_ya_trabaja'             => pb('ec_razon_ya_trabaja'),
        'razon_ya_trabaja_institucion' => pb('ec_razon_ya_trabaja'),
        'razon_desconfia'              => pb('ec_razon_desconfia'),
        'razon_desconfia_servicios'    => pb('ec_razon_desconfia'),
        'razon_agusto_actual'          => pb('ec_razon_agusto_actual'),
        'razon_mala_experiencia'       => pb('ec_razon_mala_experiencia'),
        'razon_otros'                  => pn('ec_razon_otros'),
        // ¿Qué busca de una institución?
        'que_busca_agilidad'        => $qb_agilidad,
        'que_busca_cajeros'         => $qb_cajeros,
        'que_busca_banca_linea'     => $qb_banca,
        'que_busca_agencias'        => $qb_agencias,
        'que_busca_credito_rapido'  => $qb_credito,
        'que_busca_tarjeta_debito'  => $qb_debito,
        'que_busca_tarjeta_credito' => $qb_cred_t,
        // Cierre
        'acuerdo_logrado'           => $acuerdo_logrado,
        'fecha_acuerdo'             => ($fecha_acuerdo ?: null),
        'hora_acuerdo'              => ($hora_acuerdo ?: null),
        'fecha_nuevo_contacto'      => ($fecha_nc ?: null),
        'observaciones'             => $observaciones,
        // GPS si la tabla lo soporta
        'latitud'                   => ($lat && is_numeric($lat) ? (float)$lat : null),
        'longitud'                  => ($lng && is_numeric($lng) ? (float)$lng : null),
    ];

    // Filtrar solo columnas existentes
    $enc_set_cols = [];
    $enc_set_vals = [];
    foreach ($enc_payload as $col => $val) {
        if (isset($enc_cols[$col])) {
            $enc_set_cols[] = $col;
            $enc_set_vals[] = $val;
        }
    }

    if ($enc_existente) {
        // UPDATE real
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", $enc_set_cols));
        if ($set !== '') {
            $pdo->prepare("UPDATE encuesta_comercial SET $set WHERE tarea_id = ?")
                ->execute(array_merge($enc_set_vals, [$tarea_id]));
        }
        $enc_id = $enc_existente;
    } else {
        // INSERT real (id + tarea_id siempre que existan)
        $enc_id = uuid4();
        $cols = ['id'];
        $vals = [$enc_id];
        if (isset($enc_cols['tarea_id'])) {
            $cols[] = 'tarea_id';
            $vals[] = $tarea_id;
        }
        foreach ($enc_set_cols as $i => $col) {
            if ($col === 'tarea_id' || $col === 'id') continue;
            $cols[] = $col;
            $vals[] = $enc_set_vals[$i];
        }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $pdo->prepare("INSERT INTO encuesta_comercial ($colList) VALUES ($ph)")
            ->execute($vals);
    }

    // ────────────────────────────────────────────────────────
    // PASO 4 — Acuerdo de visita + tarea de seguimiento
    // ────────────────────────────────────────────────────────
    if ($acuerdo_logrado_raw && $acuerdo_logrado_raw !== 'ninguno' && $fecha_acuerdo) {
        // Registrar acuerdo de visita (upsert por tarea_id)
        $st = $pdo->prepare('SELECT id FROM acuerdo_visita WHERE tarea_id = ? LIMIT 1');
        $st->execute([$tarea_id]);
        $av_existente = $st->fetchColumn();

        if ($av_existente) {
            $pdo->prepare("UPDATE acuerdo_visita SET tipo_acuerdo=?, fecha=?, hora=? WHERE id=?")
                ->execute([$acuerdo_logrado_raw, $fecha_acuerdo, $hora_acuerdo, $av_existente]);
        } else {
            $pdo->prepare("INSERT INTO acuerdo_visita (id,tarea_id,tipo_acuerdo,fecha,hora) VALUES (?,?,?,?,?)")
                ->execute([uuid4(), $tarea_id, $acuerdo_logrado_raw, $fecha_acuerdo, $hora_acuerdo]);
        }

        // Crear tarea de seguimiento (solo en modo nuevo)
        if (!$modo_edicion) {
            $tipo_map = [
                'nueva_cita_campo'         => 'nueva_cita_campo',
                'nueva_cita_oficina'       => 'nueva_cita_oficina',
                'recolectar_documentacion' => 'documentos_pendientes',
                'levantamiento_campo'      => 'levantamiento',
                'ninguno'                  => null,
            ];
            $tipo_follow = $tipo_map[$acuerdo_logrado_raw] ?? 'evaluacion';
            if ($tipo_follow) {
                $pdo->prepare("
                    INSERT INTO tarea
                        (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                         fecha_programada, hora_programada, observaciones)
                    VALUES (?,?,?,?,'programada',?,?,?)
                ")->execute([
                    uuid4(), $asesor_id, $cliente_id, $tipo_follow,
                    $fecha_acuerdo, $hora_acuerdo ?: null,
                    "Seguimiento: " . str_replace('_', ' ', $acuerdo_logrado_raw),
                ]);
            }
        }
    }

    // ────────────────────────────────────────────────────────
    // PASO 4.5 — Tarea de propuesta previa al vencimiento (Inversiones / CDP)
    if ($ec_inv && $crear_tarea_prev_venc && $fecha_previa_vencimiento) {
        $st = $pdo->prepare("SELECT id FROM tarea WHERE cliente_prospecto_id = ? AND tipo_tarea = 'nueva_cita_inversion' AND estado = 'programada' LIMIT 1");
        $st->execute([$cliente_id]);
        $tarea_inv_existente = $st->fetchColumn();

        $obs_inv = "💰 Nueva cita de inversión" . ($propuesta_inversion ? ": " . $propuesta_inversion : "") . ($ec_fecha_inv ? " (CDP vence: " . $ec_fecha_inv . ")" : "");

        if ($tarea_inv_existente) {
            $pdo->prepare("UPDATE tarea SET fecha_programada = ?, hora_programada = ?, observaciones = ? WHERE id = ?")
                ->execute([$fecha_previa_vencimiento, $hora_previa_vencimiento ?: null, $obs_inv, $tarea_inv_existente]);
        } else {
            $pdo->prepare("
                INSERT INTO tarea
                    (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado,
                     fecha_programada, hora_programada, observaciones)
                VALUES (?,?,?,?,'programada',?,?,?)
            ")->execute([
                uuid4(), $asesor_id, $cliente_id, 'nueva_cita_inversion',
                $fecha_previa_vencimiento, $hora_previa_vencimiento ?: null, $obs_inv
            ]);
        }
    }

    // PASO 5 — Fichas de producto
    // ────────────────────────────────────────────────────────
    $productos = array_filter(array_map('trim', explode(',', $prod_interes)));
    $fichas_ok = [];

    $fp_cols = $get_cols('ficha_producto');

    // En edición: removemos fichas anteriores de los tipos que se están guardando
    // para evitar duplicación y registros huérfanos en tablas hijas.
    // Realizado de forma segura y precisa por tipo de producto dentro del loop.

    foreach ($productos as $prod) {
        try {
            $fid = uuid4();
            $uid = $_SESSION['usuario_id'] ?? $asesor_id;

            // Mapear a tipos válidos usados por el backend móvil
            $tipo_fp = match ($prod) {
                'ahorro'    => 'cuenta_ahorros',
                'corriente' => 'cuenta_corriente',
                'inversion' => 'inversiones',
                'credito'   => 'credito',
                default     => $prod,
            };

            // Evitar duplicados de ficha_producto y sus tablas hijas en modo edición
            if ($modo_edicion) {
                $fids_to_del = [];
                if (isset($fp_cols['encuesta_id'])) {
                    $st_fp = $pdo->prepare("SELECT id FROM ficha_producto WHERE encuesta_id = ? AND producto_tipo = ?");
                    $st_fp->execute([$enc_id, $tipo_fp]);
                    $fids_to_del = $st_fp->fetchAll(PDO::FETCH_COLUMN) ?: [];
                } else {
                    $st_fp = $pdo->prepare("SELECT id FROM ficha_producto WHERE cliente_cedula = ? AND producto_tipo = ? AND asesor_id = ?");
                    $st_fp->execute([$cedula, $tipo_fp, $asesor_id]);
                    $fids_to_del = $st_fp->fetchAll(PDO::FETCH_COLUMN) ?: [];
                }
                
                foreach ($fids_to_del as $f_del_id) {
                    $child_tables = ['ficha_cuenta_ahorros', 'ficha_cuenta_corriente', 'ficha_inversiones', 'ficha_credito'];
                    foreach ($child_tables as $ct) {
                        try {
                            $pdo->prepare("DELETE FROM `$ct` WHERE ficha_id = ?")->execute([$f_del_id]);
                        } catch (PDOException $_) {}
                    }
                    $pdo->prepare("DELETE FROM ficha_producto WHERE id = ?")->execute([$f_del_id]);
                }
            }

            // Insert dinámico para soportar esquemas con/ sin encuesta_id, gps, etc.
            $fp_insert_cols = ['id', 'usuario_id', 'asesor_id', 'producto_tipo', 'cliente_cedula', 'cliente_nombre'];
            $fp_insert_vals = [$fid, $uid, $asesor_id, $tipo_fp, $cedula, $nombre_full];
            if (isset($fp_cols['encuesta_id'])) {
                $fp_insert_cols[] = 'encuesta_id';
                $fp_insert_vals[] = $enc_id;
            }
            if (isset($fp_cols['latitud'])) {
                $fp_insert_cols[] = 'latitud';
                $fp_insert_vals[] = ($lat && is_numeric($lat) ? (float)$lat : null);
            }
            if (isset($fp_cols['longitud'])) {
                $fp_insert_cols[] = 'longitud';
                $fp_insert_vals[] = ($lng && is_numeric($lng) ? (float)$lng : null);
            }
            if (isset($fp_cols['hora_gps'])) {
                $fp_insert_cols[] = 'hora_gps';
                $fp_insert_vals[] = $hora;
            }
            if (isset($fp_cols['estado_revision'])) {
                $fp_insert_cols[] = 'estado_revision';
                $fp_insert_vals[] = 'pendiente';
            }
            $fp_ph = implode(',', array_fill(0, count($fp_insert_cols), '?'));
            $fp_colList = implode(',', array_map(fn($c) => "`$c`", $fp_insert_cols));
            $pdo->prepare("INSERT INTO ficha_producto ($fp_colList) VALUES ($fp_ph)")
                ->execute($fp_insert_vals);

            switch ($prod) {
                case 'ahorro':
                    $pdo->prepare("INSERT INTO ficha_cuenta_ahorros (id,ficha_id,tipo_ahorro,titular_nombre,titular_cedula,titular_celular,monto_inicial,frecuencia_deposito) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([uuid4(), $fid, pn('fa_tipo_ahorro'), pn('fa_nombre') ?? $nombre_full, pn('fa_cedula') ?? $cedula, pn('fa_celular') ?? $celular, pn('fa_monto_inicial'), pn('fa_frecuencia')]);
                    $fichas_ok[] = 'Ahorro';
                    break;
                case 'corriente':
                    $pdo->prepare("INSERT INTO ficha_cuenta_corriente (id,ficha_id,tipo_cc,titular_nombre,titular_cedula,titular_celular) VALUES (?,?,?,?,?,?)")
                        ->execute([uuid4(), $fid, pn('fc_tipo_cc'), pn('fc_nombre') ?? $nombre_full, pn('fc_cedula') ?? $cedula, pn('fc_celular') ?? $celular]);
                    $fichas_ok[] = 'Corriente';
                    break;
                case 'inversion':
                    $pdo->prepare("INSERT INTO ficha_inversiones (id,ficha_id,tipo_inversion,monto_inversion,plazo_meses,objetivo_inversion,tiene_inv_otra,renovacion_auto) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([uuid4(), $fid, pn('fi_tipo'), pn('fi_monto'), pn('fi_plazo'), pn('fi_objetivo'), pb('fi_otra_institucion'), pb('fi_renovacion_automatica')]);
                    $fichas_ok[] = 'Inversión';
                    break;
                case 'credito':
                    $req_credito = pn('fk_requiere_credito') !== null ? (int)pn('fk_requiere_credito') : 1;
                    
                    $doc_cedula = pn('fk_doc_cedula') !== null ? (int)pn('fk_doc_cedula') : 0;
                    $doc_planilla = pn('fk_doc_planilla') !== null ? (int)pn('fk_doc_planilla') : 0;
                    $doc_ruc_rise = pn('fk_doc_ruc_rise') !== null ? (int)pn('fk_doc_ruc_rise') : 0;
                    $doc_estados_cuenta = pn('fk_doc_estados_cuenta') !== null ? (int)pn('fk_doc_estados_cuenta') : 0;
                    $doc_declaraciones = pn('fk_doc_declaraciones') !== null ? (int)pn('fk_doc_declaraciones') : 0;
                    $doc_matricula = pn('fk_doc_matricula') !== null ? (int)pn('fk_doc_matricula') : 0;
                    $doc_foto_negocio = pn('fk_doc_foto_negocio') !== null ? (int)pn('fk_doc_foto_negocio') : 0;
                    $doc_solicitud_credito = pn('fk_doc_solicitud') !== null ? (int)pn('fk_doc_solicitud') : 0;
                    $doc_foto_cliente = pn('fk_doc_foto_cliente') !== null ? (int)pn('fk_doc_foto_cliente') : 0;

                    $pdo->prepare("INSERT INTO ficha_credito (
                        id, ficha_id, requiere_credito,
                        destino_credito, dest_otros_detalle, monto_credito, plazo_credito_meses,
                        solicitante_nombre, solicitante_cedula, solicitante_celular, solicitante_estado_civil,
                        solicitante_conyuge_nombre, solicitante_conyuge_cedula, solicitante_conyuge_celular,
                        garante_nombre, garante_cedula, garante_celular, garante_estado_civil,
                        garante_conyuge_nombre, garante_conyuge_cedula, garante_conyuge_celular,
                        direccion_sitio,
                        doc_cedula, doc_planilla, doc_ruc_rise, doc_estados_cuenta, doc_declaraciones, doc_matricula,
                        doc_foto_negocio, doc_solicitud_credito, doc_foto_cliente
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?
                    )")->execute([
                        uuid4(), $fid, $req_credito,
                        pn('fk_destino'), pn('fk_destino_otros'), pn('fk_monto'), pn('fk_plazo'),
                        pn('fk_sol_nombre') ?: $nombre_full, pn('fk_sol_cedula') ?: $cedula, pn('fk_sol_celular') ?: $celular, pn('fk_sol_ec'),
                        pn('fk_sol_conyuge_nombre'), pn('fk_sol_conyuge_cedula'), pn('fk_sol_conyuge_celular'),
                        pn('fk_gar_nombre'), pn('fk_gar_cedula'), pn('fk_gar_celular'), pn('fk_gar_ec'),
                        pn('fk_gar_conyuge_nombre'), pn('fk_gar_conyuge_cedula'), pn('fk_gar_conyuge_celular'),
                        pn('fk_direccion_sitio'),
                        $doc_cedula, $doc_planilla, $doc_ruc_rise, $doc_estados_cuenta, $doc_declaraciones, $doc_matricula,
                        $doc_foto_negocio, $doc_solicitud_credito, $doc_foto_cliente
                    ]);
                    $fichas_ok[] = 'Crédito';
                    break;
            }
        } catch (PDOException $ef) {
            error_log("[guardar_encuesta] ficha $prod: " . $ef->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────
    // COMMIT
    // ────────────────────────────────────────────────────────
    $pdo->commit();

    $msg = $modo_edicion
        ? 'Encuesta actualizada correctamente.'
        : 'Encuesta guardada. La tarea aparece en tus tareas de hoy.';
    if (!empty($fichas_ok)) {
        $msg .= ' Fichas: ' . implode(', ', $fichas_ok) . '.';
    }
    redirect('ok', $msg, null);

} catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $_) {}
    $msg = $e->getMessage();
    error_log("[guardar_encuesta] ERROR: $msg | asesor=$asesor_id | tarea_id_edicion=" . ($tarea_id_edicion ?? 'null'));
    redirect('error', 'Error guardando encuesta: ' . $msg, $modo_edicion ? $tarea_id_edicion : null);
}

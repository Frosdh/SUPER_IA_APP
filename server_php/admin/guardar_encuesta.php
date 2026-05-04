<?php
// ============================================================
// guardar_encuesta.php  —  v2026-05-04
// Recibe el POST de nueva_encuesta.php y guarda:
//   1. cliente_prospecto (upsert por cédula)
//   2. encuesta_comercial (insert)
//   3. ficha_producto + tabla de detalle por cada producto
//      seleccionado (ahorro / corriente / inversión / crédito)
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

// Auth
if (empty($_SESSION['asesor_logged_in'])) {
    header('Location: login.php?e=session');
    exit;
}

require_once __DIR__ . '/db_admin.php'; // $pdo

// ── Helpers ──────────────────────────────────────────────────
function p(string $k): string  { return trim((string)($_POST[$k] ?? '')); }
function pn(string $k): ?string { $v = p($k); return $v !== '' ? $v : null; }
function pb(string $k): int    { return p($k) === '1' ? 1 : 0; }
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
    $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ── Redirect helper ───────────────────────────────────────────
function redirect(string $status, string $msg): void {
    $q = http_build_query(['enc_status' => $status, 'enc_msg' => $msg]);
    header("Location: nueva_encuesta.php?$q");
    exit;
}

// ── Recoger datos del formulario ──────────────────────────────
$asesor_id   = (string)($_SESSION['asesor_table_id'] ?? $_SESSION['asesor_id'] ?? '');
$usuario_id  = (string)($_SESSION['usuario_id'] ?? $asesor_id);
$cliente_id  = pn('cliente_id');

$nombre       = pn('nombre');
$cedula       = pn('cedula');
$celular      = pn('celular');
$telefono     = pn('telefono');
$email        = pn('email');
$direccion    = pn('direccion');
$ciudad       = pn('ciudad');
$zona         = pn('zona');
$regimen      = pn('regimen_type');
$tiene_ruc    = pb('tiene_ruc');
$tiene_rise   = pb('tiene_rise');
$ruc_numero   = pn('ruc_numero');
$rise_numero  = pn('rise_numero');
$nombre_emp   = pn('nombre_empresa');
$actividad_e  = pn('actividad_empresa');
$actividad    = pn('actividad');

// RUC / RISE preguntas
$ruc_declara_iva     = pn('ruc_declara_iva');
$ruc_emite_facturas  = pn('ruc_emite_facturas');
$ruc_lleva_contab    = pn('ruc_lleva_contab');
$rise_paga_cuota     = pn('rise_paga_cuota');
$rise_emite_notas    = pn('rise_emite_notas');
$rise_conoce_limite  = pn('rise_conoce_limite');

// Situación económica
$ec_mantiene_ahorro   = pb('ec_mantiene_cuenta_ahorro');
$ec_inst_ahorro       = pn('ec_institucion_ahorro');
$ec_saldo_ahorro      = pn('ec_saldo_ahorro');
$ec_mantiene_cc       = pb('ec_mantiene_cuenta_corriente');
$ec_inst_cc           = pn('ec_institucion_corriente');
$ec_inv               = pb('ec_tiene_inversiones');
$ec_inst_inv          = pn('ec_institucion_inversiones');
$ec_valor_inv         = pn('ec_valor_inversion');
$ec_plazo_inv         = pn('ec_plazo_inversion');
$ec_fecha_inv         = pn('ec_fecha_vencimiento_inversion');
$ec_credito           = pb('ec_tiene_operaciones_crediticias');
$ec_inst_cred         = pn('ec_institucion_credito');
$ec_monto_cred        = pn('ec_monto_credito_actual');

// Producto y acuerdo
$prod_interes  = pn('prod_interes');   // comma-separated: ahorro,corriente,inversion,credito
$nivel_interes = pn('nivel_interes');
$fecha_acuerdo = pn('fecha_acuerdo');
$hora_acuerdo  = pn('hora_acuerdo');
$fecha_nc      = pn('fecha_nuevo_contacto');

$lat  = pn('lat');
$lng  = pn('lng');

if (!$cedula) redirect('error', 'La cédula es obligatoria.');

// ── 1. Upsert cliente_prospecto ───────────────────────────────
try {
    $st = $pdo->prepare('SELECT id FROM cliente_prospecto WHERE cedula = ? LIMIT 1');
    $st->execute([$cedula]);
    $existing = $st->fetchColumn();

    if ($existing) {
        $pdo->prepare("
            UPDATE cliente_prospecto SET
                nombre=COALESCE(NULLIF(?,'' ), nombre),
                telefono=COALESCE(NULLIF(?,'' ), telefono),
                telefono2=COALESCE(NULLIF(?,'' ), telefono2),
                email=COALESCE(NULLIF(?,'' ), email),
                direccion=COALESCE(NULLIF(?,'' ), direccion),
                ciudad=COALESCE(NULLIF(?,'' ), ciudad),
                zona=COALESCE(NULLIF(?,'' ), zona),
                actividad=COALESCE(NULLIF(?,'' ), actividad),
                nombre_empresa=COALESCE(NULLIF(?,'' ), nombre_empresa),
                tiene_ruc=?, tiene_rise=?
            WHERE cedula=?
        ")->execute([
            $nombre, $telefono, $celular, $email, $direccion,
            $ciudad, $zona, $actividad, $nombre_emp,
            $tiene_ruc, $tiene_rise,
            $cedula
        ]);
        $cliente_id = $existing;
    } else {
        $cliente_id = $cliente_id ?: uuid4();
        $pdo->prepare("
            INSERT INTO cliente_prospecto
                (id, cedula, nombre, telefono, telefono2, email, direccion,
                 ciudad, zona, actividad, nombre_empresa, tiene_ruc, tiene_rise, asesor_id, estado)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'prospecto')
        ")->execute([
            $cliente_id, $cedula, $nombre, $telefono, $celular, $email,
            $direccion, $ciudad, $zona, $actividad, $nombre_emp,
            $tiene_ruc, $tiene_rise, $asesor_id
        ]);
    }
} catch (PDOException $e) {
    redirect('error', 'Error guardando prospecto: ' . $e->getMessage());
}

// ── 2. Crear o asegurar tabla encuesta_comercial ──────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS encuesta_comercial (
            id                    CHAR(36) NOT NULL PRIMARY KEY,
            cliente_id            CHAR(36) DEFAULT NULL,
            cliente_cedula        VARCHAR(20) DEFAULT NULL,
            asesor_id             CHAR(36) DEFAULT NULL,
            usuario_id            CHAR(36) DEFAULT NULL,
            regimen_tributario    VARCHAR(20) DEFAULT NULL,
            ruc_declara_iva       TINYINT(1) DEFAULT NULL,
            ruc_emite_facturas    TINYINT(1) DEFAULT NULL,
            ruc_lleva_contab      TINYINT(1) DEFAULT NULL,
            rise_paga_cuota       TINYINT(1) DEFAULT NULL,
            rise_emite_notas      TINYINT(1) DEFAULT NULL,
            rise_conoce_limite    TINYINT(1) DEFAULT NULL,
            mantiene_cuenta_ahorro     TINYINT(1) DEFAULT 0,
            institucion_ahorro         VARCHAR(200) DEFAULT NULL,
            saldo_ahorro               VARCHAR(30) DEFAULT NULL,
            mantiene_cuenta_corriente  TINYINT(1) DEFAULT 0,
            institucion_corriente      VARCHAR(200) DEFAULT NULL,
            tiene_inversiones          TINYINT(1) DEFAULT 0,
            institucion_inversiones    VARCHAR(200) DEFAULT NULL,
            valor_inversion            VARCHAR(30) DEFAULT NULL,
            plazo_inversion            VARCHAR(10) DEFAULT NULL,
            fecha_vencimiento_inversion VARCHAR(20) DEFAULT NULL,
            tiene_operaciones_crediticias TINYINT(1) DEFAULT 0,
            institucion_credito        VARCHAR(200) DEFAULT NULL,
            monto_credito_actual       VARCHAR(30) DEFAULT NULL,
            prod_interes               VARCHAR(200) DEFAULT NULL,
            nivel_interes              VARCHAR(30) DEFAULT NULL,
            fecha_acuerdo              DATE DEFAULT NULL,
            hora_acuerdo               VARCHAR(10) DEFAULT NULL,
            fecha_nuevo_contacto       DATE DEFAULT NULL,
            latitud                    DECIMAL(10,7) DEFAULT NULL,
            longitud                   DECIMAL(10,7) DEFAULT NULL,
            created_at                 DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Table may already exist with different structure — ignore
}

// ── 3. Insert encuesta_comercial ─────────────────────────────
$enc_id = uuid4();
try {
    $pdo->prepare("
        INSERT INTO encuesta_comercial (
            id, cliente_id, cliente_cedula, asesor_id, usuario_id,
            regimen_tributario,
            ruc_declara_iva, ruc_emite_facturas, ruc_lleva_contab,
            rise_paga_cuota, rise_emite_notas, rise_conoce_limite,
            mantiene_cuenta_ahorro, institucion_ahorro, saldo_ahorro,
            mantiene_cuenta_corriente, institucion_corriente,
            tiene_inversiones, institucion_inversiones, valor_inversion,
            plazo_inversion, fecha_vencimiento_inversion,
            tiene_operaciones_crediticias, institucion_credito, monto_credito_actual,
            prod_interes, nivel_interes,
            fecha_acuerdo, hora_acuerdo, fecha_nuevo_contacto,
            latitud, longitud
        ) VALUES (
            ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
        )
    ")->execute([
        $enc_id, $cliente_id, $cedula, $asesor_id, $usuario_id,
        $regimen,
        $ruc_declara_iva !== null ? (int)$ruc_declara_iva : null,
        $ruc_emite_facturas !== null ? (int)$ruc_emite_facturas : null,
        $ruc_lleva_contab !== null ? (int)$ruc_lleva_contab : null,
        $rise_paga_cuota !== null ? (int)$rise_paga_cuota : null,
        $rise_emite_notas !== null ? (int)$rise_emite_notas : null,
        $rise_conoce_limite !== null ? (int)$rise_conoce_limite : null,
        $ec_mantiene_ahorro, $ec_inst_ahorro, $ec_saldo_ahorro,
        $ec_mantiene_cc, $ec_inst_cc,
        $ec_inv, $ec_inst_inv, $ec_valor_inv,
        $ec_plazo_inv, $ec_fecha_inv,
        $ec_credito, $ec_inst_cred, $ec_monto_cred,
        $prod_interes, $nivel_interes,
        ($fecha_acuerdo ?: null), ($hora_acuerdo ?: null), ($fecha_nc ?: null),
        ($lat && is_numeric($lat) ? (float)$lat : null),
        ($lng && is_numeric($lng) ? (float)$lng : null),
    ]);
} catch (PDOException $e) {
    redirect('error', 'Error guardando encuesta: ' . $e->getMessage());
}

// ── 4. Fichas de producto ─────────────────────────────────────
// Asegurar tablas de fichas (CREATE IF NOT EXISTS, igual que guardar_ficha_producto.php)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_producto (
            id              CHAR(36) NOT NULL PRIMARY KEY,
            usuario_id      CHAR(36) NOT NULL,
            asesor_id       CHAR(36) DEFAULT NULL,
            producto_tipo   VARCHAR(30) NOT NULL,
            cliente_cedula  VARCHAR(20) DEFAULT NULL,
            cliente_nombre  VARCHAR(200) DEFAULT NULL,
            encuesta_id     CHAR(36) DEFAULT NULL,
            latitud         DECIMAL(10,7) DEFAULT NULL,
            longitud        DECIMAL(10,7) DEFAULT NULL,
            estado_revision ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Add encuesta_id column if not present (migration)
    $chk = $pdo->query("SHOW COLUMNS FROM ficha_producto LIKE 'encuesta_id'");
    if ($chk->rowCount() === 0) {
        $pdo->exec("ALTER TABLE ficha_producto ADD COLUMN encuesta_id CHAR(36) DEFAULT NULL AFTER cliente_nombre");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_cuenta_ahorros (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_ahorro          VARCHAR(20) DEFAULT NULL,
            titular_nombre       VARCHAR(200) DEFAULT NULL,
            titular_cedula       VARCHAR(20) DEFAULT NULL,
            titular_celular      VARCHAR(20) DEFAULT NULL,
            titular_estado_civil VARCHAR(20) DEFAULT NULL,
            monto_inicial        VARCHAR(30) DEFAULT NULL,
            frecuencia_deposito  VARCHAR(20) DEFAULT NULL,
            objetivo_ahorro      TEXT DEFAULT NULL,
            tiene_ahorro_otra    TINYINT(1) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_cuenta_corriente (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_cc              VARCHAR(20) DEFAULT NULL,
            titular_nombre       VARCHAR(200) DEFAULT NULL,
            titular_cedula       VARCHAR(20) DEFAULT NULL,
            titular_celular      VARCHAR(20) DEFAULT NULL,
            titular_estado_civil VARCHAR(20) DEFAULT NULL,
            monto_inicial        VARCHAR(30) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_inversiones (
            id                       CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id                 CHAR(36) NOT NULL,
            tipo_inversion           VARCHAR(20) DEFAULT NULL,
            monto_inversion          VARCHAR(30) DEFAULT NULL,
            plazo_meses              VARCHAR(10) DEFAULT NULL,
            objetivo_inversion       VARCHAR(30) DEFAULT NULL,
            tiene_inv_otra           TINYINT(1) DEFAULT NULL,
            institucion_competencia  VARCHAR(200) DEFAULT NULL,
            renovacion_auto          TINYINT(1) DEFAULT NULL,
            observaciones            TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_credito (
            id                    CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id              CHAR(36) NOT NULL,
            requiere_credito      TINYINT(1) DEFAULT NULL,
            destino_credito       VARCHAR(50) DEFAULT NULL,
            dest_otros_detalle    VARCHAR(255) DEFAULT NULL,
            monto_credito         VARCHAR(30) DEFAULT NULL,
            plazo_credito_meses   VARCHAR(10) DEFAULT NULL,
            solicitante_nombre    VARCHAR(200) DEFAULT NULL,
            solicitante_cedula    VARCHAR(20) DEFAULT NULL,
            solicitante_celular   VARCHAR(20) DEFAULT NULL,
            solicitante_estado_civil VARCHAR(20) DEFAULT NULL,
            observaciones         TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {
    // Ignore table creation errors (may already exist)
}

// Helper: insert ficha_producto parent row
function insertFichaProducto(PDO $pdo, string $tipo, string $asesor_id, string $usuario_id,
                              ?string $cedula, ?string $nombre, string $enc_id,
                              ?string $lat, ?string $lng): string {
    $fid = uuid4();
    $pdo->prepare("
        INSERT INTO ficha_producto
            (id, usuario_id, asesor_id, producto_tipo, cliente_cedula, cliente_nombre,
             encuesta_id, latitud, longitud)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([
        $fid, $usuario_id, $asesor_id, $tipo, $cedula, $nombre, $enc_id,
        ($lat && is_numeric($lat) ? (float)$lat : null),
        ($lng && is_numeric($lng) ? (float)$lng : null),
    ]);
    return $fid;
}

$productos = array_filter(array_map('trim', explode(',', $prod_interes ?? '')));
$fichas_guardadas = [];

foreach ($productos as $prod) {
    try {
        switch ($prod) {
            case 'ahorro':
                $fid = insertFichaProducto($pdo, 'cuenta_ahorros', $asesor_id, $usuario_id,
                                           $cedula, $nombre, $enc_id, $lat, $lng);
                $pdo->prepare("
                    INSERT INTO ficha_cuenta_ahorros
                        (id, ficha_id, tipo_ahorro, titular_nombre, titular_cedula,
                         titular_celular, titular_estado_civil, monto_inicial, frecuencia_deposito)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ")->execute([
                    uuid4(), $fid,
                    pn('fa_tipo_ahorro'), pn('fa_nombre'), pn('fa_cedula'),
                    pn('fa_celular'), pn('fa_estado_civil'), pn('fa_monto_inicial'),
                    pn('fa_frecuencia_deposito'),
                ]);
                $fichas_guardadas[] = 'ahorro';
                break;

            case 'corriente':
                $fid = insertFichaProducto($pdo, 'cuenta_corriente', $asesor_id, $usuario_id,
                                           $cedula, $nombre, $enc_id, $lat, $lng);
                $pdo->prepare("
                    INSERT INTO ficha_cuenta_corriente
                        (id, ficha_id, tipo_cc, titular_nombre, titular_cedula,
                         titular_celular, titular_estado_civil, monto_inicial)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([
                    uuid4(), $fid,
                    pn('fc_tipo'), pn('fc_nombre'), pn('fc_cedula'),
                    pn('fc_celular'), pn('fc_estado_civil'), pn('fc_monto_inicial'),
                ]);
                $fichas_guardadas[] = 'corriente';
                break;

            case 'inversion':
                $fid = insertFichaProducto($pdo, 'inversiones', $asesor_id, $usuario_id,
                                           $cedula, $nombre, $enc_id, $lat, $lng);
                $pdo->prepare("
                    INSERT INTO ficha_inversiones
                        (id, ficha_id, tipo_inversion, monto_inversion, plazo_meses,
                         objetivo_inversion, tiene_inv_otra, renovacion_auto)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([
                    uuid4(), $fid,
                    pn('fi_tipo'), pn('fi_monto'), pn('fi_plazo'),
                    pn('fi_objetivo'),
                    pb('fi_otra_institucion'),
                    pb('fi_renovacion_automatica'),
                ]);
                $fichas_guardadas[] = 'inversión';
                break;

            case 'credito':
                $fid = insertFichaProducto($pdo, 'credito', $asesor_id, $usuario_id,
                                           $cedula, $nombre, $enc_id, $lat, $lng);
                $pdo->prepare("
                    INSERT INTO ficha_credito
                        (id, ficha_id, destino_credito,
                         monto_credito, plazo_credito_meses,
                         solicitante_nombre, solicitante_cedula, solicitante_celular)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([
                    uuid4(), $fid,
                    pn('fk_destino'),
                    pn('fk_monto'), pn('fk_plazo'),
                    $nombre, $cedula, $celular,
                ]);
                $fichas_guardadas[] = 'crédito';
                break;
        }
    } catch (PDOException $e) {
        // Non-fatal: log and continue with next product
        error_log("[guardar_encuesta] ficha $prod error: " . $e->getMessage());
    }
}

// ── 5. Redirect with success ──────────────────────────────────
$msg = 'Encuesta guardada correctamente.';
if (!empty($fichas_guardadas)) {
    $msg .= ' Fichas: ' . implode(', ', $fichas_guardadas) . '.';
}
redirect('ok', $msg);

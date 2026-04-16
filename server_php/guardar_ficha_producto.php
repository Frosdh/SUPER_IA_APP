<?php
/**
 * guardar_ficha_producto.php
 * Guarda la ficha de un producto financiero específico (crédito, cuenta corriente,
 * cuenta de ahorros, inversiones) vinculada al cliente prospecto y al asesor.
 *
 * POST params comunes:
 *   usuario_id, asesor_id, producto_tipo, cliente_cedula, cliente_nombre,
 *   latitud, longitud, hora_gps
 *
 * POST params por tipo (ver switch abajo)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('ngrok-skip-browser-warning: true');

require_once __DIR__ . '/db_config.php';

// ── Helpers ───────────────────────────────────────────────────
function s(string $key): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}
function b(string $key): int {
    return s($key) === '1' ? 1 : 0;
}
function respond(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

// ── Parámetros comunes ────────────────────────────────────────
$usuario_id    = s('usuario_id');
$asesor_id     = s('asesor_id');
$producto_tipo = s('producto_tipo');
$cedula        = s('cliente_cedula');
$nombre        = s('cliente_nombre');
$lat           = s('latitud')  ?: null;
$lng           = s('longitud') ?: null;
$hora_gps      = s('hora_gps') ?: null;

$tipos_validos = ['credito','cuenta_corriente','cuenta_ahorros','inversiones'];
if (!in_array($producto_tipo, $tipos_validos, true)) {
    respond('error', 'Tipo de producto inválido: ' . $producto_tipo);
}
if (empty($usuario_id)) {
    respond('error', 'usuario_id requerido');
}

// ── Asegurar tablas ───────────────────────────────────────────
try {
    // Tabla principal de fichas
    $conn->query("
        CREATE TABLE IF NOT EXISTS ficha_producto (
            id              CHAR(36)     NOT NULL PRIMARY KEY,
            usuario_id      CHAR(36)     NOT NULL,
            asesor_id       CHAR(36)     DEFAULT NULL,
            producto_tipo   VARCHAR(30)  NOT NULL,
            cliente_cedula  VARCHAR(20)  DEFAULT NULL,
            cliente_nombre  VARCHAR(200) DEFAULT NULL,
            latitud         DECIMAL(10,7) DEFAULT NULL,
            longitud        DECIMAL(10,7) DEFAULT NULL,
            hora_gps        VARCHAR(10)  DEFAULT NULL,
            created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Estado de revisión (para aprobación/rechazo en panel)
    $cols_fp = [
        'estado_revision'        => "ALTER TABLE ficha_producto ADD COLUMN estado_revision ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente' AFTER producto_tipo",
        'revision_usuario_id'    => "ALTER TABLE ficha_producto ADD COLUMN revision_usuario_id CHAR(36) DEFAULT NULL AFTER estado_revision",
        'revision_at'            => "ALTER TABLE ficha_producto ADD COLUMN revision_at DATETIME DEFAULT NULL AFTER revision_usuario_id",
        'revision_observaciones' => "ALTER TABLE ficha_producto ADD COLUMN revision_observaciones TEXT DEFAULT NULL AFTER revision_at",
    ];
    foreach ($cols_fp as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM ficha_producto LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query($ddl);
        }
    }

    // Tablas por tipo (creadas solo si no existen)
    $conn->query("
        CREATE TABLE IF NOT EXISTS ficha_credito (
            id                        CHAR(36)     NOT NULL PRIMARY KEY,
            ficha_id                  CHAR(36)     NOT NULL,
            requiere_credito          TINYINT(1)   DEFAULT NULL,
            -- Destino único (una sola opción)
            destino_credito           VARCHAR(50)  DEFAULT NULL,
            dest_otros_detalle        VARCHAR(255) DEFAULT NULL,
            monto_credito             VARCHAR(30)  DEFAULT NULL,
            plazo_credito_meses       VARCHAR(10)  DEFAULT NULL,
            -- Solicitante
            solicitante_nombre        VARCHAR(200) DEFAULT NULL,
            solicitante_cedula        VARCHAR(20)  DEFAULT NULL,
            solicitante_celular       VARCHAR(20)  DEFAULT NULL,
            solicitante_estado_civil  VARCHAR(20)  DEFAULT NULL,
            solicitante_conyuge_nombre  VARCHAR(200) DEFAULT NULL,
            solicitante_conyuge_cedula  VARCHAR(20)  DEFAULT NULL,
            solicitante_conyuge_celular VARCHAR(20)  DEFAULT NULL,
            -- Garante
            garante_nombre            VARCHAR(200) DEFAULT NULL,
            garante_cedula            VARCHAR(20)  DEFAULT NULL,
            garante_celular           VARCHAR(20)  DEFAULT NULL,
            garante_estado_civil      VARCHAR(20)  DEFAULT NULL,
            garante_conyuge_nombre    VARCHAR(200) DEFAULT NULL,
            garante_conyuge_cedula    VARCHAR(20)  DEFAULT NULL,
            garante_conyuge_celular   VARCHAR(20)  DEFAULT NULL,
            -- Dirección en sitio
            direccion_sitio           TEXT         DEFAULT NULL,
            -- Empresa/negocio
            tiene_empresa             TINYINT(1)   DEFAULT NULL,
            -- Levantamiento de campo
            venta_lv                  VARCHAR(20)  DEFAULT NULL,
            venta_sabado              VARCHAR(20)  DEFAULT NULL,
            venta_domingo             VARCHAR(20)  DEFAULT NULL,
            mes_alta_venta            VARCHAR(5)   DEFAULT NULL,
            mes_baja_venta            VARCHAR(5)   DEFAULT NULL,
            compra_lv                 VARCHAR(20)  DEFAULT NULL,
            compra_sabado             VARCHAR(20)  DEFAULT NULL,
            compra_domingo            VARCHAR(20)  DEFAULT NULL,
            mes_alta_compra           VARCHAR(5)   DEFAULT NULL,
            dias_atencion_lv          TINYINT(1)   DEFAULT 0,
            dias_atencion_sab         TINYINT(1)   DEFAULT 0,
            dias_atencion_dom         TINYINT(1)   DEFAULT 0,
            -- Documentos recibidos
            doc_cedula                TINYINT(1)   DEFAULT 0,
            doc_planilla              TINYINT(1)   DEFAULT 0,
            doc_ruc_rise              TINYINT(1)   DEFAULT 0,
            doc_estados_cuenta        TINYINT(1)   DEFAULT 0,
            doc_declaraciones         TINYINT(1)   DEFAULT 0,
            doc_matricula             TINYINT(1)   DEFAULT 0,
            doc_foto_negocio          TINYINT(1)   DEFAULT 0,
            doc_solicitud_credito     TINYINT(1)   DEFAULT 0,
            doc_foto_cliente          TINYINT(1)   DEFAULT 0,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Agregar columnas nuevas si la tabla ya existía (migración no destructiva)
    $cols_nuevas = [
        'destino_credito'           => "ADD COLUMN destino_credito VARCHAR(50) DEFAULT NULL AFTER requiere_credito",
        'solicitante_celular'       => "ADD COLUMN solicitante_celular VARCHAR(20) DEFAULT NULL AFTER solicitante_cedula",
        'solicitante_estado_civil'  => "ADD COLUMN solicitante_estado_civil VARCHAR(20) DEFAULT NULL AFTER solicitante_celular",
        'solicitante_conyuge_nombre'=> "ADD COLUMN solicitante_conyuge_nombre VARCHAR(200) DEFAULT NULL AFTER solicitante_estado_civil",
        'solicitante_conyuge_cedula'=> "ADD COLUMN solicitante_conyuge_cedula VARCHAR(20) DEFAULT NULL AFTER solicitante_conyuge_nombre",
        'solicitante_conyuge_celular'=>"ADD COLUMN solicitante_conyuge_celular VARCHAR(20) DEFAULT NULL AFTER solicitante_conyuge_cedula",
        'garante_celular'           => "ADD COLUMN garante_celular VARCHAR(20) DEFAULT NULL AFTER garante_cedula",
        'garante_estado_civil'      => "ADD COLUMN garante_estado_civil VARCHAR(20) DEFAULT NULL AFTER garante_celular",
        'garante_conyuge_nombre'    => "ADD COLUMN garante_conyuge_nombre VARCHAR(200) DEFAULT NULL AFTER garante_estado_civil",
        'garante_conyuge_cedula'    => "ADD COLUMN garante_conyuge_cedula VARCHAR(20) DEFAULT NULL AFTER garante_conyuge_nombre",
        'garante_conyuge_celular'   => "ADD COLUMN garante_conyuge_celular VARCHAR(20) DEFAULT NULL AFTER garante_conyuge_cedula",
        'direccion_sitio'           => "ADD COLUMN direccion_sitio TEXT DEFAULT NULL AFTER garante_conyuge_celular",
        'tiene_empresa'             => "ADD COLUMN tiene_empresa TINYINT(1) DEFAULT NULL AFTER direccion_sitio",
        'doc_solicitud_credito'     => "ADD COLUMN doc_solicitud_credito TINYINT(1) DEFAULT 0 AFTER doc_foto_negocio",
        'doc_foto_cliente'          => "ADD COLUMN doc_foto_cliente TINYINT(1) DEFAULT 0 AFTER doc_solicitud_credito",
    ];
    foreach ($cols_nuevas as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM ficha_credito LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE ficha_credito $ddl");
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS ficha_cuenta_corriente (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_cc              VARCHAR(20) DEFAULT NULL,
            titular_nombre       VARCHAR(200) DEFAULT NULL,
            titular_cedula       VARCHAR(20)  DEFAULT NULL,
            titular_celular      VARCHAR(20)  DEFAULT NULL,
            titular_estado_civil VARCHAR(20)  DEFAULT NULL,
            proposito            TEXT DEFAULT NULL,
            monto_deposito_prom  VARCHAR(30) DEFAULT NULL,
            usa_cheques          TINYINT(1) DEFAULT NULL,
            requiere_td          TINYINT(1) DEFAULT NULL,
            ingreso_mensual      VARCHAR(30) DEFAULT NULL,
            tiene_nomina         TINYINT(1) DEFAULT NULL,
            tiene_cc_otra        TINYINT(1) DEFAULT NULL,
            institucion_cc       VARCHAR(200) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Migración no-destructiva ficha_cuenta_corriente
    foreach ([
        'titular_nombre'       => "ADD COLUMN titular_nombre VARCHAR(200) DEFAULT NULL AFTER tipo_cc",
        'titular_cedula'       => "ADD COLUMN titular_cedula VARCHAR(20) DEFAULT NULL AFTER titular_nombre",
        'titular_celular'      => "ADD COLUMN titular_celular VARCHAR(20) DEFAULT NULL AFTER titular_cedula",
        'titular_estado_civil' => "ADD COLUMN titular_estado_civil VARCHAR(20) DEFAULT NULL AFTER titular_celular",
        'tiene_cc_otra'        => "ADD COLUMN tiene_cc_otra TINYINT(1) DEFAULT NULL AFTER tiene_nomina",
        'institucion_cc'       => "ADD COLUMN institucion_cc VARCHAR(200) DEFAULT NULL AFTER tiene_cc_otra",
    ] as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM ficha_cuenta_corriente LIKE '$col'");
        if ($chk && $chk->num_rows === 0) $conn->query("ALTER TABLE ficha_cuenta_corriente $ddl");
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS ficha_cuenta_ahorros (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_ahorro          VARCHAR(20) DEFAULT NULL,
            titular_nombre       VARCHAR(200) DEFAULT NULL,
            titular_cedula       VARCHAR(20)  DEFAULT NULL,
            titular_celular      VARCHAR(20)  DEFAULT NULL,
            titular_estado_civil VARCHAR(20)  DEFAULT NULL,
            monto_inicial        VARCHAR(30) DEFAULT NULL,
            frecuencia_deposito  VARCHAR(20) DEFAULT NULL,
            objetivo_ahorro      TEXT DEFAULT NULL,
            tiene_ahorro_otra    TINYINT(1) DEFAULT NULL,
            institucion_ahorro   VARCHAR(200) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Migración no-destructiva ficha_cuenta_ahorros
    foreach ([
        'titular_nombre'       => "ADD COLUMN titular_nombre VARCHAR(200) DEFAULT NULL AFTER tipo_ahorro",
        'titular_cedula'       => "ADD COLUMN titular_cedula VARCHAR(20) DEFAULT NULL AFTER titular_nombre",
        'titular_celular'      => "ADD COLUMN titular_celular VARCHAR(20) DEFAULT NULL AFTER titular_cedula",
        'titular_estado_civil' => "ADD COLUMN titular_estado_civil VARCHAR(20) DEFAULT NULL AFTER titular_celular",
    ] as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM ficha_cuenta_ahorros LIKE '$col'");
        if ($chk && $chk->num_rows === 0) $conn->query("ALTER TABLE ficha_cuenta_ahorros $ddl");
    }

    $conn->query("
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
            observaciones            TEXT DEFAULT NULL,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Migración no-destructiva ficha_inversiones
    foreach ([
        'institucion_competencia' => "ADD COLUMN institucion_competencia VARCHAR(200) DEFAULT NULL AFTER tiene_inv_otra",
    ] as $col => $ddl) {
        $chk = $conn->query("SHOW COLUMNS FROM ficha_inversiones LIKE '$col'");
        if ($chk && $chk->num_rows === 0) $conn->query("ALTER TABLE ficha_inversiones $ddl");
    }

} catch (\Throwable $e) {
    respond('error', 'Error creando tablas: ' . $e->getMessage());
}

// ── Generar UUIDs ─────────────────────────────────────────────
function uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$ficha_id  = uuid4();
$detalle_id = uuid4();
$lat_val   = is_numeric($lat) ? (float)$lat : null;
$lng_val   = is_numeric($lng) ? (float)$lng : null;

// ── Insertar ficha_producto ───────────────────────────────────
try {
    $st = $conn->prepare("
        INSERT INTO ficha_producto
            (id, usuario_id, asesor_id, producto_tipo, cliente_cedula, cliente_nombre,
             latitud, longitud, hora_gps)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $asesor_val = $asesor_id ?: null;
    $lat_str    = $lat_val !== null ? number_format($lat_val, 7, '.', '') : null;
    $lng_str    = $lng_val !== null ? number_format($lng_val, 7, '.', '') : null;
    $st->bind_param('sssssssss',
        $ficha_id, $usuario_id, $asesor_val, $producto_tipo,
        $cedula, $nombre, $lat_str, $lng_str, $hora_gps
    );
    $st->execute();
    $st->close();
} catch (\Throwable $e) {
    respond('error', '[ficha_producto] ' . $e->getMessage());
}

// ── Insertar detalle según tipo ───────────────────────────────
try {
    switch ($producto_tipo) {

        case 'credito':
            // ── Leer todos los campos ────────────────────────────────
            $req   = s('requiere_credito') ?: null;
            // Destino único
            $dest  = s('destino_credito') ?: null;
            $odet  = s('dest_otros_detalle') ?: null;
            $mto   = s('monto_credito') ?: null;
            $plz   = s('plazo_credito_meses') ?: null;
            // Solicitante
            $snm   = s('solicitante_nombre')          ?: null;
            $scd   = s('solicitante_cedula')           ?: null;
            $scel  = s('solicitante_celular')          ?: null;
            $sec   = s('solicitante_estado_civil')     ?: null;
            $scnm  = s('solicitante_conyuge_nombre')   ?: null;
            $sccd  = s('solicitante_conyuge_cedula')   ?: null;
            $sccel = s('solicitante_conyuge_celular')  ?: null;
            // Garante
            $gnm   = s('garante_nombre')               ?: null;
            $gcd   = s('garante_cedula')               ?: null;
            $gcel  = s('garante_celular')              ?: null;
            $gec   = s('garante_estado_civil')         ?: null;
            $gcnm  = s('garante_conyuge_nombre')       ?: null;
            $gccd  = s('garante_conyuge_cedula')       ?: null;
            $gccel = s('garante_conyuge_celular')      ?: null;
            // Dirección y empresa
            $dsite = s('direccion_sitio')              ?: null;
            $tiemp = s('tiene_empresa')                ?: null;
            // Levantamiento
            $vlv   = s('venta_lv')      ?: null;  $vsb  = s('venta_sabado')   ?: null;  $vdm  = s('venta_domingo')  ?: null;
            $mav   = s('mes_alta_venta') ?: null; $mbv  = s('mes_baja_venta') ?: null;
            $clv   = s('compra_lv')     ?: null;  $csb  = s('compra_sabado')  ?: null;  $cdm  = s('compra_domingo') ?: null;
            $mac   = s('mes_alta_compra') ?: null;
            $dlv   = s('dias_atencion_lv');  $dsb = s('dias_atencion_sab'); $ddm = s('dias_atencion_dom');
            // Documentos
            $dc    = s('doc_cedula');         $dpl  = s('doc_planilla');       $drr  = s('doc_ruc_rise');
            $des   = s('doc_estados_cuenta'); $ddec = s('doc_declaraciones');  $dmat = s('doc_matricula');
            $dfot  = s('doc_foto_negocio');   $dsc  = s('doc_solicitud_credito'); $dfc = s('doc_foto_cliente');

            // 44 parámetros
            $st = $conn->prepare("
                INSERT INTO ficha_credito (
                    id, ficha_id,
                    requiere_credito,
                    destino_credito, dest_otros_detalle,
                    monto_credito, plazo_credito_meses,
                    solicitante_nombre, solicitante_cedula, solicitante_celular,
                    solicitante_estado_civil,
                    solicitante_conyuge_nombre, solicitante_conyuge_cedula, solicitante_conyuge_celular,
                    garante_nombre, garante_cedula, garante_celular,
                    garante_estado_civil,
                    garante_conyuge_nombre, garante_conyuge_cedula, garante_conyuge_celular,
                    direccion_sitio, tiene_empresa,
                    venta_lv, venta_sabado, venta_domingo,
                    mes_alta_venta, mes_baja_venta,
                    compra_lv, compra_sabado, compra_domingo,
                    mes_alta_compra,
                    dias_atencion_lv, dias_atencion_sab, dias_atencion_dom,
                    doc_cedula, doc_planilla, doc_ruc_rise,
                    doc_estados_cuenta, doc_declaraciones, doc_matricula,
                    doc_foto_negocio, doc_solicitud_credito, doc_foto_cliente
                ) VALUES (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                )
            ");
            if (!$st) {
                respond('error', '[ficha_credito] prepare: ' . $conn->error);
            }
            $st->bind_param(
                'ssssssssssssssssssssssssssssssssssssssssssss', // 44 s
                $detalle_id, $ficha_id,
                $req,
                $dest, $odet,
                $mto, $plz,
                $snm, $scd, $scel, $sec, $scnm, $sccd, $sccel,
                $gnm, $gcd, $gcel, $gec, $gcnm, $gccd, $gccel,
                $dsite, $tiemp,
                $vlv, $vsb, $vdm, $mav, $mbv,
                $clv, $csb, $cdm, $mac,
                $dlv, $dsb, $ddm,
                $dc, $dpl, $drr, $des, $ddec, $dmat,
                $dfot, $dsc, $dfc
            );
            $st->execute();
            $st->close();
            break;

        case 'cuenta_corriente':
            $tipo     = s('tipo_cc')              ?: null;
            $tnom     = s('titular_nombre')        ?: null;
            $tced     = s('titular_cedula')        ?: null;
            $tcel     = s('titular_celular')       ?: null;
            $tec      = s('titular_estado_civil')  ?: null;
            $prop     = s('proposito')             ?: null;
            $mdep     = s('monto_deposito_prom')   ?: null;
            $usc      = s('usa_cheques')           ?: null;
            $rtd      = s('requiere_td')           ?: null;
            $ing      = s('ingreso_mensual')       ?: null;
            $nom      = s('tiene_nomina')          ?: null;
            $cc_otra  = s('tiene_cc_otra')         ?: null;
            $inst_cc  = s('institucion_cc')        ?: null;
            $obs      = s('observaciones')         ?: null;

            $st = $conn->prepare("
                INSERT INTO ficha_cuenta_corriente
                    (id, ficha_id, tipo_cc,
                     titular_nombre, titular_cedula, titular_celular, titular_estado_civil,
                     proposito, monto_deposito_prom,
                     usa_cheques, requiere_td, ingreso_mensual, tiene_nomina,
                     tiene_cc_otra, institucion_cc,
                     observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $st->bind_param('ssssssssssssssss', // 16 s
                $detalle_id, $ficha_id, $tipo,
                $tnom, $tced, $tcel, $tec,
                $prop, $mdep,
                $usc, $rtd, $ing, $nom,
                $cc_otra, $inst_cc,
                $obs
            );
            $st->execute();
            $st->close();
            break;

        case 'cuenta_ahorros':
            $tipo  = s('tipo_ahorro')          ?: null;
            $tnom  = s('titular_nombre')        ?: null;
            $tced  = s('titular_cedula')        ?: null;
            $tcel  = s('titular_celular')       ?: null;
            $tec   = s('titular_estado_civil')  ?: null;
            $moin  = s('monto_inicial')         ?: null;
            $frec  = s('frecuencia_deposito')   ?: null;
            $obj   = s('objetivo_ahorro')       ?: null;
            $ota   = s('tiene_ahorro_otra')     ?: null;
            $inst  = s('institucion_ahorro')    ?: null;
            $obs   = s('observaciones')         ?: null;

            $st = $conn->prepare("
                INSERT INTO ficha_cuenta_ahorros
                    (id, ficha_id, tipo_ahorro,
                     titular_nombre, titular_cedula, titular_celular, titular_estado_civil,
                     monto_inicial, frecuencia_deposito,
                     objetivo_ahorro, tiene_ahorro_otra, institucion_ahorro, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $st->bind_param('sssssssssssss', // 13 s
                $detalle_id, $ficha_id, $tipo,
                $tnom, $tced, $tcel, $tec,
                $moin, $frec,
                $obj, $ota, $inst, $obs
            );
            $st->execute();
            $st->close();
            break;

        case 'inversiones':
            $tipo     = s('tipo_inversion')          ?: null;
            $monto    = s('monto_inversion')         ?: null;
            $plazo    = s('plazo_meses')             ?: null;
            $obj      = s('objetivo_inversion')      ?: null;
            $inv_otra = s('tiene_inv_otra')          ?: null;
            $inst_comp= s('institucion_competencia') ?: null;
            $renov    = s('renovacion_auto')         ?: null;
            $obs      = s('observaciones')           ?: null;

            $st = $conn->prepare("
                INSERT INTO ficha_inversiones
                    (id, ficha_id, tipo_inversion, monto_inversion, plazo_meses,
                     objetivo_inversion, tiene_inv_otra, institucion_competencia,
                     renovacion_auto, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $st->bind_param('ssssssssss', // 10 s
                $detalle_id, $ficha_id, $tipo, $monto, $plazo,
                $obj, $inv_otra, $inst_comp, $renov, $obs
            );
            $st->execute();
            $st->close();
            break;
    }
} catch (\Throwable $e) {
    // Limpiar ficha huérfana
    $conn->query("DELETE FROM ficha_producto WHERE id = '$ficha_id'");
    respond('error', '[detalle] ' . $e->getMessage());
}

respond('success', 'Ficha guardada correctamente', ['ficha_id' => $ficha_id]);

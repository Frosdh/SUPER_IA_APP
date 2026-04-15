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
    $conexion->query("
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

    // Tablas por tipo (creadas solo si no existen)
    $conexion->query("
        CREATE TABLE IF NOT EXISTS ficha_credito (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            requiere_credito     TINYINT(1) DEFAULT NULL,
            dest_capital_trabajo TINYINT(1) DEFAULT 0,
            dest_activos_fijos   TINYINT(1) DEFAULT 0,
            dest_pago_deudas     TINYINT(1) DEFAULT 0,
            dest_consolidacion   TINYINT(1) DEFAULT 0,
            dest_vehiculo        TINYINT(1) DEFAULT 0,
            dest_vivienda_compra TINYINT(1) DEFAULT 0,
            dest_arreglos_vivienda TINYINT(1) DEFAULT 0,
            dest_educacion       TINYINT(1) DEFAULT 0,
            dest_viajes          TINYINT(1) DEFAULT 0,
            dest_otros           TINYINT(1) DEFAULT 0,
            dest_otros_detalle   VARCHAR(255) DEFAULT NULL,
            monto_credito        VARCHAR(30) DEFAULT NULL,
            plazo_credito_meses  VARCHAR(10) DEFAULT NULL,
            solicitante_nombre   VARCHAR(200) DEFAULT NULL,
            solicitante_cedula   VARCHAR(20)  DEFAULT NULL,
            garante_nombre       VARCHAR(200) DEFAULT NULL,
            garante_cedula       VARCHAR(20)  DEFAULT NULL,
            venta_lv             VARCHAR(20) DEFAULT NULL,
            venta_sabado         VARCHAR(20) DEFAULT NULL,
            venta_domingo        VARCHAR(20) DEFAULT NULL,
            mes_alta_venta       VARCHAR(5)  DEFAULT NULL,
            mes_baja_venta       VARCHAR(5)  DEFAULT NULL,
            compra_lv            VARCHAR(20) DEFAULT NULL,
            compra_sabado        VARCHAR(20) DEFAULT NULL,
            compra_domingo       VARCHAR(20) DEFAULT NULL,
            mes_alta_compra      VARCHAR(5)  DEFAULT NULL,
            dias_atencion_lv     TINYINT(1) DEFAULT 0,
            dias_atencion_sab    TINYINT(1) DEFAULT 0,
            dias_atencion_dom    TINYINT(1) DEFAULT 0,
            doc_cedula           TINYINT(1) DEFAULT 0,
            doc_planilla         TINYINT(1) DEFAULT 0,
            doc_ruc_rise         TINYINT(1) DEFAULT 0,
            doc_estados_cuenta   TINYINT(1) DEFAULT 0,
            doc_declaraciones    TINYINT(1) DEFAULT 0,
            doc_matricula        TINYINT(1) DEFAULT 0,
            doc_foto_negocio     TINYINT(1) DEFAULT 0,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conexion->query("
        CREATE TABLE IF NOT EXISTS ficha_cuenta_corriente (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_cc              VARCHAR(20) DEFAULT NULL,
            proposito            TEXT DEFAULT NULL,
            monto_deposito_prom  VARCHAR(30) DEFAULT NULL,
            usa_cheques          TINYINT(1) DEFAULT NULL,
            requiere_td          TINYINT(1) DEFAULT NULL,
            ingreso_mensual      VARCHAR(30) DEFAULT NULL,
            tiene_nomina         TINYINT(1) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conexion->query("
        CREATE TABLE IF NOT EXISTS ficha_cuenta_ahorros (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_ahorro          VARCHAR(20) DEFAULT NULL,
            monto_inicial        VARCHAR(30) DEFAULT NULL,
            frecuencia_deposito  VARCHAR(20) DEFAULT NULL,
            objetivo_ahorro      TEXT DEFAULT NULL,
            tiene_ahorro_otra    TINYINT(1) DEFAULT NULL,
            institucion_ahorro   VARCHAR(200) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conexion->query("
        CREATE TABLE IF NOT EXISTS ficha_inversiones (
            id                   CHAR(36) NOT NULL PRIMARY KEY,
            ficha_id             CHAR(36) NOT NULL,
            tipo_inversion       VARCHAR(20) DEFAULT NULL,
            monto_inversion      VARCHAR(30) DEFAULT NULL,
            plazo_meses          VARCHAR(10) DEFAULT NULL,
            objetivo_inversion   VARCHAR(30) DEFAULT NULL,
            tiene_inv_otra       TINYINT(1) DEFAULT NULL,
            renovacion_auto      TINYINT(1) DEFAULT NULL,
            observaciones        TEXT DEFAULT NULL,
            FOREIGN KEY (ficha_id) REFERENCES ficha_producto(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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
    $st = $conexion->prepare("
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
            // Todos los valores se pasan como string (MySQL hace el cast a TINYINT/INT)
            $req  = s('requiere_credito') ?: null;
            $ctl  = s('dest_capital_trabajo');   $adf  = s('dest_activos_fijos');
            $pdeu = s('dest_pago_deudas');        $cons = s('dest_consolidacion');
            $veh  = s('dest_vehiculo');           $viv  = s('dest_vivienda_compra');
            $arr  = s('dest_arreglos_vivienda');  $edu  = s('dest_educacion');
            $via  = s('dest_viajes');             $otr  = s('dest_otros');
            $odet = s('dest_otros_detalle') ?: null;
            $mto  = s('monto_credito') ?: null;   $plz  = s('plazo_credito_meses') ?: null;
            $snm  = s('solicitante_nombre') ?: null; $scd = s('solicitante_cedula') ?: null;
            $gnm  = s('garante_nombre') ?: null;     $gcd = s('garante_cedula') ?: null;
            $vlv  = s('venta_lv') ?: null;        $vsb = s('venta_sabado') ?: null;  $vdm = s('venta_domingo') ?: null;
            $mav  = s('mes_alta_venta') ?: null;  $mbv = s('mes_baja_venta') ?: null;
            $clv  = s('compra_lv') ?: null;       $csb = s('compra_sabado') ?: null; $cdm = s('compra_domingo') ?: null;
            $mac  = s('mes_alta_compra') ?: null;
            $dlv  = s('dias_atencion_lv');  $dsb = s('dias_atencion_sab'); $ddm = s('dias_atencion_dom');
            $dc   = s('doc_cedula');        $dpl = s('doc_planilla');      $drr = s('doc_ruc_rise');
            $des  = s('doc_estados_cuenta'); $ddec = s('doc_declaraciones'); $dmat = s('doc_matricula'); $dfot = s('doc_foto_negocio');

            // 39 parámetros — 39 's'
            $st = $conexion->prepare("
                INSERT INTO ficha_credito (
                    id, ficha_id,
                    requiere_credito,
                    dest_capital_trabajo, dest_activos_fijos, dest_pago_deudas,
                    dest_consolidacion, dest_vehiculo, dest_vivienda_compra,
                    dest_arreglos_vivienda, dest_educacion, dest_viajes,
                    dest_otros, dest_otros_detalle,
                    monto_credito, plazo_credito_meses,
                    solicitante_nombre, solicitante_cedula,
                    garante_nombre, garante_cedula,
                    venta_lv, venta_sabado, venta_domingo,
                    mes_alta_venta, mes_baja_venta,
                    compra_lv, compra_sabado, compra_domingo,
                    mes_alta_compra,
                    dias_atencion_lv, dias_atencion_sab, dias_atencion_dom,
                    doc_cedula, doc_planilla, doc_ruc_rise,
                    doc_estados_cuenta, doc_declaraciones,
                    doc_matricula, doc_foto_negocio
                ) VALUES (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                )
            ");
            $st->bind_param(
                'sssssssssssssssssssssssssssssssssssssss', // 39 s
                $detalle_id, $ficha_id,
                $req,
                $ctl, $adf, $pdeu, $cons, $veh, $viv, $arr, $edu, $via, $otr,
                $odet, $mto, $plz,
                $snm, $scd, $gnm, $gcd,
                $vlv, $vsb, $vdm, $mav, $mbv,
                $clv, $csb, $cdm, $mac,
                $dlv, $dsb, $ddm,
                $dc, $dpl, $drr, $des, $ddec, $dmat, $dfot
            );
            $st->execute();
            $st->close();
            break;

        case 'cuenta_corriente':
            $usc  = s('usa_cheques')  ?: null;
            $rtd  = s('requiere_td')  ?: null;
            $nom  = s('tiene_nomina') ?: null;
            $tipo = s('tipo_cc') ?: null;
            $prop = s('proposito') ?: null;
            $mdep = s('monto_deposito_prom') ?: null;
            $ing  = s('ingreso_mensual') ?: null;
            $obs  = s('observaciones') ?: null;

            $st = $conexion->prepare("
                INSERT INTO ficha_cuenta_corriente
                    (id, ficha_id, tipo_cc, proposito, monto_deposito_prom,
                     usa_cheques, requiere_td, ingreso_mensual, tiene_nomina, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $st->bind_param('ssssssssss', // 10 s
                $detalle_id, $ficha_id, $tipo, $prop, $mdep,
                $usc, $rtd, $ing, $nom, $obs
            );
            $st->execute();
            $st->close();
            break;

        case 'cuenta_ahorros':
            $ota  = s('tiene_ahorro_otra') ?: null;
            $tipo = s('tipo_ahorro') ?: null;
            $moin = s('monto_inicial') ?: null;
            $frec = s('frecuencia_deposito') ?: null;
            $obj  = s('objetivo_ahorro') ?: null;
            $inst = s('institucion_ahorro') ?: null;
            $obs  = s('observaciones') ?: null;

            $st = $conexion->prepare("
                INSERT INTO ficha_cuenta_ahorros
                    (id, ficha_id, tipo_ahorro, monto_inicial, frecuencia_deposito,
                     objetivo_ahorro, tiene_ahorro_otra, institucion_ahorro, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $st->bind_param('sssssssss',
                $detalle_id, $ficha_id, $tipo, $moin, $frec,
                $obj, $ota, $inst, $obs
            );
            $st->execute();
            $st->close();
            break;

        case 'inversiones':
            $inv_otra = s('tiene_inv_otra')  ?: null;
            $renov    = s('renovacion_auto')  ?: null;
            $tipo     = s('tipo_inversion') ?: null;
            $monto    = s('monto_inversion') ?: null;
            $plazo    = s('plazo_meses') ?: null;
            $obj      = s('objetivo_inversion') ?: null;
            $obs      = s('observaciones') ?: null;

            $st = $conexion->prepare("
                INSERT INTO ficha_inversiones
                    (id, ficha_id, tipo_inversion, monto_inversion, plazo_meses,
                     objetivo_inversion, tiene_inv_otra, renovacion_auto, observaciones)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $st->bind_param('sssssssss',
                $detalle_id, $ficha_id, $tipo, $monto, $plazo,
                $obj, $inv_otra, $renov, $obs
            );
            $st->execute();
            $st->close();
            break;
    }
} catch (\Throwable $e) {
    // Limpiar ficha huérfana
    $conexion->query("DELETE FROM ficha_producto WHERE id = '$ficha_id'");
    respond('error', '[detalle] ' . $e->getMessage());
}

respond('success', 'Ficha guardada correctamente', ['ficha_id' => $ficha_id]);

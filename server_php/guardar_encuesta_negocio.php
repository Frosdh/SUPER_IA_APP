<?php
// guardar_encuesta_negocio.php
// Guarda o actualiza la encuesta de negocio (empresa) asociada a un cliente.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

function respond_json($code, $payload) {
    if (!headers_sent()) { http_response_code((int)$code); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}
function genUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}
function strOrNull($v): ?string { $v = trim((string)$v); return $v !== '' ? $v : null; }
function intOrNull($v): ?int { if ($v === null || $v === '') return null; return (int)$v; }
function floatOrNull($v): ?float { if ($v === null || $v === '') return null; return (float)$v; }

$cliente_id = trim($_POST['cliente_id'] ?? '');
$tarea_id   = trim($_POST['tarea_id'] ?? '');

if ($cliente_id === '') {
    respond_json(200, ['status'=>'error','message'=>'cliente_id requerido']);
    exit;
}

try {
    $conn->begin_transaction();

    // Si no se provee tarea_id, buscar la última tarea del cliente
    if ($tarea_id === '') {
        $st = $conn->prepare('SELECT id FROM tarea WHERE cliente_prospecto_id = ? ORDER BY fecha_programada DESC LIMIT 1');
        $st->bind_param('s', $cliente_id);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if ($r) {
            $tarea_id = $r['id'];
        }
    }

    // Si aún no hay tarea, crear una nueva tarea de tipo 'levantamiento'
    if ($tarea_id === '') {
        $tarea_id = genUUID();
        $tipo_tarea = 'levantamiento';
        $estado = 'completada';
        $fecha_prog = date('Y-m-d');
        $hora_prog  = date('H:i:s');
        $obs = 'Levantamiento de empresa (creado por guardar_encuesta_negocio)';
        $lat_ini = floatOrNull($_POST['latitud_inicio'] ?? '');
        $lng_ini = floatOrNull($_POST['longitud_inicio'] ?? '');

        $st = $conn->prepare(
            "INSERT INTO tarea (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, hora_programada, fecha_realizada, hora_realizada, latitud_inicio, longitud_inicio, observaciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // asesor_id: la tarea DEBE quedar asignada a quien la levantó, nunca
        // sin dueño (si quedara en NULL caería en el "pool" y sería visible
        // para cualquier asesor, no solo para quien hizo el levantamiento).
        $asesor_id = trim($_POST['asesor_id'] ?? '');
        if ($asesor_id === '') {
            $usuario_id_neg = trim($_POST['usuario_id'] ?? '');
            if ($usuario_id_neg !== '') {
                $stA = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
                $stA->bind_param('s', $usuario_id_neg);
                $stA->execute();
                $rowA = $stA->get_result()->fetch_assoc();
                $stA->close();
                if ($rowA && !empty($rowA['id'])) {
                    $asesor_id = (string)$rowA['id'];
                }
            }
        }
        if ($asesor_id === '') {
            $conn->rollback();
            respond_json(200, ['status' => 'error', 'message' => 'No se pudo determinar el asesor (envíe asesor_id o usuario_id). La tarea no se creó para evitar dejarla sin dueño.']);
            exit;
        }
        $fecha_hoy = date('Y-m-d'); $hora_hoy = date('H:i:s');
        $st->bind_param('sssss s s s dd s', $tarea_id, $asesor_id, $cliente_id, $tipo_tarea, $estado, $fecha_prog, $hora_prog, $fecha_hoy, $hora_hoy, $lat_ini, $lng_ini, $obs);
        // NOTE: older PHP/mysqli may complain about typed bind string; use fallback if prepare fails
        if ($st === false) {
            // fallback simple insert (escape values)
            $asesor_q = $asesor_id ? "'" . $conn->real_escape_string($asesor_id) . "'" : 'NULL';
            $obs_q = $conn->real_escape_string($obs);
            $lat_q = $lat_ini === null ? 'NULL' : (float)$lat_ini;
            $lng_q = $lng_ini === null ? 'NULL' : (float)$lng_ini;
            $conn->query("INSERT INTO tarea (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, hora_programada, fecha_realizada, hora_realizada, latitud_inicio, longitud_inicio, observaciones) VALUES ('{$tarea_id}', {$asesor_q}, '{$cliente_id}', '{$tipo_tarea}', '{$estado}', '{$fecha_prog}', '{$hora_prog}', '{$fecha_hoy}', '{$hora_hoy}', {$lat_q}, {$lng_q}, '{$obs_q}')");
        } else {
            // mysqli requires correct type string; rebind using generic types
            $st->close();
            $st = $conn->prepare(
                "INSERT INTO tarea (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, hora_programada, fecha_realizada, hora_realizada, latitud_inicio, longitud_inicio, observaciones)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st->bind_param('ssssssssddds', $tarea_id, $asesor_id, $cliente_id, $tipo_tarea, $estado, $fecha_prog, $hora_prog, $fecha_hoy, $hora_hoy, $lat_ini, $lng_ini, $obs);
            $st->execute();
            $st->close();
        }
    }

    // Asegurar tabla encuesta_negocio (misma estructura que en guardar_cliente_encuesta.php)
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

    // ── Migración: agregar columnas que pueden faltar en tablas pre-existentes ──
    $cols_faltantes_neg = [
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
    ];
    foreach ($cols_faltantes_neg as $col => $sql) {
        $chk = $conn->query("SHOW COLUMNS FROM encuesta_negocio LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            try { $conn->query($sql); } catch (\Throwable $_) {}
        }
    }

    // Leer y normalizar campos (aceptar nulls)
    $venta_lv           = floatOrNull($_POST['venta_lv'] ?? null);
    $venta_sabado       = floatOrNull($_POST['venta_sabado'] ?? null);
    $venta_domingo      = floatOrNull($_POST['venta_domingo'] ?? null);
    $mes_alta_venta     = strOrNull($_POST['mes_alta_venta'] ?? null);
    $mes_baja_venta     = strOrNull($_POST['mes_baja_venta'] ?? null);
    $compra_lv          = floatOrNull($_POST['compra_lv'] ?? null);
    $compra_sabado      = floatOrNull($_POST['compra_sabado'] ?? null);
    $compra_domingo     = floatOrNull($_POST['compra_domingo'] ?? null);
    $mes_alta_compra    = strOrNull($_POST['mes_alta_compra'] ?? null);
    $dia_lv             = (int)($_POST['dias_atencion_lv'] ?? 0);
    $dia_sab            = (int)($_POST['dias_atencion_sab'] ?? 0);
    $dia_dom            = (int)($_POST['dias_atencion_dom'] ?? 0);
    $pct_contado        = intOrNull($_POST['pct_contado'] ?? null);
    $pct_credito        = intOrNull($_POST['pct_credito'] ?? null);
    $pct_efectivo       = intOrNull($_POST['pct_efectivo'] ?? null);
    $recuperacion_credito = floatOrNull($_POST['recuperacion_credito'] ?? null);
    $costos_ventas        = floatOrNull($_POST['costos_ventas'] ?? null);
    $gastos_negocio       = floatOrNull($_POST['gastos_negocio'] ?? null);
    $otros_ingresos       = floatOrNull($_POST['otros_ingresos'] ?? null);
    $gastos_familiares    = floatOrNull($_POST['gastos_familiares'] ?? null);
    $g_neg_sueldos       = floatOrNull($_POST['g_neg_sueldos'] ?? null);
    $g_neg_arriendo      = floatOrNull($_POST['g_neg_arriendo'] ?? null);
    $g_neg_serv_bas      = floatOrNull($_POST['g_neg_serv_bas'] ?? null);
    $g_neg_transporte    = floatOrNull($_POST['g_neg_transporte'] ?? null);
    $g_neg_mantenimiento = floatOrNull($_POST['g_neg_mantenimiento'] ?? null);
    $g_neg_otros         = floatOrNull($_POST['g_neg_otros'] ?? null);
    $g_neg_imprevistos   = floatOrNull($_POST['g_neg_imprevistos'] ?? null);
    $o_ing_conyuge    = floatOrNull($_POST['o_ing_conyuge'] ?? null);
    $o_ing_arriendos  = floatOrNull($_POST['o_ing_arriendos'] ?? null);
    $o_ing_pensiones  = floatOrNull($_POST['o_ing_pensiones'] ?? null);
    $o_ing_otros      = floatOrNull($_POST['o_ing_otros'] ?? null);
    $g_fam_alim        = floatOrNull($_POST['g_fam_alim'] ?? null);
    $g_fam_arriendo    = floatOrNull($_POST['g_fam_arriendo'] ?? null);
    $g_fam_serv_bas    = floatOrNull($_POST['g_fam_serv_bas'] ?? null);
    $g_fam_educacion   = floatOrNull($_POST['g_fam_educacion'] ?? null);
    $g_fam_salud       = floatOrNull($_POST['g_fam_salud'] ?? null);
    $g_fam_otros       = floatOrNull($_POST['g_fam_otros'] ?? null);
    $g_fam_imprevistos = floatOrNull($_POST['g_fam_imprevistos'] ?? null);
    $otras_deudas_json = $_POST['otras_deudas_json'] ?? null;
    $vehiculos_negocio_json = $_POST['vehiculos_negocio_json'] ?? null;
    $vehiculos_hogar_json = $_POST['vehiculos_hogar_json'] ?? null;
    $inmuebles_negocio_json = $_POST['inmuebles_negocio_json'] ?? null;
    $inmuebles_hogar_json = $_POST['inmuebles_hogar_json'] ?? null;

    // Verificar si ya existe encuesta_negocio para la tarea
    $st = $conn->prepare('SELECT id FROM encuesta_negocio WHERE tarea_id = ? LIMIT 1');
    $st->bind_param('s', $tarea_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        // UPDATE
        $enc_id = $row['id'];
        $stU = $conn->prepare(
            "UPDATE encuesta_negocio SET
                venta_lv=?, venta_sabado=?, venta_domingo=?, mes_alta_venta=?, mes_baja_venta=?,
                compra_lv=?, compra_sabado=?, compra_domingo=?, mes_alta_compra=?,
                dia_lv=?, dia_sab=?, dia_dom=?, pct_contado=?, pct_credito=?, pct_efectivo=?,
                recuperacion_credito=?, costos_ventas=?, gastos_negocio=?, otros_ingresos=?, gastos_familiares=?,
                g_neg_sueldos=?, g_neg_arriendo=?, g_neg_serv_bas=?, g_neg_transporte=?, g_neg_mantenimiento=?, g_neg_otros=?, g_neg_imprevistos=?,
                o_ing_conyuge=?, o_ing_arriendos=?, o_ing_pensiones=?, o_ing_otros=?,
                g_fam_alim=?, g_fam_arriendo=?, g_fam_serv_bas=?, g_fam_educacion=?, g_fam_salud=?, g_fam_otros=?, g_fam_imprevistos=?,
                otras_deudas_json=?, vehiculos_negocio_json=?, vehiculos_hogar_json=?, inmuebles_negocio_json=?, inmuebles_hogar_json=?
             WHERE id = ?"
        );
        $stU->bind_param('ddddssddddiiiiidddddddddddddddddddddsssssssssssss',
            $venta_lv, $venta_sabado, $venta_domingo, $mes_alta_venta, $mes_baja_venta,
            $compra_lv, $compra_sabado, $compra_domingo, $mes_alta_compra,
            $dia_lv, $dia_sab, $dia_dom, $pct_contado, $pct_credito, $pct_efectivo,
            $recuperacion_credito, $costos_ventas, $gastos_negocio, $otros_ingresos, $gastos_familiares,
            $g_neg_sueldos, $g_neg_arriendo, $g_neg_serv_bas, $g_neg_transporte, $g_neg_mantenimiento, $g_neg_otros, $g_neg_imprevistos,
            $o_ing_conyuge, $o_ing_arriendos, $o_ing_pensiones, $o_ing_otros,
            $g_fam_alim, $g_fam_arriendo, $g_fam_serv_bas, $g_fam_educacion, $g_fam_salud, $g_fam_otros, $g_fam_imprevistos,
            $otras_deudas_json, $vehiculos_negocio_json, $vehiculos_hogar_json, $inmuebles_negocio_json, $inmuebles_hogar_json,
            $enc_id
        );
        $stU->execute();
        $stU->close();

    } else {
        // INSERT
        $enc_id = genUUID();
        $stI = $conn->prepare(
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
              otras_deudas_json, vehiculos_negocio_json, vehiculos_hogar_json, inmuebles_negocio_json, inmuebles_hogar_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stI->bind_param('ssdddssdddsiiiiiidddddddddddddddddddddddsssss',
            $enc_id, $tarea_id,
            $venta_lv, $venta_sabado, $venta_domingo, $mes_alta_venta, $mes_baja_venta,
            $compra_lv, $compra_sabado, $compra_domingo, $mes_alta_compra,
            $dia_lv, $dia_sab, $dia_dom,
            $pct_contado, $pct_credito, $pct_efectivo,
            $recuperacion_credito, $costos_ventas, $gastos_negocio, $otros_ingresos, $gastos_familiares,
            $g_neg_sueldos, $g_neg_arriendo, $g_neg_serv_bas, $g_neg_transporte, $g_neg_mantenimiento, $g_neg_otros, $g_neg_imprevistos,
            $o_ing_conyuge, $o_ing_arriendos, $o_ing_pensiones, $o_ing_otros,
            $g_fam_alim, $g_fam_arriendo, $g_fam_serv_bas, $g_fam_educacion, $g_fam_salud, $g_fam_otros, $g_fam_imprevistos,
            $otras_deudas_json, $vehiculos_negocio_json, $vehiculos_hogar_json, $inmuebles_negocio_json, $inmuebles_hogar_json
        );
        $stI->execute();
        $stI->close();
    }

    // Marcar la tarea como completada y registrar fecha/hora de realización
    try {
        $fecha_hoy = date('Y-m-d');
        $hora_hoy  = date('H:i:s');
        $tarea_qid = $conn->real_escape_string($tarea_id);
        $conn->query("UPDATE tarea SET estado = 'completada', fecha_realizada = '{$fecha_hoy}', hora_realizada = '{$hora_hoy}' WHERE id = '{$tarea_qid}'");
    } catch (\Throwable $_) {
        // no bloquear si la actualización falla
    }

    // Intentar marcar en cliente_prospecto que ya se levantó empresa (columna opcional `levanto_empresa`)
    try {
        $cliente_q = $conn->real_escape_string($cliente_id);
        $conn->query("UPDATE cliente_prospecto SET levanto_empresa = 1 WHERE id = '{$cliente_q}'");
        // También limpiar estado 'pendiente' para reflejar que el levantamiento se completó
        try {
            $conn->query("UPDATE cliente_prospecto SET estado = 'prospecto' WHERE id = '{$cliente_q}' AND estado = 'pendiente'");
        } catch (\Throwable $_) {}
    } catch (\Throwable $_) {
        // ignorar si la columna no existe
    }

    $conn->commit();
    respond_json(200, ['status'=>'success','message'=>'Encuesta negocio guardada','encuesta_negocio_id'=>$enc_id,'tarea_id'=>$tarea_id,'cliente_id'=>$cliente_id]);
    exit;

} catch (\Throwable $e) {
    if (isset($conn) && $conn->connect_errno === 0) { try { $conn->rollback(); } catch (\Throwable $_) {} }
    error_log('[guardar_encuesta_negocio] ' . $e->getMessage());
    respond_json(200, ['status'=>'error','message'=>'Error interno: '.substr($e->getMessage(),0,200)]);
    exit;
}

?>
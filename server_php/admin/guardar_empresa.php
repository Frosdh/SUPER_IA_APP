<?php
/**
 * guardar_empresa.php (en admin/)
 * Guarda datos de levantamiento de empresa usando tablas existentes:
 * - cliente_prospecto (datos básicos empresa - Paso 1)
 * - encuesta_negocio (comportamiento ventas - Paso 2)
 * - producto_comercializado (productos que comercializa - Paso 3)
 * - encuesta_comercial (interés productos + cierre - Paso 4)
 * Llamado por levantamiento_empresa.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db_admin.php';

function respond_json($code, $payload) {
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function genUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function floatOrNull($v): ?float {
    if ($v === null || $v === '') return null;
    return (float)$v;
}

function intOrNull($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function boolToTinyInt($v): int {
    return ($v === 'on' || $v === '1' || $v === 1) ? 1 : 0;
}

// Datos enviados desde levantamiento_empresa.php O NuevaEncuestaScreen.dart (Flutter)
$cliente_id = trim($_POST['cliente_id'] ?? '');
$asesor_id  = trim($_POST['asesor_id'] ?? '');

if (!$cliente_id) {
    respond_json(200, ['status' => 'error', 'message' => 'cliente_id requerido']);
    exit;
}

// Mapear campos de Flutter vs web
// Flutter: ciudad, zona; Web: ciudad_empresa, zona_empresa
$ciudad_empresa = trim($_POST['ciudad_empresa'] ?? $_POST['ciudad'] ?? '');
$zona_empresa   = trim($_POST['zona_empresa'] ?? $_POST['zona'] ?? '');

try {
    $pdo->beginTransaction();

    // ========== PASO 1: Actualizar cliente_prospecto con datos básicos de empresa ==========
    $st = $pdo->prepare(
        "UPDATE cliente_prospecto SET
            nombre_empresa = ?,
            actividad = ?,
            asesor_id = ?,
            ciudad = ?,
            zona = ?,
            latitud = ?,
            longitud = ?,
            updated_at = NOW()
         WHERE id = ?"
    );
    $st->execute([
        trim($_POST['nombre_empresa'] ?? ''),
        trim($_POST['actividad'] ?? 'negocio_propio'),
        $asesor_id ?: null,
        $ciudad_empresa,
        $zona_empresa,
        floatOrNull($_POST['lat'] ?? $_POST['latitud_inicio'] ?? ''),
        floatOrNull($_POST['lng'] ?? $_POST['longitud_inicio'] ?? ''),
        $cliente_id
    ]);

    // ========== Obtener o crear TAREA de levantamiento ==========
    $st = $pdo->prepare(
        "SELECT id FROM tarea WHERE cliente_prospecto_id = ? AND tipo_tarea = 'levantamiento_empresa' 
         ORDER BY fecha_programada DESC LIMIT 1"
    );
    $st->execute([$cliente_id]);
    $tarea = $st->fetch(PDO::FETCH_ASSOC);
    
    if ($tarea) {
        $tarea_id = $tarea['id'];
    } else {
        $tarea_id = genUUID();
        $st = $pdo->prepare(
            "INSERT INTO tarea (id, asesor_id, cliente_prospecto_id, tipo_tarea, estado, fecha_programada, hora_programada, latitud_inicio, longitud_inicio)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)"
        );
        $st->execute([
            $tarea_id,
            $asesor_id ?: null,
            $cliente_id,
            'levantamiento_empresa',
            'pendiente',
            date('H:i:s'),
            floatOrNull($_POST['lat'] ?? ''),
            floatOrNull($_POST['lng'] ?? '')
        ]);
    }

    // ========== PASO 2: Insertar ENCUESTA_NEGOCIO (comportamiento de ventas) ==========
    $encuesta_negocio_id = genUUID();
    $st = $pdo->prepare(
        "INSERT INTO encuesta_negocio (
            id, tarea_id,
            venta_lv, venta_sabado, venta_domingo,
            mes_alta_venta, mes_baja_venta,
            compra_lv, compra_sabado, compra_domingo,
            mes_alta_compra,
            dia_lv, dia_sab, dia_dom,
            pct_contado, pct_credito, pct_efectivo,
            recuperacion_credito, costos_ventas, gastos_negocio, otros_ingresos, gastos_familiares,
            g_neg_sueldos, g_neg_arriendo, g_neg_serv_bas, g_neg_transporte, g_neg_mantenimiento, g_neg_otros, g_neg_imprevistos,
            o_ing_conyuge, o_ing_arriendos, o_ing_pensiones, o_ing_otros,
            g_fam_alim, g_fam_arriendo, g_fam_serv_bas, g_fam_educacion, g_fam_salud, g_fam_otros, g_fam_imprevistos
        ) VALUES (
            ?, ?,
            ?, ?, ?,
            ?, ?,
            ?, ?, ?,
            ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?
        )"
    );
    $st->execute([
        $encuesta_negocio_id, $tarea_id,
        floatOrNull($_POST['venta_lv'] ?? ''),
        floatOrNull($_POST['venta_sabado'] ?? ''),
        floatOrNull($_POST['venta_domingo'] ?? ''),
        trim($_POST['mes_alta_venta'] ?? ''),
        trim($_POST['mes_baja_venta'] ?? ''),
        floatOrNull($_POST['compra_lv'] ?? ''),
        floatOrNull($_POST['compra_sabado'] ?? ''),
        floatOrNull($_POST['compra_domingo'] ?? ''),
        trim($_POST['mes_alta_compra'] ?? ''),
        boolToTinyInt($_POST['dia_lv'] ?? ''),
        boolToTinyInt($_POST['dia_sab'] ?? ''),
        boolToTinyInt($_POST['dia_dom'] ?? ''),
        intOrNull($_POST['pct_contado'] ?? ''),
        intOrNull($_POST['pct_credito'] ?? ''),
        intOrNull($_POST['pct_efectivo'] ?? ''),
        floatOrNull($_POST['recuperacion_credito'] ?? ''),
        floatOrNull($_POST['costos_ventas'] ?? ''),
        floatOrNull($_POST['gastos_negocio'] ?? ''),
        floatOrNull($_POST['otros_ingresos'] ?? ''),
        floatOrNull($_POST['gastos_familiares'] ?? ''),
        floatOrNull($_POST['g_neg_sueldos'] ?? ''),
        floatOrNull($_POST['g_neg_arriendo'] ?? ''),
        floatOrNull($_POST['g_neg_serv_bas'] ?? ''),
        floatOrNull($_POST['g_neg_transporte'] ?? ''),
        floatOrNull($_POST['g_neg_mantenimiento'] ?? ''),
        floatOrNull($_POST['g_neg_otros'] ?? ''),
        floatOrNull($_POST['g_neg_imprevistos'] ?? ''),
        floatOrNull($_POST['o_ing_conyuge'] ?? ''),
        floatOrNull($_POST['o_ing_arriendos'] ?? ''),
        floatOrNull($_POST['o_ing_pensiones'] ?? ''),
        floatOrNull($_POST['o_ing_otros'] ?? ''),
        floatOrNull($_POST['g_fam_alim'] ?? ''),
        floatOrNull($_POST['g_fam_arriendo'] ?? ''),
        floatOrNull($_POST['g_fam_serv_bas'] ?? ''),
        floatOrNull($_POST['g_fam_educacion'] ?? ''),
        floatOrNull($_POST['g_fam_salud'] ?? ''),
        floatOrNull($_POST['g_fam_otros'] ?? ''),
        floatOrNull($_POST['g_fam_imprevistos'] ?? '')
    ]);

    // ========== PASO 3: Insertar PRODUCTO_COMERCIALIZADO (productos que vende) ==========
    // Obtener o crear encuesta_crediticia (requerida para productos)
    $st = $pdo->prepare("SELECT id FROM encuesta_crediticia LIMIT 1");
    $st->execute();
    $encuesta_cred = $st->fetch(PDO::FETCH_ASSOC);
    
    // Si no existe, creamos una dummy
    if (!$encuesta_cred) {
        $encuesta_cred_id = genUUID();
        $pdo->prepare("INSERT INTO encuesta_crediticia (id) VALUES (?)")->execute([$encuesta_cred_id]);
    } else {
        $encuesta_cred_id = $encuesta_cred['id'];
    }

    // Procesar productos: puede venir en dos formatos
    // 1. Formato web: prod[i][nombre], prod[i][precio_venta], etc.
    // 2. Formato Flutter JSON: comercio_productos_json o productos_json
    
    $productos_a_guardar = [];
    
    // Intenta procesar prod[i] arrays (formato web)
    $i = 0;
    while (isset($_POST["prod[$i][nombre]"]) && trim($_POST["prod[$i][nombre]"]) !== '') {
        $productos_a_guardar[] = [
            'nombre' => trim($_POST["prod[$i][nombre]"] ?? ''),
            'precio_venta' => floatOrNull($_POST["prod[$i][precio_venta]"] ?? ''),
            'costo' => floatOrNull($_POST["prod[$i][costo]"] ?? ''),
            'cantidad' => intOrNull($_POST["prod[$i][cantidad]"] ?? ''),
            'margen' => floatOrNull($_POST["prod[$i][margen]"] ?? ''),
            'total_venta_mes' => floatOrNull($_POST["prod[$i][total_venta_mes]"] ?? ''),
            'inventario' => intOrNull($_POST["prod[$i][inventario]"] ?? ''),
            'compra_sem' => floatOrNull($_POST["prod[$i][compra_sem]"] ?? '')
        ];
        $i++;
    }
    
    // Si no hay productos en formato web, intenta JSON de Flutter
    if (empty($productos_a_guardar)) {
        $json_str = $_POST['comercio_productos_json'] ?? $_POST['productos_json'] ?? '';
        if ($json_str) {
            try {
                $json_data = json_decode($json_str, true);
                if (is_array($json_data)) {
                    foreach ($json_data as $prod) {
                        $productos_a_guardar[] = [
                            'nombre' => $prod['nombre'] ?? '',
                            'precio_venta' => floatOrNull($prod['precio_venta_unidad'] ?? $prod['precio_unitario'] ?? ''),
                            'costo' => floatOrNull($prod['costo_unidad'] ?? ''),
                            'cantidad' => intOrNull($prod['cantidad_vendida_mes'] ?? $prod['unidades_vendidas'] ?? ''),
                            'margen' => floatOrNull($prod['margen_utilidad_pct'] ?? $prod['margen_utilidad'] ?? ''),
                            'total_venta_mes' => floatOrNull($prod['total_venta_mes'] ?? $prod['ventas_mensuales'] ?? ''),
                            'inventario' => intOrNull($prod['inventario_existente'] ?? $prod['unidades_verificadas'] ?? ''),
                            'compra_sem' => floatOrNull($prod['monto_compra_promedio_sem'] ?? '')
                        ];
                    }
                }
            } catch (Exception $e) {}
        }
    }

    // Insertar productos en BD
    foreach ($productos_a_guardar as $prod) {
        if (trim($prod['nombre'])) {
            $prod_id = genUUID();
            $st = $pdo->prepare(
                "INSERT INTO producto_comercializado (
                    id, encuesta_crediticia_id,
                    nombre_producto, precio_venta_unidad, costo_unidad,
                    cantidad_vendida_mes, margen_utilidad_pct, total_venta_mes,
                    inventario_existente, monto_compra_promedio_sem
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st->execute([
                $prod_id,
                $encuesta_cred_id,
                trim($prod['nombre']),
                $prod['precio_venta'],
                $prod['costo'],
                $prod['cantidad'],
                $prod['margen'],
                $prod['total_venta_mes'],
                $prod['inventario'],
                $prod['compra_sem']
            ]);
        }
    }

    // ========== PASO 4: Insertar ENCUESTA_COMERCIAL (interés productos + cierre) ==========
    $encuesta_comercial_id = genUUID();
    $st = $pdo->prepare(
        "INSERT INTO encuesta_comercial (
            id, tarea_id,
            mantiene_cuenta_ahorro, mantiene_cuenta_corriente,
            tiene_inversiones, institucion_inversiones, valor_inversion, plazo_inversion, fecha_vencimiento_inversion,
            interes_propuesta_previa, fecha_nuevo_contacto,
            tiene_operaciones_crediticias, institucion_credito,
            interes_conocer_productos,
            interes_cc, interes_ahorro, interes_inversion, interes_credito,
            razon_ya_trabaja_institucion, razon_desconfia_servicios, razon_agusto_actual, razon_mala_experiencia,
            razon_otros,
            acuerdo_logrado, fecha_acuerdo, hora_acuerdo,
            observaciones
        ) VALUES (
            ?, ?,
            ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?,
            ?, ?, ?,
            ?
        )"
    );
    $st->execute([
        $encuesta_comercial_id,
        $tarea_id,
        boolToTinyInt($_POST['mantiene_cuenta_ahorros'] ?? $_POST['mantiene_ahorro'] ?? ''),
        boolToTinyInt($_POST['mantiene_cuenta_corriente'] ?? $_POST['mantiene_corriente'] ?? ''),
        boolToTinyInt($_POST['tiene_inversiones'] ?? ''),
        trim($_POST['institucion_inversiones'] ?? $_POST['institucion_inversiones'] ?? ''),
        floatOrNull($_POST['valor_inversion'] ?? ''),
        trim($_POST['plazo_inversion'] ?? ''),
        trim($_POST['fecha_vencimiento_inversion'] ?? '') ?: null,
        boolToTinyInt($_POST['propuesta_prev_vencimiento'] ?? $_POST['interes_propuesta_previa'] ?? ''),
        trim($_POST['fecha_nuevo_contacto'] ?? '') ?: null,
        boolToTinyInt($_POST['tiene_operaciones_crediticias'] ?? $_POST['tiene_opsCred'] ?? ''),
        trim($_POST['institucion_credito'] ?? ''),
        boolToTinyInt($_POST['interes_conocer_productos'] ?? $_POST['interes_conocer'] ?? ''),
        // Mapeo: Flutter envía interes_cc, interes_ahorro, etc.
        boolToTinyInt($_POST['interes_cc'] ?? $_POST['solicitar_corriente'] ?? ''),
        boolToTinyInt($_POST['interes_ahorro'] ?? $_POST['solicitar_ahorro'] ?? ''),
        boolToTinyInt($_POST['interes_inversion'] ?? $_POST['solicitar_inversion'] ?? ''),
        boolToTinyInt($_POST['interes_credito'] ?? $_POST['solicitar_credito'] ?? ''),
        boolToTinyInt($_POST['razon_ya_trabaja_institucion'] ?? $_POST['razon_ya_trabaja'] ?? ''),
        boolToTinyInt($_POST['razon_desconfia_servicios'] ?? $_POST['razon_desconfia'] ?? ''),
        boolToTinyInt($_POST['razon_agusto_actual'] ?? $_POST['razon_agusto'] ?? ''),
        boolToTinyInt($_POST['razon_mala_experiencia'] ?? $_POST['razon_mala_exp'] ?? ''),
        trim($_POST['razon_otros'] ?? ''),
        trim($_POST['acuerdo_logrado'] ?? '') ?: null,
        trim($_POST['fecha_acuerdo'] ?? '') ?: null,
        trim($_POST['hora_acuerdo'] ?? '') ?: null,
        trim($_POST['observaciones'] ?? '')
    ]);

    // ========== Marcar tarea como completada ==========
    $st = $pdo->prepare('UPDATE tarea SET estado = ?, fecha_realizada = NOW(), hora_realizada = ? WHERE id = ?');
    $st->execute(['completada', date('H:i:s'), $tarea_id]);

    $pdo->commit();

    respond_json(200, [
        'status' => 'success',
        'message' => 'Levantamiento de empresa guardado correctamente',
        'tarea_id' => $tarea_id,
        'encuesta_negocio_id' => $encuesta_negocio_id,
        'encuesta_comercial_id' => $encuesta_comercial_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    respond_json(500, [
        'status' => 'error',
        'message' => 'Error al guardar: ' . $e->getMessage(),
        'debug' => $e->getFile() . ':' . $e->getLine()
    ]);
}

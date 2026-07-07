<?php
// ============================================================
// sync_clientes_cache.php
// ------------------------------------------------------------
// Alimenta la caché local (SQLite, tabla 'clientes_cache') que usa
// ClienteCacheService.dart para poder verificar una cédula (¿existe?
// ¿es cliente o prospecto? ¿ya tiene empresa?) SIN internet en
// NuevaEncuestaScreen.
//
// Dos cosas para que esto no sea pesado ni para el servidor ni para
// el celular:
//
//  1) Se filtra SIEMPRE por 'asesor_id': cada celular solo descarga
//     su propia cartera, no la tabla cliente_prospecto completa.
//  2) Soporta sincronización incremental: si se manda 'since' (el
//     mayor 'updated_at' recibido en la última sincronización), solo
//     se devuelven los registros que cambiaron desde entonces. Sin
//     'since' (primera vez en ese celular) se manda todo, hasta el
//     límite indicado.
//
// La forma de cada item en 'items' es intencionalmente la misma que
// el campo 'data' de buscar_prospecto_por_cedula.php (más 'es_cliente'
// y 'updated_at'), para que el cliente pueda reusar el mismo código
// de "prellenar formulario" sin importar si el dato vino del servidor
// en vivo o de la caché local.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-07-07a';

function respond_json($code, $payload) {
    global $API_BUILD;
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (is_array($payload)) $payload['build'] = $API_BUILD;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

// Nunca devolver body vacío en fatal errors (mismo patrón que el resto
// de endpoints offline: la app no debe recibir un cuerpo vacío/HTML).
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) return;
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    $msg = substr($err['message'] ?? '', 0, 180);
    echo json_encode(['status' => 'error', 'message' => "Error interno: $msg"]);
});

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status' => 'ok', 'message' => 'sync_clientes_cache alive', 'build' => $API_BUILD]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

$asesor_id = trim($_POST['asesor_id'] ?? $_GET['asesor_id'] ?? '');
$since     = trim($_POST['since']     ?? $_GET['since']     ?? '');
$limit     = (int)($_POST['limit']    ?? $_GET['limit']     ?? 1000);
if ($limit <= 0 || $limit > 3000) $limit = 1000;

if ($asesor_id === '') {
    respond_json(200, ['status' => 'error', 'message' => 'asesor_id requerido']);
    exit;
}

try {
    // 'es_cliente' se calcula igual que en buscar_prospecto_por_cedula.php:
    // aprobado en ficha_producto O crédito aprobado/desembolsado. Se arma
    // como sub-SELECT condicional porque en algunos despliegues esas
    // tablas podrían no existir todavía (ver comprobación abajo).
    $tieneFicha = false;
    $tieneCredito = false;
    try {
        $r1 = $conn->query("SHOW TABLES LIKE 'ficha_producto'");
        $tieneFicha = ($r1 !== false && $r1->num_rows > 0);
    } catch (Throwable $ignored) {}
    try {
        $r2 = $conn->query("SHOW TABLES LIKE 'credito_proceso'");
        $tieneCredito = ($r2 !== false && $r2->num_rows > 0);
    } catch (Throwable $ignored) {}

    $esClienteParts = [];
    if ($tieneFicha) {
        $esClienteParts[] = "EXISTS(SELECT 1 FROM ficha_producto fp WHERE fp.cliente_cedula = cp.cedula AND fp.estado_revision = 'aprobada')";
    }
    if ($tieneCredito) {
        $esClienteParts[] = "EXISTS(SELECT 1 FROM credito_proceso kp WHERE kp.cliente_prospecto_id = cp.id AND (kp.estado_credito IN ('aprobado','desembolsado') OR kp.estado IN ('aprobado','desembolsado')))";
    }
    $esClienteSql = $esClienteParts ? ('(' . implode(' OR ', $esClienteParts) . ')') : '0';

    // COALESCE(updated_at, created_at): filas antiguas creadas antes de
    // que existiera la columna 'updated_at' igual necesitan una fecha de
    // corte utilizable para el delta-sync.
    $sql = "SELECT cp.id, cp.nombre, cp.cedula, cp.telefono, cp.telefono2 AS celular, cp.email,
                   cp.direccion, cp.ciudad, cp.zona, cp.actividad, cp.nombre_empresa,
                   cp.tiene_ruc, cp.tiene_rise, cp.asesor_id, cp.estado AS estado_db,
                   cp.latitud, cp.longitud, cp.created_at, cp.tiene_empresa,
                   cp.ruc_val, cp.rise_val, cp.tipo_empresa, cp.regimen_tributario,
                   cp.numero_ruc, cp.declara_iva, cp.emite_facturas, cp.lleva_contabilidad,
                   cp.paga_cuota_rise, cp.emite_notas_venta, cp.conoce_limite_rise,
                   cp.origen_prospecto,
                   COALESCE(cp.updated_at, cp.created_at) AS updated_at,
                   $esClienteSql AS es_cliente
            FROM cliente_prospecto cp
            WHERE cp.asesor_id = ?";
    $types = 's';
    $params = [$asesor_id];

    if ($since !== '') {
        $sql .= " AND COALESCE(cp.updated_at, cp.created_at) > ?";
        $types .= 's';
        $params[] = $since;
    }

    $sql .= " ORDER BY COALESCE(cp.updated_at, cp.created_at) ASC LIMIT ?";
    $types .= 'i';
    $params[] = $limit;

    $st = $conn->prepare($sql);
    if (!$st) {
        respond_json(200, ['status' => 'error', 'message' => 'Error preparando consulta']);
        exit;
    }
    $st->bind_param($types, ...$params);
    $st->execute();
    $res = $st->get_result();

    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['es_cliente'] = (int)($row['es_cliente'] ?? 0);
        $items[] = $row;
    }
    $st->close();

    respond_json(200, [
        'status' => 'success',
        'count'  => count($items),
        'items'  => $items,
    ]);
} catch (Throwable $e) {
    respond_json(200, ['status' => 'error', 'message' => 'Error consultando: ' . $e->getMessage()]);
}

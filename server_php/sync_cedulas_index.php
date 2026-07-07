<?php
// ============================================================
// sync_cedulas_index.php
// ------------------------------------------------------------
// Alimenta un índice local liviano (SQLite, tabla 'cedulas_index') que
// cubre TODA la empresa (todos los asesores), a diferencia de
// sync_clientes_cache.php que solo entrega la cartera de un asesor.
//
// Existe para un caso concreto: un asesor busca sin internet una cédula
// que no es "suya" (la levantó otro asesor, o ya es cliente por otra
// vía). 'clientes_cache' (cartera propia) no la va a tener, así que sin
// este índice la app no tendría forma de saber que esa cédula ya existe.
//
// Para que cubrir TODA la empresa siga siendo liviano en el celular, este
// endpoint NO manda el detalle pesado (RUC, régimen tributario,
// dirección, etc., eso sigue viniendo de sync_clientes_cache.php o de
// buscar_prospecto_por_cedula.php en vivo) — solo lo mínimo para
// responder "¿existe? ¿es cliente? ¿tiene empresa? ¿de qué asesor?".
//
// Igual que sync_clientes_cache.php, soporta delta-sync con 'since' (el
// mayor 'updated_at' recibido la última vez) para no tener que volver a
// bajar/reescribir todo el índice en cada reconexión.
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

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) return;
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    $msg = substr($err['message'] ?? '', 0, 180);
    echo json_encode(['status' => 'error', 'message' => "Error interno: $msg"]);
});

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status' => 'ok', 'message' => 'sync_cedulas_index alive', 'build' => $API_BUILD]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

$since = trim($_POST['since'] ?? $_GET['since'] ?? '');
$limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 2000);
if ($limit <= 0 || $limit > 5000) $limit = 2000;

try {
    // Mismo cálculo de 'es_cliente' que sync_clientes_cache.php y
    // buscar_prospecto_por_cedula.php: aprobado en ficha_producto O
    // crédito aprobado/desembolsado.
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

    // Nota: a propósito NO se selecciona detalle pesado (RUC, régimen
    // tributario, dirección, etc.) — este endpoint es solo el índice
    // liviano. El detalle completo se obtiene en línea (o de
    // clientes_cache/sync_clientes_cache.php si es cartera propia).
    $sql = "SELECT cp.id, cp.cedula, cp.nombre, cp.estado AS estado_db,
                   cp.asesor_id, cp.tiene_empresa, cp.nombre_empresa,
                   COALESCE(cp.updated_at, cp.created_at) AS updated_at,
                   $esClienteSql AS es_cliente
            FROM cliente_prospecto cp
            WHERE cp.cedula IS NOT NULL AND cp.cedula != ''";
    $types = '';
    $params = [];

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
    if ($types !== '') {
        $st->bind_param($types, ...$params);
    }
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

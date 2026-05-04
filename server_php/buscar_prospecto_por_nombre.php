<?php
// ============================================================
// buscar_prospecto_por_nombre.php  —  v2026-05-04
// Busca prospectos/clientes por nombre o nombre_empresa (LIKE)
// Devuelve hasta 10 resultados para el levantamiento de empresa.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-05-04';

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
    if (!in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) return;
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['status' => 'error', 'message' => 'Error interno', 'resultados' => []]);
});

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status' => 'ok', 'message' => 'buscar_prospecto_por_nombre alive', 'build' => $API_BUILD]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$nombre = trim($_POST['nombre'] ?? $_GET['nombre'] ?? '');
if (strlen($nombre) < 2) {
    respond_json(200, ['status' => 'error', 'message' => 'Ingresa al menos 2 caracteres.', 'resultados' => []]);
    exit;
}

try {
    $like = '%' . $nombre . '%';

    $st = $conn->prepare(
        "SELECT cp.id, cp.nombre, cp.cedula, cp.telefono, cp.telefono2 AS celular,
                cp.email, cp.direccion, cp.ciudad, cp.zona,
                cp.actividad, cp.nombre_empresa, cp.tiene_ruc, cp.tiene_rise,
                cp.estado, cp.asesor_id
         FROM cliente_prospecto cp
         WHERE cp.nombre LIKE ?
            OR cp.nombre_empresa LIKE ?
         ORDER BY cp.nombre ASC
         LIMIT 10"
    );
    $st->bind_param('ss', $like, $like);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $resultados = [];
    foreach ($rows as $row) {
        $resultados[] = [
            'id'             => (string)($row['id'] ?? ''),
            'nombre'         => (string)($row['nombre'] ?? ''),
            'cedula'         => (string)($row['cedula'] ?? ''),
            'telefono'       => (string)($row['telefono'] ?? ''),
            'celular'        => (string)($row['celular'] ?? ''),
            'email'          => (string)($row['email'] ?? ''),
            'direccion'      => (string)($row['direccion'] ?? ''),
            'ciudad'         => (string)($row['ciudad'] ?? ''),
            'zona'           => (string)($row['zona'] ?? ''),
            'actividad'      => (string)($row['actividad'] ?? ''),
            'nombre_empresa' => (string)($row['nombre_empresa'] ?? ''),
            'tiene_ruc'      => (int)($row['tiene_ruc'] ?? 0),
            'tiene_rise'     => (int)($row['tiene_rise'] ?? 0),
            'estado'         => (string)($row['estado'] ?? 'prospecto'),
        ];
    }

    respond_json(200, [
        'status'     => count($resultados) > 0 ? 'found' : 'not_found',
        'resultados' => $resultados,
        'total'      => count($resultados),
    ]);

} catch (Throwable $e) {
    respond_json(200, [
        'status'     => 'error',
        'message'    => 'Error consultando: ' . $e->getMessage(),
        'resultados' => [],
    ]);
}

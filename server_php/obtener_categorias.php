<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-04-14a';

if (!defined('JSON_UNESCAPED_UNICODE')) {
    define('JSON_UNESCAPED_UNICODE', 0);
}

if (!function_exists('http_response_code')) {
    function http_response_code($code = null) {
        if ($code !== null) {
            header('X-PHP-Response-Code: ' . (int)$code, true, (int)$code);
        }
        return null;
    }
}

function respond_json($code, $payload) {
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (is_array($payload) && !isset($payload['build'])) {
        $payload['build'] = isset($GLOBALS['API_BUILD']) ? $GLOBALS['API_BUILD'] : null;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

// Capturar fatales (parse/error, undefined function, etc.) para que nunca quede body vacío.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) return;

    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    $errType = isset($err['type']) ? (int)$err['type'] : 0;
    if (!in_array($errType, $fatalTypes, true)) return;

    $errMsg = isset($err['message']) ? $err['message'] : '';
    $errFile = isset($err['file']) ? $err['file'] : '';
    $errLine = isset($err['line']) ? $err['line'] : '';
    @error_log('[obtener_categorias][FATAL] ' . $errMsg . ' in ' . $errFile . ':' . $errLine);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(array(
        'status' => 'success',
        'categorias' => array(),
        'build' => isset($GLOBALS['API_BUILD']) ? $GLOBALS['API_BUILD'] : null,
    ), JSON_UNESCAPED_UNICODE);
});

set_exception_handler(function ($e) {
    @error_log('[obtener_categorias][EXCEPTION_HANDLER] ' . $e);
    respond_json(200, array('status' => 'success', 'categorias' => array()));
    exit;
});

// Ping opcional para verificar despliegue sin afectar el uso normal del endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ping'])) {
    respond_json(200, array(
        'status' => 'ok',
        'message' => 'obtener_categorias.php alive',
        'build' => $API_BUILD,
        'php' => PHP_VERSION,
    ));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    respond_json(200, ['status' => 'success', 'categorias' => []]);
    exit;
}

require_once __DIR__ . '/db_config.php';

// Forzar errores de MySQLi como excepciones (capturables)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // Si la tabla no existe en el hosting, no romper: el app usa fallback.
    $check = $conn->query("SHOW TABLES LIKE 'categorias'");
    if (!$check || $check->num_rows === 0) {
        respond_json(200, ['status' => 'success', 'categorias' => []]);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT id, nombre, tarifa_base, precio_km, precio_minuto FROM categorias ORDER BY id ASC"
    );
    if (!$stmt) {
        respond_json(200, ['status' => 'success', 'categorias' => []]);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $categorias = [];
    while ($row = $result->fetch_assoc()) {
        $categorias[] = [
            'id' => (int)$row['id'],
            'nombre' => $row['nombre'],
            'tarifa_base' => (float)$row['tarifa_base'],
            'precio_km' => (float)$row['precio_km'],
            'precio_minuto' => (float)$row['precio_minuto'],
        ];
    }

    respond_json(200, [
        'status' => 'success',
        'categorias' => $categorias,
    ]);

    $stmt->close();
} catch (Exception $e) {
    @error_log('[obtener_categorias][EXCEPTION] ' . $e);
    respond_json(200, ['status' => 'success', 'categorias' => []]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

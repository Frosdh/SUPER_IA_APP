<?php
// ============================================================
// db_config.php — Conexión centralizada a la base de datos
// NUNCA subir este archivo a repositorios públicos (GitHub, etc.)
// Agregar a .gitignore: server_php/db_config.php
// ============================================================

$error_reporting_level = E_ALL;
error_reporting($error_reporting_level);
ini_set('display_errors', '0');

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

$db_host     = "localhost";
$db_name     = "corporat_base_super_ia";
$db_user     = "corporat_coac_user";
$db_password = 'zCD;^[1YN[AE8P6f';

$conn = null;

try {
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name);
} catch (Exception $e) {
    @error_log('[db_config][CONNECT_EXCEPTION] ' . $e);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error de conexión a la base de datos'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($conn->connect_error) {
    @error_log('[db_config][CONNECT_ERROR] ' . $conn->connect_error);
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error de conexión a la base de datos'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$conn->set_charset('utf8mb4');
?>

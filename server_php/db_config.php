<?php
// ============================================================
// db_config.php — Conexión centralizada a la base de datos
// NUNCA subir este archivo a repositorios públicos (GitHub, etc.)
// Agregar a .gitignore: server_php/db_config.php
// ============================================================

$error_reporting_level = E_ALL;
error_reporting($error_reporting_level);
ini_set('display_errors', '0');

// Zona horaria de Ecuador (evita que las horas guardadas/mostradas
// salgan adelantadas si el servidor corre en UTC por defecto)
date_default_timezone_set('America/Guayaquil');

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
$db_password = '*6LuhePgy=9?Zy-&';

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

// Forzar la sesión de MySQL a hora de Ecuador (-05:00, sin horario de verano).
// Sin esto, NOW()/CURDATE()/CURTIME()/CURRENT_TIMESTAMP() usan la zona horaria
// del servidor de base de datos (con frecuencia UTC en hosting compartido),
// lo que hacía que las tareas se guardaran ~5 horas adelantadas.
$conn->query("SET time_zone = '-05:00'");
?>

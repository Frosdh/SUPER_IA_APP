<?php
/**
 * DEBUG: Mostrar IP del servidor para configurar en Flutter
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$ip_local = gethostbyname(gethostname());
$ip_remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$server_name = $_SERVER['SERVER_NAME'] ?? 'unknown';
$server_addr = $_SERVER['SERVER_ADDR'] ?? 'unknown';
$http_host = $_SERVER['HTTP_HOST'] ?? 'unknown';

echo json_encode([
    'status' => 'success',
    'server_info' => [
        'gethostbyname' => $ip_local,
        'SERVER_ADDR' => $server_addr,
        'SERVER_NAME' => $server_name,
        'HTTP_HOST' => $http_host,
        'CLIENT_IP' => $ip_remote,
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
    ],
    'message' => 'Usa la IP que coincida con tu red local (ej: 192.168.x.x o 10.x.x.x)'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>

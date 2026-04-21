<?php
// Simple echo endpoint for debugging POST delivery
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$h = function_exists('getallheaders') ? getallheaders() : [];
$raw = @file_get_contents('php://input');
$out = [
    'ok' => true,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'headers' => $h,
    'post_keys' => array_keys($_POST),
    'post' => $_POST,
    'raw_len' => strlen($raw),
    'raw' => strlen($raw) <= 2000 ? $raw : substr($raw,0,2000),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE);

?>

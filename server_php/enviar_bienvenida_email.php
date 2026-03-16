<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/email_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "status" => "error",
        "message" => "Metodo no permitido"
    ]);
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "status" => "error",
        "message" => "Correo invalido"
    ]);
    exit;
}

if ($nombre === '') {
    $nombre = 'viajero';
}

list($sent, $errorMessage) = sendEmailMessage(
    $email,
    'Bienvenido a GeoMove',
    buildWelcomeEmailHtml($nombre),
    buildWelcomeEmailText($nombre)
);

if (!$sent) {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo enviar el correo de bienvenida: " . $errorMessage
    ]);
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Correo de bienvenida enviado correctamente"
]);

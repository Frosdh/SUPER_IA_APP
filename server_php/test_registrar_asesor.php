<?php
/**
 * TEST: Registrar asesor con documento
 * Simula un POST desde Flutter
 */
header('Content-Type: application/json; charset=utf-8');

// Simular los datos que envía Flutter
$postData = [
    'nombres' => 'Juan',
    'apellidos' => 'Pérez',
    'email' => 'juan@test.com',
    'telefono' => '0987654321',
    'contrasena' => 'Test123!',
    'supervisor_id' => 1,
    'unidad_bancaria_id' => 'c6e4cdc8-343b-11f1-b209-047c1638ca9a',
    'documento_path' => 'uploads/documentos_asesor/doc_test.jpg',
];

echo "DATOS QUE FLUTTER ENVÍA:\n";
echo json_encode($postData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

// Simular el POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = $postData;

// Incluir el API
require_once 'api_crear_asesor.php';
?>

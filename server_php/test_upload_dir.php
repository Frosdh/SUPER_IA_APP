<?php
/**
 * TEST: Upload Document
 * Verifica que api_subir_documento.php funcione correctamente
 */
header('Content-Type: application/json; charset=utf-8');

// Simular un archivo de prueba
$testDir = __DIR__ . '/uploads/documentos_asesor/';
if (!is_dir($testDir)) {
    mkdir($testDir, 0755, true);
}

// Crear un archivo de prueba
$testFile = $testDir . 'test_documento.pdf';
file_put_contents($testFile, 'PDF de prueba - ' . date('Y-m-d H:i:s'));

echo json_encode([
    'status' => 'success',
    'test' => 'folder_writable',
    'path' => $testFile,
    'exists' => file_exists($testFile),
    'is_file' => is_file($testFile),
    'size' => filesize($testFile),
    'upload_dir_permissions' => substr(sprintf('%o', fileperms($testDir)), -4)
]);

// Limpiar archivo de prueba
if (file_exists($testFile)) {
    unlink($testFile);
}
?>

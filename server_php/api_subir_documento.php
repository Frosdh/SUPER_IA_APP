<?php
/**
 * API: Subir documento/imagen de asesor
 * POST http://localhost/FUBER_APP/server_php/api_subir_documento.php
 * 
 * Recibe:
 *   - file: archivo (PDF, JPG, PNG)
 *   - asesor_id: (opcional) ID del asesor si es actualización
 * 
 * Retorna:
 *   - status: success|error
 *   - filename: nombre del archivo guardado
 *   - filepath: ruta relativa
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Crear directorio de uploads si no existe
$upload_dir = __DIR__ . '/uploads/documentos_asesor/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

try {
    // Validar que se envió un archivo
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("No se recibió archivo o hubo error en la carga");
    }

    $file = $_FILES['file'];
    
    // Validar tipo de archivo
    $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception("Tipo de archivo no permitido. Use: PDF, JPG, PNG");
    }

    // Validar tamaño (máximo 5MB)
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        throw new Exception("Archivo muy grande. Máximo 5MB");
    }

    // Generar nombre único
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'doc_' . bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    $filepath = $upload_dir . $filename;

    // Mover archivo
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception("Error al guardar el archivo");
    }

    echo json_encode([
        'status' => 'success',
        'filename' => $filename,
        'filepath' => 'uploads/documentos_asesor/' . $filename,
        'message' => 'Archivo subido correctamente'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>

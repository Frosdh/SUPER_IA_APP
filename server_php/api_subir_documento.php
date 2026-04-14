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

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Compatibilidad (hosts antiguos)
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

$API_BUILD = '2026-04-14a';

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

// Ping rápido para verificar despliegue en hosting
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, array(
        'status' => 'ok',
        'message' => 'api_subir_documento.php alive',
        'build' => $API_BUILD,
        'php' => PHP_VERSION,
    ));
    exit;
}

function upload_err_msg($code) {
    $code = (int)$code;
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'El archivo supera el tamaño permitido por el servidor.';
        case UPLOAD_ERR_PARTIAL:
            return 'El archivo se subió parcialmente. Inténtalo de nuevo.';
        case UPLOAD_ERR_NO_FILE:
            return 'No se recibió ningún archivo.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Falta la carpeta temporal del servidor.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'No se pudo guardar el archivo en el servidor.';
        case UPLOAD_ERR_EXTENSION:
            return 'Una extensión de PHP bloqueó la carga del archivo.';
        default:
            return 'Hubo un error al subir el archivo.';
    }
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $errType = isset($err['type']) ? (int)$err['type'] : 0;
    if (!in_array($errType, $fatalTypes, true)) return;

    $errMsg = isset($err['message']) ? $err['message'] : '';
    $errFile = isset($err['file']) ? $err['file'] : '';
    $errLine = isset($err['line']) ? $err['line'] : '';
    @error_log('[api_subir_documento][FATAL] ' . $errMsg . ' in ' . $errFile . ':' . $errLine);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'status' => 'error',
        'message' => 'Error interno del servidor',
        'build' => isset($GLOBALS['API_BUILD']) ? $GLOBALS['API_BUILD'] : null,
    ], JSON_UNESCAPED_UNICODE);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Crear directorio de uploads si no existe
// Guardar fuera de server_php para que la ruta devuelta sea accesible:
// /SUPER_IA/uploads/documentos_asesor/...
$upload_dir = __DIR__ . '/../uploads/documentos_asesor/';
if (!is_dir($upload_dir)) {
    if (!@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        throw new Exception('No se pudo crear el directorio de subida en el servidor');
    }
}

try {
    // Validar que se envió un archivo
    if (!isset($_FILES['file'])) {
        // Caso común cuando post_max_size es menor al archivo: PHP deja $_FILES vacío.
        $postMax = (string)ini_get('post_max_size');
        $uploadMax = (string)ini_get('upload_max_filesize');
        throw new Exception('No se recibió archivo. Verifique el tamaño (límite servidor: post_max_size=' . $postMax . ', upload_max_filesize=' . $uploadMax . ').');
    }

    $file = $_FILES['file'];
    $errCode = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($errCode !== UPLOAD_ERR_OK) {
        $postMax = (string)ini_get('post_max_size');
        $uploadMax = (string)ini_get('upload_max_filesize');
        throw new Exception(upload_err_msg($errCode) . ' (post_max_size=' . $postMax . ', upload_max_filesize=' . $uploadMax . ')');
    }

    // Validar tipo de archivo
    $allowed_mimes = [
        'application/pdf',
        'application/x-pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/pjpeg',
        'application/octet-stream', // algunos hosts/devices reportan genérico
    ];
    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $detected_mime = '';
    if (function_exists('mime_content_type')) {
        $detected_mime = (string)@mime_content_type($file['tmp_name']);
    }
    if ($detected_mime === '' && function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected_mime = (string)@finfo_file($fi, $file['tmp_name']);
            @finfo_close($fi);
        }
    }

    $client_mime = isset($file['type']) ? (string)$file['type'] : '';
    $file_type = $detected_mime !== '' ? $detected_mime : $client_mime;

    if (!in_array($ext, $allowed_exts, true) && !in_array($file_type, $allowed_mimes, true)) {
        throw new Exception("Tipo de archivo no permitido. Use: PDF, JPG, PNG");
    }

    // Validar tamaño (máximo 5MB)
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        throw new Exception("Archivo muy grande. Máximo 5MB");
    }

    // Generar nombre único
    $ext = $ext !== '' ? $ext : (string)pathinfo($file['name'], PATHINFO_EXTENSION);
    $uniq = str_replace('.', '', uniqid('doc_', true));
    $filename = $uniq . '.' . strtolower($ext);
    $filepath = $upload_dir . $filename;

    // Mover archivo
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception("Error al guardar el archivo");
    }

    respond_json(200, [
        'status' => 'success',
        'filename' => $filename,
        'filepath' => 'uploads/documentos_asesor/' . $filename,
        'message' => 'Archivo subido correctamente',
    ]);

} catch (Exception $e) {
    @error_log('[api_subir_documento][CATCH] ' . $e);
    respond_json(400, [
        'status' => 'error',
        'message' => $e->getMessage(),
    ]);
}
?>

<?php
require_once 'db_admin.php';

// Verificar sesión del admin o super_admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    http_response_code(403);
    die('Acceso denegado');
}

// Obtener ID de solicitud
$id_solicitud = $_GET['id'] ?? null;

if (!$id_solicitud || !is_numeric($id_solicitud)) {
    http_response_code(400);
    die('ID de solicitud inválido');
}

try {
    // Buscar la solicitud
    $stmt = $pdo->prepare("SELECT archivo_credencial FROM solicitudes_admin WHERE id_solicitud = ?");
    $stmt->execute([$id_solicitud]);
    $solicitud = $stmt->fetch();

    if (!$solicitud || empty($solicitud['archivo_credencial'])) {
        http_response_code(404);
        die('Archivo no encontrado');
    }

    // Construir ruta del archivo
    $archivo = __DIR__ . '/solicitudes_admin/' . basename($solicitud['archivo_credencial']);

    // Validar que el archivo existe y está en la carpeta permitida
    if (!file_exists($archivo) || realpath($archivo) === false) {
        http_response_code(404);
        die('Archivo no encontrado en el servidor');
    }

    // Validar que está en la carpeta correcta (prevenir directory traversal)
    $base_dir = realpath(__DIR__ . '/solicitudes_admin');
    $file_dir = realpath(dirname($archivo));
    
    if ($file_dir !== $base_dir) {
        http_response_code(403);
        die('Acceso denegado');
    }

    // Enviar el archivo
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($archivo) . '"');
    header('Content-Length: ' . filesize($archivo));
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($archivo);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Error al descargar el archivo');
}
?>

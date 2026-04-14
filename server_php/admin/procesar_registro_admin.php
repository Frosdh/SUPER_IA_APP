<?php
require_once 'db_admin.php';

// Verificar que sea una solicitud POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registro_admin.php');
    exit;
}

// Obtener y validar datos
$cooperativa = $_POST['cooperativa'] ?? '';
$nombres = $_POST['nombres'] ?? '';
$apellidos = $_POST['apellidos'] ?? '';
$email = $_POST['email'] ?? '';
$usuario = $_POST['usuario'] ?? '';
$password = $_POST['password'] ?? '';
$region = $_POST['region'] ?? '';
$telefono = $_POST['telefono'] ?? '';

// Validaciones básicas
$errores = [];

if (empty($cooperativa)) {
    $errores[] = "La cooperativa es requerida";
}
if (empty($nombres)) {
    $errores[] = "El nombre es requerido";
}
if (empty($apellidos)) {
    $errores[] = "Los apellidos son requeridos";
}
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = "Email válido requerido";
}
if (empty($usuario) || strlen($usuario) < 3) {
    $errores[] = "Usuario debe tener al menos 3 caracteres";
}
if (empty($password) || strlen($password) < 8) {
    $errores[] = "La contraseña debe tener al menos 8 caracteres";
}
if (empty($region)) {
    $errores[] = "La región es requerida";
}
if (empty($telefono)) {
    $errores[] = "El teléfono es requerido";
}

// Validar archivo PDF
$archivo_credencial = '';
if (!isset($_FILES['credencial']) || $_FILES['credencial']['error'] != UPLOAD_ERR_OK) {
    $errores[] = "Debes enviar la credencial/nombramiento en PDF";
} else {
    $file = $_FILES['credencial'];
    
    // Validar tipo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $tipo = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($tipo !== 'application/pdf' && $file['type'] !== 'application/pdf') {
        $errores[] = "El archivo debe ser un PDF válido";
    }
    
    // Validar tamaño (máx 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        $errores[] = "El archivo no debe superar 5MB";
    }
}

// Si hay errores, redirigir
if (!empty($errores)) {
    header('Location: registro_admin.php?error=' . urlencode(implode(' | ', $errores)));
    exit;
}

try {
    // Verificar si el usuario ya existe
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE usuario = ?");
    $stmt->execute([$usuario]);
    if ($stmt->rowCount() > 0) {
        throw new Exception("El usuario ya existe");
    }

    // Verificar si el email ya existe
    $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        throw new Exception("El email ya está registrado");
    }

    // Crear tabla solicitudes_admin si no existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_admin (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa INT NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            region VARCHAR(100) NOT NULL,
            telefono VARCHAR(20) NOT NULL,
            archivo_credencial VARCHAR(255) NOT NULL,
            estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
            fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion TIMESTAMP NULL,
            observaciones TEXT NULL
        )
    ");

    // Crear carpeta de solicitudes si no existe
    $dir_solicitudes = __DIR__ . '/solicitudes_admin';
    if (!is_dir($dir_solicitudes)) {
        mkdir($dir_solicitudes, 0755, true);
    }

    // Guardar archivo con nombre único
    $nombre_archivo = 'credencial_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $usuario) . '.pdf';
    $ruta_archivo = $dir_solicitudes . '/' . $nombre_archivo;
    
    if (!move_uploaded_file($_FILES['credencial']['tmp_name'], $ruta_archivo)) {
        throw new Exception("Error al guardar el archivo");
    }

    // Insertar solicitud pendiente
    $hash_password = hash('sha256', $password);
    
    $stmt = $pdo->prepare("
        INSERT INTO solicitudes_admin (id_cooperativa, usuario, nombres, apellidos, email, password_hash, region, telefono, archivo_credencial, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')
    ");
    
    $stmt->execute([
        $cooperativa,
        $usuario,
        $nombres,
        $apellidos,
        $email,
        $hash_password,
        $region,
        $telefono,
        $nombre_archivo
    ]);

    // Redirigir con éxito
    header('Location: registro_admin.php?success=1');
    exit;

} catch (Exception $e) {
    // Limpiar archivo si hay error
    if (isset($ruta_archivo) && file_exists($ruta_archivo)) {
        unlink($ruta_archivo);
    }
    header('Location: registro_admin.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

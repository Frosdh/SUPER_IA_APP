<?php
require_once 'db_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registro_supervisor.php');
    exit;
}

// Obtener y validar datos
$cooperativa = $_POST['cooperativa'] ?? '';
$administrador = $_POST['administrador'] ?? '';
$nombres = trim($_POST['nombres'] ?? '');
$apellidos = trim($_POST['apellidos'] ?? '');
$usuario = trim($_POST['usuario'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$password = $_POST['password'] ?? '';

$errores = [];
$archivo_guardado = null;

// Validaciones
if (empty($cooperativa)) $errores[] = 'La cooperativa es requerida';
if (empty($administrador)) $errores[] = 'El administrador es requerido';
if (empty($nombres)) $errores[] = 'Los nombres son requeridos';
if (empty($apellidos)) $errores[] = 'Los apellidos son requeridos';
if (empty($usuario) || strlen($usuario) < 4) $errores[] = 'Usuario debe tener al menos 4 caracteres';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';
if (empty($password) || strlen($password) < 6) $errores[] = 'Contraseña debe tener al menos 6 caracteres';

// Validar archivo
$archivo_upload = $_FILES['credencial'] ?? null;
if ($archivo_upload && $archivo_upload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($archivo_upload['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Error en la subida del archivo';
    } else {
        // Validaciones del archivo
        $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
        $tamaño_maximo = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($archivo_upload['type'], $tipos_permitidos)) {
            $errores[] = 'Tipo de archivo no permitido (PDF, JPG, PNG)';
        } elseif ($archivo_upload['size'] > $tamaño_maximo) {
            $errores[] = 'Archivo muy grande (máximo 5MB)';
        } else {
            // Procesar archivo
            $dir_upload = __DIR__ . '/../../uploads/supervisor_credentials/';
            
            // Crear directorio si no existe
            if (!is_dir($dir_upload)) {
                mkdir($dir_upload, 0755, true);
            }
            
            // Generar nombre único
            $ext = pathinfo($archivo_upload['name'], PATHINFO_EXTENSION);
            $nombre_archivo = 'cred_' . $usuario . '_' . time() . '.' . $ext;
            $ruta_completa = $dir_upload . $nombre_archivo;
            
            // Mover archivo
            if (move_uploaded_file($archivo_upload['tmp_name'], $ruta_completa)) {
                $archivo_guardado = $nombre_archivo;
            } else {
                $errores[] = 'No se pudo guardar el archivo';
            }
        }
    }
} else {
    $errores[] = 'El archivo de credencial es requerido';
}

if (!empty($errores)) {
    $error_msg = implode(', ', $errores);
    header("Location: registro_supervisor.php?error=" . urlencode($error_msg));
    exit;
}

try {
    // Crear tabla solicitudes_supervisor si no existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_supervisor (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa INT NOT NULL,
            id_administrador INT NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            nombres VARCHAR(100) NOT NULL,
            apellidos VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            telefono VARCHAR(20) NOT NULL,
            credencial_archivo VARCHAR(255) NULL,
            estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
            fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion TIMESTAMP NULL,
            observaciones TEXT NULL
        )
    ");
    
    // Verificar si la columna credencial_archivo existe, si no agregarla
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_supervisor LIKE 'credencial_archivo'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE solicitudes_supervisor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER telefono");
    }

    // Hash de contraseña
    $password_hash = hash('sha256', $password);

    // Insertar solicitud
    $stmt = $pdo->prepare("
        INSERT INTO solicitudes_supervisor 
        (id_cooperativa, id_administrador, usuario, nombres, apellidos, email, password_hash, telefono, credencial_archivo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $cooperativa,
        $administrador,
        $usuario,
        $nombres,
        $apellidos,
        $email,
        $password_hash,
        $telefono,
        $archivo_guardado
    ]);

    header('Location: registro_supervisor.php?success=1');
    exit;

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        $error = 'El usuario o email ya existe';
    } else {
        $error = 'Error al procesar la solicitud: ' . $e->getMessage();
    }
    header("Location: registro_supervisor.php?error=" . urlencode($error));
    exit;
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
    header("Location: registro_supervisor.php?error=" . urlencode($error));
    exit;
}

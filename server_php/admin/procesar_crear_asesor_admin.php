<?php
require_once 'db_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: crear_asesor_admin.php');
    exit;
}

// Verificar si es admin o super admin
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

// Obtener y validar datos
$id_supervisor = $_POST['id_supervisor'] ?? '';
$nombre = trim(($_POST['nombres'] ?? '') . ' ' . ($_POST['apellidos'] ?? ''));
$email = trim($_POST['email'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$password = $_POST['password'] ?? '';
$unidad_bancaria_id = $_POST['unidad_bancaria_id'] ?? '';

$errores = [];
$archivo_guardado = null;

// Validaciones
if (empty($unidad_bancaria_id)) $errores[] = 'La cooperativa/banco es requerida';
if (empty($id_supervisor)) $errores[] = 'El supervisor es requerido';
if (empty($nombre) || strlen(trim($nombre)) < 3) $errores[] = 'El nombre completo es requerido';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';
if (empty($telefono)) $errores[] = 'El teléfono es requerido';
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
            $dir_upload = __DIR__ . '/../../uploads/asesor_credentials/';
            
            // Crear directorio si no existe
            if (!is_dir($dir_upload)) {
                mkdir($dir_upload, 0755, true);
            }
            
            // Generar nombre único
            $ext = pathinfo($archivo_upload['name'], PATHINFO_EXTENSION);
            $nombre_archivo = 'asesor_' . $usuario . '_' . time() . '.' . $ext;
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
    header("Location: crear_asesor_admin.php?error=" . urlencode($error_msg));
    exit;
}

try {
    // Hashear contraseña con password_hash
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Insertar nuevo usuario en la tabla usuario (SUPER_IA LOGAN)
    $stmt = $pdo->prepare("
        INSERT INTO usuario 
        (nombre, email, password_hash, rol, agencia_id, estado_aprobacion, activo, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $nombre,                    // nombre
        $email,                     // email
        $password_hash,            // password_hash
        'asesor',                  // rol = 'asesor'
        $unidad_bancaria_id,       // agencia_id (cooperativa)
        'pendiente',               // estado_aprobacion
        1                          // activo = 1
    ]);

    $usuario_id = $pdo->lastInsertId();

    // Crear relación en tabla asesor
    $stmt2 = $pdo->prepare("
        INSERT INTO asesor 
        (usuario_id, supervisor_id, meta_tareas_diarias, created_at)
        VALUES (?, ?, ?, NOW())
    ");

    $stmt2->execute([
        $usuario_id,           // usuario_id
        $id_supervisor,        // supervisor_id
        8                      // meta_tareas_diarias (default)
    ]);

    // Si hay archivo de credencial, guardar en tabla solicitud_registro
    if ($archivo_guardado) {
        $stmt3 = $pdo->prepare("
            INSERT INTO solicitud_registro 
            (usuario_id, rol_solicitado, documento_url, documento_nombre_original, documento_tipo, estado, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt3->execute([
            $usuario_id,
            'asesor',
            $archivo_guardado,
            $_FILES['credencial']['name'],
            'otro',
            'pendiente'
        ]);
    }

    header('Location: crear_asesor_admin.php?success=1');
    exit;

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        $error = 'El email ya existe en el sistema';
    } else {
        $error = 'Error al procesar la solicitud: ' . $e->getMessage();
    }
    header("Location: crear_asesor_admin.php?error=" . urlencode($error));
    exit;
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
    header("Location: crear_asesor_admin.php?error=" . urlencode($error));
    exit;
}

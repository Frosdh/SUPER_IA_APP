<?php
require_once 'db_admin.php';
require_once __DIR__ . '/funciones_validacion.php';

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

$rNombres = validarNombre($nombres, 'El nombre');
if (!$rNombres['ok']) $errores[] = $rNombres['msg'];

$rApellidos = validarNombre($apellidos, 'Los apellidos');
if (!$rApellidos['ok']) $errores[] = $rApellidos['msg'];

$rEmail = validarEmail($email);
if (!$rEmail['ok']) $errores[] = $rEmail['msg'];

if (empty($usuario) || strlen($usuario) < 3) {
    $errores[] = "Usuario debe tener al menos 3 caracteres";
}
if (empty($password) || strlen($password) < 8) {
    $errores[] = "La contraseña debe tener al menos 8 caracteres";
}
if (empty($region)) {
    $errores[] = "La región es requerida";
}

$rTelefono = validarTelefono($telefono);
if (!$rTelefono['ok']) $errores[] = $rTelefono['msg'];

// Validar archivo PDF
$archivo_credencial = '';
if (!isset($_FILES['credencial']) || $_FILES['credencial']['error'] != UPLOAD_ERR_OK) {
    $errores[] = "Debes enviar la credencial/nombramiento en PDF";
} else {
    $file = $_FILES['credencial'];

    // Validar tipo. finfo_open() puede no estar disponible en algunos
    // hostings (extensión fileinfo deshabilitada) y antes eso provocaba un
    // error fatal (500) en vez de un mensaje de validación normal — ahora
    // se usa solo si existe, con el tipo MIME reportado por el navegador y
    // la extensión del archivo como respaldo.
    $tipo = null;
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $tipo = @finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }
    $tipoDeclarado = $file['type'] ?? '';
    $extensionArchivo = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

    if ($tipo !== 'application/pdf' && $tipoDeclarado !== 'application/pdf' && $extensionArchivo !== 'pdf') {
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
    // Nota: antes esto consultaba una tabla `usuarios` (plural) que no
    // existe en este esquema — la tabla real de usuarios activos se llama
    // `usuario` (singular) y no tiene columna de nombre de usuario (el
    // "usuario" de login solo se guarda en las tablas de solicitudes
    // pendientes, como `solicitudes_admin`). Esa consulta rota terminaba
    // provocando el error 500 al enviar el formulario.

    // Crear tabla solicitudes_admin si no existe (debe ir ANTES de
    // consultarla para verificar duplicados).
    // id_cooperativa es VARCHAR(64) porque los IDs reales de cooperativa
    // (UUID de unidad_bancaria, o "seps_123" del catastro SEPS) no son
    // enteros — antes era INT y solo "funcionaba" con la lista de 4
    // cooperativas de ejemplo que ya no se usa.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_admin (
            id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa VARCHAR(64) NOT NULL,
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

    // Migración defensiva: si la tabla ya existía con id_cooperativa INT
    // (de una versión anterior), se amplía a VARCHAR sin perder datos.
    try {
        $colCoop = $pdo->query("SHOW COLUMNS FROM solicitudes_admin LIKE 'id_cooperativa'")->fetch();
        if ($colCoop && stripos($colCoop['Type'], 'int') !== false) {
            $pdo->exec("ALTER TABLE solicitudes_admin MODIFY COLUMN id_cooperativa VARCHAR(64) NOT NULL");
        }
    } catch (\Throwable $e) {
        // no crítico
    }

    // Verificar si el nombre de usuario ya existe en solicitudes de admin
    // pendientes/aprobadas
    $stmt = $pdo->prepare("SELECT 1 FROM solicitudes_admin WHERE usuario = ? AND estado != 'rechazada' LIMIT 1");
    $stmt->execute([$usuario]);
    if ($stmt->fetchColumn()) {
        throw new Exception("El usuario ya existe");
    }

    // Verificar si el email ya existe (cuenta activa o solicitud pendiente)
    $stmt = $pdo->prepare("SELECT 1 FROM usuario WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        throw new Exception("El email ya está registrado");
    }
    $stmt = $pdo->prepare("SELECT 1 FROM solicitudes_admin WHERE email = ? AND estado != 'rechazada' LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        throw new Exception("El email ya está registrado");
    }

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
    // password_hash() (bcrypt) en vez de hash('sha256', ...): el login usa
    // password_verify(), que solo reconoce hashes generados por
    // password_hash(). Con sha256 el administrador aprobado nunca podría
    // iniciar sesión (la contraseña "correcta" siempre fallaría).
    $hash_password = password_hash($password, PASSWORD_BCRYPT);
    
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

} catch (\Throwable $e) {
    // \Throwable (no solo Exception) para que cualquier error inesperado
    // — incluyendo errores de PHP como TypeError o consultas a tablas que
    // no existen — termine en un mensaje de error normal en vez de una
    // página en blanco con 500 Internal Server Error.
    // Limpiar archivo si hay error
    if (isset($ruta_archivo) && file_exists($ruta_archivo)) {
        unlink($ruta_archivo);
    }
    header('Location: registro_admin.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

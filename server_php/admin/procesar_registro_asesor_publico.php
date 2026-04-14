<?php
require_once 'db_admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registro_asesor_publico.php');
    exit;
}

// Obtener y validar datos
$id_cooperativa = (int)($_POST['id_cooperativa'] ?? 0);
$id_supervisor = (int)($_POST['id_supervisor'] ?? 0);
$nombres = trim($_POST['nombres'] ?? '');
$apellidos = trim($_POST['apellidos'] ?? '');
$email = trim($_POST['email'] ?? '');
$telefono = trim($_POST['telefono'] ?? '');
$usuario = trim($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';
$banco = '';
// Ya no se solicitan estos campos en UI; se setean automáticamente
$numero_cuenta = '';
$tipo_cuenta = 'Asesor';

$errores = [];
$archivo_guardado = null;

if ($id_cooperativa <= 0) $errores[] = 'Debes seleccionar una cooperativa';
if ($id_supervisor <= 0) $errores[] = 'Debes seleccionar un supervisor';
if (empty($nombres)) $errores[] = 'Los nombres son requeridos';
if (empty($apellidos)) $errores[] = 'Los apellidos son requeridos';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';
if (empty($telefono)) $errores[] = 'El teléfono es requerido';
if (empty($usuario) || strlen($usuario) < 4) $errores[] = 'Usuario debe tener al menos 4 caracteres';
if (empty($password) || strlen($password) < 6) $errores[] = 'Contraseña debe tener al menos 6 caracteres';

// Verificar que el supervisor exista
try {
    $stmt = $pdo->prepare("SELECT u.id_usuario
                           FROM usuarios u
                           JOIN roles r ON u.id_rol_fk = r.id_rol
                           WHERE u.id_usuario = ? AND r.nombre = 'Supervisor' LIMIT 1");
    $stmt->execute([$id_supervisor]);
    if (!$stmt->fetchColumn()) {
        $errores[] = 'Supervisor no válido';
    }
} catch (Exception $e) {
    $errores[] = 'No se pudo validar el supervisor';
}

// Validar archivo
$archivo_upload = $_FILES['credencial'] ?? null;
if ($archivo_upload && $archivo_upload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($archivo_upload['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Error en la subida del archivo';
    } else {
        $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
        $tamaño_maximo = 5 * 1024 * 1024; // 5MB

        if (!in_array($archivo_upload['type'], $tipos_permitidos)) {
            $errores[] = 'Tipo de archivo no permitido (PDF, JPG, PNG)';
        } elseif ($archivo_upload['size'] > $tamaño_maximo) {
            $errores[] = 'Archivo muy grande (máximo 5MB)';
        } else {
            $dir_upload = __DIR__ . '/../../uploads/asesor_credentials/';
            if (!is_dir($dir_upload)) {
                mkdir($dir_upload, 0755, true);
            }
            $ext = pathinfo($archivo_upload['name'], PATHINFO_EXTENSION);
            $nombre_archivo = 'asesor_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $usuario) . '_' . time() . '.' . $ext;
            $ruta_completa = $dir_upload . $nombre_archivo;

            if (move_uploaded_file($archivo_upload['tmp_name'], $ruta_completa)) {
                $archivo_guardado = $nombre_archivo;
            } else {
                $errores[] = 'No se pudo guardar el archivo';
            }
        }
    }
}
// Documento es opcional: si no se adjuntó, $archivo_guardado queda en null.

if (!empty($errores)) {
    header('Location: registro_asesor_publico.php?error=' . urlencode(implode(', ', $errores)));
    exit;
}

try {
    // Crear tabla solicitudes_asesor si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS solicitudes_asesor (
        id_solicitud INT AUTO_INCREMENT PRIMARY KEY,
        id_cooperativa INT NOT NULL,
        id_supervisor INT NOT NULL,
        usuario VARCHAR(50) NOT NULL UNIQUE,
        nombres VARCHAR(100) NOT NULL,
        apellidos VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        telefono VARCHAR(20) NOT NULL,
        banco VARCHAR(100) NOT NULL,
        numero_cuenta VARCHAR(50) NOT NULL,
        tipo_cuenta VARCHAR(50) NOT NULL,
        credencial_archivo VARCHAR(255) NULL,
        estado ENUM('pendiente', 'aprobada', 'rechazada') DEFAULT 'pendiente',
        fecha_solicitud TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_aprobacion TIMESTAMP NULL,
        observaciones TEXT NULL
    )");

    // Asegurar columna credencial_archivo
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE 'credencial_archivo'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE solicitudes_asesor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER tipo_cuenta");
    }

    // Asegurar columna id_cooperativa
    $stmt = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE 'id_cooperativa'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE solicitudes_asesor ADD COLUMN id_cooperativa INT NOT NULL AFTER id_solicitud");
    }

    $password_hash = hash('sha256', $password);

    $stmt = $pdo->prepare("INSERT INTO solicitudes_asesor
        (id_cooperativa, id_supervisor, usuario, nombres, apellidos, email, password_hash, telefono, banco, numero_cuenta, tipo_cuenta, credencial_archivo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $id_cooperativa,
        $id_supervisor,
        $usuario,
        $nombres,
        $apellidos,
        $email,
        $password_hash,
        $telefono,
        $banco,
        $numero_cuenta,
        $tipo_cuenta,
        $archivo_guardado
    ]);

    header('Location: registro_asesor_publico.php?success=1');
    exit;

} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        $error = 'El usuario o email ya existe';
    } else {
        $error = 'Error al procesar la solicitud';
    }
    header('Location: registro_asesor_publico.php?error=' . urlencode($error));
    exit;
} catch (Exception $e) {
    header('Location: registro_asesor_publico.php?error=' . urlencode('Error: ' . $e->getMessage()));
    exit;
}

<?php
// ============================================================
// admin/procesar_registro_asesor.php — Procesador ÚNICO de registro de asesor.
//
// Funciona en dos modos detectados automáticamente:
//
//  • MODO SUPERVISOR (interno):
//      $_SESSION['supervisor_logged_in'] === true
//      El supervisor ya está autenticado; banco/cuenta y credencial son obligatorios.
//      El id_supervisor se toma de la sesión.
//
//  • MODO PÚBLICO (auto-registro):
//      Sin sesión de supervisor.
//      El asesor se registra por su cuenta; banco/cuenta y credencial son opcionales.
//      id_cooperativa e id_supervisor vienen del formulario (POST).
//
// En ambos casos se valida que las contraseñas coincidan y se guarda
// en solicitudes_asesor con estado = 'pendiente' para que el supervisor apruebe.
// ============================================================
require_once 'db_admin.php';

// ── Detectar modo ──────────────────────────────────────────
$modo_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;

// ── Formulario de retorno según modo ─────────────────────
$form_origen = 'registro_asesor.php'; // único formulario para ambos modos

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: $form_origen");
    exit;
}

// ── Recoger campos comunes ─────────────────────────────────
$nombres          = trim($_POST['nombres']           ?? '');
$apellidos        = trim($_POST['apellidos']         ?? '');
$email            = trim($_POST['email']             ?? '');
$telefono         = trim($_POST['telefono']          ?? '');
$usuario          = trim($_POST['usuario']           ?? '');
$password         = $_POST['password']               ?? '';
$password_confirm = $_POST['password_confirm']       ?? '';

// ── Campos según modo ─────────────────────────────────────
if ($modo_supervisor) {
    // El id_supervisor viene de la sesión
    $supervisor_id  = $_SESSION['supervisor_id'] ?? null;
    $id_cooperativa = null; // se resolverá desde la BD si se necesita
    $banco          = trim($_POST['banco']         ?? '');
    $numero_cuenta  = trim($_POST['numero_cuenta'] ?? '');
    $tipo_cuenta    = trim($_POST['tipo_cuenta']   ?? '');
} else {
    // El asesor selecciona cooperativa y supervisor en el formulario
    $supervisor_id  = trim($_POST['id_supervisor']  ?? '');
    $id_cooperativa = (int)($_POST['id_cooperativa'] ?? 0);
    $banco          = '';
    $numero_cuenta  = '';
    $tipo_cuenta    = 'Asesor';
}

$errores = [];
$archivo_guardado = null;

// ── Validaciones comunes ───────────────────────────────────
if (empty($nombres))                                           $errores[] = 'Los nombres son requeridos';
if (empty($apellidos))                                         $errores[] = 'Los apellidos son requeridos';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';
if (empty($telefono))                                          $errores[] = 'El teléfono es requerido';
if (empty($usuario) || strlen($usuario) < 4)                  $errores[] = 'Usuario debe tener al menos 4 caracteres';
if (empty($password) || strlen($password) < 6)                $errores[] = 'Contraseña debe tener al menos 6 caracteres';
if ($password !== $password_confirm)                           $errores[] = 'Las contraseñas no coinciden';

// ── Validaciones específicas por modo ─────────────────────
if ($modo_supervisor) {
    if (empty($banco))         $errores[] = 'El banco es requerido';
    if (empty($numero_cuenta)) $errores[] = 'El número de cuenta es requerido';
    if (empty($tipo_cuenta))   $errores[] = 'El tipo de cuenta es requerido';
    if (empty($supervisor_id)) $errores[] = 'No se encontró la sesión de supervisor';
} else {
    if ($id_cooperativa <= 0)  $errores[] = 'Debes seleccionar una cooperativa';
    if (empty($supervisor_id)) $errores[] = 'Debes seleccionar un supervisor';

    // Verificar que el supervisor exista en la tabla usuario
    if (empty($errores) || !in_array('Debes seleccionar un supervisor', $errores)) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM usuario WHERE id = ? AND rol = 'supervisor' AND activo = 1 LIMIT 1"
            );
            $stmt->execute([$supervisor_id]);
            if (!$stmt->fetch()) {
                $errores[] = 'El supervisor seleccionado no es válido';
            }
        } catch (\Throwable $e) {
            // Si la consulta falla (tabla diferente), no bloqueamos el registro
        }
    }
}

// ── Procesar archivo ──────────────────────────────────────
$archivo_upload = $_FILES['credencial'] ?? null;
$credencial_presente = $archivo_upload && $archivo_upload['error'] !== UPLOAD_ERR_NO_FILE;

if ($credencial_presente) {
    if ($archivo_upload['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Error en la subida del archivo';
    } else {
        $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
        $tamaño_maximo    = 5 * 1024 * 1024; // 5 MB

        if (!in_array($archivo_upload['type'], $tipos_permitidos)) {
            $errores[] = 'Tipo de archivo no permitido (PDF, JPG, PNG)';
        } elseif ($archivo_upload['size'] > $tamaño_maximo) {
            $errores[] = 'Archivo muy grande (máximo 5 MB)';
        } else {
            $dir_upload = __DIR__ . '/../../uploads/asesor_credentials/';
            if (!is_dir($dir_upload)) {
                mkdir($dir_upload, 0755, true);
            }
            $ext            = pathinfo($archivo_upload['name'], PATHINFO_EXTENSION);
            $nombre_archivo = 'asesor_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $usuario) . '_' . time() . '.' . $ext;
            $ruta_completa  = $dir_upload . $nombre_archivo;

            if (move_uploaded_file($archivo_upload['tmp_name'], $ruta_completa)) {
                $archivo_guardado = $nombre_archivo;
            } else {
                $errores[] = 'No se pudo guardar el archivo';
            }
        }
    }
} elseif ($modo_supervisor) {
    // En modo supervisor la credencial es obligatoria
    $errores[] = 'El archivo de credencial es requerido';
}
// En modo público la credencial es opcional: $archivo_guardado queda null.

// ── Retornar errores ──────────────────────────────────────
if (!empty($errores)) {
    header("Location: $form_origen?error=" . urlencode(implode(', ', $errores)));
    exit;
}

// ── Insertar solicitud ────────────────────────────────────
try {
    // Asegurar estructura mínima de la tabla
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_asesor (
            id_solicitud       INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa     INT NULL,
            id_supervisor      VARCHAR(64) NOT NULL,
            usuario            VARCHAR(50)  NOT NULL UNIQUE,
            nombres            VARCHAR(100) NOT NULL,
            apellidos          VARCHAR(100) NOT NULL,
            email              VARCHAR(100) NOT NULL UNIQUE,
            password_hash      VARCHAR(255) NOT NULL,
            telefono           VARCHAR(20)  NOT NULL,
            banco              VARCHAR(100) NOT NULL DEFAULT '',
            numero_cuenta      VARCHAR(50)  NOT NULL DEFAULT '',
            tipo_cuenta        VARCHAR(50)  NOT NULL DEFAULT 'Asesor',
            credencial_archivo VARCHAR(255) NULL,
            estado             ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
            fecha_solicitud    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion   TIMESTAMP NULL,
            observaciones      TEXT NULL,
            INDEX idx_email  (email),
            INDEX idx_usuario(usuario)
        )
    ");

    // Columnas que pueden faltar en tablas ya existentes
    $columnas_extra = [
        'id_cooperativa'     => "ALTER TABLE solicitudes_asesor ADD COLUMN id_cooperativa INT NULL AFTER id_solicitud",
        'credencial_archivo' => "ALTER TABLE solicitudes_asesor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER tipo_cuenta",
    ];
    foreach ($columnas_extra as $col => $sql) {
        $chk = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE '$col'");
        if (!$chk->fetch()) {
            $pdo->exec($sql);
        }
    }

    // Hash — se usa password_hash para que sea compatible con el login (password_verify)
    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("
        INSERT INTO solicitudes_asesor
            (id_cooperativa, id_supervisor, usuario, nombres, apellidos,
             email, password_hash, telefono, banco, numero_cuenta, tipo_cuenta, credencial_archivo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $id_cooperativa,
        $supervisor_id,
        $usuario,
        $nombres,
        $apellidos,
        $email,
        $password_hash,
        $telefono,
        $banco,
        $numero_cuenta,
        $tipo_cuenta,
        $archivo_guardado,
    ]);

    header("Location: $form_origen?success=1");
    exit;

} catch (\PDOException $e) {
    $error = str_contains($e->getMessage(), 'Duplicate')
        ? 'El usuario o email ya está registrado'
        : 'Error al guardar la solicitud: ' . $e->getMessage();

    header("Location: $form_origen?error=" . urlencode($error));
    exit;
} catch (\Throwable $e) {
    header("Location: $form_origen?error=" . urlencode('Error inesperado: ' . $e->getMessage()));
    exit;
}

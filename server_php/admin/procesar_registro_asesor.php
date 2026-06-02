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
require_once __DIR__ . '/funciones_validacion.php';

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
$cedula           = trim($_POST['cedula']            ?? '');
$email            = trim($_POST['email']             ?? '');
$telefono         = trim($_POST['telefono']          ?? '');
$usuario          = trim($_POST['usuario']           ?? '');
$password         = $_POST['password']               ?? '';
$password_confirm = $_POST['password_confirm']       ?? '';

// ── Campos según modo ─────────────────────────────────────
$banco         = '';
$numero_cuenta = '';
$tipo_cuenta   = 'Asesor';
$id_supervisor_int = null;  // ID entero real para el INSERT

if ($modo_supervisor) {
    $usuario_id_sesion = $_SESSION['supervisor_id'] ?? null;
    $id_cooperativa    = null;
} else {
    $usuario_id_sesion = null;
    $id_cooperativa    = (int)($_POST['id_cooperativa'] ?? 0);
    $supervisor_id     = trim($_POST['id_supervisor'] ?? '');
}

$errores = [];
$archivo_guardado = null;

// ── Validaciones comunes (usando funciones_validacion.php) ─
$errores = array_merge($errores, validarFormularioAsesor($_POST, true));

// ── Validaciones específicas por modo ─────────────────────
if ($modo_supervisor) {
    if (empty($usuario_id_sesion)) {
        $errores[] = 'No se encontró la sesión de supervisor';
    } else {
        // Resolver el ID entero del supervisor desde la tabla supervisor
        try {
            $stSup = $pdo->prepare('SELECT id, cooperativa_id FROM supervisor WHERE usuario_id = ? LIMIT 1');
            $stSup->execute([$usuario_id_sesion]);
            $rowSup = $stSup->fetch(PDO::FETCH_ASSOC);
            if ($rowSup) {
                $id_supervisor_int = (int)$rowSup['id'];
                $id_cooperativa    = $id_cooperativa ?? ($rowSup['cooperativa_id'] ?? null);
            } else {
                // Fallback: intentar buscar directo en usuario
                $stSup2 = $pdo->prepare('SELECT id FROM usuario WHERE id = ? LIMIT 1');
                $stSup2->execute([$usuario_id_sesion]);
                $rowSup2 = $stSup2->fetch(PDO::FETCH_ASSOC);
                $id_supervisor_int = $rowSup2 ? (int)$rowSup2['id'] : null;
            }
        } catch (\Throwable $e) {
            // Si el usuario_id ya es numérico, usarlo directo
            if (is_numeric($usuario_id_sesion)) {
                $id_supervisor_int = (int)$usuario_id_sesion;
            }
        }
        if (!$id_supervisor_int) {
            $errores[] = 'No se pudo identificar al supervisor en el sistema';
        }
    }
} else {
    if ($id_cooperativa <= 0)  $errores[] = 'Debes seleccionar una cooperativa';
    if (empty($supervisor_id)) $errores[] = 'Debes seleccionar un supervisor';
    else $id_supervisor_int = is_numeric($supervisor_id) ? (int)$supervisor_id : null;

    // Verificar que el supervisor exista
    if ($id_supervisor_int) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM usuario WHERE id = ? AND rol = 'supervisor' AND activo = 1 LIMIT 1");
            $stmt->execute([$id_supervisor_int]);
            if (!$stmt->fetch()) $errores[] = 'El supervisor seleccionado no es válido';
        } catch (\Throwable $e) { /* no bloquear */ }
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
}

// ── Credencial obligatoria ────────────────────────────────
if (!$credencial_presente) {
    $errores[] = 'Debes adjuntar la credencial o nombramiento (PDF, JPG o PNG)';
}

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
        'cedula'             => "ALTER TABLE solicitudes_asesor ADD COLUMN cedula VARCHAR(13) NULL AFTER apellidos",
        'credencial_archivo' => "ALTER TABLE solicitudes_asesor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER tipo_cuenta",
    ];

    // Asegurar que id_supervisor sea INT (puede ser VARCHAR en instalaciones antiguas)
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE 'id_supervisor'")->fetch(PDO::FETCH_ASSOC);
        if ($colInfo && stripos($colInfo['Type'], 'varchar') !== false) {
            $pdo->exec("ALTER TABLE solicitudes_asesor MODIFY COLUMN id_supervisor INT NOT NULL");
        }
    } catch (\Throwable $e) { /* ignorar */ }
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
            (id_cooperativa, id_supervisor, usuario, nombres, apellidos, cedula,
             email, password_hash, telefono, banco, numero_cuenta, tipo_cuenta, credencial_archivo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $id_cooperativa,
        $id_supervisor_int,
        $usuario,
        $nombres,
        $apellidos,
        $cedula,
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

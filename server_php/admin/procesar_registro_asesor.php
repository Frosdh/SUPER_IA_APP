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
$banco             = '';
$numero_cuenta     = '';
$tipo_cuenta       = 'Asesor';
$id_supervisor_val = null;   // valor final para el INSERT (string o int según BD)

if ($modo_supervisor) {
    // supervisor_id en sesión = usuario.id
    $id_supervisor_val = $_SESSION['supervisor_id'] ?? null;
    $id_cooperativa    = null;

    // Recorrer la cadena jerárquica para obtener la unidad_bancaria (cooperativa) del supervisor:
    // supervisor.usuario_id → supervisor.jefe_agencia_id → jefe_agencia.agencia_id → agencia.unidad_bancaria_id
    if ($id_supervisor_val) {
        try {
            $stCoop = $pdo->prepare("
                SELECT ag.unidad_bancaria_id
                FROM supervisor sup
                JOIN jefe_agencia ja ON ja.id = sup.jefe_agencia_id
                JOIN agencia ag      ON ag.id = ja.agencia_id
                WHERE sup.usuario_id = ?
                LIMIT 1
            ");
            $stCoop->execute([$id_supervisor_val]);
            $rowCoop = $stCoop->fetch(PDO::FETCH_ASSOC);
            $id_cooperativa = $rowCoop['unidad_bancaria_id'] ?? null;
        } catch (\Throwable $e) { /* opcional */ }
    }
} else {
    $id_cooperativa    = trim($_POST['id_cooperativa'] ?? '');
    $id_supervisor_val = trim($_POST['id_supervisor'] ?? '');
}

$errores = [];
$archivo_guardado = null;

// ── Validaciones comunes (usando funciones_validacion.php) ─
$errores = array_merge($errores, validarFormularioAsesor($_POST, true));

// ── Validaciones específicas por modo ─────────────────────
if ($modo_supervisor) {
    if (empty($id_supervisor_val)) {
        $errores[] = 'No se encontró la sesión de supervisor';
    }
} else {
    if (empty($id_cooperativa))    $errores[] = 'Debes seleccionar una cooperativa';
    if (empty($id_supervisor_val)) $errores[] = 'Debes seleccionar un supervisor';
}

// ── Procesar archivo ──────────────────────────────────────
$archivo_upload = $_FILES['credencial'] ?? null;
$credencial_presente = $archivo_upload && $archivo_upload['error'] !== UPLOAD_ERR_NO_FILE;

if ($credencial_presente) {
    if ($archivo_upload['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Error en la subida del archivo';
    } else {
        $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'application/octet-stream'];
        $ext_permitidas   = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext_archivo      = strtolower(pathinfo($archivo_upload['name'], PATHINFO_EXTENSION));
        $tamaño_maximo    = 5 * 1024 * 1024; // 5 MB

        $tipo_ok = in_array($archivo_upload['type'], $tipos_permitidos) || in_array($ext_archivo, $ext_permitidas);
        if (!$tipo_ok) {
            $errores[] = 'Tipo de archivo no permitido. Usa PDF, JPG o PNG.';
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

// ── Validar unicidad: email, usuario y cédula ─────────────
if (empty($errores)) {
    // Email: revisar en usuario activo y en solicitudes pendientes/aprobadas
    $st = $pdo->prepare("SELECT 1 FROM usuario WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetchColumn()) {
        $errores[] = 'El correo electrónico ya está registrado en el sistema.';
    } else {
        $st2 = $pdo->prepare("SELECT 1 FROM solicitudes_asesor WHERE email = ? AND estado != 'rechazada' LIMIT 1");
        $st2->execute([$email]);
        if ($st2->fetchColumn()) {
            $errores[] = 'Ya existe una solicitud activa con ese correo electrónico.';
        }
    }

    // Usuario: revisar en solicitudes activas
    $st3 = $pdo->prepare("SELECT 1 FROM solicitudes_asesor WHERE usuario = ? AND estado != 'rechazada' LIMIT 1");
    $st3->execute([$usuario]);
    if ($st3->fetchColumn()) {
        $errores[] = 'El nombre de usuario ya está en uso.';
    }

    // Cédula: revisar en solicitudes activas (si se proporcionó)
    if (!empty($cedula)) {
        $st4 = $pdo->prepare("SELECT 1 FROM solicitudes_asesor WHERE cedula = ? AND estado != 'rechazada' LIMIT 1");
        $st4->execute([$cedula]);
        if ($st4->fetchColumn()) {
            $errores[] = 'La cédula ya está registrada en una solicitud activa.';
        }
    }
}

// ── Retornar errores (guardar datos en sesión para repoblar el formulario) ──
if (!empty($errores)) {
    $_SESSION['form_prev'] = [
        'id_cooperativa' => $_POST['id_cooperativa'] ?? '',
        'id_supervisor'  => $_POST['id_supervisor']  ?? '',
        'nombres'        => $nombres,
        'apellidos'      => $apellidos,
        'cedula'         => $cedula,
        'email'          => $email,
        'telefono'       => $telefono,
        'usuario'        => $usuario,
    ];
    header("Location: $form_origen?error=" . urlencode(implode(', ', $errores)));
    exit;
}

// ── Insertar solicitud ────────────────────────────────────
try {
    // Asegurar estructura mínima de la tabla
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS solicitudes_asesor (
            id_solicitud       INT AUTO_INCREMENT PRIMARY KEY,
            id_cooperativa     VARCHAR(36) NULL,
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
        'id_cooperativa'     => "ALTER TABLE solicitudes_asesor ADD COLUMN id_cooperativa VARCHAR(36) NULL AFTER id_solicitud",
        'cedula'             => "ALTER TABLE solicitudes_asesor ADD COLUMN cedula VARCHAR(13) NULL AFTER apellidos",
        'credencial_archivo' => "ALTER TABLE solicitudes_asesor ADD COLUMN credencial_archivo VARCHAR(255) NULL AFTER tipo_cuenta",
    ];

    // Asegurar que id_supervisor sea VARCHAR(64) para soportar UUIDs
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE 'id_supervisor'")->fetch(PDO::FETCH_ASSOC);
        if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
            $pdo->exec("ALTER TABLE solicitudes_asesor MODIFY COLUMN id_supervisor VARCHAR(64) NOT NULL DEFAULT ''");
        }
    } catch (\Throwable $e) { /* ignorar */ }

    // Asegurar que id_cooperativa sea VARCHAR(36) para soportar UUIDs (corregir si era INT)
    try {
        $colInfo = $pdo->query("SHOW COLUMNS FROM solicitudes_asesor LIKE 'id_cooperativa'")->fetch(PDO::FETCH_ASSOC);
        if ($colInfo && stripos($colInfo['Type'], 'int') !== false) {
            $pdo->exec("ALTER TABLE solicitudes_asesor MODIFY COLUMN id_cooperativa VARCHAR(36) NULL");
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
        $id_supervisor_val,
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

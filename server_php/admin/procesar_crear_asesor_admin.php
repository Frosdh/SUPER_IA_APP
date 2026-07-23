<?php
// ============================================================
// admin/procesar_crear_asesor_admin.php
// ------------------------------------------------------------
// Procesa el formulario unificado "Crear Cuenta" (crear_asesor_admin.php)
// para los 3 roles que un Super Admin/Admin puede dar de alta
// directamente (activos de inmediato, sin paso de aprobación,
// porque es el propio administrador quien la está creando):
//   - gerente_general -> usuario + gerente_general
//   - supervisor      -> usuario + supervisor (requiere Gerente Responsable
//                         para resolver su jefe_agencia_id)
//   - asesor          -> usuario + asesor (requiere Supervisor asignado)
//
// Corrige varios bugs de la versión anterior (solo asesor):
//   - usaba $pdo->lastInsertId() para el id de `usuario`, pero esa tabla
//     usa id CHAR(36) generado por DEFAULT uuid() en MySQL, no
//     AUTO_INCREMENT — lastInsertId() nunca devuelve ese UUID, así que el
//     INSERT en `asesor`/`solicitud_registro` quedaba con un usuario_id
//     vacío/erróneo.
//   - guardaba unidad_bancaria_id en `usuario.agencia_id`, columna que en
//     realidad referencia `agencia.id`, no `unidad_bancaria.id`.
//   - usaba una variable $usuario que nunca se definía en este archivo
//     (quedaba vacía) al construir el nombre del archivo subido.
// ============================================================
require_once 'db_admin.php';
require_once __DIR__ . '/funciones_validacion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: crear_asesor_admin.php');
    exit;
}

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    header('Location: login.php?role=admin');
    exit;
}

$admin_id = $is_super_admin ? $_SESSION['super_admin_id'] : $_SESSION['admin_id'];

function cca_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Encuentra (o crea) una agencia "por defecto" para una cooperativa que
// todavía no tiene ninguna agencia registrada.
function cca_resolverAgenciaPrincipal(PDO $pdo, string $unidadBancariaId): string {
    $st = $pdo->prepare("SELECT id FROM agencia WHERE unidad_bancaria_id = ? ORDER BY id LIMIT 1");
    $st->execute([$unidadBancariaId]);
    $ag = $st->fetchColumn();
    if ($ag) return (string)$ag;

    $st = $pdo->prepare("SELECT nombre FROM unidad_bancaria WHERE id = ? LIMIT 1");
    $st->execute([$unidadBancariaId]);
    $nombreCoop = $st->fetchColumn() ?: 'Cooperativa';
    $nombreCoopCorto = mb_substr((string)$nombreCoop, 0, 30);
    $nombreZona = mb_substr('Zona - ' . $nombreCoopCorto, 0, 45);

    $zonaId = cca_uuid();
    try {
        $pdo->prepare("INSERT INTO zona (id, nombre, ciudad) VALUES (?, ?, ?)")
            ->execute([$zonaId, $nombreZona, 'N/D']);
    } catch (\Throwable $e) {
        $pdo->prepare("INSERT INTO zona (id, nombre) VALUES (?, ?)")
            ->execute([$zonaId, $nombreZona]);
    }

    $agenciaId = cca_uuid();
    try {
        $pdo->prepare("INSERT INTO agencia (id, zona_id, unidad_bancaria_id, nombre, ciudad, direccion, activo) VALUES (?, ?, ?, ?, ?, ?, 1)")
            ->execute([$agenciaId, $zonaId, $unidadBancariaId, 'Agencia Principal', 'N/D', 'N/D']);
    } catch (\Throwable $e) {
        $pdo->prepare("INSERT INTO agencia (id, zona_id, unidad_bancaria_id, nombre, activo) VALUES (?, ?, ?, ?, 1)")
            ->execute([$agenciaId, $zonaId, $unidadBancariaId, 'Agencia Principal']);
    }
    return $agenciaId;
}

// Resuelve (o crea) supervisor.jefe_agencia_id a partir del usuario del
// "gerente responsable" elegido en el formulario.
function cca_resolverJefeAgenciaId(PDO $pdo, string $idGerenteUsuario): ?string {
    $st = $pdo->prepare('SELECT id FROM jefe_agencia WHERE usuario_id = ? LIMIT 1');
    $st->execute([$idGerenteUsuario]);
    $ja = $st->fetchColumn();
    if ($ja) return (string)$ja;

    $st = $pdo->prepare('SELECT unidad_bancaria_id FROM gerente_general WHERE usuario_id = ? LIMIT 1');
    $st->execute([$idGerenteUsuario]);
    $ubId = $st->fetchColumn();
    if (!$ubId) return null;

    $agenciaId = cca_resolverAgenciaPrincipal($pdo, (string)$ubId);
    $nuevoJaId = cca_uuid();
    $pdo->prepare('INSERT INTO jefe_agencia (id, usuario_id, agencia_id) VALUES (?, ?, ?)')
        ->execute([$nuevoJaId, $idGerenteUsuario, $agenciaId]);
    return $nuevoJaId;
}

// ── Recoger y validar datos comunes ──────────────────────────
$rol_crear           = $_POST['rol_crear'] ?? '';
$unidad_bancaria_id  = $_POST['unidad_bancaria_id'] ?? '';
$gerente_responsable = trim($_POST['gerente_responsable_id'] ?? '');
$id_supervisor        = trim($_POST['id_supervisor'] ?? '');
$nombres              = trim($_POST['nombres'] ?? '');
$apellidos            = trim($_POST['apellidos'] ?? '');
$cedula               = trim($_POST['cedula'] ?? '');
$email                = trim($_POST['email'] ?? '');
$telefono             = trim($_POST['telefono'] ?? '');
$password             = $_POST['password'] ?? '';
$nombre_completo      = trim($nombres . ' ' . $apellidos);

$errores = [];

if (!in_array($rol_crear, ['gerente_general', 'supervisor', 'asesor'], true)) {
    $errores[] = 'Rol a crear inválido';
}
if (empty($unidad_bancaria_id)) $errores[] = 'La cooperativa/banco es requerida';
if ($rol_crear === 'supervisor' && $gerente_responsable === '') {
    $errores[] = 'Debes elegir el Gerente Responsable de esta cooperativa para crear un supervisor';
}
if ($rol_crear === 'asesor' && $id_supervisor === '') {
    $errores[] = 'Debes elegir el Supervisor al que reportará este asesor';
}

foreach ([
    ['nombres',   fn($v) => validarNombre($v, 'Nombres')],
    ['apellidos', fn($v) => validarNombre($v, 'Apellidos')],
    ['cedula',    fn($v) => validarCedulaEc($v)],
    ['email',     fn($v) => validarEmail($v)],
    ['telefono',  fn($v) => validarTelefono($v)],
    ['password',  fn($v) => validarPassword($v)],
] as [$campo, $fn]) {
    $valores = ['nombres' => $nombres, 'apellidos' => $apellidos, 'cedula' => $cedula, 'email' => $email, 'telefono' => $telefono, 'password' => $password];
    $r = $fn($valores[$campo]);
    if (!$r['ok']) $errores[] = $r['msg'];
}

// Archivo de credencial — OPCIONAL en este flujo (el admin ya está
// vinculando la cuenta bajo su propia responsabilidad).
$archivo_guardado = null;
$archivo_upload = $_FILES['credencial'] ?? null;
if ($archivo_upload && $archivo_upload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($archivo_upload['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Error en la subida del archivo';
    } else {
        $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
        $tamano_maximo = 5 * 1024 * 1024; // 5MB

        if (!in_array($archivo_upload['type'], $tipos_permitidos)) {
            $errores[] = 'Tipo de archivo no permitido (PDF, JPG, PNG)';
        } elseif ($archivo_upload['size'] > $tamano_maximo) {
            $errores[] = 'Archivo muy grande (máximo 5MB)';
        } else {
            $dir_upload = __DIR__ . '/../../uploads/credenciales_creadas_admin/';
            if (!is_dir($dir_upload)) {
                mkdir($dir_upload, 0755, true);
            }
            $ext = pathinfo($archivo_upload['name'], PATHINFO_EXTENSION);
            $nombre_archivo = $rol_crear . '_' . preg_replace('/[^a-zA-Z0-9]/', '', $cedula) . '_' . time() . '.' . $ext;
            $ruta_completa = $dir_upload . $nombre_archivo;

            if (move_uploaded_file($archivo_upload['tmp_name'], $ruta_completa)) {
                $archivo_guardado = $nombre_archivo;
            } else {
                $errores[] = 'No se pudo guardar el archivo';
            }
        }
    }
}

if (!empty($errores)) {
    $error_msg = implode(', ', $errores);
    header("Location: crear_asesor_admin.php?error=" . urlencode($error_msg));
    exit;
}

try {
    $chkEmail = $pdo->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
    $chkEmail->execute([$email]);
    if ($chkEmail->fetch()) {
        header("Location: crear_asesor_admin.php?error=" . urlencode('Ya existe un usuario con ese email'));
        exit;
    }

    // Asegurar que la columna cedula exista en la tabla usuario
    $chk = $pdo->query("SHOW COLUMNS FROM usuario LIKE 'cedula'");
    if (!$chk->fetch()) {
        $pdo->exec("ALTER TABLE usuario ADD COLUMN cedula VARCHAR(13) NULL AFTER nombre");
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $nuevo_usuario_id = cca_uuid();
    $pdo->prepare("
        INSERT INTO usuario
            (id, nombre, cedula, email, telefono, password_hash, rol, activo, estado_aprobacion, aprobado_por, fecha_aprobacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'aprobado', ?, NOW())
    ")->execute([$nuevo_usuario_id, $nombre_completo, $cedula, $email, $telefono, $password_hash, $rol_crear, $admin_id]);

    if ($rol_crear === 'gerente_general') {
        $nuevoGgId = cca_uuid();
        $pdo->prepare("INSERT INTO gerente_general (id, usuario_id, unidad_bancaria_id) VALUES (?, ?, ?)")
            ->execute([$nuevoGgId, $nuevo_usuario_id, $unidad_bancaria_id]);

    } elseif ($rol_crear === 'supervisor') {
        $jefeAgenciaId = cca_resolverJefeAgenciaId($pdo, $gerente_responsable);
        if (!$jefeAgenciaId) {
            throw new Exception('El gerente elegido no tiene una cooperativa/agencia válida asociada. Verifica el gerente responsable.');
        }
        $nuevoSupId = cca_uuid();
        $pdo->prepare("INSERT INTO supervisor (id, usuario_id, jefe_agencia_id, meta_asesores) VALUES (?, ?, ?, 5)")
            ->execute([$nuevoSupId, $nuevo_usuario_id, $jefeAgenciaId]);

    } elseif ($rol_crear === 'asesor') {
        $stmtSup = $pdo->prepare("SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1");
        $stmtSup->execute([$id_supervisor]);
        $supervisor_pk = $stmtSup->fetchColumn();
        if (!$supervisor_pk) {
            throw new Exception('No se encontró el registro de supervisor elegido.');
        }
        $nuevoAsesorId = cca_uuid();
        $pdo->prepare("INSERT INTO asesor (id, usuario_id, supervisor_id, meta_tareas_diarias) VALUES (?, ?, ?, 8)")
            ->execute([$nuevoAsesorId, $nuevo_usuario_id, $supervisor_pk]);
    }

    $pdo->commit();

    header('Location: crear_asesor_admin.php?success=1');
    exit;

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = str_contains($e->getMessage(), 'Duplicate') ? 'El email o cédula ya existe en el sistema' : $e->getMessage();
    header("Location: crear_asesor_admin.php?error=" . urlencode('Error al crear la cuenta: ' . $msg));
    exit;
}

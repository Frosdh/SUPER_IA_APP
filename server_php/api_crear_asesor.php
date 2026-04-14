<?php
/**
 * API: Crear/Registrar nuevo Asesor
 * POST http://localhost/FUBER_APP/server_php/api_crear_asesor.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

require_once 'db_config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido. Use POST");
    }

    // Validar y obtener parámetros
    $nombres = $_POST['nombres'] ?? null;
    $apellidos = $_POST['apellidos'] ?? null;
    $email = $_POST['email'] ?? null;
    $telefono = $_POST['telefono'] ?? null;
    $contrasena = $_POST['contrasena'] ?? null;
    $supervisor_id = $_POST['supervisor_id'] ?? null;
    $unidad_bancaria_id = $_POST['unidad_bancaria_id'] ?? null;
    $documento_path = $_POST['documento_path'] ?? null;  // Ruta del documento subido

    // Validar campos requeridos
    if (!$nombres || !$apellidos || !$email || !$contrasena || !$supervisor_id) {
        throw new Exception("Campos requeridos: nombres, apellidos, email, contrasena, supervisor_id");
    }

    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($conexion->connect_error) {
        throw new Exception("Conexión fallida: " . $conexion->connect_error);
    }
    $conexion->set_charset('utf8mb4');

    // Asegurar que solicitud_registro.documento_url admite NULL
    $conexion->query("ALTER TABLE solicitud_registro MODIFY COLUMN documento_url VARCHAR(500) NULL DEFAULT NULL");

    // Verificar que el email no exista (prepared)
    $st = $conexion->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
    $st->bind_param('s', $email);
    $st->execute();
    $st->store_result();
    if ($st->num_rows > 0) throw new Exception("El email ya está registrado");
    $st->close();

    // Validar que el supervisor existe (prepared)
    $st = $conexion->prepare(
        "SELECT s.id FROM supervisor s
         JOIN usuario u ON u.id = s.usuario_id
         WHERE s.usuario_id = ?
           AND u.rol = 'supervisor'
           AND u.activo = 1
           AND u.estado_aprobacion = 'aprobado'
         LIMIT 1"
    );
    $st->bind_param('s', $supervisor_id);
    $st->execute();
    $sup_row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$sup_row) throw new Exception("Supervisor no encontrado o inactivo");
    $supervisor_table_id = $sup_row['id'];

    // Iniciar transacción
    $conexion->begin_transaction();

    // 1. Crear usuario
    $usuario_id      = uniqid('usr_', true);
    $nombre_completo = "$nombres $apellidos";
    $password_hash   = password_hash($contrasena, PASSWORD_DEFAULT);

    $st = $conexion->prepare(
        "INSERT INTO usuario
         (id, nombre, email, password_hash, rol, activo, estado_aprobacion, telefono, created_at)
         VALUES (?, ?, ?, ?, 'asesor', 0, 'pendiente', ?, NOW())"
    );
    $st->bind_param('sssss', $usuario_id, $nombre_completo, $email, $password_hash, $telefono);
    if (!$st->execute()) throw new Exception("Error creando usuario: " . $st->error);
    $st->close();

    // 2. Crear perfil de asesor
    $asesor_id       = uniqid('asesor_', true);
    $doc_path_val    = $documento_path ?: null;   // guardar NULL si no hay documento

    $st = $conexion->prepare(
        "INSERT INTO asesor (id, usuario_id, supervisor_id, documento_path)
         VALUES (?, ?, ?, ?)"
    );
    $st->bind_param('ssss', $asesor_id, $usuario_id, $supervisor_table_id, $doc_path_val);
    if (!$st->execute()) throw new Exception("Error creando perfil asesor: " . $st->error);
    $st->close();

    // 3. Crear solicitud de registro (documento_url = doc_path o NULL)
    $solicitud_id = uniqid('sol_', true);

    $st = $conexion->prepare(
        "INSERT INTO solicitud_registro
         (id, usuario_id, rol_solicitado, documento_url, estado, created_at)
         VALUES (?, ?, 'asesor', ?, 'pendiente', NOW())"
    );
    $st->bind_param('sss', $solicitud_id, $usuario_id, $doc_path_val);
    if (!$st->execute()) throw new Exception("Error creando solicitud: " . $st->error);
    $st->close();

    // Commit
    $conexion->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Asesor registrado exitosamente. Pendiente de aprobación.',
        'asesor_id' => $asesor_id,
        'usuario_id' => $usuario_id
    ]);

    $conexion->close();

} catch (Exception $e) {
    if (isset($conexion)) {
        $conexion->rollback();
        $conexion->close();
    }
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>

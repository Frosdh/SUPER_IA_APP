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

    // Verificar que el email no exista
    $result = $conexion->query("SELECT id FROM usuario WHERE email = '$email' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        throw new Exception("El email ya está registrado");
    }

    // Validar que el supervisor existe
    $result = $conexion->query(
        "SELECT s.id FROM supervisor s 
         JOIN usuario u ON u.id = s.usuario_id
         WHERE s.usuario_id = '$supervisor_id' 
         AND u.rol = 'supervisor' 
         AND u.activo = 1 
         AND u.estado_aprobacion = 'aprobado' 
         LIMIT 1"
    );
    if (!$result || $result->num_rows === 0) {
        throw new Exception("Supervisor no encontrado o inactivo");
    }
    
    // Obtener el supervisor.id para la FK
    $sup_row = $result->fetch_assoc();
    $supervisor_table_id = $sup_row['id'];

    // Iniciar transacción
    $conexion->begin_transaction();

    // 1. Crear usuario
    $usuario_id = uniqid('usr_', true);
    $nombre_completo = "$nombres $apellidos";
    $password_hash = password_hash($contrasena, PASSWORD_DEFAULT);

    $sql = "INSERT INTO usuario 
            (id, nombre, email, password_hash, rol, activo, estado_aprobacion, telefono, created_at)
            VALUES 
            ('$usuario_id', '$nombre_completo', '$email', '$password_hash', 'asesor', 0, 'pendiente', '$telefono', NOW())";

    if (!$conexion->query($sql)) {
        throw new Exception("Error creando usuario: " . $conexion->error);
    }

    // 2. Crear perfil de asesor
    $asesor_id = uniqid('asesor_', true);
    $sql = "INSERT INTO asesor 
            (id, usuario_id, supervisor_id, documento_path)
            VALUES 
            ('$asesor_id', '$usuario_id', '$supervisor_table_id', '$documento_path')";

    if (!$conexion->query($sql)) {
        throw new Exception("Error creando perfil asesor: " . $conexion->error);
    }

    // 3. Crear solicitud de registro
    $solicitud_id = uniqid('sol_', true);
    $sql = "INSERT INTO solicitud_registro 
            (id, usuario_id, rol_solicitado, estado, created_at)
            VALUES 
            ('$solicitud_id', '$usuario_id', 'asesor', 'pendiente', NOW())";

    if (!$conexion->query($sql)) {
        throw new Exception("Error creando solicitud: " . $conexion->error);
    }

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

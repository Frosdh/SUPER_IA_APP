<?php
// ============================================================
// register_user.php - SUPER_IA LOGAN - Registro de Asesores
// Base de datos: super_ia_logan
// Estructura: usuario (email, password_hash, rol, estado_registro)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once __DIR__ . '/db_config.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

// Leer parámetros del POST
$nombre         = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$email          = isset($_POST['email']) ? trim($_POST['email']) : '';
$password       = isset($_POST['password']) ? trim($_POST['password']) : '';
$telefono       = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$agencia_id     = isset($_POST['agencia_id']) ? intval($_POST['agencia_id']) : null;
$rol            = isset($_POST['rol']) ? trim($_POST['rol']) : 'asesor';

// Validaciones
if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'El nombre es requerido']);
    exit;
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email inválido']);
    exit;
}

if (empty($password) || strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'La contraseña debe tener al menos 6 caracteres']);
    exit;
}

// Validar rol
$rolesValidos = ['asesor', 'supervisor', 'jefe_agencia', 'gerente_general'];
if (!in_array($rol, $rolesValidos)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Rol no válido']);
    exit;
}

try {
    // Verificar si el email ya existe
    $checkEmail = $conn->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
    $checkEmail->bind_param('s', $email);
    $checkEmail->execute();
    $resultEmail = $checkEmail->get_result();
    
    if ($resultEmail->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Este email ya está registrado']);
        $checkEmail->close();
        exit;
    }
    $checkEmail->close();
    
    // Hashear contraseña
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insertar nuevo usuario con estado "pendiente"
    $insertStmt = $conn->prepare(
        "INSERT INTO usuario (nombre, email, password_hash, rol, agencia_id, estado_registro, activo, created_at) 
         VALUES (?, ?, ?, ?, ?, 'pendiente', 1, NOW())"
    );
    
    if (!$insertStmt) {
        throw new Exception('Error en preparar la consulta: ' . $conn->error);
    }
    
    $insertStmt->bind_param('ssssi', $nombre, $email, $password_hash, $rol, $agencia_id);
    
    if (!$insertStmt->execute()) {
        throw new Exception('Error al insertar usuario: ' . $insertStmt->error);
    }
    
    $usuario_id = $conn->insert_id;
    $insertStmt->close();
    
    // Si es asesor y se proporciona supervisor_id, crear entrada en tabla asesor
    if ($rol === 'asesor' && isset($_POST['supervisor_id'])) {
        $supervisor_id = intval($_POST['supervisor_id']);
        
        $asesorStmt = $conn->prepare(
            "INSERT INTO asesor (usuario_id, supervisor_id, meta_tareas_diarias, created_at)
             VALUES (?, ?, 8, NOW())"
        );
        
        $asesorStmt->bind_param('ii', $usuario_id, $supervisor_id);
        $asesorStmt->execute();
        $asesorStmt->close();
    }
    
    // Crear entrada en solicitud_registro para auditoría
    $solicitudStmt = $conn->prepare(
        "INSERT INTO solicitud_registro (usuario_id, fecha_solicitud, estado, comentarios)
         VALUES (?, NOW(), 'pendiente', ?)"
    );
    
    $comentario = "Registro de $rol a través de app móvil";
    $solicitudStmt->bind_param('is', $usuario_id, $comentario);
    $solicitudStmt->execute();
    $solicitudStmt->close();
    
    // Respuesta exitosa
    http_response_code(201);
    echo json_encode([
        'status'        => 'success',
        'message'       => 'Cuenta creada exitosamente. Esperando aprobación del administrador.',
        'usuario_id'    => $usuario_id,
        'email'         => $email,
        'estado'        => 'pendiente',
        'rol'           => $rol,
        'activo'        => 1
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
    
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

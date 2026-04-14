<?php
// ============================================================
// login.php - SUPER_IA LOGAN - Login de Usuarios
// Base de datos: super_ia_logan
// Estructura: usuario (email, password_hash, rol)
// ============================================================

header('Content-Type: application/json');
require_once 'db_config.php';

// Recibir datos del formulario/POST
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

// Validar que los campos no estén vacíos
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Email y contraseña son requeridos'
    ]);
    exit;
}

try {
    // Buscar usuario por email en la tabla usuario
    $query = "SELECT id, nombre, email, password_hash, rol, estado_registro, 
                     activo, agencia_id, ultimo_login
              FROM usuario
              WHERE email = ? AND activo = 1";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Error en la consulta: " . $conn->error);
    }
    
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Email o contraseña incorrectos'
        ]);
        exit;
    }
    
    $user = $result->fetch_assoc();
    
    // Verificar contraseña usando password_verify
    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Email o contraseña incorrectos'
        ]);
        exit;
    }
    
    // Verificar estado de registro
    if ($user['estado_registro'] === 'rechazado') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Tu cuenta ha sido rechazada. Contacta al administrador.'
        ]);
        exit;
    }
    
    if ($user['estado_registro'] === 'pendiente') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Tu cuenta está pendiente de aprobación. Espera a que sea validada.',
            'pending' => true
        ]);
        exit;
    }
    
    // Login exitoso - iniciar sesión
    session_start();
    
    $_SESSION['usuario_id'] = $user['id'];
    $_SESSION['nombre'] = $user['nombre'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['rol'] = $user['rol'];
    $_SESSION['agencia_id'] = $user['agencia_id'];
    $_SESSION['login_time'] = time();
    
    // Actualizar último login
    $updateQuery = "UPDATE usuario SET ultimo_login = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param('i', $user['id']);
    $updateStmt->execute();
    
    // Generar token simple (en producción usar JWT)
    $token = bin2hex(random_bytes(32));
    
    // Respuesta exitosa
    echo json_encode([
        'status' => 'success',
        'message' => 'Login exitoso',
        'user' => [
            'id' => $user['id'],
            'nombre' => $user['nombre'],
            'email' => $user['email'],
            'rol' => $user['rol'],
            'agencia_id' => $user['agencia_id']
        ],
        'token' => $token,
        'redirect' => '/dashboard'
    ]);
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

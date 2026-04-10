<?php
// ============================================================
// register_asesor.php - Registra un nuevo asesor en la basede datos
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        throw new Exception('Datos inválidos');
    }

    // Validar campos requeridos
    $required = ['nombres', 'apellidos', 'email', 'telefono', 'usuario', 'contrasena', 'cooperativa', 'supervisor'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception('Campo requerido: ' . $field);
        }
    }

    $nombres = trim($data['nombres']);
    $apellidos = trim($data['apellidos']);
    $email = trim($data['email']);
    $telefono = trim($data['telefono']);
    $usuario = trim($data['usuario']);
    $contrasena = trim($data['contrasena']);
    $cooperativa = trim($data['cooperativa']);
    $supervisor = trim($data['supervisor']);

    // Validar email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email inválido');
    }

    // Validar longitud de contraseña
    if (strlen($contrasena) < 6) {
        throw new Exception('Contraseña debe tener al menos 6 caracteres');
    }

    // Verificar si el usuario ya existe
    $check_user = $conn->prepare("SELECT id FROM asesor WHERE usuario = ? OR email = ?");
    $check_user->bind_param("ss", $usuario, $email);
    $check_user->execute();
    $result_check = $check_user->get_result();

    if ($result_check->num_rows > 0) {
        throw new Exception('El usuario o email ya está registrado');
    }

    $check_user->close();

    // Hashear contraseña
    $contrasena_hash = password_hash($contrasena, PASSWORD_BCRYPT);

    // Insertar nuevo asesor
    $insert = $conn->prepare(
        "INSERT INTO asesor (nombres, apellidos, email, telefono, usuario, contrasena, cooperativa, supervisor, 
         estado, fecha_registro) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    if (!$insert) {
        throw new Exception('Error en preparación: ' . $conn->error);
    }

    $estado = 'pendiente'; // Nuevo asesor en estado pendiente de aprobación
    
    $insert->bind_param(
        "sssssssss",
        $nombres,
        $apellidos,
        $email,
        $telefono,
        $usuario,
        $contrasena_hash,
        $cooperativa,
        $supervisor,
        $estado
    );

    if ($insert->execute()) {
        $insert->close();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Asesor registrado exitosamente',
            'asesor_id' => $conn->insert_id
        ]);
    } else {
        throw new Exception('Error al registrar: ' . $conn->error);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?>

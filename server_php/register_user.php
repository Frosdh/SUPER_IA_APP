<?php
// ============================================================
// register_user.php - API para registrar usuarios de fu_uber
// Colocar este archivo en: /fuber_api/register_user.php
// ============================================================

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/email_helper.php';

// -------- CONFIGURACION DE BASE DE DATOS --------
$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';
// ------------------------------------------------

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

// Leer parametros del POST
$nombre   = isset($_POST['nombre'])   ? trim($_POST['nombre'])   : '';
$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
$password_plain = isset($_POST['password']) ? trim($_POST['password']) : '';
$token_fcm = isset($_POST['token_fcm']) ? trim($_POST['token_fcm']) : '';

// Validar que no vengan vacios
if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
    exit;
}

if (empty($email)) {
    echo json_encode(["status" => "error", "message" => "El correo es requerido"]);
    exit;
}

// Conectar a MySQL
$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    echo json_encode([
        "status"  => "error",
        "message" => "Error de conexion a base de datos: " . $conn->connect_error
    ]);
    exit;
}

// Buscar usuario por telefono
$checkPhone = $conn->prepare("SELECT id, email FROM usuarios WHERE telefono = ? LIMIT 1");
$checkPhone->bind_param("s", $telefono);
$checkPhone->execute();
$resultPhone = $checkPhone->get_result();
$usuarioPorTelefono = $resultPhone->fetch_assoc();
$checkPhone->close();

// Buscar usuario por correo
$checkEmail = $conn->prepare("SELECT id, telefono FROM usuarios WHERE email = ? LIMIT 1");
$checkEmail->bind_param("s", $email);
$checkEmail->execute();
$resultEmail = $checkEmail->get_result();
$usuarioPorEmail = $resultEmail->fetch_assoc();
$checkEmail->close();

if ($usuarioPorTelefono && $usuarioPorEmail) {
    if (intval($usuarioPorTelefono['id']) !== intval($usuarioPorEmail['id'])) {
        echo json_encode([
            "status"  => "error",
            "message" => "Ese numero ya esta asociado a otra cuenta y ese correo pertenece a un usuario distinto"
        ]);
        $conn->close();
        exit;
    }

    $update = $conn->prepare("UPDATE usuarios SET nombre = ?, token_fcm = ? WHERE id = ?");
    $userId = intval($usuarioPorTelefono['id']);
    $update->bind_param("ssi", $nombre, $token_fcm, $userId);
    $update->execute();
    $update->close();

    echo json_encode([
        "status"  => "success",
        "message" => "Usuario actualizado correctamente",
        "nuevo"   => false,
        "id"      => $userId
    ]);
    $conn->close();
    exit;
}

if ($usuarioPorTelefono && !$usuarioPorEmail) {
    echo json_encode([
        "status"  => "error",
        "message" => "Ese numero ya esta asociado a otra cuenta"
    ]);
    $conn->close();
    exit;
}

if (!$usuarioPorTelefono && $usuarioPorEmail) {
    echo json_encode([
        "status"  => "error",
        "message" => "Ese correo ya esta registrado con otro numero"
    ]);
    $conn->close();
    exit;
}

// Hashear la contraseña
$password_hash = password_hash($password_plain, PASSWORD_DEFAULT);

// Insertar nuevo usuario - columnas exactas de la tabla fuber_db.usuarios
$stmt = $conn->prepare("INSERT INTO usuarios (nombre, telefono, email, pass_hash, token_fcm, activo, creado_en) VALUES (?, ?, ?, ?, ?, 1, NOW())");
$stmt->bind_param("sssss", $nombre, $telefono, $email, $password_hash, $token_fcm);

if ($stmt->execute()) {
    $nuevo_id = $conn->insert_id;
    list($welcomeSent, $welcomeError) = sendEmailMessage(
        $email,
        'Bienvenido a GeoMove',
        buildWelcomeEmailHtml($nombre),
        buildWelcomeEmailText($nombre)
    );
    echo json_encode([
        "status"  => "success",
        "message" => "Usuario registrado correctamente",
        "nuevo"   => true,
        "id"      => $nuevo_id,
        "welcome_email_sent" => $welcomeSent,
        "welcome_email_error" => $welcomeSent ? null : $welcomeError
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "Error al insertar en la base de datos: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>

<?php
// ============================================================
// register_user.php - API para registrar usuarios de fu_uber
// Colocar este archivo en: /fuber_api/register_user.php
// ============================================================

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

// -------- CONFIGURACION DE BASE DE DATOS --------
$host     = "localhost";
$dbname   = "fuber_db";      // <-- Cambia por el nombre de tu base de datos
$username = "root";           // <-- Cambia por tu usuario MySQL
$password = "";               // <-- Cambia por tu contraseña MySQL
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

// Validar que no vengan vacios
if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
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

// Verificar si el telefono ya existe (usando el nombre exacto de columna de la tabla)
$check = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ?");
$check->bind_param("s", $telefono);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    // El usuario ya existe - actualizar el nombre si viene uno nuevo
    $check->close();
    if (!empty($nombre) || !empty($email)) {
        $update = $conn->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE telefono = ?");
        $update->bind_param("sss", $nombre, $email, $telefono);
        $update->execute();
        $update->close();
    }
    echo json_encode([
        "status"  => "success",
        "message" => "Usuario actualizado",
        "nuevo"   => false
    ]);
    $conn->close();
    exit;
}
$check->close();

// Hashear la contraseña
$password_hash = password_hash($password_plain, PASSWORD_DEFAULT);

// Insertar nuevo usuario - columnas exactas de la tabla fuber_db.usuarios
$stmt = $conn->prepare("INSERT INTO usuarios (nombre, telefono, email, pass_hash, activo, creado_en) VALUES (?, ?, ?, ?, 1, NOW())");
$stmt->bind_param("ssss", $nombre, $telefono, $email, $password_hash);

if ($stmt->execute()) {
    $nuevo_id = $conn->insert_id;
    echo json_encode([
        "status"  => "success",
        "message" => "Usuario registrado correctamente",
        "nuevo"   => true,
        "id"      => $nuevo_id
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

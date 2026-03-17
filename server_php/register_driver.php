<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// register_driver.php - API para registrar conductores
// ============================================================

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

// -------- CONFIGURACION DE BASE DE DATOS --------
// ------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit;
}

// Datos del Conductor
$nombre         = isset($_POST['nombre'])       ? trim($_POST['nombre'])       : '';
$email          = isset($_POST['email'])        ? trim($_POST['email'])        : '';
$telefono       = isset($_POST['telefono'])     ? trim($_POST['telefono'])     : '';
$cedula         = isset($_POST['cedula'])       ? trim($_POST['cedula'])       : '';
$password_plain = isset($_POST['password'])     ? trim($_POST['password'])     : '';
$ciudad         = isset($_POST['ciudad'])       ? trim($_POST['ciudad'])       : 'Cuenca';

// Datos del Vehículo
$marca        = isset($_POST['marca'])        ? trim($_POST['marca'])        : '';
$modelo       = isset($_POST['modelo'])       ? trim($_POST['modelo'])       : '';
$placa        = isset($_POST['placa'])        ? strtoupper(trim($_POST['placa'])) : '';
$color        = isset($_POST['color'])        ? trim($_POST['color'])        : '';
$anio         = isset($_POST['anio'])         ? intval($_POST['anio'])       : 0;
$categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 1;

// Validaciones
if (empty($nombre) || empty($telefono) || empty($cedula) || empty($password_plain) ||
    empty($marca) || empty($modelo) || empty($placa) || empty($color) || $anio < 1990) {
    echo json_encode(["status" => "error", "message" => "Todos los campos son requeridos"]);
    exit;
}
if ($categoria_id < 1 || $categoria_id > 3) {
    $categoria_id = 1;
}

// Verificar duplicado de teléfono o cédula
$check = $conn->prepare("SELECT id FROM conductores WHERE telefono = ? OR cedula = ? LIMIT 1");
$check->bind_param("ss", $telefono, $cedula);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    $conn->close();
    echo json_encode(["status" => "error", "message" => "Ya existe un conductor registrado con ese teléfono o cédula"]);
    exit;
}
$check->close();

// Verificar placa duplicada
$checkPlaca = $conn->prepare("SELECT id FROM vehiculos WHERE placa = ? LIMIT 1");
$checkPlaca->bind_param("s", $placa);
$checkPlaca->execute();
$checkPlaca->store_result();
if ($checkPlaca->num_rows > 0) {
    $checkPlaca->close();
    $conn->close();
    echo json_encode(["status" => "error", "message" => "Ya existe un vehículo registrado con esa placa"]);
    exit;
}
$checkPlaca->close();

// Hash de contraseña
$pass_hash = password_hash($password_plain, PASSWORD_BCRYPT);

// Transacción: conductor + vehículo
$conn->begin_transaction();
try {
    // Insertar conductor (verificado=0 → pendiente, estado=desconectado)
    $stmt = $conn->prepare(
        "INSERT INTO conductores (nombre, email, telefono, cedula, pass_hash, ciudad, estado, verificado, calificacion_promedio)
         VALUES (?, ?, ?, ?, ?, ?, 'desconectado', 0, 5.00)"
    );
    $stmt->bind_param("ssssss", $nombre, $email, $telefono, $cedula, $pass_hash, $ciudad);
    $stmt->execute();
    $conductor_id = $conn->insert_id;
    $stmt->close();

    if ($conductor_id <= 0) {
        throw new Exception("No se pudo insertar el conductor");
    }

    // Insertar vehículo vinculado con la categoría seleccionada
    $stmtV = $conn->prepare(
        "INSERT INTO vehiculos (conductor_id, categoria_id, placa, marca, modelo, color, anio)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmtV->bind_param("iissssi", $conductor_id, $categoria_id, $placa, $marca, $modelo, $color, $anio);
    $stmtV->execute();
    $stmtV->close();

    $conn->commit();
    echo json_encode([
        "status"       => "success",
        "message"      => "Registro exitoso. Tu cuenta será revisada por un administrador.",
        "conductor_id" => $conductor_id
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Error al registrar: " . $e->getMessage()]);
}

$conn->close();
?>

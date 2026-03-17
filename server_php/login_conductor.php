<?php
require_once __DIR__ . '/db_config.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$identificador = isset($_POST['identificador']) ? trim($_POST['identificador']) : '';
$passwordPlain = isset($_POST['password']) ? trim($_POST['password']) : '';

if ($identificador === '' || $passwordPlain === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Telefono o cedula y password requeridos"
    ]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, nombre, telefono, cedula, pass_hash, estado, verificado, latitud, longitud
    FROM conductores
    WHERE telefono = ? OR cedula = ?
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "message" => "Error al preparar consulta: " . $conn->error
    ]);
    $conn->close();
    exit;
}

$stmt->bind_param("ss", $identificador, $identificador);
$stmt->execute();
$stmt->bind_result(
    $id,
    $nombre,
    $telefono,
    $cedula,
    $passHash,
    $estado,
    $verificado,
    $latitud,
    $longitud
);

if (!$stmt->fetch()) {
    echo json_encode([
        "status" => "error",
        "message" => "Conductor no encontrado"
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

$stmt->close();

if (!password_verify($passwordPlain, $passHash)) {
    echo json_encode([
        "status" => "error",
        "message" => "Credenciales invalidas"
    ]);
    $conn->close();
    exit;
}

if ((int)$verificado !== 1) {
    echo json_encode([
        "status" => "error",
        "message" => "Tu cuenta de conductor aun no esta verificada"
    ]);
    $conn->close();
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Login correcto",
    "conductor" => [
        "id" => (int)$id,
        "nombre" => $nombre,
        "telefono" => $telefono,
        "cedula" => $cedula,
        "estado" => $estado,
        "verificado" => (int)$verificado,
        "latitud" => $latitud !== null ? (double)$latitud : null,
        "longitud" => $longitud !== null ? (double)$longitud : null
    ]
]);

$conn->close();
?>

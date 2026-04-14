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
$passwordPlain = isset($_POST['password'])      ? trim($_POST['password'])      : '';
$tokenFcm      = isset($_POST['token_fcm'])     ? trim($_POST['token_fcm'])     : '';

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

if ((int)$verificado === 2) {
    echo json_encode([
        "status" => "error",
        "message" => "Tu solicitud fue rechazada. Contacta al soporte para mas informacion."
    ]);
    $conn->close();
    exit;
}

if ((int)$verificado === 0) {
    // Permitir acceso parcial para la pantalla de revisión
    // Guardar token FCM si viene en el request
    if ($tokenFcm !== '') {
        $stmtFcm = $conn->prepare("UPDATE conductores SET token_fcm = ? WHERE id = ?");
        $stmtFcm->bind_param("si", $tokenFcm, $id);
        $stmtFcm->execute();
        $stmtFcm->close();
    }

    echo json_encode([
        "status" => "pending",
        "message" => "Tu cuenta aun no ha sido verificada. Debes revisar tus documentos.",
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
    exit;
}

if ((int)$verificado !== 1) {
    echo json_encode([
        "status" => "error",
        "message" => "Estado de conductor no válido."
    ]);
    $conn->close();
    exit;
}
// Guardar token FCM si viene en el request
if ($tokenFcm !== '') {
    $stmtFcm = $conn->prepare("UPDATE conductores SET token_fcm = ? WHERE id = ?");
    $stmtFcm->bind_param("si", $tokenFcm, $id);
    $stmtFcm->execute();
    $stmtFcm->close();
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

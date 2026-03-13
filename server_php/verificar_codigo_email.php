<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';

if (empty($email) || empty($codigo)) {
    echo json_encode(["status" => "error", "message" => "Correo y codigo son requeridos"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode([
        "status"  => "error",
        "message" => "Error de conexion a base de datos: " . $conn->connect_error
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM email_otp_codes WHERE email = ? AND codigo = ? AND usado = 0 AND expira_en >= NOW() ORDER BY id DESC LIMIT 1");
$stmt->bind_param("ss", $email, $codigo);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $otpId = intval($row["id"]);
    $stmt->close();

    $update = $conn->prepare("UPDATE email_otp_codes SET usado = 1 WHERE id = ?");
    $update->bind_param("i", $otpId);
    $update->execute();
    $update->close();

    echo json_encode([
        "status" => "success",
        "valid" => true,
        "message" => "Codigo verificado"
    ]);
} else {
    $stmt->close();
    echo json_encode([
        "status" => "error",
        "valid" => false,
        "message" => "Codigo invalido o expirado"
    ]);
}

$conn->close();
?>

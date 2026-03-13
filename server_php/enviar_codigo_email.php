<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

require_once __DIR__ . '/email_helper.php';

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Correo invalido"]);
    exit;
}

list($emailConfig, $configError) = loadEmailConfig();
if ($configError !== null) {
    echo json_encode([
        "status" => "error",
        "message" => $configError
    ]);
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

$codigo = str_pad(strval(random_int(0, 999999)), 6, "0", STR_PAD_LEFT);

$stmtExpire = $conn->prepare("UPDATE email_otp_codes SET usado = 1 WHERE email = ? AND usado = 0");
$stmtExpire->bind_param("s", $email);
$stmtExpire->execute();
$stmtExpire->close();

$stmt = $conn->prepare("INSERT INTO email_otp_codes (email, codigo, expira_en, usado, creado_en) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0, NOW())");
$stmt->bind_param("ss", $email, $codigo);

if (!$stmt->execute()) {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo guardar el codigo OTP"
    ]);
    $stmt->close();
    $conn->close();
    exit;
}
$stmt->close();

list($mailSent, $mailError) = sendEmailMessage(
    $email,
    'Fuber - Codigo de verificacion',
    buildOtpEmailHtml($codigo),
    buildOtpEmailText($codigo)
);

if (!$mailSent) {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo enviar el correo: " . $mailError
    ]);
    $conn->close();
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Codigo enviado al correo"
]);

$conn->close();
?>

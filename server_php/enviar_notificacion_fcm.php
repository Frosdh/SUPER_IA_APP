<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

$serviceAccountPath = __DIR__ . "/firebase_service_account.json";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$titulo = isset($_POST['titulo']) ? trim($_POST['titulo']) : 'GeoMove';
$mensaje = isset($_POST['mensaje']) ? trim($_POST['mensaje']) : 'Tienes una nueva notificacion';
$dataRaw = isset($_POST['data_json']) ? trim($_POST['data_json']) : '';

if ($telefono === '') {
    echo json_encode(["status" => "error", "message" => "Telefono requerido"]);
    exit;
}

if (!file_exists($serviceAccountPath)) {
    echo json_encode([
        "status" => "error",
        "message" => "Falta firebase_service_account.json en server_php",
    ]);
    exit;
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getAccessToken($serviceAccountPath) {
    $creds = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$creds || empty($creds['client_email']) || empty($creds['private_key'])) {
        throw new Exception("Credenciales Firebase invalidas");
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    $claims = [
        'iss' => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ];

    $jwtHeader = base64UrlEncode(json_encode($header));
    $jwtClaims = base64UrlEncode(json_encode($claims));
    $signatureInput = $jwtHeader . '.' . $jwtClaims;

    $privateKey = openssl_pkey_get_private($creds['private_key']);
    if (!$privateKey) {
        throw new Exception("No se pudo leer la clave privada");
    }

    openssl_sign($signatureInput, $signature, $privateKey, 'sha256WithRSAEncryption');
    openssl_free_key($privateKey);
    $jwt = $signatureInput . '.' . base64UrlEncode($signature);

    $postFields = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("No se pudo obtener access token: " . $response);
    }

    $json = json_decode($response, true);
    return [$json['access_token'], $creds['project_id']];
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode([
        "status" => "error",
        "message" => "Error de conexion: " . $conn->connect_error,
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT token_fcm FROM usuarios WHERE telefono = ? LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
$conn->close();

$tokenFcm = $row['token_fcm'] ?? '';
if ($tokenFcm === '') {
    echo json_encode([
        "status" => "error",
        "message" => "El usuario no tiene token_fcm registrado",
    ]);
    exit;
}

$customData = [];
if ($dataRaw !== '') {
    $decoded = json_decode($dataRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $key => $value) {
            $customData[$key] = strval($value);
        }
    }
}

try {
    list($accessToken, $projectId) = getAccessToken($serviceAccountPath);

    $message = [
        'token' => $tokenFcm,
        'notification' => [
            'title' => $titulo,
            'body' => $mensaje,
        ],
        'android' => [
            'priority' => 'high',
        ],
    ];

    if (!empty($customData)) {
        $message['data'] = (object) $customData;
    }

    $payload = [
        'message' => $message,
    ];

    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; charset=UTF-8',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
      echo json_encode([
          "status" => "success",
          "message" => "Notificacion enviada",
          "response" => json_decode($response, true),
      ]);
    } else {
      echo json_encode([
          "status" => "error",
          "message" => "FCM respondio HTTP " . $httpCode,
          "response" => $response,
      ]);
    }
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage(),
    ]);
}
?>

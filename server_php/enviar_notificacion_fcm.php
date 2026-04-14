<?php
require_once __DIR__ . '/db_config.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$serviceAccountPath = __DIR__ . "/firebase_service_account.json";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

// Leer parámetros con $_REQUEST para mayor compatibilidad (GET, POST, form-data)
$jsonInput = json_decode(file_get_contents('php://input'), true);

$telefono = isset($_REQUEST['telefono']) ? trim($_REQUEST['telefono']) : (isset($_REQUEST['telefono_']) ? trim($_REQUEST['telefono_']) : ($jsonInput['telefono'] ?? ($jsonInput['telefono_'] ?? '')));
$titulo   = isset($_REQUEST['titulo'])   ? trim($_REQUEST['titulo'])   : (isset($_REQUEST['titulo_'])   ? trim($_REQUEST['titulo_'])   : ($jsonInput['titulo']   ?? ($jsonInput['titulo_']   ?? 'GeoMove')));
$mensaje  = isset($_REQUEST['mensaje'])  ? trim($_REQUEST['mensaje'])  : (isset($_REQUEST['mensaje_'])  ? trim($_REQUEST['mensaje_'])  : ($jsonInput['mensaje']  ?? ($jsonInput['mensaje_']  ?? 'Tienes una nueva notificacion')));
$dataRaw  = isset($_REQUEST['data_json']) ? trim($_REQUEST['data_json']) : (isset($_REQUEST['data_json_']) ? trim($_REQUEST['data_json_']) : ($jsonInput['data_json'] ?? ($jsonInput['data_json_'] ?? '')));

if ($telefono === '') {
    echo json_encode([
        "status" => "error", 
        "message" => "Telefono requerido",
        "debug_request" => $_REQUEST,
        "debug_post" => $_POST,
        "debug_get" => $_GET
    ]);
    exit;
}

if (!file_exists($serviceAccountPath)) {
    echo json_encode([
        "status" => "error",
        "message" => "Falta firebase_service_account.json en server_php",
    ]);
    exit;
}

// Funciones y lógica principal consolidada en el bloque try inferior

// Intentamos buscar en usuarios (Pasajero)
$stmt = $conn->prepare("SELECT token_fcm FROM usuarios WHERE telefono = ? LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$result = $stmt->get_result();
$tokenFcm = $row['token_fcm'] ?? '';
$sourceTable = "usuarios";

// Si no está en usuarios, intentamos buscar en conductores (Conductor)
if ($tokenFcm === '') {
    $stmtC = $conn->prepare("SELECT token_fcm FROM conductores WHERE telefono = ? LIMIT 1");
    $stmtC->bind_param("s", $telefono);
    $stmtC->execute();
    $resultC = $stmtC->get_result();
    $rowC = $resultC->fetch_assoc();
    $stmtC->close();
    $tokenFcm = $rowC['token_fcm'] ?? '';
    $sourceTable = "conductores";
}

$conn->close();

if ($tokenFcm === '') {
    echo json_encode([
        "status" => "error",
        "message" => "El numero $telefono no tiene token_fcm registrado (no encontrado en usuarios ni conductores)",
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
    // ── Helpers FCM v1 (Exact Copy from solicitar_viaje.php) ─────
    function _b64u($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    function _fcmAccessToken($path) {
        $creds = json_decode(file_get_contents($path), true);
        $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now     = time();
        $claims  = [
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];
        $input = _b64u(json_encode($header)) . '.' . _b64u(json_encode($claims));
        $key   = openssl_pkey_get_private($creds['private_key']);
        openssl_sign($input, $sig, $key, 'sha256WithRSAEncryption');
        $jwt = $input . '.' . _b64u($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            $debug_info = "Email: " . substr($creds['client_email'], 0, 5) . "... (Len: ".strlen($creds['client_email']).") | PrivateKey Len: " . strlen($creds['private_key']);
            throw new Exception("Token OAuth fallido ($code): $res | Debug: $debug_info");
        }
        $json = json_decode($res, true);
        return [$json['access_token'], $creds['project_id']];
    }

    list($accessToken, $projectId) = _fcmAccessToken($serviceAccountPath);

    $message = [
        'token' => $tokenFcm,
        'data' => array_merge([
            'title' => $titulo,
            'body' => $mensaje,
        ], $customData),
        'android' => [
            'priority' => 'high',
        ],
    ];

    $payload = [
        'message' => $message,
    ];

    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
        CURLOPT_POSTFIELDS => json_encode(['message' => $message]),
    ]);
    
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
      $tokenDebug = "Table: " . $sourceTable . " | Token: " . substr($tokenFcm, 0, 10) . "...";
      echo json_encode([
          "status" => "error",
          "message" => "FCM respondio HTTP " . $httpCode . " | " . $response . " | Debug: " . $tokenDebug,
      ]);
    }
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error critico: " . $e->getMessage() . " | Server Time: " . date("Y-m-d H:i:s"),
    ]);
}
?>

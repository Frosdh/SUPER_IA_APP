<?php
// ============================================================
// fcm_helper.php - Funciones centralizadas para notificaciones FCM
// ============================================================

function _b64u($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Obtiene el Access Token de Firebase OAuth2 usando el JSON de cuenta de servicio.
 */
function _fcmAccessToken($path) {
    if (!file_exists($path)) return [null, null];
    
    $creds = json_decode(file_get_contents($path), true);
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    $claims = [
        'iss' => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ];
    $input = _b64u(json_encode($header)) . '.' . _b64u(json_encode($claims));
    $key = openssl_pkey_get_private($creds['private_key']);
    openssl_sign($input, $sig, $key, 'sha256WithRSAEncryption');
    $jwt = $input . '.' . _b64u($sig);
    
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt
        ])
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    return [$json['access_token'] ?? null, $creds['project_id'] ?? null];
}

/**
 * Envía una notificación FCM v1 a un token específico.
 */
function _sendFcm($accessToken, $projectId, $token, $title, $body, $data = []) {
    if (!$accessToken || !$projectId) return [500, "Faltan credenciales"];
    
    $message = [
        'token' => (string)$token,
        'data' => array_merge([
            'title' => (string)$title,
            'body' => (string)$body,
        ], array_map('strval', $data)),
        'android' => [
            'priority' => 'high'
        ]
    ];
    
    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8'
        ],
        CURLOPT_POSTFIELDS => json_encode(['message' => $message])
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

/**
 * Registra un evento de FCM en la base de datos para depuración.
 */
function _log_fcm_to_db($conn, $viajeId, $conductorId, $token, $code, $response) {
    try {
        if (!$conn || $conn->connect_error) return;
        $stmt = $conn->prepare("
            INSERT INTO fcm_debug_logs (viaje_id, conductor_id, token_fcm, response_code, response_text) 
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("iisis", $viajeId, $conductorId, $token, $code, $response);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {}
}
?>

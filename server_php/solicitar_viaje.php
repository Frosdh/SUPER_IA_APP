<?php
require_once __DIR__ . '/db_config.php';
ignore_user_abort(true);
set_time_limit(300);

// ============================================================
// solicitar_viaje.php - Crea un viaje nuevo con estado 'pedido'
// y envía notificación FCM a conductores libres cercanos.
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$telefono      = isset($_POST['telefono'])      ? trim($_POST['telefono'])           : '';
$origen_texto  = isset($_POST['origen_texto'])  ? trim($_POST['origen_texto'])       : '';
$destino_texto = isset($_POST['destino_texto']) ? trim($_POST['destino_texto'])      : '';
$distancia_km  = isset($_POST['distancia_km'])  ? floatval($_POST['distancia_km'])   : 0;
$duracion_min  = isset($_POST['duracion_min'])  ? intval($_POST['duracion_min'])     : 0;
$tarifa_total  = isset($_POST['tarifa_total'])  ? floatval($_POST['tarifa_total'])   : 0;
$origen_lat    = isset($_POST['origen_lat'])    ? floatval($_POST['origen_lat'])     : null;
$origen_lng    = isset($_POST['origen_lng'])    ? floatval($_POST['origen_lng'])     : null;
$destino_lat   = isset($_POST['destino_lat'])   ? floatval($_POST['destino_lat'])    : null;
$destino_lng   = isset($_POST['destino_lng'])   ? floatval($_POST['destino_lng'])    : null;

if (empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
    exit;
}

// Buscar usuario por teléfono
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? AND activo = 1 LIMIT 1");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$stmt->bind_result($usuario_id);
$usuarioEncontrado = $stmt->fetch();
$stmt->close();

if (!$usuarioEncontrado) {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    $conn->close();
    exit;
}

$categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 1;

// Crear el viaje con estado 'pedido'
$insert = $conn->prepare("
    INSERT INTO viajes
        (usuario_id, conductor_id, categoria_id,
         origen_texto, destino_texto,
         origen_lat, origen_lng, destino_lat, destino_lng,
         distancia_km, duracion_min, tarifa_total,
         estado, fecha_pedido)
    VALUES
        (?, NULL, ?,
         ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?,
         'pedido', NOW())
");

$insert->bind_param(
    "iissdddddid",
    $usuario_id, $categoria_id,
    $origen_texto, $destino_texto,
    $origen_lat, $origen_lng, $destino_lat, $destino_lng,
    $distancia_km, $duracion_min, $tarifa_total
);

if (!$insert->execute()) {
    echo json_encode([
        "status"  => "error",
        "message" => "Error al crear viaje: " . $insert->error
    ]);
    $insert->close();
    $conn->close();
    exit;
}

$viaje_id = $conn->insert_id;
$insert->close();

// ─── RESPUESTA RÁPIDA AL CLIENTE ─────────────────────────────────────────────
// Enviamos el ID al pasajero de inmediato y seguimos procesando FCM en background.
$response = [
    "status"   => "success",
    "message"  => "Viaje creado correctamente",
    "viaje_id" => (int)$viaje_id
];
ob_start();
echo json_encode($response);
$size = ob_get_length();
header("Content-Length: $size");
header("Connection: close");
header("Content-Type: application/json");
ob_end_flush();
ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
// ─────────────────────────────────────────────────────────────────────────────

// ─── Notificaciones FCM a conductores libres cercanos ───────────────────────

$fcmEnviados = 0;
$fcmErrores  = [];

// Buscar conductores libres con token_fcm.
// Si el pasajero envió coordenadas, filtramos por radio de 6 km usando Haversine.
// Si no hay coordenadas, notificamos a todos los libres con token.
if ($origen_lat !== null && $origen_lng !== null) {
    $radioKm = 6.0;
    $sqlConductores = "
        SELECT id, nombre, token_fcm,
            (6371 * ACOS(
                COS(RADIANS(?)) * COS(RADIANS(latitud)) *
                COS(RADIANS(longitud) - RADIANS(?)) +
                SIN(RADIANS(?)) * SIN(RADIANS(latitud))
            )) AS distancia_km
        FROM conductores
        WHERE estado = 'libre'
          AND token_fcm IS NOT NULL
          AND token_fcm <> ''
          AND latitud IS NOT NULL
          AND longitud IS NOT NULL
          AND (categoria_id = ? OR ? = 0)
        HAVING distancia_km <= ?
        ORDER BY distancia_km ASC
        LIMIT 20
    ";
    $stmtC = $conn->prepare($sqlConductores);
    $stmtC->bind_param("dddiid", $origen_lat, $origen_lng, $origen_lat, $categoria_id, $categoria_id, $radioKm);
} else {
    $sqlConductores = "
        SELECT id, nombre, token_fcm
        FROM conductores
        WHERE estado = 'libre'
          AND token_fcm IS NOT NULL
          AND token_fcm <> ''
          AND (categoria_id = ? OR ? = 0)
        ORDER BY id ASC
        LIMIT 20
    ";
    $stmtC = $conn->prepare($sqlConductores);
    $stmtC->bind_param("ii", $categoria_id, $categoria_id);
}

$stmtC->execute();
$resConductores = $stmtC->get_result();
$conductoresLibres = $resConductores->fetch_all(MYSQLI_ASSOC);
$stmtC->close();
$conn->close();

// Enviar FCM solo si hay conductores y existe el archivo de credenciales
$serviceAccountPath = __DIR__ . '/firebase_service_account.json';

if (!empty($conductoresLibres) && file_exists($serviceAccountPath)) {

    // ── Helpers FCM v1 ───────────────────────────────────────────
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
        if ($code !== 200) throw new Exception("Token OAuth fallido: $res");
        $json = json_decode($res, true);
        return [$json['access_token'], $creds['project_id']];
    }

    function _sendFcm($accessToken, $projectId, $token, $title, $body, $data = []) {
        $message = [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'android'      => [
                'priority'     => 'high',
                'notification' => [
                    'channel_id'              => 'high_importance_channel',
                    'notification_priority'   => 'PRIORITY_MAX',
                    'default_vibrate_timings' => true,
                    'default_sound'           => true,
                ],
            ],
        ];
        if (!empty($data)) {
            $message['data'] = (object) array_map('strval', $data);
        }
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
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $res];
    }
    // ─────────────────────────────────────────────────────────────

    try {
        list($accessToken, $projectId) = _fcmAccessToken($serviceAccountPath);

        $titulo  = '¡Nuevo viaje disponible!';
        $cuerpo  = "De: {$origen_texto}\nHacia: {$destino_texto}\n\$" . number_format($tarifa_total, 2);
        $dataMap = [
            'tipo'          => 'nuevo_viaje',
            'viaje_id'      => (string) $viaje_id,
            'origen_texto'  => $origen_texto,
            'destino_texto' => $destino_texto,
            'tarifa_total'  => (string) $tarifa_total,
            'origen_lat'    => $origen_lat  !== null ? (string) $origen_lat  : '',
            'origen_lng'    => $origen_lng  !== null ? (string) $origen_lng  : '',
        ];

        foreach ($conductoresLibres as $conductor) {
            list($httpCode, ) = _sendFcm(
                $accessToken, $projectId,
                $conductor['token_fcm'],
                $titulo, $cuerpo, $dataMap
            );
            if ($httpCode >= 200 && $httpCode < 300) {
                $fcmEnviados++;
            } else {
                $fcmErrores[] = "conductor_id={$conductor['id']} HTTP=$httpCode";
            }
        }
    } catch (Exception $e) {
        $fcmErrores[] = $e->getMessage();
    }
}

// El script continúa aquí enviando notificaciones FCM sin que el usuario espere.
?>

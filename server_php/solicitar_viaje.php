<?php
require_once __DIR__ . '/db_config.php';
ignore_user_abort(true);
set_time_limit(300);

// ============================================================
// solicitar_viaje.php - Versión de DEPURECIÓN ROBUSTA
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

// --- LOGGING EN BASE DE DATOS ---
function _log_to_db($conn, $viajeId, $conductorId, $token, $code, $response) {
    try {
        if (!$conn || $conn->connect_error) {
            // No podemos loguear si no hay conexión
            return;
        }
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

_log_to_db($conn, 0, 0, 'SISTEMA', 200, "Script iniciado via POST");

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
$categoria_id  = isset($_POST['categoria_id'])  ? intval($_POST['categoria_id'])     : 1;

if (empty($telefono)) {
    _log_to_db($conn, 0, 0, 'SISTEMA', 400, "ERROR: Falta parametro telefono en POST");
    echo json_encode(["status" => "error", "message" => "El telefono es requerido"]);
    exit;
}

// 1. Buscar usuario
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE telefono = ? AND activo = 1 LIMIT 1");
if (!$stmt) {
    _log_to_db($conn, 0, 0, 'SISTEMA', 500, "ERROR SQL Prepare Usuario: " . $conn->error);
} else {
    $stmt->bind_param("s", $telefono);
    $stmt->execute();
    $stmt->bind_result($usuario_id);
    $usuarioEncontrado = $stmt->fetch();
    $stmt->close();
}

if (!$usuarioEncontrado) {
    _log_to_db($conn, 0, 0, 'SISTEMA', 404, "ERROR: Usuario con tel $telefono no encontrado o inactivo");
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    $conn->close();
    exit;
}

_log_to_db($conn, 0, 0, 'SISTEMA', 200, "Usuario ID $usuario_id validado. Creando registro de viaje...");

// 2. Crear viaje
$insert = $conn->prepare("
    INSERT INTO viajes
        (usuario_id, conductor_id, categoria_id, origen_texto, destino_texto,
         origen_lat, origen_lng, destino_lat, destino_lng,
         distancia_km, duracion_min, tarifa_total, estado, fecha_pedido)
    VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pedido', NOW())
");

if (!$insert) {
    _log_to_db($conn, 0, 0, 'SISTEMA', 500, "ERROR SQL Prepare Viajes: " . $conn->error);
} else {
    $insert->bind_param("iissdddddid", $usuario_id, $categoria_id, $origen_texto, $destino_texto, $origen_lat, $origen_lng, $destino_lat, $destino_lng, $distancia_km, $duracion_min, $tarifa_total);
    if (!$insert->execute()) {
        _log_to_db($conn, 0, 0, 'SISTEMA', 500, "ERROR SQL Execute Viajes: " . $insert->error);
    }
    $viaje_id = $conn->insert_id;
    $insert->close();
}

if (!isset($viaje_id) || $viaje_id <= 0) {
    _log_to_db($conn, 0, 0, 'SISTEMA', 500, "FATAL: No se pudo crear el viaje en la DB.");
    echo json_encode(["status" => "error", "message" => "Error al crear viaje"]);
    exit;
}

_log_to_db($conn, $viaje_id, 0, 'SISTEMA', 200, "Viaje ID $viaje_id guardado. Respondiendo al app y buscando conductores...");

// --- RESPUESTA RÁPIDA (Background) ---
$response = ["status" => "success", "message" => "Viaje creado", "viaje_id" => (int)$viaje_id];
ob_start();
echo json_encode($response);
$size = ob_get_length();
header("Content-Length: $size");
header("Connection: close");
ob_end_flush();
ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// 3. Buscar conductores
$radioKm = 6.0;
if ($origen_lat !== null && $origen_lng !== null) {
    $sqlC = "SELECT c.id, c.nombre, c.token_fcm, 
             (6371 * ACOS(COS(RADIANS(?)) * COS(RADIANS(c.latitud)) * COS(RADIANS(c.longitud) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(c.latitud)))) AS dist 
             FROM conductores c
             LEFT JOIN vehiculos v ON v.conductor_id = c.id
             WHERE c.estado = 'libre' AND c.token_fcm IS NOT NULL AND c.token_fcm <> '' AND c.latitud IS NOT NULL AND c.longitud IS NOT NULL 
             AND (v.categoria_id = ? OR ? = 0)
             AND NOT EXISTS (SELECT 1 FROM solicitud_viajes sv WHERE sv.viaje_id = ? AND sv.conductor_id = c.id AND sv.estado = 'rechazado')
             HAVING dist <= ? ORDER BY dist ASC LIMIT 15";
    $stmtC = $conn->prepare($sqlC);
    if ($stmtC) { $stmtC->bind_param("dddiidi", $origen_lat, $origen_lng, $origen_lat, $categoria_id, $categoria_id, $viaje_id, $radioKm); }
} else {
    $sqlC = "SELECT c.id, c.nombre, c.token_fcm 
             FROM conductores c
             LEFT JOIN vehiculos v ON v.conductor_id = c.id
             WHERE c.estado = 'libre' AND c.token_fcm IS NOT NULL AND c.token_fcm <> '' 
             AND (v.categoria_id = ? OR ? = 0)
             AND NOT EXISTS (SELECT 1 FROM solicitud_viajes sv WHERE sv.viaje_id = ? AND sv.conductor_id = c.id AND sv.estado = 'rechazado')
             ORDER BY c.id ASC LIMIT 15";
    $stmtC = $conn->prepare($sqlC);
    if ($stmtC) { $stmtC->bind_param("iii", $categoria_id, $categoria_id, $viaje_id); }
}

if (!$stmtC) {
    _log_to_db($conn, $viaje_id, 0, 'SISTEMA', 500, "ERROR SQL Prepare Conductores: " . $conn->error);
    exit;
}

$stmtC->execute();
$resC = $stmtC->get_result();
$conductores = $resC->fetch_all(MYSQLI_ASSOC);
$stmtC->close();

_log_to_db($conn, $viaje_id, 0, 'SISTEMA', 200, "Conductores libres encontrados: " . count($conductores));

// 4. Notificaciones FCM
$serviceAccountPath = __DIR__ . '/firebase_service_account.json';
if (!empty($conductores) && file_exists($serviceAccountPath)) {
    
    function _b64u($data) { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
    function _fcmAccessToken($path) {
        $creds = json_decode(file_get_contents($path), true);
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $claims = [
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600, 'iat' => $now
        ];
        $input = _b64u(json_encode($header)) . '.' . _b64u(json_encode($claims));
        $key = openssl_pkey_get_private($creds['private_key']);
        openssl_sign($input, $sig, $key, 'sha256WithRSAEncryption');
        $jwt = $input . '.' . _b64u($sig);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion'  => $jwt])
        ]);
        $res = curl_exec($ch); curl_close($ch);
        $json = json_decode($res, true);
        return [$json['access_token'] ?? null, $creds['project_id']];
    }

    function _sendFcm($accessToken, $projectId, $token, $title, $body, $data = []) {
        $message = ['token' => (string)$token, 'data' => array_merge(['title' => (string)$title, 'body' => (string)$body], array_map('strval', $data)), 'android' => ['priority' => 'high']];
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json; charset=UTF-8'],
            CURLOPT_POSTFIELDS => json_encode(['message' => $message])
        ]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $res];
    }

    try {
        list($accessToken, $projectId) = _fcmAccessToken($serviceAccountPath);
        if (!$accessToken) {
            _log_to_db($conn, $viaje_id, 0, 'SISTEMA', 500, "ERROR: No token FCM OAuth2");
        } else {
            $titulo = '¡Nuevo viaje disponible!';
            $cuerpo = "Rutas: $origen_texto -> $destino_texto";
            foreach ($conductores as $c) {
                list($status, $res) = _sendFcm($accessToken, $projectId, $c['token_fcm'], $titulo, $cuerpo, ['tipo' => 'nuevo_viaje', 'viaje_id' => (string)$viaje_id]);
                _log_to_db($conn, $viaje_id, $c['id'], $c['token_fcm'], $status, $res);
            }
        }
    } catch (Exception $e) {
        _log_to_db($conn, $viaje_id, 0, 'ERROR_CODE', 0, $e->getMessage());
    }
}

$conn->close();
?>

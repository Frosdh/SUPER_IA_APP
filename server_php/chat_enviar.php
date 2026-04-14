<?php
// ============================================================
// chat_enviar.php — Guarda un mensaje de chat y notifica al otro
// POST JSON: { "viaje_id": 123, "remitente": "pasajero", "mensaje": "Hola",
//              "nombre_remitente": "Ana" }
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/fcm_helper.php';

$input    = json_decode(file_get_contents("php://input"), true);
$viaje_id         = (int)(  $input['viaje_id']         ?? $_POST['viaje_id']         ?? 0);
$remitente        = trim(   $input['remitente']         ?? $_POST['remitente']         ?? '');
$mensaje          = trim(   $input['mensaje']           ?? $_POST['mensaje']           ?? '');
$nombre_remitente = trim(   $input['nombre_remitente']  ?? $_POST['nombre_remitente']  ?? '');

// ── Validaciones ──────────────────────────────────────────
if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id inválido"]); exit;
}
if (!in_array($remitente, ['pasajero', 'conductor'])) {
    echo json_encode(["status" => "error", "message" => "remitente inválido"]); exit;
}
if (mb_strlen($mensaje) === 0) {
    echo json_encode(["status" => "error", "message" => "mensaje vacío"]); exit;
}
if (mb_strlen($mensaje) > 1000) {
    echo json_encode(["status" => "error", "message" => "mensaje demasiado largo"]); exit;
}

// ── Guardar mensaje ───────────────────────────────────────
$stmt = $conn->prepare("INSERT INTO chat_mensajes (viaje_id, remitente, mensaje) VALUES (?, ?, ?)");
$stmt->bind_param("iss", $viaje_id, $remitente, $mensaje);

if (!$stmt->execute()) {
    $stmt->close(); $conn->close();
    echo json_encode(["status" => "error", "message" => "No se pudo guardar el mensaje"]); exit;
}
$nuevo_id = $stmt->insert_id;
$stmt->close();

// ── Responder al app de inmediato ─────────────────────────
echo json_encode([
    "status"     => "success",
    "message"    => "Mensaje enviado",
    "mensaje_id" => $nuevo_id,
]);

// Cerrar output para que el app no espere el FCM
if (ob_get_level() > 0) {
    $size = ob_get_length();
    header("Content-Length: $size");
    ob_end_flush();
}
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

// ── Buscar token FCM del OTRO (en background) ─────────────
// Si quien habla es el pasajero → notificar al conductor, y viceversa
try {
    $token_otro  = null;
    $nombre_otro = 'GeoMove';

    if ($remitente === 'pasajero') {
        // Buscar token del conductor asignado a este viaje
        $s = $conn->prepare("
            SELECT c.token_fcm, c.nombre
            FROM viajes v
            JOIN conductores c ON c.id = v.conductor_id
            WHERE v.id = ? AND c.token_fcm IS NOT NULL AND c.token_fcm <> ''
            LIMIT 1
        ");
        $s->bind_param("i", $viaje_id);
        $s->execute();
        $s->bind_result($token_otro, $nombre_otro);
        $s->fetch();
        $s->close();
    } else {
        // Buscar token del pasajero de este viaje
        $s = $conn->prepare("
            SELECT u.token_fcm, u.nombre
            FROM viajes v
            JOIN usuarios u ON u.id = v.usuario_id
            WHERE v.id = ? AND u.token_fcm IS NOT NULL AND u.token_fcm <> ''
            LIMIT 1
        ");
        $s->bind_param("i", $viaje_id);
        $s->execute();
        $s->bind_result($token_otro, $nombre_otro);
        $s->fetch();
        $s->close();
    }

    if (!empty($token_otro)) {
        // Nombre del remitente para mostrar en la notificación
        $titulo_notif = empty($nombre_remitente)
            ? ($remitente === 'pasajero' ? 'Pasajero' : 'Conductor')
            : $nombre_remitente;

        // Mensaje corto para la notificación (máx 80 chars)
        $preview = mb_strlen($mensaje) > 80 ? mb_substr($mensaje, 0, 77) . '...' : $mensaje;

        list($accessToken, $projectId) = _fcmAccessToken(__DIR__ . '/firebase_service_account.json');
        if ($accessToken) {
            _sendFcm(
                $accessToken,
                $projectId,
                $token_otro,
                $titulo_notif,       // título = nombre de quien escribe
                $preview,            // cuerpo = el mensaje
                [
                    'tipo'      => 'chat_mensaje',
                    'viaje_id'  => (string)$viaje_id,
                    'remitente' => $remitente,
                    'nombre'    => $titulo_notif,
                    'mensaje'   => $preview,
                ]
            );
        }
    }
} catch (Exception $e) { /* No bloquear nunca */ }

$conn->close();
?>

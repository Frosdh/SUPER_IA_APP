<?php
// ============================================================
// aceptar_viaje.php - Permite al conductor aceptar una solicitud
// ============================================================
header("Content-Type: application/json");

require_once __DIR__ . '/db_config.php';

$viaje_id = $_POST['viaje_id'] ?? 0;
$conductor_id = $_POST['conductor_id'] ?? 0;

if ($viaje_id == 0 || $conductor_id == 0) {
    echo json_encode(["status" => "error", "message" => "Datos incompletos"]);
    exit;
}

// 1. Verificar si el viaje sigue disponible (estado 'pedido')
$sql_check = "SELECT estado FROM viajes WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param("i", $viaje_id);
$stmt->execute();
$result = $stmt->get_result();
$viaje = $result->fetch_assoc();

if (!$viaje) {
    echo json_encode(["status" => "error", "message" => "Viaje no existe"]);
    exit;
}

if ($viaje['estado'] !== 'pedido') {
    echo json_encode(["status" => "error", "message" => "El viaje ya fue tomado por otro conductor"]);
    exit;
}

// 2. Asignar el viaje al conductor
$sql_update = "UPDATE viajes SET 
                conductor_id = ?, 
                estado = 'aceptado',
                fecha_aceptacion = NOW() 
               WHERE id = ? AND estado = 'pedido'";

$stmt_up = $conn->prepare($sql_update);
$stmt_up->bind_param("ii", $conductor_id, $viaje_id);

if ($stmt_up->execute() && $stmt_up->affected_rows > 0) {
    // 3. Notificar al pasajero y limpiar a otros conductores
    try {
        require_once __DIR__ . '/fcm_helper.php';
        
        // Obtener token del pasajero y datos del conductor
        $sql_data = "SELECT u.token_fcm as user_token, c.nombre as conductor_nombre 
                     FROM viajes v
                     JOIN usuarios u ON v.usuario_id = u.id
                     JOIN conductores c ON v.conductor_id = c.id
                     WHERE v.id = ? LIMIT 1";
        $stmt_data = $conn->prepare($sql_data);
        $stmt_data->bind_param("i", $viaje_id);
        $stmt_data->execute();
        $res_data = $stmt_data->get_result()->fetch_assoc();
        
        if ($res_data && !empty($res_data['user_token'])) {
            list($token, $proj) = _fcmAccessToken(__DIR__ . '/firebase_service_account.json');
            if ($token) {
                // Notificar al Pasajero
                _sendFcm($token, $proj, $res_data['user_token'], "¡Viaje Aceptado! 🚕", 
                         "{$res_data['conductor_nombre']} va en camino a tu ubicación.", 
                         ['viaje_id' => $viaje_id, 'estado' => 'aceptado']);
            }
        }

        // Limpiar solicitud para otros conductores (opcional pero recomendado)
        $sql_others = "SELECT token_fcm FROM fcm_debug_logs WHERE viaje_id = ? AND conductor_id <> ? GROUP BY token_fcm";
        $stmt_o = $conn->prepare($sql_others);
        $stmt_o->bind_param("ii", $viaje_id, $conductor_id);
        $stmt_o->execute();
        $res_o = $stmt_o->get_result();
        
        list($token, $proj) = _fcmAccessToken(__DIR__ . '/firebase_service_account.json');
        while ($row_o = $res_o->fetch_assoc()) {
            if (!empty($row_o['token_fcm'])) {
                _sendFcm($token, $proj, $row_o['token_fcm'], "Limpiar", "Solicitud tomada", 
                         ['action' => 'limpiar_solicitud', 'viaje_id' => $viaje_id]);
            }
        }

    } catch (Exception $e) {
        // No bloquear la respuesta principal si falla el FCM
    }

    echo json_encode([
        "status" => "success", 
        "message" => "Viaje aceptado correctamente",
        "viaje_id" => $viaje_id
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "No se pudo asignar el viaje"]);
}
$conn->close();
?>
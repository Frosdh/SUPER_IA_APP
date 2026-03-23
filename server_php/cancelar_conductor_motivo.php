<?php
// ============================================================
// cancelar_conductor_motivo.php - El conductor cancela un viaje aceptado
// ============================================================
header("Content-Type: application/json");

require_once __DIR__ . '/db_config.php';

$viaje_id = $_POST['viaje_id'] ?? 0;
$conductor_id = $_POST['conductor_id'] ?? 0;
$motivo = $_POST['motivo'] ?? 'No especificado';

if ($viaje_id == 0 || $conductor_id == 0) {
    echo json_encode(["status" => "error", "message" => "Datos incompletos"]);
    exit;
}

// 1. Verificar que el viaje está asignado a este conductor y en estado 'aceptado' o 'en_camino'
$sql_check = "SELECT estado FROM viajes WHERE id = ? AND conductor_id = ? LIMIT 1";
$stmt = $conn->prepare($sql_check);
$stmt->bind_param("ii", $viaje_id, $conductor_id);
$stmt->execute();
$viaje = $stmt->get_result()->fetch_assoc();

if (!$viaje) {
    echo json_encode(["status" => "error", "message" => "Viaje no encontrado o no asignado"]);
    exit;
}

if ($viaje['estado'] === 'iniciado') {
    echo json_encode(["status" => "error", "message" => "No se puede cancelar un viaje que ya comenzó"]);
    exit;
}

$conn->begin_transaction();
try {
    // 2. Liberar el viaje: volver a 'pedido' y quitar el conductor_id
    $sql_liberar = "UPDATE viajes SET conductor_id = NULL, estado = 'pedido' WHERE id = ?";
    $stmt_lib = $conn->prepare($sql_liberar);
    $stmt_lib->bind_param("i", $viaje_id);
    $stmt_lib->execute();

    // 3. Registrar el rechazo en solicitud_viajes para que NO vuelva a ver este viaje
    $sql_rechazo = "INSERT INTO solicitud_viajes (conductor_id, viaje_id, estado) 
                    VALUES (?, ?, 'rechazado') 
                    ON DUPLICATE KEY UPDATE estado = 'rechazado'";
    $stmt_rech = $conn->prepare($sql_rechazo);
    $stmt_rech->bind_param("ii", $conductor_id, $viaje_id);
    $stmt_rech->execute();

    // 4. (Opcional) Log del motivo en fcm_debug_logs o tabla de auditoría
    $stmt_log = $conn->prepare("INSERT INTO fcm_debug_logs (viaje_id, conductor_id, response_text, token_fcm) VALUES (?, ?, ?, 'CANCELACION_MOTIVO')");
    $log_msg = "Cancelado por conductor. Motivo: " . $motivo;
    $stmt_log->bind_param("iis", $viaje_id, $conductor_id, $log_msg);
    $stmt_log->execute();

    $conn->commit();

    // 5. Notificar al pasajero que estamos buscando otro conductor
    try {
        require_once __DIR__ . '/fcm_helper.php';
        $sql_usr = "SELECT u.token_fcm FROM viajes v JOIN usuarios u ON v.usuario_id = u.id WHERE v.id = ? LIMIT 1";
        $stmt_u = $conn->prepare($sql_usr);
        $stmt_u->bind_param("i", $viaje_id);
        $stmt_u->execute();
        $res_u = $stmt_u->get_result()->fetch_assoc();
        
        if ($res_u && !empty($res_u['token_fcm'])) {
            list($token, $proj) = _fcmAccessToken(__DIR__ . '/firebase_service_account.json');
            if ($token) {
                _sendFcm($token, $proj, $res_u['token_fcm'], "Cambio en tu viaje 🔄", 
                         "Tu conductor anterior tuvo un inconveniente. Estamos buscando uno nuevo para ti.", 
                         ['viaje_id' => $viaje_id, 'estado' => 'pedido']);
            }
        }
    } catch (Exception $e) {}

    echo json_encode([
        "status" => "success",
        "message" => "Viaje liberado y puesto en búsqueda nuevamente",
        "viaje_id" => $viaje_id
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Error interno: " . $e->getMessage()]);
}

$conn->close();
?>

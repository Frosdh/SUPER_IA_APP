<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/email_helper.php';

// ============================================================
// actualizar_estado_viaje.php - Actualiza el estado de un viaje
// (conductor: en_camino, iniciado, terminado)
// Colocar en: /fuber_api/actualizar_estado_viaje.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
$viajeId = isset($_POST['viaje_id']) ? intval($_POST['viaje_id']) : 0;
$estado = isset($_POST['estado']) ? trim($_POST['estado']) : '';

$allowed = ['en_camino', 'iniciado', 'terminado'];
if ($conductorId <= 0 || $viajeId <= 0 || !in_array($estado, $allowed, true)) {
    echo json_encode(["status" => "error", "message" => "Datos invalidos"]);
    exit;
}

// Validar que el viaje pertenece al conductor y que no esta cancelado/terminado.
$stmtCheck = $conn->prepare("
    SELECT estado
    FROM viajes
    WHERE id = ? AND conductor_id = ?
    LIMIT 1
");
$stmtCheck->bind_param("ii", $viajeId, $conductorId);
$stmtCheck->execute();
$stmtCheck->bind_result($estadoActual);
$existe = $stmtCheck->fetch();
$stmtCheck->close();

if (!$existe) {
    echo json_encode(["status" => "error", "message" => "Viaje no encontrado o no asignado al conductor"]);
    $conn->close();
    exit;
}

if ($estadoActual === 'cancelado' || $estadoActual === 'terminado') {
    echo json_encode(["status" => "error", "message" => "No se puede actualizar (viaje $estadoActual)"]);
    $conn->close();
    exit;
}

$conn->begin_transaction();
try {
    if ($estado === 'terminado') {
        $stmt = $conn->prepare("
            UPDATE viajes
            SET estado = 'terminado', fecha_fin = NOW()
            WHERE id = ? AND conductor_id = ?
        ");
        $stmt->bind_param("ii", $viajeId, $conductorId);
        $stmt->execute();
        $stmt->close();

        // Liberar al conductor para que reciba nuevos viajes.
        $stmtDriver = $conn->prepare("UPDATE conductores SET estado = 'libre' WHERE id = ?");
        $stmtDriver->bind_param("i", $conductorId);
        $stmtDriver->execute();
        $stmtDriver->close();

        // ── Enviar recibo por email al pasajero ──────────────────────────
        $stmtRecibo = $conn->prepare("
            SELECT
                v.id, v.origen_texto, v.destino_texto,
                v.distancia_km, v.duracion_min, v.tarifa_total,
                v.descuento, v.codigo_descuento, v.fecha_pedido,
                u.nombre  AS pasajero_nombre,
                u.email   AS pasajero_email,
                c.nombre  AS conductor_nombre,
                ve.marca, ve.modelo, ve.color, ve.placa
            FROM viajes v
            JOIN usuarios    u  ON u.id  = v.usuario_id
            JOIN conductores c  ON c.id  = v.conductor_id
            LEFT JOIN vehiculos ve ON ve.conductor_id = v.conductor_id
            WHERE v.id = ?
            LIMIT 1
        ");
        $stmtRecibo->bind_param("i", $viajeId);
        $stmtRecibo->execute();
        $rowRecibo = $stmtRecibo->get_result()->fetch_assoc();
        $stmtRecibo->close();

        if ($rowRecibo && !empty($rowRecibo['pasajero_email'])) {
            $vehiculoDesc = trim(
                ($rowRecibo['marca'] ?? '') . ' ' .
                ($rowRecibo['modelo'] ?? '') . ' ' .
                ($rowRecibo['color']  ?? '')
            );
            $receiptData = [
                'viaje_id'        => $rowRecibo['id'],
                'pasajero'        => $rowRecibo['pasajero_nombre'],
                'conductor'       => $rowRecibo['conductor_nombre'],
                'origen'          => $rowRecibo['origen_texto'],
                'destino'         => $rowRecibo['destino_texto'],
                'distancia'       => $rowRecibo['distancia_km'],
                'duracion'        => $rowRecibo['duracion_min'],
                'tarifa'          => $rowRecibo['tarifa_total'],
                'descuento'       => $rowRecibo['descuento'],
                'codigo_descuento'=> $rowRecibo['codigo_descuento'],
                'placa'           => $rowRecibo['placa'] ?? '-',
                'vehiculo'        => $vehiculoDesc ?: '-',
                'fecha'           => date('d/m/Y H:i', strtotime($rowRecibo['fecha_pedido'])),
            ];
            $asunto   = "Recibo de tu viaje #" . $rowRecibo['id'] . " – GeoMove";
            $htmlBody = buildReceiptEmailHtml($receiptData);
            $textBody = buildReceiptEmailText($receiptData);
            // Envío en background — no bloquea la respuesta al conductor
            sendEmailMessage($rowRecibo['pasajero_email'], $asunto, $htmlBody, $textBody);
        }
        // ────────────────────────────────────────────────────────────────
    } else {
        $stmt = $conn->prepare("
            UPDATE viajes
            SET estado = ?
            WHERE id = ? AND conductor_id = ?
        ");
        $stmt->bind_param("sii", $estado, $viajeId, $conductorId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    // ── NOTIFICAR AL PASAJERO EN TIEMPO REAL ─────────────────────────
    try {
        require_once __DIR__ . '/fcm_helper.php';
        $sql_usr = "SELECT u.token_fcm FROM viajes v JOIN usuarios u ON v.usuario_id = u.id WHERE v.id = ? LIMIT 1";
        $stmt_u = $conn->prepare($sql_usr);
        $stmt_u->bind_param("i", $viajeId);
        $stmt_u->execute();
        $res_u = $stmt_u->get_result()->fetch_assoc();
        
        if ($res_u && !empty($res_u['token_fcm'])) {
            list($token, $proj) = _fcmAccessToken(__DIR__ . '/firebase_service_account.json');
            if ($token) {
                $titulo = "Actualización de Viaje";
                $mensaje = "El estado de tu viaje ha cambiado a $estado";
                
                if ($estado === 'en_camino') {
                    $titulo = "¡Conductor en camino! 🚗";
                    $mensaje = "Tu conductor ya se dirige a recogerte.";
                } elseif ($estado === 'iniciado') {
                    $titulo = "¡Tu conductor ha llegado! 🤗";
                    $mensaje = "El conductor está en el punto de recogida. ¡Sube al vehículo!";
                } elseif ($estado === 'terminado') {
                    $titulo = "Viaje Finalizado ✨";
                    $mensaje = "Gracias por viajar con GeoMove. ¡Esperamos verte pronto!";
                }
                
                _sendFcm($token, $proj, $res_u['token_fcm'], $titulo, $mensaje, [
                    'viaje_id' => $viajeId, 
                    'estado' => $estado
                ]);
            }
        }
    } catch (Exception $e) { /* No bloquear */ }
    // ────────────────────────────────────────────────────────────────

    echo json_encode([
        "status" => "success",
        "message" => "Estado actualizado",
        "viaje_id" => $viajeId,
        "estado" => $estado
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
}

$conn->close();
?>


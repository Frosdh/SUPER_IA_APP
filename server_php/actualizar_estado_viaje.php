<?php
require_once __DIR__ . '/db_config.php';

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


<?php
// ============================================================
// guardar_viaje.php - Guarda un viaje completado en la BD
// Colocar en: /fuber_api/guardar_viaje.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$viaje_id         = isset($_POST['viaje_id'])         ? intval($_POST['viaje_id'])           : 0;
$calificacion     = isset($_POST['calificacion'])     ? floatval($_POST['calificacion'])      : null;
$comentario       = isset($_POST['comentario'])       ? trim($_POST['comentario'])            : '';
$descuento        = isset($_POST['descuento'])        ? floatval($_POST['descuento'])         : 0.0;
$codigo_descuento = isset($_POST['codigo_descuento']) ? strtoupper(trim($_POST['codigo_descuento'])) : '';

if ($viaje_id <= 0) {
    echo json_encode(["status" => "error", "message" => "viaje_id requerido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

$conn->begin_transaction();
try {
    // 1) Terminar el viaje
    $stmt = $conn->prepare("
        UPDATE viajes
        SET estado = 'terminado',
            fecha_fin = NOW(),
            calificacion = ?,
            comentario = ?,
            descuento = ?,
            codigo_descuento = ?
        WHERE id = ?
    ");

    $stmt->bind_param("dsdsi", $calificacion, $comentario, $descuento, $codigo_descuento, $viaje_id);
    $stmt->execute();
    $stmt->close();

    // 2) Liberar conductor si existe asignado a este viaje
    $stmtGet = $conn->prepare("SELECT conductor_id FROM viajes WHERE id = ? LIMIT 1");
    $stmtGet->bind_param("i", $viaje_id);
    $stmtGet->execute();
    $stmtGet->bind_result($conductor_id_db);
    $stmtGet->fetch();
    $stmtGet->close();

    if (!is_null($conductor_id_db) && intval($conductor_id_db) > 0) {
        $stmtDriver = $conn->prepare("UPDATE conductores SET estado = 'libre' WHERE id = ?");
        $stmtDriver->bind_param("i", $conductor_id_db);
        $stmtDriver->execute();
        $stmtDriver->close();
    }

    $conn->commit();
    echo json_encode([
        "status"  => "success",
        "message" => "Viaje actualizado correctamente",
        "viaje_id" => $viaje_id
    ]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        "status"  => "error",
        "message" => "Error al actualizar: " . $e->getMessage()
    ]);
}

$conn->close();
?>

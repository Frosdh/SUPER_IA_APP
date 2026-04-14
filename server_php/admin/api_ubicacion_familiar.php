<?php
require_once 'db_admin.php';

header("Content-Type: application/json");

if (!isset($_SESSION['family_logged_in']) || $_SESSION['family_logged_in'] !== true) {
    echo json_encode(["status" => "error", "message" => "Sesion no iniciada"]);
    exit;
}

$conductorId = $_SESSION['conductor_id'];

try {
    $stmt = $pdo->prepare("SELECT latitud, longitud, nombre, estado FROM conductores WHERE id = ? LIMIT 1");
    $stmt->execute([$conductorId]);
    $conductor = $stmt->fetch();

    if ($conductor) {
        echo json_encode([
            "status" => "success",
            "latitud" => $conductor['latitud'] !== null ? (double)$conductor['latitud'] : null,
            "longitud" => $conductor['longitud'] !== null ? (double)$conductor['longitud'] : null,
            "nombre" => $conductor['nombre'],
            "estado" => $conductor['estado']
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Conductor no encontrado"]);
    }
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>

<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// obtener_documentos_conductor.php
// Devuelve el estado de los documentos de un conductor
// GET/POST: conductor_id
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

$conductor_id = 0;
if (isset($_GET['conductor_id']))  $conductor_id = intval($_GET['conductor_id']);
if (isset($_POST['conductor_id'])) $conductor_id = intval($_POST['conductor_id']);

if ($conductor_id <= 0) {
    echo json_encode(["status" => "error", "message" => "conductor_id requerido"]);
    exit;
}

// Estado general del conductor
$stmtC = $conn->prepare(
    "SELECT verificado, estado FROM conductores WHERE id = ? LIMIT 1"
);
$stmtC->bind_param("i", $conductor_id);
$stmtC->execute();
$stmtC->bind_result($verificado, $estadoConductor);
$stmtC->fetch();
$stmtC->close();

// Documentos
$stmtD = $conn->prepare(
    "SELECT tipo, estado, notas FROM documentos_conductor WHERE conductor_id = ?"
);
$stmtD->bind_param("i", $conductor_id);
$stmtD->execute();
$result = $stmtD->get_result();

$documentos = [];
while ($row = $result->fetch_assoc()) {
    $documentos[] = [
        "tipo"   => $row['tipo'],
        "estado" => $row['estado'],
        "notas"  => $row['notas'],
    ];
}
$stmtD->close();
$conn->close();

echo json_encode([
    "status"           => "success",
    "verificado"       => (int)($verificado ?? 0),
    "estado_conductor" => $estadoConductor ?? 'desconectado',
    "documentos"       => $documentos,
]);
?>

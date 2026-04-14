<?php
require_once __DIR__ . '/db_config.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$latitud = isset($_POST['latitud']) ? trim($_POST['latitud']) : '';
$longitud = isset($_POST['longitud']) ? trim($_POST['longitud']) : '';

// Fallback: si no viene ID pero sí teléfono, buscar el ID
if ($conductorId <= 0 && $telefono !== '') {
    $stmtId = $conn->prepare("SELECT id FROM conductores WHERE telefono = ?");
    $stmtId->bind_param("s", $telefono);
    $stmtId->execute();
    $resId = $stmtId->get_result();
    if ($rowId = $resId->fetch_assoc()) {
        $conductorId = $rowId['id'];
    }
    $stmtId->close();
}

if ($conductorId <= 0 || $latitud === '' || $longitud === '') {
    echo json_encode([
        "status" => "error",
        "message" => "conductor_id, latitud y longitud requeridos"
    ]);
    exit;
}

$stmt = $conn->prepare("UPDATE conductores SET latitud = ?, longitud = ?, ultima_ubicacion = NOW() WHERE id = ?");
if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "message" => "Error al preparar consulta: " . $conn->error
    ]);
    $conn->close();
    exit;
}

$lat = (double)$latitud;
$lng = (double)$longitud;
$stmt->bind_param("ddi", $lat, $lng, $conductorId);

if ($stmt->execute()) {
    // ── LOG DE RUTA HISTÓRICA (GPX) ──
    try {
        $shouldSaveRoute = true;
        // Solo guardar si se movió más de 15 metros desde el último punto
        $stmtLast = $conn->prepare("SELECT latitud, longitud FROM conductores_rutas WHERE conductor_id = ? ORDER BY id DESC LIMIT 1");
        $stmtLast->bind_param("i", $conductorId);
        $stmtLast->execute();
        $resLast = $stmtLast->get_result();
        if ($rowLast = $resLast->fetch_assoc()) {
            $lastLat = (double)$rowLast['latitud'];
            $lastLng = (double)$rowLast['longitud'];
            $dist = sqrt(pow($lat - $lastLat, 2) + pow($lng - $lastLng, 2)) * 111320; // aprox metros
            if ($dist < 15) { $shouldSaveRoute = false; }
        }
        $stmtLast->close();

        if ($shouldSaveRoute) {
            $stmtRoute = $conn->prepare("INSERT INTO conductores_rutas (conductor_id, latitud, longitud) VALUES (?, ?, ?)");
            $stmtRoute->bind_param("idd", $conductorId, $lat, $lng);
            $stmtRoute->execute();
            $stmtRoute->close();
        }
    } catch (Exception $e) {
        // Error en log de ruta no debe bloquear la respuesta principal
    }

    echo json_encode([
        "status" => "success",
        "message" => "Ubicacion actualizada",
        "latitud" => $lat,
        "longitud" => $lng
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "No se pudo actualizar la ubicacion: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>

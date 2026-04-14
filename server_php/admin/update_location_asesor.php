<?php
// ============================================================
// admin/update_location_asesor.php
// Recibe la ubicación GPS del asesor autenticado y la guarda en BD
// Solo acepta peticiones de sesión web (no API móvil)
// ============================================================

header('Content-Type: application/json; charset=utf-8');

require_once 'db_admin.php';      // $pdo + session_start()
require_once '../db_config.php';  // $conn (mysqli)

// Verificar sesión del asesor
if (!isset($_SESSION['asesor_logged_in']) || $_SESSION['asesor_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

// $_SESSION['asesor_id'] = usuario.id (char 36) — ver login.php
$asesor_usuario_id = $_SESSION['asesor_id'] ?? null;
if (!$asesor_usuario_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Sesión inválida: sin asesor_id']);
    exit;
}

// Resolver usuario.id → asesor.id (la FK de ubicacion_asesor apunta a asesor.id)
$stmt = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de BD al buscar asesor']);
    exit;
}
$stmt->bind_param('s', $asesor_usuario_id);
$stmt->execute();
$res = $stmt->get_result();
$asesor_row = $res->fetch_assoc();
$stmt->close();

if (!$asesor_row) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Perfil de asesor no encontrado']);
    exit;
}
$asesor_id = $asesor_row['id']; // char(36) real de la tabla asesor

// Validar coordenadas del POST
$lat       = isset($_POST['latitud'])    ? (float)$_POST['latitud']    : null;
$lng       = isset($_POST['longitud'])   ? (float)$_POST['longitud']   : null;
$precision = isset($_POST['precision_m'])? (float)$_POST['precision_m']: 0.0;

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Faltan latitud o longitud']);
    exit;
}
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Coordenadas fuera de rango']);
    exit;
}

// Insertar en ubicacion_asesor
// El trigger trg_georef_ubicacion calculará automáticamente el campo `punto`
$conn->query(
    "CREATE TABLE IF NOT EXISTS asesor_presencia (
        asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
        estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$conn->query(
    "ALTER TABLE asesor_presencia
     MODIFY asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"
);

$stmt = $conn->prepare(
    'INSERT INTO ubicacion_asesor (asesor_id, latitud, longitud, precision_m, timestamp)
     VALUES (?, ?, ?, ?, NOW())'
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error preparando INSERT: ' . $conn->error]);
    exit;
}

$stmt->bind_param('sddd', $asesor_id, $lat, $lng, $precision);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error al guardar ubicación']);
    exit;
}

$presenceStmt = $conn->prepare(
    "INSERT INTO asesor_presencia (asesor_id, estado, updated_at)
     VALUES (?, 'conectado', NOW())
     ON DUPLICATE KEY UPDATE estado = 'conectado', updated_at = NOW()"
);
if ($presenceStmt) {
    $presenceStmt->bind_param('s', $asesor_id);
    $presenceStmt->execute();
    $presenceStmt->close();
}

echo json_encode([
    'status'    => 'success',
    'message'   => 'Ubicación guardada',
    'hora'      => date('H:i:s'),
    'asesor_id' => $asesor_id
]);

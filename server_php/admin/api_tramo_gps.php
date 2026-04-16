<?php
// ============================================================
// admin/api_tramo_gps.php
// Devuelve los puntos GPS de ubicacion_asesor en un rango
// de tiempo específico (para dibujar trayecto de un asesor
// entre dos tareas).
// Params: ?asesor_id=xxx&desde=YYYY-MM-DD HH:MM:SS&hasta=YYYY-MM-DD HH:MM:SS
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_admin_superIA.php';
require_once '../db_config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$is_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;
if (!$is_supervisor) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$supervisor_id = $_SESSION['supervisor_id'] ?? null;
$asesor_id     = trim($_GET['asesor_id'] ?? '');
$desde         = trim($_GET['desde'] ?? '');
$hasta         = trim($_GET['hasta'] ?? '');

if (!$supervisor_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'supervisor_id no encontrado']);
    exit;
}
if ($asesor_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'asesor_id requerido']);
    exit;
}
if ($desde === '' || $hasta === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'desde y hasta son requeridos']);
    exit;
}

// Validar formato básico de datetime
$desdeDt = DateTime::createFromFormat('Y-m-d H:i:s', $desde);
$hastaDt = DateTime::createFromFormat('Y-m-d H:i:s', $hasta);
if (!$desdeDt || !$hastaDt) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Formato de fecha inválido. Use YYYY-MM-DD HH:MM:SS']);
    exit;
}

try {
    // Verificar que el asesor pertenece a este supervisor
    $stVerify = $conn->prepare("
        SELECT a.id
        FROM asesor a
        JOIN supervisor s ON s.id = a.supervisor_id
        WHERE a.id = ? AND s.usuario_id = ?
        LIMIT 1
    ");
    if (!$stVerify) throw new Exception('Prepare verify: ' . $conn->error);
    $stVerify->bind_param('ss', $asesor_id, $supervisor_id);
    $stVerify->execute();
    $ok = $stVerify->get_result()->fetch_assoc();
    $stVerify->close();

    if (!$ok) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Asesor no encontrado o no pertenece al supervisor']);
        exit;
    }

    // Obtener puntos GPS en el rango solicitado
    $stPts = $conn->prepare("
        SELECT latitud, longitud, timestamp
        FROM ubicacion_asesor
        WHERE asesor_id = ?
          AND timestamp BETWEEN ? AND ?
        ORDER BY timestamp ASC
    ");
    if (!$stPts) throw new Exception('Prepare puntos: ' . $conn->error);
    $stPts->bind_param('sss', $asesor_id, $desde, $hasta);
    $stPts->execute();
    $resPts = $stPts->get_result();

    $puntos = [];
    while ($pt = $resPts->fetch_assoc()) {
        $puntos[] = [
            'lat' => (float)$pt['latitud'],
            'lng' => (float)$pt['longitud'],
            'ts'  => $pt['timestamp'],
        ];
    }
    $stPts->close();

    echo json_encode([
        'status'    => 'ok',
        'asesor_id' => $asesor_id,
        'desde'     => $desde,
        'hasta'     => $hasta,
        'puntos'    => $puntos,
        'total'     => count($puntos),
        'ts'        => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[api_tramo_gps] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

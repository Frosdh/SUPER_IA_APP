<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metodo no permitido']);
    exit;
}

$asesor_id = isset($_POST['asesor_id']) ? (int)$_POST['asesor_id'] : 0;
$usuario_id = isset($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : 0;

$lat_raw = isset($_POST['latitud']) ? trim((string)$_POST['latitud']) : '';
$lng_raw = isset($_POST['longitud']) ? trim((string)$_POST['longitud']) : '';
$precision_raw = isset($_POST['precision_m']) ? trim((string)$_POST['precision_m']) : '';

if ($asesor_id <= 0 && $usuario_id > 0) {
    $stmt_map = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    if ($stmt_map) {
        $stmt_map->bind_param('i', $usuario_id);
        if ($stmt_map->execute()) {
            $res = $stmt_map->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $asesor_id = (int)$row['id'];
            }
        }
        $stmt_map->close();
    }
}

if ($asesor_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'asesor_id es requerido']);
    exit;
}

if ($lat_raw === '' || $lng_raw === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Latitud y longitud son requeridas']);
    exit;
}

$latitud = (float)$lat_raw;
$longitud = (float)$lng_raw;
$precision_m = $precision_raw === '' ? 0.0 : (float)$precision_raw;

if ($latitud < -90 || $latitud > 90 || $longitud < -180 || $longitud > 180) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Coordenadas inválidas']);
    exit;
}

try {
    $stmt = $conn->prepare(
        'INSERT INTO ubicacion_asesor (asesor_id, latitud, longitud, precision_m, timestamp) VALUES (?, ?, ?, ?, NOW())'
    );

    if (!$stmt) {
        throw new Exception('Error en preparación: ' . $conn->error);
    }

    $stmt->bind_param('iddd', $asesor_id, $latitud, $longitud, $precision_m);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        throw new Exception('Error al guardar ubicación');
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Ubicación actualizada',
        'asesor_id' => $asesor_id,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor: ' . $e->getMessage(),
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

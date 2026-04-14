<?php
// ============================================================
// admin/api_ubicaciones_mapa.php
// AJAX endpoint: devuelve JSON con ubicaciones activas de asesores
// para el mapa en vivo del supervisor.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_admin_superIA.php';
require_once '../db_config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_supervisor = isset($_SESSION['supervisor_logged_in']) && $_SESSION['supervisor_logged_in'] === true;
if (!$is_supervisor) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$supervisor_id = $_SESSION['supervisor_id'] ?? null;
if (!$supervisor_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'supervisor_id no encontrado en sesión']);
    exit;
}

$ubicaciones = [];
$error_msg = '';

try {
    // Asegurar que la tabla existe
    $conn->query(
        "CREATE TABLE IF NOT EXISTS asesor_presencia (
            asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
            estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $query = "
        SELECT DISTINCT
            ua.asesor_id,
            ua.latitud,
            ua.longitud,
            ua.timestamp,
            COALESCE(ua.precision_m, 0) AS precision_m,
            u.nombre AS asesor_nombre
        FROM ubicacion_asesor ua
        INNER JOIN asesor a  ON a.id   = ua.asesor_id
        INNER JOIN supervisor s ON s.id = a.supervisor_id
        INNER JOIN usuario u ON u.id   = a.usuario_id
        LEFT  JOIN asesor_presencia ap ON ap.asesor_id = ua.asesor_id
        WHERE s.usuario_id = ?
          AND ua.timestamp >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND ua.latitud  IS NOT NULL
          AND ua.longitud IS NOT NULL
          AND COALESCE(ap.estado, 'conectado') != 'desconectado'
        ORDER BY ua.asesor_id DESC, ua.timestamp DESC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Error preparando query: ' . $conn->error);
    }

    $stmt->bind_param('s', $supervisor_id);
    if (!$stmt->execute()) {
        throw new Exception('Error ejecutando query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $map = [];
    while ($row = $result->fetch_assoc()) {
        $aid = $row['asesor_id'];
        if (!isset($map[$aid])) {
            $map[$aid] = $row;
        }
    }
    $ubicaciones = array_values($map);
    $stmt->close();

} catch (Exception $e) {
    $error_msg = $e->getMessage();
    error_log('[api_ubicaciones_mapa] ' . $error_msg);
}

echo json_encode([
    'status'     => $error_msg ? 'error' : 'ok',
    'ubicaciones' => $ubicaciones,
    'total'      => count($ubicaciones),
    'ts'         => date('H:i:s'),
    'error'      => $error_msg ?: null,
]);

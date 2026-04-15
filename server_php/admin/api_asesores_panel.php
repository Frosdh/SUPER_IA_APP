<?php
// ============================================================
// admin/api_asesores_panel.php
// Devuelve TODOS los asesores del supervisor (online + offline)
// con su última ubicación conocida y estado de conexión.
// Usado por el panel lateral del mapa en vivo.
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
if (!$supervisor_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'supervisor_id no encontrado en sesión']);
    exit;
}

try {
    // Asegurar que la tabla asesor_presencia existe
    $conn->query(
        "CREATE TABLE IF NOT EXISTS asesor_presencia (
            asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
            estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Obtener todos los asesores con su última ubicación conocida
    $sql = "
        SELECT
            a.id            AS asesor_id,
            u.nombre        AS asesor_nombre,
            COALESCE(ap.estado, 'desconectado') AS estado,
            ua.latitud,
            ua.longitud,
            ua.timestamp    AS ultima_vez
        FROM asesor a
        JOIN supervisor s  ON s.id  = a.supervisor_id
        JOIN usuario    u  ON u.id  = a.usuario_id
        LEFT JOIN asesor_presencia ap ON ap.asesor_id = a.id
        LEFT JOIN (
            SELECT ua1.asesor_id, ua1.latitud, ua1.longitud, ua1.timestamp
            FROM ubicacion_asesor ua1
            INNER JOIN (
                SELECT asesor_id, MAX(timestamp) AS max_ts
                FROM ubicacion_asesor
                GROUP BY asesor_id
            ) latest ON latest.asesor_id = ua1.asesor_id
                    AND latest.max_ts    = ua1.timestamp
        ) ua ON ua.asesor_id = a.id
        WHERE s.usuario_id = ?
        ORDER BY
            CASE WHEN COALESCE(ap.estado, 'desconectado') = 'conectado' THEN 0 ELSE 1 END,
            u.nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare error: ' . $conn->error);

    $stmt->bind_param('s', $supervisor_id);
    if (!$stmt->execute()) throw new Exception('Execute error: ' . $stmt->error);

    $result   = $stmt->get_result();
    $asesores = [];
    $now      = time();

    while ($row = $result->fetch_assoc()) {
        // Determinar online: estado = 'conectado' O última ubicación < 2 min
        $online = ($row['estado'] === 'conectado');
        if (!$online && $row['ultima_vez']) {
            $diff = $now - strtotime($row['ultima_vez']);
            if ($diff <= 120) $online = true;
        }

        $asesores[] = [
            'asesor_id'  => $row['asesor_id'],
            'nombre'     => $row['asesor_nombre'],
            'online'     => $online,
            'latitud'    => $row['latitud']    !== null ? (float)$row['latitud']  : null,
            'longitud'   => $row['longitud']   !== null ? (float)$row['longitud'] : null,
            'ultima_vez' => $row['ultima_vez'],
        ];
    }
    $stmt->close();

    echo json_encode([
        'status'   => 'ok',
        'asesores' => $asesores,
        'total'    => count($asesores),
        'online'   => count(array_filter($asesores, fn($a) => $a['online'])),
        'ts'       => date('H:i:s'),
    ]);

} catch (\Throwable $e) {
    error_log('[api_asesores_panel] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

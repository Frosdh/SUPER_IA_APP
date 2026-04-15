<?php
// ============================================================
// admin/api_encuestas_resumen.php
// Resumen de encuestas por asesor (count) para un supervisor.
// Params: ?fecha=YYYY-MM-DD (opcional, default hoy)
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
$fecha         = trim($_GET['fecha'] ?? date('Y-m-d'));

if (!$supervisor_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'supervisor_id no encontrado']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}

try {
    $sql = "
        SELECT
            t.asesor_id,
            COUNT(*) AS total
        FROM tarea t
        JOIN asesor a      ON a.id = t.asesor_id
        JOIN supervisor s  ON s.id = a.supervisor_id
        LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id
        WHERE s.usuario_id = ?
          AND t.estado = 'completada'
          AND t.fecha_realizada = ?
          AND ec.id IS NOT NULL
        GROUP BY t.asesor_id
    ";

    $st = $conn->prepare($sql);
    if (!$st) throw new Exception('Prepare resumen: ' . $conn->error);
    $st->bind_param('ss', $supervisor_id, $fecha);
    $st->execute();
    $res = $st->get_result();

    $porAsesor = [];
    $total = 0;
    while ($row = $res->fetch_assoc()) {
        $aid = (string)$row['asesor_id'];
        $cnt = (int)($row['total'] ?? 0);
        $porAsesor[$aid] = $cnt;
        $total += $cnt;
    }
    $st->close();

    echo json_encode([
        'status'     => 'ok',
        'fecha'      => $fecha,
        'por_asesor' => $porAsesor,
        'total'      => $total,
        'ts'         => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[api_encuestas_resumen] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

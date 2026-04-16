<?php
// ============================================================
// admin/api_clientes_encuestados.php
// Devuelve los clientes encuestados por un asesor en una fecha.
// Params: ?asesor_id=xxx&fecha=YYYY-MM-DD (opcional, default hoy)
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
$fecha         = trim($_GET['fecha'] ?? date('Y-m-d'));

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
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}

try {
    // Verificar que el asesor pertenece a este supervisor
    $stVerify = $conn->prepare(
        'SELECT a.id
         FROM asesor a
         JOIN supervisor s ON s.id = a.supervisor_id
         WHERE a.id = ? AND s.usuario_id = ?
         LIMIT 1'
    );
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

    // Obtener el primer punto GPS del asesor en el día (inicio de sesión)
    $stFirst = $conn->prepare("
        SELECT timestamp
        FROM ubicacion_asesor
        WHERE asesor_id = ?
          AND DATE(timestamp) = ?
        ORDER BY timestamp ASC
        LIMIT 1
    ");
    $session_start = null;
    if ($stFirst) {
        $stFirst->bind_param('ss', $asesor_id, $fecha);
        $stFirst->execute();
        $rowFirst = $stFirst->get_result()->fetch_assoc();
        $stFirst->close();
        if ($rowFirst) {
            $session_start = $rowFirst['timestamp'];
        }
    }

    // Clientes encuestados = tareas completadas con registro en encuesta_comercial
    $sql = "
        SELECT
            t.id            AS tarea_id,
            t.tipo_tarea    AS tipo_tarea,
            t.fecha_realizada,
            t.hora_realizada,
            CONCAT(t.fecha_realizada, ' ', t.hora_realizada) AS fecha_hora,
            t.latitud_fin   AS latitud,
            t.longitud_fin  AS longitud,
            cp.id           AS cliente_id,
            cp.nombre       AS cliente_nombre
        FROM tarea t
        JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        JOIN asesor a            ON a.id  = t.asesor_id
        JOIN supervisor s        ON s.id  = a.supervisor_id
        LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id
        WHERE t.asesor_id = ?
          AND s.usuario_id = ?
          AND t.estado = 'completada'
          AND t.fecha_realizada = ?
          AND ec.id IS NOT NULL
        ORDER BY t.hora_realizada ASC, t.fecha_realizada ASC
    ";

    $st = $conn->prepare($sql);
    if (!$st) throw new Exception('Prepare clientes: ' . $conn->error);
    $st->bind_param('sss', $asesor_id, $supervisor_id, $fecha);
    $st->execute();
    $res = $st->get_result();

    $clientes = [];
    while ($row = $res->fetch_assoc()) {
        $clientes[] = [
            'tarea_id'       => $row['tarea_id'],
            'tipo_tarea'     => $row['tipo_tarea'],
            'fecha'          => $row['fecha_realizada'],
            'hora'           => $row['hora_realizada'],
            'fecha_hora'     => $row['fecha_hora'],
            'latitud'        => $row['latitud']  !== null ? (float)$row['latitud']  : null,
            'longitud'       => $row['longitud'] !== null ? (float)$row['longitud'] : null,
            'cliente_id'     => $row['cliente_id'],
            'cliente_nombre' => $row['cliente_nombre'],
        ];
    }
    $st->close();

    echo json_encode([
        'status'        => 'ok',
        'asesor_id'     => $asesor_id,
        'fecha'         => $fecha,
        'session_start' => $session_start,
        'clientes'      => $clientes,
        'total'         => count($clientes),
        'ts'            => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[api_clientes_encuestados] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

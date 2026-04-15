<?php
// ============================================================
// admin/api_ultima_ruta.php
// Devuelve los segmentos de la ÚLTIMA sesión de ruta
// de un asesor específico (el día más reciente con datos).
// Parámetro: ?asesor_id=xxx
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

try {
    // Verificar que el asesor pertenece a este supervisor
    $stVerify = $conn->prepare("
        SELECT a.id, u.nombre
        FROM asesor a
        JOIN supervisor s ON s.id = a.supervisor_id
        JOIN usuario    u ON u.id = a.usuario_id
        WHERE a.id = ? AND s.usuario_id = ?
        LIMIT 1
    ");
    if (!$stVerify) throw new Exception('Prepare verify: ' . $conn->error);
    $stVerify->bind_param('ss', $asesor_id, $supervisor_id);
    $stVerify->execute();
    $asesorRow = $stVerify->get_result()->fetch_assoc();
    $stVerify->close();

    if (!$asesorRow) {
        echo json_encode(['status' => 'error', 'message' => 'Asesor no encontrado o no pertenece al supervisor']);
        exit;
    }
    $asesorNombre = $asesorRow['nombre'];

    // Asegurar tabla ruta_segmento
    $conn->query("
        CREATE TABLE IF NOT EXISTS ruta_segmento (
            id               CHAR(36)      NOT NULL,
            asesor_id        CHAR(64)      NOT NULL,
            numero_segmento  INT           NOT NULL DEFAULT 1,
            tarea_origen_id  CHAR(36)      DEFAULT NULL,
            tarea_destino_id CHAR(36)      DEFAULT NULL,
            estado           ENUM('activo','completado','cerrado_logout') NOT NULL DEFAULT 'activo',
            inicio_lat       DECIMAL(10,8) DEFAULT NULL,
            inicio_lng       DECIMAL(11,8) DEFAULT NULL,
            fin_lat          DECIMAL(10,8) DEFAULT NULL,
            fin_lng          DECIMAL(11,8) DEFAULT NULL,
            inicio_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fin_at           DATETIME      DEFAULT NULL,
            color_hex        VARCHAR(7)    NOT NULL DEFAULT '#3B82F6',
            PRIMARY KEY (id),
            KEY idx_rs_asesor_fecha (asesor_id, inicio_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Fecha opcional: si viene, devuelve los segmentos de esa fecha.
    // Si no viene, usa el día más reciente con datos.
    $fecha = null;
    $fecha_in = trim($_GET['fecha'] ?? '');

    if ($fecha_in !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_in)) {
        $fecha = $fecha_in;
    } else {
        // Encontrar la fecha más reciente con segmentos de ruta para este asesor
        $stDate = $conn->prepare("
            SELECT DATE(inicio_at) AS fecha
            FROM ruta_segmento
            WHERE asesor_id = ?
            ORDER BY inicio_at DESC
            LIMIT 1
        ");
        if (!$stDate) throw new Exception('Prepare date: ' . $conn->error);
        $stDate->bind_param('s', $asesor_id);
        $stDate->execute();
        $dateRow = $stDate->get_result()->fetch_assoc();
        $stDate->close();

        if (!$dateRow) {
            // Sin rutas registradas
            echo json_encode([
                'status'    => 'ok',
                'asesor_id' => $asesor_id,
                'nombre'    => $asesorNombre,
                'fecha'     => null,
                'segmentos' => [],
                'total'     => 0,
            ]);
            exit;
        }

        $fecha = $dateRow['fecha'];
    }

    // solo_ultimo=1  → devuelve ÚNICAMENTE el último segmento (el de mayor numero_segmento)
    // solo_ultimo=0  → devuelve todos los segmentos del día (para búsqueda por fecha)
    $soloUltimo = (trim($_GET['solo_ultimo'] ?? '1') === '1');

    // Obtener segmentos del día (uno o todos según solo_ultimo)
    $limitClause = $soloUltimo ? 'ORDER BY rs.numero_segmento DESC LIMIT 1' : 'ORDER BY rs.numero_segmento ASC';

    $stSeg = $conn->prepare("
        SELECT rs.id, rs.asesor_id, rs.numero_segmento, rs.estado,
               rs.inicio_lat, rs.inicio_lng, rs.fin_lat, rs.fin_lng,
               rs.inicio_at, rs.fin_at, rs.color_hex,
               rs.tarea_destino_id,
               t.tipo_tarea,
               cp.nombre AS cliente_nombre
        FROM ruta_segmento rs
        LEFT JOIN tarea t              ON t.id  = rs.tarea_destino_id
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE rs.asesor_id = ?
          AND DATE(rs.inicio_at) = ?
        $limitClause
    ");
    if (!$stSeg) throw new Exception('Prepare seg: ' . $conn->error);
    $stSeg->bind_param('ss', $asesor_id, $fecha);
    $stSeg->execute();
    $resSeg = $stSeg->get_result();

    $resultado = [];
    while ($seg = $resSeg->fetch_assoc()) {
        $inicioAt = $seg['inicio_at'];
        $finAt    = $seg['fin_at'] ?? date('Y-m-d H:i:s'); // activo → hasta ahora

        // Obtener puntos GPS del segmento
        $stPts = $conn->prepare("
            SELECT latitud, longitud, timestamp
            FROM ubicacion_asesor
            WHERE asesor_id = ?
              AND timestamp BETWEEN ? AND ?
            ORDER BY timestamp ASC
        ");
        if ($stPts) {
            $stPts->bind_param('sss', $asesor_id, $inicioAt, $finAt);
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
        } else {
            $puntos = [];
        }

        $resultado[] = [
            'segmento_id'   => $seg['id'],
            'asesor_id'     => $asesor_id,
            'asesor_nombre' => $asesorNombre,
            'numero'        => (int)$seg['numero_segmento'],
            'estado'        => $seg['estado'],
            'color'         => $seg['color_hex'],
            'inicio_at'     => $seg['inicio_at'],
            'fin_at'        => $seg['fin_at'],
            'inicio_lat'    => $seg['inicio_lat'] !== null ? (float)$seg['inicio_lat'] : null,
            'inicio_lng'    => $seg['inicio_lng'] !== null ? (float)$seg['inicio_lng'] : null,
            'fin_lat'       => $seg['fin_lat']    !== null ? (float)$seg['fin_lat']    : null,
            'fin_lng'       => $seg['fin_lng']    !== null ? (float)$seg['fin_lng']    : null,
            'tarea_id'      => $seg['tarea_destino_id'],
            'tarea_tipo'    => $seg['tipo_tarea'],
            'cliente_nombre'=> $seg['cliente_nombre'],
            'puntos'        => $puntos,
            'total_puntos'  => count($puntos),
        ];
    }
    $stSeg->close();

    echo json_encode([
        'status'      => 'ok',
        'asesor_id'   => $asesor_id,
        'nombre'      => $asesorNombre,
        'fecha'       => $fecha,
        'solo_ultimo' => $soloUltimo,
        'segmentos'   => $resultado,
        'total'       => count($resultado),
        'ts'          => date('H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    error_log('[api_ultima_ruta] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

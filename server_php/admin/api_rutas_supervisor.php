<?php
// ============================================================
// admin/api_rutas_supervisor.php
// Devuelve los segmentos de ruta de los asesores del supervisor,
// con los puntos GPS de cada segmento para dibujar polilíneas.
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
    echo json_encode(['status' => 'error', 'message' => 'supervisor_id no encontrado']);
    exit;
}

// Parámetros opcionales
$fecha      = trim($_GET['fecha']     ?? date('Y-m-d'));   // default: hoy
$asesor_id  = trim($_GET['asesor_id'] ?? '');              // filtrar por asesor

// Validar fecha
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = date('Y-m-d');
}

try {
    // Asegurar tabla
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

    // ── Obtener asesores del supervisor ─────────────────────
    $sqlAsesores = "
        SELECT a.id AS asesor_id, u.nombre AS asesor_nombre
        FROM asesor     a
        JOIN supervisor s ON s.id      = a.supervisor_id
        JOIN usuario    u ON u.id      = a.usuario_id
        WHERE s.usuario_id = ?
    ";
    if ($asesor_id !== '') {
        $sqlAsesores .= " AND a.id = ?";
    }

    $stA = $conn->prepare($sqlAsesores);
    if (!$stA) throw new Exception('Prepare asesores: ' . $conn->error);

    if ($asesor_id !== '') {
        $stA->bind_param('ss', $supervisor_id, $asesor_id);
    } else {
        $stA->bind_param('s', $supervisor_id);
    }

    $stA->execute();
    $resA = $stA->get_result();
    $asesores = [];
    while ($row = $resA->fetch_assoc()) {
        $asesores[$row['asesor_id']] = $row['asesor_nombre'];
    }
    $stA->close();

    if (empty($asesores)) {
        echo json_encode(['status' => 'ok', 'segmentos' => [], 'fecha' => $fecha]);
        exit;
    }

    $asesorIds   = array_keys($asesores);
    $placeholders = implode(',', array_fill(0, count($asesorIds), '?'));

    // ── Obtener segmentos del día ────────────────────────────
    $sqlSeg = "
        SELECT rs.id, rs.asesor_id, rs.numero_segmento, rs.estado,
               rs.inicio_lat, rs.inicio_lng, rs.fin_lat, rs.fin_lng,
               rs.inicio_at, rs.fin_at, rs.color_hex,
               rs.tarea_destino_id,
               t.tipo_tarea,
               cp.nombre AS cliente_nombre
        FROM ruta_segmento rs
        LEFT JOIN tarea         t  ON t.id  = rs.tarea_destino_id
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE rs.asesor_id IN ($placeholders)
          AND DATE(rs.inicio_at) = ?
        ORDER BY rs.asesor_id, rs.numero_segmento ASC
    ";

    $stSeg = $conn->prepare($sqlSeg);
    $types = str_repeat('s', count($asesorIds)) . 's';
    $params = array_merge($asesorIds, [$fecha]);
    $stSeg->bind_param($types, ...$params);
    $stSeg->execute();
    $resSeg = $stSeg->get_result();

    $segmentosPorAsesor = [];
    while ($seg = $resSeg->fetch_assoc()) {
        $aid = $seg['asesor_id'];
        if (!isset($segmentosPorAsesor[$aid])) $segmentosPorAsesor[$aid] = [];
        $segmentosPorAsesor[$aid][] = $seg;
    }
    $stSeg->close();

    // ── Para cada segmento obtener puntos GPS ────────────────
    $resultado = [];

    foreach ($segmentosPorAsesor as $aid => $segs) {
        $asesorNombre = $asesores[$aid] ?? 'Asesor';

        foreach ($segs as $seg) {
            $inicioAt = $seg['inicio_at'];
            $finAt    = $seg['fin_at'] ?? date('Y-m-d H:i:s'); // si activo → hasta ahora

            // Obtener puntos GPS del segmento
            $stPts = $conn->prepare(
                'SELECT latitud, longitud, timestamp
                 FROM ubicacion_asesor
                 WHERE asesor_id = ?
                   AND timestamp BETWEEN ? AND ?
                 ORDER BY timestamp ASC'
            );
            $stPts->bind_param('sss', $aid, $inicioAt, $finAt);
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

            $resultado[] = [
                'segmento_id'     => $seg['id'],
                'asesor_id'       => $aid,
                'asesor_nombre'   => $asesorNombre,
                'numero'          => (int)$seg['numero_segmento'],
                'estado'          => $seg['estado'],
                'color'           => $seg['color_hex'],
                'inicio_at'       => $seg['inicio_at'],
                'fin_at'          => $seg['fin_at'],
                'inicio_lat'      => $seg['inicio_lat'] !== null ? (float)$seg['inicio_lat'] : null,
                'inicio_lng'      => $seg['inicio_lng'] !== null ? (float)$seg['inicio_lng'] : null,
                'fin_lat'         => $seg['fin_lat']    !== null ? (float)$seg['fin_lat']    : null,
                'fin_lng'         => $seg['fin_lng']    !== null ? (float)$seg['fin_lng']    : null,
                'tarea_id'        => $seg['tarea_destino_id'],
                'tarea_tipo'      => $seg['tipo_tarea'],
                'cliente_nombre'  => $seg['cliente_nombre'],
                'puntos'          => $puntos,
                'total_puntos'    => count($puntos),
            ];
        }
    }

    echo json_encode([
        'status'    => 'ok',
        'fecha'     => $fecha,
        'segmentos' => $resultado,
        'total'     => count($resultado),
        'ts'        => date('H:i:s'),
    ]);

} catch (\Throwable $e) {
    error_log('[api_rutas_supervisor] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

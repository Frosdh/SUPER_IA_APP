<?php
// ============================================================
// api_cerrar_segmento.php
// Cierra el segmento activo del asesor.
// razon: 'tarea_completada' | 'logout'
// Si es 'tarea_completada' también crea el segmento siguiente.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

$COLORES = ['#3B82F6','#10B981','#F59E0B','#8B5CF6','#EF4444','#06B6D4','#EC4899','#84CC16'];

function genUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

$asesor_id       = trim($_POST['asesor_id']        ?? '');
$usuario_id      = trim($_POST['usuario_id']       ?? '');
$lat_raw         = trim($_POST['latitud']          ?? '');
$lng_raw         = trim($_POST['longitud']         ?? '');
$tarea_id        = trim($_POST['tarea_id']         ?? '') ?: null;
$razon           = trim($_POST['razon']            ?? 'logout');  // 'tarea_completada' | 'logout'

// Resolver asesor_id desde usuario_id si hace falta
if ($asesor_id === '' && $usuario_id !== '') {
    $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('s', $usuario_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if ($row) $asesor_id = $row['id'];
        $st->close();
    }
}

if ($asesor_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'asesor_id requerido']);
    exit;
}

$lat = ($lat_raw !== '') ? (float)$lat_raw : null;
$lng = ($lng_raw !== '') ? (float)$lng_raw : null;

try {
    // 1. Buscar segmento activo del día
    $stSeg = $conn->prepare(
        'SELECT id, numero_segmento
         FROM ruta_segmento
         WHERE asesor_id = ? AND estado = \'activo\' AND DATE(inicio_at) = CURDATE()
         ORDER BY inicio_at DESC LIMIT 1'
    );
    $stSeg->bind_param('s', $asesor_id);
    $stSeg->execute();
    $seg = $stSeg->get_result()->fetch_assoc();
    $stSeg->close();

    if (!$seg) {
        // No hay segmento activo; responder OK igual (idempotente)
        echo json_encode(['status' => 'success', 'message' => 'Sin segmento activo']);
        exit;
    }

    $seg_id  = $seg['id'];
    $seg_num = (int)$seg['numero_segmento'];

    // 2. Cerrar segmento
    $estado_cierre = ($razon === 'tarea_completada') ? 'completado' : 'cerrado_logout';
    $stClose = $conn->prepare(
        'UPDATE ruta_segmento
         SET estado = ?, fin_at = NOW(), fin_lat = ?, fin_lng = ?, tarea_destino_id = ?
         WHERE id = ?'
    );
    $stClose->bind_param('sddss', $estado_cierre, $lat, $lng, $tarea_id, $seg_id);
    $stClose->execute();
    $stClose->close();

    $nuevo_segmento_id = null;

    // 3. Si es tarea completada → crear nuevo segmento
    if ($razon === 'tarea_completada') {
        $nuevo_num  = $seg_num + 1;
        $color      = $COLORES[($nuevo_num - 1) % count($COLORES)];
        $nuevo_id   = genUUID();

        $stNew = $conn->prepare(
            'INSERT INTO ruta_segmento
             (id, asesor_id, numero_segmento, tarea_origen_id, estado,
              inicio_lat, inicio_lng, inicio_at, color_hex)
             VALUES (?, ?, ?, ?, \'activo\', ?, ?, NOW(), ?)'
        );
        $stNew->bind_param('ssissdds', $nuevo_id, $asesor_id, $nuevo_num, $tarea_id, $lat, $lng, $color);
        $stNew->execute();
        $stNew->close();
        $nuevo_segmento_id = $nuevo_id;
    }

    echo json_encode([
        'status'              => 'success',
        'segmento_cerrado_id' => $seg_id,
        'nuevo_segmento_id'   => $nuevo_segmento_id,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>

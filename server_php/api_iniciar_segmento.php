<?php
// ============================================================
// api_iniciar_segmento.php
// Crea un nuevo segmento de ruta para el asesor.
// Se llama al iniciar sesión (primer segmento) y después de
// completar cada tarea (segmento siguiente).
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

// ── Asegurar tabla ruta_segmento ────────────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS ruta_segmento (
        id              CHAR(36)       NOT NULL,
        asesor_id       CHAR(64)       NOT NULL,
        numero_segmento INT            NOT NULL DEFAULT 1,
        tarea_origen_id CHAR(36)       DEFAULT NULL COMMENT 'Tarea que originó el inicio (null = login)',
        tarea_destino_id CHAR(36)      DEFAULT NULL COMMENT 'Tarea que cerró el segmento',
        estado          ENUM('activo','completado','cerrado_logout') NOT NULL DEFAULT 'activo',
        inicio_lat      DECIMAL(10,8)  DEFAULT NULL,
        inicio_lng      DECIMAL(11,8)  DEFAULT NULL,
        fin_lat         DECIMAL(10,8)  DEFAULT NULL,
        fin_lng         DECIMAL(11,8)  DEFAULT NULL,
        inicio_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fin_at          DATETIME       DEFAULT NULL,
        color_hex       VARCHAR(7)     NOT NULL DEFAULT '#3B82F6',
        PRIMARY KEY (id),
        KEY idx_rs_asesor_fecha (asesor_id, inicio_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

function genUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
}

// Colores cíclicos para cada segmento del día
$COLORES = ['#3B82F6','#10B981','#F59E0B','#8B5CF6','#EF4444','#06B6D4','#EC4899','#84CC16'];

$asesor_id       = trim($_POST['asesor_id']       ?? '');
$usuario_id      = trim($_POST['usuario_id']      ?? '');
$lat_raw         = trim($_POST['latitud']         ?? '');
$lng_raw         = trim($_POST['longitud']        ?? '');
$tarea_origen_id = trim($_POST['tarea_origen_id'] ?? '') ?: null;

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
    // 1. Cerrar cualquier segmento activo previo del mismo día
    //    (por si el asesor forzó el cierre sin logout limpio)
    $conn->query("
        UPDATE ruta_segmento
        SET estado = 'cerrado_logout', fin_at = NOW()
        WHERE asesor_id = '$asesor_id'
          AND estado    = 'activo'
          AND DATE(inicio_at) = CURDATE()
    ");

    // 2. Calcular número de segmento del día
    $stNum = $conn->prepare(
        'SELECT COALESCE(MAX(numero_segmento),0)+1 AS siguiente
         FROM ruta_segmento
         WHERE asesor_id = ? AND DATE(inicio_at) = CURDATE()'
    );
    $stNum->bind_param('s', $asesor_id);
    $stNum->execute();
    $siguiente = (int)$stNum->get_result()->fetch_assoc()['siguiente'];
    $stNum->close();

    $color = $COLORES[($siguiente - 1) % count($COLORES)];

    // 3. Insertar nuevo segmento
    $id = genUUID();
    $st = $conn->prepare(
        'INSERT INTO ruta_segmento
         (id, asesor_id, numero_segmento, tarea_origen_id, estado,
          inicio_lat, inicio_lng, inicio_at, color_hex)
         VALUES (?, ?, ?, ?, \'activo\', ?, ?, NOW(), ?)'
    );
    $st->bind_param('ssisdds', $id, $asesor_id, $siguiente, $tarea_origen_id, $lat, $lng, $color);
    $st->execute();
    $st->close();

    echo json_encode([
        'status'          => 'success',
        'segmento_id'     => $id,
        'numero_segmento' => $siguiente,
        'color'           => $color,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    $conn->close();
}
?>

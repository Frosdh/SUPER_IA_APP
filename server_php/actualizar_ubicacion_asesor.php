<?php
// ============================================================
// actualizar_ubicacion_asesor.php
// Recibe latitud/longitud del asesor desde la app Flutter y
// guarda en ubicacion_asesor. También marca presencia='conectado'.
// ============================================================
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

// ── Leer parámetros ─────────────────────────────────────────
// asesor_id y usuario_id son UUID (char 36), NO enteros
$asesor_id   = isset($_POST['asesor_id'])   ? trim((string)$_POST['asesor_id'])   : '';
$usuario_id  = isset($_POST['usuario_id'])  ? trim((string)$_POST['usuario_id'])  : '';
$lat_raw     = isset($_POST['latitud'])     ? trim((string)$_POST['latitud'])     : '';
$lng_raw     = isset($_POST['longitud'])    ? trim((string)$_POST['longitud'])    : '';
$prec_raw    = isset($_POST['precision_m']) ? trim((string)$_POST['precision_m']) : '';

// Si no llegó asesor_id pero sí usuario_id, buscarlo por usuario_id
if ($asesor_id === '' && $usuario_id !== '') {
    $stmt_map = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    if ($stmt_map) {
        $stmt_map->bind_param('s', $usuario_id);
        if ($stmt_map->execute()) {
            $res = $stmt_map->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $asesor_id = (string)$row['id'];
            }
        }
        $stmt_map->close();
    }
}

if ($asesor_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'asesor_id es requerido']);
    exit;
}

if ($lat_raw === '' || $lng_raw === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Latitud y longitud son requeridas']);
    exit;
}

$latitud    = (float)$lat_raw;
$longitud   = (float)$lng_raw;
$precision  = $prec_raw === '' ? 0.0 : (float)$prec_raw;

if ($latitud < -90 || $latitud > 90 || $longitud < -180 || $longitud > 180) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Coordenadas invalidas']);
    exit;
}

try {
    // ── Asegurar tablas ──────────────────────────────────────
    // ubicacion_asesor: historial de puntos GPS (base para trazar rutas)
    $conn->query(
        "CREATE TABLE IF NOT EXISTS ubicacion_asesor (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asesor_id   VARCHAR(64) NOT NULL,
            latitud     DECIMAL(10,7) NOT NULL,
            longitud    DECIMAL(10,7) NOT NULL,
            precision_m FLOAT DEFAULT 0,
            timestamp   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ub_asesor_ts (asesor_id, timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $conn->query(
        "CREATE TABLE IF NOT EXISTS asesor_presencia (
            asesor_id  VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
            estado     ENUM('conectado','desconectado') NOT NULL DEFAULT 'conectado',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "ALTER TABLE asesor_presencia
         MODIFY asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"
    );

    // ── Guardar ubicación ────────────────────────────────────
    $stmt = $conn->prepare(
        'INSERT INTO ubicacion_asesor (asesor_id, latitud, longitud, precision_m, timestamp)
         VALUES (?, ?, ?, ?, NOW())'
    );
    if (!$stmt) {
        throw new Exception('Error preparando insert ubicacion: ' . $conn->error);
    }
    // asesor_id es string UUID → tipo 's'
    $stmt->bind_param('sddd', $asesor_id, $latitud, $longitud, $precision);
    if (!$stmt->execute()) {
        throw new Exception('Error ejecutando insert ubicacion: ' . $stmt->error);
    }
    $stmt->close();

    // ── Marcar presencia como CONECTADO ─────────────────────
    // Cada heartbeat GPS confirma que el asesor está activo.
    // Si estaba marcado como 'desconectado' (logout previo sin borrar sesión),
    // este INSERT ON DUPLICATE KEY lo vuelve a activar automáticamente.
    $pres = $conn->prepare(
        "INSERT INTO asesor_presencia (asesor_id, estado, updated_at)
         VALUES (?, 'conectado', NOW())
         ON DUPLICATE KEY UPDATE estado = 'conectado', updated_at = NOW()"
    );
    if ($pres) {
        $pres->bind_param('s', $asesor_id);
        $pres->execute();
        $pres->close();
    }

    echo json_encode([
        'status'    => 'success',
        'message'   => 'Ubicacion actualizada',
        'asesor_id' => $asesor_id,
        'latitud'   => $latitud,
        'longitud'  => $longitud,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error del servidor: ' . $e->getMessage(),
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

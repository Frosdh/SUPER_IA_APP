<?php
// ============================================================
// admin/api_mapa_calor_encuestas.php
// Endpoint AJAX para el Mapa de Calor: devuelve la ubicación real
// (georeferenciada) de TODAS las encuestas registradas en la base
// (comercial, crediticia y de negocio), para pintarlas como
// capa de calor.
// ============================================================
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

$is_admin       = isset($_SESSION['admin_logged_in'])       && $_SESSION['admin_logged_in']       === true;
$is_super_admin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;

if (!$is_admin && !$is_super_admin) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

// Tipos de encuesta a incluir (por defecto los tres)
$tiposPermitidos = ['comercial', 'crediticia', 'negocio'];
$tiposSolicitados = isset($_GET['tipos']) ? explode(',', $_GET['tipos']) : $tiposPermitidos;
$tipos = array_values(array_intersect($tiposPermitidos, $tiposSolicitados));
if (empty($tipos)) $tipos = $tiposPermitidos;

$puntos = [];
$conteo = ['comercial' => 0, 'crediticia' => 0, 'negocio' => 0];
$error_msg = '';

try {
    $partes = [];

    if (in_array('comercial', $tipos)) {
        $partes[] = "
            SELECT
                COALESCE(cp.latitud,  t.latitud_fin,  t.latitud_inicio)  AS lat,
                COALESCE(cp.longitud, t.longitud_fin, t.longitud_inicio) AS lng,
                'comercial' AS tipo
            FROM encuesta_comercial ec
            INNER JOIN tarea t ON t.id = ec.tarea_id
            LEFT  JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE COALESCE(cp.latitud,  t.latitud_fin,  t.latitud_inicio)  IS NOT NULL
              AND COALESCE(cp.longitud, t.longitud_fin, t.longitud_inicio) IS NOT NULL
        ";
    }
    if (in_array('crediticia', $tipos)) {
        $partes[] = "
            SELECT
                COALESCE(cp.latitud,  t.latitud_fin,  t.latitud_inicio)  AS lat,
                COALESCE(cp.longitud, t.longitud_fin, t.longitud_inicio) AS lng,
                'crediticia' AS tipo
            FROM encuesta_crediticia ecr
            INNER JOIN tarea t ON t.id = ecr.tarea_id
            LEFT  JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE COALESCE(cp.latitud,  t.latitud_fin,  t.latitud_inicio)  IS NOT NULL
              AND COALESCE(cp.longitud, t.longitud_fin, t.longitud_inicio) IS NOT NULL
        ";
    }
    if (in_array('negocio', $tipos)) {
        $partes[] = "
            SELECT
                COALESCE(cp.latitud,  t.latitud_fin,  t.latitud_inicio)  AS lat,
                COALESCE(cp.longitud, t.longitud_fin, t.longitud_inicio) AS lng,
                'negocio' AS tipo
            FROM encuesta_negocio en
            INNER JOIN tarea t ON t.id = en.tarea_id
            LEFT  JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
            WHERE COALESCE(cp.latitud,  t.latitud_fin,  t.latitud_inicio)  IS NOT NULL
              AND COALESCE(cp.longitud, t.longitud_fin, t.longitud_inicio) IS NOT NULL
        ";
    }

    $sql = implode(" UNION ALL ", $partes);

    $stmt = $pdo->query($sql);
    foreach ($stmt->fetchAll() as $row) {
        $lat = (float)$row['lat'];
        $lng = (float)$row['lng'];
        // Descarta coordenadas 0,0 (georeferenciación inválida/no capturada)
        if ($lat == 0.0 && $lng == 0.0) continue;
        $puntos[] = [$lat, $lng, 0.6];
        $conteo[$row['tipo']] = ($conteo[$row['tipo']] ?? 0) + 1;
    }
} catch (Throwable $e) {
    $error_msg = $e->getMessage();
    error_log('[api_mapa_calor_encuestas] ' . $error_msg);
}

echo json_encode([
    'status' => $error_msg ? 'error' : 'ok',
    'total'   => count($puntos),
    'conteo'  => $conteo,
    'puntos'  => $puntos,
    'error'   => $error_msg ?: null,
], JSON_UNESCAPED_UNICODE);

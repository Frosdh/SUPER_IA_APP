<?php
// ============================================================
// admin/api_mapa_vivo_admin.php
// Endpoint AJAX para el Mapa en Vivo del panel Super Admin / Gerente.
// Devuelve la ubicación de asesores conectados, con el color de
// equipo (por supervisor) y soporte para filtrar en cascada por
// banco/cooperativa (unidad_bancaria) -> gerente (jefe_agencia) ->
// supervisor -> asesor.
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

// Paleta de colores estable por equipo (supervisor)
$PALETTE = [
    '#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
    '#008080', '#f032e6', '#9a6324', '#e6a400', '#42d4f4',
    '#800000', '#000075', '#a9a9a9', '#469990', '#d4a017',
];
function colorForId(array $palette, ?string $id): string {
    if (!$id) return '#4363d8';
    $hash = crc32($id);
    return $palette[$hash % count($palette)];
}

$banco_id      = isset($_GET['banco_id'])      && $_GET['banco_id']      !== '' ? $_GET['banco_id']      : null;
$gerente_id    = isset($_GET['gerente_id'])    && $_GET['gerente_id']    !== '' ? $_GET['gerente_id']    : null;
$supervisor_id = isset($_GET['supervisor_id']) && $_GET['supervisor_id'] !== '' ? $_GET['supervisor_id'] : null;
$asesor_id     = isset($_GET['asesor_id'])     && $_GET['asesor_id']     !== '' ? $_GET['asesor_id']     : null;

$ubicaciones = [];
$error_msg   = '';

try {
    // Asegurar que la tabla de presencia existe (misma que usa el resto del sistema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS asesor_presencia (
        asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
        estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $sql = "
        SELECT DISTINCT
            ua.asesor_id,
            ua.latitud, ua.longitud, ua.timestamp,
            COALESCE(ua.precision_m, 0) AS precision_m,
            au.nombre  AS asesor_nombre,
            sup.id     AS supervisor_id,
            su.nombre  AS supervisor_nombre,
            ja.id      AS gerente_id,
            ju.nombre  AS gerente_nombre,
            ag.unidad_bancaria_id AS banco_id,
            ub.nombre  AS banco_nombre
        FROM ubicacion_asesor ua
        INNER JOIN asesor       a   ON a.id   = ua.asesor_id
        INNER JOIN usuario      au  ON au.id  = a.usuario_id
        INNER JOIN supervisor   sup ON sup.id = a.supervisor_id
        INNER JOIN usuario      su  ON su.id  = sup.usuario_id
        INNER JOIN jefe_agencia ja  ON ja.id  = sup.jefe_agencia_id
        INNER JOIN usuario      ju  ON ju.id  = ja.usuario_id
        LEFT  JOIN agencia        ag ON ag.id = ja.agencia_id
        LEFT  JOIN unidad_bancaria ub ON ub.id = ag.unidad_bancaria_id
        LEFT  JOIN asesor_presencia ap ON ap.asesor_id = ua.asesor_id
        WHERE ua.timestamp >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND ua.latitud  IS NOT NULL
          AND ua.longitud IS NOT NULL
          AND COALESCE(ap.estado, 'conectado') != 'desconectado'
    ";
    $params = [];
    if ($banco_id !== null) {
        $sql .= " AND ag.unidad_bancaria_id = :banco_id";
        $params[':banco_id'] = $banco_id;
    }
    if ($gerente_id !== null) {
        $sql .= " AND ja.id = :gerente_id";
        $params[':gerente_id'] = $gerente_id;
    }
    if ($supervisor_id !== null) {
        $sql .= " AND sup.id = :supervisor_id";
        $params[':supervisor_id'] = $supervisor_id;
    }
    if ($asesor_id !== null) {
        $sql .= " AND a.id = :asesor_id";
        $params[':asesor_id'] = $asesor_id;
    }
    $sql .= " ORDER BY ua.asesor_id DESC, ua.timestamp DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset($seen[$row['asesor_id']])) continue;
        $seen[$row['asesor_id']] = true;
        $row['color'] = colorForId($PALETTE, $row['supervisor_id']);
        $ubicaciones[] = $row;
    }

    // Listas para poblar/actualizar los filtros del frontend (cascada
    // banco -> gerente -> supervisor -> asesor)
    $bancos = $pdo->query("
        SELECT id, nombre
        FROM unidad_bancaria
        WHERE activo = 1
        ORDER BY nombre ASC
    ")->fetchAll();

    $gerentes = $pdo->query("
        SELECT ja.id, ju.nombre, ag.unidad_bancaria_id AS banco_id
        FROM jefe_agencia ja
        INNER JOIN usuario ju ON ju.id = ja.usuario_id
        LEFT  JOIN agencia ag ON ag.id = ja.agencia_id
        ORDER BY ju.nombre ASC
    ")->fetchAll();

    $supervisores = $pdo->query("
        SELECT sup.id, su.nombre, sup.jefe_agencia_id
        FROM supervisor sup
        INNER JOIN usuario su ON su.id = sup.usuario_id
        ORDER BY su.nombre ASC
    ")->fetchAll();

    $asesores = $pdo->query("
        SELECT a.id, au.nombre, a.supervisor_id
        FROM asesor a
        INNER JOIN usuario au ON au.id = a.usuario_id
        ORDER BY au.nombre ASC
    ")->fetchAll();

} catch (Throwable $e) {
    $error_msg = $e->getMessage();
    error_log('[api_mapa_vivo_admin] ' . $error_msg);
}

echo json_encode([
    'status'       => $error_msg ? 'error' : 'ok',
    'ts'           => date('H:i:s'),
    'total'        => count($ubicaciones),
    'ubicaciones'  => $ubicaciones,
    'bancos'       => $bancos ?? [],
    'gerentes'     => $gerentes ?? [],
    'supervisores' => $supervisores ?? [],
    'asesores'     => $asesores ?? [],
    'error'        => $error_msg ?: null,
], JSON_UNESCAPED_UNICODE);

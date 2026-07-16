<?php
// ============================================================
// admin/api_mapa_vivo_admin.php
// Endpoint AJAX para el Mapa en Vivo del panel Super Admin / Gerente.
//
// Antes: solo devolvía asesores con un ping GPS en los últimos 2
// minutos, así que si nadie estaba enviando ubicación en ese
// instante el mapa y el filtro por banco quedaban vacíos.
//
// Ahora: replica el mismo patrón que usa el Mapa en Vivo del
// supervisor (mapa_vivo_superIA.php) -> devuelve TODOS los
// asesores que calzan con el filtro (banco -> gerente ->
// supervisor -> asesor), cada uno con su ÚLTIMA ubicación
// conocida (haya sido hace 10 segundos o hace 3 días) y un
// flag `online` para poder pintarlos como conectados/desconectados,
// igual que hace el panel del supervisor.
// ============================================================
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

// Cualquier warning/notice de PHP que se imprima antes del JSON (muy común
// en XAMPP con display_errors=On, p.ej. deprecations de PHP 8.1) rompe el
// parseo en el frontend y ahí se ve como "Sin conexión con el servidor"
// aunque el servidor sí respondió. Este buffer nos asegura devolver SIEMPRE
// un JSON válido, descartando cualquier salida accidental.
ob_start();

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
$totalOnline = 0;

try {
    // Asegurar que la tabla de presencia existe (misma que usa el resto del sistema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS asesor_presencia (
        asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
        estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Base: TODOS los asesores del sistema (con o sin ping reciente) ──
    // LEFT JOIN a la última fila de ubicacion_asesor por asesor, igual
    // que hace $subUltima en mapa_vivo_superIA.php, para poder mostrar
    // la "última ubicación conocida" de un asesor desconectado.
    $sql = "
        SELECT
            a.id AS asesor_id,
            au.nombre  AS asesor_nombre,
            sup.id     AS supervisor_id,
            su.nombre  AS supervisor_nombre,
            ja.id      AS gerente_id,
            ju.nombre  AS gerente_nombre,
            ag.unidad_bancaria_id AS banco_id,
            ub.nombre  AS banco_nombre,
            COALESCE(ap.estado, 'desconectado') AS estado,
            ult.latitud     AS latitud,
            ult.longitud    AS longitud,
            ult.timestamp   AS ultima_vez,
            COALESCE(ult.precision_m, 0) AS precision_m
        FROM asesor        a
        INNER JOIN usuario      au  ON au.id  = a.usuario_id
        INNER JOIN supervisor   sup ON sup.id = a.supervisor_id
        INNER JOIN usuario      su  ON su.id  = sup.usuario_id
        INNER JOIN jefe_agencia ja  ON ja.id  = sup.jefe_agencia_id
        INNER JOIN usuario      ju  ON ju.id  = ja.usuario_id
        LEFT  JOIN agencia         ag ON ag.id = ja.agencia_id
        LEFT  JOIN unidad_bancaria ub ON ub.id = ag.unidad_bancaria_id
        LEFT  JOIN asesor_presencia ap ON ap.asesor_id = a.id
        LEFT  JOIN (
            SELECT ua1.asesor_id, ua1.latitud, ua1.longitud, ua1.timestamp, ua1.precision_m
            FROM ubicacion_asesor ua1
            INNER JOIN (
                SELECT asesor_id, MAX(timestamp) AS max_ts
                FROM ubicacion_asesor
                GROUP BY asesor_id
            ) latest ON latest.asesor_id = ua1.asesor_id AND latest.max_ts = ua1.timestamp
        ) ult ON ult.asesor_id = a.id
        WHERE 1=1
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
    $sql .= " ORDER BY au.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $now = time();
    foreach ($stmt->fetchAll() as $row) {
        // Online = presencia marcada como 'conectado', o bien un ping
        // GPS dentro de los últimos 2 minutos (igual que el resto del
        // sistema, que envía pings cada 15s).
        $online = ($row['estado'] === 'conectado');
        if (!$online && $row['ultima_vez']) {
            if ($now - strtotime($row['ultima_vez']) <= 120) $online = true;
        }
        if ($online) $totalOnline++;

        $row['online']    = $online;
        $row['latitud']   = $row['latitud']  !== null ? (float)$row['latitud']  : null;
        $row['longitud']  = $row['longitud'] !== null ? (float)$row['longitud'] : null;
        $row['color']     = colorForId($PALETTE, $row['supervisor_id']);
        $ubicaciones[]     = $row;
    }

    // Listas para poblar/actualizar los filtros del frontend (cascada
    // banco -> gerente -> supervisor -> asesor). Estas listas quedan
    // SIN filtrar para que el frontend pueda repoblar los combos.
    //
    // Nota: antes se filtraba `WHERE activo = 1`, pero eso dejaba fuera
    // bancos/cooperativas que sí tienen asesores operando (igual que
    // hacía el selector de registro_supervisor.php, que tampoco filtra
    // por `activo`). Se quita esa condición para que el combo de
    // búsqueda muestre siempre todos los bancos/cooperativas reales.
    $bancos = $pdo->query("
        SELECT id, nombre
        FROM unidad_bancaria
        ORDER BY nombre ASC
    ")->fetchAll();

    $gerentes = $pdo->query("
        SELECT ja.id, ju.nombre, ag.unidad_bancaria_id AS banco_id
        FROM jefe_agencia ja
        INNER JOIN usuario ju ON ju.id = ja.usuario_id
        LEFT  JOIN agencia ag ON ag.id = ja.agencia_id
        ORDER BY ju.nombre ASC
    ")->fetchAll();

    // Se agrega banco_id para poder filtrar supervisores directamente al
    // elegir un banco, sin obligar a escoger primero un gerente.
    $supervisores = $pdo->query("
        SELECT sup.id, su.nombre, sup.jefe_agencia_id,
               ag.unidad_bancaria_id AS banco_id
        FROM supervisor sup
        INNER JOIN usuario su ON su.id = sup.usuario_id
        INNER JOIN jefe_agencia ja ON ja.id = sup.jefe_agencia_id
        LEFT  JOIN agencia ag ON ag.id = ja.agencia_id
        ORDER BY su.nombre ASC
    ")->fetchAll();

    // Igual que arriba: se agregan gerente_id y banco_id para poder
    // filtrar asesores directamente al elegir un banco (o un gerente),
    // sin obligar a pasar primero por el supervisor.
    $asesores = $pdo->query("
        SELECT a.id, au.nombre, a.supervisor_id,
               sup.jefe_agencia_id AS gerente_id,
               ag.unidad_bancaria_id AS banco_id
        FROM asesor a
        INNER JOIN usuario au ON au.id = a.usuario_id
        INNER JOIN supervisor sup ON sup.id = a.supervisor_id
        INNER JOIN jefe_agencia ja ON ja.id = sup.jefe_agencia_id
        LEFT  JOIN agencia ag ON ag.id = ja.agencia_id
        ORDER BY au.nombre ASC
    ")->fetchAll();

} catch (Throwable $e) {
    $error_msg = $e->getMessage();
    error_log('[api_mapa_vivo_admin] ' . $error_msg);
}

// Descartar cualquier warning/notice/HTML que se haya colado en el buffer
// y que rompería el JSON en el navegador.
$stray = ob_get_clean();
if ($stray !== '' && trim($stray) !== '') {
    error_log('[api_mapa_vivo_admin] Salida inesperada suprimida: ' . substr($stray, 0, 800));
}

echo json_encode([
    'status'        => $error_msg ? 'error' : 'ok',
    'ts'            => date('H:i:s'),
    'total'         => $totalOnline,
    'total_asesores'=> count($ubicaciones),
    'ubicaciones'   => $ubicaciones,
    'bancos'        => $bancos ?? [],
    'gerentes'      => $gerentes ?? [],
    'supervisores'  => $supervisores ?? [],
    'asesores'      => $asesores ?? [],
    'error'         => $error_msg ?: null,
], JSON_UNESCAPED_UNICODE);

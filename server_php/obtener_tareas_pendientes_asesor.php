<?php
// ============================================================
// obtener_tareas_pendientes_asesor.php
// Lista tareas pendientes/programadas de un asesor (mobile)
// ============================================================

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metodo no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$usuario_id   = trim($_POST['usuario_id'] ?? '');
$asesor_id_in = trim($_POST['asesor_id'] ?? '');
$desde        = trim($_POST['desde'] ?? '');
// Filtro opcional por tipo de tarea (ej: 'recuperacion'). Vacío = todos los tipos.
$tipo_filter  = trim($_POST['tipo'] ?? '');

if ($usuario_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'usuario_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($desde === '') {
    $desde = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
    echo json_encode(['status' => 'error', 'message' => 'desde invalido (YYYY-MM-DD)'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1. Priorizar asesor_id del POST (si viene)
    // 2. Si no viene, resolver desde usuario_id
    $asesor_id = $asesor_id_in;

    if ($asesor_id === '') {
        $st = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
        $st->bind_param('s', $usuario_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if ($row && !empty($row['id'])) {
            $asesor_id = (string)$row['id'];
        }
    }

    if ($asesor_id === '') {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo determinar el ID de asesor'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Asegurar que el ENUM de tipo_tarea incluya nueva_cita_inversion
    $colTarea = $conn->query("SHOW COLUMNS FROM tarea LIKE 'tipo_tarea'")->fetch_assoc();
    if ($colTarea && strpos($colTarea['Type'], "'nueva_cita_inversion'") === false) {
        $conn->query("ALTER TABLE tarea MODIFY COLUMN tipo_tarea ENUM(
            'prospecto_nuevo','visita_frio','evaluacion','recuperacion',
            'post_venta','represtamo','documentos_pendientes',
            'nueva_cita_campo','nueva_cita_oficina','nueva_cita_inversion',
            'levantamiento','seguimiento'
        ) NOT NULL DEFAULT 'prospecto_nuevo'");
    }

    // Asegurar columnas para selección diaria / fijado (migración no destructiva)
    foreach ([
        'estado_seleccion_prev' => "ADD COLUMN estado_seleccion_prev VARCHAR(20) DEFAULT NULL AFTER estado",
        'seleccionada_dia'      => "ADD COLUMN seleccionada_dia VARCHAR(10) DEFAULT NULL",
        'seleccion_fijada'      => "ADD COLUMN seleccion_fijada TINYINT(1) DEFAULT 0",
        'fecha_seleccion_fijada'=> "ADD COLUMN fecha_seleccion_fijada DATE DEFAULT NULL",
        'motivo_descarte'       => "ADD COLUMN motivo_descarte TEXT DEFAULT NULL",
        'descartada_at'         => "ADD COLUMN descartada_at DATETIME DEFAULT NULL",
    ] as $col => $ddl) {
        $check = $conn->query("SHOW COLUMNS FROM tarea LIKE '$col'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE tarea $ddl");
        }
    }

    // Regla 8 horas: lo que quedó 'en_proceso' pasa a 'pendiente' para el día siguiente
    // (limpia selección y desbloquea)
    $stExp = $conn->prepare(
        "UPDATE tarea
         SET estado='pendiente',
             fecha_programada = DATE_ADD(DATE(seleccionada_at), INTERVAL 1 DAY),
             estado_seleccion_prev = NULL,
             seleccionada_dia = NULL,
             seleccionada_at  = NULL,
             seleccion_fijada = 0,
             seleccion_fijada_at = NULL
         WHERE asesor_id = ?
           AND estado = 'en_proceso'
           AND seleccionada_at IS NOT NULL
           AND seleccionada_at < (NOW() - INTERVAL 8 HOUR)"
    );
    if ($stExp) {
        $stExp->bind_param('s', $asesor_id);
        $stExp->execute();
        $stExp->close();
    }

    // Cláusula de tipo:
    // - Si viene filtro explícito → solo ese tipo
    // - Si no hay filtro → excluir siempre 'recuperacion' (esas van a AgendaRecuperacion)
    if ($tipo_filter !== '') {
        $tipoClause = "t.tipo_tarea = '$tipo_filter' AND";
    } else {
        $tipoClause = "t.tipo_tarea != 'recuperacion' AND";
    }

    $sql = "
        SELECT
            t.id,
            t.tipo_tarea,
            t.estado,
            t.fecha_programada,
            t.hora_programada,
            t.fecha_realizada,
            t.hora_realizada,
            t.observaciones,
            t.seleccionada_dia,
            t.seleccionada_at,
            t.seleccion_fijada,
            t.seleccion_fijada_at,
            t.motivo_descarte,
            t.descartada_at,
            t.created_at,
            t.asesor_id,
            cp.id                      AS cliente_id,
            cp.nombre                  AS cliente_nombre,
            COALESCE(cp.cedula,   '')  AS cliente_cedula,
            COALESCE(cp.telefono, '')  AS cliente_telefono,
            cp.ciudad                  AS cliente_ciudad,
            cp.direccion               AS cliente_direccion,
            cp.latitud                 AS cliente_latitud,
            cp.longitud                AS cliente_longitud,
            (SELECT DATE_FORMAT(cred.created_at, '%Y-%m-%d')
             FROM credito_proceso cred
             WHERE cred.cliente_prospecto_id = t.cliente_prospecto_id
             ORDER BY cred.created_at DESC
             LIMIT 1)                 AS fecha_credito
        FROM tarea t
        LEFT JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
        WHERE $tipoClause (
            (
                (t.asesor_id = ? OR TRIM(t.asesor_id) = TRIM(?))
                AND (
                    t.estado IN ('programada','pendiente','postergada','en_proceso')
                    OR (t.estado = 'completada' AND t.fecha_realizada >= ?)
                    OR (t.estado = 'cancelada' AND COALESCE(t.descartada_at, t.created_at) >= DATE_SUB(?, INTERVAL 30 DAY))
                )
            )
            OR
            (
                (t.asesor_id IS NULL OR TRIM(t.asesor_id) = '')
                AND t.seleccion_fijada = 0
                AND t.estado IN ('programada','pendiente','postergada')
            )
        )
        ORDER BY
          CASE WHEN t.estado = 'completada' THEN t.fecha_realizada ELSE t.fecha_programada END ASC,
          t.created_at DESC,
          CASE WHEN t.estado = 'completada' THEN t.hora_realizada  ELSE t.hora_programada  END ASC
        LIMIT 300
    ";

    $st = $conn->prepare($sql);
    // Bind de parámetros: asesor_id (1), asesor_id (2), desde -> completadas (3), desde -> canceladas (4)
    $st->bind_param('ssss', $asesor_id, $asesor_id, $desde, $desde);
    $st->execute();
    $res = $st->get_result();

    $tareas = [];
    while ($r = $res->fetch_assoc()) {
        $tareaAsesorId = (string)($r['asesor_id'] ?? '');
        $esPool = ($tareaAsesorId === '' || $tareaAsesorId === null);
        $tareas[] = [
            'id' => (string)($r['id'] ?? ''),
            'tipo_tarea' => (string)($r['tipo_tarea'] ?? ''),
            'estado' => (string)($r['estado'] ?? ''),
            'fecha_programada' => (string)($r['fecha_programada'] ?? ''),
            'hora_programada' => (string)($r['hora_programada'] ?? ''),
            'fecha_realizada' => (string)($r['fecha_realizada'] ?? ''),
            'hora_realizada' => (string)($r['hora_realizada'] ?? ''),
            'observaciones' => (string)($r['observaciones'] ?? ''),
            'seleccionada_dia' => (string)($r['seleccionada_dia'] ?? ''),
            'seleccionada_at'  => (string)($r['seleccionada_at'] ?? ''),
            'seleccion_fijada' => (string)($r['seleccion_fijada'] ?? '0'),
            'seleccion_fijada_at' => (string)($r['seleccion_fijada_at'] ?? ''),
            'motivo_descarte' => (string)($r['motivo_descarte'] ?? ''),
            'descartada_at' => (string)($r['descartada_at'] ?? ''),
            'es_pool' => $esPool ? '1' : '0',   // 1 = tarea disponible para cualquier asesor
            'cliente_id'        => (string)($r['cliente_id'] ?? ''),
            'cliente_nombre'    => (string)($r['cliente_nombre'] ?? ''),
            'cliente_cedula'    => (string)($r['cliente_cedula'] ?? ''),
            'cliente_telefono'  => (string)($r['cliente_telefono'] ?? ''),
            'cliente_ciudad'    => (string)($r['cliente_ciudad'] ?? ''),
            'cliente_direccion' => (string)($r['cliente_direccion'] ?? ''),
            'cliente_latitud'   => (string)($r['cliente_latitud'] ?? ''),
            'cliente_longitud'  => (string)($r['cliente_longitud'] ?? ''),
            'fecha_credito'     => (string)($r['fecha_credito'] ?? ''),
        ];
    }
    $st->close();

    // DEBUG: Log if nueva_cita_inversion is found
    $countInv = 0;
    foreach ($tareas as $tx) {
        if ($tx['tipo_tarea'] === 'nueva_cita_inversion') $countInv++;
    }
    error_log("[obtener_tareas_pendientes_asesor] Total: " . count($tareas) . " | Inversion: $countInv | Asesor: $asesor_id");

    // DEBUG: Get total tasks for this asesor in DB
    $stDbg = $conn->prepare("SELECT COUNT(*) as total FROM tarea WHERE asesor_id = ?");
    $stDbg->bind_param('s', $asesor_id);
    $stDbg->execute();
    $dbgRow = $stDbg->get_result()->fetch_assoc();
    $totalDB = $dbgRow['total'] ?? 0;
    $stDbg->close();

    // DEBUG: Get enum definition
    $colT = $conn->query("SHOW COLUMNS FROM tarea LIKE 'tipo_tarea'")->fetch_assoc();
    $enumDef = $colT ? $colT['Type'] : 'unknown';

    // DEBUG: Collect all unique types found in results
    $typesFound = [];
    echo json_encode([
        'status' => 'success',
        'asesor_id' => $asesor_id,
        'desde' => $desde,
        'total_db' => $totalDB,
        'tareas' => $tareas,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[obtener_tareas_pendientes_asesor] ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor',
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn)) {
        try { $conn->close(); } catch (Throwable $_) {}
    }
}

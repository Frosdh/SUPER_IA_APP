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
$is_admin      = isset($_SESSION['admin_logged_in'])      && $_SESSION['admin_logged_in']      === true;
if (!$is_supervisor && !$is_admin) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$supervisor_id = $is_supervisor ? ($_SESSION['supervisor_id'] ?? null) : null;
$asesor_id     = trim($_GET['asesor_id'] ?? '');

if ($asesor_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'asesor_id requerido']);
    exit;
}

try {
    $asesorNombre = '';
    if ($is_supervisor) {
        if (!$supervisor_id) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'supervisor_id no encontrado']);
            exit;
        }
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
    } else {
        // Admin: solo verificar que el asesor existe
        $stCheck = $conn->prepare("SELECT u.nombre FROM asesor a JOIN usuario u ON u.id=a.usuario_id WHERE a.id=? LIMIT 1");
        if ($stCheck) { $stCheck->bind_param('s', $asesor_id); $stCheck->execute(); $r = $stCheck->get_result()->fetch_assoc(); $asesorNombre = $r['nombre'] ?? ''; $stCheck->close(); }
    }

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
                $lat = (float)$pt['latitud'];
                $lng = (float)$pt['longitud'];
                if (abs($lat) < 1e-8 && abs($lng) < 1e-8) continue;
                $puntos[] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'ts'  => $pt['timestamp'],
                ];
            }
            $stPts->close();
        } else {
            $puntos = [];
        }

        // ── Anclar el tramo a las coordenadas reales de inicio/fin ──
        // El GPS periódico (cada ~15s) puede llegar tarde o con huecos
        // (arranque en frío del GPS, servicio en segundo plano recién
        // iniciado, etc.). Si eso pasa, el tramo quedaba con 0-1 puntos
        // y la línea de la ruta no se dibujaba, aunque el asesor sí se
        // haya movido. Para evitarlo, siempre agregamos como extremos
        // el punto de inicio de sesión/segmento y el punto donde se
        // completó la actividad (ambos ya se guardan en ruta_segmento),
        // así la línea siempre conecta login → actividad completada
        // incluso si faltan puntos intermedios.
        $inicioValido = $seg['inicio_lat'] !== null && $seg['inicio_lng'] !== null
            && !(abs((float)$seg['inicio_lat']) < 1e-8 && abs((float)$seg['inicio_lng']) < 1e-8);
        $finValido = $seg['fin_lat'] !== null && $seg['fin_lng'] !== null
            && !(abs((float)$seg['fin_lat']) < 1e-8 && abs((float)$seg['fin_lng']) < 1e-8);

        if ($inicioValido) {
            $primerPto = $puntos[0] ?? null;
            $esMismoPunto = $primerPto && abs($primerPto['lat'] - (float)$seg['inicio_lat']) < 1e-5
                && abs($primerPto['lng'] - (float)$seg['inicio_lng']) < 1e-5;
            if (!$esMismoPunto) {
                array_unshift($puntos, [
                    'lat' => (float)$seg['inicio_lat'],
                    'lng' => (float)$seg['inicio_lng'],
                    'ts'  => $seg['inicio_at'],
                ]);
            }
        }
        if ($finValido) {
            $ultimoPto = $puntos[count($puntos) - 1] ?? null;
            $esMismoPunto = $ultimoPto && abs($ultimoPto['lat'] - (float)$seg['fin_lat']) < 1e-5
                && abs($ultimoPto['lng'] - (float)$seg['fin_lng']) < 1e-5;
            if (!$esMismoPunto) {
                $puntos[] = [
                    'lat' => (float)$seg['fin_lat'],
                    'lng' => (float)$seg['fin_lng'],
                    'ts'  => $finAt,
                ];
            }
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

    // ══════════════════════════════════════════════════════════════════════
    // FALLBACK: Si no hay segmentos registrados en ruta_segmento,
    // construir segmentos virtuales desde ubicacion_asesor + tareas
    // (para asesores que usaron versiones anteriores de la app o cuando
    //  api_cerrar_segmento falló silenciosamente).
    // ══════════════════════════════════════════════════════════════════════
    if (empty($resultado)) {
        $COLORES_FB = ['#3B82F6','#10B981','#F59E0B','#8B5CF6','#EF4444','#06B6D4','#EC4899','#84CC16'];

        // 1. Todos los puntos GPS del asesor en el día, ordenados por tiempo
        $stAllPts = $conn->prepare("
            SELECT latitud, longitud, timestamp
            FROM ubicacion_asesor
            WHERE asesor_id = ?
              AND DATE(timestamp) = ?
            ORDER BY timestamp ASC
        ");
        $todosGPS = [];
        if ($stAllPts) {
            $stAllPts->bind_param('ss', $asesor_id, $fecha);
            $stAllPts->execute();
            $resAllPts = $stAllPts->get_result();
            while ($pt = $resAllPts->fetch_assoc()) {
                $lat = (float)$pt['latitud'];
                $lng = (float)$pt['longitud'];
                if (abs($lat) < 1e-8 && abs($lng) < 1e-8) continue;
                $todosGPS[] = $pt;
            }
            $stAllPts->close();
        }

        if (!empty($todosGPS)) {
            // 2. Tareas completadas del asesor en el día (con encuesta), ordenadas por hora
            $stTareas = $conn->prepare("
                SELECT t.id, t.tipo_tarea,
                       CONCAT(t.fecha_realizada, ' ', t.hora_realizada) AS completada_at,
                       t.latitud_fin, t.longitud_fin,
                       cp.nombre AS cliente_nombre
                FROM tarea t
                JOIN cliente_prospecto cp ON cp.id = t.cliente_prospecto_id
                LEFT JOIN encuesta_comercial ec ON ec.tarea_id = t.id
                WHERE t.asesor_id = ?
                  AND t.estado = 'completada'
                  AND t.fecha_realizada = ?
                  AND ec.id IS NOT NULL
                ORDER BY t.hora_realizada ASC
            ");
            $tareas = [];
            if ($stTareas) {
                $stTareas->bind_param('ss', $asesor_id, $fecha);
                $stTareas->execute();
                $resTareas = $stTareas->get_result();
                while ($t = $resTareas->fetch_assoc()) {
                    $tareas[] = $t;
                }
                $stTareas->close();
            }

            // 3. Construir cortes de tiempo: inicio de sesión + cada tarea completada
            $primerGPS  = $todosGPS[0]['timestamp'];
            $ultimoGPS  = $todosGPS[count($todosGPS) - 1]['timestamp'];

            // Cortes: [inicio_session, tarea1, tarea2, ..., fin_dia]
            // Nota: antes se excluían las tareas completadas ANTES del primer
            // GPS del día (`> $primerGPS`), lo que hacía desaparecer por
            // completo el tramo "login → primera actividad" cuando el GPS
            // tardaba en arrancar y la primera encuesta se completaba antes
            // de recibir el primer ping. Ahora se incluyen siempre; el corte
            // se ordena junto con el resto y, si cae antes de $primerGPS,
            // el segmento correspondiente simplemente no tendrá puntos GPS
            // intermedios (se resuelve más abajo con el propio punto de la
            // tarea como ancla).
            $cortes = [$primerGPS];
            foreach ($tareas as $t) {
                $cortes[] = $t['completada_at'];
            }
            // El último segmento cierra en el último GPS del día
            $cortes[] = $ultimoGPS;
            // Eliminar duplicados y ordenar
            $cortes = array_unique($cortes);
            sort($cortes);

            // 4. Para cada par de cortes → un segmento virtual
            $numSeg = 0;
            for ($i = 0; $i < count($cortes) - 1; $i++) {
                $desde = $cortes[$i];
                $hasta = $cortes[$i + 1];

                // Puntos GPS en este rango
                $puntosSegmento = array_filter($todosGPS, function($p) use ($desde, $hasta) {
                    return $p['timestamp'] >= $desde && $p['timestamp'] <= $hasta;
                });
                $puntosSegmento = array_values($puntosSegmento);

                // ¿Qué tarea está asociada a este segmento? (la que cierra este tramo)
                $tareaAsoc = null;
                foreach ($tareas as $t) {
                    if ($t['completada_at'] === $hasta || abs(strtotime($t['completada_at']) - strtotime($hasta)) <= 5) {
                        $tareaAsoc = $t;
                        break;
                    }
                }

                // Si no hay ningún ping GPS dentro de este tramo (por ejemplo,
                // la actividad se completó antes de que arrancara el GPS del
                // día), pero sí sabemos dónde se completó la tarea, igual
                // generamos el segmento anclado a esa coordenada: así al
                // menos se ve el marcador/tramo en vez de que desaparezca.
                if (empty($puntosSegmento)) {
                    if ($tareaAsoc && $tareaAsoc['latitud_fin'] !== null && $tareaAsoc['longitud_fin'] !== null) {
                        $puntosSegmento = [[
                            'latitud'   => $tareaAsoc['latitud_fin'],
                            'longitud'  => $tareaAsoc['longitud_fin'],
                            'timestamp' => $hasta,
                        ]];
                    } else {
                        continue;
                    }
                }

                $numSeg++;
                $color = $COLORES_FB[($numSeg - 1) % count($COLORES_FB)];

                $primerPunto = $puntosSegmento[0];
                $ultimoPunto = $puntosSegmento[count($puntosSegmento) - 1];

                // Estado: el último segmento es 'activo' si no hay tarea que lo cierre
                $esUltimo = ($i === count($cortes) - 2);
                $estado = ($tareaAsoc !== null) ? 'completado' : ($esUltimo ? 'activo' : 'completado');

                $resultado[] = [
                    'segmento_id'    => 'virtual_' . $asesor_id . '_' . $numSeg,
                    'asesor_id'      => $asesor_id,
                    'asesor_nombre'  => $asesorNombre,
                    'numero'         => $numSeg,
                    'estado'         => $estado,
                    'color'          => $color,
                    'inicio_at'      => $desde,
                    'fin_at'         => $tareaAsoc ? $hasta : null,
                    'inicio_lat'     => (float)$primerPunto['latitud'],
                    'inicio_lng'     => (float)$primerPunto['longitud'],
                    'fin_lat'        => $tareaAsoc ? (
                        $tareaAsoc['latitud_fin']  !== null ? (float)$tareaAsoc['latitud_fin']  :
                        (float)$ultimoPunto['latitud']
                    ) : null,
                    'fin_lng'        => $tareaAsoc ? (
                        $tareaAsoc['longitud_fin'] !== null ? (float)$tareaAsoc['longitud_fin'] :
                        (float)$ultimoPunto['longitud']
                    ) : null,
                    'tarea_id'       => $tareaAsoc ? $tareaAsoc['id']           : null,
                    'tarea_tipo'     => $tareaAsoc ? $tareaAsoc['tipo_tarea']    : null,
                    'cliente_nombre' => $tareaAsoc ? $tareaAsoc['cliente_nombre']: null,
                    'puntos'         => array_map(fn($p) => [
                        'lat' => (float)$p['latitud'],
                        'lng' => (float)$p['longitud'],
                        'ts'  => $p['timestamp'],
                    ], $puntosSegmento),
                    'total_puntos'   => count($puntosSegmento),
                    'virtual'        => true,
                ];
            }
        }
    }

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

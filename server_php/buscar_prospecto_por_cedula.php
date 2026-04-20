<?php
// ============================================================
// buscar_prospecto_por_cedula.php  —  v2026-04-20a
// Busca en cliente_prospecto por cédula y devuelve los datos
// si existe (prospecto o cliente). Usado por la app Flutter
// para prellenar la encuesta en la agenda de tareas.
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-04-20a';

function respond_json($code, $payload) {
    global $API_BUILD;
    if (!headers_sent()) {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
    }
    if (is_array($payload)) $payload['build'] = $API_BUILD;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

// Nunca devolver body vacío en fatal errors
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) return;
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    $msg = substr($err['message'] ?? '', 0, 180);
    echo json_encode(['status' => 'error', 'message' => "Error interno: $msg"]);
});

// Ping GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status' => 'ok', 'message' => 'buscar_prospecto_por_cedula alive', 'build' => $API_BUILD]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

$cedula = trim($_POST['cedula'] ?? $_GET['cedula'] ?? '');
if ($cedula === '') {
    respond_json(200, ['status' => 'error', 'message' => 'Cédula requerida']);
    exit;
}

try {
    // Buscar en cliente_prospecto por cédula
    $st = $conn->prepare(
        "SELECT cp.id, cp.nombre, cp.cedula, cp.telefono, cp.telefono2, cp.email,
                cp.direccion, cp.ciudad, cp.zona, cp.actividad, cp.nombre_empresa,
                cp.tiene_ruc, cp.tiene_rise, cp.asesor_id, cp.estado, cp.latitud, cp.longitud,
                cp.created_at
         FROM cliente_prospecto cp
         WHERE cp.cedula = ?
         LIMIT 1"
    );
    $st->bind_param('s', $cedula);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row) {
        respond_json(200, [
            'status'   => 'not_found',
            'message'  => 'No existe prospecto/cliente con esa cédula.',
            'cedula'   => $cedula,
        ]);
        exit;
    }

    // Determinar si es CLIENTE (tiene alguna ficha_producto aprobada o crédito aprobado)
    $estadoDb = strtolower((string)($row['estado'] ?? 'prospecto'));
    $es_cliente = false;

    // 1) Fichas aprobadas (crédito, cuenta o inversión)
    try {
        $stC = $conn->prepare(
            "SELECT 1 FROM ficha_producto
             WHERE cliente_cedula = ? AND estado_revision = 'aprobada'
             LIMIT 1"
        );
        if ($stC) {
            $stC->bind_param('s', $cedula);
            $stC->execute();
            $rowFp = $stC->get_result()->fetch_assoc();
            if ($rowFp) $es_cliente = true;
            $stC->close();
        }
    } catch (Throwable $ignored) {}

    // 2) Crédito formal aprobado/desembolsado
    if (!$es_cliente) {
        try {
            $cid = (string)$row['id'];
            $stK = $conn->prepare(
                "SELECT 1 FROM credito_proceso
                 WHERE cliente_prospecto_id = ?
                   AND (estado_credito IN ('aprobado','desembolsado')
                        OR estado IN ('aprobado','desembolsado'))
                 LIMIT 1"
            );
            if ($stK) {
                $stK->bind_param('s', $cid);
                $stK->execute();
                $rowCp = $stK->get_result()->fetch_assoc();
                if ($rowCp) $es_cliente = true;
                $stK->close();
            }
        } catch (Throwable $ignored) {}
    }

    // Separar nombres/apellidos (heurístico: primer token = nombre, resto = apellidos)
    $nombre_full = trim((string)($row['nombre'] ?? ''));
    $nombres = $nombre_full;
    $apellidos = '';
    if ($nombre_full !== '') {
        $parts = preg_split('/\s+/', $nombre_full, 2);
        if (is_array($parts) && count($parts) >= 1) {
            $nombres = $parts[0] ?? $nombre_full;
            $apellidos = $parts[1] ?? '';
        }
    }

    // Resolver etiqueta tipo
    $tipo = $es_cliente ? 'cliente' : (($estadoDb === 'descartado') ? 'descartado' : 'prospecto');

    respond_json(200, [
        'status'    => 'found',
        'tipo'      => $tipo,               // 'prospecto' | 'cliente' | 'descartado'
        'es_cliente'=> $es_cliente ? 1 : 0,
        'data' => [
            'id'             => (string)($row['id'] ?? ''),
            'cedula'         => (string)($row['cedula'] ?? ''),
            'nombre'         => $nombre_full,
            'nombres'        => $nombres,
            'apellidos'      => $apellidos,
            'telefono'       => (string)($row['telefono'] ?? ''),
            'celular'        => (string)($row['telefono2'] ?? ''),
            'email'          => (string)($row['email'] ?? ''),
            'direccion'      => (string)($row['direccion'] ?? ''),
            'ciudad'         => (string)($row['ciudad'] ?? ''),
            'zona'           => (string)($row['zona'] ?? ''),
            'actividad'      => (string)($row['actividad'] ?? ''),
            'nombre_empresa' => (string)($row['nombre_empresa'] ?? ''),
            'tiene_ruc'      => (int)($row['tiene_ruc']  ?? 0),
            'tiene_rise'     => (int)($row['tiene_rise'] ?? 0),
            'asesor_id'      => (string)($row['asesor_id'] ?? ''),
            'estado_db'      => $estadoDb,
            'latitud'        => isset($row['latitud']) && $row['latitud'] !== null ? (float)$row['latitud'] : null,
            'longitud'       => isset($row['longitud']) && $row['longitud'] !== null ? (float)$row['longitud'] : null,
            'created_at'     => (string)($row['created_at'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    respond_json(200, ['status' => 'error', 'message' => 'Error consultando: ' . $e->getMessage()]);
}

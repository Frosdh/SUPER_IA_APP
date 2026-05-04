<?php
// ============================================================
// buscar_prospecto_por_cedula.php  —  v2026-05-04
// Busca en cliente_prospecto por cédula y devuelve los datos
// si existe (prospecto o cliente). Usado por la app Flutter
// para prellenar la encuesta en la agenda de tareas.
// Actualizado a PDO para compatibilidad con guardar_empresa.php
// ============================================================

// CRÍTICO: Ini antes de cualquier output
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
http_response_code(200);

ob_start(); // Buffer output para evitar output no-JSON

error_reporting(E_ALL);
ini_set('display_errors', '0');

$API_BUILD = '2026-05-04';

function respond_json($code, $payload) {
    global $API_BUILD;
    http_response_code((int)$code);
    if (is_array($payload)) $payload['build'] = $API_BUILD;
    ob_end_clean(); // Limpia cualquier output anterior
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Nunca devolver body vacío en fatal errors
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    respond_json(200, ['status' => 'error', 'message' => 'Error PHP: ' . $errstr]);
    return true;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    if (!in_array((int)($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) return;
    respond_json(500, ['status' => 'error', 'message' => 'Error fatal: ' . substr($err['message'] ?? '', 0, 100)]);
});

// Ping GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond_json(200, ['status' => 'ok', 'message' => 'buscar_prospecto_por_cedula alive', 'build' => $API_BUILD]);
}

// OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Validación POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(200, ['status' => 'error', 'message' => 'Solo POST permitido']);
}

require_once __DIR__ . '/db_config.php';

// Validar que db_config tenga las credenciales
if (empty($db_host) || empty($db_name) || empty($db_user)) {
    respond_json(500, ['status' => 'error', 'message' => 'Configuración de base de datos incompleta']);
}

$cedula = trim($_POST['cedula'] ?? '');
if ($cedula === '') {
    respond_json(200, ['status' => 'error', 'message' => 'Cédula requerida']);
}

try {
    // Crear conexión PDO
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]
    );

    // Buscar en cliente_prospecto por cédula
    $st = $pdo->prepare(
        "SELECT id, nombre, cedula, telefono, telefono2, email,
                direccion, ciudad, zona, actividad, nombre_empresa,
                tiene_ruc, tiene_rise, asesor_id, estado, latitud, longitud,
                created_at
         FROM cliente_prospecto
         WHERE cedula = ?
         LIMIT 1"
    );
    
    if (!$st->execute([$cedula])) {
        respond_json(500, ['status' => 'error', 'message' => 'Error ejecutando query: ' . $st->errorInfo()[2]]);
    }
    
    $row = $st->fetch();

    if (!$row) {
        // Cédula NO encontrada → nuevo prospecto
        respond_json(200, [
            'status'   => 'not_found',
            'message'  => 'Cédula no registrada. Crea nuevo prospecto.',
            'cedula'   => $cedula,
            'tipo'     => 'nuevo'
        ]);
    }

    // Determinar si es CLIENTE (simplemente verifica estado en DB)
    $estadoDb = strtolower((string)($row['estado'] ?? 'prospecto'));
    $es_cliente = ($estadoDb === 'cliente' || $estadoDb === 'activo');

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
} catch (PDOException $e) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Error de base de datos',
        'detail' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
} catch (Exception $e) {
    respond_json(500, [
        'status' => 'error',
        'message' => 'Error inesperado',
        'detail' => $e->getMessage()
    ]);
}

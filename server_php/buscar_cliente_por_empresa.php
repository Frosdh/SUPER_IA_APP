<?php
// buscar_cliente_por_empresa.php
// Busca clientes por nombre de empresa y devuelve cliente + contacto + encuesta_negocio (si existe)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/db_config.php';

function respond_json($code, $payload) {
    if (!headers_sent()) { http_response_code((int)$code); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

$nombre_empresa = trim($_POST['nombre_empresa'] ?? '');
$limit = (int)($_POST['limit'] ?? 10);
if ($nombre_empresa === '') {
    respond_json(200, ['status'=>'error','message'=>'nombre_empresa requerido']);
    exit;
}

try {
    $q = "%" . $nombre_empresa . "%";
    $st = $conn->prepare("SELECT id, nombre, cedula, telefono, telefono2 AS celular, nombre_empresa, ciudad, direccion, estado FROM cliente_prospecto WHERE nombre_empresa LIKE ? LIMIT ?");
    $st->bind_param('si', $q, $limit);
    $st->execute();
    $res = $st->get_result();
    $items = [];
    while ($r = $res->fetch_assoc()) {
        $cliente = $r;
        // buscar la ultima tarea asociada (si existe)
        $tarea = null;
        $stt = $conn->prepare("SELECT id, tipo_tarea, estado, fecha_programada, hora_programada FROM tarea WHERE cliente_prospecto_id = ? ORDER BY fecha_programada DESC LIMIT 1");
        $stt->bind_param('s', $r['id']);
        $stt->execute();
        $rt = $stt->get_result()->fetch_assoc();
        $stt->close();
        if ($rt) {
            $tarea = $rt;
            // buscar encuesta_negocio por tarea_id
            $sten = $conn->prepare("SELECT * FROM encuesta_negocio WHERE tarea_id = ? LIMIT 1");
            $sten->bind_param('s', $rt['id']);
            $sten->execute();
            $ren = $sten->get_result()->fetch_assoc();
            $sten->close();
            if ($ren) $cliente['encuesta_negocio'] = $ren;
        }
        $cliente['ultima_tarea'] = $tarea;
        $items[] = $cliente;
    }
    $st->close();

    respond_json(200, ['status'=>'success','count'=>count($items),'items'=>$items]);
} catch (\Throwable $e) {
    respond_json(200, ['status'=>'error','message'=>'Error interno: '.substr($e->getMessage(),0,200)]);
}

?>
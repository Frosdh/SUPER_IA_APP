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
$limit = (int)($_POST['limit'] ?? 100);

try {
    if ($nombre_empresa === '') {
        $st = $conn->prepare(
            "SELECT cp.id, cp.nombre, cp.cedula, cp.telefono, cp.telefono2 AS celular, cp.email,
                    cp.nombre_empresa, cp.ciudad, cp.direccion, cp.estado,
                    cp.tiene_ruc, cp.tiene_rise, cp.ruc_val, cp.rise_val, cp.tipo_empresa,
                    cp.regimen_tributario, cp.numero_ruc, cp.declara_iva, cp.emite_facturas,
                    cp.lleva_contabilidad, cp.paga_cuota_rise, cp.emite_notas_venta, cp.conoce_limite_rise,
                    cp.tiene_empresa,
                    (SELECT t.id FROM tarea t
                     WHERE t.cliente_prospecto_id = cp.id
                       AND t.tipo_tarea = 'levantamiento'
                     ORDER BY t.created_at DESC LIMIT 1) as tarea_id
             FROM cliente_prospecto cp
             WHERE cp.nombre_empresa IS NOT NULL AND cp.nombre_empresa != ''
             LIMIT ?"
        );
        $st->bind_param('i', $limit);
    } else {
        $q = "%" . $nombre_empresa . "%";
        $st = $conn->prepare(
            "SELECT cp.id, cp.nombre, cp.cedula, cp.telefono, cp.telefono2 AS celular, cp.email,
                    cp.nombre_empresa, cp.ciudad, cp.direccion, cp.estado,
                    cp.tiene_ruc, cp.tiene_rise, cp.ruc_val, cp.rise_val, cp.tipo_empresa,
                    cp.regimen_tributario, cp.numero_ruc, cp.declara_iva, cp.emite_facturas,
                    cp.lleva_contabilidad, cp.paga_cuota_rise, cp.emite_notas_venta, cp.conoce_limite_rise,
                    cp.tiene_empresa,
                    (SELECT t.id FROM tarea t
                     WHERE t.cliente_prospecto_id = cp.id
                       AND t.tipo_tarea = 'levantamiento'
                     ORDER BY t.created_at DESC LIMIT 1) as tarea_id
             FROM cliente_prospecto cp
             WHERE cp.nombre_empresa LIKE ?
             LIMIT ?"
        );
        $st->bind_param('si', $q, $limit);
    }
    $st->execute();
    $res = $st->get_result();
    $items = [];

    // Verificar si encuesta_negocio ya existe en la BD
    $encNegRes    = $conn->query("SHOW TABLES LIKE 'encuesta_negocio'");
    $encNegExiste = ($encNegRes !== false && $encNegRes->num_rows > 0);

    while ($r = $res->fetch_assoc()) {
        $cliente = $r;

        // ── Última tarea ──────────────────────────────────────────────────────────
        $tarea = null;
        $stt = $conn->prepare(
            "SELECT id, tipo_tarea, estado, fecha_programada, hora_programada
             FROM tarea
             WHERE cliente_prospecto_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        $stt->bind_param('s', $r['id']);
        $stt->execute();
        $rt = $stt->get_result()->fetch_assoc();
        $stt->close();
        if ($rt) $tarea = $rt;
        $cliente['ultima_tarea'] = $tarea;

        // ── Buscar encuesta_negocio en CUALQUIER tarea del cliente ────────────────
        // No solo en la última: las tareas de seguimiento con fecha futura
        // aparecerían como "más recientes" y ocultarían el levantamiento completado.
        if ($encNegExiste) {
            try {
                // Solo mostrar badge "✓ Levantamiento completado" si la encuesta_negocio
                // proviene de una tarea de tipo 'levantamiento' (guardada desde LevantarEmpresaScreen).
                // Las encuestas iniciales (prospecto_nuevo) no crean encuesta_negocio desde
                // guardar_cliente_encuesta.php, pero esta condición garantiza consistencia.
                $sten = $conn->prepare(
                    "SELECT en.id AS en_id
                     FROM encuesta_negocio en
                     INNER JOIN tarea t ON t.id = en.tarea_id
                     WHERE t.cliente_prospecto_id = ?
                       AND t.tipo_tarea = 'levantamiento'
                     ORDER BY t.created_at DESC
                     LIMIT 1"
                );
                $sten->bind_param('s', $r['id']);
                $sten->execute();
                $ren = $sten->get_result()->fetch_assoc();
                $sten->close();
                // Solo mostrar badge si el levantamiento fue completado correctamente
                if ($ren) $cliente['encuesta_negocio'] = ['id' => $ren['en_id']];
            } catch (\Throwable $_) {
                // No bloquear si la tabla existe pero hay otro problema
            }
        }

        $items[] = $cliente;
    }
    $st->close();

    respond_json(200, ['status'=>'success','count'=>count($items),'items'=>$items]);
} catch (\Throwable $e) {
    respond_json(200, ['status'=>'error','message'=>'Error interno: '.substr($e->getMessage(),0,200)]);
}
?>

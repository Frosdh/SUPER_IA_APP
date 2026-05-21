<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_admin.php'; // provides $pdo

$result = ['status'=>'ok','alerts'=>[]];

try {
    // Solo supervisores deben recibir notificaciones en este endpoint
    if (!isset($_SESSION['supervisor_logged_in']) || $_SESSION['supervisor_logged_in'] !== true) {
        echo json_encode(['status'=>'error','message'=>'No autorizado']);
        exit;
    }

    $sess_usuario_id = $_SESSION['supervisor_id'] ?? null;
    if (!$sess_usuario_id) {
        echo json_encode(['status'=>'error','message'=>'Sesión inválida']);
        exit;
    }

    // Resolver supervisor.table id (algunas sesiones guardan usuario_id)
    $st = $pdo->prepare('SELECT id FROM supervisor WHERE usuario_id = ? LIMIT 1');
    $st->execute([$sess_usuario_id]);
    $sup_table_id = $st->fetchColumn();

    if (!$sup_table_id) {
        echo json_encode(['status'=>'error','message'=>'Supervisor no encontrado']);
        exit;
    }

    $limit = 20;
    $q = $pdo->prepare('SELECT id, tarea_id, asesor_id, campo_modificado, valor_anterior, valor_nuevo, vista_supervisor, created_at FROM alerta_modificacion WHERE supervisor_id = ? ORDER BY created_at DESC LIMIT ?');
    $q->execute([$sup_table_id, $limit]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        // Formato breve de fecha/hora para el título: dd/MM HH:ii
        $title = 'Alerta';
        try {
            $dt = new DateTime($r['created_at']);
            $title = 'Alerta · ' . $dt->format('d/m H:i');
        } catch (Throwable $_) { /* ignore date parse errors */ }

        // Obtener nombre del asesor (quien realizó la modificación en la mayoría de casos)
        $asesor_nombre = '';
        if (!empty($r['asesor_id'])) {
            try {
                $s = $pdo->prepare('SELECT u.nombre FROM asesor a JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ? LIMIT 1');
                $s->execute([$r['asesor_id']]);
                $asesor_nombre = (string)$s->fetchColumn();
            } catch (Throwable $_) { $asesor_nombre = ''; }
        }

        // Intentar extraer nombre del cliente desde valor_nuevo / valor_anterior
        $cliente_nombre = '';
        $try_fields = [$r['valor_nuevo'] ?? '', $r['valor_anterior'] ?? ''];
        foreach ($try_fields as $f) {
            if (!$f) continue;
            // buscar patrón "Cliente: NOMBRE" o "Cliente: NOMBRE ("
            if (preg_match('/Cliente\s*:\s*([^\|\(\n]+)/iu', $f, $m)) {
                $cliente_nombre = trim($m[1]);
                break;
            }
            // buscar un nombre entre 'Asesor: ' y ' | Cliente: '
            if (preg_match('/Cliente\s*-?\s*([^\|\n]+)/iu', $f, $m2)) {
                $cliente_nombre = trim($m2[1]);
                break;
            }
        }

        // Si no encontramos cliente por texto, intentar por tarea_id -> tarea.cliente_prospecto_id
        if (empty($cliente_nombre) && !empty($r['tarea_id'])) {
            try {
                $stt = $pdo->prepare('SELECT cliente_prospecto_id FROM tarea WHERE id = ? LIMIT 1');
                $stt->execute([$r['tarea_id']]);
                $cid = $stt->fetchColumn();
                if ($cid) {
                    $stc = $pdo->prepare('SELECT nombre FROM cliente_prospecto WHERE id = ? LIMIT 1');
                    $stc->execute([$cid]);
                    $cliente_nombre = (string)$stc->fetchColumn();
                }
            } catch (Throwable $_) { /* ignore */ }
        }

        // Construir mensaje: "<cliente> — Modificado por <asesor>" o fallback
        $parts = [];
        if ($cliente_nombre !== '') $parts[] = $cliente_nombre;
        if ($asesor_nombre !== '') $parts[] = 'Modificado por ' . $asesor_nombre;
        if (empty($parts)) {
            // último recurso: usar campo valor_nuevo/valor_anterior truncado
            $fallback = '';
            if (!empty($r['valor_nuevo'])) $fallback = strip_tags($r['valor_nuevo']);
            elseif (!empty($r['valor_anterior'])) $fallback = strip_tags($r['valor_anterior']);
            else $fallback = ($r['campo_modificado'] ?? 'Cambio registrado');
            $message = mb_substr($fallback, 0, 180);
        } else {
            $message = mb_substr(implode(' — ', $parts), 0, 180);
        }

        // calcular initials del asesor
        $initials = '';
        if ($asesor_nombre !== '') {
            $parts = preg_split('/\s+/', trim($asesor_nombre));
            $letters = array_map(function($p){ return mb_substr($p,0,1); }, array_slice($parts,0,2));
            $initials = strtoupper(implode('', $letters));
        }

        $result['alerts'][] = [
            'id' => $r['id'],
            'title' => $title,
            'message' => $message,
            'author' => $asesor_nombre,
            'initials' => $initials,
            'created_at' => $r['created_at'],
            'vista' => (int)$r['vista_supervisor'],
            'url' => 'alertas_detalle.php?id=' . urlencode($r['id'])
        ];
    }

    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

exit;

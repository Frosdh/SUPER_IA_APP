<?php
// obtener_encuesta_para_editar.php
// Carga todos los datos de una encuesta existente para editarla
// Parámetro GET: tarea_id

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// db_admin.php arranca la sesión y crea $pdo
require_once 'db_admin.php';

if (empty($_SESSION['asesor_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'No autorizado']);
    exit;
}

$tarea_id = trim($_GET['tarea_id'] ?? $_POST['tarea_id'] ?? '');
if (empty($tarea_id)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'tarea_id requerido']);
    exit;
}

try {
    // Obtener datos de la tarea
    $st = $pdo->prepare('SELECT * FROM tarea WHERE id = ? LIMIT 1');
    $st->execute([$tarea_id]);
    $tarea = $st->fetch(PDO::FETCH_ASSOC);
    
    if (!$tarea) {
        http_response_code(404);
        echo json_encode(['status'=>'error','message'=>'Tarea no encontrada']);
        exit;
    }
    
    $cliente_id = $tarea['cliente_prospecto_id'] ?? null;
    
    // Obtener datos del cliente/prospecto
    $cliente = null;
    if ($cliente_id) {
        $st = $pdo->prepare('SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1');
        $st->execute([$cliente_id]);
        $cliente = $st->fetch(PDO::FETCH_ASSOC);
    }
    
    // Obtener encuesta comercial
    $st = $pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = ? LIMIT 1');
    $st->execute([$tarea_id]);
    $encuesta = $st->fetch(PDO::FETCH_ASSOC);
    
    // Obtener fichas de productos (si existen)
    $fichas = [];
    try {
        // Detectar si existe la columna encuesta_id
        $fp_has_encuesta_id = false;
        try {
            $stc = $pdo->prepare("SHOW COLUMNS FROM `ficha_producto` LIKE 'encuesta_id'");
            $stc->execute();
            $fp_has_encuesta_id = (bool)$stc->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $fp_has_encuesta_id = false;
        }

        if ($fp_has_encuesta_id) {
            $st = $pdo->prepare('SELECT * FROM ficha_producto WHERE encuesta_id = (SELECT id FROM encuesta_comercial WHERE tarea_id = ?)');
            $st->execute([$tarea_id]);
            $fichas_raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            // Fallback (cuando no hay encuesta_id): aproximación por cliente + asesor
            $ced = $cliente['cedula'] ?? null;
            $aid = $tarea['asesor_id'] ?? null;
            if ($ced && $aid) {
                $st = $pdo->prepare('SELECT * FROM ficha_producto WHERE cliente_cedula = ? AND asesor_id = ? ORDER BY created_at DESC LIMIT 50');
                $st->execute([$ced, $aid]);
                $fichas_raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $fichas_raw = [];
            }
        }
        
        $fichas = [];
        foreach ($fichas_raw as $fp) {
            $type = $fp['producto_tipo'];
            $child_table = '';
            if ($type === 'credito')          $child_table = 'ficha_credito';
            elseif ($type === 'inversiones')  $child_table = 'ficha_inversiones';
            elseif ($type === 'cuenta_ahorros') $child_table = 'ficha_cuenta_ahorros';
            elseif ($type === 'cuenta_corriente') $child_table = 'ficha_cuenta_corriente';
            
            if ($child_table) {
                try {
                    $stc = $pdo->prepare("SELECT * FROM `$child_table` WHERE ficha_id = ? LIMIT 1");
                    $stc->execute([$fp['id']]);
                    $child_data = $stc->fetch(PDO::FETCH_ASSOC) ?: [];
                    $fp = array_merge($fp, $child_data);
                } catch (PDOException $ex) {}
            }
            $fichas[] = $fp;
        }
    } catch (PDOException $e) {
        // Si la tabla no existe o el esquema no coincide, omitir fichas
        $fichas = [];
    }
    
    echo json_encode([
        'status' => 'ok',
        'tarea' => $tarea,
        'cliente' => $cliente,
        'encuesta' => $encuesta,
        'fichas' => $fichas
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

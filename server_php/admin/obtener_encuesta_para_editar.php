<?php
// obtener_encuesta_para_editar.php
// Carga todos los datos de una encuesta existente para editarla
// Parámetro GET: tarea_id

// Iniciar buffer de salida al extremo principio para capturar y evitar fugas de advertencias/BOM
ob_start();

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

// Inicializar variables
$tarea = null;
$cliente = null;
$encuesta = null;
$fichas = [];
$debug_info = [];

/**
 * Elimina columnas binarias (POINT/geometry) y normaliza texto a UTF-8 válido.
 * Sin esto, json_encode() devuelve false al encontrar bytes binarios
 * (p.ej. la columna `georef_punto` de cliente_prospecto) y la respuesta
 * llega VACÍA al navegador -> "La respuesta del servidor no es válida".
 */
function limpiar_para_json($valor) {
    // Columnas binarias conocidas que nunca deben ir al JSON
    static $columnas_binarias = ['georef_punto', 'punto', 'georef', 'geom'];

    if (is_array($valor)) {
        $limpio = [];
        foreach ($valor as $k => $v) {
            if (is_string($k) && in_array($k, $columnas_binarias, true)) {
                continue; // descartar geometría binaria
            }
            $limpio[$k] = limpiar_para_json($v);
        }
        return $limpio;
    }
    if (is_string($valor)) {
        // Forzar UTF-8 válido; descarta bytes inválidos
        if (!mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
        }
        return $valor;
    }
    return $valor;
}

try {
    // Obtener datos de la tarea
    try {
        $st = $pdo->prepare('SELECT * FROM tarea WHERE id = ? LIMIT 1');
        $st->execute([$tarea_id]);
        $tarea = $st->fetch(PDO::FETCH_ASSOC);
        $debug_info[] = 'Tarea: ' . ($tarea ? 'OK' : 'NOT FOUND');
    } catch (Exception $e) {
        $debug_info[] = 'Tarea ERROR: ' . $e->getMessage();
        error_log('obtener_encuesta: Error obteniendo tarea - '.$e->getMessage());
    }
    
    if (!$tarea) {
        http_response_code(404);
        echo json_encode(['status'=>'error','message'=>'Tarea no encontrada']);
        exit;
    }
    
    $cliente_id = $tarea['cliente_prospecto_id'] ?? null;
    
    // Obtener datos del cliente/prospecto
    if ($cliente_id) {
        try {
            $st = $pdo->prepare('SELECT * FROM cliente_prospecto WHERE id = ? LIMIT 1');
            $st->execute([$cliente_id]);
            $cliente = $st->fetch(PDO::FETCH_ASSOC);
            $debug_info[] = 'Cliente: ' . ($cliente ? 'OK' : 'NOT FOUND');
        } catch (Exception $e) {
            $debug_info[] = 'Cliente ERROR: ' . $e->getMessage();
            error_log('obtener_encuesta: Error obteniendo cliente - '.$e->getMessage());
            $cliente = null;
        }
    } else {
        $debug_info[] = 'Cliente: SKIP (no cliente_id)';
    }
    
    // Obtener encuesta comercial - AQUÍ VAMOS A DEVOLVER TODOS LOS CAMPOS
    try {
        $st = $pdo->prepare('SELECT * FROM encuesta_comercial WHERE tarea_id = ? LIMIT 1');
        $st->execute([$tarea_id]);
        $encuesta = $st->fetch(PDO::FETCH_ASSOC);
        
        if ($encuesta) {
            $debug_info[] = 'Encuesta: OK (campos: ' . count($encuesta) . ')';
        } else {
            $debug_info[] = 'Encuesta: NOT FOUND (se devolverá vacía)';
            $encuesta = [];
        }
    } catch (Exception $e) {
        $debug_info[] = 'Encuesta ERROR: ' . $e->getMessage();
        error_log('obtener_encuesta: Error obteniendo encuesta_comercial - '.$e->getMessage());
        $encuesta = [];
    }
    
    // Obtener fichas de productos (si existen)
    try {
        // Verificar si existe la tabla ficha_producto
        $ficha_tabla_existe = false;
        try {
            $stc = $pdo->prepare("SELECT 1 FROM `ficha_producto` LIMIT 1");
            $stc->execute();
            $ficha_tabla_existe = true;
        } catch (Exception $e) {
            $ficha_tabla_existe = false;
        }
        
        if ($ficha_tabla_existe) {
            // Detectar si existe la columna encuesta_id
            $fp_has_encuesta_id = false;
            try {
                $stc = $pdo->prepare("SHOW COLUMNS FROM `ficha_producto` LIKE 'encuesta_id'");
                $stc->execute();
                $fp_has_encuesta_id = (bool)$stc->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $fp_has_encuesta_id = false;
            }

            $fichas_raw = [];
            
            // Intenta por encuesta_id si existe la columna
            if ($fp_has_encuesta_id && !empty($encuesta) && isset($encuesta['id'])) {
                try {
                    $enc_id = $encuesta['id'];
                    $st = $pdo->prepare('SELECT * FROM ficha_producto WHERE encuesta_id = ?');
                    $st->execute([$enc_id]);
                    $fichas_raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $debug_info[] = 'Fichas por encuesta_id: ' . count($fichas_raw);
                } catch (Exception $e) {
                    $debug_info[] = 'Fichas encuesta_id ERROR: ' . $e->getMessage();
                    error_log('obtener_encuesta: Error obteniendo fichas por encuesta_id - '.$e->getMessage());
                }
            }
            
            // Fallback: busca por tarea_id
            if (empty($fichas_raw)) {
                try {
                    $st = $pdo->prepare('SELECT * FROM ficha_producto WHERE tarea_id = ? ORDER BY created_at DESC LIMIT 50');
                    $st->execute([$tarea_id]);
                    $fichas_raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $debug_info[] = 'Fichas por tarea_id: ' . count($fichas_raw);
                } catch (Exception $e) {
                    $debug_info[] = 'Fichas tarea_id ERROR: ' . $e->getMessage();
                    error_log('obtener_encuesta: Error obteniendo fichas por tarea_id - '.$e->getMessage());
                }
            }
            
            // Fallback: busca por cliente + asesor si aún no encontró
            if (empty($fichas_raw) && $cliente) {
                try {
                    $ced = $cliente['cedula'] ?? null;
                    $aid = $tarea['asesor_id'] ?? null;
                    if ($ced && $aid) {
                        $st = $pdo->prepare('SELECT * FROM ficha_producto WHERE cliente_cedula = ? AND asesor_id = ? ORDER BY created_at DESC LIMIT 50');
                        $st->execute([$ced, $aid]);
                        $fichas_raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $debug_info[] = 'Fichas por cliente+asesor: ' . count($fichas_raw);
                    }
                } catch (Exception $e) {
                    $debug_info[] = 'Fichas cliente+asesor ERROR: ' . $e->getMessage();
                    error_log('obtener_encuesta: Error obteniendo fichas por cliente+asesor - '.$e->getMessage());
                }
            }
            
            // Procesar fichas con sus datos específicos
            $fichas = [];
            foreach ($fichas_raw as $fp) {
                try {
                    $type = $fp['producto_tipo'] ?? '';
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
                        } catch (Exception $ex) {
                            error_log("obtener_encuesta: Error obteniendo datos de $child_table - ".$ex->getMessage());
                        }
                    }
                    $fichas[] = $fp;
                } catch (Exception $ex) {
                    error_log('obtener_encuesta: Error procesando ficha - '.$ex->getMessage());
                }
            }
        } else {
            $debug_info[] = 'Fichas: tabla NO existe';
        }
    } catch (Exception $e) {
        $debug_info[] = 'Fichas bloque ERROR: ' . $e->getMessage();
        error_log('obtener_encuesta: Error en bloque de fichas - '.$e->getMessage());
        $fichas = [];
    }
    
    // Respuesta exitosa con los datos que se pudieron recuperar.
    // limpiar_para_json() elimina columnas binarias (georef_punto) y
    // normaliza el texto para que json_encode() nunca devuelva false.
    $response = [
        'status'  => 'ok',
        'tarea'   => $tarea    ? limpiar_para_json($tarea)   : (object)[],
        'cliente' => $cliente  ? limpiar_para_json($cliente) : (object)[],
        'encuesta'=> $encuesta ? limpiar_para_json($encuesta): (object)[],
        'fichas'  => limpiar_para_json($fichas),
        'debug'   => $debug_info
    ];

    // Limpiar TODO el buffer y enviar respuesta JSON 100% limpia
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    @header('Content-Type: application/json; charset=utf-8');
    @header('Access-Control-Allow-Origin: *');

    $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    // Si aun así fallara la codificación, devolver un error JSON explícito
    // en lugar de un cuerpo vacío (que produce "respuesta no es válida").
    if ($json === false) {
        error_log('obtener_encuesta: json_encode falló - ' . json_last_error_msg());
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'No se pudo codificar la encuesta: ' . json_last_error_msg()
        ]);
        exit;
    }

    echo $json;
    exit;
    
} catch (Throwable $e) {
    error_log('obtener_encuesta: Error fatal - '.$e->getMessage() . "\n" . $e->getTraceAsString());
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    @header('Content-Type: application/json; charset=utf-8');
    @header('Access-Control-Allow-Origin: *');
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Error al cargar encuesta: '.$e->getMessage()]);
    exit;
}
?>

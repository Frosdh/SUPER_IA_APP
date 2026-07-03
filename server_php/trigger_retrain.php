<?php
// trigger_retrain.php
// Exporta datos de entrenamiento de sesiones en cola y retorna JSON.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '-05:00'");
} catch (PDOException $e) {
    echo json_encode(['status'=>'error','message'=>'DB connection error']); exit;
}

// Obtener entradas no procesadas de la cola
$rows = [];
try {
    $q = $pdo->prepare("SELECT id, session_id FROM training_queue WHERE processed = 0 ORDER BY enqueued_at LIMIT 500");
    $q->execute();
    $rows = $q->fetchAll();
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'training_queue missing or query failed']); exit;
}

if (count($rows) === 0) {
    echo json_encode(['status'=>'empty','message'=>'No sessions in queue']); exit;
}

$sessionIds = array_column($rows, 'session_id');
$placeholders = implode(',', array_fill(0, count($sessionIds), '?'));

$stmt = $pdo->prepare("SELECT id, TIMESTAMPDIFF(SECOND, started_at, finished_at) AS duration, keypress_count, selection_count, questions_total, productivity_score FROM survey_sessions WHERE id IN ($placeholders) AND productivity_score IS NOT NULL");
$stmt->execute($sessionIds);
$data = $stmt->fetchAll();

if (count($data) === 0) {
    // marcar como procesadas para evitar bucle
    $ids = array_column($rows, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE training_queue SET processed = 1, processed_at = NOW() WHERE id IN ($ph)")->execute($ids);
    echo json_encode(['status'=>'empty','message'=>'No completed sessions with productivity_score yet']); exit;
}

// Preparar archivo temporal para entrenamiento
$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
$ts = time();
$tmpFile = $tmpDir . "/training_data_{$ts}.json";
file_put_contents($tmpFile, json_encode($data));

// Marcar las entradas de la cola como procesadas
$queueIds = array_column($rows, 'id');
$ph = implode(',', array_fill(0, count($queueIds), '?'));
$pdo->prepare("UPDATE training_queue SET processed = 1, processed_at = NOW() WHERE id IN ($ph)")->execute($queueIds);

// Preparar respuesta base
$response = ['status'=>'success','file'=>$tmpFile, 'rows'=>count($data)];

// Intentar lanzar el entrenador Node.js (opcional)
try {
    // Crear tabla `model_versions` si no existe (metadatos del modelo)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS model_versions (
            id CHAR(36) NOT NULL PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            model_path VARCHAR(255) NOT NULL,
            sample_count INT DEFAULT 0,
            metrics JSON DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // no crítico: continuar si falla
}

$nodeOk = false;
$nodeOutput = '';
// Comprobar si 'node' está disponible
try {
    $ver = null;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $ver = shell_exec('node -v 2>&1');
    } else {
        $ver = shell_exec('which node 2>&1');
    }
    if ($ver && trim($ver) !== '') {
        $nodeOk = true;
    }
} catch (Exception $e) {
    $nodeOk = false;
}

if ($nodeOk) {
    $trainScript = realpath(__DIR__ . '/../server_tools/train_and_save.js');
    if ($trainScript && file_exists($trainScript)) {
        $cmd = escapeshellcmd('node') . ' ' . escapeshellarg($trainScript) . ' ' . escapeshellarg($tmpFile) . ' 2>&1';
        $nodeOutput = shell_exec($cmd);
        $response['node_run'] = ['node_ok'=>true, 'output'=>$nodeOutput];

        // Registrar versión del modelo (intento)
        try {
            $modelPath = __DIR__ . '/../server_tools/models/survey-productivity';
            $sampleCount = count($data);
            $metrics = json_encode(['node_output' => $nodeOutput]);
            $ins = $pdo->prepare("INSERT INTO model_versions (id, model_path, sample_count, metrics) VALUES (UUID(), ?, ?, ?)");
            $ins->execute([$modelPath, $sampleCount, $metrics]);
            $response['model_version_recorded'] = true;
            $response['model_path'] = $modelPath;
            $response['sample_count'] = $sampleCount;
        } catch (Exception $e) {
            $response['model_version_recorded'] = false;
            $response['model_version_error'] = $e->getMessage();
        }
    } else {
        $response['node_run'] = ['node_ok'=>true, 'output'=>'train script not found'];
    }
} else {
    $response['node_run'] = ['node_ok'=>false, 'output'=>'node not available or not in PATH'];
}

echo json_encode($response);



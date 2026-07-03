<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("SET time_zone = '-05:00'");
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection error']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$event = $payload['event_type'] ?? '';
$survey_id = $payload['survey_id'] ?? null;
$asesor_id = $payload['asesor_id'] ?? null;
$session_id = isset($payload['session_id']) ? intval($payload['session_id']) : null;
$payload_text = isset($payload['payload']) ? json_encode($payload['payload']) : null;

if (!$event) { echo json_encode(['status'=>'error','message'=>'event_type required']); exit; }

try {
    if ($event === 'survey_start') {
        $stmt = $pdo->prepare("INSERT INTO survey_sessions (survey_id, asesor_id, started_at, created_at) VALUES (?, ?, NOW(), NOW())");
        $stmt->execute([$survey_id, $asesor_id]);
        $newId = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO survey_events (session_id, survey_id, asesor_id, event_type, payload) VALUES (?, ?, ?, ?, ?)")
            ->execute([$newId, $survey_id, $asesor_id, $event, $payload_text]);
        echo json_encode(['status'=>'success','session_id'=>$newId]);
        exit;
    }

    if ($event === 'survey_end') {
        if (!$session_id) { echo json_encode(['status'=>'error','message'=>'session_id required']); exit; }
        $pdo->prepare("UPDATE survey_sessions SET finished_at = NOW() WHERE id = ?")->execute([$session_id]);
        // compute basic productivity: duration and keypress/selection normalized (can be improved)
        $row = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, started_at, finished_at) AS duration, keypress_count, selection_count, questions_total FROM survey_sessions WHERE id = ?");
        $row->execute([$session_id]);
        $data = $row->fetch();
        $duration = $data['duration'] ?? 0;
        $keypress = $data['keypress_count'] ?? 0;
        $selection = $data['selection_count'] ?? 0;
        $questions = max(1, intval($data['questions_total'] ?? 1));
        // simple heuristic productivity score
        $speed = $duration > 0 ? ($questions / $duration) : 0;
        $interaction = ($keypress + $selection) / $questions;
        $productivity = min(100, ($speed * 50) + ($interaction * 10));
        $pdo->prepare("UPDATE survey_sessions SET productivity_score = ? WHERE id = ?")->execute([$productivity, $session_id]);
        // Encolar para reentrenamiento (si la tabla existe)
        try {
            $pdo->prepare("INSERT INTO training_queue (session_id) VALUES (?)")->execute([$session_id]);
        } catch (Exception $e) {
            // tabla training_queue no existe o error; ignorar para compatibilidad
        }
        $pdo->prepare("INSERT INTO survey_events (session_id, survey_id, asesor_id, event_type, payload) VALUES (?, ?, ?, ?, ?)")
            ->execute([$session_id, $survey_id, $asesor_id, $event, $payload_text]);
        echo json_encode(['status'=>'success','session_id'=>$session_id,'productivity'=>$productivity]);
        exit;
    }

    // keypress or selection or other event types - store and optionally update counters
    $pdo->prepare("INSERT INTO survey_events (session_id, survey_id, asesor_id, event_type, payload) VALUES (?, ?, ?, ?, ?)")
        ->execute([$session_id, $survey_id, $asesor_id, $event, $payload_text]);

    if ($event === 'keypress') {
        $pdo->prepare("UPDATE survey_sessions SET keypress_count = keypress_count + 1 WHERE id = ?")->execute([$session_id]);
    }
    if ($event === 'selection') {
        $pdo->prepare("UPDATE survey_sessions SET selection_count = selection_count + 1 WHERE id = ?")->execute([$session_id]);
    }
    if ($event === 'questions_total') {
        $q = intval($payload['payload']['questions'] ?? 0);
        if ($q > 0) $pdo->prepare("UPDATE survey_sessions SET questions_total = ? WHERE id = ?")->execute([$q, $session_id]);
    }

    echo json_encode(['status'=>'success']);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}

?>

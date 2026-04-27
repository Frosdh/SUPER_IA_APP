<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_config.php';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection error']);
    exit;
}

$asesor = $_GET['asesor_id'] ?? null;
$survey = $_GET['survey_id'] ?? null;

$where = [];
$params = [];
if ($asesor) { $where[] = 'asesor_id = ?'; $params[] = $asesor; }
if ($survey) { $where[] = 'survey_id = ?'; $params[] = $survey; }

$sql = 'SELECT COUNT(*) AS sessions, AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS avg_seconds, AVG(productivity_score) AS avg_productivity, AVG(keypress_count) AS avg_keypress, AVG(selection_count) AS avg_selection FROM survey_sessions';
if (count($where)) $sql .= ' WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$res = $stmt->fetch();

echo json_encode(['status'=>'success','data'=>$res]);

?>

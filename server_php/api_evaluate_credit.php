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
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection error']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$empresa = trim($payload['empresa'] ?? '');
$ingresos = floatval($payload['ingresos'] ?? 0);
$gastos = floatval($payload['gastos'] ?? 0);
$capital = floatval($payload['capital'] ?? 0);
$monto = floatval($payload['monto_solicitado'] ?? 0);

// Normalizaciones seguras
$profitMargin = ($ingresos > 0) ? max(-1, min(1, ($ingresos - $gastos) / $ingresos)) : 0;
$debtRatio = ($capital + $monto > 0) ? ($monto / ($capital + $monto)) : 1; // 0..1 (lower better)
$capitalCoverage = ($monto > 0) ? min(1, $capital / ($monto)) : 1;

// Weights
$w_profit = 0.45; $w_debt = 0.35; $w_capital = 0.20;

// Map profitMargin (-1..1) to 0..1
$profitNorm = ($profitMargin + 1) / 2;
$debtNorm = 1 - $debtRatio; // higher is better
$capNorm = $capitalCoverage; // 0..1

$score = ($profitNorm * $w_profit + $debtNorm * $w_debt + $capNorm * $w_capital) * 100.0;
$viable = ($score >= 60) ? 1 : 0;

$detalles = "profitMargin={$profitMargin}, debtRatio={$debtRatio}, capitalCoverage={$capitalCoverage}";

// Persistir solicitud
// Si se proporciona cliente_prospecto_id o credito_proceso_id, actualizar la tabla existente
$clienteId = $payload['cliente_prospecto_id'] ?? null;
$creditoProcesoId = $payload['credito_proceso_id'] ?? null;
if ($creditoProcesoId || $clienteId) {
    try {
        if ($creditoProcesoId) {
            $upd = $pdo->prepare("UPDATE credito_proceso SET score = ?, viable = ?, monto_aprobado = COALESCE(monto_aprobado, NULL), evaluation_details = ? WHERE id = ?");
            $upd->execute([$score, $viable, $detalles, $creditoProcesoId]);
        } else {
            // intentar actualizar la última solicitud para este cliente
            $upd = $pdo->prepare("UPDATE credito_proceso SET score = ?, viable = ?, evaluation_details = ? WHERE cliente_prospecto_id = ? ORDER BY created_at DESC LIMIT 1");
            $upd->execute([$score, $viable, $detalles, $clienteId]);
            if ($upd->rowCount() === 0) {
                // si no existe, insertar un registro mínimo
                $ins = $pdo->prepare("INSERT INTO credito_proceso (cliente_prospecto_id, asesor_id, monto_aprobado, monto_aprobado, score, viable, evaluation_details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $ins->execute([$clienteId, $payload['asesor_id'] ?? null, $monto, $monto, $score, $viable, $detalles]);
            }
        }
        echo json_encode(['status'=>'success','score'=>round($score,2),'viable'=>intval($viable)]);
        exit;
    } catch (Exception $e) {
        // fallback: guardar en credit_applications
    }
}

// Si no se enlaza a tablas existentes, crear registro independiente
$stmt = $pdo->prepare("INSERT INTO credit_applications (empresa, ingresos, gastos, capital, monto_solicitado, score, viable, detalles) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$empresa, $ingresos, $gastos, $capital, $monto, $score, $viable, $detalles]);

echo json_encode([
    'status' => 'success',
    'score' => round($score,2),
    'viable' => intval($viable),
    'details' => $detalles,
    'id' => $pdo->lastInsertId()
]);

?>

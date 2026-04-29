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
// Flag: el supervisor está intentando aprobar formalmente el crédito
$intentaAprobar = !empty($payload['aprobar']) || !empty($payload['set_aprobado']);

if ($creditoProcesoId || $clienteId) {
    try {
        // ── Guardia de aprobación: si el cliente tiene empresa, el levantamiento
        //    de empresa (encuesta_negocio) debe estar completo antes de aprobar ──
        if ($intentaAprobar && $clienteId) {
            $stCliente = $pdo->prepare(
                "SELECT nombre_empresa FROM cliente_prospecto WHERE id = ? LIMIT 1"
            );
            $stCliente->execute([$clienteId]);
            $rowCliente = $stCliente->fetch();
            $tieneEmpresa = $rowCliente && !empty(trim($rowCliente['nombre_empresa'] ?? ''));

            if ($tieneEmpresa) {
                // Verificar que exista al menos un registro de encuesta_negocio
                // con datos financieros básicos ingresados (levantar empresa)
                $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'encuesta_negocio'")->fetchColumn();
                $encNegocioCompleta = false;
                if ($tableExists) {
                    $stEnc = $pdo->prepare(
                        "SELECT en.id FROM encuesta_negocio en
                         INNER JOIN tarea t ON t.id = en.tarea_id
                         WHERE t.cliente_prospecto_id = ?
                           AND (en.venta_lv IS NOT NULL
                                OR en.costos_ventas IS NOT NULL
                                OR en.gastos_negocio IS NOT NULL)
                         LIMIT 1"
                    );
                    $stEnc->execute([$clienteId]);
                    $encNegocioCompleta = (bool)$stEnc->fetchColumn();
                }
                if (!$encNegocioCompleta) {
                    echo json_encode([
                        'status'  => 'error',
                        'message' => 'Para aprobar el crédito, primero debe completar el levantamiento de empresa en la sección "Levantar Empresa".',
                        'requiere_levantar_empresa' => true,
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }

        if ($creditoProcesoId) {
            $upd = $pdo->prepare("UPDATE credito_proceso SET score = ?, viable = ?, evaluation_details = ? WHERE id = ?");
            $upd->execute([$score, $viable, $detalles, $creditoProcesoId]);
            // Si el supervisor aprueba y el score es viable, marcar como aprobado
            if ($intentaAprobar && $viable) {
                $upd2 = $pdo->prepare("UPDATE credito_proceso SET estado_credito = 'aprobado', monto_aprobado = COALESCE(monto_aprobado, ?) WHERE id = ?");
                $upd2->execute([$monto, $creditoProcesoId]);
            }
        } else {
            // intentar actualizar la última solicitud para este cliente
            $upd = $pdo->prepare("UPDATE credito_proceso SET score = ?, viable = ?, evaluation_details = ? WHERE cliente_prospecto_id = ? ORDER BY created_at DESC LIMIT 1");
            $upd->execute([$score, $viable, $detalles, $clienteId]);
            if ($upd->rowCount() === 0) {
                // si no existe, insertar un registro mínimo (sin duplicar monto_aprobado)
                $ins = $pdo->prepare("INSERT INTO credito_proceso (cliente_prospecto_id, asesor_id, monto_aprobado, score, viable, evaluation_details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $ins->execute([$clienteId, $payload['asesor_id'] ?? null, $monto, $score, $viable, $detalles]);
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

<?php
// ============================================================
// admin/api_coop_mapa.php
// Versión filtrada para secretarias de cooperativa
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['secretary_logged_in']) || $_SESSION['secretary_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$coopId = $_SESSION['cooperativa_id'];

$stmt = $pdo->prepare("
    SELECT
        c.id, c.nombre, c.telefono, c.estado, c.latitud, c.longitud,
        c.calificacion_promedio, c.verificado, v.marca, v.modelo, v.color, v.placa,
        cat.nombre AS categoria
    FROM conductores c
    LEFT JOIN vehiculos v   ON v.conductor_id = c.id
    LEFT JOIN categorias cat ON cat.id = v.categoria_id
    WHERE c.latitud IS NOT NULL
      AND c.longitud IS NOT NULL
      AND c.estado IN ('libre', 'ocupado')
      AND c.cooperativa_id = ?
    ORDER BY c.estado ASC, c.nombre ASC
");
$stmt->execute([$coopId]);
$conductores = $stmt->fetchAll();

// Contar por estado
$totales = ['libre' => 0, 'ocupado' => 0];
foreach ($conductores as $c) {
    $totales[$c['estado']] = ($totales[$c['estado']] ?? 0) + 1;
}

echo json_encode([
    'status'      => 'ok',
    'timestamp'   => date('H:i:s'),
    'totales'     => $totales,
    'conductores' => $conductores,
], JSON_UNESCAPED_UNICODE);

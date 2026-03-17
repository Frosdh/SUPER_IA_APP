<?php
// ============================================================
// admin/api_conductores_mapa.php
// Devuelve JSON con todos los conductores que tienen coordenadas
// registradas (libre u ocupado). Usado por mapa.php para
// actualización en tiempo real vía AJAX.
// ============================================================
require_once 'db_admin.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$stmt = $pdo->query("
    SELECT
        c.id,
        c.nombre,
        c.telefono,
        c.estado,
        c.latitud,
        c.longitud,
        c.calificacion_promedio,
        c.verificado,
        v.marca,
        v.modelo,
        v.color,
        v.placa,
        cat.nombre AS categoria
    FROM conductores c
    LEFT JOIN vehiculos v   ON v.conductor_id = c.id
    LEFT JOIN categorias cat ON cat.id = v.categoria_id
    WHERE c.latitud IS NOT NULL
      AND c.longitud IS NOT NULL
      AND c.estado IN ('libre', 'ocupado')
    ORDER BY c.estado ASC, c.nombre ASC
");

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

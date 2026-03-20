<?php
// ============================================================
// admin/api_ruta_historica.php
// Devuelve JSON con el historial de puntos de un conductor
// ============================================================
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$conductorId = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
$fecha       = isset($_GET['fecha'])        ? $_GET['fecha']        : date('Y-m-d');

if ($conductorId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID de conductor no válido']);
    exit;
}

try {
    // Obtener puntos del día seleccionado
    $stmt = $pdo->prepare("
        SELECT latitud, longitud, fecha_registro
        FROM conductores_rutas
        WHERE conductor_id = ?
          AND DATE(fecha_registro) = ?
        ORDER BY fecha_registro ASC
    ");
    $stmt->execute([$conductorId, $fecha]);
    $puntos = $stmt->fetchAll();

    echo json_encode([
        'status'  => 'ok',
        'puntos'  => $puntos,
        'count'   => count($puntos)
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

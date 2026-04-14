<?php
// ============================================================
// admin/api_ruta_historica.php
// Devuelve JSON con el historial de puntos de un conductor
// ============================================================
require_once 'db_admin.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['secretary_logged_in']) && !isset($_SESSION['super_admin_logged_in'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isSecretary = isset($_SESSION['secretary_logged_in']) && $_SESSION['secretary_logged_in'] === true;
$isSuperAdmin = isset($_SESSION['super_admin_logged_in']) && $_SESSION['super_admin_logged_in'] === true;
$coopId = $_SESSION['cooperativa_id'] ?? 0;

$conductorId = isset($_GET['conductor_id']) ? intval($_GET['conductor_id']) : 0;
$fecha       = isset($_GET['fecha'])        ? $_GET['fecha']        : date('Y-m-d');

if ($conductorId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID de conductor no válido']);
    exit;
}

// Seguridad: Si es secretaria, validar que el conductor sea de su cooperativa
// SuperAdmin y Admin ven todos los viajes
if ($isSecretary) {
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM viajes WHERE id_conductor = ? AND cooperativa_id = ?");
    $stmtCheck->execute([$conductorId, $coopId]);
    if ($stmtCheck->fetchColumn() == 0) {
        echo json_encode(['status' => 'error', 'message' => 'Este conductor no pertenece a su cooperativa']);
        exit;
    }
}

try {
    // Obtener puntos del día seleccionado de la tabla viajes
    $stmt = $pdo->prepare("
        SELECT 
            latitud_inicio as latitud, 
            longitud_inicio as longitud, 
            fecha_hora as fecha_registro
        FROM viajes
        WHERE id_conductor = ?
          AND DATE(fecha_hora) = ?
        ORDER BY fecha_hora ASC
    ");
    $stmt->execute([$conductorId, $fecha]);
    $puntos = $stmt->fetchAll();

    // Si no hay puntos de inicio, intentar con fin
    if (empty($puntos)) {
        $stmt = $pdo->prepare("
            SELECT 
                latitud_fin as latitud, 
                longitud_fin as longitud, 
                fecha_hora as fecha_registro
            FROM viajes
            WHERE id_conductor = ?
              AND DATE(fecha_hora) = ?
            ORDER BY fecha_hora ASC
        ");
        $stmt->execute([$conductorId, $fecha]);
        $puntos = $stmt->fetchAll();
    }

    echo json_encode([
        'status'  => 'ok',
        'puntos'  => $puntos,
        'count'   => count($puntos)
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

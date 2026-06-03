<?php
// get_supervisores_por_cooperativa.php
// Devuelve supervisores activos de una unidad_bancaria (cooperativa).
// Intenta dos rutas de unión y devuelve todos los que coincidan por cualquiera.
require_once 'db_admin.php';
header('Content-Type: application/json');

$id_cooperativa = trim($_GET['id_cooperativa'] ?? '');

if (!$id_cooperativa) {
    echo json_encode(['supervisores' => []]);
    exit;
}

$supervisores = [];

try {
    // Ruta 1: supervisor → jefe_agencia → agencia → unidad_bancaria_id
    // Ruta 2: usuario.agencia_id → agencia → unidad_bancaria_id
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.id AS id_usuario, u.nombre
        FROM usuario u
        JOIN supervisor sup ON sup.usuario_id = u.id
        WHERE u.activo = 1
          AND u.estado_aprobacion = 'aprobado'
          AND u.rol = 'supervisor'
          AND (
              -- Ruta directa: usuario tiene agencia asignada
              EXISTS (
                  SELECT 1 FROM agencia ag
                  WHERE ag.id = u.agencia_id
                    AND ag.unidad_bancaria_id = ?
              )
              OR
              -- Ruta jerárquica: supervisor → jefe_agencia → agencia
              EXISTS (
                  SELECT 1
                  FROM jefe_agencia ja
                  JOIN agencia ag2 ON ag2.id = ja.agencia_id
                  WHERE ja.id = sup.jefe_agencia_id
                    AND ag2.unidad_bancaria_id = ?
              )
          )
        ORDER BY u.nombre
    ");
    $stmt->execute([$id_cooperativa, $id_cooperativa]);
    $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (\Throwable $e) {
    // Si falla todo, devolver error legible
    echo json_encode(['supervisores' => [], 'debug' => $e->getMessage()]);
    exit;
}

// Si no hay supervisores por ninguna ruta, devolver lista vacía con mensaje
if (empty($supervisores)) {
    echo json_encode([
        'supervisores' => [],
        'mensaje'      => 'No hay supervisores registrados para esta cooperativa.'
    ]);
    exit;
}

echo json_encode(['supervisores' => $supervisores]);
exit;

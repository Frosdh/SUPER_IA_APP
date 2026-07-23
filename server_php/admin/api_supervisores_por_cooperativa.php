<?php
// ============================================================
// api_supervisores_por_cooperativa.php
// Carga supervisores dinámicamente según cooperativa seleccionada
// ============================================================

header('Content-Type: application/json');
require_once '../db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$cooperativa_id = $_POST['cooperativa_id'] ?? '';

if (empty($cooperativa_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Cooperativa ID requerido']);
    exit;
}

try {
    // Filtra por la cooperativa/banco realmente elegida, siguiendo la
    // cadena supervisor -> jefe_agencia -> agencia -> unidad_bancaria.
    // Antes esta consulta devolvía TODOS los supervisores activos del
    // sistema sin importar el banco seleccionado (bug: el filtro por
    // banco no existía de verdad, solo el <select> lo aparentaba).
    $stmt = $conn->prepare("
        SELECT u.id, u.nombre, u.email, u.rol
        FROM usuario u
        JOIN supervisor sv ON sv.usuario_id = u.id
        JOIN jefe_agencia ja ON ja.id = sv.jefe_agencia_id
        JOIN agencia ag ON ag.id = ja.agencia_id
        WHERE ag.unidad_bancaria_id = ?
          AND u.activo = 1 AND u.estado_aprobacion = 'aprobado'
        ORDER BY u.nombre ASC
    ");
    $stmt->bind_param('s', $cooperativa_id);
    $stmt->execute();
    $supervisores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'status' => 'success',
        'supervisores' => $supervisores
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>

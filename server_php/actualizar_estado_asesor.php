<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metodo no permitido']);
    exit;
}

$estado = isset($_POST['estado']) ? strtolower(trim((string)$_POST['estado'])) : '';
$asesor_id = isset($_POST['asesor_id']) ? trim((string)$_POST['asesor_id']) : '';
$usuario_id = isset($_POST['usuario_id']) ? trim((string)$_POST['usuario_id']) : '';

if ($estado === '' || !in_array($estado, ['conectado', 'desconectado'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'estado invalido']);
    exit;
}

// Resolver asesor_id a partir de usuario_id si no llegó en el payload
if ($asesor_id === '' && $usuario_id !== '') {
    $mapStmt = $conn->prepare('SELECT id FROM asesor WHERE usuario_id = ? LIMIT 1');
    if ($mapStmt) {
        $mapStmt->bind_param('s', $usuario_id);
        if ($mapStmt->execute()) {
            $res = $mapStmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                $asesor_id = (string)$row['id'];
            }
        }
        $mapStmt->close();
    }
}

if ($asesor_id === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'asesor_id es requerido']);
    exit;
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS asesor_presencia (
            asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
            estado ENUM('conectado','desconectado') NOT NULL DEFAULT 'desconectado',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $conn->query(
        "ALTER TABLE asesor_presencia
         MODIFY asesor_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL"
    );

    $presStmt = $conn->prepare(
        "INSERT INTO asesor_presencia (asesor_id, estado, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE estado = VALUES(estado), updated_at = NOW()"
    );
    if (!$presStmt) {
        throw new Exception('Error en preparacion presencia: ' . $conn->error);
    }
    $presStmt->bind_param('ss', $asesor_id, $estado);
    $presStmt->execute();
    $presStmt->close();

    if ($estado === 'desconectado') {
        // Eliminar posiciones recientes para que desaparezca del mapa de inmediato.
        $stmt = $conn->prepare(
            'DELETE FROM ubicacion_asesor WHERE asesor_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 2 HOUR)'
        );
        if (!$stmt) {
            throw new Exception('Error en preparacion: ' . $conn->error);
        }
        $stmt->bind_param('s', $asesor_id);
        $stmt->execute();
        $rows = $stmt->affected_rows;
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'message' => 'Asesor marcado como desconectado',
            'asesor_id' => $asesor_id,
            'rows_affected' => $rows,
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Asesor conectado',
        'asesor_id' => $asesor_id,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error del servidor: ' . $e->getMessage(),
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

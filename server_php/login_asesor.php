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

$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email y contraseña son requeridos']);
    exit;
}

try {
    $sql = "SELECT 
                u.id,
                u.nombre,
                u.email,
                u.telefono,
                u.password_hash,
                u.rol,
                u.activo,
                u.estado_aprobacion,
                a.id AS asesor_id
            FROM usuario u
            LEFT JOIN asesor a ON a.usuario_id = u.id
            WHERE u.email = ? AND u.rol = 'asesor'
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error en preparación: ' . $conn->error);
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Email o contraseña incorrectos']);
        exit;
    }

    $user = $result->fetch_assoc();

    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Email o contraseña incorrectos']);
        exit;
    }

    // Estado de aprobación / activo
    if ((int)$user['activo'] !== 1 || ($user['estado_aprobacion'] ?? '') !== 'aprobado') {
        $estado = (string)($user['estado_aprobacion'] ?? 'pendiente');
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => $estado === 'rechazada'
                ? 'Tu cuenta fue rechazada. Contacta al supervisor.'
                : 'Tu cuenta está pendiente de aprobación. Espera a que sea validada.',
            'pending' => $estado !== 'rechazada',
            'estado_aprobacion' => $estado,
        ]);
        exit;
    }

    // Asegurar que exista registro en la tabla asesor
    if (empty($user['asesor_id'])) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Tu cuenta de asesor aún no está configurada. Contacta al supervisor.',
        ]);
        exit;
    }

    // ── Marcar asesor como CONECTADO en la tabla de presencia ──
    // Esto permite que el mapa del supervisor lo muestre inmediatamente
    // y que, al cerrar sesión, la bandera 'desconectado' lo oculte de inmediato.
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS asesor_presencia (
                asesor_id  VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
                estado     ENUM('conectado','desconectado') NOT NULL DEFAULT 'conectado',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $aid = (string)$user['asesor_id'];
        $presStmt = $conn->prepare(
            "INSERT INTO asesor_presencia (asesor_id, estado, updated_at)
             VALUES (?, 'conectado', NOW())
             ON DUPLICATE KEY UPDATE estado = 'conectado', updated_at = NOW()"
        );
        if ($presStmt) {
            $presStmt->bind_param('s', $aid);
            $presStmt->execute();
            $presStmt->close();
        }
    } catch (Exception $ex) {
        error_log('[login_asesor] No se pudo actualizar presencia: ' . $ex->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Login exitoso',
        'user' => [
            'id'        => $user['id'],
            'asesor_id' => $user['asesor_id'],
            'nombre'    => $user['nombre'] ?? '',
            'email'     => $user['email'] ?? $email,
            'telefono'  => $user['telefono'] ?? '',
            'rol'       => $user['rol'] ?? 'asesor',
        ],
    ]);

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error del servidor: ' . $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

<?php
/**
 * API: Obtener supervisores de una cooperativa
 * POST http://localhost/FUBER_APP/server_php/api_supervisores_cooperativa.php
 * POST Data: unidad_bancaria_id
 * Response: JSON array de supervisores
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

require_once 'db_config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Método no permitido");
    }

    $unidad_bancaria_id = $_POST['unidad_bancaria_id'] ?? $_GET['unidad_bancaria_id'] ?? null;
    
    if (!$unidad_bancaria_id) {
        throw new Exception("unidad_bancaria_id es requerido");
    }

    $conexion = new mysqli($db_host, $db_user, $db_password, 'base_super_ia');
    if ($conexion->connect_error) {
        throw new Exception("Conexión fallida: " . $conexion->connect_error);
    }
    $conexion->set_charset('utf8mb4');

    // Obtener supervisores de la cooperativa
    // Un supervisor está asociado a través de: supervisor -> jefe_agencia -> agencia.unidad_bancaria_id
    $result = $conexion->query(
        "SELECT DISTINCT 
            u.id,
            u.nombre,
            u.email
         FROM usuario u
         JOIN supervisor s ON s.usuario_id = u.id
         JOIN jefe_agencia ja ON ja.id = s.jefe_agencia_id
         JOIN agencia a ON a.id = ja.agencia_id
         WHERE a.unidad_bancaria_id = '$unidad_bancaria_id'
         AND u.rol = 'supervisor'
         AND u.activo = 1
         AND u.estado_aprobacion = 'aprobado'
         ORDER BY u.nombre ASC"
    );

    $supervisores = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $supervisores[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre'],
                'email' => $row['email']
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => $supervisores
    ]);

    $conexion->close();

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>

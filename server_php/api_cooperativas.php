<?php
/**
 * API: Obtener lista de cooperativas/bancos activos
 * GET http://localhost/FUBER_APP/server_php/api_cooperativas.php
 * Response: JSON array de cooperativas
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

require_once 'db_config.php';

try {
    $conexion = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($conexion->connect_error) {
        throw new Exception("Conexión fallida: " . $conexion->connect_error);
    }
    $conexion->set_charset('utf8mb4');

    // Obtener cooperativas/bancos (todas, incluyendo inactivas)
    $result = $conexion->query(
        "SELECT id, nombre, codigo, descripcion as ciudad
         FROM unidad_bancaria 
         ORDER BY nombre ASC"
    );

    $cooperativas = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $cooperativas[] = [
                'id' => $row['id'],
                'nombre' => $row['nombre'],
                'codigo' => $row['codigo'],
                'ciudad' => $row['ciudad']
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => $cooperativas
    ]);

    $conexion->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>

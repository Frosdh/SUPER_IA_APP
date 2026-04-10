<?php
// ============================================================
// obtener_supervisores.php — Lista supervisores activos
// GET/POST  →  JSON
// Esquema: SUPER_IA LOGAN (super_ia_logan)
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/db_config.php';

// Asegurar columna token_fcm en usuario (MySQL 8+)
$conn->query("ALTER TABLE usuario ADD COLUMN IF NOT EXISTS token_fcm VARCHAR(500) DEFAULT NULL");

$agencia_id = isset($_REQUEST['agencia_id']) ? trim($_REQUEST['agencia_id']) : '';

if ($agencia_id !== '') {
    // Filtrar supervisores que pertenecen a la agencia indicada (via jefe_agencia)
    $stmt = $conn->prepare(
        "SELECT s.id AS supervisor_id,
                u.nombre,
                u.email,
                a.id   AS agencia_id,
                a.nombre AS agencia_nombre
         FROM supervisor s
         JOIN usuario u         ON u.id  = s.usuario_id
         JOIN jefe_agencia ja   ON ja.id = s.jefe_agencia_id
         JOIN agencia a         ON a.id  = ja.agencia_id
         WHERE u.activo = 1
           AND a.id = ?
         ORDER BY u.nombre"
    );
    $stmt->bind_param('s', $agencia_id);
} else {
    // Todos los supervisores activos
    $stmt = $conn->prepare(
        "SELECT s.id AS supervisor_id,
                u.nombre,
                u.email,
                a.id    AS agencia_id,
                a.nombre AS agencia_nombre
         FROM supervisor s
         JOIN usuario u         ON u.id  = s.usuario_id
         LEFT JOIN jefe_agencia ja ON ja.id = s.jefe_agencia_id
         LEFT JOIN agencia a       ON a.id  = ja.agencia_id
         WHERE u.activo = 1
         ORDER BY u.nombre"
    );
}

$stmt->execute();
$result = $stmt->get_result();

$supervisores = [];
while ($row = $result->fetch_assoc()) {
    $supervisores[] = [
        'id'              => $row['supervisor_id'],
        'nombre'          => $row['nombre'],
        'email'           => $row['email'],
        'agencia_id'      => $row['agencia_id'] ?? null,
        'agencia_nombre'  => $row['agencia_nombre'] ?? null,
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'status'       => 'success',
    'supervisores' => $supervisores,
]);
?>

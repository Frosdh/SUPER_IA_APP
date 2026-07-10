<?php
/**
 * Devuelve los gerentes reales vinculados a una cooperativa (unidad_bancaria),
 * para el select "Gerente Responsable" del registro de supervisor.
 *
 * Antes esta consulta (api_administradores_por_coop.php) leía una tabla
 * legacy `usuarios` + `roles` totalmente desconectada de la cooperativa
 * seleccionada (devolvía TODOS los "Admin"/"SuperAdmin" sin filtrar por
 * cooperativa_id). Se reemplaza por una consulta real contra `usuario`
 * (la tabla UUID que usa todo el sistema), enlazando por las dos vías
 * posibles de un gerente:
 *   - gerente_general.unidad_bancaria_id  (gerente general directo)
 *   - jefe_agencia -> agencia.unidad_bancaria_id (jefe de agencia de esa coop)
 */
require_once 'db_admin.php';

header('Content-Type: application/json');

if (!isset($_GET['cooperativa_id']) || $_GET['cooperativa_id'] === '') {
    echo json_encode(['error' => 'cooperativa_id es requerido']);
    exit;
}

$cooperativa_id = (string)$_GET['cooperativa_id'];

// Las cooperativas importadas del catastro SEPS (id con prefijo "seps_") son
// solo catálogo externo: no tienen gerente interno asignado en el sistema.
if (strpos($cooperativa_id, 'seps_') === 0) {
    echo json_encode([
        'status' => 'ok',
        'gerentes' => [],
        'info' => 'Cooperativa del catastro SEPS: aún no tiene gerente asignado en el sistema.'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT u.id AS id_usuario, u.nombre, u.email
        FROM gerente_general gg
        JOIN usuario u ON u.id = gg.usuario_id
        WHERE gg.unidad_bancaria_id = ? AND u.activo = 1

        UNION

        SELECT u.id AS id_usuario, u.nombre, u.email
        FROM jefe_agencia ja
        JOIN agencia ag ON ag.id = ja.agencia_id
        JOIN usuario u ON u.id = ja.usuario_id
        WHERE ag.unidad_bancaria_id = ? AND u.activo = 1

        ORDER BY nombre ASC
    ");
    $stmt->execute([$cooperativa_id, $cooperativa_id]);
    $gerentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'ok',
        'gerentes' => $gerentes
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => 'Error al cargar gerentes: ' . $e->getMessage()
    ]);
}

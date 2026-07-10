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

// Las cooperativas importadas del catastro SEPS (id con prefijo "seps_") no
// tienen gerente directamente sobre esa fila: cuando se aprueba un gerente
// para una de estas cooperativas, administrar_solicitudes_admin.php crea
// una fila "espejo" en unidad_bancaria (codigo = 'SEPS-<id>') y el gerente
// queda enlazado a ESA fila (gerente_general.unidad_bancaria_id). Antes esta
// API cortaba aquí y devolvía siempre lista vacía para toda cooperativa
// "seps_", así que un gerente ya aprobado nunca aparecía como opción al
// registrar un supervisor. Ahora se resuelve la fila espejo (si existe) y
// se sigue el mismo camino que las cooperativas internas.
if (strpos($cooperativa_id, 'seps_') === 0) {
    $sepsId = substr($cooperativa_id, 5);
    $codigo = 'SEPS-' . $sepsId;
    $stEspejo = $pdo->prepare('SELECT id FROM unidad_bancaria WHERE codigo = ? LIMIT 1');
    $stEspejo->execute([$codigo]);
    $ubId = $stEspejo->fetchColumn();

    if (!$ubId) {
        echo json_encode([
            'status' => 'ok',
            'gerentes' => [],
            'info' => 'Cooperativa del catastro SEPS: aún no tiene gerente asignado en el sistema.'
        ]);
        exit;
    }
    $cooperativa_id = $ubId;
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

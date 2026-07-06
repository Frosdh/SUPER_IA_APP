<?php
// ============================================================
// obtener_perfil_asesor.php
// ------------------------------------------------------------
// Devuelve los datos del asesor para la pantalla "Mi Perfil" de la
// app móvil: los editables (nombre, email, teléfono, ya vienen de
// la tabla `usuario`) y los de solo lectura que ya existen en la
// base pero que la app nunca mostraba: supervisor asignado, agencia
// y las metas fijas que le puso su supervisor (tabla `asesor`).
//
// GET/POST obtener_perfil_asesor.php?usuario_id=...
//        (o asesor_id=... si no se tiene a mano el usuario_id)
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_config.php';
mysqli_report(MYSQLI_REPORT_OFF);

function resp($ok, array $data = [], string $msg = '') {
    echo json_encode(array_merge(
        ['status' => $ok ? 'success' : 'error', 'message' => $msg],
        $data
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$conn || $conn->connect_errno) {
    resp(false, [], 'DB no disponible');
}
$conn->set_charset('utf8mb4');

$usuario_id = trim($_POST['usuario_id'] ?? $_GET['usuario_id'] ?? '');
$asesor_id  = trim($_POST['asesor_id']  ?? $_GET['asesor_id']  ?? '');

if ($usuario_id === '' && $asesor_id === '') {
    resp(false, [], 'Falta usuario_id o asesor_id');
}

try {
    // ── Datos base del usuario (nombre/email/teléfono/agencia) ──
    if ($usuario_id !== '') {
        $st = $conn->prepare("
            SELECT u.id, u.nombre, u.email, u.telefono, u.rol,
                   ag.nombre AS agencia_nombre, ag.ciudad AS agencia_ciudad
            FROM usuario u
            LEFT JOIN agencia ag ON ag.id = u.agencia_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $st->bind_param('s', $usuario_id);
    } else {
        // Solo se tiene asesor_id: resolver el usuario a través de asesor
        $st = $conn->prepare("
            SELECT u.id, u.nombre, u.email, u.telefono, u.rol,
                   ag.nombre AS agencia_nombre, ag.ciudad AS agencia_ciudad
            FROM asesor a
            INNER JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN agencia ag ON ag.id = u.agencia_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $st->bind_param('s', $asesor_id);
    }
    $st->execute();
    $usuario = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$usuario) {
        resp(false, [], 'No se encontró la cuenta del asesor');
    }
    $usuario_id = $usuario['id'];

    // ── Fila de asesor: metas fijas + supervisor asignado ────────
    $stA = $conn->prepare("
        SELECT a.id AS asesor_id, a.meta_tareas_diarias, a.meta_visitas_mes,
               a.meta_visitas, a.meta_monto_creditos_aprobados,
               a.meta_cuentas_ahorro_abiertas, a.meta_inversiones_aprobadas,
               su.nombre AS supervisor_nombre
        FROM asesor a
        LEFT JOIN supervisor s ON s.id = a.supervisor_id
        LEFT JOIN usuario su ON su.id = s.usuario_id
        WHERE a.usuario_id = ?
        LIMIT 1
    ");
    $stA->bind_param('s', $usuario_id);
    $stA->execute();
    $asesor = $stA->get_result()->fetch_assoc();
    $stA->close();

    resp(true, [
        'usuario_id'       => $usuario_id,
        'nombre'           => $usuario['nombre']   ?? '',
        'email'            => $usuario['email']    ?? '',
        'telefono'         => $usuario['telefono'] ?? '',
        'rol'              => $usuario['rol']      ?? 'asesor',
        'agencia_nombre'   => $usuario['agencia_nombre'] ?: null,
        'agencia_ciudad'   => $usuario['agencia_ciudad'] ?: null,
        'asesor_id'                     => $asesor['asesor_id']                     ?? null,
        'supervisor_nombre'             => $asesor['supervisor_nombre']             ?? null,
        'meta_tareas_diarias'           => isset($asesor['meta_tareas_diarias'])           ? (int)$asesor['meta_tareas_diarias']           : null,
        'meta_visitas_mes'              => isset($asesor['meta_visitas_mes'])              ? (int)$asesor['meta_visitas_mes']              : null,
        'meta_visitas'                  => isset($asesor['meta_visitas'])                  ? (int)$asesor['meta_visitas']                  : null,
        'meta_monto_creditos_aprobados' => isset($asesor['meta_monto_creditos_aprobados']) ? (int)$asesor['meta_monto_creditos_aprobados'] : null,
        'meta_cuentas_ahorro_abiertas'  => isset($asesor['meta_cuentas_ahorro_abiertas'])  ? (int)$asesor['meta_cuentas_ahorro_abiertas']  : null,
        'meta_inversiones_aprobadas'    => isset($asesor['meta_inversiones_aprobadas'])    ? (int)$asesor['meta_inversiones_aprobadas']    : null,
    ]);
} catch (\Throwable $e) {
    resp(false, [], 'Error interno: ' . $e->getMessage());
}

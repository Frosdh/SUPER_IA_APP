<?php
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/email_helper.php';

// ============================================================
// reportar_objeto_perdido.php
// El pasajero reporta un objeto perdido en un viaje terminado.
// Crea un ticket de soporte tipo "otro" con los detalles,
// y envía email de confirmación al pasajero + aviso al admin.
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit;
}

$telefono     = trim($_POST['telefono']     ?? '');
$viajeId      = intval($_POST['viaje_id']   ?? 0);
$descripcion  = trim($_POST['descripcion']  ?? '');

// ── Validaciones ─────────────────────────────────────────────
if (empty($telefono) || $viajeId <= 0 || empty($descripcion)) {
    echo json_encode(["status" => "error", "message" => "Faltan datos requeridos (telefono, viaje_id, descripcion)"]);
    exit;
}
if (mb_strlen($descripcion) > 500) {
    echo json_encode(["status" => "error", "message" => "La descripción no puede superar 500 caracteres"]);
    exit;
}

// ── Obtener usuario ───────────────────────────────────────────
$stmtUser = $conn->prepare("SELECT id, nombre, email FROM usuarios WHERE telefono = ? LIMIT 1");
$stmtUser->bind_param("s", $telefono);
$stmtUser->execute();
$stmtUser->bind_result($usuarioId, $nombreUsuario, $emailUsuario);
$stmtUser->fetch();
$stmtUser->close();

if (!$usuarioId) {
    echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
    exit;
}

// ── Verificar que el viaje pertenece al usuario y está terminado ──
$stmtViaje = $conn->prepare("
    SELECT v.id, v.origen_texto, v.destino_texto, v.fecha_pedido,
           c.nombre AS conductor_nombre, c.telefono AS conductor_telefono,
           ve.marca, ve.modelo, ve.placa
    FROM viajes v
    JOIN conductores c ON c.id = v.conductor_id
    LEFT JOIN vehiculos ve ON ve.conductor_id = v.conductor_id
    WHERE v.id = ? AND v.usuario_id = ? AND v.estado = 'terminado'
    LIMIT 1
");
$stmtViaje->bind_param("ii", $viajeId, $usuarioId);
$stmtViaje->execute();
$rowViaje = $stmtViaje->get_result()->fetch_assoc();
$stmtViaje->close();

if (!$rowViaje) {
    echo json_encode(["status" => "error", "message" => "Viaje no encontrado o no finalizado"]);
    exit;
}

// ── Verificar que no existe ya un reporte para este viaje ────
$stmtCheck = $conn->prepare("
    SELECT id FROM tickets_soporte
    WHERE usuario_id = ? AND viaje_id = ? AND tipo = 'otro'
    AND asunto LIKE '%objeto perdido%'
    LIMIT 1
");
$stmtCheck->bind_param("ii", $usuarioId, $viajeId);
$stmtCheck->execute();
$stmtCheck->bind_result($ticketExistente);
$stmtCheck->fetch();
$stmtCheck->close();

if ($ticketExistente) {
    echo json_encode(["status" => "error", "message" => "Ya existe un reporte para este viaje"]);
    exit;
}

// ── Crear ticket de soporte ───────────────────────────────────
$asuntoTicket  = "Objeto perdido en viaje #$viajeId";
$mensajeTicket = "El pasajero reporta haber olvidado un objeto en el viaje.\n\n"
               . "Descripción del objeto: $descripcion\n\n"
               . "Conductor: " . $rowViaje['conductor_nombre'] . "\n"
               . "Teléfono conductor: " . $rowViaje['conductor_telefono'] . "\n"
               . "Vehículo: " . trim(($rowViaje['marca'] ?? '') . ' ' . ($rowViaje['modelo'] ?? '')) . "\n"
               . "Placa: " . ($rowViaje['placa'] ?? '-') . "\n"
               . "Fecha del viaje: " . date('d/m/Y H:i', strtotime($rowViaje['fecha_pedido']));

$stmtTicket = $conn->prepare("
    INSERT INTO tickets_soporte (usuario_id, viaje_id, tipo, asunto, mensaje, estado)
    VALUES (?, ?, 'otro', ?, ?, 'abierto')
");
$stmtTicket->bind_param("iiss", $usuarioId, $viajeId, $asuntoTicket, $mensajeTicket);
$stmtTicket->execute();
$ticketId = $conn->insert_id;
$stmtTicket->close();

// ── Email de confirmación al pasajero ─────────────────────────
if (!empty($emailUsuario)) {
    $conductor = $rowViaje['conductor_nombre'];
    $placa     = $rowViaje['placa'] ?? '-';
    $fecha     = date('d/m/Y H:i', strtotime($rowViaje['fecha_pedido']));
    $vehiculo  = trim(($rowViaje['marca'] ?? '') . ' ' . ($rowViaje['modelo'] ?? ''));

    $htmlConfirmacion = "
    <div style='margin:0;padding:32px 16px;background:#07101f;font-family:Arial,sans-serif;color:#eef4ff;'>
        <div style='max-width:580px;margin:0 auto;background:linear-gradient(180deg,#101933,#0a1226);border-radius:28px;overflow:hidden;border:1px solid rgba(127,150,255,.16);box-shadow:0 20px 60px rgba(0,0,0,.4);'>
            <div style='padding:32px 32px 20px;background:linear-gradient(135deg,#182455,#0d1430);'>
                <div style='display:inline-block;padding:8px 16px;border-radius:999px;background:rgba(255,200,80,.15);color:#ffd580;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;'>GeoMove · Objeto perdido</div>
                <h1 style='margin:18px 0 8px;font-size:26px;color:#fff;'>Recibimos tu reporte</h1>
                <p style='margin:0;color:#b2c2e7;font-size:15px;line-height:1.6;'>Hola <strong>$nombreUsuario</strong>, hemos registrado tu reporte #$ticketId. Nos pondremos en contacto contigo pronto.</p>
            </div>
            <div style='padding:24px 32px 32px;'>
                <div style='padding:20px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);margin-bottom:18px;'>
                    <div style='font-size:12px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;'>Objeto reportado</div>
                    <div style='font-size:15px;color:#eef4ff;'>$descripcion</div>
                </div>
                <div style='padding:20px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);'>
                    <div style='font-size:12px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;'>Detalles del viaje</div>
                    <div style='font-size:14px;color:#a9b8da;line-height:1.8;'>
                        Conductor: <strong style='color:#fff;'>$conductor</strong><br>
                        Vehículo: <strong style='color:#fff;'>$vehiculo</strong> · Placa <strong style='color:#fff;'>$placa</strong><br>
                        Fecha: <strong style='color:#fff;'>$fecha</strong>
                    </div>
                </div>
                <div style='margin-top:24px;text-align:center;color:#7a8fb0;font-size:13px;'>
                    Número de reporte: <strong style='color:#eef4ff;'>#$ticketId</strong><br>
                    <span style='font-size:12px;'>Puedes ver el estado en la sección de Soporte de la app.</span>
                </div>
            </div>
        </div>
    </div>";

    $textoConfirmacion = "Hola $nombreUsuario,\n\nRecibimos tu reporte de objeto perdido en el viaje #$viajeId.\n"
        . "Número de reporte: #$ticketId\n"
        . "Objeto: $descripcion\n"
        . "Conductor: $conductor · Placa: $placa\n\n"
        . "Nos pondremos en contacto contigo pronto.\n\nGeoMove";

    sendEmailMessage($emailUsuario, "Reporte de objeto perdido #$ticketId – GeoMove", $htmlConfirmacion, $textoConfirmacion);
}

echo json_encode([
    "status"    => "success",
    "message"   => "Reporte creado correctamente",
    "ticket_id" => $ticketId
]);

$conn->close();
?>

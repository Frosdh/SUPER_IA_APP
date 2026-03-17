<?php
require_once __DIR__ . '/db_config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");

$conductorId = 0;
if (isset($_POST['conductor_id'])) $conductorId = intval($_POST['conductor_id']);
if (isset($_GET['conductor_id']))  $conductorId = intval($_GET['conductor_id']);

if ($conductorId <= 0) {
    echo json_encode(["status" => "error", "message" => "conductor_id requerido"]);
    exit;
}

// ── Datos del conductor ────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, nombre,
           IFNULL(email, '')    AS email,
           telefono, cedula,
           IFNULL(ciudad, 'Cuenca') AS ciudad,
           IFNULL(foto_perfil, '')  AS foto_perfil,
           verificado, estado, creado_en,
           IFNULL(calificacion_promedio, 0) AS calificacion
    FROM conductores
    WHERE id = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "Error preparando consulta: " . $conn->error]);
    $conn->close();
    exit;
}
$stmt->bind_param("i", $conductorId);
$stmt->execute();
$result = $stmt->get_result();
$conductor = $result->fetch_assoc();
$stmt->close();

if (!$conductor) {
    echo json_encode(["status" => "error", "message" => "Conductor no encontrado"]);
    $conn->close();
    exit;
}

// ── Vehículo ───────────────────────────────────────────────────────────────
$stmtV = $conn->prepare("SELECT marca, modelo, placa, color, anio FROM vehiculos WHERE conductor_id = ? LIMIT 1");
$stmtV->bind_param("i", $conductorId);
$stmtV->execute();
$vehiculo = $stmtV->get_result()->fetch_assoc();
$stmtV->close();
if ($vehiculo) $vehiculo['categoria_nombre'] = '';

// ── Documentos ────────────────────────────────────────────────────────────
$documentos = [];
$stmtD = $conn->prepare("
    SELECT tipo, estado, notas, updated_at
    FROM documentos_conductor
    WHERE conductor_id = ?
    ORDER BY FIELD(tipo, 'licencia_frente','licencia_reverso','cedula','soat','matricula')
");
if ($stmtD) {
    $stmtD->bind_param("i", $conductorId);
    $stmtD->execute();
    $resD = $stmtD->get_result();
    while ($row = $resD->fetch_assoc()) {
        $documentos[] = $row;
    }
    $stmtD->close();
}
// Si no hay tabla documentos_conductor simplemente queda array vacío

// ── Estadísticas de viajes ─────────────────────────────────────────────────
$totalViajes  = 0;
$gananciaTotal = 0.0;

$resS = $conn->query(
    "SELECT COUNT(*) AS total, COALESCE(SUM(tarifa_total), 0) AS ganancia
     FROM viajes
     WHERE conductor_id = $conductorId AND estado = 'terminado'"
);
if ($resS) {
    $rowS = $resS->fetch_assoc();
    $totalViajes   = (int)($rowS['total']    ?? 0);
    $gananciaTotal = (float)($rowS['ganancia'] ?? 0);
    $resS->free();
}

$conn->close();

// ── Respuesta ──────────────────────────────────────────────────────────────
echo json_encode([
    "status"    => "success",
    "conductor" => [
        "id"           => (int)$conductor['id'],
        "nombre"       => $conductor['nombre'],
        "email"        => $conductor['email'],
        "telefono"     => $conductor['telefono'],
        "cedula"       => $conductor['cedula'],
        "ciudad"       => $conductor['ciudad'],
        "foto_perfil"  => $conductor['foto_perfil'],
        "verificado"   => (int)$conductor['verificado'],
        "estado"       => $conductor['estado'],
        "calificacion" => round((float)$conductor['calificacion'], 1),
        "categoria"    => $vehiculo['categoria_nombre'] ?? '',
        "creado_en"    => $conductor['creado_en'],
    ],
    "vehiculo"   => $vehiculo ?: null,
    "documentos" => $documentos,
    "stats"      => [
        "total_viajes"   => $totalViajes,
        "ganancia_total" => round($gananciaTotal, 2),
    ],
]);
?>

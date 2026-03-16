<?php
// ============================================================
// historial_conductor.php  —  Historial de viajes del conductor
// Colocar en: /fuber_api/historial_conductor.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host     = "localhost";
$dbname   = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$conductorId = isset($_POST['conductor_id']) ? intval($_POST['conductor_id']) : 0;
$page        = isset($_POST['page'])         ? max(1, intval($_POST['page'])) : 1;
$limit       = 20;
$offset      = ($page - 1) * $limit;

if ($conductorId <= 0) {
    echo json_encode(["status" => "error", "message" => "conductor_id invalido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}
$conn->set_charset("utf8");

// ── Total de viajes terminados ──────────────────────────────
// Usamos query() directo con entero seguro (no necesita mysqlnd)
$resTotal = $conn->query(
    "SELECT COUNT(*) AS total FROM viajes WHERE conductor_id = $conductorId AND estado = 'terminado'"
);
$total = 0;
if ($resTotal) {
    $rowTotal = $resTotal->fetch_assoc();
    $total = (int)($rowTotal['total'] ?? 0);
    $resTotal->free();
}

// ── Ganancia total acumulada ────────────────────────────────
$resGanancia = $conn->query(
    "SELECT COALESCE(SUM(tarifa_total), 0) AS ganancia FROM viajes WHERE conductor_id = $conductorId AND estado = 'terminado'"
);
$gananciaTotal = 0.0;
if ($resGanancia) {
    $rowG = $resGanancia->fetch_assoc();
    $gananciaTotal = (float)($rowG['ganancia'] ?? 0);
    $resGanancia->free();
}

// ── Lista de viajes paginada ────────────────────────────────
$sql = "
    SELECT
        v.id,
        v.origen_texto,
        v.destino_texto,
        v.distancia_km,
        v.duracion_min,
        v.tarifa_total,
        v.fecha_pedido,
        v.fecha_fin,
        COALESCE(u.nombre, 'Pasajero') AS pasajero_nombre
    FROM viajes v
    LEFT JOIN usuarios u ON u.id = v.usuario_id
    WHERE v.conductor_id = $conductorId AND v.estado = 'terminado'
    ORDER BY v.fecha_fin DESC
    LIMIT $limit OFFSET $offset
";

$resViajes = $conn->query($sql);

$viajes = [];
if ($resViajes) {
    while ($row = $resViajes->fetch_assoc()) {
        $viajes[] = [
            'id'              => (int)$row['id'],
            'origen_texto'    => $row['origen_texto']    ?? '',
            'destino_texto'   => $row['destino_texto']   ?? '',
            'distancia_km'    => (float)($row['distancia_km']  ?? 0),
            'duracion_min'    => (float)($row['duracion_min']  ?? 0),
            'tarifa_total'    => (float)($row['tarifa_total']  ?? 0),
            'fecha_pedido'    => $row['fecha_pedido']    ?? '',
            'fecha_fin'       => $row['fecha_fin']       ?? '',
            'pasajero_nombre' => $row['pasajero_nombre'] ?? 'Pasajero',
        ];
    }
    $resViajes->free();
}

$conn->close();

echo json_encode([
    "status"         => "success",
    "total"          => $total,
    "page"           => $page,
    "ganancia_total" => $gananciaTotal,
    "viajes"         => $viajes,
]);
?>

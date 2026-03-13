<?php
// ============================================================
// validar_codigo.php  –  Valida un código de descuento
// Colocar en: /fuber_api/validar_codigo.php
// ============================================================
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

$host = "localhost";
$dbname = "corporat_fuber_db";
$username = "corporat_fuber_user";
$password = 'FuB3r!Db#2026$Qx9';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

$codigo = isset($_POST['codigo']) ? strtoupper(trim($_POST['codigo'])) : '';

if (empty($codigo)) {
    echo json_encode(["status" => "error", "message" => "Codigo requerido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

// Buscar el código en la base de datos
$stmt = $conn->prepare("
    SELECT id, codigo, tipo, valor, minimo_viaje, maximo_usos, usos_actuales,
           fecha_inicio, fecha_fin, activo
    FROM codigos_descuento
    WHERE codigo = ?
    LIMIT 1
");
$stmt->bind_param("s", $codigo);
$stmt->execute();
$result = $stmt->get_result();
$cupon  = $result->fetch_assoc();
$stmt->close();

if (!$cupon) {
    echo json_encode(["status" => "error", "message" => "Codigo no encontrado"]);
    $conn->close();
    exit;
}

// Validaciones del lado del servidor
$ahora = new DateTime();

if (!$cupon['activo']) {
    echo json_encode(["status" => "error", "message" => "Este codigo no esta activo"]);
    $conn->close();
    exit;
}

if ($cupon['fecha_inicio'] && new DateTime($cupon['fecha_inicio']) > $ahora) {
    echo json_encode(["status" => "error", "message" => "Este codigo aun no esta disponible"]);
    $conn->close();
    exit;
}

if ($cupon['fecha_fin'] && new DateTime($cupon['fecha_fin']) < $ahora) {
    echo json_encode(["status" => "error", "message" => "Este codigo ha expirado"]);
    $conn->close();
    exit;
}

if ($cupon['maximo_usos'] !== null && $cupon['usos_actuales'] >= $cupon['maximo_usos']) {
    echo json_encode(["status" => "error", "message" => "Este codigo ya no tiene usos disponibles"]);
    $conn->close();
    exit;
}

// Código válido — devolver datos completos
echo json_encode([
    "status" => "success",
    "message" => "Codigo valido",
    "codigo" => [
        "id"            => (int)$cupon['id'],
        "codigo"        => $cupon['codigo'],
        "tipo"          => $cupon['tipo'],
        "valor"         => (float)$cupon['valor'],
        "minimo_viaje"  => (float)$cupon['minimo_viaje'],
        "maximo_usos"   => (int)$cupon['maximo_usos'],
        "usos_actuales" => (int)$cupon['usos_actuales'],
        "fecha_inicio"  => $cupon['fecha_inicio'],
        "fecha_fin"     => $cupon['fecha_fin'],
        "activo"        => (int)$cupon['activo'],
    ]
]);

$conn->close();
?>

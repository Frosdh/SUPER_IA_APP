<?php
// ============================================================
// registrar_uso_codigo.php  –  Incrementa el contador de usos
//                              del código tras confirmar viaje
// Colocar en: /fuber_api/registrar_uso_codigo.php
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

$codigo   = isset($_POST['codigo'])   ? strtoupper(trim($_POST['codigo']))  : '';
$viajeId  = isset($_POST['viaje_id']) ? intval($_POST['viaje_id'])          : 0;

if (empty($codigo)) {
    echo json_encode(["status" => "error", "message" => "Codigo requerido"]);
    exit;
}

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Error de conexion: " . $conn->connect_error]);
    exit;
}

// Incrementar usos_actuales del código
$stmt = $conn->prepare("
    UPDATE codigos_descuento
    SET usos_actuales = usos_actuales + 1
    WHERE codigo = ? AND activo = 1
");
$stmt->bind_param("s", $codigo);
$stmt->execute();
$afectadas = $stmt->affected_rows;
$stmt->close();

if ($afectadas === 0) {
    echo json_encode(["status" => "error", "message" => "No se encontro el codigo o ya no esta activo"]);
    $conn->close();
    exit;
}

// Si tenemos viaje_id, desactivar automáticamente si alcanzó el máximo de usos
if ($viajeId > 0) {
    $conn->query("
        UPDATE codigos_descuento
        SET activo = 0
        WHERE codigo = '$codigo'
          AND maximo_usos IS NOT NULL
          AND usos_actuales >= maximo_usos
    ");
}

echo json_encode([
    "status"  => "success",
    "message" => "Uso registrado correctamente",
    "codigo"  => $codigo,
    "viaje_id" => $viajeId,
]);

$conn->close();
?>

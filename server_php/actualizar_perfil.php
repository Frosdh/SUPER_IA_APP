<?php
require_once __DIR__ . '/db_config.php';

// ============================================================
// actualizar_perfil.php - Actualiza nombre, teléfono y email del
// asesor que inició sesión en la app móvil.
//
// IMPORTANTE (fix 2026-07): la versión anterior de este script
// actualizaba una tabla `usuarios` (plural) que no existe en este
// proyecto — la tabla real es `usuario` (singular, ver
// server_php/db_config.php / esquema de la base). Además buscaba
// el registro por `telefono`, que no es una clave confiable (puede
// repetirse o venir vacío). Por eso, aunque la app mostraba
// "Perfil actualizado", el cambio NUNCA llegaba a guardarse en el
// servidor: solo quedaba en el teléfono del asesor (SharedPreferences),
// se perdía al reinstalar la app o iniciar sesión en otro equipo, y
// nunca se reflejaba en el panel de supervisores/admin.
//
// Ahora se identifica al usuario por `usuario_id` (guardado en el
// celular desde el login, ver AuthPrefs.getUsuarioId()), que es la
// llave primaria real de la tabla `usuario`. Se mantiene un
// respaldo por `telefono` solo por compatibilidad con versiones
// viejas de la app que todavía no envíen usuario_id.
// ============================================================
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Metodo no permitido"]);
    exit;
}

function campo(string $key): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
}

$usuario_id = campo('usuario_id');
$telefono   = campo('telefono');
$nombre     = campo('nombre');
$email      = campo('email');

if (empty($nombre)) {
    echo json_encode(["status" => "error", "message" => "El nombre no puede estar vacío"]);
    exit;
}

if (empty($usuario_id) && empty($telefono)) {
    echo json_encode(["status" => "error", "message" => "Falta usuario_id o telefono para identificar la cuenta"]);
    exit;
}

try {
    // Resolver el usuario_id real si solo llegó el teléfono (apps viejas)
    if (empty($usuario_id) && !empty($telefono)) {
        $stFind = $conn->prepare("SELECT id FROM usuario WHERE telefono = ? LIMIT 1");
        $stFind->bind_param('s', $telefono);
        $stFind->execute();
        $rowFind = $stFind->get_result()->fetch_assoc();
        $stFind->close();
        if (!$rowFind) {
            echo json_encode(["status" => "error", "message" => "No se encontró la cuenta del asesor"]);
            exit;
        }
        $usuario_id = $rowFind['id'];
    }

    // Construir UPDATE dinámico: el teléfono solo se actualiza si vino en
    // el request (algunas pantallas lo dejan de solo lectura).
    $sets   = ['nombre = ?'];
    $types  = 's';
    $params = [$nombre];

    if ($telefono !== '') {
        $sets[]   = 'telefono = ?';
        $types   .= 's';
        $params[] = $telefono;
    }

    // email puede quedar vacío (columna NOT NULL en el esquema actual, así
    // que si viene vacío no lo tocamos para no romper la fila existente).
    if ($email !== '') {
        $sets[]   = 'email = ?';
        $types   .= 's';
        $params[] = $email;
    }

    $types   .= 's';
    $params[] = $usuario_id;

    $sql = "UPDATE usuario SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Error preparando la actualización: " . $conn->error]);
        exit;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    if ($stmt->error) {
        // p.ej. email duplicado (uk_usuario_email)
        $msg = stripos($stmt->error, 'Duplicate') !== false
            ? 'Ese correo ya está en uso por otra cuenta'
            : ('Error al actualizar: ' . $stmt->error);
        echo json_encode(["status" => "error", "message" => $msg]);
        $stmt->close();
        exit;
    }
    $stmt->close();

    // Devolver el estado actual ya guardado en la BD (por si algún campo
    // no se tocó, p. ej. email vacío)
    $stGet = $conn->prepare("SELECT nombre, telefono, email FROM usuario WHERE id = ? LIMIT 1");
    $stGet->bind_param('s', $usuario_id);
    $stGet->execute();
    $actual = $stGet->get_result()->fetch_assoc() ?: [];
    $stGet->close();

    echo json_encode([
        "status"     => "success",
        "message"    => "Perfil actualizado correctamente",
        "usuario_id" => $usuario_id,
        "nombre"     => $actual['nombre']   ?? $nombre,
        "telefono"   => $actual['telefono'] ?? $telefono,
        "email"      => $actual['email']    ?? $email,
    ]);
} catch (\Throwable $e) {
    echo json_encode(["status" => "error", "message" => "Error interno: " . $e->getMessage()]);
}

$conn->close();

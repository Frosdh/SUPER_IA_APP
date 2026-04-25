<?php
// ============================================================
// api_recuperar_password_asesor.php
// Recuperación de contraseña para asesores (app móvil Flutter).
//
// Acciones (POST):
//   action=enviar_otp      → genera y envía OTP al email del asesor
//   action=verificar_otp   → verifica que el código sea válido
//   action=nueva_password  → actualiza la contraseña en la DB
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db_config.php';

// Conexión PDO
try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión']);
    exit;
}

$action = trim($_POST['action'] ?? '');

// ──────────────────────────────────────────────────────────
// 1. ENVIAR OTP
// ──────────────────────────────────────────────────────────
if ($action === 'enviar_otp') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Ingresa un correo válido.']);
        exit;
    }

    // Buscar asesor activo en la tabla usuario
    $stmt = $pdo->prepare(
        "SELECT id, nombre FROM usuario
         WHERE email = ? AND rol = 'asesor' AND activo = 1
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Por seguridad: respuesta igual aunque no exista
    if (!$user) {
        echo json_encode([
            'status'  => 'success',
            'message' => 'Si el correo está registrado, recibirás un código.',
        ]);
        exit;
    }

    // Generar OTP de 6 dígitos
    $codigo    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expira_en = date('Y-m-d H:i:s', time() + 600); // 10 minutos

    // Invalidar OTPs anteriores
    $pdo->prepare("UPDATE email_otp_codes SET usado = 1 WHERE email = ? AND usado = 0")
        ->execute([$email]);

    // Insertar nuevo OTP
    $pdo->prepare(
        "INSERT INTO email_otp_codes (email, codigo, expira_en, usado, creado_en)
         VALUES (?, ?, ?, 0, NOW())"
    )->execute([$email, $codigo, $expira_en]);

    // Enviar email
    $sent     = false;
    $mailErr  = '';
    $helperPath = __DIR__ . '/email_helper.php';

    if (file_exists($helperPath)) {
        require_once $helperPath;
        $html  = buildOtpEmailHtml($codigo);
        $plain = buildOtpEmailText($codigo);
        list($sent, $mailErr) = sendEmailMessage(
            $email,
            'Código de recuperación — Super_IA',
            $html,
            $plain
        );
    }

    if ($sent) {
        echo json_encode([
            'status'  => 'success',
            'message' => 'Código enviado a tu correo. Revisa tu bandeja de entrada.',
        ]);
    } else {
        echo json_encode([
            'status'    => 'error',
            'message'   => 'SMTP Error: ' . ($mailErr ?: 'email_config.php no encontrado o credenciales incorrectas'),
            'smtp_error' => $mailErr,
        ]);
    }
    exit;
}

// ──────────────────────────────────────────────────────────
// 2. VERIFICAR OTP
// ──────────────────────────────────────────────────────────
if ($action === 'verificar_otp') {
    $email  = trim($_POST['email']  ?? '');
    $codigo = trim($_POST['codigo'] ?? '');

    if (empty($email) || empty($codigo)) {
        echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM email_otp_codes
         WHERE email = ? AND codigo = ? AND usado = 0 AND expira_en > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email, $codigo]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Código incorrecto o expirado.']);
        exit;
    }

    // Marcar como usado
    $pdo->prepare("UPDATE email_otp_codes SET usado = 1 WHERE id = ?")
        ->execute([$row['id']]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Código verificado correctamente.',
    ]);
    exit;
}

// ──────────────────────────────────────────────────────────
// 3. NUEVA CONTRASEÑA
// ──────────────────────────────────────────────────────────
if ($action === 'nueva_password') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['nueva_password'] ?? '';

    if (empty($email) || strlen($password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Datos inválidos.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        "UPDATE usuario SET password_hash = ?
         WHERE email = ? AND rol = 'asesor' AND activo = 1"
    );
    $stmt->execute([$hash, $email]);

    if ($stmt->rowCount() === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No se encontró el usuario.']);
        exit;
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.',
    ]);
    exit;
}

// Acción no reconocida
echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);

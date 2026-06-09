<?php
// api_recuperar_password_asesor.php — Recuperación de contraseña (app móvil)
// Responde JSON siempre, nunca lanza 500

// ── Headers CORS y JSON ────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

// ── Capturar cualquier error fatal y devolverlo como JSON ──
set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Error interno: ' . $e->getMessage()]);
    exit;
});
set_error_handler(function($errno, $errstr) {
    // Solo errores fatales
    if ($errno === E_ERROR || $errno === E_PARSE) {
        echo json_encode(['status' => 'error', 'message' => "PHP Error: $errstr"]);
        exit;
    }
    return false;
});

// ── Conexión DB ────────────────────────────────────────────
require_once __DIR__ . '/db_config.php';

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Crear tabla de OTPs si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_otp_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(255) NOT NULL,
        codigo     VARCHAR(10)  NOT NULL,
        expira_en  DATETIME     NOT NULL,
        usado      TINYINT(1)   NOT NULL DEFAULT 0,
        creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_usado (email, usado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Sin conexión a BD']);
    exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ══════════════════════════════════════════════════════════
// 1. ENVIAR OTP
// ══════════════════════════════════════════════════════════
if ($action === 'enviar_otp') {
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Correo inválido.']);
        exit;
    }

    try {
        // Buscar usuario activo con ese correo — cualquier rol (asesor, supervisor, gerente, superadmin)
        $st = $pdo->prepare("SELECT id, nombre, rol FROM usuario WHERE email = ? AND activo = 1 LIMIT 1");
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user) {
            $codigo    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expira_en = date('Y-m-d H:i:s', time() + 600);

            // Invalidar OTPs anteriores y guardar el nuevo
            $pdo->prepare("UPDATE email_otp_codes SET usado = 1 WHERE email = ? AND usado = 0")->execute([$email]);
            $pdo->prepare("INSERT INTO email_otp_codes (email, codigo, expira_en, usado, creado_en) VALUES (?, ?, ?, 0, NOW())")
                ->execute([$email, $codigo, $expira_en]);

            // Enviar email ANTES de responder para garantizar que se procese
            [$sent, $err] = _enviarEmailOtp($email, $codigo);

            if ($sent) {
                echo json_encode([
                    'status'  => 'success',
                    'message' => 'Código enviado a tu correo. Revisa tu bandeja de entrada.',
                    'email'   => $email,
                ]);
            } else {
                echo json_encode([
                    'status'  => 'success',
                    'message' => 'No se pudo enviar el email. Usa este código de emergencia.',
                    'email'   => $email,
                    'codigo_emergencia' => $codigo,
                    'smtp_error' => $err,
                ]);
            }
        } else {
            // Por seguridad respondemos igual aunque no exista el usuario
            echo json_encode([
                'status'  => 'success',
                'message' => 'Si el correo está registrado, recibirás el código.',
                'email'   => $email,
            ]);
        }

    } catch (\Throwable $e) {
        file_put_contents(__DIR__ . '/api_otp_error.log',
            date('Y-m-d H:i:s') . " enviar_otp ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'message' => 'Error interno al enviar el código.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════
// 2. VERIFICAR OTP
// ══════════════════════════════════════════════════════════
if ($action === 'verificar_otp') {
    $email  = trim($_POST['email']  ?? '');
    $codigo = trim($_POST['codigo'] ?? '');

    if (!$email || !$codigo) {
        echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
        exit;
    }

    try {
        $st = $pdo->prepare(
            "SELECT id FROM email_otp_codes
             WHERE email = ? AND codigo = ? AND usado = 0 AND expira_en > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$email, $codigo]);
        $row = $st->fetch();

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Código incorrecto o expirado.']);
            exit;
        }

        $pdo->prepare("UPDATE email_otp_codes SET usado = 1 WHERE id = ?")->execute([$row['id']]);
        echo json_encode(['status' => 'success', 'message' => 'Código verificado.']);

    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error al verificar.']);
    }
    exit;
}

// ══════════════════════════════════════════════════════════
// 3. NUEVA CONTRASEÑA
// ══════════════════════════════════════════════════════════
if ($action === 'nueva_password') {
    $email    = trim($_POST['email']         ?? '');
    $password = trim($_POST['nueva_password'] ?? '');

    if (!$email || strlen($password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'Datos inválidos.']);
        exit;
    }

    try {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $st = $pdo->prepare("UPDATE usuario SET password_hash = ? WHERE email = ? AND activo = 1");
        $st->execute([$hash, $email]);

        if ($st->rowCount() === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado.']);
            exit;
        }

        echo json_encode(['status' => 'success', 'message' => 'Contraseña actualizada correctamente.']);

    } catch (\Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar.']);
    }
    exit;
}

// Acción no reconocida
echo json_encode(['status' => 'error', 'message' => "Acción '$action' no válida."]);
exit;

// ══════════════════════════════════════════════════════════
// FUNCIÓN: Enviar email con OTP
// ══════════════════════════════════════════════════════════
function _enviarEmailOtp(string $toEmail, string $codigo): array
{
    $logFile = __DIR__ . '/email_send_mobile.log';

    try {
        $helperPath = __DIR__ . '/email_helper.php';
        if (!file_exists($helperPath)) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " email_helper.php no encontrado\n", FILE_APPEND);
            return [false, "email_helper.php no encontrado"];
        }

        require_once $helperPath;

        $html  = buildOtpEmailHtml($codigo);
        $plain = buildOtpEmailText($codigo);

        [$sent, $err] = sendEmailMessage($toEmail, 'Código de recuperación — Super_IA', $html, $plain);

        file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . ($sent ? " OK" : " FAIL: $err") . " | to=$toEmail | code=$codigo\n",
            FILE_APPEND | LOCK_EX
        );
        return [$sent, $err];
    } catch (\Throwable $e) {
        file_put_contents(
            $logFile,
            date('Y-m-d H:i:s') . " EXCEPTION: " . $e->getMessage() . " | to=$toEmail\n",
            FILE_APPEND | LOCK_EX
        );
        return [false, $e->getMessage()];
    }
}

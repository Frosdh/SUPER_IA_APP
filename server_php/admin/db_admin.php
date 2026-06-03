<?php
// ============================================================
// admin/db_admin.php — Conexión PDO para el Panel de Administración
// Las credenciales viven únicamente en db_config.php (un nivel arriba)
// ============================================================

// Iniciar sesión si no está activa (necesario para $_SESSION en todo el panel)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configPath = __DIR__ . '/../db_config.php';
if (!file_exists($configPath)) {
    die("Error: no se encontró db_config.php");
}

// Leer las variables del archivo central sin ejecutar la conexión MySQLi
$raw = file_get_contents($configPath);
preg_match('/\$db_host\s*=\s*["\']([^"\']+)["\']/',     $raw, $m); $db_host     = $m[1] ?? 'localhost';
preg_match('/\$db_name\s*=\s*["\']([^"\']+)["\']/',     $raw, $m); $db_name     = $m[1] ?? '';
preg_match('/\$db_user\s*=\s*["\']([^"\']+)["\']/',     $raw, $m); $db_user     = $m[1] ?? '';
preg_match('/\$db_password\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m); $db_password = $m[1] ?? '';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Crear tabla de OTPs si no existe (requerida para recuperación de contraseña)
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_otp_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        email      VARCHAR(255) NOT NULL,
        codigo     VARCHAR(10)  NOT NULL,
        expira_en  DATETIME     NOT NULL,
        usado      TINYINT(1)   NOT NULL DEFAULT 0,
        creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_usado (email, usado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>
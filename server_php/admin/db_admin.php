<?php
// ============================================================
// admin/db_admin.php — Conexión PDO para el Panel de Administración
// Las credenciales viven únicamente en db_config.php (un nivel arriba)
// ============================================================

// Iniciar sesión si no está activa (necesario para $_SESSION en todo el panel)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Zona horaria de Ecuador (el servidor suele correr en UTC por defecto,
// lo que adelantaba ~5 horas las fechas/horas mostradas en el panel)
date_default_timezone_set('America/Guayaquil');

$configPath = __DIR__ . '/../db_config.php';
if (!file_exists($configPath)) {
    die("Error: no se encontró db_config.php");
}

$db_host     = getenv('DB_HOST') ?: "localhost";
$db_name     = getenv('DB_NAME') ?: "corporat_base_super_ia";
$db_user     = getenv('DB_USER') ?: "corporat_coac_user";
$db_password = getenv('DB_PASS') ?: '*6LuhePgy=9?Zy-&';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Forzar la sesión de MySQL a hora de Ecuador (-05:00) para que
    // NOW()/CURDATE()/CURTIME()/CURRENT_TIMESTAMP() coincidan con la hora real.
    $pdo->exec("SET time_zone = '-05:00'");

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
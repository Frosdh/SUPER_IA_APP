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
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>
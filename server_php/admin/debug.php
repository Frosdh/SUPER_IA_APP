<?php
// ============================================================
// debug.php — Diagnóstico para Hostinger
// IMPORTANTE: Eliminar este archivo del servidor después de usarlo
// Acceder en: https://corporativoqbank.com/SUPER_IA/server_php/admin/debug.php
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<style>body{font-family:monospace;padding:20px;background:#111;color:#0f0;} .ok{color:#0f0;} .err{color:#f55;} .warn{color:#fa0;} h2{color:#fff;border-bottom:1px solid #333;padding-bottom:5px;} pre{background:#1a1a1a;padding:10px;border-radius:5px;}</style>";
echo "<h1 style='color:#0af;'>🔍 Debug COAC Finance — Hostinger</h1>";

// ── 1. PHP Version
echo "<h2>1. PHP</h2>";
echo "<span class='ok'>✔ Versión PHP: " . PHP_VERSION . "</span><br>";
echo "SO: " . PHP_OS . "<br>";

// ── 2. Extensiones necesarias
echo "<h2>2. Extensiones PHP</h2>";
$extensions = ['pdo', 'pdo_mysql', 'mysqli', 'session', 'json'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<span class='ok'>✔ $ext activo</span><br>";
    } else {
        echo "<span class='err'>✘ $ext NO está activo — PROBLEMA</span><br>";
    }
}

// ── 3. Sesiones
echo "<h2>3. Sesiones</h2>";
echo "session.save_path: <b>" . (ini_get('session.save_path') ?: '(vacío — usa default del sistema)') . "</b><br>";
echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . "s<br>";

if (session_status() === PHP_SESSION_NONE) session_start();
echo "Estado sesión: <span class='ok'>ACTIVA</span><br>";
echo "Session ID: " . session_id() . "<br>";
echo "Variables en sesión actual: <pre>" . print_r($_SESSION, true) . "</pre>";

// ── 4. Conexión a la base de datos
echo "<h2>4. Base de Datos (PDO)</h2>";
$configPath = __DIR__ . '/../db_config.php';
if (!file_exists($configPath)) {
    echo "<span class='err'>✘ db_config.php NO encontrado en: $configPath</span><br>";
} else {
    echo "<span class='ok'>✔ db_config.php encontrado</span><br>";
    $raw = file_get_contents($configPath);
    preg_match('/\$db_host\s*=\s*["\']([^"\']+)["\']/',     $raw, $m); $db_host     = $m[1] ?? 'localhost';
    preg_match('/\$db_name\s*=\s*["\']([^"\']+)["\']/',     $raw, $m); $db_name     = $m[1] ?? '';
    preg_match('/\$db_user\s*=\s*["\']([^"\']+)["\']/',     $raw, $m); $db_user     = $m[1] ?? '';
    preg_match('/\$db_password\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m); $db_password = $m[1] ?? '';

    echo "Host: <b>$db_host</b> | DB: <b>$db_name</b> | User: <b>$db_user</b><br>";

    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<span class='ok'>✔ Conexión PDO exitosa</span><br>";

        // Verificar tabla usuario
        $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tablas encontradas: <b>" . count($tablas) . "</b> — " . implode(', ', $tablas) . "<br>";

        if (in_array('usuario', $tablas)) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM usuario")->fetchColumn();
            echo "<span class='ok'>✔ Tabla 'usuario': $cnt registros</span><br>";

            $supervisores = $pdo->query("SELECT id, nombre, email, rol, activo, estado_aprobacion FROM usuario WHERE rol = 'supervisor' LIMIT 5")->fetchAll();
            echo "Supervisores en BD: <b>" . count($supervisores) . "</b><br>";
            if ($supervisores) {
                echo "<pre>" . print_r($supervisores, true) . "</pre>";
            } else {
                echo "<span class='warn'>⚠ No hay usuarios con rol='supervisor' — ese es el problema del login vacío</span><br>";
            }
        } else {
            echo "<span class='err'>✘ Tabla 'usuario' NO existe — la BD está vacía o mal importada</span><br>";
        }

        if (in_array('asesor', $tablas)) {
            echo "<span class='ok'>✔ Tabla 'asesor' existe</span><br>";
        } else {
            echo "<span class='warn'>⚠ Tabla 'asesor' NO existe — supervisor_index.php fallará en la query</span><br>";
        }

    } catch (PDOException $e) {
        echo "<span class='err'>✘ Error de conexión PDO: " . htmlspecialchars($e->getMessage()) . "</span><br>";
    }
}

// ── 5. Rutas y permisos
echo "<h2>5. Rutas</h2>";
echo "__DIR__: " . __DIR__ . "<br>";
echo "db_config.php path: " . realpath(__DIR__ . '/../db_config.php') . "<br>";

echo "<h2>6. output_buffering</h2>";
echo "ob_get_level(): " . ob_get_level() . "<br>";
echo "output_buffering: " . ini_get('output_buffering') . "<br>";

echo "<hr style='border-color:#333;margin:20px 0;'>";
echo "<span style='color:#666;'>⚠ ELIMINA debug.php del servidor después de revisar esto.</span>";
?>

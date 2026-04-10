<?php
// Test para verificar que usuarios.php funciona correctamente
// Simula sesión de super_admin
session_start();

// Verificar si es una solicitud de prueba
if ($_GET['test'] === '1') {
    $_SESSION['super_admin_logged_in'] = true;
    $_SESSION['super_admin_id'] = 1;
    $_SESSION['super_admin_nombre'] = 'SuperAdmin Test';
    $_SESSION['super_admin_rol'] = 'SuperAdministrador';
    header('Location: usuarios.php');
    exit;
} elseif ($_GET['test'] === '2') {
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = 2;
    $_SESSION['admin_nombre'] = 'Admin Test';
    $_SESSION['admin_rol'] = 'Administrador';
    header('Location: usuarios.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test - Usuarios Page</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f7fa; }
        .test-container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
        .test-card { margin-bottom: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #6b11ff; }
        .test-button { padding: 12px 24px; margin: 10px 5px 0 0; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .btn-super-admin { background: #6b11ff; color: white; }
        .btn-admin { background: #2563eb; color: white; }
        .btn-super-admin:hover { background: #5a0cde; }
        .btn-admin:hover { background: #1d4ed8; }
        h1 { color: #1f2937; margin-bottom: 30px; }
        h3 { color: #374151; margin-bottom: 15px; }
        p { color: #6b7280; line-height: 1.6; margin: 10px 0; }
        .feature-list { list-style: none; padding-left: 0; }
        .feature-list li { padding: 8px 0; color: #4b5563; }
        .feature-list li:before { content: "✓ "; color: #10b981; font-weight: bold; margin-right: 8px; }
    </style>
</head>
<body>
    <div class="test-container">
        <h1>🧪 Test - Página de Usuarios</h1>
        
        <div class="test-card">
            <h3>✨ Cambios Realizados:</h3>
            <ul class="feature-list">
                <li>Soporte para super_admin_logged_in</li>
                <li>Soporte para admin_logged_in</li>
                <li>Navbar dinámica según rol</li>
                <li>CSS responsivo con min-width: 0</li>
                <li>Media queries para pantallas pequeñas</li>
                <li>No más recorte de botones del sidebar</li>
                <li>Muestra jerarquía de usuarios</li>
                <li>Muestra todos los roles (SuperAdmin, Admin, Supervisor, Asesor)</li>
            </ul>
        </div>

        <div class="test-card">
            <h3>🚀 Pruebas Disponibles:</h3>
            <p style="margin-bottom: 20px;">Haz clic en uno de los botones para probar usuarios.php con diferentes roles:</p>
            
            <div>
                <button class="test-button btn-super-admin" onclick="testSuperAdmin()">
                    👑 Probar como SuperAdmin
                </button>
                <button class="test-button btn-admin" onclick="testAdmin()">
                    🎯 Probar como Admin
                </button>
            </div>
        </div>

        <div class="test-card">
            <h3>📋 Checklist de Verificación:</h3>
            <p>Cuando hagas clic, verifica:</p>
            <ul class="feature-list">
                <li>Navbar muestra el emoji correcto (👑 o 🎯)</li>
                <li>Navbar muestra tu nombre y rol</li>
                <li>Sidebar NO se recorta al cambiar de pestañas</li>
                <li>Se muestran todos los usuarios en la tabla</li>
                <li>Se muestra la jerarquía organizacional correctamente</li>
                <li>Puedes navegar a Clientes y Operaciones sin errores</li>
            </ul>
        </div>

        <div class="test-card" style="background: #fff3cd; border-left-color: #fbbf24;">
            <h3>ℹ️ Nota Importante:</h3>
            <p>Este test crea sesiones simuladas. Para pruebas reales, usa las credenciales correctas en login.php</p>
        </div>
    </div>

    <script>
        function testSuperAdmin() {
            window.location.href = '?test=1';
        }
        function testAdmin() {
            window.location.href = '?test=2';
        }
    </script>
</body>
</html>

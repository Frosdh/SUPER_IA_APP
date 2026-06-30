<?php
// ============================================================
// setup_administrador.php — Crea/actualiza el usuario administrador
// EJECUTAR UNA SOLA VEZ desde el navegador, luego ELIMINAR este archivo
// URL: http://localhost/SUPER_IA/server_php/admin/setup_administrador.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/db_admin.php';

$action  = $_POST['action']  ?? '';
$message = '';
$type    = '';

if ($action === 'crear') {
    $nombre   = trim($_POST['nombre']   ?? 'Administrador Sistema');
    $email    = trim($_POST['email']    ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm']       ?? '';

    if (empty($email) || empty($password)) {
        $message = 'Email y contraseña son obligatorios.';
        $type    = 'danger';
    } elseif ($password !== $confirm) {
        $message = 'Las contraseñas no coinciden.';
        $type    = 'danger';
    } elseif (strlen($password) < 8) {
        $message = 'La contraseña debe tener al menos 8 caracteres.';
        $type    = 'danger';
    } else {
        try {
            // Verificar si ya existe
            $check = $pdo->prepare("SELECT id FROM usuario WHERE email = ? LIMIT 1");
            $check->execute([$email]);
            $existing = $check->fetch();

            $hash = password_hash($password, PASSWORD_DEFAULT);

            if ($existing) {
                // Actualizar existente
                $upd = $pdo->prepare("
                    UPDATE usuario SET
                        nombre            = ?,
                        password_hash     = ?,
                        rol               = 'administrador',
                        activo            = 1,
                        estado_aprobacion = 'aprobado',
                        fecha_aprobacion  = NOW(),
                        telefono          = ?
                    WHERE email = ?
                ");
                $upd->execute([$nombre, $hash, $telefono, $email]);
                $message = "✅ Usuario administrador ACTUALIZADO correctamente.<br>Email: <strong>$email</strong> · Contraseña: la que ingresaste.";
            } else {
                // Insertar nuevo
                $ins = $pdo->prepare("
                    INSERT INTO usuario
                        (id, nombre, email, telefono, password_hash, rol, activo, estado_aprobacion, fecha_aprobacion, created_at)
                    VALUES
                        (UUID(), ?, ?, ?, ?, 'administrador', 1, 'aprobado', NOW(), NOW())
                ");
                $ins->execute([$nombre, $email, $telefono, $hash]);
                $message = "✅ Usuario administrador CREADO correctamente.<br>Email: <strong>$email</strong> · Contraseña: la que ingresaste.";
            }

            $message .= '<br><br>🔒 <strong>Elimina este archivo</strong> del servidor después de usarlo.';
            $type = 'success';
        } catch (PDOException $e) {
            $message = '❌ Error de base de datos: ' . htmlspecialchars($e->getMessage());
            $type    = 'danger';

            // Si falla por el enum, intentar modificarlo primero
            if (strpos($e->getMessage(), 'Data truncated') !== false || strpos($e->getMessage(), 'enum') !== false) {
                try {
                    $pdo->exec("ALTER TABLE usuario MODIFY COLUMN rol ENUM('gerente_general','jefe_regional','jefe_agencia','supervisor','asesor','administrador') NOT NULL");
                    $message .= '<br>⚠️ Se intentó modificar el enum — vuelve a intentar crear el usuario.';
                } catch (PDOException $e2) {
                    $message .= '<br>Error al modificar enum: ' . htmlspecialchars($e2->getMessage());
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup Administrador — SUPER_IA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  body { background: #0f172a; color: #f8fafc; font-family: 'Segoe UI', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card-setup { background: #1e293b; border: 1px solid #334155; border-radius: 16px; max-width: 480px; width: 100%; padding: 40px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
  .badge-warn { background: rgba(245,158,11,.15); color: #fbbf24; border: 1px solid rgba(245,158,11,.3); padding: 8px 14px; border-radius: 8px; font-size: 13px; }
  .form-control { background: #0f172a; border: 1px solid #334155; color: #f8fafc; border-radius: 8px; }
  .form-control:focus { background: #0f172a; border-color: #6366f1; color: #f8fafc; box-shadow: 0 0 0 3px rgba(99,102,241,.2); }
  .btn-create { background: linear-gradient(135deg, #6366f1, #8b5cf6); border: none; border-radius: 10px; padding: 12px; font-weight: 700; font-size: 16px; }
  .btn-create:hover { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: #fff; }
  label { color: #94a3b8; font-size: 14px; margin-bottom: 4px; }
  h4 { font-weight: 800; }
  .login-link { color: #6366f1; text-decoration: none; }
</style>
</head>
<body>
<div class="card-setup">
  <div class="text-center mb-4">
    <div style="font-size:40px;margin-bottom:8px">🛡️</div>
    <h4>Setup Administrador</h4>
    <p style="color:#64748b;font-size:14px">Crea el usuario con rol de administrador total</p>
  </div>

  <div class="badge-warn mb-4">
    <i class="fas fa-triangle-exclamation me-1"></i>
    Ejecutar <strong>solo una vez</strong>. Eliminar este archivo después.
  </div>

  <?php if ($message): ?>
  <div class="alert alert-<?= $type === 'success' ? 'success' : ($type === 'danger' ? 'danger' : 'warning') ?> mb-3" role="alert">
    <?= $message ?>
    <?php if ($type === 'success'): ?>
    <hr>
    <a href="login.php?role=administrador" class="login-link"><i class="fas fa-arrow-right me-1"></i>Ir al login</a>
    <?php endif ?>
  </div>
  <?php endif ?>

  <form method="POST">
    <input type="hidden" name="action" value="crear">
    <div class="mb-3">
      <label>Nombre completo</label>
      <input type="text" class="form-control" name="nombre" value="Administrador Sistema" required>
    </div>
    <div class="mb-3">
      <label>Email</label>
      <input type="email" class="form-control" name="email" placeholder="admin@superIA.local" required>
    </div>
    <div class="mb-3">
      <label>Teléfono (opcional)</label>
      <input type="text" class="form-control" name="telefono" placeholder="0999000000">
    </div>
    <div class="mb-3">
      <label>Contraseña (mín. 8 caracteres)</label>
      <input type="password" class="form-control" name="password" required minlength="8">
    </div>
    <div class="mb-4">
      <label>Confirmar contraseña</label>
      <input type="password" class="form-control" name="confirm" required>
    </div>
    <button type="submit" class="btn btn-create w-100 text-white">
      <i class="fas fa-user-shield me-2"></i>Crear / Actualizar Administrador
    </button>
  </form>

  <div class="text-center mt-3" style="font-size:13px;color:#475569">
    <a href="login.php?role=administrador" class="login-link">← Volver al login</a>
  </div>
</div>
</body>
</html>

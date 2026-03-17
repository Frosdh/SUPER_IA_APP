<?php
// ============================================================
// admin/registro_secretaria.php — Registro de nuevas secretarias
// ============================================================
require_once 'db_admin.php';

$msg = '';
$msgType = 'success';

// Obtener lista de cooperativas para el selector
$stmtCoops = $pdo->query("SELECT id, nombre FROM cooperativas ORDER BY nombre ASC");
$cooperativas = $stmtCoops->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $usuario = trim($_POST['usuario'] ?? '');
    $pass = $_POST['password'] ?? '';
    $coop_id = intval($_POST['cooperativa_id'] ?? 0);

    if (empty($nombre) || empty($usuario) || empty($pass) || $coop_id <= 0) {
        $msg = "Todos los campos son obligatorios.";
        $msgType = "danger";
    } else {
        // Verificar si el usuario ya existe
        $check = $pdo->prepare("SELECT id FROM secretarias WHERE usuario = ?");
        $check->execute([$usuario]);
        
        if ($check->fetch()) {
            $msg = "El nombre de usuario ya está en uso.";
            $msgType = "danger";
        } else {
            $passHash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO secretarias (usuario, pass_hash, cooperativa_id, nombre) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$usuario, $passHash, $coop_id, $nombre])) {
                $msg = "Registro exitoso. Ya puedes iniciar sesión.";
                // Redirigir después de 2 segundos
                header("refresh:2;url=login.php?role=secretary");
            } else {
                $msg = "Error al registrar. Inténtalo de nuevo.";
                $msgType = "danger";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Secretaria — GeoMove</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #6b11ff;
            --secondary-color: #110038;
            --accent-color: #00d4ff;
            --glass-bg: rgba(255, 255, 255, 0.9);
        }

        body {
            background: linear-gradient(135deg, var(--secondary-color) 0%, #250070 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            overflow-x: hidden;
        }

        .auth-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .brand-logo {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
            margin-bottom: 5px;
            display: block;
            text-align: center;
        }

        .auth-title {
            text-align: center;
            color: var(--secondary-color);
            font-weight: 700;
            margin-bottom: 25px;
            font-size: 1.25rem;
        }

        .form-label {
            font-weight: 600;
            color: #444;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 2px solid #eee;
            transition: all 0.3s;
            font-size: 1rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(107, 17, 255, 0.1);
        }

        .btn-register {
            background: linear-gradient(to right, var(--primary-color), #8a45ff);
            border: none;
            border-radius: 12px;
            padding: 14px;
            color: white;
            font-weight: 700;
            width: 100%;
            margin-top: 15px;
            transition: all 0.3s;
            box-shadow: 0 8px 16px rgba(107, 17, 255, 0.3);
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 20px rgba(107, 17, 255, 0.4);
        }

        .login-link {
            text-align: center;
            margin-top: 25px;
            display: block;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .login-link strong {
            color: var(--primary-color);
        }

        .icon-box {
            width: 50px;
            height: 50px;
            background: rgba(107, 17, 255, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--primary-color);
            font-size: 1.5rem;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="icon-box">
        <i class="fas fa-briefcase"></i>
    </div>
    <span class="brand-logo">GeoMove</span>
    <h2 class="auth-title">Registro de Secretaria</h2>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> border-0 rounded-3 small">
            <i class="fas fa-<?= $msgType==='success'?'check-circle':'exclamation-circle' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Nombre Completo</label>
            <input type="text" name="nombre" class="form-control" placeholder="Ej: Maria Lopez" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Usuario</label>
            <input type="text" name="usuario" class="form-control" placeholder="usuario123" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Contraseña</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>

        <div class="mb-4">
            <label class="form-label">Selecciona tu Cooperativa</label>
            <select name="cooperativa_id" class="form-select" required>
                <option value="" selected disabled>Elegir cooperativa...</option>
                <?php foreach ($cooperativas as $coop): ?>
                    <option value="<?= $coop['id'] ?>"><?= htmlspecialchars($coop['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-register">
            Crear Cuenta de Secretaria
        </button>
    </form>

    <a href="login.php?role=secretary" class="login-link">
        ¿Ya tienes cuenta? <strong>Inicia sesión aquí</strong>
    </a>
</div>

</body>
</html>

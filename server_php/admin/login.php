<?php
require_once 'db_admin.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';

    // Credenciales hardcodeadas por simplicidad temporal como indicó el plan
    if ($user === 'admin' && $pass === 'Admin123!') {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GeoMove - Admin Login</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            color: #fff;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .login-box {
            background: rgba(255, 255, 255, 0.05);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.5);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
            width: 350px;
            text-align: center;
        }
        h2 { margin-bottom: 25px; font-weight: 600; }
        input {
            width: 100%;
            padding: 12px 15px;
            margin: 10px 0 20px 0;
            border: none;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: #fff;
            box-sizing: border-box;
            outline: none;
        }
        input::placeholder { color: #ccc; }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, #6b11ff, #3182fe);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button:hover { transform: translateY(-2px); }
        .error { color: #ff4d4d; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Panel de Administración</h2>
        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Usuario" required autocomplete="off">
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>

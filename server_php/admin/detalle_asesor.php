<?php
// detalle_asesor.php — Ver y editar datos de un asesor (dentro del layout del supervisor)
require_once 'db_admin.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (empty($_SESSION['supervisor_logged_in'])) {
    header('Location: login.php?role=supervisor'); exit;
}

$supervisor_usuario_id = $_SESSION['supervisor_id']    ?? null;
$supervisor_nombre     = $_SESSION['supervisor_nombre'] ?? 'Supervisor';
$supervisor_rol        = $_SESSION['supervisor_rol']    ?? 'Supervisor';
$asesor_usuario_id     = trim($_GET['id'] ?? '');

if (!$asesor_usuario_id) { header('Location: mis_asesores.php'); exit; }

$mensaje_exito = '';
$mensaje_error = '';

// ── Verificar que el asesor pertenece a este supervisor ────
try {
    $st = $pdo->prepare("
        SELECT a.id AS asesor_id
        FROM asesor a
        JOIN supervisor s ON s.id = a.supervisor_id
        WHERE a.usuario_id = ? AND s.usuario_id = ?
        LIMIT 1
    ");
    $st->execute([$asesor_usuario_id, $supervisor_usuario_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { header('Location: mis_asesores.php'); exit; }
    $asesor_id = $row['asesor_id'];
} catch (\Throwable $e) { header('Location: mis_asesores.php'); exit; }

// ── Cargar datos ───────────────────────────────────────────
$stUsr = $pdo->prepare("SELECT * FROM usuario WHERE id = ? LIMIT 1");
$stUsr->execute([$asesor_usuario_id]);
$usr = $stUsr->fetch(PDO::FETCH_ASSOC);

$stAsr = $pdo->prepare("SELECT * FROM asesor WHERE id = ? LIMIT 1");
$stAsr->execute([$asesor_id]);
$asr = $stAsr->fetch(PDO::FETCH_ASSOC);

// Cédula y usuario desde solicitudes_asesor
$cedula  = '';
$usuario = '';
try {
    $sc = $pdo->prepare("SELECT cedula, usuario FROM solicitudes_asesor WHERE email = ? ORDER BY id_solicitud DESC LIMIT 1");
    $sc->execute([$usr['email'] ?? '']);
    $scRow   = $sc->fetch(PDO::FETCH_ASSOC) ?: [];
    $cedula  = $scRow['cedula']  ?? '';
    $usuario = $scRow['usuario'] ?? '';
} catch (\Throwable $e) {}

// ── Procesar edición ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre       = trim($_POST['nombre']   ?? '');
    $nuevo_usuario = trim($_POST['usuario'] ?? '');
    $email        = trim($_POST['email']    ?? '');
    $telefono     = trim($_POST['telefono'] ?? '');
    $password     = $_POST['password']      ?? '';

    $errores = [];
    if (!$nombre)         $errores[] = 'El nombre es obligatorio.';
    if (!$nuevo_usuario || strlen($nuevo_usuario) < 4)
                          $errores[] = 'El usuario debe tener al menos 4 caracteres.';
    if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $nuevo_usuario))
                          $errores[] = 'El usuario solo puede contener letras, números, puntos y guiones bajos.';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
                          $errores[] = 'Email inválido.';
    if ($password && strlen($password) < 6)
                          $errores[] = 'La contraseña debe tener al menos 6 caracteres.';

    if (!count($errores)) {
        // Email único en usuario
        $chk = $pdo->prepare("SELECT id FROM usuario WHERE email = ? AND id != ? LIMIT 1");
        $chk->execute([$email, $asesor_usuario_id]);
        if ($chk->fetch()) $errores[] = 'Ese correo ya lo usa otro usuario.';
    }
    if (!count($errores)) {
        // Usuario único en solicitudes_asesor (excluir el propio)
        $chkU = $pdo->prepare("SELECT id_solicitud FROM solicitudes_asesor WHERE usuario = ? AND email != ? AND estado != 'rechazada' LIMIT 1");
        $chkU->execute([$nuevo_usuario, $usr['email'] ?? '']);
        if ($chkU->fetch()) $errores[] = 'Ese nombre de usuario ya está en uso.';
    }

    if (!count($errores)) {
        $pdo->beginTransaction();
        try {
            // Actualizar tabla usuario
            if ($password) {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE usuario SET nombre=?, email=?, telefono=?, password_hash=? WHERE id=?")
                    ->execute([$nombre, $email, $telefono, $hash, $asesor_usuario_id]);
            } else {
                $pdo->prepare("UPDATE usuario SET nombre=?, email=?, telefono=? WHERE id=?")
                    ->execute([$nombre, $email, $telefono, $asesor_usuario_id]);
            }
            // Actualizar usuario en solicitudes_asesor
            $pdo->prepare("UPDATE solicitudes_asesor SET usuario=?, nombres=?, email=?, telefono=? WHERE email=?")
                ->execute([$nuevo_usuario, $nombre, $email, $telefono, $usr['email'] ?? '']);

            $pdo->commit();
            // Recargar
            $stUsr->execute([$asesor_usuario_id]);
            $usr = $stUsr->fetch(PDO::FETCH_ASSOC);
            // Recargar usuario
            $sc2 = $pdo->prepare("SELECT cedula, usuario FROM solicitudes_asesor WHERE email = ? ORDER BY id_solicitud DESC LIMIT 1");
            $sc2->execute([$email]);
            $scRow2  = $sc2->fetch(PDO::FETCH_ASSOC) ?: [];
            $cedula  = $scRow2['cedula']  ?? $cedula;
            $usuario = $scRow2['usuario'] ?? $nuevo_usuario;
            $mensaje_exito = 'Datos actualizados correctamente.';
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            $mensaje_error = 'Error al guardar: ' . $ex->getMessage();
        }
    } else {
        $mensaje_error = implode(' ', $errores);
    }
}

$nombre_asesor = htmlspecialchars($usr['nombre'] ?? '');
$inicial = strtoupper(mb_substr($usr['nombre'] ?? 'A', 0, 1));
$currentPage = 'asesores';
$alertas_pendientes = 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Detalle Asesor — <?= $nombre_asesor ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="supervisor_layout.css?v=<?= time() ?>">
<style>
/* ── Page header (mismo estilo que mis_asesores) ── */
.ma-page-header{display:flex;align-items:center;gap:14px;margin-bottom:28px;padding-bottom:18px;border-bottom:2px solid #e8eef6;}
.ma-page-icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#0a2748,#1e4d8c);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(10,39,72,.22);flex-shrink:0;}
.ma-page-icon i{color:#ffdd00;font-size:22px;}
.ma-page-title{font-size:22px;font-weight:900;color:#0a2748;margin:0;}
.ma-page-sub{font-size:13px;color:#94a3b8;margin:2px 0 0;font-weight:500;}

.back-btn{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:7px 16px;font-size:13px;font-weight:600;color:#0a2748;text-decoration:none;transition:box-shadow .2s;}
.back-btn:hover{box-shadow:0 4px 12px rgba(10,39,72,.12);color:#0a2748;}

/* ── Perfil ── */
.profile-banner{background:linear-gradient(135deg,#0a2748 0%,#1e4d8c 100%);border-radius:18px;padding:28px;display:flex;align-items:center;gap:20px;margin-bottom:22px;box-shadow:0 4px 20px rgba(10,39,72,.15);}
.profile-avatar{width:68px;height:68px;border-radius:50%;background:#ffdd00;color:#0a2748;font-size:26px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:3px solid rgba(255,255,255,.2);}
.profile-banner h2{margin:0;color:#fff;font-size:20px;font-weight:800;}
.profile-banner p{margin:3px 0 0;color:#94a3b8;font-size:13px;}
.pill-activo{background:#dcfce7;color:#166534;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;margin-top:8px;}
.pill-inactivo{background:#fee2e2;color:#991b1b;padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;display:inline-block;margin-top:8px;}

/* ── Form card ── */
.form-card{background:#fff;border-radius:18px;box-shadow:0 4px 20px rgba(10,39,72,.07);padding:28px;}
.sec-label{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin:22px 0 12px;border-bottom:1px solid #f1f5f9;padding-bottom:7px;}
.sec-label:first-child{margin-top:0;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:580px){.form-row{grid-template-columns:1fr;}}
.field{display:flex;flex-direction:column;gap:4px;}
.field label{font-size:13px;font-weight:600;color:#374151;}
.field input{border:1.5px solid #e2e8f0;border-radius:9px;padding:10px 14px;font-size:14px;outline:none;transition:border-color .2s;width:100%;}
.field input:focus{border-color:#0a2748;}
.field input[readonly]{background:#f8fafc;color:#94a3b8;cursor:not-allowed;}
.field-hint{font-size:11px;color:#94a3b8;}
.btn-save{background:#0a2748;color:#fff;border:none;border-radius:10px;padding:11px 30px;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s;}
.btn-save:hover{background:#1e4d8c;}
.alert-ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:12px 16px;margin-bottom:18px;}
.alert-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px 16px;margin-bottom:18px;}
</style>
</head>
<body>

<?php require_once '_sidebar_supervisor.php'; ?>

<!-- CONTENIDO PRINCIPAL -->
<div class="main-content" style="padding:28px 32px;">

    <!-- Header de página -->
    <div class="ma-page-header">
        <div class="ma-page-icon"><i class="fas fa-user-edit"></i></div>
        <div style="flex:1;">
            <h1 class="ma-page-title">Perfil del Asesor</h1>
            <p class="ma-page-sub">Edita los datos del asesor de tu equipo</p>
        </div>
        <a href="mis_asesores.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Volver a Mis Asesores
        </a>
    </div>

    <!-- Banner de perfil -->
    <div class="profile-banner">
        <div class="profile-avatar"><?= $inicial ?></div>
        <div>
            <h2><?= $nombre_asesor ?></h2>
            <p><?= htmlspecialchars($usr['email'] ?? '') ?></p>
            <?php if ($usr['activo']): ?>
                <span class="pill-activo"><i class="fas fa-circle" style="font-size:7px;"></i> Activo</span>
            <?php else: ?>
                <span class="pill-inactivo"><i class="fas fa-circle" style="font-size:7px;"></i> Inactivo</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formulario -->
    <div class="form-card">

        <?php if ($mensaje_exito): ?>
        <div class="alert-ok"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($mensaje_exito) ?></div>
        <?php endif; ?>
        <?php if ($mensaje_error): ?>
        <div class="alert-err"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($mensaje_error) ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="sec-label"><i class="fas fa-user me-1"></i> Datos Personales</div>
            <div class="form-row">
                <div class="field">
                    <label>Nombre completo <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="nombre" value="<?= $nombre_asesor ?>" required>
                </div>
                <div class="field">
                    <label>Cédula de Identidad</label>
                    <input type="text" value="<?= htmlspecialchars($cedula ?: 'No registrada') ?>" readonly>
                    <span class="field-hint"><i class="fas fa-lock" style="font-size:10px;"></i> No editable</span>
                </div>
            </div>
            <div class="form-row" style="margin-top:14px;">
                <div class="field">
                    <label>Usuario <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="usuario" name="usuario"
                           value="<?= htmlspecialchars($usuario) ?>"
                           minlength="4" required
                           placeholder="Ej: jgarcia">
                    <span id="usuario-feedback" class="field-hint"></span>
                </div>
                <div class="field">
                    <label>Teléfono</label>
                    <input type="tel" name="telefono" value="<?= htmlspecialchars($usr['telefono'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row" style="margin-top:14px;">
                <div class="field">
                    <label>Correo electrónico <span style="color:#ef4444;">*</span></label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($usr['email'] ?? '') ?>" required>
                    <span id="email-feedback" class="field-hint"></span>
                </div>
            </div>

            <div class="sec-label" style="margin-top:22px;"><i class="fas fa-lock me-1"></i> Cambiar Contraseña <span style="font-weight:400;color:#94a3b8;font-size:10px;">(dejar vacío para no cambiar)</span></div>
            <div class="form-row">
                <div class="field">
                    <label>Nueva contraseña</label>
                    <div style="position:relative;">
                        <input type="password" id="pass1" name="password" placeholder="Mín. 6 caracteres" style="padding-right:40px;">
                        <button type="button" onclick="toggleVis('pass1','eye1')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;"><i class="fas fa-eye" id="eye1"></i></button>
                    </div>
                </div>
                <div class="field">
                    <label>Confirmar contraseña</label>
                    <div style="position:relative;">
                        <input type="password" id="pass2" placeholder="Repite la contraseña" style="padding-right:40px;">
                        <button type="button" onclick="toggleVis('pass2','eye2')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;"><i class="fas fa-eye" id="eye2"></i></button>
                    </div>
                    <span id="pass-feedback" class="field-hint"></span>
                </div>
            </div>

            <div style="margin-top:26px;display:flex;justify-content:flex-end;">
                <button type="submit" class="btn-save"><i class="fas fa-save me-2"></i>Guardar cambios</button>
            </div>
        </form>
    </div>

</div><!-- /.main-content -->

<script>
function toggleVis(id, iconId) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    document.getElementById(iconId).classList.toggle('fa-eye');
    document.getElementById(iconId).classList.toggle('fa-eye-slash');
}

document.getElementById('pass2').addEventListener('input', function() {
    const fb = document.getElementById('pass-feedback');
    if (!this.value) { fb.textContent = ''; return; }
    if (document.getElementById('pass1').value !== this.value) {
        fb.innerHTML = '<span style="color:#ef4444;">✗ No coinciden</span>';
    } else {
        fb.innerHTML = '<span style="color:#10b981;">✓ Coinciden</span>';
    }
});

// ── Validación email ──────────────────────────────────────
let emailTimer;
const emailOriginal   = <?= json_encode($usr['email'] ?? '') ?>;
const usuarioOriginal = <?= json_encode($usuario) ?>;

document.getElementById('email').addEventListener('input', function() {
    clearTimeout(emailTimer);
    const fb  = document.getElementById('email-feedback');
    fb.textContent = '';
    const val = this.value.trim();
    if (!val || val === emailOriginal) return;
    emailTimer = setTimeout(async () => {
        try {
            const res  = await fetch('api_verificar_campo.php?campo=email&valor=' + encodeURIComponent(val));
            const data = await res.json();
            if (!data.disponible) {
                fb.innerHTML = '<span style="color:#ef4444;">✗ Ese correo ya está en uso</span>';
            } else {
                fb.innerHTML = '<span style="color:#10b981;">✓ Disponible</span>';
            }
        } catch(e) {}
    }, 600);
});

// ── Validación usuario ────────────────────────────────────
let usuarioTimer;
document.getElementById('usuario').addEventListener('input', function() {
    clearTimeout(usuarioTimer);
    const fb  = document.getElementById('usuario-feedback');
    fb.textContent = '';
    const val = this.value.trim();
    if (!val || val === usuarioOriginal) return;
    if (val.length < 4) {
        fb.innerHTML = '<span style="color:#ef4444;">✗ Mínimo 4 caracteres</span>'; return;
    }
    if (!/^[a-zA-Z0-9_\.]+$/.test(val)) {
        fb.innerHTML = '<span style="color:#ef4444;">✗ Solo letras, números, puntos y _</span>'; return;
    }
    usuarioTimer = setTimeout(async () => {
        try {
            const res  = await fetch('api_verificar_campo.php?campo=usuario&valor=' + encodeURIComponent(val));
            const data = await res.json();
            if (!data.disponible) {
                fb.innerHTML = '<span style="color:#ef4444;">✗ Ese usuario ya está en uso</span>';
            } else {
                fb.innerHTML = '<span style="color:#10b981;">✓ Disponible</span>';
            }
        } catch(e) {}
    }, 600);
});

document.querySelector('form').addEventListener('submit', function(e) {
    const p1 = document.getElementById('pass1').value;
    const p2 = document.getElementById('pass2').value;
    if (p1 && p1 !== p2) {
        e.preventDefault();
        document.getElementById('pass-feedback').innerHTML = '<span style="color:#ef4444;">✗ Las contraseñas no coinciden</span>';
        return;
    }
    // Bloquear si hay errores visibles en usuario o email
    const ufb = document.getElementById('usuario-feedback').innerHTML;
    const efb = document.getElementById('email-feedback').innerHTML;
    if (ufb.includes('✗') || efb.includes('✗')) {
        e.preventDefault();
        alert('Corrige los campos marcados antes de guardar.');
    }
});
</script>
</body>
</html>

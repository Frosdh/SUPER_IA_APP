<?php
// test_smtp.php — Diagnóstico de SMTP (solo para administrador, borrar después)
require_once 'db_admin.php';
if (empty($_SESSION['super_admin_logged_in']) && empty($_SESSION['admin_logged_in'])) {
    die('Acceso denegado');
}

require_once __DIR__ . '/../email_helper.php';

$resultado = '';
$enviado   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destino = trim($_POST['destino'] ?? '');
    if ($destino && filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        [$ok, $err] = sendEmailMessage(
            $destino,
            'Prueba SMTP — Super_IA',
            '<h2>✅ SMTP funciona correctamente</h2><p>Este es un correo de prueba desde Super_IA.</p>',
            'SMTP funciona correctamente. Este es un correo de prueba desde Super_IA.'
        );
        $enviado = $ok;
        $resultado = $ok ? '✅ Enviado exitosamente a ' . htmlspecialchars($destino) : '❌ Error: ' . htmlspecialchars($err);
    }
}

// Leer log
$log = file_get_contents(__DIR__ . '/../email_send.log') ?: 'Sin log aún.';
$cfg = require __DIR__ . '/../email_config.php';
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Test SMTP</title>
<style>body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:30px;}
.box{background:#1e293b;border-radius:12px;padding:20px;margin-bottom:20px;}
input{background:#334155;border:1px solid #475569;color:#fff;padding:8px 12px;border-radius:8px;font-size:14px;width:300px;}
button{background:#3b82f6;color:#fff;border:none;border-radius:8px;padding:9px 20px;cursor:pointer;font-size:14px;margin-left:8px;}
.ok{color:#4ade80;}.err{color:#f87171;}.label{color:#94a3b8;font-size:12px;}pre{white-space:pre-wrap;font-size:12px;color:#cbd5e1;max-height:300px;overflow-y:auto;}
</style></head><body>
<h2>🔧 Diagnóstico SMTP — Super_IA</h2>

<div class="box">
<div class="label">Configuración actual</div>
<pre>HOST:     <?= htmlspecialchars($cfg['host']) ?>:<?= $cfg['port'] ?> (<?= $cfg['secure'] ?>)
USERNAME: <?= htmlspecialchars($cfg['username']) ?>
FROM:     <?= htmlspecialchars($cfg['from_email']) ?>
FALLBACK: <?= htmlspecialchars($cfg['fallback_host'] ?? 'no configurado') ?>:<?= $cfg['fallback_port'] ?? '-' ?>
FALLBACK_PASS: <?= !empty($cfg['fallback_password']) ? '✅ configurada' : '❌ vacía' ?></pre>
</div>

<div class="box">
<form method="POST">
    <div class="label" style="margin-bottom:8px;">Enviar correo de prueba a:</div>
    <input type="email" name="destino" placeholder="correo@ejemplo.com" value="<?= htmlspecialchars($_POST['destino'] ?? '') ?>">
    <button type="submit">Enviar prueba</button>
</form>
<?php if ($resultado): ?>
<div style="margin-top:16px;font-size:16px;font-weight:700;" class="<?= $enviado ? 'ok' : 'err' ?>">
    <?= $resultado ?>
</div>
<?php endif; ?>
</div>

<div class="box">
<div class="label">Log de envíos (email_send.log)</div>
<pre><?= htmlspecialchars($log) ?></pre>
</div>
</body></html>

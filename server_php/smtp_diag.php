<?php
// ============================================================
// smtp_diag.php — Diagnóstico SMTP con output detallado
// Acceder desde: http://localhost/SUPER_IA/server_php/smtp_diag.php
// BORRAR este archivo después de solucionar el problema.
// ============================================================
header('Content-Type: text/html; charset=utf-8');

$serverPhpDir = __DIR__;

require_once $serverPhpDir . '/PHPMailer/src/Exception.php';
require_once $serverPhpDir . '/PHPMailer/src/PHPMailer.php';
require_once $serverPhpDir . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

// Configuraciones a probar
$tests = [
    [
        'label'  => '① Gmail — Puerto 587 / TLS (STARTTLS)',
        'host'   => 'smtp.gmail.com',
        'port'   => 587,
        'secure' => PHPMailer::ENCRYPTION_STARTTLS,
    ],
    [
        'label'  => '② Gmail — Puerto 465 / SSL',
        'host'   => 'smtp.gmail.com',
        'port'   => 465,
        'secure' => PHPMailer::ENCRYPTION_SMTPS,
    ],
];

$username   = 'edwinchoez83@gmail.com';
$password   = 'abzudapfzcsjkeoz';
$toEmail    = $_GET['to'] ?? 'edwinchoez25@gmail.com';

echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>SMTP Diagnóstico — Super_IA</title>
<style>
 *{box-sizing:border-box;margin:0;padding:0;}
 body{font-family:"Courier New",monospace;background:#0d1117;color:#c9d1d9;padding:24px;font-size:13px;}
 h1{font-family:"Segoe UI",sans-serif;color:#58a6ff;margin-bottom:20px;font-size:20px;}
 .block{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:20px;margin-bottom:18px;}
 .block h2{font-family:"Segoe UI",sans-serif;font-size:15px;margin-bottom:14px;color:#e6edf3;}
 .ok {color:#3fb950;} .err{color:#f85149;} .warn{color:#d29922;} .info{color:#58a6ff;}
 pre{white-space:pre-wrap;word-break:break-all;line-height:1.6;}
 .badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;}
 .badge-ok{background:rgba(63,185,80,.15);color:#3fb950;border:1px solid rgba(63,185,80,.3);}
 .badge-err{background:rgba(248,81,73,.15);color:#f85149;border:1px solid rgba(248,81,73,.3);}
</style></head><body>';

echo '<h1>🔬 Diagnóstico SMTP — Super_IA</h1>';

// ── 0. Entorno
echo '<div class="block"><h2>📋 Entorno</h2><pre>';
echo '<span class="info">PHP versión:</span>  ' . PHP_VERSION . "\n";
echo '<span class="info">OpenSSL:</span>      ' . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'No disponible') . "\n";
echo '<span class="info">Usuario:</span>      ' . $username . "\n";
echo '<span class="info">Correo prueba:</span> ' . htmlspecialchars($toEmail) . "\n";
echo '</pre></div>';

// ── 1. DNS lookup
echo '<div class="block"><h2>🌐 Resolución DNS de smtp.gmail.com</h2><pre>';
$dnsRecords = @dns_get_record('smtp.gmail.com', DNS_A);
if (!empty($dnsRecords)) {
    foreach ($dnsRecords as $r) {
        echo '<span class="ok">✓ smtp.gmail.com → ' . ($r['ip'] ?? 'N/A') . "</span>\n";
    }
} else {
    echo '<span class="err">✗ No se pudo resolver smtp.gmail.com (problema DNS)</span>' . "\n";
}
echo '</pre></div>';

// ── 2. Prueba de socket TCP en cada puerto
echo '<div class="block"><h2>🔌 Conectividad TCP (sin TLS/SSL)</h2><pre>';
foreach ([587, 465] as $p) {
    $fp = @fsockopen('smtp.gmail.com', $p, $errno, $errstr, 8);
    if ($fp) {
        $banner = @fgets($fp, 512);
        fclose($fp);
        echo '<span class="ok">✓ Puerto ' . $p . ' accesible — Banner: ' . trim(htmlspecialchars($banner)) . "</span>\n";
    } else {
        echo '<span class="err">✗ Puerto ' . $p . ' bloqueado — Error ' . $errno . ': ' . htmlspecialchars($errstr) . "</span>\n";
    }
}
echo '</pre></div>';

// ── 3. Intentos de envío con PHPMailer
foreach ($tests as $t) {
    echo '<div class="block"><h2>' . htmlspecialchars($t['label']) . '</h2><pre>';

    // Capturar debug output
    ob_start();

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $t['host'];
    $mail->Port       = $t['port'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $username;
    $mail->Password   = $password;
    $mail->SMTPSecure = $t['secure'];
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15;
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER;   // Muestra diálogo completo SMTP
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom($username, 'Super_IA Diag');
    $mail->addAddress($toEmail);
    $mail->Subject = 'SMTP Diagnóstico Super_IA — ' . $t['label'];
    $mail->Body    = 'Este correo confirma que SMTP funciona correctamente.';

    $ok = false;
    $errInfo = '';
    try {
        $mail->send();
        $ok = true;
    } catch (\Exception $e) {
        $errInfo = $mail->ErrorInfo;
    }

    $debugOut = ob_get_clean();

    // Filtrar y colorear debug
    $lines = explode("\n", $debugOut);
    foreach ($lines as $line) {
        $line = htmlspecialchars(trim($line));
        if ($line === '') continue;
        if (strpos($line, 'SERVER ->') !== false)     echo '<span class="info">' . $line . "</span>\n";
        elseif (strpos($line, 'CLIENT ->') !== false) echo '<span class="warn">' . $line . "</span>\n";
        elseif (strpos($line, 'SMTP ERROR') !== false || strpos($line, 'AUTH') !== false) echo '<span class="err">' . $line . "</span>\n";
        else                                          echo $line . "\n";
    }

    if ($ok) {
        echo "\n<span class=\"badge badge-ok\">✅ ENVIADO con éxito</span>\n";
    } else {
        echo "\n<span class=\"badge badge-err\">❌ FALLÓ: " . htmlspecialchars($errInfo) . "</span>\n";
    }

    echo '</pre></div>';
}

echo '<div class="block" style="border-color:#f85149;"><pre><span class="err">⚠ BORRA este archivo (smtp_diag.php) después de solucionar el problema — expone credenciales SMTP.</span></pre></div>';
echo '</body></html>';

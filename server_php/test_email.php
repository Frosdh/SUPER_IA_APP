<?php
// test_email.php
require_once __DIR__ . '/email_helper.php';

$email = 'edwinchoez83@gmail.com'; // let's test sending to ourselves
$codigo = '123456';
$htmlBody  = buildOtpEmailHtml($codigo);
$plainBody = buildOtpEmailText($codigo);

echo "Cargando config...\n";
list($config, $err) = loadEmailConfig();
if ($err) {
    echo "Error de config: $err\n";
    exit;
}
print_r($config);

echo "Intentando enviar correo a $email...\n";

// We will recreate the mailer but enable SMTP Debug
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $mail = createMailer($config);
    $mail->SMTPDebug = 3; // Enable verbose debug output
    $mail->Debugoutput = 'echo';
    
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = 'Test OTP';
    $mail->Body = $htmlBody;
    $mail->AltBody = $plainBody;
    
    $sent = $mail->send();
    echo "\nResultado: " . ($sent ? "Enviado con éxito" : "Fallo") . "\n";
} catch (Exception $e) {
    echo "\nExcepción atrapada: " . $e->getMessage() . "\n";
    echo "PHPMailer ErrorInfo: " . $mail->ErrorInfo . "\n";
}

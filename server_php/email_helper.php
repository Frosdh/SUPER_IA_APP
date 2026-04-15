<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';

function loadEmailConfig()
{
    $configPath = __DIR__ . '/email_config.php';
    if (!file_exists($configPath)) {
        return [null, "Falta configurar email_config.php con las credenciales SMTP"];
    }

    $emailConfig = require $configPath;
    if (
        empty($emailConfig['host']) ||
        empty($emailConfig['port']) ||
        empty($emailConfig['secure']) ||
        empty($emailConfig['username']) ||
        empty($emailConfig['password']) ||
        empty($emailConfig['from_email']) ||
        empty($emailConfig['from_name']) ||
        $emailConfig['password'] === 'CAMBIA_ESTA_PASSWORD'
    ) {
        return [null, "Configura correctamente las credenciales SMTP en email_config.php"];
    }

    return [$emailConfig, null];
}

function createMailer(array $emailConfig)
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $emailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $emailConfig['username'];
    $mail->Password = $emailConfig['password'];
    $mail->SMTPSecure = $emailConfig['secure'];
    $mail->Port = (int)$emailConfig['port'];
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($emailConfig['from_email'], $emailConfig['from_name']);

    return $mail;
}

function sendEmailMessage($toEmail, $subject, $htmlBody, $plainBody)
{
    list($emailConfig, $configError) = loadEmailConfig();
    if ($configError !== null) {
        return [false, $configError];
    }

    try {
        $mail = createMailer($emailConfig);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;
        $mail->send();

        return [true, null];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo];
    }
}

function buildOtpEmailHtml($codigo)
{
    $safeCode = htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8');

    return '
        <div style="margin:0;padding:32px 16px;background:#0b1020;font-family:Arial,sans-serif;color:#f4f7fb;">
            <div style="max-width:560px;margin:0 auto;background:linear-gradient(180deg,#131a33 0%,#0f1530 100%);border:1px solid rgba(138,164,255,.18);border-radius:28px;overflow:hidden;box-shadow:0 24px 80px rgba(3,8,25,.45);">
                <div style="padding:32px 32px 20px;background:radial-gradient(circle at top right,rgba(112,157,255,.35),transparent 32%),linear-gradient(135deg,#161f45 0%,#0f1530 70%);">
                    <div style="display:inline-block;padding:10px 16px;border-radius:999px;background:rgba(125,151,255,.16);color:#bcd0ff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Super_IA</div>
                    <h1 style="margin:20px 0 8px;font-size:30px;line-height:1.15;color:#ffffff;">Tu código de verificación ya está listo</h1>
                    <p style="margin:0;color:#a9b6da;font-size:15px;line-height:1.6;">Usa este código para continuar con tu acceso a Super_IA. Expira en 10 minutos.</p>
                </div>
                <div style="padding:12px 32px 32px;">
                    <div style="margin:20px 0 24px;padding:24px;border-radius:24px;background:linear-gradient(135deg,#7d97ff 0%,#64b2ff 100%);text-align:center;box-shadow:0 16px 40px rgba(89,132,255,.28);">
                        <div style="font-size:13px;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.75);font-weight:700;">Código OTP</div>
                        <div style="margin-top:14px;font-size:38px;letter-spacing:10px;font-weight:800;color:#ffffff;">' . $safeCode . '</div>
                    </div>
                    <div style="padding:18px 20px;border-radius:20px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);">
                        <p style="margin:0 0 10px;color:#ffffff;font-size:15px;font-weight:700;">¿No lo solicitaste?</p>
                        <p style="margin:0;color:#9faecc;font-size:14px;line-height:1.6;">Ignora este mensaje. Tu cuenta seguirá protegida.</p>
                    </div>
                </div>
            </div>
        </div>';
}

function buildOtpEmailText($codigo)
{
    return "Tu codigo de verificacion de Super_IA es: $codigo. Este codigo expira en 10 minutos.";
}

function buildWelcomeEmailHtml($nombre)
{
    $safeName = htmlspecialchars(trim($nombre) !== '' ? $nombre : 'viajero', ENT_QUOTES, 'UTF-8');

    return '
        <div style="margin:0;padding:32px 16px;background:#07101f;font-family:Arial,sans-serif;color:#eef4ff;">
            <div style="max-width:600px;margin:0 auto;background:linear-gradient(180deg,#101933 0%,#0a1226 100%);border-radius:30px;overflow:hidden;border:1px solid rgba(127,150,255,.16);box-shadow:0 28px 90px rgba(0,0,0,.42);">
                <div style="padding:36px 36px 22px;background:radial-gradient(circle at top right,rgba(109,161,255,.42),transparent 30%),radial-gradient(circle at left top,rgba(163,110,255,.24),transparent 28%),linear-gradient(135deg,#182455 0%,#0d1430 70%);">
                    <div style="display:inline-block;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.08);color:#d9e6ff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Bienvenido a Super_IA</div>
                    <h1 style="margin:18px 0 10px;font-size:32px;line-height:1.12;color:#ffffff;">' . $safeName . ', tu cuenta ya está lista</h1>
                    <p style="margin:0;color:#b2c2e7;font-size:16px;line-height:1.7;">Gracias por registrarte. Desde este momento ya puedes solicitar viajes, guardar tus destinos favoritos y moverte con una experiencia pensada para ser rápida, clara y confiable.</p>
                </div>
                <div style="padding:28px 36px 36px;">
                    <div style="padding:22px 22px 18px;border-radius:24px;background:linear-gradient(135deg,rgba(125,151,255,.16),rgba(92,181,255,.12));border:1px solid rgba(125,151,255,.18);">
                        <div style="font-size:15px;font-weight:700;color:#ffffff;margin-bottom:10px;">Lo mejor que ya tienes disponible</div>
                        <div style="color:#a9b8da;font-size:14px;line-height:1.8;">
                            • Solicitar viajes con estimación de precio<br>
                            • Guardar favoritos, recientes e historial<br>
                            • Compartir trayecto y usar SOS si lo necesitas
                        </div>
                    </div>
                    <div style="margin-top:22px;padding:20px;border-radius:22px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);">
                        <p style="margin:0 0 8px;color:#ffffff;font-size:15px;font-weight:700;">Gracias por confiar en nosotros</p>
                        <p style="margin:0;color:#9faecc;font-size:14px;line-height:1.7;">Queremos que cada viaje se sienta simple, seguro y bien acompañado desde el primer toque en la app.</p>
                    </div>
                    <div style="margin-top:28px;text-align:center;">
                        <span style="display:inline-block;padding:14px 24px;border-radius:999px;background:linear-gradient(135deg,#8c9fff 0%,#66b4ff 100%);color:#06101f;font-size:14px;font-weight:800;letter-spacing:.04em;">Tu cuenta ya está activa</span>
                    </div>
                </div>
            </div>
        </div>';
}

function buildWelcomeEmailText($nombre)
{
    $displayName = trim($nombre) !== '' ? $nombre : 'viajero';

    return "Hola $displayName,\n\nTu cuenta en Super_IA fue creada correctamente.\nGracias por registrarte. Ya puedes solicitar viajes y usar la app.\n\nBienvenido a Super_IA.";
}

// ── Recibo de viaje ───────────────────────────────────────────────────────────
function buildReceiptEmailHtml($data)
{
    $pasajero   = htmlspecialchars($data['pasajero']   ?? 'Pasajero',   ENT_QUOTES, 'UTF-8');
    $conductor  = htmlspecialchars($data['conductor']  ?? 'Conductor',  ENT_QUOTES, 'UTF-8');
    $origen     = htmlspecialchars($data['origen']     ?? '-',          ENT_QUOTES, 'UTF-8');
    $destino    = htmlspecialchars($data['destino']    ?? '-',          ENT_QUOTES, 'UTF-8');
    $tarifa     = number_format(floatval($data['tarifa']     ?? 0), 2);
    $descuento  = number_format(floatval($data['descuento']  ?? 0), 2);
    $total      = number_format(floatval($data['tarifa'] ?? 0) - floatval($data['descuento'] ?? 0), 2);
    $distancia  = number_format(floatval($data['distancia']  ?? 0), 2);
    $duracion   = intval($data['duracion'] ?? 0);
    $placa      = htmlspecialchars($data['placa']      ?? '-',          ENT_QUOTES, 'UTF-8');
    $vehiculo   = htmlspecialchars($data['vehiculo']   ?? '-',          ENT_QUOTES, 'UTF-8');
    $fecha      = htmlspecialchars($data['fecha']      ?? date('d/m/Y H:i'), ENT_QUOTES, 'UTF-8');
    $viajeId    = intval($data['viaje_id'] ?? 0);
    $codigo     = htmlspecialchars($data['codigo_descuento'] ?? '', ENT_QUOTES, 'UTF-8');

    $descuentoRow = $descuento > 0 ? "
        <tr>
            <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,.07);color:#a9b8da;font-size:14px;'>Cupón aplicado</td>
            <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,.07);color:#4fd9a0;font-size:14px;text-align:right;font-weight:700;'>- \$$descuento</td>
        </tr>" : '';

    return "
    <div style='margin:0;padding:32px 16px;background:#07101f;font-family:Arial,sans-serif;color:#eef4ff;'>
        <div style='max-width:600px;margin:0 auto;background:linear-gradient(180deg,#101933 0%,#0a1226 100%);border-radius:30px;overflow:hidden;border:1px solid rgba(127,150,255,.16);box-shadow:0 28px 90px rgba(0,0,0,.42);'>

            <!-- Header -->
            <div style='padding:36px 36px 22px;background:radial-gradient(circle at top right,rgba(109,161,255,.42),transparent 30%),linear-gradient(135deg,#182455 0%,#0d1430 70%);'>
                <div style='display:inline-block;padding:10px 16px;border-radius:999px;background:rgba(255,255,255,.08);color:#d9e6ff;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;'>Super_IA · Recibo #{$viajeId}</div>
                <h1 style='margin:18px 0 10px;font-size:28px;line-height:1.2;color:#ffffff;'>¡Tu viaje ha terminado!</h1>
                <p style='margin:0;color:#b2c2e7;font-size:15px;line-height:1.7;'>Hola <strong>{$pasajero}</strong>, aquí está el resumen de tu viaje del {$fecha}.</p>
            </div>

            <div style='padding:28px 36px 36px;'>

                <!-- Ruta -->
                <div style='margin-bottom:22px;padding:22px;border-radius:20px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);'>
                    <div style='display:flex;align-items:flex-start;margin-bottom:16px;'>
                        <div style='width:12px;height:12px;border-radius:50%;background:#4fd9a0;margin-top:3px;flex-shrink:0;'></div>
                        <div style='margin-left:12px;'>
                            <div style='font-size:11px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px;'>Origen</div>
                            <div style='font-size:14px;color:#eef4ff;'>{$origen}</div>
                        </div>
                    </div>
                    <div style='margin-left:5px;width:2px;height:20px;background:rgba(255,255,255,.15);margin-bottom:16px;margin-top:-10px;'></div>
                    <div style='display:flex;align-items:flex-start;'>
                        <div style='width:12px;height:12px;border-radius:50%;background:#7d97ff;margin-top:3px;flex-shrink:0;'></div>
                        <div style='margin-left:12px;'>
                            <div style='font-size:11px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px;'>Destino</div>
                            <div style='font-size:14px;color:#eef4ff;'>{$destino}</div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div style='display:flex;gap:12px;margin-bottom:22px;'>
                    <div style='flex:1;padding:16px;border-radius:16px;background:rgba(125,151,255,.10);border:1px solid rgba(125,151,255,.18);text-align:center;'>
                        <div style='font-size:11px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;'>Distancia</div>
                        <div style='font-size:20px;font-weight:800;color:#ffffff;'>{$distancia} km</div>
                    </div>
                    <div style='flex:1;padding:16px;border-radius:16px;background:rgba(125,151,255,.10);border:1px solid rgba(125,151,255,.18);text-align:center;'>
                        <div style='font-size:11px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;'>Duración</div>
                        <div style='font-size:20px;font-weight:800;color:#ffffff;'>{$duracion} min</div>
                    </div>
                </div>

                <!-- Conductor -->
                <div style='margin-bottom:22px;padding:18px 22px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);'>
                    <div style='font-size:12px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;'>Tu conductor</div>
                    <div style='font-size:16px;font-weight:700;color:#ffffff;margin-bottom:4px;'>{$conductor}</div>
                    <div style='font-size:13px;color:#a9b8da;'>{$vehiculo} &nbsp;·&nbsp; Placa: <strong style='color:#eef4ff;'>{$placa}</strong></div>
                </div>

                <!-- Resumen de cobro -->
                <div style='padding:22px;border-radius:20px;background:linear-gradient(135deg,rgba(125,151,255,.14),rgba(92,181,255,.10));border:1px solid rgba(125,151,255,.2);'>
                    <div style='font-size:13px;color:#7a8fb0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:16px;font-weight:700;'>Resumen del cobro</div>
                    <table style='width:100%;border-collapse:collapse;'>
                        <tr>
                            <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,.07);color:#a9b8da;font-size:14px;'>Tarifa del viaje</td>
                            <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,.07);color:#ffffff;font-size:14px;text-align:right;'>\${$tarifa}</td>
                        </tr>
                        {$descuentoRow}
                        <tr>
                            <td style='padding:14px 0 0;color:#ffffff;font-size:16px;font-weight:800;'>Total pagado</td>
                            <td style='padding:14px 0 0;color:#4fd9a0;font-size:22px;font-weight:800;text-align:right;'>\${$total}</td>
                        </tr>
                    </table>
                </div>

                <div style='margin-top:28px;text-align:center;color:#7a8fb0;font-size:13px;line-height:1.8;'>
                    Pago en efectivo · Super_IA<br>
                    <span style='font-size:11px;'>¿Tienes algún problema con este viaje? Contáctanos desde la app.</span>
                </div>
            </div>
        </div>
    </div>";
}

function buildReceiptEmailText($data)
{
    $pasajero  = $data['pasajero']  ?? 'Pasajero';
    $conductor = $data['conductor'] ?? 'Conductor';
    $origen    = $data['origen']    ?? '-';
    $destino   = $data['destino']   ?? '-';
    $tarifa    = number_format(floatval($data['tarifa'] ?? 0), 2);
    $total     = number_format(floatval($data['tarifa'] ?? 0) - floatval($data['descuento'] ?? 0), 2);
    $distancia = number_format(floatval($data['distancia'] ?? 0), 2);
    $duracion  = intval($data['duracion'] ?? 0);
    $fecha     = $data['fecha'] ?? date('d/m/Y H:i');
    $viajeId   = intval($data['viaje_id'] ?? 0);

    return "Recibo de tu viaje en Super_IA - #{$viajeId}\n\n"
        . "Hola {$pasajero},\n\n"
        . "Tu viaje del {$fecha} ha terminado.\n\n"
        . "RUTA:\nOrigen: {$origen}\nDestino: {$destino}\n\n"
        . "Distancia: {$distancia} km | Duración: {$duracion} min\n\n"
        . "CONDUCTOR: {$conductor}\n\n"
        . "TOTAL COBRADO: \${$total}\n\n"
        . "Gracias por viajar con Super_IA.";
}

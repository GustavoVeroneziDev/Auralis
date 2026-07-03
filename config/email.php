<?php
// config/email.php — envio de e-mail transacional (ativação de conta, redefinição
// de senha, boas-vindas). Usa SMTP via PHPMailer quando config/smtp_keys.php estiver
// preenchido; cai de volta pro mail() nativo do PHP enquanto não estiver (mesmo
// comportamento de antes — nada quebra na ausência da configuração).

require_once __DIR__ . '/../vendor/autoload.php';

$smtpHost = $smtpPorta = $smtpSeguranca = $smtpUsuario = $smtpSenha = $smtpDe = $smtpDeNome = '';
$_smtpKeysFile = __DIR__ . '/smtp_keys.php';
if (file_exists($_smtpKeysFile)) {
    require $_smtpKeysFile;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envia um e-mail HTML. Usa SMTP (PHPMailer) se config/smtp_keys.php estiver
 * configurado; senão cai no mail() nativo do PHP. Retorna true/false (sucesso).
 */
if (!function_exists('enviarEmail')) {
    function enviarEmail(string $para, string $assunto, string $corpoHtml): bool
    {
        global $smtpHost, $smtpPorta, $smtpSeguranca, $smtpUsuario, $smtpSenha, $smtpDe, $smtpDeNome;

        if (empty($smtpHost)) {
            // Fallback: comportamento antigo, sem SMTP dedicado
            $cabecalhos  = "MIME-Version: 1.0\r\n";
            $cabecalhos .= "Content-type: text/html; charset=UTF-8\r\n";
            $cabecalhos .= "From: " . ($smtpDeNome ?: 'Auralis') . " <" . ($smtpDe ?: 'suporte@meuauralis.com') . ">\r\n";
            $cabecalhos .= "Reply-To: " . ($smtpDe ?: 'suporte@meuauralis.com') . "\r\n";
            return @mail($para, $assunto, $corpoHtml, $cabecalhos);
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $smtpHost;
            $mail->Port       = (int)$smtpPorta;
            $mail->SMTPSecure = $smtpSeguranca === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtpUsuario;
            $mail->Password   = $smtpSenha;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($smtpDe ?: $smtpUsuario, $smtpDeNome ?: 'Auralis');
            $mail->addAddress($para);
            $mail->addReplyTo($smtpDe ?: $smtpUsuario);
            $mail->isHTML(true);
            $mail->Subject = $assunto;
            $mail->Body    = $corpoHtml;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('Falha ao enviar e-mail via SMTP: ' . $mail->ErrorInfo);
            return false;
        }
    }
}

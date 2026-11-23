<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use PHPMailer\PHPMailer\PHPMailer;

final class MimeMessage
{
    public static function build(MailConfig $config, array $row, array $rendered): PHPMailer
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', (string) ($row['idempotency_key'] ?? '')) !== 1
            || preg_match('/\A<furo-[a-f0-9]+@[a-zA-Z0-9.-]+>\z/', (string) ($row['message_id'] ?? '')) !== 1
            || filter_var($row['recipient_email'] ?? '', FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\r\n\x00]/', (string) ($rendered['subject'] ?? ''))) {
            throw new TransportException('message_invalid', false);
        }
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_8BIT;
        $mail->XMailer = '';
        $mail->setFrom($config->from, $config->fromName);
        $mail->addAddress($row['recipient_email']);
        $mail->MessageID = $row['message_id'];
        $mail->Subject = (string) $rendered['subject'];
        $mail->isHTML(true);
        $mail->Body = (string) $rendered['html'];
        $mail->AltBody = (string) $rendered['text'];
        return $mail;
    }
}

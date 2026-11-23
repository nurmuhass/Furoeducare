<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Throwable;

final class SmtpTransport implements MailTransport
{
    public function __construct(private readonly MailConfig $config)
    {
    }

    public function send(array $row, array $rendered): array
    {
        $smtp = new class extends SMTP {
            public bool $dataStarted = false;
            public int $failureCode = 0;

            protected function sendCommand($command, $commandstring, $expect)
            {
                $sent = parent::sendCommand($command, $commandstring, $expect);
                if ($command !== 'QUIT') {
                    $this->failureCode = $sent ? 0 : (int) ($this->getError()['smtp_code'] ?? 0);
                }
                return $sent;
            }

            public function data($msg_data)
            {
                $this->dataStarted = true;
                return parent::data($msg_data);
            }
        };
        $mail = null;
        try {
            $this->config->validate();
            $mail = MimeMessage::build($this->config, $row, $rendered);
            $mail->isSMTP();
            $mail->setSMTPInstance($smtp);
            $mail->Host = $this->config->host;
            $mail->Port = $this->config->port;
            $mail->SMTPAuth = $this->config->username !== '';
            $mail->Username = $this->config->username;
            $mail->Password = $this->config->password;
            $mail->SMTPSecure = match ($this->config->encryption) {
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                'smtps' => PHPMailer::ENCRYPTION_SMTPS,
                default => '',
            };
            $mail->SMTPAutoTLS = $this->config->encryption !== 'none';
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->Timeout = $this->config->timeout;
            $smtp->Timeout = $this->config->timeout;
            $smtp->Timelimit = $this->config->timeout;
            $mail->send();
            $id = $smtp->getLastTransactionID();
            // Relay transaction IDs are untrusted; never put a free-form response into audit logs.
            return ['provider_status' => 'accepted', 'provider_id' => is_string($id) && preg_match('/\A[A-Za-z0-9_.:-]{1,160}\z/', $id) ? $id : null];
        } catch (TransportException $error) {
            throw $error;
        } catch (Throwable) {
            // PHPMailer issues QUIT after a rejected RCPT, which clears the SMTP error.
            $code = $smtp->failureCode ?: (int) ($smtp->getError()['smtp_code'] ?? 0);
            if ($code >= 500 && $code < 600) {
                throw new TransportException('smtp_permanent', false);
            }
            if ($code >= 400 && $code < 500) {
                throw new TransportException('smtp_temporary', true);
            }
            throw new TransportException($smtp->dataStarted ? 'smtp_uncertain' : 'smtp_connection', true, $smtp->dataStarted);
        } finally {
            $mail?->smtpClose();
        }
    }
}

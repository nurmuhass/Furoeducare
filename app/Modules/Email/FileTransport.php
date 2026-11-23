<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use Throwable;

final class FileTransport implements MailTransport
{
    public function __construct(private readonly MailConfig $config)
    {
    }

    public function send(array $row, array $rendered): array
    {
        $temporary = null;
        $lock = null;
        try {
            $this->config->validate();
            $mail = MimeMessage::build($this->config, $row, $rendered);
            $directory = $this->config->privateDirectory();
            $path = $directory . DIRECTORY_SEPARATOR . $row['idempotency_key'] . '.eml';
            $lock = @fopen($directory . DIRECTORY_SEPARATOR . 'spool.lock', 'c');
            if ($lock === false || !@chmod($directory . DIRECTORY_SEPARATOR . 'spool.lock', 0600) || !flock($lock, LOCK_EX)) {
                throw new TransportException('file_unavailable', true);
            }
            if (file_exists($path)) {
                if (is_link($path)) {
                    throw new TransportException('file_conflict', false);
                }
                $existing = @file_get_contents($path);
                if ($existing === false || !str_contains($existing, 'Message-ID: ' . $row['message_id'] . "\r\n")) {
                    throw new TransportException('file_conflict', false);
                }
            } else {
                // Use RFC 5322 CRLF even when the host platform uses LF for local mail().
                $mail->isSMTP();
                $mail->preSend();
                $bytes = $mail->getSentMIMEMessage();
                $temporary = tempnam($directory, '.mail-');
                if ($temporary === false || !@chmod($temporary, 0600) || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                    || !@rename($temporary, $path)) {
                    throw new TransportException('file_unavailable', true);
                }
                $temporary = null;
            }
            return ['provider_status' => 'stored', 'provider_id' => $row['message_id']];
        } catch (TransportException $error) {
            throw $error;
        } catch (Throwable) {
            throw new TransportException('file_unavailable', true);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}

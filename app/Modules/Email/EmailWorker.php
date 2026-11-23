<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use Closure;
use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Logger;
use PDO;
use Throwable;

final class EmailWorker
{
    private readonly MailConfig $mail;
    private readonly MailTransport $transport;
    private readonly Logger $logger;
    private readonly EmailPreferences $preferences;

    public function __construct(private readonly Closure $database, private readonly Config $config, ?MailTransport $transport = null)
    {
        $this->mail = new MailConfig($config);
        $this->mail->validate();
        MailCrypto::key($config->mailOptions['encryption_key']);
        $this->transport = $transport ?? ($this->mail->transport === 'file' ? new FileTransport($this->mail) : new SmtpTransport($this->mail));
        $this->logger = new Logger($config->mailOptions['log_file']);
        $this->preferences = new EmailPreferences($database);
    }

    public function run(int $limit = 50): array
    {
        $counts = ['accepted' => 0, 'retried' => 0, 'failed' => 0, 'suppressed' => 0, 'uncertain' => $this->recoverAbandoned()];
        for ($processed = 0; $processed < max(1, min(1000, $limit)); $processed++) {
            $row = $this->claim();
            if ($row === null) {
                break;
            }
            $status = $this->deliver($row);
            $counts[$status]++;
        }
        return $counts;
    }

    private function recoverAbandoned(): int
    {
        return Database::transaction(($this->database)(), function (PDO $db): int {
            $query = $db->query("SELECT id, attempts FROM email_outbox WHERE status = 'processing'
                AND locked_until <= UTC_TIMESTAMP(6) ORDER BY id LIMIT 100 FOR UPDATE SKIP LOCKED");
            $rows = $query->fetchAll();
            foreach ($rows as $row) {
                $db->prepare("UPDATE email_outbox SET status = 'uncertain', last_error_code = 'lease_expired', secret_payload = NULL,
                    lock_token = NULL, locked_until = NULL WHERE id = ?")->execute([$row['id']]);
                $db->prepare("UPDATE email_delivery_attempts SET status = 'uncertain', error_code = 'lease_expired',
                    finished_at = UTC_TIMESTAMP(6) WHERE outbox_id = ? AND attempt = ? AND status = 'processing'")
                    ->execute([$row['id'], $row['attempts']]);
                $this->log($row, 'lease_expired');
            }
            return count($rows);
        });
    }

    private function claim(): ?array
    {
        return Database::transaction(($this->database)(), static function (PDO $db): ?array {
            $row = $db->query("SELECT * FROM email_outbox WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP(6)
                ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if ($row === false) {
                return null;
            }
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['lock_token'] = bin2hex(random_bytes(16));
            $db->prepare("UPDATE email_outbox SET status = 'processing', attempts = ?, lock_token = ?,
                locked_until = UTC_TIMESTAMP(6) + INTERVAL 5 MINUTE WHERE id = ?")
                ->execute([$row['attempts'], $row['lock_token'], $row['id']]);
            $db->prepare('INSERT INTO email_delivery_attempts (outbox_id, attempt) VALUES (?, ?)')->execute([$row['id'], $row['attempts']]);
            return $row;
        });
    }

    private function deliver(array $row): string
    {
        $submitted = false;
        try {
            if (!$this->eligible($row)) {
                return $this->finish($row, 'suppressed', 'recipient_or_preference_changed');
            }
            $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
            if ($row['secret_payload'] !== null) {
                $token = MailCrypto::decrypt($row['secret_payload'], $this->config->mailOptions['encryption_key']);
                if (!hash_equals($row['token_hash'], hash('sha256', $token, true))) {
                    throw new \RuntimeException('The encrypted token does not match its queued authentication event.');
                }
                $payload['token'] = $token;
            }
            $rendered = EmailTemplates::render($row['template'], $payload, $this->config->baseUrl);
            // A stable message ID accompanies all attempts for the same event.
            $domain = substr(strrchr($this->mail->from, '@'), 1);
            $row['message_id'] = '<furo-' . $row['idempotency_key'] . '@' . $domain . '>';
            $result = $this->transport->send($row, $rendered);
            $submitted = true;
            return $this->finish($row, 'accepted', null, $result);
        } catch (TransportException $error) {
            $status = $error->uncertain ? 'uncertain' : ($error->retryable && (int) $row['attempts'] < (int) $row['max_attempts'] ? 'retried' : 'failed');
            return $this->finish($row, $status, $error->codeName);
        } catch (Throwable $error) {
            return $submitted ? $this->finish($row, 'uncertain', 'acceptance_recording_failed')
                : $this->finish($row, 'failed', 'mail_processing_error');
        }
    }

    private function eligible(array $row): bool
    {
        $db = ($this->database)();
        $query = $db->prepare('SELECT email, email_verified_at, suspended_at FROM users WHERE id = ?');
        $query->execute([$row['user_id']]);
        $user = $query->fetch();
        if ($user === false || $user['suspended_at'] !== null) {
            return false;
        }
        if ($row['template'] === 'email_change') {
            $query = $db->prepare('SELECT 1 FROM account_email_changes c JOIN users u ON u.id = c.user_id
                WHERE c.user_id = ? AND c.token_hash = ? AND c.new_email = ? AND c.old_email COLLATE utf8mb4_0900_as_ci = u.email
                AND c.auth_version = u.auth_version AND c.cancelled_at IS NULL AND c.consumed_at IS NULL
                AND c.expires_at > UTC_TIMESTAMP(6) AND u.email_verified_at IS NOT NULL');
            $query->execute([$row['user_id'], $row['token_hash'], $row['recipient_email']]);
            return $query->fetchColumn() !== false;
        }
        if ($user['email'] !== $row['recipient_email']) return false;
        $security = in_array($row['template'], ['account_verification', 'password_reset'], true);
        if ($security) {
            $verification = $row['template'] === 'account_verification';
            if ($verification !== ($user['email_verified_at'] === null)) {
                return false;
            }
            $table = $verification ? 'email_verification_tokens' : 'password_reset_tokens';
            $sql = "SELECT 1 FROM $table WHERE token_hash = ? AND user_id = ? AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)";
            $params = [$row['token_hash'], $row['user_id']];
            if ($verification) {
                $sql .= ' AND email_at_issue = ?';
                $params[] = $row['recipient_email'];
            }
            $query = $db->prepare($sql);
            $query->execute($params);
            return $query->fetchColumn() !== false;
        }
        $category = EmailTemplates::category($row['template']);
        return $user['email_verified_at'] !== null && $category !== null
            && $this->preferences->allowed((int) $row['user_id'], $category);
    }

    private function finish(array $row, string $status, ?string $errorCode, array $provider = []): string
    {
        $errorCode = $errorCode === null ? null : (preg_match('/\A[a-z0-9_]{1,60}\z/', $errorCode) === 1 ? $errorCode : 'transport_error');
        $recorded = Database::transaction(($this->database)(), function (PDO $db) use ($row, $status, $errorCode, $provider): bool {
            $retry = $status === 'retried';
            $seconds = min(3600, 60 * (2 ** min(6, (int) $row['attempts'] - 1)));
            $providerStatus = substr((string) ($provider['provider_status'] ?? ''), 0, 32) ?: null;
            $providerId = isset($provider['provider_id']) ? substr((string) $provider['provider_id'], 0, 255) : null;
            $query = $db->prepare("UPDATE email_outbox SET status = ?, last_error_code = ?, provider_status = ?, provider_id = ?,
                available_at = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)), accepted_at = IF(? = 'accepted', UTC_TIMESTAMP(6), NULL),
                secret_payload = IF(?, secret_payload, NULL), locked_until = NULL, lock_token = NULL
                WHERE id = ? AND status = 'processing' AND lock_token = ?");
            $query->execute([$retry ? 'pending' : $status, $errorCode, $providerStatus, $providerId, $retry ? $seconds : 0,
                $status, (int) $retry, $row['id'], $row['lock_token']]);
            if ($query->rowCount() === 1) {
                $db->prepare('UPDATE email_delivery_attempts SET status = ?, error_code = ?, provider_id = ?, finished_at = UTC_TIMESTAMP(6)
                    WHERE outbox_id = ? AND attempt = ? AND status = \'processing\'')
                    ->execute([$status, $errorCode, $providerId, $row['id'], $row['attempts']]);
                return true;
            }
            return false;
        });
        if (!$recorded) {
            $this->log($row, 'lease_ownership_lost');
            return 'uncertain';
        }
        if ($errorCode !== null && $status !== 'suppressed') {
            $this->log($row, $errorCode);
        }
        return $status;
    }

    private function log(array $row, string $code): void
    {
        $this->logger->error(['event' => 'email.delivery_failure', 'outbox_id' => (int) $row['id'],
            'attempt' => (int) $row['attempts'], 'error_code' => $code]);
    }
}

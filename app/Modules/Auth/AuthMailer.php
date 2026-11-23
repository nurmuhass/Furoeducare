<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use Closure;
use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Email\MailConfig;
use Furoeducare\Modules\Email\MailCrypto;
use Furoeducare\Modules\Email\Outbox;
use PDO;
use RuntimeException;
use Throwable;

final class AuthMailer
{
    public function __construct(private readonly Config $config, private readonly Closure $database)
    {
    }

    public function ready(): bool
    {
        try {
            (new MailConfig($this->config))->validate();
            MailCrypto::key($this->config->mailOptions['encryption_key']);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Enqueue inside token issuance's transaction. Network delivery belongs to the CLI worker. */
    public function deliver(
        EmailToken $purpose, string $email, #[\SensitiveParameter] string $token,
        ?Role $flow = null, ?string $teacherRequestReference = null,
    ): void {
        if (!AuthInput::validToken($token)) {
            throw new RuntimeException('Cannot queue an invalid account token.');
        }
        $db = ($this->database)();
        $enqueue = function (PDO $db) use ($purpose, $email, $token, $flow, $teacherRequestReference): void {
            $hash = hash('sha256', $token, true);
            $query = $db->prepare('SELECT t.user_id, t.expires_at FROM ' . $purpose->value . ' t JOIN users u ON u.id = t.user_id
                WHERE t.token_hash = ? AND u.email = ? AND t.consumed_at IS NULL AND t.expires_at > UTC_TIMESTAMP(6)');
            $query->execute([$hash, $email]);
            $row = $query->fetch();
            if ($row === false) {
                throw new RuntimeException('Cannot queue an inactive account token.');
            }
            $verification = $purpose === EmailToken::Verification;
            $payload = $verification ? ['flow' => $flow?->value, 'ref' => AuthInput::teacherRequestReference($teacherRequestReference)] : [];
            Outbox::enqueue($db, 'auth:' . $purpose->value . ':' . bin2hex($hash), (int) $row['user_id'],
                $verification ? 'account_verification' : 'password_reset', $payload, $email,
                MailCrypto::encrypt($token, $this->config->mailOptions['encryption_key']), $hash, $row['expires_at']);
        };
        if ($db->inTransaction()) {
            $enqueue($db);
        } else {
            Database::transaction($db, $enqueue);
        }
    }
}

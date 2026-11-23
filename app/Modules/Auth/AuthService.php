<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use Closure;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Notification\NotificationEvents;
use PDO;
use PDOException;
use RuntimeException;

final class AuthService
{
    // A fixed bcrypt hash keeps the invalid-credential path doing one password verification.
    private const PENDING_HASH = '!pending-email-verification';
    private const DUMMY_HASH = '$2y$12$B.0Poqrx7H.TDHO.FxArC.Msc.e.lC8L3I3tLndDidRlhaxXVihWG';

    public function __construct(private readonly Closure $database)
    {
    }

    public function findUser(int|string $identity, bool $lock = false): ?array
    {
        $column = is_int($identity) ? 'id' : 'email';
        $query = ($this->database)()->prepare(
            "SELECT id, email, password_hash, email_verified_at, suspended_at, auth_version FROM users WHERE $column = ?"
            . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$identity]);
        return $query->fetch() ?: null;
    }

    /** @return list<Role> */
    public function roles(int $userId): array
    {
        $query = ($this->database)()->prepare(
            'SELECT roles.slug FROM roles JOIN user_roles ON user_roles.role_id = roles.id WHERE user_roles.user_id = ?'
        );
        $query->execute([$userId]);
        $slugs = $query->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(Role::cases(), static fn (Role $role): bool => in_array($role->value, $slugs, true)));
    }

    public function checkPassword(string $email, #[\SensitiveParameter] string $password): ?array
    {
        $user = $this->findUser($email);
        $hash = $user['password_hash'] ?? self::PENDING_HASH;
        $matches = password_verify($password, $hash === self::PENDING_HASH ? self::DUMMY_HASH : $hash);
        return $matches && $hash !== self::PENDING_HASH ? $user : null;
    }

    public function rehashIfNeeded(array $user, #[\SensitiveParameter] string $password): void
    {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            ($this->database)()->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?')->execute([
                password_hash($password, PASSWORD_DEFAULT), $user['id'], $user['password_hash'],
            ]);
        }
    }

    public function register(string $email, ?Closure $enqueue = null): ?string
    {
        try {
            return Database::transaction(($this->database)(), function (PDO $db) use ($email, $enqueue): string {
                $db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)')->execute([$email, self::PENDING_HASH]);
                $token = $this->issue(EmailToken::Verification, (int) $db->lastInsertId(), $email);
                $enqueue?->__invoke($token);
                return $token;
            });
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062 && $this->findUser($email) !== null) {
                return null;
            }
            throw $error;
        }
    }

    public function requestLink(EmailToken $purpose, string $email, ?Closure $enqueue = null): ?string
    {
        return Database::transaction(($this->database)(), function () use ($purpose, $email, $enqueue): ?string {
            $user = $this->findUser($email, true);
            if ($user === null || $user['suspended_at'] !== null
                || ($purpose === EmailToken::Verification && $user['email_verified_at'] !== null)
                || ($purpose === EmailToken::PasswordReset && $user['email_verified_at'] === null)) {
                return null;
            }
            $token = $this->issue($purpose, (int) $user['id'], $user['email']);
            $enqueue?->__invoke($token);
            return $token;
        });
    }

    public function canConfirmProfileEmail(#[\SensitiveParameter] string $token): bool
    {
        $query = ($this->database)()->prepare(
            'SELECT 1 FROM email_verification_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.consumed_at IS NULL AND t.expires_at > CURRENT_TIMESTAMP(6)
             AND u.email_verified_at IS NULL AND u.suspended_at IS NULL AND t.email_at_issue = u.email'
        );
        $query->execute([hash('sha256', $token, true)]);
        return $query->fetchColumn() !== false;
    }

    /** The profile is persisted in the same transaction that verifies the account. */
    public function confirmProfileEmail(
        Role $role,
        #[\SensitiveParameter] string $token,
        string $email,
        #[\SensitiveParameter] Closure $passwordHash,
        Closure $createProfile,
    ): ?array {
        if (!$role->hasProfileRegistration()) {
            throw new RuntimeException('Invalid role for profile activation.');
        }
        $account = null;
        $this->consume(EmailToken::Verification, $token, function (PDO $db, int $id) use ($role, $passwordHash, $createProfile, &$account): void {
            $this->activate($db, $id, $passwordHash(), $role);
            $createProfile($id);
            $account = ['id' => $id, 'version' => (int) $this->findUser($id)['auth_version']];
        }, profileEmail: $email, profileRole: $role);
        return $account;
    }

    private function activate(PDO $db, int $id, #[\SensitiveParameter] string $hash, Role $role): void
    {
        $db->prepare('UPDATE users SET email_verified_at = CURRENT_TIMESTAMP(6), password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
            ->execute([$hash, $id]);

        $assigned = $db->prepare('SELECT 1 FROM user_roles WHERE user_id = ? LIMIT 1');
        $assigned->execute([$id]);
        if ($assigned->fetchColumn() !== false) {
            NotificationEvents::record($db, $id, $id, 'account.verified', ['event_key' => 'welcome:' . $id], 'Email verified', 'Your email has been verified. Open your dashboard for the next steps.');
            return;
        }

        $grant = $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?');
        $grant->execute([$id, $role->value]);
        if ($grant->rowCount() !== 1) {
            throw new RuntimeException('The reference account roles have not been seeded.');
        }
        NotificationEvents::record($db, $id, $id, 'account.verified', ['event_key' => 'welcome:' . $id], 'Email verified', 'Your email has been verified. Open your dashboard for the next steps.');
    }

    public function resetPassword(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): bool
    {
        return $this->consume(EmailToken::PasswordReset, $token, static function (PDO $db, int $id) use ($password): void {
            $db->prepare('UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $query = $db->prepare('SELECT auth_version FROM users WHERE id = ?');
            $query->execute([$id]);
            NotificationEvents::record($db, $id, $id, 'account.password_changed', ['revision' => (int) $query->fetchColumn()], 'Password changed', 'Your account password was changed.');
        });
    }

    private function issue(EmailToken $purpose, int $userId, string $email): string
    {
        $db = ($this->database)();
        $table = $purpose->value;
        $token = bin2hex(random_bytes(32));
        $lifetime = $purpose->lifetime();
        $hash = hash('sha256', $token, true);
        $expiry = "TIMESTAMPADD(SECOND, $lifetime, CURRENT_TIMESTAMP(6))";
        if ($purpose === EmailToken::Verification) {
            $db->prepare("INSERT INTO $table (token_hash, user_id, email_at_issue, expires_at) VALUES (?, ?, ?, $expiry)")
                ->execute([$hash, $userId, $email]);
        } else {
            $db->prepare("INSERT INTO $table (token_hash, user_id, expires_at) VALUES (?, ?, $expiry)")
                ->execute([$hash, $userId]);
        }
        return $token;
    }

    private function consume(EmailToken $purpose, #[\SensitiveParameter] string $token, Closure $onSuccess, ?string $profileEmail = null, ?Role $profileRole = null): bool
    {
        $db = ($this->database)();
        $table = $purpose->value;
        $hash = hash('sha256', $token, true);
        $lookup = $db->prepare("SELECT user_id FROM $table WHERE token_hash = ?");
        $lookup->execute([$hash]);
        $id = $lookup->fetchColumn();
        if ($id === false) {
            return false;
        }

        return Database::transaction($db, function (PDO $db) use ($purpose, $table, $hash, $id, $onSuccess, $profileEmail, $profileRole): bool {
            // Issuance and consumption both lock the owner before changing token state.
            $user = $this->findUser((int) $id, true);
            if ($user === null || $user['suspended_at'] !== null) {
                return false;
            }
            $verifying = $purpose === EmailToken::Verification;
            if ($verifying !== ($user['email_verified_at'] === null)) {
                return false;
            }

            if ($profileEmail !== null) {
                $roles = $this->roles((int) $id);
                if ($user['email'] !== $profileEmail || ($roles !== [] && !in_array($profileRole, $roles, true))) {
                    return false;
                }
            }

            $matchEmail = $verifying ? ' AND email_at_issue = ?' : '';
            $values = $verifying ? [$hash, $id, $user['email']] : [$hash, $id];
            $claim = $db->prepare(
                "UPDATE $table SET consumed_at = CURRENT_TIMESTAMP(6) WHERE token_hash = ? AND user_id = ?
                 AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP(6)$matchEmail"
            );
            $claim->execute($values);
            if ($claim->rowCount() !== 1) {
                return false;
            }

            $onSuccess($db, (int) $id);
            $db->prepare("UPDATE $table SET consumed_at = CURRENT_TIMESTAMP(6) WHERE user_id = ? AND consumed_at IS NULL")
                ->execute([$id]);
            return true;
        });
    }
}

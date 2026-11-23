<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Settings;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class AppearancePreferences
{
    public const CHOICES = ['light', 'dark', 'system'];

    public function __construct(private readonly Closure $database) {}

    public function get(int $user): string
    {
        $query = ($this->database)()->prepare('SELECT appearance FROM account_preferences WHERE user_id = ?');
        $query->execute([$user]);
        $appearance = $query->fetchColumn();
        return in_array($appearance, self::CHOICES, true) ? $appearance : 'system';
    }

    public function save(int $user, string $appearance, ?int $version = null): void
    {
        if (!in_array($appearance, self::CHOICES, true)) {
            throw new DomainException('Choose Light, Dark or System default.', 422);
        }
        Database::transaction(($this->database)(), static function (PDO $db) use ($user, $appearance, $version): void {
            $query = $db->prepare('SELECT email_verified_at, suspended_at, auth_version FROM users WHERE id = ? FOR UPDATE');
            $query->execute([$user]);
            $account = $query->fetch();
            if ($account === false || $account['email_verified_at'] === null || $account['suspended_at'] !== null
                || ($version !== null && (int) $account['auth_version'] !== $version)) {
                throw new DomainException('A verified, active account is required.', 403);
            }
            $db->prepare('INSERT INTO account_preferences (user_id, appearance) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE appearance = VALUES(appearance)')->execute([$user, $appearance]);
            VerificationEvents::audit($db, $user, 'account.appearance_updated', ['appearance' => $appearance]);
        });
    }
}

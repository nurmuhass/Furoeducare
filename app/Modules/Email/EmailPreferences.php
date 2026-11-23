<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use Closure;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class EmailPreferences
{
    public const CATEGORIES = [
        'account' => 'Account and verification updates',
        'recruitment' => 'Recruitment and candidate requests',
        'messages' => 'New secure messages',
        'tutoring' => 'Tutoring updates',
        'placements' => 'Placement confirmations',
        'training' => 'Training confirmations',
    ];

    public function __construct(private readonly Closure $database)
    {
    }

    /** @return array<string,bool> Category keys, defaulting to enabled until saved. */
    public function forUser(int $user): array
    {
        if ($user < 1) {
            throw new InvalidArgumentException('A signed-in account is required.');
        }
        $query = ($this->database)()->prepare('SELECT account_enabled, recruitment_enabled, messages_enabled, tutoring_enabled, placements_enabled, training_enabled FROM email_notification_preferences WHERE user_id = ?');
        $query->execute([$user]);
        $row = $query->fetch();
        $result = [];
        foreach (self::CATEGORIES as $category => $_label) {
            $result[$category] = $row === false || (bool) $row[$category . '_enabled'];
        }
        return $result;
    }

    /** Re-read at dispatch time so opting out also suppresses previously queued optional email. */
    public function allowed(int $user, string $category): bool
    {
        return isset(self::CATEGORIES[$category]) && $this->forUser($user)[$category];
    }

    /** @param list<string> $enabled */
    public function save(int $user, array $enabled): void
    {
        if ($user < 1 || !array_is_list($enabled)) {
            throw new InvalidArgumentException('Invalid notification preferences.');
        }
        foreach ($enabled as $category) {
            if (!is_string($category) || !isset(self::CATEGORIES[$category])) {
                throw new InvalidArgumentException('Invalid notification category.');
            }
        }
        Database::transaction(($this->database)(), static function (PDO $db) use ($user, $enabled): void {
            $owner = $db->prepare('SELECT id FROM users WHERE id = ? AND suspended_at IS NULL AND email_verified_at IS NOT NULL FOR UPDATE');
            $owner->execute([$user]);
            if ($owner->fetchColumn() === false) {
                throw new RuntimeException('This account cannot change notification preferences.');
            }
            $roles = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug IN ('admin', 'teacher', 'school', 'parent') LIMIT 1 FOR UPDATE");
            $roles->execute([$user]);
            if ($roles->fetchColumn() === false) {
                throw new RuntimeException('This account cannot change notification preferences.');
            }
            $values = array_map(static fn (string $key): int => in_array($key, $enabled, true) ? 1 : 0, array_keys(self::CATEGORIES));
            $db->prepare('INSERT INTO email_notification_preferences (user_id, account_enabled, recruitment_enabled, messages_enabled, tutoring_enabled, placements_enabled, training_enabled) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE account_enabled = VALUES(account_enabled), recruitment_enabled = VALUES(recruitment_enabled), messages_enabled = VALUES(messages_enabled), tutoring_enabled = VALUES(tutoring_enabled), placements_enabled = VALUES(placements_enabled), training_enabled = VALUES(training_enabled), updated_at = CURRENT_TIMESTAMP(6)')
                ->execute([$user, ...$values]);
            VerificationEvents::audit($db, $user, 'email.preferences_updated', ['enabled_categories' => array_values(array_unique($enabled))]);
        });
    }
}

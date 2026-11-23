<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Email\EmailPreferences;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class NotificationPreferences
{
    public const CATEGORIES = EmailPreferences::CATEGORIES;

    public function __construct(private readonly Closure $database)
    {
    }

    public function forUser(int $user): array
    {
        $db = ($this->database)();
        NotificationAccess::requireOwner($db, $user);
        return self::read($db, $user);
    }

    /** Read channel decisions inside the event transaction, including account activation events. */
    public static function read(PDO $db, int $user, bool $current = false): array
    {
        $result = [];
        foreach (['in_app' => 'in_app_notification_preferences', 'email' => 'email_notification_preferences'] as $channel => $table) {
            $row = NotificationAccess::query($db, 'SELECT account_enabled, recruitment_enabled, messages_enabled, tutoring_enabled, placements_enabled, training_enabled FROM ' . $table . ' WHERE user_id = ?'
                . ($current ? ' FOR SHARE' : ''), [$user])->fetch();
            foreach (self::CATEGORIES as $category => $_label) {
                $result[$channel][$category] = $row === false || (bool) $row[$category . '_enabled'];
            }
        }
        return $result;
    }

    public function save(int $user, array $inAppEnabled, array $emailEnabled): void
    {
        self::validate($inAppEnabled);
        self::validate($emailEnabled);
        Database::transaction(($this->database)(), static function (PDO $db) use ($user, $inAppEnabled, $emailEnabled): void {
            NotificationAccess::requireOwner($db, $user, true);
            foreach (['in_app_notification_preferences' => $inAppEnabled, 'email_notification_preferences' => $emailEnabled] as $table => $enabled) {
                self::write($db, $table, $user, $enabled);
            }
            VerificationEvents::audit($db, $user, 'notifications.preferences_updated', ['in_app' => array_values(array_unique($inAppEnabled)), 'email' => array_values(array_unique($emailEnabled))]);
        });
    }

    /** Legacy email-only forms must never write another channel's settings. */
    public function saveEmail(int $user, array $enabled): void
    {
        self::validate($enabled);
        Database::transaction(($this->database)(), static function (PDO $db) use ($user, $enabled): void {
            NotificationAccess::requireOwner($db, $user, true);
            self::write($db, 'email_notification_preferences', $user, $enabled);
            VerificationEvents::audit($db, $user, 'email.preferences_updated', ['enabled_categories' => array_values(array_unique($enabled))]);
        });
    }

    private static function validate(array $enabled): void
    {
        if (!array_is_list($enabled)) {
            throw new DomainException('Choose valid notification categories.', 422);
        }
        foreach ($enabled as $category) {
            if (!is_string($category) || !isset(self::CATEGORIES[$category])) {
                throw new DomainException('Choose valid notification categories.', 422);
            }
        }
    }

    private static function write(PDO $db, string $table, int $user, array $enabled): void
    {
        $values = array_map(static fn (string $category): int => in_array($category, $enabled, true) ? 1 : 0, array_keys(self::CATEGORIES));
        NotificationAccess::query($db, 'INSERT INTO ' . $table . ' (user_id, account_enabled, recruitment_enabled, messages_enabled, tutoring_enabled, placements_enabled, training_enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE account_enabled = VALUES(account_enabled), recruitment_enabled = VALUES(recruitment_enabled),
            messages_enabled = VALUES(messages_enabled), tutoring_enabled = VALUES(tutoring_enabled), placements_enabled = VALUES(placements_enabled), training_enabled = VALUES(training_enabled)', [$user, ...$values]);
    }
}

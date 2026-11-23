<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use PDO;

final class NotificationInbox
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function listing(int $owner, int $before = PHP_INT_MAX, bool $unread = false, string $category = ''): array
    {
        $db = ($this->database)();
        NotificationAccess::requireOwner($db, $owner);
        $where = ['user_id = ?', 'in_app_visible = 1', 'id < ?'];
        $parameters = [$owner, $before];
        if ($unread) {
            $where[] = 'read_at IS NULL';
        }
        if ($category !== '') {
            if (!isset(NotificationPreferences::CATEGORIES[$category])) {
                throw new DomainException('Choose a valid notification category.', 422);
            }
            $where[] = 'category = ?';
            $parameters[] = $category;
        }
        return NotificationAccess::query($db, 'SELECT id, event, category, title, message, path, read_at, created_at FROM verification_notifications WHERE '
            . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 21', $parameters)->fetchAll();
    }

    public function unread(int $owner): int
    {
        $db = ($this->database)();
        NotificationAccess::requireOwner($db, $owner);
        return (int) NotificationAccess::query($db, 'SELECT COUNT(*) FROM verification_notifications WHERE user_id = ? AND in_app_visible = 1 AND read_at IS NULL', [$owner])->fetchColumn();
    }

    public function destination(int $owner, int $id): string
    {
        $db = ($this->database)();
        NotificationAccess::requireOwner($db, $owner);
        $path = NotificationAccess::query($db, 'SELECT path FROM verification_notifications WHERE id = ? AND user_id = ? AND in_app_visible = 1', [$id, $owner])->fetchColumn();
        if (!is_string($path)) {
            throw self::missing();
        }
        try {
            return NotificationInput::path($path);
        } catch (DomainException) {
            // An old or administratively corrupted destination never becomes an external redirect.
            return '/notifications';
        }
    }

    public function markRead(int $owner, int $id): void
    {
        Database::transaction(($this->database)(), static function (PDO $db) use ($owner, $id): void {
            NotificationAccess::requireOwner($db, $owner, true);
            $exists = NotificationAccess::query($db, 'SELECT id FROM verification_notifications WHERE id = ? AND user_id = ? AND in_app_visible = 1 FOR UPDATE', [$id, $owner])->fetchColumn();
            if ($exists === false) {
                throw self::missing();
            }
            NotificationAccess::query($db, 'UPDATE verification_notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP(6)) WHERE id = ? AND user_id = ?', [$id, $owner]);
        });
    }

    public function markAll(int $owner, int $through): void
    {
        if ($through < 1) {
            throw new DomainException('Reopen the notification list before marking it read.', 422);
        }
        Database::transaction(($this->database)(), static function (PDO $db) use ($owner, $through): void {
            NotificationAccess::requireOwner($db, $owner, true);
            NotificationAccess::query($db, 'UPDATE verification_notifications SET read_at = UTC_TIMESTAMP(6)
                WHERE user_id = ? AND id <= ? AND in_app_visible = 1 AND read_at IS NULL', [$owner, $through]);
        });
    }

    private static function missing(): DomainException
    {
        return new DomainException('This notification is unavailable.', 404);
    }
}

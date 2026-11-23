<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use Closure;
use Furoeducare\Modules\Notification\NotificationEvents;
use PDO;

final class VerificationEvents
{
    public function __construct(private readonly Closure $database)
    {
    }

    /** Write the event and its audit record inside the caller's decision transaction. */
    public static function record(
        PDO $db, int $actor, int $recipient, string $event, array $context, string $title, string $message,
    ): void {
        self::audit($db, $actor, $event, ['user_id' => $recipient] + $context);
        NotificationEvents::record($db, $actor, $recipient, $event, $context, $title, $message);
    }

    public static function audit(PDO $db, int $actor, string $event, array $context): void
    {
        $db->prepare('INSERT INTO audit_logs (actor_user_id, action, context) VALUES (?, ?, ?)')
            ->execute([$actor, $event, json_encode($context, JSON_THROW_ON_ERROR)]);
    }

    public function forUser(int $user, int $limit = 30, ?string $eventPrefix = null): array
    {
        $query = ($this->database)()->prepare(
            'SELECT title, message, created_at FROM verification_notifications WHERE user_id = ? AND in_app_visible = 1'
            . ($eventPrefix === null ? '' : ' AND event LIKE ?') . ' ORDER BY id DESC LIMIT ?'
        );
        $query->bindValue(1, $user, PDO::PARAM_INT);
        if ($eventPrefix !== null) {
            $query->bindValue(2, $eventPrefix . '%');
        }
        $query->bindValue($eventPrefix === null ? 2 : 3, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }
}

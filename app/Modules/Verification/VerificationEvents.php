<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use Closure;
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
        $db->prepare('INSERT INTO verification_notifications (user_id, event, title, message) VALUES (?, ?, ?, ?)')
            ->execute([$recipient, $event, $title, $message]);
    }

    public static function audit(PDO $db, int $actor, string $event, array $context): void
    {
        $db->prepare('INSERT INTO audit_logs (actor_user_id, action, context) VALUES (?, ?, ?)')
            ->execute([$actor, $event, json_encode($context, JSON_THROW_ON_ERROR)]);
    }

    public function forUser(int $user, int $limit = 30): array
    {
        $query = ($this->database)()->prepare(
            'SELECT title, message, created_at FROM verification_notifications WHERE user_id = ? ORDER BY id DESC LIMIT ?'
        );
        $query->bindValue(1, $user, PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }
}

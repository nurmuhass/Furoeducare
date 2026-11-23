<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Email\EmailTemplates;
use Furoeducare\Modules\Email\Outbox;
use PDO;

final class NotificationEvents
{
    public static function record(PDO $db, ?int $actor, int $recipient, string $event, array $context, string $title, string $message): int
    {
        $definition = NotificationCatalog::describe($db, $recipient, $event, $context);
        return self::emit($db, $actor, $recipient, $event, $definition['key'], $definition['category'], $title, $message,
            $definition['path'], $definition['template'], $definition['payload']);
    }

    /** Join the business transaction or provide an atomic transaction for a standalone event. */
    public static function emit(
        PDO $db, ?int $actor, int $recipient, string $event, string $key, string $category,
        string $title, string $message, string $path, ?string $template = null, array $payload = [],
    ): int {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/', $event) !== 1 || !isset(NotificationPreferences::CATEGORIES[$category])
            || preg_match('/\A[^\x00-\x1f\x7f]{1,240}\z/u', $key) !== 1) {
            throw new DomainException('Invalid notification event, key or category.', 422);
        }
        $title = NotificationInput::text($title, 'notification title', 150);
        $message = NotificationInput::text($message, 'notification message', 1500);
        $path = NotificationInput::path($path);
        if ($template !== null) {
            if (!in_array($template, EmailTemplates::names(), true) || EmailTemplates::category($template) !== $category
                || array_diff(array_keys($payload), ['path', 'decision']) !== []) {
                throw new DomainException('Invalid notification email definition.', 422);
            }
            $payload['path'] = $path;
            foreach ($payload as $value) {
                if (!is_string($value) || strlen($value) > 1000) {
                    throw new DomainException('Invalid notification email payload.', 422);
                }
            }
        } elseif ($payload !== []) {
            throw new DomainException('An email payload requires an email template.', 422);
        }
        ksort($payload);
        $eventKey = hash('sha256', $key);
        $hash = hash('sha256', json_encode([$event, $category, $title, $message, $path, $template, $payload], JSON_THROW_ON_ERROR));
        $operation = static function (PDO $db) use ($actor, $recipient, $event, $category, $title, $message, $path, $template, $payload, $eventKey, $hash): int {
            $user = NotificationAccess::query($db, 'SELECT id FROM users WHERE id = ? FOR SHARE', [$recipient])->fetchColumn();
            if ($user === false) {
                throw new DomainException('This notification recipient is unavailable.', 404);
            }
            $preferences = NotificationPreferences::read($db, $recipient, true);
            $statement = NotificationAccess::query($db, 'INSERT INTO verification_notifications
                (user_id, event, title, message, category, path, event_key, payload_hash, in_app_visible)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
                [$recipient, $event, $title, $message, $category, $path, $eventKey, $hash, (int) $preferences['in_app'][$category]]);
            $new = $statement->rowCount() === 1;
            // A retry can begin before another transaction commits the source event.
            // Read the current unique-key row rather than the caller's older consistent snapshot.
            $row = NotificationAccess::query($db, 'SELECT id, payload_hash FROM verification_notifications WHERE user_id = ? AND event_key = ? FOR SHARE', [$recipient, $eventKey])->fetch();
            if ($row === false || !hash_equals($row['payload_hash'] ?? '', $hash)) {
                throw new DomainException('This notification event was already recorded with different content.', 409);
            }
            $id = (int) $row['id'];
            if ($new) {
                NotificationAccess::query($db, 'INSERT INTO audit_logs (actor_user_id, action, context) VALUES (?, ?, ?)',
                    [$actor, 'notifications.emitted', json_encode(['notification_id' => $id, 'user_id' => $recipient, 'event' => $event], JSON_THROW_ON_ERROR)]);
            }
            if ($new && $template !== null && $preferences['email'][$category]) {
                Outbox::enqueue($db, 'notification:' . $id, $recipient, $template, $payload);
            }
            return $id;
        };
        return $db->inTransaction() ? $operation($db) : Database::transaction($db, $operation);
    }
}

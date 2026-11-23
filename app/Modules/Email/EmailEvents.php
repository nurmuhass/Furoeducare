<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use PDO;
use Furoeducare\Modules\Notification\NotificationEvents;

final class EmailEvents
{
    public static function notification(PDO $db, int $notification, int $user, string $event, array $context): void
    {
        // Compatibility for existing outbox events; presentation comes from the central catalogue.
        $definition = \Furoeducare\Modules\Notification\NotificationCatalog::describe(
            $db, $user, $event, ['event_key' => 'notification:' . $notification] + $context,
        );
        $key = $event === 'messaging.new_message' ? 'message:' . (int) $context['message_id'] : 'notification:' . $notification;
        Outbox::enqueue($db, $key, $user, $definition['template'], $definition['payload']);
    }
    public static function staff(PDO $db, string $event, string $template, string $path): void
    {
        $query = $db->query("SELECT u.id FROM users u WHERE u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'admin')");
        foreach ($query->fetchAll() as $user) {
            $queryString = parse_url($path, PHP_URL_QUERY);
            $parameters = [];
            if (is_string($queryString)) {
                parse_str($queryString, $parameters);
            }
            $required = \Furoeducare\Modules\Admin\StaffRoutes::required(\Furoeducare\Core\Request::fromServer(
                ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path], [], $parameters,
            ));
            $permissions = \Furoeducare\Modules\Admin\StaffAccess::permissionsIn($db, (int) $user['id']);
            if ($required === null || array_diff($required, $permissions) !== []) {
                continue;
            }
            NotificationEvents::emit($db, null, (int) $user['id'], 'staff.' . $template, $event, EmailTemplates::category($template), 'New request to review', 'A new request is available in your management workspace.', $path, $template, ['path' => $path]);
        }
    }

}

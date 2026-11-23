<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use DomainException;
use PDO;

final class NotificationAccess
{
    public static function requireOwner(PDO $db, int $user, bool $lock = false): void
    {
        $record = self::query($db, 'SELECT email_verified_at, suspended_at FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$user])->fetch();
        $role = self::query($db, "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ? AND r.slug IN ('admin','teacher','school','parent') LIMIT 1" . ($lock ? ' FOR SHARE' : ''), [$user])->fetchColumn();
        if ($record === false || $record['email_verified_at'] === null || $record['suspended_at'] !== null || $role === false) {
            throw new DomainException('A verified, active account is required.', 403);
        }
    }

    public static function query(PDO $db, string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $db->prepare($sql);
        foreach (array_values($parameters) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $statement->execute();
        return $statement;
    }
}

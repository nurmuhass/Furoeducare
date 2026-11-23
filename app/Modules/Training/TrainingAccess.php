<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Training;

use DomainException;
use Furoeducare\Modules\Admin\StaffAccess;
use Furoeducare\Modules\School\SchoolPolicy;
use PDO;

final class TrainingAccess
{
    public static function requireRole(PDO $db, int $id, string $role, bool $lock = false): void
    {
        if ($role === 'admin' && $lock) {
            StaffAccess::requireIn($db, $id, 'training.manage', true);
        }
        $user = self::query($db, 'SELECT email_verified_at, suspended_at FROM users WHERE id = ?' . ($lock ? ' FOR SHARE' : ''), [$id])->fetch();
        $exists = self::query($db, 'SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?' . ($lock ? ' FOR SHARE' : ''), [$id, $role])->fetch();
        if ($user === false || $user['email_verified_at'] === null || $user['suspended_at'] !== null || $exists === false) {
            throw new DomainException('A verified, active ' . $role . ' account is required.', 403);
        }
    }

    public static function approvedSchool(PDO $db, int $id, bool $lock = false): void
    {
        self::requireRole($db, $id, 'school', $lock);
        $school = self::query($db, 'SELECT * FROM school_onboarding WHERE user_id = ?' . ($lock ? ' FOR SHARE' : ''), [$id])->fetch();
        if ($school === false || !SchoolPolicy::canRecruit($school)) {
            throw new DomainException('School approval is required before submitting or scheduling institutional training.', 403);
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

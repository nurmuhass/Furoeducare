<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

use Closure;
use DomainException;
use PDO;

final class StaffAccess
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function permissions(int $user): array
    {
        return self::permissionsIn(($this->database)(), $user);
    }

    public function has(int $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }

    public function require(int $user, string $permission, bool $lock = false): void
    {
        self::requireIn(($this->database)(), $user, $permission, $lock);
    }

    public static function requireIn(PDO $db, int $user, string $permission, bool $lock = false): void
    {
        if (!isset(StaffPermissions::ALL[$permission]) || !in_array($permission, self::permissionsIn($db, $user, $lock), true)) {
            throw new DomainException('Your staff permissions do not allow this action.', 403);
        }
    }

    public static function permissionsIn(PDO $db, int $user, bool $lock = false): array
    {
        $account = self::query($db, 'SELECT id FROM users WHERE id = ? AND suspended_at IS NULL AND email_verified_at IS NOT NULL'
            . ($lock ? ' FOR SHARE' : ''), [$user])->fetchColumn();
        if ($account === false) {
            return [];
        }
        $admin = self::query($db, "SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = 'admin'"
            . ($lock ? ' FOR SHARE' : ''), [$user])->fetchColumn();
        if ($admin === false) {
            return [];
        }
        $rows = self::query($db, 'SELECT p.permission FROM staff_user_roles ur JOIN staff_role_permissions p ON p.role_id = ur.role_id
            WHERE ur.user_id = ? ORDER BY p.permission' . ($lock ? ' FOR SHARE' : ''), [$user])->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_intersect(array_unique($rows), array_keys(StaffPermissions::ALL)));
    }

    public function roles(int $user): array
    {
        return self::query(($this->database)(), 'SELECT r.id, r.slug, r.name FROM staff_user_roles ur JOIN staff_roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id', [$user])->fetchAll();
    }

    /** Serialise destructive role/security decisions on the fixed Super Admin role row. */
    public static function lockRoles(PDO $db): void
    {
        if (self::query($db, "SELECT id FROM staff_roles WHERE slug = 'super_admin' FOR UPDATE")->fetchColumn() === false) {
            throw new \RuntimeException('The staff coordination role is unavailable.');
        }
    }

    public static function protectLastSuper(PDO $db, int $target): void
    {
        self::lockRoles($db);
        $supers = self::query($db, "SELECT u.id FROM staff_user_roles ur JOIN staff_roles sr ON sr.id = ur.role_id
            JOIN users u ON u.id = ur.user_id WHERE sr.slug = 'super_admin' AND u.suspended_at IS NULL AND u.email_verified_at IS NOT NULL
            AND EXISTS (SELECT 1 FROM user_roles a JOIN roles r ON r.id = a.role_id WHERE a.user_id = u.id AND r.slug = 'admin') FOR SHARE")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($target, $supers, true) && count($supers) <= 1) {
            throw new DomainException('Keep at least one active, verified Super Admin.', 409);
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

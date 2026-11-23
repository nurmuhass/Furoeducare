<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class AdminManagement
{
    private const SETTINGS_KEY = 'admin.workspace';

    public function __construct(private readonly Closure $database)
    {
    }

    public function staff(int $before, string $search = '', bool $includeCandidates = false): array
    {
        $search = self::text($search, 'search', 200, false);
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $admin = "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'admin')";
        $rows = StaffAccess::query(($this->database)(), 'SELECT u.id, u.email, u.suspended_at, u.email_verified_at, u.auth_version, '
            . $admin . ' AS is_staff FROM users u WHERE u.id < ? AND (' . $admin
            . ($includeCandidates && $search !== '' ? ' OR (u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL)' : '')
            . ") AND u.email LIKE ? ESCAPE '!' ORDER BY u.id DESC LIMIT 21", [$before, $pattern])->fetchAll();
        foreach ($rows as &$row) {
            $row['staff_roles'] = StaffAccess::query(($this->database)(), 'SELECT r.id, r.slug, r.name FROM staff_user_roles ur JOIN staff_roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id', [$row['id']])->fetchAll();
        }
        return $rows;
    }

    public function roles(): array
    {
        $db = ($this->database)();
        $roles = StaffAccess::query($db, 'SELECT id, slug, name, built_in, revision FROM staff_roles ORDER BY id')->fetchAll();
        foreach ($roles as &$role) {
            $role['permissions'] = StaffAccess::query($db, 'SELECT permission FROM staff_role_permissions WHERE role_id = ? ORDER BY permission', [$role['id']])->fetchAll(PDO::FETCH_COLUMN);
        }
        return $roles;
    }

    public function assign(int $actor, int $target, int $version, array $roleIds, string $reason, bool $confirmed): void
    {
        $reason = self::decision($reason, $confirmed);
        if ($actor === $target) {
            throw new DomainException('Another authorised administrator must change your staff assignment.', 403);
        }
        if (count($roleIds) > 20 || !array_is_list($roleIds)) {
            throw new DomainException('Choose up to twenty distinct staff roles.', 422);
        }
        $ids = [];
        foreach ($roleIds as $raw) {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || isset($ids[$id])) {
                throw new DomainException('Choose distinct valid staff roles.', 422);
            }
            $ids[$id] = $id;
        }
        sort($ids, SORT_NUMERIC);
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $target, $version, $ids, $reason): void {
            StaffAccess::lockRoles($db);
            StaffAccess::requireIn($db, $actor, 'staff.manage', true);
            $account = self::account($db, $target, $version);
            if ($account['email_verified_at'] === null || $account['suspended_at'] !== null) {
                throw new DomainException('Staff appointment requires a verified, active account.', 409);
            }
            $actorPermissions = StaffAccess::permissionsIn($db, $actor, true);
            $hasSuper = false;
            foreach ($ids as $id) {
                $role = StaffAccess::query($db, 'SELECT slug FROM staff_roles WHERE id = ? FOR SHARE', [$id])->fetchColumn();
                if ($role === false) {
                    throw new DomainException('A selected staff role is unavailable.', 422);
                }
                $permissions = StaffAccess::query($db, 'SELECT permission FROM staff_role_permissions WHERE role_id = ? FOR SHARE', [$id])->fetchAll(PDO::FETCH_COLUMN);
                self::delegation($actorPermissions, $permissions);
                $hasSuper = $hasSuper || $role === 'super_admin';
            }
            if (!$hasSuper) {
                StaffAccess::protectLastSuper($db, $target);
            }
            StaffAccess::query($db, "INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'
                ON DUPLICATE KEY UPDATE role_id = user_roles.role_id", [$target]);
            StaffAccess::query($db, 'DELETE FROM staff_user_roles WHERE user_id = ?', [$target]);
            foreach ($ids as $id) {
                StaffAccess::query($db, 'INSERT INTO staff_user_roles (user_id, role_id, granted_by) VALUES (?, ?, ?)', [$target, $id, $actor]);
            }
            StaffAccess::query($db, 'UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$target]);
            VerificationEvents::audit($db, $actor, 'admin.staff_assigned', ['user_id' => $target, 'role_ids' => $ids, 'reason' => $reason]);
        });
    }

    public function saveRole(int $actor, ?int $id, ?int $revision, string $name, array $permissions, string $reason, bool $confirmed): int
    {
        $reason = self::decision($reason, $confirmed);
        $name = self::text($name, 'role name', 100);
        if (!array_is_list($permissions)) {
            throw new DomainException('Choose valid management permissions.', 422);
        }
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !isset(StaffPermissions::ALL[$permission])) {
                throw new DomainException('Choose valid management permissions.', 422);
            }
        }
        $permissions = array_values(array_unique($permissions));
        return Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $name, $permissions, $reason): int {
            StaffAccess::lockRoles($db);
            StaffAccess::requireIn($db, $actor, 'staff.manage', true);
            self::delegation(StaffAccess::permissionsIn($db, $actor, true), $permissions);
            if ($id !== null) {
                $role = StaffAccess::query($db, 'SELECT slug, built_in, revision FROM staff_roles WHERE id = ? FOR UPDATE', [$id])->fetch();
                if ($role === false) {
                    throw new DomainException('This staff role is unavailable.', 404);
                }
                if ((bool) $role['built_in']) {
                    throw new DomainException('Built-in staff roles are protected. Create a custom role for different permissions.', 403);
                }
                if ((int) $role['revision'] !== $revision) {
                    throw new DomainException('This role changed. Reopen the editor.', 409);
                }
                if (StaffAccess::query($db, 'SELECT user_id FROM staff_user_roles WHERE user_id = ? AND role_id = ? FOR SHARE', [$actor, $id])->fetchColumn() !== false) {
                    throw new DomainException('Another authorised administrator must edit a role assigned to you.', 403);
                }
            }
            if (StaffAccess::query($db, 'SELECT id FROM staff_roles WHERE name = ? AND id <> ?', [$name, $id ?? 0])->fetchColumn() !== false) {
                throw new DomainException('Choose a distinct staff role name.', 422);
            }
            if ($id === null) {
                StaffAccess::query($db, 'INSERT INTO staff_roles (slug, name) VALUES (?, ?)', ['custom_' . bin2hex(random_bytes(12)), $name]);
                $id = (int) $db->lastInsertId();
            } else {
                StaffAccess::query($db, 'UPDATE staff_roles SET name = ?, revision = revision + 1 WHERE id = ?', [$name, $id]);
            }
            StaffAccess::query($db, 'DELETE FROM staff_role_permissions WHERE role_id = ?', [$id]);
            foreach ($permissions as $permission) {
                StaffAccess::query($db, 'INSERT INTO staff_role_permissions (role_id, permission) VALUES (?, ?)', [$id, $permission]);
            }
            StaffAccess::query($db, 'UPDATE users u JOIN staff_user_roles ur ON ur.user_id = u.id SET u.auth_version = u.auth_version + 1 WHERE ur.role_id = ?', [$id]);
            VerificationEvents::audit($db, $actor, 'admin.role_saved', ['role_id' => $id, 'permissions' => $permissions, 'reason' => $reason]);
            return $id;
        });
    }

    public function suspend(int $actor, int $target, int $version, string $action, string $reason, bool $confirmed): void
    {
        $reason = self::decision($reason, $confirmed);
        if ($actor === $target) {
            throw new DomainException('You cannot suspend or restore your own staff account.', 403);
        }
        if (!in_array($action, ['suspend', 'restore'], true)) {
            throw new DomainException('Choose an account access action.', 422);
        }
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $target, $version, $action, $reason): void {
            StaffAccess::lockRoles($db);
            StaffAccess::requireIn($db, $actor, 'accounts.manage', true);
            $account = self::account($db, $target, $version);
            $isStaff = StaffAccess::query($db, "SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = 'admin' FOR SHARE", [$target])->fetchColumn() !== false;
            if ($isStaff) {
                StaffAccess::requireIn($db, $actor, 'staff.manage', true);
            }
            if (($action === 'suspend') === ($account['suspended_at'] !== null)) {
                throw new DomainException('This account already has the requested access state.', 409);
            }
            if ($action === 'suspend') {
                StaffAccess::protectLastSuper($db, $target);
            } else {
                $teacher = StaffAccess::query($db, "SELECT user_id FROM teacher_profiles WHERE user_id = ? AND verification_status = 'suspended'", [$target])->fetchColumn();
                $school = StaffAccess::query($db, "SELECT user_id FROM school_onboarding WHERE user_id = ? AND review_status = 'suspended'", [$target])->fetchColumn();
                if ($teacher !== false || $school !== false) {
                    throw new DomainException('Resolve the verification suspension before restoring account access.', 409);
                }
            }
            StaffAccess::query($db, 'UPDATE users SET suspended_at = ' . ($action === 'suspend' ? 'UTC_TIMESTAMP(6)' : 'NULL') . ', auth_version = auth_version + 1 WHERE id = ?', [$target]);
            VerificationEvents::audit($db, $actor, 'admin.account_' . $action, ['user_id' => $target, 'reason' => $reason]);
        });
    }

    public function settings(): array
    {
        $raw = StaffAccess::query(($this->database)(), 'SELECT setting_value FROM system_settings WHERE setting_key = ?', [self::SETTINGS_KEY])->fetchColumn();
        return $raw === false ? ['admin_notice' => '', 'support_email' => '', 'revision' => 0]
            : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    public function saveSettings(int $actor, array $values, int $revision, string $reason, bool $confirmed): void
    {
        $reason = self::decision($reason, $confirmed);
        if (array_diff(array_keys($values), ['admin_notice', 'support_email']) !== []) {
            throw new DomainException('Only the administrative notice and support email can be changed.', 422);
        }
        $notice = self::text($values['admin_notice'] ?? '', 'administrative notice', 500, false);
        $email = self::text($values['support_email'] ?? '', 'support email', 254, false);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Enter a valid support email address.', 422);
        }
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $revision, $notice, $email, $reason): void {
            StaffAccess::lockRoles($db);
            StaffAccess::requireIn($db, $actor, 'settings.manage', true);
            $raw = StaffAccess::query($db, 'SELECT setting_value FROM system_settings WHERE setting_key = ? FOR UPDATE', [self::SETTINGS_KEY])->fetchColumn();
            $current = $raw === false ? 0 : json_decode($raw, true, flags: JSON_THROW_ON_ERROR)['revision'];
            if ($current !== $revision) {
                throw new DomainException('Settings changed. Reopen this form.', 409);
            }
            StaffAccess::query($db, 'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [self::SETTINGS_KEY, json_encode(['admin_notice' => $notice, 'support_email' => $email, 'revision' => $revision + 1], JSON_THROW_ON_ERROR)]);
            VerificationEvents::audit($db, $actor, 'admin.settings_updated', ['revision' => $revision + 1, 'reason' => $reason]);
        });
    }

    public function bootstrap(string $email): int
    {
        if (PHP_SAPI !== 'cli' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Use the trusted local staff bootstrap command with a verified account email.', 403);
        }
        return Database::transaction(($this->database)(), function (PDO $db) use ($email): int {
            StaffAccess::lockRoles($db);
            $existing = StaffAccess::query($db, "SELECT u.id FROM staff_user_roles ur JOIN staff_roles r ON r.id = ur.role_id JOIN users u ON u.id = ur.user_id
                WHERE r.slug = 'super_admin' AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
                    AND EXISTS (SELECT 1 FROM user_roles a JOIN roles ar ON ar.id = a.role_id WHERE a.user_id = u.id AND ar.slug = 'admin') FOR SHARE")->fetchColumn();
            if ($existing !== false) {
                throw new DomainException('An active Super Admin already exists. Use staff management.', 409);
            }
            $user = StaffAccess::query($db, 'SELECT id FROM users WHERE email = ? AND email_verified_at IS NOT NULL AND suspended_at IS NULL FOR UPDATE', [$email])->fetchColumn();
            if ($user === false) {
                throw new DomainException('Choose an existing verified, active account.', 422);
            }
            $id = (int) $user;
            StaffAccess::query($db, "INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin' ON DUPLICATE KEY UPDATE role_id = user_roles.role_id", [$id]);
            StaffAccess::query($db, "INSERT INTO staff_user_roles (user_id, role_id, granted_by) SELECT ?, id, ? FROM staff_roles WHERE slug = 'super_admin' ON DUPLICATE KEY UPDATE role_id = staff_user_roles.role_id", [$id, $id]);
            StaffAccess::query($db, 'UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$id]);
            VerificationEvents::audit($db, $id, 'admin.super_bootstrapped', ['user_id' => $id]);
            return $id;
        });
    }

    private static function account(PDO $db, int $id, int $version): array
    {
        $row = StaffAccess::query($db, 'SELECT id, email_verified_at, suspended_at, auth_version FROM users WHERE id = ? FOR UPDATE', [$id])->fetch();
        if ($row === false) {
            throw new DomainException('This account is unavailable.', 404);
        }
        if ((int) $row['auth_version'] !== $version) {
            throw new DomainException('This account changed. Reopen the management page.', 409);
        }
        return $row;
    }

    private static function delegation(array $held, array $requested): void
    {
        if (array_diff($requested, $held) !== []) {
            throw new DomainException('You can only delegate permissions you currently hold.', 403);
        }
    }

    private static function decision(string $reason, bool $confirmed): string
    {
        if (!$confirmed) {
            throw new DomainException('Confirm this sensitive administrative action.', 422);
        }
        return self::text($reason, 'decision reason', 500);
    }

    private static function text(mixed $value, string $label, int $maximum, bool $required = true): string
    {
        if (!is_string($value)) {
            throw new DomainException('Enter valid ' . $label . '.', 422);
        }
        $value = trim($value);
        if (($required && $value === '') || preg_match('/\A.{0,' . $maximum . '}\z/us', $value) !== 1 || preg_match('/[^\P{Cc}\r\n\t]/u', $value) !== 0) {
            throw new DomainException('Use up to ' . $maximum . ' characters for ' . $label . '.', 422);
        }
        return $value;
    }
}

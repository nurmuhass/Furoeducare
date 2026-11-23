<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Settings;

use Closure;
use DomainException;
use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\AuthInput;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Email\MailCrypto;
use Furoeducare\Modules\Email\Outbox;
use Furoeducare\Modules\Notification\NotificationEvents;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Teacher\TeacherRepository;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;
use PDOException;

final class AccountSettings
{
    public function __construct(private readonly Closure $database, private readonly Config $config) {}

    public function read(int $id): array
    {
        $db = ($this->database)();
        $phones = [];
        $links = [];
        $teacher = null;
        $roles = $this->query($db, 'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
        foreach (['teacher' => ['teacher_profiles', 'phone', 'Professional profile', '/dashboard/teacher?tab=profile'],
            'school' => ['school_onboarding', 'contact_phone', 'Organisation profile', '/dashboard/school?tab=profile'],
            'parent' => ['parent_profiles', 'phone', 'Parent profile', '/dashboard/parent?tab=settings']] as $role => [$table, $column, $label, $path]) {
            if (!in_array($role, $roles, true)) continue;
            $row = $this->query($db, "SELECT $column AS phone FROM $table WHERE user_id = ?", [$id])->fetch();
            if ($row === false) continue;
            $phones[] = ['role' => $role, 'label' => Role::from($role)->label(), 'phone' => $row['phone'] ?? ''];
            $links[] = compact('label', 'path');
        }
        if (in_array('teacher', $roles, true)) {
            $listing = $this->query($db, "SELECT IF(approved_at IS NOT NULL, 'approved', 'not_listed') FROM teacher_listings WHERE teacher_id = ?", [$id])->fetchColumn();
            $paused = $this->query($db, 'SELECT paused_at FROM teacher_disclosure_preferences WHERE teacher_id = ?', [$id])->fetchColumn();
            $teacher = ['public_status' => is_string($listing) ? $listing : 'Not approved for public listing',
                'disclosures_paused' => is_string($paused), 'availability_path' => '/dashboard/teacher?tab=availability'];
        }
        $contact = $this->query($db, 'SELECT display_name, phone FROM account_contact_details WHERE user_id = ?', [$id])->fetch();
        if (in_array('admin', $roles, true)) $phones[] = ['role' => 'admin', 'label' => 'Staff account', 'phone' => $contact['phone'] ?? ''];
        $pending = $this->query($db, 'SELECT c.new_email, c.expires_at FROM account_email_changes c JOIN users u ON u.id = c.user_id
            WHERE c.user_id = ? AND c.cancelled_at IS NULL AND c.consumed_at IS NULL AND c.expires_at > UTC_TIMESTAMP(6)
            AND c.auth_version = u.auth_version AND c.old_email COLLATE utf8mb4_0900_as_ci = u.email ORDER BY c.id DESC LIMIT 1', [$id])->fetch();
        $deactivation = $this->query($db, "SELECT d.id, IF(d.status = 'pending' AND p.status = 'resolved', 'reviewed', d.status) AS status,
            d.reason, d.created_at FROM account_deactivation_requests d JOIN candidate_privacy_requests p ON p.id = d.privacy_request_id
            WHERE d.user_id = ? ORDER BY d.id DESC LIMIT 1", [$id])->fetch();
        return ['phoneProfiles' => $phones, 'profileLinks' => $links, 'teacher' => $teacher,
            'pendingEmail' => $pending ?: null, 'deactivation' => $deactivation ?: null, 'staffDisplayName' => $contact['display_name'] ?? ''];
    }

    public function changePassword(int $id, int $version, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): int
    {
        SettingsInput::password($password, $confirmation);
        return Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current, $password): int {
            $user = $this->reauth($db, $id, $version, $current);
            if (password_verify($password, $user['password_hash'])) throw new SettingsValidation(['password' => 'Choose a different password.']);
            $this->query($db, 'UPDATE users SET password_hash = ?, auth_version = auth_version + 1 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            $this->invalidate($db, $id);
            $this->securityEvent($db, $id, 'account.password_changed', 'password-change:' . $id . ':' . ($version + 1), 'Password changed', 'Your password changed and other sign-in sessions ended.');
            return $version + 1;
        });
    }

    public function requestEmail(int $id, int $version, #[\SensitiveParameter] string $current, string $email): void
    {
        $email = SettingsInput::email($email);
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current, $email): void {
            $user = $this->reauth($db, $id, $version, $current);
            if ($email === $user['email'] || $this->query($db, 'SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) {
                throw new SettingsValidation(['new_email' => 'This address cannot be used. Choose another address.']);
            }
            $this->query($db, 'UPDATE account_email_changes SET cancelled_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL', [$id]);
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token, true);
            $this->query($db, 'INSERT INTO account_email_changes (user_id, old_email, new_email, auth_version, token_hash, expires_at)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE)', [$id, $user['email'], $email, $version, $hash]);
            $request = (int) $db->lastInsertId();
            $expires = $this->query($db, 'SELECT expires_at FROM account_email_changes WHERE id = ?', [$request])->fetchColumn();
            $this->securityEvent($db, $id, 'account.email_change_requested', 'email-change:' . $request, 'Email change requested', 'A change to your sign-in email was requested. Review or cancel it in account settings.');
            Outbox::enqueue($db, 'email-change-verification:' . $request, $id, 'email_change', [], $email,
                MailCrypto::encrypt($token, $this->config->mailOptions['encryption_key']), $hash, $expires);
        });
    }

    /** A GET link never consumes this challenge. It is confirmed by its owner after reauthentication. */
    public function emailChallenge(int $id, int $version, #[\SensitiveParameter] string $token): ?array
    {
        if (!AuthInput::validToken($token)) return null;
        return $this->query(($this->database)(), 'SELECT c.new_email, c.expires_at FROM account_email_changes c JOIN users u ON u.id = c.user_id
            WHERE c.user_id = ? AND c.token_hash = ? AND c.auth_version = ? AND u.auth_version = c.auth_version
            AND c.old_email COLLATE utf8mb4_0900_as_ci = u.email AND c.cancelled_at IS NULL AND c.consumed_at IS NULL AND c.expires_at > UTC_TIMESTAMP(6)
            AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL', [$id, hash('sha256', $token, true), $version])->fetch() ?: null;
    }

    public function confirmEmail(int $id, int $version, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $token): int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current, $token): int {
            $user = $this->reauth($db, $id, $version, $current);
            if (!AuthInput::validToken($token)) throw new DomainException('This email change link is unavailable or expired.', 410);
            $row = $this->query($db, 'SELECT id, new_email FROM account_email_changes WHERE user_id = ? AND token_hash = ?
                AND auth_version = ? AND old_email = ? AND consumed_at IS NULL AND cancelled_at IS NULL AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE',
                [$id, hash('sha256', $token, true), $version, $user['email']])->fetch();
            if ($row === false) throw new DomainException('This email change link is unavailable or expired.', 410);
            try {
                $this->query($db, 'UPDATE users SET email = ?, email_verified_at = UTC_TIMESTAMP(6), auth_version = auth_version + 1 WHERE id = ?', [$row['new_email'], $id]);
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
                throw new SettingsValidation(['new_email' => 'This address cannot be used. Cancel this change and choose another address.']);
            }
            $this->query($db, 'UPDATE account_email_changes SET consumed_at = UTC_TIMESTAMP(6) WHERE id = ?', [$row['id']]);
            $this->invalidate($db, $id);
            $this->securityEvent($db, $id, 'account.email_changed', 'email-confirmed:' . $row['id'], 'Email changed', 'Your verified sign-in email changed. Other sessions and security links have ended.');
            return $version + 1;
        });
    }

    public function cancelEmail(int $id, int $version, #[\SensitiveParameter] string $current): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current): void {
            $this->reauth($db, $id, $version, $current);
            $query = $this->query($db, 'UPDATE account_email_changes SET cancelled_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND cancelled_at IS NULL AND consumed_at IS NULL', [$id]);
            VerificationEvents::audit($db, $id, 'account.email_change_cancelled', ['cancelled' => $query->rowCount()]);
        });
    }

    public function updatePhone(int $id, int $version, #[\SensitiveParameter] string $current, Role $role, string $phone): void
    {
        $phone = SettingsInput::phone($phone);
        $this->requireRole(($this->database)(), $id, $role);
        $authorize = function (PDO $db) use ($id, $version, $current, $role): void { $this->reauth($db, $id, $version, $current); $this->requireRole($db, $id, $role); };
        if ($role === Role::Teacher) { (new TeacherRepository($this->database))->updateContactPhone($id, $phone, $authorize); return; }
        if ($role === Role::School) { (new SchoolRepository($this->database))->updateContactPhone($id, $phone, $authorize); return; }
        Database::transaction(($this->database)(), function (PDO $db) use ($authorize, $role, $id, $phone): void {
            $authorize($db);
            if ($role === Role::Parent) {
                if (!$this->query($db, 'SELECT 1 FROM parent_profiles WHERE user_id = ? FOR UPDATE', [$id])->fetchColumn()) {
                    throw new DomainException('This contact profile is unavailable.', 403);
                }
                $this->query($db, 'UPDATE parent_profiles SET phone = ? WHERE user_id = ?', [$phone, $id]);
            } else {
                $this->query($db, 'INSERT INTO account_contact_details (user_id, phone) VALUES (?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone)', [$id, $phone]);
            }
            VerificationEvents::audit($db, $id, 'account.phone_updated', ['profile_role' => $role->value]);
        });
    }

    public function updateStaffName(int $id, int $version, #[\SensitiveParameter] string $current, string $name): void
    {
        $name = SettingsInput::name($name);
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current, $name): void {
            $this->reauth($db, $id, $version, $current);
            $this->requireRole($db, $id, Role::Admin);
            $this->query($db, 'INSERT INTO account_contact_details (user_id, display_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)', [$id, $name]);
            VerificationEvents::audit($db, $id, 'account.profile_updated', ['profile_role' => 'admin']);
        });
    }

    public function requestDeactivation(int $id, int $version, #[\SensitiveParameter] string $current, string $reason, bool $confirmed): void
    {
        $reason = SettingsInput::reason($reason);
        if (!$confirmed) throw new SettingsValidation(['confirmed' => 'Confirm that you want to submit this request.']);
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current, $reason): void {
            $this->reauth($db, $id, $version, $current);
            $this->query($db, "UPDATE account_deactivation_requests d JOIN candidate_privacy_requests p ON p.id = d.privacy_request_id
                SET d.status = 'reviewed' WHERE d.user_id = ? AND d.status = 'pending' AND p.status = 'resolved'", [$id]);
            if ($this->query($db, "SELECT 1 FROM account_deactivation_requests WHERE user_id = ? AND status = 'pending'", [$id])->fetchColumn()) {
                throw new DomainException('You already have a pending deactivation request.', 409);
            }
            $this->query($db, "INSERT INTO candidate_privacy_requests (account_id, right_type, details) VALUES (?, 'restriction', ?)", [$id, 'Account deactivation requested. Reason: ' . $reason]);
            $privacy = (int) $db->lastInsertId();
            $this->query($db, 'INSERT INTO account_deactivation_requests (user_id, privacy_request_id, reason) VALUES (?, ?, ?)', [$id, $privacy, $reason]);
            $request = (int) $db->lastInsertId();
            $this->securityEvent($db, $id, 'account.deactivation_requested', 'deactivation:' . $request, 'Deactivation requested', 'Management will review your request. Your account remains active until a decision is implemented.');
            VerificationEvents::audit($db, $id, 'privacy.request_submitted', ['request_id' => $privacy, 'type' => 'restriction']);
        });
    }

    public function cancelDeactivation(int $id, int $version, #[\SensitiveParameter] string $current): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $version, $current): void {
            $this->reauth($db, $id, $version, $current);
            $row = $this->query($db, "SELECT d.id, d.privacy_request_id FROM account_deactivation_requests d JOIN candidate_privacy_requests p ON p.id = d.privacy_request_id
                WHERE d.user_id = ? AND d.status = 'pending' AND p.status = 'submitted' FOR UPDATE", [$id])->fetch();
            if ($row === false) throw new DomainException('There is no pending request available to cancel.', 409);
            $this->query($db, "UPDATE account_deactivation_requests SET status = 'cancelled' WHERE id = ?", [$row['id']]);
            $this->query($db, "UPDATE candidate_privacy_requests SET status = 'resolved', staff_note = 'Cancelled by the account holder.', closed_by = ?, closed_at = UTC_TIMESTAMP(6) WHERE id = ?", [$id, $row['privacy_request_id']]);
            VerificationEvents::audit($db, $id, 'account.deactivation_cancelled', ['request_id' => (int) $row['id']]);
        });
    }

    private function reauth(PDO $db, int $id, int $version, #[\SensitiveParameter] string $password): array
    {
        $row = $this->query($db, 'SELECT email, password_hash, email_verified_at, suspended_at, auth_version FROM users WHERE id = ? FOR UPDATE', [$id])->fetch();
        if ($row === false || $row['suspended_at'] !== null || $row['email_verified_at'] === null || (int) $row['auth_version'] !== $version) {
            throw new DomainException('Your account session changed. Sign in again before changing settings.', 403);
        }
        if (!AuthInput::canVerifyPassword($password) || !password_verify($password, $row['password_hash'])) {
            throw new SettingsValidation(['current_password' => 'Your current password is incorrect.']);
        }
        return $row;
    }

    private function requireRole(PDO $db, int $id, Role $role): void
    {
        if (!$this->query($db, 'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ? FOR SHARE', [$id, $role->value])->fetchColumn()) {
            throw new DomainException('This profile does not belong to your account.', 403);
        }
    }

    private function invalidate(PDO $db, int $id): void
    {
        foreach (['password_reset_tokens', 'email_verification_tokens'] as $table) $this->query($db, "UPDATE $table SET consumed_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND consumed_at IS NULL", [$id]);
        $this->query($db, 'UPDATE account_email_changes SET cancelled_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL', [$id]);
    }

    private function securityEvent(PDO $db, int $id, string $event, string $key, string $title, string $message): void
    {
        VerificationEvents::audit($db, $id, $event, ['source' => $key]);
        NotificationEvents::emit($db, $id, $id, $event, $key, 'account', $title, $message, '/settings/account', 'security_alert', ['path' => '/settings/account']);
    }

    private function query(PDO $db, string $sql, array $values): \PDOStatement
    {
        $query = $db->prepare($sql); $query->execute($values); return $query;
    }
}

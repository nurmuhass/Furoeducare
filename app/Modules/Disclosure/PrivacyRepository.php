<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Disclosure;

use Furoeducare\Modules\Admin\StaffAccess;

use Closure;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class PrivacyRepository
{
    public const PAGE_SIZE = 20;

    public function __construct(private readonly Closure $database)
    {
    }

    public function pausedAt(int $teacher): ?string
    {
        $query = ($this->database)()->prepare('SELECT paused_at FROM teacher_disclosure_preferences WHERE teacher_id = ?');
        $query->execute([$teacher]);
        return $query->fetch()['paused_at'] ?? null;
    }

    public function setPaused(int $teacher, bool $paused): void
    {
        Database::transaction(($this->database)(), static function (PDO $db) use ($teacher, $paused): void {
            // Sharing and pause transactions take the same teacher lock before changing preferences or grants.
            $teacherLock = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $teacherLock->execute([$teacher]);
            if ($teacherLock->fetchColumn() === false) {
                return;
            }

            if (!$paused) {
                $resume = $db->prepare('UPDATE teacher_disclosure_preferences SET paused_at = NULL WHERE teacher_id = ? AND paused_at IS NOT NULL');
                $resume->execute([$teacher]);
                if ($resume->rowCount() > 0) {
                    VerificationEvents::audit($db, $teacher, 'disclosure.resumed', ['teacher_id' => $teacher]);
                }
                return;
            }

            $pause = $db->prepare(<<<'SQL'
                INSERT INTO teacher_disclosure_preferences (teacher_id, paused_at) VALUES (?, UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE paused_at = COALESCE(paused_at, UTC_TIMESTAMP(6))
                SQL);
            $pause->execute([$teacher]);
            $revoke = $db->prepare(<<<'SQL'
                UPDATE candidate_disclosure_grants
                SET revoked_at = UTC_TIMESTAMP(6), revoked_by = ?, revocation_reason = 'Teacher paused disclosures.'
                WHERE teacher_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6)
                SQL);
            $revoke->execute([$teacher, $teacher]);
            if ($pause->rowCount() > 0 || $revoke->rowCount() > 0) {
                VerificationEvents::audit($db, $teacher, 'disclosure.paused', [
                    'teacher_id' => $teacher, 'revoked_grants' => $revoke->rowCount(),
                ]);
            }
        });
    }

    public function grants(int $teacher, int $before): array
    {
        $query = ($this->database)()->prepare(<<<'SQL'
            SELECT g.id, r.reference, g.scope, g.created_at, g.expires_at, g.revoked_at, g.policy_version,
                   (g.expires_at <= UTC_TIMESTAMP(6)) AS expired, c.requester_role, c.purpose
            FROM candidate_disclosure_grants g
            JOIN candidate_disclosure_requests r ON r.id = g.disclosure_id
            JOIN candidate_requests c ON c.id = r.case_id
            WHERE g.teacher_id = ? AND g.id < ?
            ORDER BY g.id DESC LIMIT ?
            SQL);
        $query->bindValue(1, $teacher, PDO::PARAM_INT);
        $query->bindValue(2, $before, PDO::PARAM_INT);
        $query->bindValue(3, self::PAGE_SIZE + 1, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function ownRequests(int $owner, int $before): array
    {
        return $this->requests('account_id', $owner, $before);
    }

    public function queue(string $status, int $before): array
    {
        return $this->requests('status', $status, $before);
    }

    private function requests(string $field, int|string $value, int $before): array
    {
        $query = ($this->database)()->prepare(
            'SELECT id, right_type, status, created_at FROM candidate_privacy_requests WHERE ' . $field
            . ' = ? AND id < ? ORDER BY id DESC LIMIT ?'
        );
        $query->bindValue(1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $query->bindValue(2, $before, PDO::PARAM_INT);
        $query->bindValue(3, self::PAGE_SIZE + 1, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function request(int $id, ?int $owner): ?array
    {
        $query = ($this->database)()->prepare(<<<'SQL'
            SELECT id, account_id, right_type, details, status, staff_note, created_at, closed_at
            FROM candidate_privacy_requests WHERE id = ?
            SQL . ($owner === null ? '' : ' AND account_id = ?'));
        $query->execute($owner === null ? [$id] : [$id, $owner]);
        return $query->fetch() ?: null;
    }

    public function submit(int $owner, string $type, string $details): int
    {
        return Database::transaction(($this->database)(), static function (PDO $db) use ($owner, $type, $details): int {
            $db->prepare('INSERT INTO candidate_privacy_requests (account_id, right_type, details) VALUES (?, ?, ?)')
                ->execute([$owner, $type, $details]);
            $id = (int) $db->lastInsertId();
            VerificationEvents::audit($db, $owner, 'privacy.request_submitted', ['request_id' => $id, 'type' => $type]);
            return $id;
        });
    }

    public function resolve(int $staff, int $id, string $note): string
    {
        return Database::transaction(($this->database)(), static function (PDO $db) use ($staff, $id, $note): string {
            StaffAccess::requireIn($db, $staff, 'privacy.manage', true);
            $query = $db->prepare('SELECT account_id, status FROM candidate_privacy_requests WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $row = $query->fetch();
            if ($row === false) {
                return 'missing';
            }
            if ((int) $row['account_id'] === $staff) {
                return 'own';
            }
            if ($row['status'] !== 'submitted') {
                return 'resolved';
            }
            $db->prepare(<<<'SQL'
                UPDATE candidate_privacy_requests
                SET status = 'resolved', staff_note = ?, closed_by = ?, closed_at = UTC_TIMESTAMP(6)
                WHERE id = ?
                SQL)->execute([$note, $staff, $id]);
            VerificationEvents::audit($db, $staff, 'privacy.request_resolved', [
                'request_id' => $id, 'account_id' => (int) $row['account_id'],
            ]);
            return 'saved';
        });
    }
}

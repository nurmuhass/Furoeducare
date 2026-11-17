<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Closure;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Disclosure\CandidateRepository;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\School\SchoolStatus;
use PDO;

final class DirectoryInteractions
{
    public function __construct(
        private readonly Closure $database,
        private readonly PublicDirectory $directory,
        private readonly ParentRepository $parents,
        private readonly SchoolRepository $schools,
    ) {
    }

    public function membership(array $viewer, bool $lock = false): ?array
    {
        $id = $viewer['id'];
        if (in_array(Role::Parent, $viewer['roles'], true) && $this->parents->profile($id) !== null) {
            return ['role' => Role::Parent, 'canSubmit' => true];
        }
        if (in_array(Role::School, $viewer['roles'], true)) {
            $school = $this->schools->find($id, lock: $lock);
            if ($school !== null && SchoolPolicy::profileComplete($school) && SchoolStatus::forSchool($school) !== SchoolStatus::Suspended) {
                return ['role' => Role::School, 'canSubmit' => SchoolPolicy::canRecruit($school)];
            }
        }
        return null;
    }

    public function saveBookmark(int $owner, string $reference, bool $save): string
    {
        return $this->withVisibleTarget($owner, $reference, static function (PDO $db, int $listing) use ($owner, $save): string {
            $sql = $save
                ? 'INSERT INTO saved_teachers (user_id, listing_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE listing_id = listing_id'
                : 'DELETE FROM saved_teachers WHERE user_id = ? AND listing_id = ?';
            $db->prepare($sql)->execute([$owner, $listing]);
            return 'saved';
        });
    }

    public function saveRequest(int $owner, string $reference, string $notes): string
    {
        return $this->withVisibleTarget($owner, $reference, static function (PDO $db, int $listing, array $membership) use ($owner, $notes): string {
            if ($membership['role'] !== Role::School) {
                return 'forbidden';
            }
            $query = $db->prepare('SELECT id, requester_role, submitted_at FROM teacher_listing_requests WHERE requester_id = ? AND listing_id = ? FOR UPDATE');
            $query->execute([$owner, $listing]);
            $existing = $query->fetch();
            if ($existing !== false && $existing['requester_role'] === 'school' && $existing['submitted_at'] !== null) {
                return 'already_submitted';
            }
            $canSubmit = (int) $membership['canSubmit'];
            $role = $membership['role']->value;
            if ($existing === false) {
                $db->prepare('INSERT INTO teacher_listing_requests (requester_id, listing_id, requester_role, notes, submitted_at)
                    VALUES (?, ?, ?, ?, IF(?, CURRENT_TIMESTAMP(6), NULL))')
                    ->execute([$owner, $listing, $role, $notes, $canSubmit]);
            } else {
                $db->prepare('UPDATE teacher_listing_requests SET requester_role = ?, notes = ?, submitted_at = IF(?, CURRENT_TIMESTAMP(6), NULL)
                    WHERE id = ? AND requester_id = ?')->execute([$role, $notes, $canSubmit, $existing['id'], $owner]);
            }
            CandidateRepository::mirrorSchoolEnquiry($db, $owner, $listing);
            return $canSubmit ? 'submitted' : 'draft';
        });
    }

    public function request(int $owner, string $reference): ?array
    {
        $query = ($this->database)()->prepare("SELECT r.notes, r.submitted_at FROM teacher_listing_requests r
            JOIN teacher_listings l ON l.id = r.listing_id WHERE r.requester_id = ? AND l.reference = ? AND r.requester_role = 'school'");
        $query->execute([$owner, $reference]);
        return $query->fetch() ?: null;
    }

    public function requests(?int $owner, int $before): array
    {
        $sql = 'SELECT r.id, r.notes, r.requester_role, r.submitted_at, l.reference';
        if ($owner === null) {
            $sql .= ', u.email';
        }
        $sql .= " FROM teacher_listing_requests r JOIN teacher_listings l ON l.id = r.listing_id
            JOIN users u ON u.id = r.requester_id WHERE r.id < ? AND r.requester_role = 'school' AND "
            . ($owner === null ? 'r.submitted_at IS NOT NULL' : 'r.requester_id = ?')
            . ' ORDER BY r.id DESC LIMIT 31';
        $query = ($this->database)()->prepare($sql);
        $query->execute($owner === null ? [$before] : [$before, $owner]);
        return $query->fetchAll();
    }

    private function withVisibleTarget(int $owner, string $reference, Closure $operation): string
    {
        $db = ($this->database)();
        $lookup = $db->prepare('SELECT teacher_id FROM teacher_listings WHERE reference = ?');
        $lookup->execute([$reference]);
        $teacher = $lookup->fetchColumn();
        if ($teacher === false) {
            return 'missing';
        }
        if ((int) $teacher === $owner) {
            return 'forbidden';
        }

        return Database::transaction($db, function (PDO $db) use ($owner, $teacher, $reference, $operation): string {
            $account = $db->prepare('SELECT id, suspended_at, email_verified_at FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $account->execute([$owner, $teacher]);
            $users = array_column($account->fetchAll(), null, 'id');
            if (!isset($users[$owner]) || $users[$owner]['suspended_at'] !== null || $users[$owner]['email_verified_at'] === null) {
                return 'forbidden';
            }

            $roles = $db->prepare('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
            $roles->execute([$owner]);
            $current = array_intersect($roles->fetchAll(PDO::FETCH_COLUMN), ['parent', 'school']);
            $membership = $this->membership(['id' => $owner, 'roles' => array_map(Role::from(...), $current)], lock: true);
            if ($membership === null) {
                return 'forbidden';
            }
            $target = $this->directory->lockTarget($reference);
            if ($target === null || (int) $target['teacher_id'] !== (int) $teacher) {
                return 'missing';
            }
            return $operation($db, (int) $target['id'], $membership);
        });
    }
}

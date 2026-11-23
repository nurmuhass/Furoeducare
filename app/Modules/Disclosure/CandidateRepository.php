<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Disclosure;

use Furoeducare\Modules\Admin\StaffAccess;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Email\EmailEvents;
use Furoeducare\Modules\Directory\PublicDirectory;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class CandidateRepository
{
    public function __construct(
        private readonly Closure $database,
        private readonly PublicDirectory $directory,
        private readonly SchoolRepository $schools,
        private readonly ParentRepository $parents,
    ) {
    }

    public static function reference(string $value): ?string
    {
        return preg_match('/\A(?:FT-[A-F0-9]{20}|CA-[A-F0-9]{32})\z/', $value) === 1 ? $value : null;
    }

    public static function mirrorTutoring(PDO $db, int $id): void
    {
        $db->prepare("INSERT INTO candidate_requests (requester_id, requester_role, category, selected_teacher_id, selected_reference,
            purpose, requirements, tutoring_id)
            SELECT t.parent_id, 'parent', 'tutoring', l.teacher_id, t.selected_teacher_reference,
                'Tutoring placement', 'See the linked tutoring request.', t.id
            FROM parent_tutoring_requests t LEFT JOIN teacher_listings l ON l.reference = t.selected_teacher_reference
            WHERE t.id = ? AND t.status = 'submitted' ON DUPLICATE KEY UPDATE tutoring_id = candidate_requests.tutoring_id")->execute([$id]);
    }

    public static function closeTutoring(PDO $db, int $id): void
    {
        $db->prepare("UPDATE candidate_requests SET status = 'closed' WHERE tutoring_id = ?")->execute([$id]);
    }

    public static function mirrorSchoolEnquiry(PDO $db, int $owner, int $listing): void
    {
        $db->prepare("INSERT INTO candidate_requests (requester_id, requester_role, category, selected_teacher_id, selected_reference,
            purpose, requirements, status, legacy_directory_id)
            SELECT r.requester_id, 'school', 'recruitment', l.teacher_id, l.reference, 'Teacher recruitment', r.notes,
                IF(r.submitted_at IS NULL, 'draft', 'submitted'), r.id
            FROM teacher_listing_requests r JOIN teacher_listings l ON l.id = r.listing_id
            WHERE r.requester_id = ? AND r.listing_id = ? AND r.requester_role = 'school'
            ON DUPLICATE KEY UPDATE requirements = IF(candidate_requests.status = 'draft', r.notes, candidate_requests.requirements)")
            ->execute([$owner, $listing]);
    }

    public function eligible(int $user, Role $role, bool $lock = false): bool
    {
        if (!in_array($role, [Role::School, Role::Parent], true) || !$this->hasRole($user, $role, $lock)) {
            return false;
        }
        if ($role === Role::Parent) {
            return $this->parents->profile($user) !== null;
        }
        $school = $this->schools->find($user, lock: $lock);
        return $school !== null && SchoolPolicy::canRecruit($school);
    }

    public function ownerCases(int $owner, Role $role, int $before): array
    {
        return $this->read('SELECT * FROM candidate_requests WHERE requester_id = ? AND requester_role = ? AND id < ? ORDER BY id DESC LIMIT 21',
            [$owner, $role->value, $before]);
    }

    public function ownerCase(int $owner, Role $role, int $id): ?array
    {
        return $this->read('SELECT * FROM candidate_requests WHERE id = ? AND requester_id = ? AND requester_role = ?',
            [$id, $owner, $role->value])[0] ?? null;
    }

    public function staffCases(int $before, bool $pendingDisclosure): array
    {
        $filter = $pendingDisclosure ? ' HAVING disclosure_pending = 1' : '';
        return $this->read("SELECT c.*,
            (c.status NOT IN ('draft', 'closed') AND EXISTS (
                SELECT 1 FROM candidate_disclosure_requests d WHERE d.case_id = c.id AND d.status = 'pending'
            )) AS disclosure_pending
            FROM candidate_requests c WHERE c.id < ? AND c.status <> 'draft'"
            . $filter . ' ORDER BY c.id DESC LIMIT 21', [$before]);
    }

    public function staffCase(int $id): ?array
    {
        return $this->read("SELECT * FROM candidate_requests WHERE id = ? AND status <> 'draft'", [$id])[0] ?? null;
    }

    public function vacancies(int $school): array
    {
        return $this->read("SELECT id, content->>'$.title' AS title FROM vacancies
            WHERE school_id = ? AND status <> 'draft' ORDER BY id DESC LIMIT 100", [$school]);
    }

    public function create(int $owner, Role $role, int $vacancy, ?string $ref, string $purpose, string $requirements): int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($owner, $role, $vacancy, $ref, $purpose, $requirements): int {
            $teacher = $ref === null ? null : ($this->read('SELECT teacher_id FROM teacher_listings WHERE reference = ?', [$ref])[0] ?? null);
            $teacherId = $teacher === null ? null : (int) $teacher['teacher_id'];
            $this->lockAccounts($owner, $teacherId);
            if ($role !== Role::School || !$this->eligible($owner, $role, lock: true)) {
                throw new DomainException('An approved school account is required.', 403);
            }
            $this->ownVacancy($owner, $vacancy);
            if ($ref !== null && ($teacherId === null || $teacherId === $owner
                || (int) ($this->directory->lockTarget($ref)['teacher_id'] ?? 0) !== $teacherId)) {
                throw new DomainException('This teacher is unavailable in the directory.', 404);
            }
            $db->prepare("INSERT INTO candidate_requests (requester_id, requester_role, category, vacancy_id, selected_teacher_id,
                selected_reference, purpose, requirements) VALUES (?, 'school', 'recruitment', ?, ?, ?, ?, ?)")
                ->execute([$owner, $vacancy, $teacherId, $ref, $purpose, $requirements]);
            $id = (int) $db->lastInsertId();
            VerificationEvents::record($db, $owner, $owner, 'candidate.submitted', ['case_id' => $id, 'event_key' => 'candidate:submitted:' . $id], 'Candidate request submitted', 'Your candidate request has been submitted. Open Candidate Requests to review its progress.');
            EmailEvents::staff($db, 'candidate:' . $id, 'new_teacher_request', '/dashboard/admin/candidate-requests?id=' . $id);
            return $id;
        });
    }

    public function link(int $owner, Role $role, int $id, int $vacancy, string $purpose, string $requirements): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($owner, $role, $id, $vacancy, $purpose, $requirements): void {
            $this->lockAccounts($owner);
            $case = $this->lockCase($id);
            if ((int) $case['requester_id'] !== $owner || $case['requester_role'] !== $role->value
                || $case['category'] !== 'recruitment' || $role !== Role::School) {
                throw self::unavailable();
            }
            if (!$this->eligible($owner, $role, lock: true)) {
                throw new DomainException('An approved school account is required.', 403);
            }
            if ($case['vacancy_id'] !== null || $case['legacy_directory_id'] === null || !in_array($case['status'], ['draft', 'submitted'], true)) {
                throw new DomainException('This case cannot be linked again.', 409);
            }
            $this->ownVacancy($owner, $vacancy);
            $db->prepare("UPDATE candidate_requests SET vacancy_id = ?, purpose = ?, requirements = ?, status = 'submitted' WHERE id = ?")
                ->execute([$vacancy, $purpose === '' ? $case['purpose'] : $purpose, $requirements === '' ? $case['requirements'] : $requirements, $id]);
            VerificationEvents::record($db, $owner, $owner, 'candidate.vacancy_linked', ['case_id' => $id, 'vacancy_id' => $vacancy, 'event_key' => 'candidate:linked:' . $id], 'Candidate request linked', 'Your candidate request is ready for review. Open Candidate Requests to review its progress.');
            EmailEvents::staff($db, 'candidate:' . $id, 'new_teacher_request', '/dashboard/admin/candidate-requests?id=' . $id);
        });
    }

    public function shortlist(array $case, bool $staff): array
    {
        if (!$staff && $case['shortlisted_at'] === null) {
            return [];
        }
        $rows = $this->read('SELECT reference FROM candidate_shortlists WHERE case_id = ? ORDER BY created_at, teacher_id', [$case['id']]);
        return array_map(fn (array $row): array => $row + [
            'title' => $this->directory->find($row['reference'])['title'] ?? null,
        ], $rows);
    }

    public function options(array $case): array
    {
        $rows = [];
        if ($case['selected_reference'] !== null && $case['selected_teacher_id'] !== null) {
            $rows[] = ['reference' => $case['selected_reference'], 'title' => 'Requested teacher'];
        }
        if ($case['vacancy_id'] !== null) {
            $rows = [...$rows, ...$this->read("SELECT candidate_reference AS reference, 'Vacancy applicant' AS title
                FROM vacancy_applications WHERE vacancy_id = ? AND status NOT IN ('unsuccessful','withdrawn') ORDER BY id DESC LIMIT 40",
                [$case['vacancy_id']])];
        }
        if ($case['tutoring_id'] !== null) {
            $rows = [...$rows, ...$this->read('SELECT teacher_reference AS reference, title
                FROM tutoring_candidates WHERE request_id = ? ORDER BY created_at', [$case['tutoring_id']])];
        }
        return array_values(array_column($rows, null, 'reference'));
    }

    public function manage(int $admin, int $id, string $action, ?string $ref): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($admin, $id, $action, $ref): void {
            StaffAccess::requireIn($db, $admin, 'candidates.manage', true);
            $this->requireAdmin($admin, lock: true);
            $case = $this->lockCase($id);
            if (in_array($case['status'], ['draft', 'closed'], true)) {
                throw self::unavailable();
            }
            if ($action === 'claim') {
                $this->requireContext($case);
                if ((int) ($case['assigned_admin_id'] ?? 0) === $admin && $case['status'] !== 'submitted') {
                    return;
                }
                $db->prepare("UPDATE candidate_requests SET assigned_admin_id = ?,
                    status = IF(status = 'submitted', 'under_review', status) WHERE id = ?")->execute([$admin, $id]);
            } else {
                $this->requireAssigned($case, $admin);
                if ($action === 'add' && $case['status'] === 'under_review' && $ref !== null) {
                    $this->requireContext($case);
                    $this->addToShortlist($case, $admin, $ref);
                } elseif ($action === 'publish' && $case['status'] === 'under_review') {
                    $this->requireContext($case);
                    if ($this->read('SELECT teacher_id FROM candidate_shortlists WHERE case_id = ? LIMIT 1', [$id]) === []) {
                        throw new DomainException('Add a candidate before releasing the shortlist.', 409);
                    }
                    $db->prepare("UPDATE candidate_requests SET status = 'shortlisted', shortlisted_at = CURRENT_TIMESTAMP(6) WHERE id = ?")
                        ->execute([$id]);
                } elseif ($action === 'close') {
                    $db->prepare("UPDATE candidate_requests SET status = 'closed' WHERE id = ?")->execute([$id]);
                } else {
                    throw new DomainException('This action is unavailable for the current case.', 409);
                }
            }
            VerificationEvents::audit($db, $admin, 'candidate.' . $action, ['case_id' => $id, 'reference' => $ref]
                + ($action === 'claim' ? ['previous_assignee' => $case['assigned_admin_id']] : []));
            if (in_array($action, ['claim', 'publish', 'close'], true)) {
                VerificationEvents::record($db, $admin, (int) $case['requester_id'], 'candidate.' . $action,
                    ['case_id' => $id, 'event_key' => 'candidate:audit:' . (int) $db->lastInsertId()],
                    'Candidate request update', 'Your candidate request has an update. Open Candidate Requests to review its progress.');
            }
        });
    }

    private function addToShortlist(array $case, int $admin, string $ref): void
    {
        $teacher = null;
        if ($ref === $case['selected_reference'] && $case['selected_teacher_id'] !== null) {
            $teacher = $case['selected_teacher_id'];
        } elseif (str_starts_with($ref, 'CA-') && $case['vacancy_id'] !== null) {
            $teacher = $this->read("SELECT teacher_id FROM vacancy_applications WHERE vacancy_id = ? AND candidate_reference = ?
                AND status NOT IN ('unsuccessful','withdrawn')", [$case['vacancy_id'], $ref])[0]['teacher_id'] ?? null;
        } elseif ($this->directory->find($ref) !== null) {
            $teacher = $this->read('SELECT teacher_id FROM teacher_listings WHERE reference = ?', [$ref])[0]['teacher_id'] ?? null;
        }
        if ($teacher === null || (int) $teacher === (int) $case['requester_id'] || !$this->hasRole((int) $teacher, Role::Teacher)) {
            throw new DomainException('Choose an eligible directory teacher or a candidate from the linked request.', 404);
        }
        if (count($this->read('SELECT teacher_id FROM candidate_shortlists WHERE case_id = ? LIMIT 21', [$case['id']])) >= 20) {
            throw new DomainException('The shortlist can contain up to 20 candidates.', 422);
        }
        ($this->database)()->prepare('INSERT INTO candidate_shortlists (case_id, teacher_id, reference, added_by)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reference = candidate_shortlists.reference')
            ->execute([$case['id'], $teacher, $ref, $admin]);
    }

    public function lockAccounts(int $owner, ?int $teacher = null, ?int $admin = null): void
    {
        $locks = [$owner => 'UPDATE'];
        if ($teacher !== null) {
            $locks[$teacher] = 'UPDATE';
        }
        if ($admin !== null) {
            $locks[$admin] ??= 'SHARE';
        }
        ksort($locks, SORT_NUMERIC);
        foreach ($locks as $user => $mode) {
            $this->read("SELECT id FROM users WHERE id = ? FOR $mode", [$user]);
        }
    }

    public function requireAdmin(int $id, bool $lock = false): void
    {
        if (!$this->hasRole($id, Role::Admin, $lock)) {
            throw new DomainException('An active administrator account is required.', 403);
        }
    }

    public function hasRole(int $id, Role $role, bool $lock = false): bool
    {
        return $this->read('SELECT u.id FROM users u WHERE u.id = ? AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = ?)'
            . ($lock ? ' FOR SHARE' : ''), [$id, $role->value]) !== [];
    }

    public function lockCase(int $id): array
    {
        return $this->read('SELECT * FROM candidate_requests WHERE id = ? FOR UPDATE', [$id])[0] ?? throw self::unavailable();
    }

    public function requireAssigned(array $case, int $admin): void
    {
        if ((int) ($case['assigned_admin_id'] ?? 0) !== $admin) {
            throw new DomainException('Claim this case before taking action.', 403);
        }
    }

    public function requireContext(array $case): void
    {
        if ($case['category'] === 'recruitment' && $case['vacancy_id'] !== null) {
            $this->ownVacancy((int) $case['requester_id'], (int) $case['vacancy_id']);
            return;
        }
        if ($case['category'] === 'tutoring' && $case['tutoring_id'] !== null
            && $this->read("SELECT id FROM parent_tutoring_requests WHERE id = ? AND parent_id = ? AND status NOT IN ('draft','closed')",
                [$case['tutoring_id'], $case['requester_id']]) !== []) {
            return;
        }
        throw new DomainException('Link an eligible request owned by this account to continue.', 409);
    }

    public function disclosureTeacher(array $case, string $reference): ?int
    {
        if ($case['selected_reference'] === $reference && $case['selected_teacher_id'] !== null) {
            return (int) $case['selected_teacher_id'];
        }
        if ($case['shortlisted_at'] === null) {
            return null;
        }
        $teacher = $this->read('SELECT teacher_id FROM candidate_shortlists WHERE case_id = ? AND reference = ?',
            [$case['id'], $reference])[0]['teacher_id'] ?? null;
        if ($teacher !== null && str_starts_with($reference, 'CA-') && $this->read("SELECT id FROM vacancy_applications
            WHERE vacancy_id = ? AND candidate_reference = ? AND teacher_id = ? AND status NOT IN ('unsuccessful', 'withdrawn')",
            [$case['vacancy_id'], $reference, $teacher]) === []) {
            return null;
        }
        return $teacher === null ? null : (int) $teacher;
    }

    private function ownVacancy(int $owner, int $vacancy): void
    {
        if ($this->read("SELECT id FROM vacancies WHERE id = ? AND school_id = ? AND status <> 'draft'", [$vacancy, $owner]) === []) {
            throw new DomainException('This vacancy is unavailable to this account.', 404);
        }
    }

    public static function unavailable(): DomainException
    {
        return new DomainException('This candidate request is unavailable.', 404);
    }

    private function read(string $sql, array $values): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($values);
        return $query->fetchAll();
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Disclosure;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Core\Response;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Teacher\TeacherDocuments;
use Furoeducare\Modules\Teacher\TeacherRepository;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class DisclosureRepository
{
    public function __construct(
        private readonly Closure $database,
        private readonly CandidateRepository $cases,
        private readonly DisclosurePolicy $policy,
        private readonly TeacherRepository $profiles,
        private readonly TeacherDocuments $documents,
    ) {
    }

    public function forCase(int $case, bool $staff): array
    {
        $columns = $staff ? ', r.reason, r.notice_version, g.evidence_reference' : '';
        $order = $staff ? "(r.status = 'pending') DESC, r.id DESC" : 'r.id DESC';
        return $this->read('SELECT r.id, r.reference, r.scope, r.status, g.id AS grant_id, g.expires_at, g.revoked_at,
            g.expires_at > UTC_TIMESTAMP(6) AS unexpired' . $columns . '
            FROM candidate_disclosure_requests r LEFT JOIN candidate_disclosure_grants g ON g.disclosure_id = r.id
            WHERE r.case_id = ? ORDER BY ' . $order . ' LIMIT 100', [$case]);
    }

    public function preview(int $case, int $disclosure): ?array
    {
        $row = $this->disclosure($case, $disclosure);
        if ($row === null) {
            return null;
        }
        $teacher = (int) $row['teacher_id'];
        $profile = $row['scope'] === 'professional' ? $this->profiles->profile($teacher) : null;
        return [
            'document_id' => $row['scope'] === 'original_cv' ? $this->currentCv($teacher) : null,
            'profile_revision' => $profile['verification_revision'] ?? null,
            'profile' => $profile === null ? ['qualifications' => [], 'experience' => []] : self::snapshot($profile),
        ];
    }

    public function request(int $owner, Role $role, int $id, string $ref, string $scope, bool $ack, string $notice): void
    {
        if (!in_array($scope, ['professional', 'original_cv'], true) || !$ack) {
            throw new DomainException('Choose a disclosure scope and acknowledge the purpose and privacy notice.', 422);
        }
        $version = $this->policy->active() ? $this->policy->version : null;
        if ($notice !== ($version ?? '')) {
            throw new DomainException('The privacy notice changed. Reopen the case and review the current notice.', 409);
        }
        $case = $this->cases->ownerCase($owner, $role, $id) ?? throw CandidateRepository::unavailable();
        $teacher = $this->cases->disclosureTeacher($case, $ref) ?? throw CandidateRepository::unavailable();
        Database::transaction(($this->database)(), function (PDO $db) use ($owner, $role, $id, $ref, $scope, $teacher, $version): void {
            $this->cases->lockAccounts($owner, $teacher);
            $case = $this->cases->lockCase($id);
            $this->requireOwner($case, $owner, $role);
            if ($this->cases->disclosureTeacher($case, $ref) !== $teacher || $teacher === $owner) {
                throw CandidateRepository::unavailable();
            }
            $existing = $this->read("SELECT id, notice_version FROM candidate_disclosure_requests
                WHERE case_id = ? AND teacher_id = ? AND scope = ? AND status = 'pending' FOR UPDATE", [$id, $teacher, $scope])[0] ?? null;
            if ($existing !== null) {
                if ($version !== null && $existing['notice_version'] !== $version) {
                    $db->prepare('UPDATE candidate_disclosure_requests SET notice_version = ? WHERE id = ?')->execute([$version, $existing['id']]);
                    VerificationEvents::audit($db, $owner, 'disclosure.notice_acknowledged', ['disclosure_id' => (int) $existing['id']]);
                }
                return;
            }
            $db->prepare('INSERT INTO candidate_disclosure_requests (case_id, teacher_id, reference, scope, notice_version) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $teacher, $ref, $scope, $version]);
            VerificationEvents::audit($db, $owner, 'disclosure.requested', [
                'case_id' => $id, 'disclosure_id' => (int) $db->lastInsertId(), 'scope' => $scope,
            ]);
        });
    }

    public function decide(
        int $admin, int $id, int $request, string $decision, string $reason, string $evidence,
        int $hours, bool $reviewed, ?int $document, ?int $revision, string $notice,
    ): void {
        if (!in_array($decision, ['approve', 'reject'], true) || $reason === '') {
            throw new DomainException('Choose a decision and record why.', 422);
        }
        $initial = $this->disclosure($id, $request) ?? throw CandidateRepository::unavailable();
        $teacher = (int) $initial['teacher_id'];
        $owner = (int) $initial['requester_id'];
        Database::transaction(($this->database)(), function (PDO $db) use (
            $admin, $id, $request, $decision, $reason, $evidence, $hours, $reviewed, $document, $revision, $teacher, $owner, $notice,
        ): void {
            $this->cases->lockAccounts($owner, $teacher, $admin);
            $this->cases->requireAdmin($admin, lock: true);
            $case = $this->cases->lockCase($id);
            $this->cases->requireAssigned($case, $admin);
            $row = $this->disclosure($id, $request, lock: true) ?? throw CandidateRepository::unavailable();
            if ($row['status'] !== 'pending') {
                throw new DomainException('This disclosure request already has a decision.', 409);
            }
            if ($decision === 'approve') {
                if ($notice !== $this->policy->version) {
                    throw new DomainException('The disclosure policy changed. Reopen the review.', 409);
                }
                $this->requireOwner($case, $owner, Role::from($case['requester_role']));
                $this->grant($db, $case, $row, $admin, $teacher, $owner, $evidence, $hours, $reviewed, $document, $revision);
            }
            $db->prepare('UPDATE candidate_disclosure_requests SET status = ?, reason = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(6) WHERE id = ?')
                ->execute([$decision === 'approve' ? 'approved' : 'rejected', $reason, $admin, $request]);
            VerificationEvents::record($db, $admin, $owner, 'disclosure.' . $decision, ['disclosure_id' => $request],
                'Candidate information request ' . ($decision === 'approve' ? 'approved' : 'rejected'), 'Open Candidate Requests to view the decision.');
        });
    }

    private function grant(
        PDO $db, array $case, array $row, int $admin, int $teacher, int $owner,
        string $evidence, int $hours, bool $reviewed, ?int $document, ?int $revision,
    ): void {
        if (!$this->policy->active() || $row['notice_version'] !== $this->policy->version) {
            throw new DomainException('An approved disclosure policy and the recipient’s current notice acknowledgement are required.', 409);
        }
        if ($admin === $owner || $admin === $teacher) {
            throw new DomainException('A separate administrator must approve this disclosure.', 403);
        }
        if (!$reviewed || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._:\/-]{7,159}\z/', $evidence) !== 1
            || $hours < 1 || $hours > $this->policy->limitHours($row['scope'])) {
            throw new DomainException('Record the case-specific consent reference, review the data, and enter a permitted expiry.', 422);
        }
        if ($this->cases->disclosureTeacher($case, $row['reference']) !== $teacher || !$this->teacherAvailable($teacher)) {
            throw new DomainException('The teacher or the requested scope is unavailable for disclosure.', 409);
        }
        $payload = null;
        if ($row['scope'] === 'original_cv') {
            if ($document === null || $this->currentCv($teacher, lock: true) !== $document) {
                throw new DomainException('Review the current accepted CV. The requested document version cannot be approved.', 409);
            }
            $revision = null;
        } else {
            $profile = $this->profiles->profile($teacher, lock: true);
            if ($profile === null || $revision === null || (int) $profile['verification_revision'] !== $revision) {
                throw new DomainException('The teacher profile changed. Reopen the review.', 409);
            }
            $document = null;
            $payload = json_encode(self::snapshot($profile), JSON_THROW_ON_ERROR);
        }
        $active = $this->read('SELECT g.id, g.policy_version, g.document_id, g.profile_revision
            FROM candidate_disclosure_grants g JOIN candidate_disclosure_requests r ON r.id = g.disclosure_id
            WHERE r.case_id = ? AND g.teacher_id = ? AND g.scope = ? AND g.revoked_at IS NULL
                AND g.expires_at > UTC_TIMESTAMP(6) FOR UPDATE OF g', [$case['id'], $teacher, $row['scope']]);
        foreach ($active as $previous) {
            if ($previous['policy_version'] === $this->policy->version
                && $previous['document_id'] === $document && $previous['profile_revision'] === $revision) {
                throw new DomainException('This case already has access to the reviewed material.', 409);
            }
            $db->prepare("UPDATE candidate_disclosure_grants SET revoked_at = UTC_TIMESTAMP(6), revoked_by = ?,
                revocation_reason = 'Superseded by a new disclosure approval' WHERE id = ?")
                ->execute([$admin, $previous['id']]);
            VerificationEvents::audit($db, $admin, 'disclosure.superseded', ['grant_id' => (int) $previous['id']]);
        }
        $db->prepare("INSERT INTO candidate_disclosure_grants (disclosure_id, recipient_id, teacher_id, scope, document_id,
            profile_revision, profile_data, lawful_basis, evidence_reference, policy_version, approved_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'specific_consent', ?, ?, ?, TIMESTAMPADD(HOUR, ?, UTC_TIMESTAMP(6)))")
            ->execute([$row['id'], $owner, $teacher, $row['scope'], $document, $revision, $payload, $evidence, $this->policy->version, $admin, $hours]);
    }

    public function revoke(int $admin, int $case, int $grant, string $reason): void
    {
        $row = $this->grantRow($grant, case: $case) ?? throw CandidateRepository::unavailable();
        if ($reason === '') {
            throw new DomainException('Record the revocation reason.', 422);
        }
        Database::transaction(($this->database)(), function (PDO $db) use ($admin, $case, $grant, $reason, $row): void {
            $this->cases->lockAccounts((int) $row['recipient_id'], (int) $row['teacher_id'], $admin);
            $this->cases->requireAdmin($admin, lock: true);
            $this->cases->requireAssigned($this->cases->lockCase($case), $admin);
            $current = $this->grantRow($grant, case: $case, lock: true) ?? throw CandidateRepository::unavailable();
            if ($current['revoked_at'] !== null) {
                throw new DomainException('Access has already been revoked.', 409);
            }
            $db->prepare('UPDATE candidate_disclosure_grants SET revoked_at = UTC_TIMESTAMP(6), revoked_by = ?, revocation_reason = ? WHERE id = ?')
                ->execute([$admin, $reason, $grant]);
            VerificationEvents::record($db, $admin, (int) $row['recipient_id'], 'disclosure.revoked', ['grant_id' => $grant],
                'Candidate information access revoked', 'Open Candidate Requests to view the grant status.');
        });
    }

    public function download(int $recipient, Role $role, int $grant): Response
    {
        return $this->access($recipient, $role, $grant, 'original_cv', function (PDO $db, array $row) use ($recipient, $grant): Response {
            $teacher = (int) $row['teacher_id'];
            $document = (int) $row['document_id'];
            if ($this->currentCv($teacher, lock: true) !== $document) {
                throw CandidateRepository::unavailable();
            }
            $response = $this->documents->download($teacher, $document) ?? throw CandidateRepository::unavailable();
            $extension = $response->headers['Content-Type'] === 'application/pdf' ? 'pdf' : 'docx';
            $db->prepare('INSERT INTO candidate_disclosure_downloads (grant_id, recipient_id, document_id) VALUES (?, ?, ?)')
                ->execute([$grant, $recipient, $document]);
            VerificationEvents::audit($db, $recipient, 'disclosure.cv_downloaded', ['grant_id' => $grant, 'document_id' => $document]);
            return new Response($response->body, 200, [
                'Content-Disposition' => 'attachment; filename="candidate-cv.' . $extension . '"',
                'X-Content-Type-Options' => 'nosniff',
            ] + $response->headers);
        });
    }

    public function professional(int $recipient, Role $role, int $grant): array
    {
        return $this->access($recipient, $role, $grant, 'professional', function (PDO $db, array $row) use ($grant, $recipient): array {
            $profile = $this->read('SELECT verification_revision FROM teacher_profiles WHERE user_id = ? FOR SHARE', [$row['teacher_id']])[0] ?? null;
            if ($profile === null || (int) $profile['verification_revision'] !== (int) $row['profile_revision']) {
                throw CandidateRepository::unavailable();
            }
            VerificationEvents::audit($db, $recipient, 'disclosure.profile_viewed', ['grant_id' => $grant]);
            return json_decode($row['profile_data'], true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function access(int $recipient, Role $role, int $grant, string $scope, Closure $deliver): mixed
    {
        $row = $this->grantRow($grant, $recipient) ?? throw CandidateRepository::unavailable();
        return Database::transaction(($this->database)(), function (PDO $db) use ($recipient, $role, $grant, $scope, $deliver, $row): mixed {
            $this->cases->lockAccounts($recipient, (int) $row['teacher_id']);
            $case = $this->cases->lockCase((int) $row['case_id']);
            $this->requireOwner($case, $recipient, $role);
            $current = $this->grantRow($grant, $recipient, (int) $case['id'], lock: true) ?? throw CandidateRepository::unavailable();
            if (!$this->policy->active() || $current['policy_version'] !== $this->policy->version
                || $current['scope'] !== $scope || $current['revoked_at'] !== null || !(bool) $current['unexpired']
                || $current['request_status'] !== 'approved' || !$this->teacherAvailable((int) $current['teacher_id'])
                || $this->cases->disclosureTeacher($case, $current['reference']) !== (int) $current['teacher_id']) {
                throw CandidateRepository::unavailable();
            }
            return $deliver($db, $current);
        });
    }

    private function requireOwner(array $case, int $recipient, Role $role): void
    {
        if ((int) $case['requester_id'] !== $recipient || $case['requester_role'] !== $role->value
            || in_array($case['status'], ['draft', 'closed'], true)) {
            throw CandidateRepository::unavailable();
        }
        if (!$this->cases->eligible($recipient, $role, lock: true)) {
            throw new DomainException('An eligible recipient account is required.', 403);
        }
        $this->cases->requireContext($case);
    }

    private function teacherAvailable(int $teacher): bool
    {
        return $this->cases->hasRole($teacher, Role::Teacher, lock: true)
            && $this->read("SELECT user_id FROM teacher_profiles WHERE user_id = ? AND verification_status <> 'suspended' FOR SHARE", [$teacher]) !== []
            && ($this->read('SELECT paused_at FROM teacher_disclosure_preferences WHERE teacher_id = ? FOR SHARE', [$teacher])[0]['paused_at'] ?? null) === null;
    }

    private function currentCv(int $teacher, bool $lock = false): ?int
    {
        $row = $this->read("SELECT d.id FROM teacher_documents d JOIN teacher_document_reviews r ON r.document_id = d.id
            WHERE d.cv_teacher_id = ? AND r.decision = 'accepted'" . ($lock ? ' FOR UPDATE OF d, r' : ''), [$teacher])[0] ?? null;
        return $row === null ? null : (int) $row['id'];
    }

    private function disclosure(int $case, int $id, bool $lock = false): ?array
    {
        return $this->read('SELECT r.*, c.requester_id FROM candidate_disclosure_requests r JOIN candidate_requests c ON c.id = r.case_id
            WHERE r.id = ? AND r.case_id = ?' . ($lock ? ' FOR UPDATE OF r' : ''), [$id, $case])[0] ?? null;
    }

    private function grantRow(int $id, ?int $recipient = null, ?int $case = null, bool $lock = false): ?array
    {
        $sql = 'SELECT g.*, r.case_id, r.reference, r.status AS request_status, g.expires_at > UTC_TIMESTAMP(6) AS unexpired
            FROM candidate_disclosure_grants g JOIN candidate_disclosure_requests r ON r.id = g.disclosure_id WHERE g.id = ?';
        $params = [$id];
        if ($recipient !== null) {
            $sql .= ' AND g.recipient_id = ?';
            $params[] = $recipient;
        }
        if ($case !== null) {
            $sql .= ' AND r.case_id = ?';
            $params[] = $case;
        }
        return $this->read($sql . ($lock ? ' FOR UPDATE OF g' : ''), $params)[0] ?? null;
    }

    private static function snapshot(array $profile): array
    {
        $qualifications = array_flip(['qualification', 'field_of_study', 'completed_year']);
        $experience = array_flip(['position_title', 'started_year', 'ended_year']);
        return [
            'qualifications' => array_map(static fn (array $row): array => array_intersect_key($row, $qualifications), $profile['sections']['qualifications']['rows']),
            'experience' => array_map(static fn (array $row): array => array_intersect_key($row, $experience), $profile['sections']['experience']['rows']),
        ];
    }

    private function read(string $sql, array $values): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($values);
        return $query->fetchAll();
    }
}

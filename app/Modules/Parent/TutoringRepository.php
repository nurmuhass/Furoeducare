<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Disclosure\CandidateRepository;
use Furoeducare\Modules\Directory\PublicDirectory;
use Furoeducare\Modules\Placement\PlacementRepository;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class TutoringRepository
{
    private const CONTACT_ERROR = 'This tutoring request could not be found.';

    public function __construct(
        private readonly Closure $database,
        private readonly PublicDirectory $directory,
    ) {
    }

    public function owned(int $owner, int $id): ?array
    {
        $row = $this->read('SELECT r.*, c.teacher_reference AS confirmed_reference
            FROM parent_tutoring_requests r LEFT JOIN tutoring_candidates c
                ON c.request_id = r.id AND c.teacher_id = r.confirmed_teacher_id
            WHERE r.id = ? AND r.parent_id = ?', [$id, $owner])[0] ?? null;
        return $row === null ? null : self::decode($row);
    }

    public function requests(int $owner, int $before, ?TutoringStatus $stage = null): array
    {
        return $this->read('SELECT r.id, r.subject, r.status, r.selected_teacher_reference, c.teacher_reference AS confirmed_reference
            FROM parent_tutoring_requests r LEFT JOIN tutoring_candidates c
                ON c.request_id = r.id AND c.teacher_id = r.confirmed_teacher_id
            WHERE r.parent_id = ? AND r.id < ?' . ($stage === null ? '' : ' AND r.status = ?')
            . ' ORDER BY r.id DESC LIMIT 31', $stage === null ? [$owner, $before] : [$owner, $before, $stage->value]);
    }

    public function counts(int $owner): array
    {
        return $this->read("SELECT COUNT(CASE WHEN status = 'draft' THEN 1 END) AS drafts,
            COUNT(CASE WHEN status <> 'draft' THEN 1 END) AS submitted
            FROM parent_tutoring_requests WHERE parent_id = ?", [$owner])[0];
    }

    public function staffRequests(int $before, ?TutoringStatus $status): array
    {
        return $this->read("SELECT id, subject, status, selected_teacher_reference, submitted_at
            FROM parent_tutoring_requests WHERE status <> 'draft' AND id < ?"
            . ($status === null ? '' : ' AND status = ?') . ' ORDER BY id DESC LIMIT 21',
            $status === null ? [$before] : [$before, $status->value]);
    }

    public function staffRequest(int $id): ?array
    {
        $row = $this->read("SELECT r.*, c.teacher_reference AS confirmed_reference,
            p.first_name AS contact_first_name, p.last_name AS contact_last_name,
            p.phone AS contact_phone, u.email AS contact_email, placement.id AS placement_id
            FROM parent_tutoring_requests r
            JOIN parent_profiles p ON p.user_id = r.parent_id
            JOIN users u ON u.id = r.parent_id
            LEFT JOIN placements placement ON placement.active_tutoring_id = r.id
            LEFT JOIN tutoring_candidates c
                ON c.request_id = r.id AND c.teacher_id = r.confirmed_teacher_id
            WHERE r.id = ? AND r.status <> 'draft'", [$id])[0] ?? null;
        return $row === null ? null : self::decode($row);
    }

    public function candidates(int $id): array
    {
        return $this->read('SELECT teacher_id, teacher_reference, title FROM tutoring_candidates
            WHERE request_id = ? ORDER BY created_at, teacher_id', [$id]);
    }

    public function visibleCandidates(array $record): array
    {
        $stage = TutoringStatus::from($record['status']);
        if (!in_array($stage, [TutoringStatus::Shortlisted, TutoringStatus::ArrangementPending], true)) {
            return [];
        }
        return $this->compatibleCandidates($record);
    }

    public function history(int $id): array
    {
        return $this->read('SELECT from_status, to_status, note, created_at FROM tutoring_request_history
            WHERE request_id = ? ORDER BY id DESC', [$id]);
    }

    public function notifications(int $owner): array
    {
        return $this->read("SELECT title, message, created_at FROM verification_notifications
            WHERE user_id = ? AND (event LIKE 'tutoring.%' OR event LIKE 'disclosure.%' OR event LIKE 'placement.%')
            ORDER BY id DESC LIMIT 30", [$owner]);
    }

    public function recommendations(int $owner, int $before): array
    {
        return array_map(self::decode(...), $this->read("SELECT id, subject, status, lesson_details
            FROM parent_tutoring_requests WHERE parent_id = ? AND id < ? AND status IN ('shortlisted', 'arrangement_pending')
            ORDER BY id DESC LIMIT 9", [$owner, $before]));
    }

    public function tutor(string $reference): ?array
    {
        $listing = $this->directory->find($reference);
        return $listing !== null && ((int) $listing['current_modes'] & TutoringInput::modeMask('either')) !== 0 ? $listing : null;
    }

    public function saveDraft(int $owner, ?int $id, string $version, ?string $reference, array $fields, bool $clearReference): int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($owner, $id, $version, $reference, $fields, $clearReference): int {
            if ($id !== null) {
                $record = $this->lock($id, $owner);
                if ($record['status'] !== TutoringStatus::Draft->value) {
                    throw new DomainException('Open My Requests to view the submitted details.', 409);
                }
                self::expectVersion($record, $version);
            }
            $this->requireRole($owner, 'parent');
            $details = json_encode($fields['details'], JSON_THROW_ON_ERROR);
            if ($id === null) {
                if ($reference !== null && $this->tutor($reference) === null) {
                    throw new DomainException('This teacher is unavailable for tutoring.', 404);
                }
                $db->prepare('INSERT INTO parent_tutoring_requests
                    (parent_id, subject, requirements, lesson_details, selected_teacher_reference) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$owner, $fields['subject'], $fields['requirements'], $details, $reference]);
                return (int) $db->lastInsertId();
            }
            $db->prepare('UPDATE parent_tutoring_requests SET subject = ?, requirements = ?, lesson_details = ?,
                selected_teacher_reference = IF(?, NULL, selected_teacher_reference) WHERE id = ? AND parent_id = ?')
                ->execute([$fields['subject'], $fields['requirements'], $details, (int) $clearReference, $id, $owner]);
            return $id;
        });
    }

    public function submit(int $owner, int $id, string $version): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($owner, $id, $version): void {
            $record = $this->lock($id, $owner);
            if ($record['status'] !== TutoringStatus::Draft->value) {
                $this->requireRole($owner, 'parent');
                return;
            }
            self::expectVersion($record, $version);
            $missing = TutoringInput::missing($record);
            if ($missing !== []) {
                throw new DomainException('Complete the following before submitting: ' . implode(', ', $missing) . '.', 422);
            }
            $reference = $record['selected_teacher_reference'];
            if ($reference === null) {
                $this->requireRole($owner, 'parent');
            } else {
                $this->lockTutor($owner, 'parent', $reference, $record['details']['mode']);
            }
            $db->prepare("UPDATE parent_tutoring_requests SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP(6)
                WHERE id = ? AND parent_id = ?")->execute([$id, $owner]);
            CandidateRepository::mirrorTutoring($db, $id);
            self::event($db, $record, $owner, TutoringStatus::Submitted, '');
        });
    }

    public function changeCandidate(int $actor, int $id, string $version, string $ref, bool $add): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $version, $ref, $add): void {
            $record = $this->lock($id);
            self::expectVersion($record, $version);
            $stage = TutoringStatus::from($record['status']);
            if (!$stage->canManageCandidates()) {
                throw new DomainException('Candidates can be managed during matching, shortlisting and arrangement.', 409);
            }
            if ($add) {
                $target = $this->lockTutor($actor, 'admin', $ref, $record['details']['mode'] ?? '');
                $candidates = $this->candidates($id);
                if (count($candidates) >= 10 && !in_array($ref, array_column($candidates, 'teacher_reference'), true)) {
                    throw new DomainException('A request can hold up to ten shortlisted tutors.', 422);
                }
                $db->prepare('INSERT INTO tutoring_candidates (request_id, teacher_id, teacher_reference, title)
                    VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = title')
                    ->execute([$id, $target['teacher_id'], $ref, $target['title']]);
            } else {
                $this->requireRole($actor, 'admin');
                if ($stage !== TutoringStatus::Matching
                    && in_array($ref, array_column($this->candidates($id), 'teacher_reference'), true)
                    && $this->compatibleCandidates($record, $ref) === []) {
                    throw new DomainException('Add another available tutor before removing this shortlist entry.', 409);
                }
                $db->prepare('DELETE FROM tutoring_candidates WHERE request_id = ? AND teacher_reference = ?')->execute([$id, $ref]);
            }
            $db->prepare('UPDATE parent_tutoring_requests SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?')->execute([$id]);
            VerificationEvents::audit($db, $actor, $add ? 'tutoring.candidate_added' : 'tutoring.candidate_removed',
                ['request_id' => $id, 'teacher_reference' => $ref]);
        });
    }

    public function transition(int $actor, int $id, string $version, TutoringStatus $to, string $note): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $version, $to, $note): void {
            $record = $this->lock($id);
            $this->requireRole($actor, 'admin');
            self::expectVersion($record, $version);
            $from = TutoringStatus::from($record['status']);
            if (!in_array($to, $from->staffTransitions(), true)) {
                throw new DomainException('This decision is unavailable at the current request stage.', 409);
            }
            if ($to === TutoringStatus::Closed && $note === '') {
                throw new DomainException('Enter a reason for closing the request.', 422);
            }
            if ($to === TutoringStatus::Confirmed) {
                throw new DomainException('Confirm this arrangement from the managed placement workspace.', 409);
            }
            if (in_array($to, [TutoringStatus::Shortlisted, TutoringStatus::ArrangementPending], true) && $this->compatibleCandidates($record) === []) {
                throw new DomainException('Add at least one suitable tutor before advancing the request.', 409);
            }
            $db->prepare('UPDATE parent_tutoring_requests SET status = ? WHERE id = ?')->execute([$to->value, $id]);
            if ($to === TutoringStatus::Closed) {
                PlacementRepository::cancelForTutoring($db, $id, $actor);
                CandidateRepository::closeTutoring($db, $id);
            }
            self::event($db, $record, $actor, $to, $note);
        });
    }

    /** Record the companion tutoring state in the active managed placement transaction. */
    public static function recordPlacementConfirmation(PDO $db, array $record, int $actor, int $teacher): void
    {
        $db->prepare("UPDATE parent_tutoring_requests SET status = 'confirmed', confirmed_teacher_id = ? WHERE id = ?")
            ->execute([$teacher, $record['id']]);
        self::event($db, $record, $actor, TutoringStatus::Confirmed, 'Confirmed through managed placement.');
    }

    private function compatibleCandidates(array $record, ?string $omit = null): array
    {
        $references = array_column($this->candidates((int) $record['id']), 'teacher_reference');
        if ($omit !== null) {
            $references = array_diff($references, [$omit]);
        }
        $mask = TutoringInput::modeMask($record['details']['mode'] ?? '');
        $public = array_column($this->directory->tutorSummaries($references, $mask), null, 'reference');
        $matches = [];
        foreach ($references as $ref) {
            if (isset($public[$ref])) {
                $matches[] = ['reference' => $ref, 'title' => $public[$ref]['title']];
            }
        }
        return $matches;
    }

    private function lock(int $id, ?int $owner = null): array
    {
        $row = $this->read('SELECT * FROM parent_tutoring_requests WHERE id = ?'
            . ($owner === null ? " AND status <> 'draft'" : ' AND parent_id = ?') . ' FOR UPDATE',
            $owner === null ? [$id] : [$id, $owner])[0] ?? null;
        if ($row === null) {
            throw new DomainException(self::CONTACT_ERROR, 404);
        }
        return self::decode($row);
    }

    private function lockTutor(int $actor, string $role, string $ref, string $mode): array
    {
        $listing = $this->read('SELECT teacher_id FROM teacher_listings WHERE reference = ?', [$ref])[0] ?? null;
        if ($listing === null || (int) $listing['teacher_id'] === $actor) {
            throw new DomainException('This teacher is unavailable for the requested lesson format.', 409);
        }
        $teacher = (int) $listing['teacher_id'];
        $locks = [$actor => 'SHARE', $teacher => 'UPDATE'];
        ksort($locks, SORT_NUMERIC);
        foreach ($locks as $account => $level) {
            $this->read("SELECT id FROM users WHERE id = ? FOR $level", [$account]);
        }
        $this->requireRole($actor, $role);
        $target = $this->directory->lockTarget($ref);
        if ($target === null || (int) $target['teacher_id'] !== $teacher
            || ((int) $target['current_modes'] & TutoringInput::modeMask($mode)) === 0) {
            throw new DomainException('This teacher is unavailable for the requested lesson format.', 409);
        }
        return $target;
    }

    private function requireRole(int $id, string $role): void
    {
        if ($this->read('SELECT id FROM users u WHERE id = ? AND email_verified_at IS NOT NULL AND suspended_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = ?)
            FOR SHARE', [$id, $role]) === []) {
            throw new DomainException('A verified account with the appropriate role is required.', 403);
        }
    }

    private static function expectVersion(array $record, string $version): void
    {
        if ($record['updated_at'] !== $version) {
            throw new DomainException('This request has changed. Review the latest details first.', 409);
        }
    }

    private static function event(PDO $db, array $record, int $actor, TutoringStatus $to, string $note): void
    {
        $db->prepare('INSERT INTO tutoring_request_history (request_id, actor_user_id, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$record['id'], $actor, $record['status'], $to->value, $note]);
        VerificationEvents::record($db, $actor, (int) $record['parent_id'], 'tutoring.' . $to->value,
            ['request_id' => (int) $record['id'], 'from' => $record['status'], 'to' => $to->value],
            'Tutoring request #' . $record['id'] . ' · ' . $to->label(),
            'Open My Requests to view this update and any notes from Furoeducare.');
    }

    private static function decode(array $row): array
    {
        $row['details'] = $row['lesson_details'] === null ? [] : json_decode($row['lesson_details'], true, flags: JSON_THROW_ON_ERROR);
        unset($row['lesson_details']);
        return $row;
    }

    private function read(string $sql, array $values): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($values);
        return $query->fetchAll();
    }
}

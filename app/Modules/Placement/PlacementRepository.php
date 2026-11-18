<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Placement;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Disclosure\CandidateRepository;
use Furoeducare\Modules\Verification\TeacherEligibility;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class PlacementRepository
{
    private const PUBLIC_FIELDS = 'id, kind, requester_id, tutoring_id, teacher_reference, mode, status, scheduled_at,
        offer_summary, starts_on, revision, confirmed_at, closed_at, created_at,
        (SELECT a.job_title FROM vacancy_applications a WHERE a.id = placements.application_id) AS source_label';

    public function __construct(
        private readonly Closure $database,
        private readonly PlacementSources $sources,
        private readonly CandidateRepository $accounts,
        private readonly TeacherEligibility $eligibility,
    ) {
    }

    public function startEmployment(int $staff, int $application): int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($staff, $application): int {
            $source = $this->sources->employment($db, $application);
            $owner = (int) $source['requester_id'];
            $teacher = (int) $source['teacher_id'];
            $this->sources->requireParties($owner, $teacher, 'employment', $staff);
            if ($this->rows($db, 'SELECT id FROM placements WHERE application_id = ? FOR UPDATE', [$application]) !== []) {
                throw new DomainException('This application already has a placement record.', 409);
            }
            $this->sources->requireReady($source, 'employment');
            return $this->insert($db, $staff, 'employment', $owner, $teacher, $source['candidate_reference'], $application);
        });
    }

    public function startTutoring(int $staff, int $request, string $ref, string $mode): int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($staff, $request, $ref, $mode): int {
            $source = $this->sources->tutoring($db, $request);
            $owner = (int) $source['requester_id'];
            $this->accounts->requireAdmin($staff);
            $this->sources->requireReady($source, 'tutoring');
            $teacher = $this->sources->tutor($db, $source, $ref, $mode);
            $this->sources->requireParties($owner, $teacher, 'tutoring', $staff);
            $this->sources->requireLiveTutor($ref, $mode, $teacher);
            if ($this->rows($db, 'SELECT id FROM placements WHERE active_tutoring_id = ? FOR UPDATE', [$request]) !== []) {
                throw new DomainException('This tutoring request already has a current placement.', 409);
            }
            return $this->insert($db, $staff, 'tutoring', $owner, $teacher, $ref, $request, $mode);
        });
    }

    private function insert(PDO $db, int $staff, string $kind, int $owner, int $teacher, string $reference, int $source, ?string $mode = null): int
    {
        $db->prepare('INSERT INTO placements (kind, application_id, tutoring_id, requester_id, teacher_id, teacher_reference, mode, assigned_admin_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$kind, $kind === 'employment' ? $source : null, $kind === 'tutoring' ? $source : null, $owner, $teacher, $reference, $mode, $staff]);
        $id = (int) $db->lastInsertId();
        self::record($db, ['id' => $id, 'requester_id' => $owner, 'teacher_id' => $teacher, 'shortlisted_at' => null],
            $staff, 'start', PlacementStatus::Screening, '', ['assigned_admin_id' => $staff]);
        return $id;
    }

    public function staffList(int $before): array
    {
        return $this->rows(($this->database)(), 'SELECT * FROM placements WHERE id < ? ORDER BY id DESC LIMIT 21', [$before]);
    }

    public function staffDetail(int $id): ?array
    {
        $db = ($this->database)();
        $row = $this->rows($db, 'SELECT p.*, a.job_title AS source_label FROM placements p
            LEFT JOIN vacancy_applications a ON a.id = p.application_id WHERE p.id = ?', [$id])[0] ?? null;
        if ($row !== null) {
            $row['history'] = $this->rows($db, 'SELECT actor_user_id, from_status, to_status, action, note, context, created_at
                FROM placement_history WHERE placement_id = ? ORDER BY id DESC', [$id]);
        }
        return $row;
    }

    public function participantList(int $viewer, array $roles, int $before): array
    {
        [$scope, $params] = $this->visibility($viewer, $roles);
        return $this->rows(($this->database)(), 'SELECT ' . self::PUBLIC_FIELDS . " FROM placements WHERE ($scope)
            AND id < ? ORDER BY id DESC LIMIT 21", [...$params, $before]);
    }

    public function participantDetail(int $viewer, array $roles, int $id): ?array
    {
        [$scope, $params] = $this->visibility($viewer, $roles);
        return $this->rows(($this->database)(), 'SELECT ' . self::PUBLIC_FIELDS . " FROM placements WHERE ($scope) AND id = ?",
            [...$params, $id])[0] ?? null;
    }

    private function visibility(int $viewer, array $roles): array
    {
        $visible = [];
        $params = [];
        if (in_array(Role::Teacher, $roles, true) && $this->accounts->hasRole($viewer, Role::Teacher)) {
            $visible[] = 'teacher_id = ?';
            $params[] = $viewer;
        }
        foreach ([Role::School->value => 'employment', Role::Parent->value => 'tutoring'] as $role => $kind) {
            $category = Role::from($role);
            if (in_array($category, $roles, true) && $this->accounts->eligible($viewer, $category)) {
                $visible[] = '(requester_id = ? AND kind = ? AND shortlisted_at IS NOT NULL)';
                array_push($params, $viewer, $kind);
            }
        }
        return [$visible === [] ? '0 = 1' : implode(' OR ', $visible), $params];
    }

    public function manage(int $staff, int $id, int $revision, string $action, array $input): void
    {
        $this->accounts->requireAdmin($staff);
        $this->change($id, $revision, $action, $staff, $input, staff: true);
    }

    public function select(int $owner, int $id, int $revision): void
    {
        $this->change($id, $revision, 'select', $owner, [], staff: false);
    }

    private function change(int $id, int $revision, string $action, int $actor, array $input, bool $staff): void
    {
        $db = ($this->database)();
        $initial = $this->row($db, $id);
        if (!$staff && (int) $initial['requester_id'] !== $actor) {
            throw self::missing();
        }
        Database::transaction($db, function (PDO $db) use ($initial, $id, $revision, $action, $actor, $input, $staff): void {
            $kind = $initial['kind'];
            $employment = $kind === 'employment';
            $source = $employment ? $this->sources->employment($db, (int) $initial['application_id'])
                : $this->sources->tutoring($db, (int) $initial['tutoring_id']);
            $row = $this->row($db, $id, lock: true);
            if ((int) $row['revision'] !== $revision) {
                throw new DomainException('This placement changed. Review the current details first.', 409);
            }
            $current = PlacementStatus::from($row['status']);
            $requester = (int) $row['requester_id'];
            $teacher = (int) $row['teacher_id'];
            if ($staff) {
                $this->accounts->requireAdmin($actor, lock: true);
                if ($actor === $requester || $actor === $teacher) {
                    throw new DomainException('Assign an administrator who is independent of the placement.', 403);
                }
                if ($action === 'assign' && !in_array($current, [PlacementStatus::Rejected, PlacementStatus::Closed], true)) {
                    if ((int) $row['assigned_admin_id'] !== $actor) {
                        $this->update($db, $id, ['assigned_admin_id' => $actor]);
                        self::record($db, $row, $actor, $action, $current, '', ['previous_admin_id' => (int) $row['assigned_admin_id']]);
                    }
                    return;
                }
                if ((int) $row['assigned_admin_id'] !== $actor) {
                    throw new DomainException('Assign this placement to yourself before recording a decision.', 403);
                }
            }
            if (!$staff && ($requester !== $actor || $row['shortlisted_at'] === null)) {
                throw self::missing();
            }
            $next = $current->after($action) ?? throw new DomainException('This action is unavailable at the current placement stage.', 409);
            $note = $staff ? PlacementInput::text($input['note'] ?? '', 'decision notes') : 'The requesting account selected this candidate.';
            if (!in_array($action, ['reject', 'close'], true)) {
                if ((int) $source['requester_id'] !== $requester) {
                    throw self::missing();
                }
                $this->sources->requireParties($requester, $teacher, $kind, $staff ? $actor : null);
                $this->sources->requireReady($source, $kind);
                if (!$employment) {
                    $this->requireTutor($db, $source, $row, $teacher);
                }
            }
            [$changes, $context] = $this->decision($db, $row, $source, $action, $actor, $input);
            $this->update($db, $id, ['status' => $next->value] + $changes);
            if ($employment) {
                $this->sources->syncEmployment($db, $source, $actor, $next);
            } else {
                $this->sources->syncTutoring($db, $source, $actor, $teacher, $next);
            }
            self::record($db, $row, $actor, $action, $next, $note, $context);
        });
    }

    private function requireTutor(PDO $db, array $source, array $row, int $teacher): void
    {
        if ($this->sources->tutor($db, $source, $row['teacher_reference'], $row['mode']) !== $teacher) {
            throw self::missing();
        }
        $this->sources->requireLiveTutor($row['teacher_reference'], $row['mode'], $teacher);
    }

    private function decision(PDO $db, array $row, array $source, string $action, int $actor, array $input): array
    {
        if ($action === 'shortlist') {
            return [['shortlisted_at' => self::now()], []];
        }
        if ($action === 'schedule') {
            $time = PlacementInput::discussion($input['scheduled_at'] ?? '');
            return [['scheduled_at' => $time], ['scheduled_at' => $time]];
        }
        if ($action === 'offer') {
            $date = PlacementInput::startDate($input['starts_on'] ?? '');
            $summary = PlacementInput::text($input['offer_summary'] ?? '', 'an offer or arrangement summary');
            return [['starts_on' => $date, 'offer_summary' => $summary], ['starts_on' => $date, 'offer_summary' => $summary]];
        }
        if ($action === 'close') {
            return [['closed_at' => self::now()], []];
        }
        if ($action === 'safeguard') {
            if ($row['mode'] !== 'home') {
                throw new DomainException('This checklist applies to home tutoring.', 409);
            }
            $reference = PlacementInput::safeguarding($input);
            $revision = $this->verifiedRevision((int) $row['teacher_id']);
            return [[
                'safeguarding_reference' => $reference, 'safeguarding_revision' => $revision,
                'safeguarding_checked_by' => $actor, 'safeguarding_checked_at' => self::now(),
            ], ['safeguarding_reference' => $reference, 'verification_revision' => $revision,
                'checks' => array_fill_keys(PlacementInput::HOME_CHECKS, true)]];
        }
        if ($action === 'confirm') {
            $revision = $this->verifiedRevision((int) $row['teacher_id']);
            if ($row['mode'] === 'home' && ($row['safeguarding_checked_at'] === null || (int) $row['safeguarding_revision'] !== $revision)) {
                throw new DomainException('Confirmation requires current home tutoring identity, reference and safeguarding checks.', 409);
            }
            if ($row['kind'] === 'employment') {
                $this->sources->requireEmploymentCapacity($db, $source);
            }
            return [['confirmed_at' => self::now(), 'professional_revision' => $revision], ['verification_revision' => $revision]];
        }
        return [[], []];
    }

    private function verifiedRevision(int $teacher): int
    {
        try {
            return $this->eligibility->requireForPlacement($teacher);
        } catch (DomainException $error) {
            throw new DomainException($error->getMessage(), 409, $error);
        }
    }

    private function update(PDO $db, int $id, array $columns): void
    {
        $fields = implode(', ', array_map(static fn (string $field): string => "$field = ?", array_keys($columns)));
        $db->prepare("UPDATE placements SET $fields, revision = revision + 1 WHERE id = ?")->execute([...array_values($columns), $id]);
    }

    public static function cancelForApplication(PDO $db, int $id, int $actor): void
    {
        self::cancel($db, 'application_id', $id, $actor, 'The linked application ended before final placement.');
    }

    public static function cancelForTutoring(PDO $db, int $id, int $actor): void
    {
        self::cancel($db, 'tutoring_id', $id, $actor, 'The linked tutoring request was closed before final arrangement.');
    }

    private static function cancel(PDO $db, string $sourceField, int $id, int $actor, string $reason): void
    {
        $query = $db->prepare("SELECT * FROM placements WHERE $sourceField = ?
            AND status NOT IN ('confirmed', 'rejected', 'closed') FOR UPDATE");
        $query->execute([$id]);
        foreach ($query->fetchAll() as $row) {
            $db->prepare("UPDATE placements SET status = 'rejected', revision = revision + 1 WHERE id = ?")->execute([$row['id']]);
            self::record($db, $row, $actor, 'source_ended', PlacementStatus::Rejected, $reason, []);
        }
    }

    private static function record(PDO $db, array $row, int $actor, string $action, PlacementStatus $next, string $note, array $context): void
    {
        $db->prepare('INSERT INTO placement_history (placement_id, actor_user_id, from_status, to_status, action, note, context)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$row['id'], $actor, $row['status'] ?? null, $next->value, $action,
                $note, json_encode((object) $context, JSON_THROW_ON_ERROR)]);
        $audit = ['placement_id' => (int) $row['id'], 'action' => $action, 'from' => $row['status'] ?? null, 'to' => $next->value];
        if (in_array($action, ['assign', 'safeguard'], true)) {
            VerificationEvents::audit($db, $actor, 'placement.' . $action, $audit);
            return;
        }
        $recipients = [(int) $row['teacher_id']];
        if ($row['shortlisted_at'] !== null || $next === PlacementStatus::Shortlisted) {
            $recipients[] = (int) $row['requester_id'];
        }
        foreach ($recipients as $recipient) {
            VerificationEvents::record($db, $actor, $recipient, 'placement.' . $next->value, $audit,
                'Placement #' . $row['id'] . ' · ' . $next->label(), 'Open Placements to view this update.');
        }
    }

    private function row(PDO $db, int $id, bool $lock = false): array
    {
        return $this->rows($db, 'SELECT * FROM placements WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])[0] ?? throw self::missing();
    }

    private function rows(PDO $db, string $sql, array $values): array
    {
        $query = $db->prepare($sql);
        $query->execute($values);
        return $query->fetchAll();
    }

    private static function missing(): DomainException
    {
        return new DomainException('This placement could not be found.', 404);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}

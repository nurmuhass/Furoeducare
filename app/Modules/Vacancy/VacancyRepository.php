<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\School\SchoolStatus;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class VacancyRepository
{
    private const PUBLIC_SCHOOL_NAME = "COALESCE(v.content->>'$.published_school_name', s.school_name) AS school_name ";

    private const PUBLIC_SQL = <<<'SQL'
        FROM vacancies v
        JOIN school_onboarding s ON s.user_id = v.school_id
        JOIN users u ON u.id = s.user_id
        WHERE v.status = 'published' AND v.application_deadline >= ?
            AND s.institution_name_key IS NOT NULL AND s.accepted_at IS NOT NULL
            AND COALESCE(s.review_status, IF(s.approved_at IS NULL, 'pending', 'approved')) = 'approved'
            AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
            AND EXISTS (
                SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = u.id AND r.slug = 'school'
            )
        SQL;

    public const ACTIONS = [
        'submit' => 'Submit for review', 'start_review' => 'Start review', 'approve' => 'Approve',
        'reject' => 'Reject', 'publish' => 'Publish', 'close' => 'Close vacancy',
    ];

    public function __construct(private readonly Closure $database, private readonly SchoolRepository $schools)
    {
    }

    public function browse(int $before): array
    {
        return array_map(self::decode(...), $this->read(
            'SELECT v.id, v.content, v.application_deadline, ' . self::PUBLIC_SCHOOL_NAME . self::PUBLIC_SQL
                . ' AND v.id < ? ORDER BY v.id DESC LIMIT 21',
            [VacancyInput::today(), $before],
        ));
    }

    public function publicVacancy(int $id): ?array
    {
        $row = $this->read('SELECT v.id, v.revision, v.content, v.application_deadline, '
            . self::PUBLIC_SCHOOL_NAME . self::PUBLIC_SQL . ' AND v.id = ?', [VacancyInput::today(), $id])[0] ?? null;
        return $row === null ? null : self::decode($row);
    }

    public function list(int $before, ?int $school, ?VacancyStatus $status = null): array
    {
        $sql = 'SELECT v.*, s.school_name FROM vacancies v JOIN school_onboarding s ON s.user_id = v.school_id WHERE v.id < ?';
        $values = [$before];
        if ($school !== null) {
            $sql .= ' AND v.school_id = ?';
            $values[] = $school;
        } else {
            $sql .= " AND v.status <> 'draft'";
        }
        if ($status !== null) {
            $sql .= ' AND v.status = ?';
            $values[] = $status->value;
        }
        return array_map(self::decode(...), $this->read($sql . ' ORDER BY v.id DESC LIMIT 21', $values));
    }

    public function find(int $id, ?int $school): ?array
    {
        $row = $this->read('SELECT v.*, s.school_name FROM vacancies v JOIN school_onboarding s ON s.user_id = v.school_id WHERE v.id = ?'
            . ($school === null ? '' : ' AND v.school_id = ?'), $school === null ? [$id] : [$id, $school])[0] ?? null;
        return $row === null ? null : self::decode($row);
    }

    public function history(int $id): array
    {
        return $this->read('SELECT from_status, to_status, revision, note, created_at FROM vacancy_history
            WHERE vacancy_id = ? ORDER BY revision DESC LIMIT 50', [$id]);
    }

    public function create(int $school, array $content): int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($school, $content): int {
            $this->activeSchool($school);
            $db->prepare('INSERT INTO vacancies (school_id, content, application_deadline) VALUES (?, ?, ?)')
                ->execute([$school, self::encode($content), $content['deadline'] === '' ? null : $content['deadline']]);
            $id = (int) $db->lastInsertId();
            self::event($db, $id, $school, null, VacancyStatus::Draft, 1, '');
            return $id;
        });
    }

    public function edit(int $id, int $school, int $revision, array $content): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $school, $revision, $content): void {
            $row = $this->lock($id, $school);
            $this->checkRevision($row, $revision);
            $this->activeSchool($school);
            $previous = VacancyStatus::from($row['status']);
            if (!$previous->editable()) {
                throw new DomainException('This vacancy cannot be edited at its current stage.', 409);
            }
            $stored = $row['fields'];
            ksort($stored);
            ksort($content);
            if ($previous !== VacancyStatus::Rejected && $stored === $content) {
                return;
            }
            $db->prepare("UPDATE vacancies SET status = 'draft', content = ?, application_deadline = ?,
                revision = revision + 1, published_at = NULL WHERE id = ?")
                ->execute([self::encode($content), $content['deadline'] === '' ? null : $content['deadline'], $id]);
            self::event($db, $id, $school, $previous, VacancyStatus::Draft, $revision + 1,
                $previous === VacancyStatus::Published ? 'The school edited the posting. It requires renewed review.' : '');
        });
    }

    public function transition(int $id, int $actor, bool $staff, int $revision, string $action, string $note): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $actor, $staff, $revision, $action, $note): void {
            $row = $this->lock($id, $staff ? null : $actor);
            $this->checkRevision($row, $revision);
            $from = VacancyStatus::from($row['status']);
            $to = $from->actions($staff, true)[$action] ?? null;
            if ($to === null) {
                throw new DomainException('This action is unavailable at the current vacancy stage.', 409);
            }
            if ($staff) {
                if (!$this->activeAccount($actor, 'admin')) {
                    throw new DomainException('An active administrator account is required.', 403);
                }
            } else {
                $this->activeSchool($actor, $to === VacancyStatus::Submitted);
            }
            $publishedContent = null;
            if ($to === VacancyStatus::Published) {
                $school = $this->activeSchool((int) $row['school_id'], requireApproval: true);
                $publishedContent = self::encode($row['fields'] + ['published_school_name' => $school['school_name']]);
            }
            if ($to === VacancyStatus::Submitted || $to === VacancyStatus::Approved || $to === VacancyStatus::Published) {
                $problem = VacancyInput::submissionProblem($row['fields']);
                if ($problem !== null) {
                    throw new DomainException($problem, 422);
                }
            }
            if ($to === VacancyStatus::Rejected && $note === '') {
                throw new DomainException('Enter a reason for rejecting the vacancy.', 422);
            }
            $db->prepare('UPDATE vacancies SET status = ?, revision = revision + 1, content = COALESCE(?, content),
                published_at = IF(?, CURRENT_TIMESTAMP(6), published_at) WHERE id = ?')
                ->execute([$to->value, $publishedContent, (int) ($to === VacancyStatus::Published), $id]);
            self::event($db, $id, $actor, $from, $to, $revision + 1, $note);
        });
    }

    /** The vacancy lock is taken before the school record on every existing-vacancy write. */
    private function lock(int $id, ?int $school = null): array
    {
        $row = $this->read('SELECT * FROM vacancies WHERE id = ?' . ($school === null ? '' : ' AND school_id = ?') . ' FOR UPDATE',
            $school === null ? [$id] : [$id, $school])[0] ?? null;
        if ($row === null) {
            throw new DomainException('This vacancy could not be found.', 404);
        }
        return self::decode($row);
    }

    public function applicationTarget(int $id, int $teacher): array
    {
        $row = $this->lock($id);
        if ($row['status'] !== VacancyStatus::Published->value || $row['application_deadline'] < VacancyInput::today()) {
            throw new DomainException('This vacancy is no longer open.', 404);
        }
        $schoolId = (int) $row['school_id'];
        $this->lockRecruitmentAccounts($schoolId, $teacher);
        try {
            $school = $this->activeSchool($schoolId, requireApproval: true);
            return $row + ['school_name' => $row['published_school_name'] ?? $school['school_name']];
        } catch (DomainException) {
            throw new DomainException('This vacancy is no longer open.', 404);
        }
    }

    public function requireSchoolForPlacement(int $vacancy, int $teacher, int $administrator): void
    {
        $row = $this->lock($vacancy);
        $school = (int) $row['school_id'];
        $this->lockRecruitmentAccounts($school, $teacher, $administrator);
        if (!$this->activeAccount($administrator, 'admin')) {
            throw new DomainException('An active administrator account is required.', 403);
        }
        try {
            $this->activeSchool($school, requireApproval: true);
        } catch (DomainException $error) {
            throw new DomainException('Final placement requires a currently approved school account.', 409, $error);
        }
    }

    public function isSaved(int $teacher, int $vacancy): bool
    {
        return $this->read('SELECT vacancy_id FROM saved_jobs WHERE teacher_id = ? AND vacancy_id = ?', [$teacher, $vacancy]) !== [];
    }

    public function savedJobs(int $teacher, int $before): array
    {
        return $this->read('SELECT b.vacancy_id, b.saved_title, EXISTS (SELECT 1 ' . self::PUBLIC_SQL
            . ' AND v.id = b.vacancy_id) AS is_open FROM saved_jobs b WHERE b.teacher_id = ? AND b.vacancy_id < ?
            ORDER BY b.vacancy_id DESC LIMIT 21', [VacancyInput::today(), $teacher, $before]);
    }

    public function updateSavedJob(int $teacher, int $vacancy, bool $save): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($teacher, $vacancy, $save): void {
            $target = $save ? $this->applicationTarget($vacancy, $teacher) : null;
            if (!$this->activeAccount($teacher, 'teacher')) {
                throw new DomainException('An active teacher account is required.', 403);
            }
            if ($target !== null) {
                $db->prepare('INSERT INTO saved_jobs (teacher_id, vacancy_id, saved_title) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE saved_title = saved_title')->execute([$teacher, $vacancy, $target['fields']['title']]);
            } else {
                $db->prepare('DELETE FROM saved_jobs WHERE teacher_id = ? AND vacancy_id = ?')->execute([$teacher, $vacancy]);
            }
        });
    }

    public function activeAccount(int $id, string $role): bool
    {
        return $this->read('SELECT u.id FROM users u WHERE u.id = ? AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = ?)
            FOR SHARE', [$id, $role]) !== [];
    }

    private function lockRecruitmentAccounts(int $school, int $teacher, ?int $administrator = null): void
    {
        // Directory requests take these same account locks before accessing school and teacher profiles.
        $locks = [$school => 'UPDATE', $teacher => 'UPDATE'];
        if ($administrator !== null) {
            $locks[$administrator] ??= 'SHARE';
        }
        ksort($locks, SORT_NUMERIC);
        foreach ($locks as $account => $mode) {
            $this->read("SELECT id FROM users WHERE id = ? FOR $mode", [$account]);
        }
    }

    private function activeSchool(int $school, bool $requireApproval = false): array
    {
        if (!$this->activeAccount($school, 'school')) {
            throw new DomainException('An active school account is required.', 403);
        }
        $record = $this->schools->find($school, lock: true);
        if ($record === null || !SchoolPolicy::profileComplete($record) || SchoolStatus::forSchool($record) === SchoolStatus::Suspended) {
            throw new DomainException('A complete and active school profile is required.', 403);
        }
        if ($requireApproval && !SchoolPolicy::canRecruit($record)) {
            throw new DomainException('The school must be approved for this action.', 403);
        }
        return $record;
    }

    private function checkRevision(array $row, int $revision): void
    {
        if ((int) $row['revision'] !== $revision) {
            throw new DomainException('This vacancy has changed. Reopen it and try again.', 409);
        }
    }

    private static function event(PDO $db, int $id, int $actor, ?VacancyStatus $from, VacancyStatus $to, int $revision, string $note): void
    {
        $db->prepare('INSERT INTO vacancy_history (vacancy_id, actor_user_id, from_status, to_status, revision, note) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $actor, $from?->value, $to->value, $revision, $note]);
        VerificationEvents::audit($db, $actor, 'vacancy.transition', ['vacancy_id' => $id, 'from' => $from?->value, 'to' => $to->value, 'revision' => $revision]);
    }

    private function read(string $sql, array $parameters): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll();
    }

    private static function decode(array $row): array
    {
        $row['fields'] = json_decode($row['content'], true, flags: JSON_THROW_ON_ERROR);
        $row['published_school_name'] = $row['fields']['published_school_name'] ?? null;
        unset($row['fields']['published_school_name']);
        $row['fields']['deadline'] = $row['application_deadline'] ?? '';
        unset($row['content']);
        return $row;
    }

    private static function encode(array $content): string
    {
        unset($content['deadline']);
        return json_encode($content, JSON_THROW_ON_ERROR);
    }
}

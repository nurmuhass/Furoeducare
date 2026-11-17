<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Vacancy;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\TeacherEligibility;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class ApplicationRepository
{
    public function __construct(
        private readonly Closure $database,
        private readonly VacancyRepository $vacancies,
        private readonly TeacherEligibility $eligibility,
    ) {
    }

    public function submit(int $vacancy, int $teacher, int $revision, string $statement = ''): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($vacancy, $teacher, $revision, $statement): void {
            $target = $this->vacancies->applicationTarget($vacancy, $teacher);
            $profile = $this->eligibility->applicationProfile($teacher, lock: true);
            if ($profile === null) {
                throw new DomainException('Verify your email and complete your teacher profile before applying.', 403);
            }
            if ($this->alreadyApplied($teacher, $vacancy)) {
                throw new DomainException('You already applied to this vacancy.', 409);
            }
            if ($revision !== (int) $target['revision']) {
                throw new DomainException('The vacancy changed. Review the current details before applying.', 409);
            }
            $db->prepare('INSERT INTO vacancy_applications (vacancy_id, teacher_id, vacancy_revision, job_title, job_snapshot,
                candidate_reference, application_statement, profile_snapshot)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$vacancy, $teacher, $target['revision'], $target['fields']['title'],
                    json_encode(['school_name' => $target['school_name'], 'fields' => $target['fields']], JSON_THROW_ON_ERROR),
                    'CA-' . strtoupper(bin2hex(random_bytes(16))), $statement,
                    json_encode(ApplicationData::profile($profile), JSON_THROW_ON_ERROR)]);
            self::event($db, (int) $db->lastInsertId(), $teacher, null, ApplicationStatus::Submitted, '');
        });
    }

    public function alreadyApplied(int $teacher, int $vacancy): bool
    {
        return $this->read('SELECT id FROM vacancy_applications WHERE teacher_id = ? AND vacancy_id = ?', [$teacher, $vacancy]) !== [];
    }

    public function list(int $before, ?int $teacher, ?int $vacancy = null): array
    {
        if ($teacher !== null) {
            return $this->read('SELECT id, candidate_reference, job_title, status, created_at FROM vacancy_applications
                WHERE teacher_id = ? AND id < ? ORDER BY id DESC LIMIT 21', [$teacher, $before]);
        }
        return $this->read('SELECT a.id, a.candidate_reference, a.job_title, a.status, a.created_at FROM vacancy_applications a
            WHERE a.id < ?'
                . ($vacancy === null ? '' : ' AND a.vacancy_id = ?') . ' ORDER BY a.id DESC LIMIT 21',
            $vacancy === null ? [$before] : [$before, $vacancy]);
    }

    public function find(int $id, ?int $teacher): ?array
    {
        $row = $this->read('SELECT a.id, a.vacancy_id, a.teacher_id, a.vacancy_revision, a.job_title, a.job_snapshot, a.status, a.created_at,
            a.candidate_reference, a.application_statement, a.profile_snapshot FROM vacancy_applications a
            WHERE a.id = ?' . ($teacher === null ? '' : ' AND a.teacher_id = ?'), $teacher === null ? [$id] : [$id, $teacher])[0] ?? null;
        if ($row !== null) {
            $row['job'] = json_decode($row['job_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $row['profile'] = $row['profile_snapshot'] === null ? null : json_decode($row['profile_snapshot'], true, flags: JSON_THROW_ON_ERROR);
            unset($row['job_snapshot'], $row['profile_snapshot']);
        }
        return $row;
    }

    public function history(int $id): array
    {
        return $this->read('SELECT from_status, to_status, note, created_at FROM vacancy_application_history
            WHERE application_id = ? ORDER BY id DESC', [$id]);
    }

    public function counts(int $teacher): array
    {
        return $this->read("SELECT COUNT(*) AS total,
            COUNT(CASE WHEN status NOT IN ('successful','unsuccessful','withdrawn') THEN 1 END) AS active,
            COUNT(CASE WHEN status = 'interview_invited' THEN 1 END) AS interviews
            FROM vacancy_applications WHERE teacher_id = ?", [$teacher])[0];
    }

    public function transition(int $id, int $actor, bool $staff, string $expected, ApplicationStatus $to, string $note): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $actor, $staff, $expected, $to, $note): void {
            $row = $this->read('SELECT status, teacher_id, vacancy_id FROM vacancy_applications WHERE id = ?' . ($staff ? '' : ' AND teacher_id = ?')
                . ' FOR UPDATE', $staff ? [$id] : [$id, $actor])[0] ?? null;
            if ($row === null) {
                throw new DomainException('This application could not be found.', 404);
            }
            if ($to !== ApplicationStatus::Successful && !$this->vacancies->activeAccount($actor, $staff ? 'admin' : 'teacher')) {
                throw new DomainException('An active account is required.', 403);
            }
            $from = ApplicationStatus::from($row['status']);
            if ($expected !== $from->value || !in_array($to, $from->transitions($staff), true)) {
                throw new DomainException('This application changed or the requested action is unavailable.', 409);
            }
            if ($to === ApplicationStatus::Successful) {
                $this->vacancies->requireSchoolForPlacement((int) $row['vacancy_id'], (int) $row['teacher_id'], $actor);
                try {
                    $this->eligibility->requireForPlacement((int) $row['teacher_id']);
                } catch (DomainException $error) {
                    throw new DomainException($error->getMessage(), 409, $error);
                }
            }
            $db->prepare('UPDATE vacancy_applications SET status = ? WHERE id = ?')->execute([$to->value, $id]);
            self::event($db, $id, $actor, $from, $to, $note);
        });
    }

    private static function event(PDO $db, int $id, int $actor, ?ApplicationStatus $from, ApplicationStatus $to, string $note): void
    {
        $db->prepare('INSERT INTO vacancy_application_history (application_id, actor_user_id, from_status, to_status, note)
            VALUES (?, ?, ?, ?, ?)')->execute([$id, $actor, $from?->value, $to->value, $note]);
        VerificationEvents::audit($db, $actor, 'vacancy.application.transition', ['application_id' => $id, 'from' => $from?->value, 'to' => $to->value]);
    }

    private function read(string $sql, array $values): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($values);
        return $query->fetchAll();
    }
}

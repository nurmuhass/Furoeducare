<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Placement;

use DomainException;
use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Directory\PublicDirectory;
use Furoeducare\Modules\Disclosure\CandidateRepository;
use Furoeducare\Modules\Parent\TutoringInput;
use Furoeducare\Modules\Parent\TutoringRepository;
use Furoeducare\Modules\Vacancy\ApplicationRepository;
use Furoeducare\Modules\Vacancy\ApplicationStatus;
use PDO;

final class PlacementSources
{
    private const EMPLOYMENT_STAGES = ['under_review', 'shortlisted', 'interview_invited', 'offer_pending'];

    public function __construct(
        private readonly CandidateRepository $accounts,
        private readonly PublicDirectory $directory,
    ) {
    }

    public function employment(PDO $db, int $application): array
    {
        $link = self::row($db, 'SELECT vacancy_id FROM vacancy_applications WHERE id = ?', [$application]);
        if ($link === null) {
            throw new DomainException('This employment application could not be found.', 404);
        }
        $vacancy = self::row($db, "SELECT id, school_id, content->>'$.positions' AS positions,
            status FROM vacancies WHERE id = ? FOR UPDATE", [$link['vacancy_id']]);
        $source = self::row($db, 'SELECT * FROM vacancy_applications WHERE id = ? AND vacancy_id = ? FOR UPDATE',
            [$application, $link['vacancy_id']]);
        if ($vacancy === null || $source === null) {
            throw new DomainException('This employment application could not be found.', 404);
        }
        return $source + ['requester_id' => $vacancy['school_id'], 'positions' => $vacancy['positions'],
            'vacancy_status' => $vacancy['status']];
    }

    public function tutoring(PDO $db, int $id): array
    {
        $source = self::row($db, 'SELECT * FROM parent_tutoring_requests WHERE id = ? FOR UPDATE', [$id]);
        if ($source === null) {
            throw new DomainException('This tutoring request could not be found.', 404);
        }
        return $source + ['requester_id' => $source['parent_id']];
    }

    public function tutor(PDO $db, array $source, string $reference, string $mode): int
    {
        $details = $source['lesson_details'] === null ? [] : json_decode($source['lesson_details'], true, flags: JSON_THROW_ON_ERROR);
        $requested = $details['mode'] ?? '';
        $candidate = self::row($db, 'SELECT teacher_id FROM tutoring_candidates WHERE request_id = ? AND teacher_reference = ? FOR SHARE',
            [$source['id'], $reference]);
        if (!in_array($mode, ['home', 'online'], true) || !in_array($requested, [$mode, 'either'], true) || $candidate === null) {
            throw new DomainException('Choose a shortlisted tutor and a lesson format allowed by the request.', 409);
        }
        return (int) $candidate['teacher_id'];
    }

    public function requireLiveTutor(string $reference, string $mode, int $teacher): void
    {
        $target = $this->directory->lockTarget($reference);
        if ($target === null || (int) $target['teacher_id'] !== $teacher
            || ((int) $target['current_modes'] & TutoringInput::modeMask($mode)) === 0) {
            throw new DomainException('The shortlisted tutor is no longer available for this lesson format.', 409);
        }
    }

    public function requireReady(array $source, string $kind): void
    {
        $ready = $kind === 'employment'
            ? in_array($source['status'], self::EMPLOYMENT_STAGES, true) && in_array($source['vacancy_status'], ['published', 'closed'], true)
            : $source['status'] === 'arrangement_pending';
        if (!$ready) {
            throw new DomainException('The application or tutoring request is unavailable for placement at its current stage.', 409);
        }
    }

    public function requireParties(int $requester, int $teacher, string $kind, ?int $staff): void
    {
        $this->accounts->lockAccounts($requester, $teacher, $staff);
        if ($staff !== null) {
            $this->accounts->requireAdmin($staff, lock: true);
            if ($staff === $requester || $staff === $teacher) {
                throw new DomainException('Assign an administrator who is independent of the placement.', 403);
            }
        }
        $role = $kind === 'employment' ? Role::School : Role::Parent;
        if ($requester === $teacher || !$this->accounts->eligible($requester, $role, lock: true)
            || !$this->accounts->hasRole($teacher, Role::Teacher, lock: true)) {
            throw new DomainException('Placement requires an eligible requesting account and teacher.', 409);
        }
    }

    public function requireEmploymentCapacity(PDO $db, array $source): void
    {
        $limit = max(1, (int) $source['positions']);
        $query = $db->prepare("SELECT id FROM vacancy_applications WHERE vacancy_id = ? AND status = 'successful' FOR SHARE");
        $query->execute([$source['vacancy_id']]);
        if (count($query->fetchAll()) >= $limit) {
            throw new DomainException('All positions on this vacancy have already been filled.', 409);
        }
    }

    public function syncEmployment(PDO $db, array $source, int $actor, PlacementStatus $stage): void
    {
        $target = match ($stage) {
            PlacementStatus::Shortlisted => ApplicationStatus::Shortlisted,
            PlacementStatus::DiscussionScheduled => ApplicationStatus::InterviewInvited,
            PlacementStatus::OfferPending => ApplicationStatus::OfferPending,
            PlacementStatus::Confirmed => ApplicationStatus::Successful,
            PlacementStatus::Rejected => ApplicationStatus::Unsuccessful,
            default => null,
        };
        if ($target === null) {
            return;
        }
        $from = ApplicationStatus::fromStored($source['status']);
        $order = [ApplicationStatus::UnderReview, ApplicationStatus::Shortlisted, ApplicationStatus::InterviewInvited,
            ApplicationStatus::OfferPending, ApplicationStatus::Successful];
        $end = array_search($target, $order, true);
        $start = array_search($from, $order, true);
        if ($target === ApplicationStatus::Unsuccessful && in_array($target, $from->transitions(true), true)) {
            ApplicationRepository::recordPlacementStage($db, (int) $source['id'], $actor, $from, $target);
        } elseif ($end !== false && $start !== false) {
            for ($position = $start + 1; $position <= $end; $position++) {
                ApplicationRepository::recordPlacementStage($db, (int) $source['id'], $actor, $order[$position - 1], $order[$position]);
            }
        }
    }

    public function syncTutoring(PDO $db, array $source, int $actor, int $teacher, PlacementStatus $stage): void
    {
        if ($stage === PlacementStatus::Confirmed) {
            TutoringRepository::recordPlacementConfirmation($db, $source, $actor, $teacher);
        }
    }

    private static function row(PDO $db, string $sql, array $values): ?array
    {
        $query = $db->prepare($sql);
        $query->execute($values);
        return $query->fetch() ?: null;
    }
}

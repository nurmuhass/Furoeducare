<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use Closure;
use DomainException;
use Furoeducare\Modules\Teacher\ProfileCompletion;
use Furoeducare\Modules\Teacher\TeacherRepository;
use LogicException;
use PDO;

final class TeacherEligibility
{
    public function __construct(private readonly Closure $database, private readonly TeacherRepository $profiles)
    {
    }

    public function canApply(int $teacher, bool $lock = false): bool
    {
        return $this->applicationProfile($teacher, $lock) !== null;
    }

    public function applicationProfile(int $teacher, bool $lock = false): ?array
    {
        $account = $this->account($teacher, $lock);
        if ($account === null || $account['email_verified_at'] === null || $account['suspended_at'] !== null) {
            return null;
        }
        $profile = $this->profiles->profile($teacher, $lock);
        return self::canUseRecruitment($account, $profile) ? $profile : null;
    }

    /** Call in the same transaction as the final placement write. */
    public function requireForPlacement(int $teacher): int
    {
        $db = ($this->database)();
        if (!$db->inTransaction()) {
            throw new LogicException('Final placement eligibility must be checked in the placement transaction.');
        }
        $account = $this->account($teacher, lock: true);
        $profile = $this->profiles->profile($teacher, lock: true);
        if ($account === null || !self::canUseRecruitment($account, $profile)
            || $profile['verification_status'] !== TeacherStatus::Verified->value || $profile['professional_verified_at'] === null
            || !self::hasAcceptedCv($db, $teacher)) {
            throw new DomainException('Final placement requires current professional verification.');
        }

        $decision = $db->prepare("SELECT checks FROM teacher_verification_reviews
            WHERE teacher_id = ? AND revision = ? AND decision = 'verified' FOR UPDATE");
        $decision->execute([$teacher, $profile['verification_revision']]);
        $checks = json_decode((string) $decision->fetchColumn(), true);
        if (!is_array($checks) || !ReviewInput::passed($checks, ReviewInput::TEACHER_CHECKS)) {
            throw new DomainException('Final placement requires all professional checks to be confirmed.');
        }
        return (int) $profile['verification_revision'];
    }

    public static function complete(array $profile): bool
    {
        return array_filter(ProfileCompletion::missing($profile)) === [];
    }

    public static function hasAcceptedCv(PDO $db, int $teacher): bool
    {
        $cv = $db->prepare("SELECT 1 FROM teacher_documents d JOIN teacher_document_reviews r ON r.document_id = d.id
            WHERE d.cv_teacher_id = ? AND r.decision = 'accepted' FOR UPDATE");
        $cv->execute([$teacher]);
        return $cv->fetchColumn() !== false;
    }

    private static function canUseRecruitment(array $account, ?array $profile): bool
    {
        return $profile !== null && $account['email_verified_at'] !== null && $account['suspended_at'] === null
            && $profile['verification_status'] !== TeacherStatus::Suspended->value && self::complete($profile);
    }

    private function account(int $teacher, bool $lock = false): ?array
    {
        $query = ($this->database)()->prepare(
            "SELECT u.email_verified_at, u.suspended_at FROM users u
             WHERE u.id = ? AND EXISTS (
                SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'teacher'
             )" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$teacher]);
        return $query->fetch() ?: null;
    }
}

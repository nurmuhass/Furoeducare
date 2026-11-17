<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use Closure;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Teacher\TeacherRepository;
use PDO;

final class VerificationRepository
{
    public function __construct(private readonly Closure $database, private readonly TeacherRepository $profiles)
    {
    }

    public function teachers(int $before, ?TeacherStatus $status): array
    {
        return $this->all('SELECT user_id, first_name, last_name, verification_status FROM teacher_profiles
            WHERE user_id < ?' . ($status === null ? '' : ' AND verification_status = ?') . ' ORDER BY user_id DESC LIMIT 31',
            $status === null ? [$before] : [$before, $status->value]);
    }

    public function documents(int $before): array
    {
        return $this->all('SELECT d.id, d.display_name, d.kind, t.first_name, t.last_name
            FROM teacher_documents d JOIN teacher_profiles t ON t.user_id = d.teacher_id
            LEFT JOIN teacher_document_reviews r ON r.document_id = d.id
            WHERE d.id < ? AND d.delete_requested_at IS NULL AND r.document_id IS NULL ORDER BY d.id DESC LIMIT 31', [$before]);
    }

    public function teacher(int $id): ?array
    {
        $account = $this->account($id);
        $profile = $account === null ? null : $this->profiles->profile($id);
        if ($profile === null) {
            return null;
        }
        $profile['sections']['documents']['files'] = $this->all(
            'SELECT d.id, d.display_name, d.kind, r.decision FROM teacher_documents d
             LEFT JOIN teacher_document_reviews r ON r.document_id = d.id
             WHERE d.teacher_id = ? AND d.delete_requested_at IS NULL ORDER BY d.id DESC', [$id]
        );
        return $profile + $account;
    }

    public function document(int $id): ?array
    {
        return $this->all('SELECT d.id, d.teacher_id, d.display_name, d.kind, d.bytes, d.created_at, t.first_name, t.last_name,
            r.decision, r.reason, r.created_at AS reviewed_at, u.email AS reviewer_email
            FROM teacher_documents d JOIN teacher_profiles t ON t.user_id = d.teacher_id
            LEFT JOIN teacher_document_reviews r ON r.document_id = d.id LEFT JOIN users u ON u.id = r.reviewer_id
            WHERE d.id = ? AND d.delete_requested_at IS NULL', [$id])[0] ?? null;
    }

    public function history(int $teacher): array
    {
        return $this->all('SELECT r.decision, r.checks, r.reason, r.created_at, u.email AS reviewer_email
            FROM teacher_verification_reviews r JOIN users u ON u.id = r.reviewer_id
            WHERE r.teacher_id = ? ORDER BY r.revision DESC LIMIT 30', [$teacher]);
    }

    public function removedDocumentReviews(int $teacher): array
    {
        return $this->all('SELECT r.kind, r.decision, r.reason, r.created_at, u.email AS reviewer_email
            FROM teacher_document_reviews r JOIN users u ON u.id = r.reviewer_id
            WHERE r.teacher_id = ? AND r.document_id IS NULL ORDER BY r.id DESC LIMIT 30', [$teacher]);
    }

    public function submitTeacher(int $id): string
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($id): string {
            $account = $this->account($id, lock: true);
            $profile = $this->profiles->profile($id, lock: true);
            if ($profile === null || $account === null) {
                return 'missing';
            }
            if ($account['email_verified_at'] === null || $account['suspended_at'] !== null) {
                return 'forbidden';
            }

            $status = TeacherStatus::from($profile['verification_status']);
            if (!in_array($status, [TeacherStatus::Incomplete, TeacherStatus::CorrectionsRequired], true)) {
                return 'transition';
            }
            if (!TeacherEligibility::complete($profile)) {
                return 'incomplete';
            }
            if ($status === TeacherStatus::CorrectionsRequired) {
                $last = $db->prepare("SELECT MAX(revision) FROM teacher_verification_reviews WHERE teacher_id = ? AND decision = 'corrections_required'");
                $last->execute([$id]);
                if ((int) $last->fetchColumn() >= (int) $profile['verification_revision']) {
                    return 'unchanged';
                }
            }

            $db->prepare("UPDATE teacher_profiles SET verification_status = 'pending', verification_revision = verification_revision + 1
                WHERE user_id = ?")->execute([$id]);
            VerificationEvents::audit($db, $id, 'verification.teacher.submitted', [
                'user_id' => $id, 'from' => $status->value, 'to' => TeacherStatus::Pending->value,
            ]);
            return 'saved';
        });
    }

    public function reviewTeacher(int $staff, int $id, int $revision, TeacherStatus $decision, string $reason, array $checks): string
    {
        if ($staff === $id) {
            return 'forbidden';
        }
        return Database::transaction(($this->database)(), function (PDO $db) use ($staff, $id, $revision, $decision, $reason, $checks): string {
            $account = $this->account($id, lock: true);
            $profile = $this->profiles->profile($id, lock: true);
            if ($account === null || $profile === null) {
                return 'missing';
            }
            if ((int) $profile['verification_revision'] !== $revision) {
                return 'stale';
            }
            $status = TeacherStatus::from($profile['verification_status']);
            if (!in_array($decision, $status->staffDecisions(), true)) {
                return 'transition';
            }
            if ($decision === TeacherStatus::Verified) {
                if ($account['email_verified_at'] === null || $account['suspended_at'] !== null || !TeacherEligibility::complete($profile)) {
                    return 'incomplete';
                }
                if (!ReviewInput::passed($checks, ReviewInput::TEACHER_CHECKS)) {
                    return 'checks';
                }
                if (!TeacherEligibility::hasAcceptedCv($db, $id)) {
                    return 'cv';
                }
            }

            $now = $db->query('SELECT CURRENT_TIMESTAMP(6)')->fetchColumn();
            $suspension = $decision === TeacherStatus::Suspended || $status === TeacherStatus::Suspended
                ? VerificationSuspension::update($db, $id, $decision === TeacherStatus::Suspended,
                    $account['suspended_at'], $profile['teacher_suspension_at'], fromTeacher: true)
                : null;

            $db->prepare('UPDATE teacher_profiles SET verification_status = ?, professional_verified_at = ?,
                teacher_suspension_at = ?, verification_revision = verification_revision + 1 WHERE user_id = ?')
                ->execute([$decision->value, $decision === TeacherStatus::Verified ? $now : null, $suspension, $id]);
            $db->prepare('INSERT INTO teacher_verification_reviews (teacher_id, reviewer_id, revision, decision, checks, reason)
                VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $staff, $revision + 1, $decision->value, json_encode($checks, JSON_THROW_ON_ERROR), $reason]);
            VerificationEvents::record($db, $staff, $id, 'verification.teacher.review', [
                'from' => $status->value, 'to' => $decision->value, 'revision' => $revision + 1, 'checks' => $checks,
            ], $decision->label(), $reason);
            return 'saved';
        });
    }

    public function reviewDocument(int $staff, int $teacher, int $id, string $decision, string $reason): string
    {
        if ($staff === $teacher) {
            return 'forbidden';
        }
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            return 'transition';
        }
        return Database::transaction(($this->database)(), function (PDO $db) use ($staff, $teacher, $id, $decision, $reason): string {
            if ($this->account($teacher, lock: true) === null) {
                return 'missing';
            }
            $profile = $db->prepare('SELECT user_id FROM teacher_profiles WHERE user_id = ? FOR UPDATE');
            $profile->execute([$teacher]);
            if ($profile->fetchColumn() === false) {
                return 'missing';
            }
            $doc = $this->document($id);
            if ($doc === null || (int) $doc['teacher_id'] !== $teacher) {
                return 'missing';
            }
            if ($doc['decision'] !== null) {
                return 'transition';
            }

            $db->prepare('INSERT INTO teacher_document_reviews (id, document_id, teacher_id, reviewer_id, kind, decision, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $id, $teacher, $staff, $doc['kind'], $decision, $reason]);
            $db->prepare("UPDATE teacher_profiles SET verification_revision = verification_revision + 1
                WHERE user_id = ? AND verification_status = 'pending'")->execute([$teacher]);
            VerificationEvents::record($db, $staff, $teacher, 'verification.document.review', [
                'document_id' => $id, 'kind' => $doc['kind'], 'to' => $decision,
            ], ucfirst($doc['kind'] === 'cv' ? 'CV' : $doc['kind']) . ' ' . $decision, $reason);
            return 'saved';
        });
    }

    public function auditDownload(int $staff, int $teacher, int $document): void
    {
        VerificationEvents::audit(($this->database)(), $staff, 'verification.document.download', [
            'user_id' => $teacher, 'document_id' => $document,
        ]);
    }

    private function account(int $id, bool $lock = false): ?array
    {
        return $this->all('SELECT email, email_verified_at, suspended_at FROM users WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])[0] ?? null;
    }

    private function all(string $sql, array $parameters): array
    {
        $query = ($this->database)()->prepare($sql);
        $query->execute($parameters);
        return $query->fetchAll();
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Auth\LegalNotice;
use Furoeducare\Modules\Verification\ReviewInput;
use Furoeducare\Modules\Verification\VerificationEvents;
use Furoeducare\Modules\Verification\VerificationSuspension;
use PDO;
use PDOException;

final class SchoolRepository
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $query = ($this->database)()->prepare('SELECT * FROM school_onboarding WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function findForReview(int $id): ?array
    {
        $query = ($this->database)()->prepare(
            'SELECT s.*, u.email AS representative_email, u.email_verified_at, u.suspended_at AS account_suspended_at
             FROM school_onboarding s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?'
        );
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    /** Called inside the shared email activation transaction. */
    public function create(int $id, array $values): void
    {
        $this->guardLegacyName($values['school_name'], $id);
        $columns = implode(', ', SchoolInput::PROFILE_FIELDS);
        try {
            ($this->database)()->prepare(
                "INSERT INTO school_onboarding (user_id, $columns, institution_name_key, institution_city_key,
                    terms_version, privacy_version, accepted_at, review_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6), 'pending')"
            )->execute([$id, ...$this->profileValues($values), LegalNotice::TERMS, LegalNotice::PRIVACY]);
        } catch (PDOException $error) {
            $this->translateDuplicate($error);
        }
    }

    public function saveProfile(int $id, array $values): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $values): void {
            $school = $this->editable($id);
            $wasComplete = SchoolPolicy::profileComplete($school);
            $changed = !$wasComplete;
            foreach (SchoolInput::PROFILE_FIELDS as $field) {
                $changed = $changed || $values[$field] !== $school[$field];
            }
            if (!$changed) {
                return;
            }

            if (SchoolInput::institutionKey($values['school_name']) !== SchoolInput::institutionKey($school['school_name'])) {
                $this->guardLegacyName($values['school_name'], $id);
            }
            $previousStatus = SchoolStatus::forSchool($school);
            $status = $previousStatus;
            $identityChanged = !$wasComplete || $values['category'] !== $school['category'] || $values['address'] !== $school['address']
                || $values['state_code'] !== $school['state_code'] || SchoolInput::institutionKey($values['city']) !== $school['institution_city_key']
                || SchoolInput::institutionKey($values['school_name']) !== $school['institution_name_key'];
            $representativeChanged = $values['representative_name'] !== $school['representative_name']
                || $values['representative_position'] !== $school['representative_position']
                || $values['contact_email'] !== $school['contact_email'] || $values['contact_phone'] !== $school['contact_phone'];
            if ($status === SchoolStatus::CorrectionsRequired || ($status === SchoolStatus::Approved && ($identityChanged || $representativeChanged))) {
                $status = SchoolStatus::Pending;
            }
            $fields = implode(' = ?, ', SchoolInput::PROFILE_FIELDS) . ' = ?';
            try {
                $db->prepare(
                    "UPDATE school_onboarding SET $fields, institution_name_key = ?, institution_city_key = ?,
                        terms_version = COALESCE(terms_version, ?), privacy_version = COALESCE(privacy_version, ?),
                        accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP(6)), review_status = ?, approved_at = ?,
                        revision = revision + 1 WHERE user_id = ?"
                )->execute([...$this->profileValues($values), LegalNotice::TERMS, LegalNotice::PRIVACY, $status->value,
                    $status === SchoolStatus::Approved ? $school['approved_at'] : null, $id]);
            } catch (PDOException $error) {
                $this->translateDuplicate($error);
            }
            if ($previousStatus !== $status) {
                VerificationEvents::record($db, $id, $id, 'verification.school.resubmitted',
                    ['revision' => (int) $school['revision'] + 1, 'from' => $previousStatus->value, 'to' => $status->value], 'School profile returned to review',
                    'Furoeducare will review your updated school information.');
            }
        });
    }

    /** Settings changes preserve the organisation and its approval-review boundary. */
    public function updateContactPhone(int $id, string $phone, Closure $authorize): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($id, $phone, $authorize): void {
            $school = $this->editable($id);
            $authorize($db);
            if ($school['contact_phone'] === $phone) return;
            $status = SchoolStatus::forSchool($school);
            $db->prepare("UPDATE school_onboarding SET contact_phone = ?, revision = revision + 1,
                review_status = IF(review_status = 'approved' OR approved_at IS NOT NULL, 'pending', review_status),
                approved_at = NULL WHERE user_id = ?")->execute([$phone, $id]);
            VerificationEvents::audit($db, $id, 'account.phone_updated', ['profile_role' => 'school']);
            if ($status === SchoolStatus::Approved) {
                VerificationEvents::record($db, $id, $id, 'verification.school.resubmitted',
                    ['revision' => (int) $school['revision'] + 1, 'from' => $status->value, 'to' => SchoolStatus::Pending->value],
                    'School profile returned to review', 'Furoeducare will review your updated contact information.');
            }
        });
    }

    public function browse(int $before, ?SchoolStatus $status = null): array
    {
        $filter = match ($status) {
            null => '',
            SchoolStatus::Pending => ' AND (s.review_status = ? OR (s.review_status IS NULL AND s.approved_at IS NULL))',
            default => ' AND s.review_status = ?',
        };
        $query = ($this->database)()->prepare(
            'SELECT s.user_id, s.school_name, s.city, s.state_code, s.review_status, s.approved_at, s.institution_name_key, s.accepted_at
             FROM school_onboarding s WHERE s.user_id < ?' . $filter . ' ORDER BY s.user_id DESC LIMIT 31'
        );
        $query->execute($status === null ? [$before] : [$before, $status->value]);
        return $query->fetchAll();
    }

    public function reviews(int $school, bool $withReviewer = false): array
    {
        $fields = $withReviewer ? ', a.email AS reviewer_email, r.checks' : '';
        $join = $withReviewer ? ' JOIN users a ON a.id = r.reviewer_id' : '';
        $query = ($this->database)()->prepare(
            "SELECT r.decision, r.reason, r.created_at$fields FROM school_reviews r$join
             WHERE r.school_id = ? ORDER BY r.id DESC LIMIT 30"
        );
        $query->execute([$school]);
        return $query->fetchAll();
    }

    public function review(int $reviewer, int $id, int $revision, SchoolStatus $decision, string $reason, array $checks): string
    {
        if ($reviewer === $id) {
            return 'self';
        }
        return Database::transaction(($this->database)(), function (PDO $db) use ($reviewer, $id, $revision, $decision, $reason, $checks): string {
            if ($decision === SchoolStatus::Suspended) {
                \Furoeducare\Modules\Admin\StaffAccess::lockRoles($db);
            }
            \Furoeducare\Modules\Admin\StaffAccess::requireIn($db, $reviewer, 'verification.manage', true);
            $query = $db->prepare('SELECT suspended_at, email_verified_at FROM users WHERE id = ? FOR UPDATE');
            $query->execute([$id]);
            $account = $query->fetch();
            $school = $this->find($id, lock: true);
            if ($school === null || $account === false) {
                return 'missing';
            }
            if ((int) $school['revision'] !== $revision) {
                return 'stale';
            }
            if ($decision === SchoolStatus::Approved && (!SchoolPolicy::profileComplete($school) || $account['email_verified_at'] === null)) {
                return 'incomplete';
            }
            $status = SchoolStatus::forSchool($school);
            if (!in_array($decision, $status->allowedDecisions(), true)) {
                return 'transition';
            }
            if ($decision === SchoolStatus::Approved && !ReviewInput::passed($checks, ReviewInput::SCHOOL_CHECKS)) {
                return 'checks';
            }
            if ($decision === SchoolStatus::Approved && $account['suspended_at'] !== null
                && $school['school_suspension_at'] !== $account['suspended_at']) {
                return 'suspended';
            }

            $now = $db->query('SELECT CURRENT_TIMESTAMP(6)')->fetchColumn();
            $suspension = $decision === SchoolStatus::Suspended || $status === SchoolStatus::Suspended
                ? VerificationSuspension::update($db, $id, $decision === SchoolStatus::Suspended,
                    $account['suspended_at'], $school['school_suspension_at'], fromTeacher: false)
                : null;

            $db->prepare('UPDATE school_onboarding SET review_status = ?, approved_at = ?, school_suspension_at = ?, revision = revision + 1 WHERE user_id = ?')
                ->execute([$decision->value, $decision === SchoolStatus::Approved ? $now : null, $suspension, $id]);
            $db->prepare('INSERT INTO school_reviews (school_id, reviewer_id, decision, checks, reason, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $reviewer, $decision->value, json_encode($checks, JSON_THROW_ON_ERROR), $reason, $now]);
            VerificationEvents::record($db, $reviewer, $id, 'verification.school.review', [
                'from' => $status->value, 'to' => $decision->value, 'revision' => $revision + 1, 'checks' => $checks,
            ], 'School approval · ' . $decision->label(), $reason);
            return 'saved';
        });
    }

    public function drafts(int $school, int $before): array
    {
        $query = ($this->database)()->prepare(
            'SELECT id, title, updated_at FROM school_recruitment_drafts WHERE school_id = ? AND id < ? ORDER BY id DESC LIMIT 31'
        );
        $query->execute([$school, $before]);
        return $query->fetchAll();
    }

    public function draft(int $school, int $id): ?array
    {
        $query = ($this->database)()->prepare('SELECT id, title, requirements FROM school_recruitment_drafts WHERE school_id = ? AND id = ?');
        $query->execute([$school, $id]);
        return $query->fetch() ?: null;
    }

    public function saveDraft(int $school, ?int $id, array $fields): ?int
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($school, $id, $fields): ?int {
            $this->editable($school);
            if ($id === null) {
                $db->prepare('INSERT INTO school_recruitment_drafts (school_id, title, requirements) VALUES (?, ?, ?)')
                    ->execute([$school, $fields['title'], $fields['requirements']]);
                return (int) $db->lastInsertId();
            }
            if ($this->draft($school, $id) === null) {
                return null;
            }
            $db->prepare('UPDATE school_recruitment_drafts SET title = ?, requirements = ? WHERE school_id = ? AND id = ?')
                ->execute([$fields['title'], $fields['requirements'], $school, $id]);
            return $id;
        });
    }

    private function editable(int $id): array
    {
        $account = ($this->database)()->prepare('SELECT email_verified_at, suspended_at FROM users WHERE id = ? FOR UPDATE');
        $account->execute([$id]);
        $user = $account->fetch();
        $school = $this->find($id, lock: true);
        if ($user === false || $user['email_verified_at'] === null || $user['suspended_at'] !== null
            || $school === null || SchoolStatus::forSchool($school) === SchoolStatus::Suspended) {
            throw new DomainException('This school account cannot be updated.');
        }
        return $school;
    }

    private function profileValues(array $values): array
    {
        return [
            ...array_map(static fn (string $field): string => $values[$field], SchoolInput::PROFILE_FIELDS),
            SchoolInput::institutionKey($values['school_name']),
            SchoolInput::institutionKey($values['city']),
        ];
    }

    private function guardLegacyName(string $name, int $owner): void
    {
        // Without a location, a legacy account may already represent the proposed institution.
        $query = ($this->database)()->prepare(
            "SELECT 1 FROM school_onboarding WHERE state_code IS NULL AND user_id <> ?
             AND REGEXP_REPLACE(school_name, ?, '') = ? LIMIT 1"
        );
        $query->execute([$owner, '[^\p{L}\p{N}\p{M}]', SchoolInput::institutionKey($name)]);
        if ($query->fetchColumn() !== false) {
            throw new InstitutionAlreadyRegistered();
        }
    }

    private function translateDuplicate(PDOException $error): never
    {
        if (($error->errorInfo[1] ?? null) === 1062 && str_contains($error->getMessage(), 'uq_school_onboarding_institution')) {
            throw new InstitutionAlreadyRegistered();
        }
        throw $error;
    }
}

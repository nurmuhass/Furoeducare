<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Teacher\Cv\CvJobs;
use Furoeducare\Modules\Verification\TeacherStatus;
use Furoeducare\Modules\Verification\TeacherEligibility;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;
use InvalidArgumentException;
use RuntimeException;

final class TeacherRepository
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function create(int $id, array $registration): void
    {
        ($this->database)()->prepare(
            'INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE user_id = user_id'
        )->execute([
            $id, $registration['category'], $registration['first_name'], $registration['last_name'], $registration['phone'],
            TeacherInput::TERMS, TeacherInput::PRIVACY,
        ]);
    }

    public function profile(int $id, bool $lock = false): ?array
    {
        $db = ($this->database)();
        $locking = $lock ? ' FOR UPDATE' : '';
        $statement = $db->prepare('SELECT * FROM teacher_profiles WHERE user_id = ?' . $locking);
        $statement->execute([$id]);
        $profile = $statement->fetch();
        if ($profile === false) {
            return null;
        }

        $profile['sections'] = json_decode($profile['sections'], true, flags: JSON_THROW_ON_ERROR);
        foreach ([Section::Qualifications, Section::Experience] as $section) {
            [$table] = $this->entries($section);
            $statement = $db->prepare("SELECT * FROM $table WHERE teacher_id = ? ORDER BY id" . $locking);
            $statement->execute([$id]);
            $profile['sections'][$section->value]['rows'] = $statement->fetchAll();
        }
        $statement = $db->prepare('SELECT d.id, d.kind, d.display_name, d.media_type, d.bytes, d.created_at, r.decision AS review_status
            FROM teacher_documents d LEFT JOIN teacher_document_reviews r ON r.document_id = d.id
            WHERE d.teacher_id = ? AND d.delete_requested_at IS NULL ORDER BY d.id DESC' . ($lock ? ' FOR UPDATE OF d' : ''));
        $statement->execute([$id]);
        $profile['sections']['documents']['files'] = $statement->fetchAll();
        return $profile;
    }

    public function save(int $id, Section $section, array $data): void
    {
        if ($section === Section::Personal) {
            $this->forTeacher($id, static function (PDO $db) use ($id, $data): void {
                $db->prepare(
                    "UPDATE teacher_profiles SET first_name = ?, last_name = ?, phone = ?,
                     sections = JSON_SET(sections, '$.personal', CAST(? AS JSON)) WHERE user_id = ?"
                )->execute([
                    $data['first_name'], $data['last_name'], $data['phone'],
                    json_encode(['nysc' => $data['nysc'] ?? []], JSON_THROW_ON_ERROR), $id,
                ]);
            });
            return;
        }

        if ($section === Section::Qualifications || $section === Section::Experience) {
            $this->forTeacher($id, function (PDO $db) use ($id, $section, $data): void {
                [$table] = $this->entries($section);
                $db->prepare("DELETE FROM $table WHERE teacher_id = ?")->execute([$id]);
                $this->insertEntries($db, $id, $section, $data['rows']);
                $this->saveSection($db, $id, $section, $section === Section::Experience
                    ? ['no_experience' => $data['no_experience']] : []);
            });
            return;
        }
        if ($section === Section::Availability) {
            $this->forTeacher($id, function (PDO $db) use ($id, $section, $data): void {
                $statement = $db->prepare("SELECT JSON_EXTRACT(sections, '$.availability') FROM teacher_profiles WHERE user_id = ?");
                $statement->execute([$id]);
                $value = $statement->fetchColumn();
                $saved = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [];
                $options = $saved['opportunities'] ?? [];

                if (!array_key_exists('opportunities', $data)) {
                    $data['opportunities'] = $data['status'] === 'unavailable'
                        ? ['unavailable'] : array_values(array_diff($options, ['unavailable']));
                    if ($data['status'] === '' && $data['opportunities'] !== []) {
                        $data['status'] = 'now';
                    }
                } elseif ($data['status'] === '' && $options === [] && ($saved['status'] ?? '') === 'now') {
                    // Older profiles might have a schedule without any saved opportunity types.
                    $data['status'] = 'now';
                }
                $this->saveSection($db, $id, $section, $data);
            });
            return;
        }
        $this->forTeacher($id, fn (PDO $db) => $this->saveSection($db, $id, $section, $data));
    }

    private function saveSection(PDO $db, int $id, Section $section, array $data): void
    {
        $db->prepare('UPDATE teacher_profiles SET sections = JSON_SET(sections, ?, CAST(? AS JSON)), updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?')
            ->execute(['$.' . $section->value, json_encode((object) $data, JSON_THROW_ON_ERROR), $id]);
    }

    private function entries(Section $section): array
    {
        return $section === Section::Qualifications
            ? ['teacher_qualifications', ['qualification', 'institution', 'field_of_study', 'completed_year']]
            : ['teacher_experiences', ['position_title', 'organisation', 'started_year', 'ended_year', 'description']];
    }

    public function document(int $id, int $document): ?array
    {
        $statement = ($this->database)()->prepare('SELECT * FROM teacher_documents WHERE teacher_id = ? AND id = ? AND delete_requested_at IS NULL');
        $statement->execute([$id, $document]);
        return $statement->fetch() ?: null;
    }

    public function addDocument(int $id, string $kind, string $key, string $name, string $mime, int $size): void
    {
        $this->forTeacher($id, static function (PDO $db) use ($id, $kind, $key, $name, $mime, $size): void {
            if ($kind === 'cv') {
                $pending = $db->prepare("SELECT 1 FROM teacher_documents WHERE teacher_id = ? AND kind = 'cv'
                    AND delete_requested_at IS NOT NULL LIMIT 1");
                $pending->execute([$id]);
                if ($pending->fetchColumn() !== false) {
                    throw new InvalidArgumentException('The previous CV is still being removed. Try again shortly.');
                }
                $db->prepare('UPDATE teacher_documents SET delete_requested_at = CURRENT_TIMESTAMP(6) WHERE cv_teacher_id = ?')->execute([$id]);
            } else {
                $count = $db->prepare("SELECT COUNT(*) FROM teacher_documents WHERE teacher_id = ? AND kind <> 'cv'");
                $count->execute([$id]);
                if ((int) $count->fetchColumn() >= TeacherDocuments::SUPPORTING_LIMIT) {
                    throw new InvalidArgumentException('Remove a supporting document before uploading another.');
                }
            }

            $db->prepare(
                'INSERT INTO teacher_documents (teacher_id, kind, storage_key, display_name, media_type, bytes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $kind, $key, $name, $mime, $size]);
            if ($kind === 'cv') {
                CvJobs::queue($db, (int) $db->lastInsertId());
            }
        });
    }

    public function removeDocument(int $id, int $document): bool
    {
        return $this->forTeacher($id, static function (PDO $db) use ($id, $document): bool {
            $statement = $db->prepare(
                'UPDATE teacher_documents SET delete_requested_at = CURRENT_TIMESTAMP(6)
                 WHERE teacher_id = ? AND id = ? AND delete_requested_at IS NULL'
            );
            $statement->execute([$id, $document]);
            return $statement->rowCount() === 1;
        });
    }

    public function pendingDocumentKeys(int $id): array
    {
        $statement = ($this->database)()->prepare(
            'SELECT storage_key FROM teacher_documents WHERE teacher_id = ? AND delete_requested_at IS NOT NULL'
        );
        $statement->execute([$id]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function finishDocumentRemoval(int $id, string $key): void
    {
        ($this->database)()->prepare(
            'DELETE FROM teacher_documents WHERE teacher_id = ? AND storage_key = ? AND delete_requested_at IS NOT NULL'
        )->execute([$id, $key]);
    }

    public function queueExistingCv(int $teacher, int $document): bool
    {
        return $this->forTeacher($teacher, function (PDO $db) use ($teacher, $document): bool {
            if (($this->document($teacher, $document)['kind'] ?? null) !== 'cv') {
                return false;
            }
            CvJobs::queue($db, $document);
            return true;
        }, affectsReview: false);
    }

    /** Add reviewed suggestions without replacing saved profile records. */
    public function applyCvDraft(int $teacher, int $document, array $review): bool
    {
        return $this->forTeacher($teacher, function (PDO $db) use ($teacher, $document, $review): bool {
            $mark = $db->prepare(
                "UPDATE teacher_cv_extractions j JOIN teacher_documents d ON d.id = j.document_id
                 SET j.status = 'applied', j.draft = NULL, j.expires_at = NULL, j.applied_at = CURRENT_TIMESTAMP(6)
                 WHERE j.document_id = ? AND d.cv_teacher_id = ? AND j.status = 'ready'
                    AND j.expires_at > CURRENT_TIMESTAMP(6)"
            );
            $mark->execute([$document, $teacher]);
            if ($mark->rowCount() !== 1) {
                return false;
            }

            $saved = $this->profile($teacher)['sections'];
            if (($saved['biography']['bio'] ?? '') === '' && $review['summary'] !== '') {
                $this->saveSection($db, $teacher, Section::Biography, ['bio' => $review['summary']]);
            }

            foreach (['skills' => ['skills', 'certifications'], 'subjects' => ['subjects']] as $section => $fields) {
                $data = $saved[$section] ?? [];
                $original = $data;
                foreach ($fields as $field) {
                    if ($review[$field] !== []) {
                        $data[$field] = array_slice(array_values(array_unique([...($data[$field] ?? []), ...$review[$field]])), 0, TeacherInput::ROW_LIMIT);
                    }
                }
                if ($data !== $original) {
                    $this->saveSection($db, $teacher, Section::from($section), $data);
                }
            }

            foreach ([Section::Qualifications, Section::Experience] as $section) {
                if ($section === Section::Experience && !empty($saved['experience']['no_experience'])) {
                    continue;
                }
                $existing = $saved[$section->value]['rows'];
                $rows = [];
                foreach ($review[$section->value] as $row) {
                    if (count($existing) >= TeacherInput::ROW_LIMIT) {
                        break;
                    }
                    if (!$this->containsEntry($section, $existing, $row)) {
                        $rows[] = $row;
                        $existing[] = $row;
                    }
                }
                if ($rows !== []) {
                    $this->insertEntries($db, $teacher, $section, $rows);
                }
            }

            return true;
        });
    }

    private function containsEntry(Section $section, array $existing, array $candidate): bool
    {
        [$first, $second, $detail] = $section === Section::Experience
            ? ['position_title', 'organisation', 'started_year'] : ['qualification', 'institution', 'field_of_study'];
        foreach ($existing as $row) {
            if (strcasecmp($row[$first], $candidate[$first]) === 0 && strcasecmp($row[$second], $candidate[$second]) === 0
                && ((string) $row[$detail] === '' || (string) $candidate[$detail] === ''
                    || strcasecmp((string) $row[$detail], (string) $candidate[$detail]) === 0)) {
                return true;
            }
        }
        return false;
    }

    private function insertEntries(PDO $db, int $teacher, Section $section, array $rows): void
    {
        [$table, $fields] = $this->entries($section);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
        $insert = $db->prepare("INSERT INTO $table (teacher_id, $columns) VALUES ($placeholders)");
        foreach ($rows as $row) {
            $insert->execute([$teacher, ...array_map(static fn (string $field) => $row[$field], $fields)]);
        }
    }

    private function forTeacher(int $id, Closure $operation, bool $affectsReview = true): mixed
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($id, $operation, $affectsReview): mixed {
            $account = $db->prepare('SELECT email_verified_at, suspended_at FROM users WHERE id = ? FOR UPDATE');
            $account->execute([$id]);
            $user = $account->fetch();
            if ($user === false || $user['email_verified_at'] === null || $user['suspended_at'] !== null) {
                throw new DomainException('An active teacher account is required.');
            }
            $lock = $db->prepare('SELECT verification_status FROM teacher_profiles WHERE user_id = ? FOR UPDATE');
            $lock->execute([$id]);
            $status = $lock->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('The teacher profile no longer exists.');
            }
            $fullProfile = $status === TeacherStatus::CorrectionsRequired->value;
            $before = $affectsReview ? $this->reviewEvidence($this->profile($id), $fullProfile) : null;
            $result = $operation($db);
            if ($affectsReview && $result !== false && $before !== $this->reviewEvidence($this->profile($id), $fullProfile)) {
                if ($status === TeacherStatus::Verified->value) {
                    $db->prepare("UPDATE teacher_profiles SET verification_status = 'pending', professional_verified_at = NULL,
                        verification_revision = verification_revision + 1 WHERE user_id = ?")->execute([$id]);
                    VerificationEvents::record($db, $id, $id, 'verification.teacher.evidence_changed',
                        ['from' => $status, 'to' => TeacherStatus::Pending->value], 'Professional verification reopened',
                        'Your professional profile or documents changed. Furoeducare will review the new information.');
                } else {
                    $db->prepare('UPDATE teacher_profiles SET verification_revision = verification_revision + 1 WHERE user_id = ?')->execute([$id]);
                }
            }
            return $result;
        });
    }

    private function reviewEvidence(array $profile, bool $fullProfile): array
    {
        $sections = $profile['sections'];
        if (!$fullProfile) {
            unset($sections['availability'], $sections['locations'], $sections['preferences']);
        }
        foreach ([Section::Qualifications, Section::Experience] as $section) {
            [, $fields] = $this->entries($section);
            $sections[$section->value]['rows'] = array_map(
                static fn (array $row): array => array_intersect_key($row, array_flip($fields)),
                $sections[$section->value]['rows'],
            );
        }
        return [$profile['first_name'], $profile['last_name'], $profile['phone'], $sections, TeacherEligibility::complete($profile)];
    }
}

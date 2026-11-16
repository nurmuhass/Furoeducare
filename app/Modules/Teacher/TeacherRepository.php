<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher;

use Closure;
use Furoeducare\Core\Database;
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

    public function profile(int $id): ?array
    {
        $db = ($this->database)();
        $statement = $db->prepare('SELECT * FROM teacher_profiles WHERE user_id = ?');
        $statement->execute([$id]);
        $profile = $statement->fetch();
        if ($profile === false) {
            return null;
        }

        $profile['sections'] = json_decode($profile['sections'], true, flags: JSON_THROW_ON_ERROR);
        foreach ([Section::Qualifications, Section::Experience] as $section) {
            [$table] = $this->entries($section);
            $statement = $db->prepare("SELECT * FROM $table WHERE teacher_id = ? ORDER BY id");
            $statement->execute([$id]);
            $profile['sections'][$section->value]['rows'] = $statement->fetchAll();
        }
        $statement = $db->prepare('SELECT id, kind, display_name, media_type, bytes, created_at FROM teacher_documents WHERE teacher_id = ? AND delete_requested_at IS NULL ORDER BY id DESC');
        $statement->execute([$id]);
        $profile['sections']['documents']['files'] = $statement->fetchAll();
        return $profile;
    }

    public function save(int $id, Section $section, array $data): void
    {
        $db = ($this->database)();
        if ($section === Section::Personal) {
            $db->prepare(
                "UPDATE teacher_profiles SET first_name = ?, last_name = ?, phone = ?,
                 sections = JSON_SET(sections, '$.personal', CAST(? AS JSON)) WHERE user_id = ?"
            )->execute([
                $data['first_name'], $data['last_name'], $data['phone'],
                json_encode(['nysc' => $data['nysc'] ?? []], JSON_THROW_ON_ERROR), $id,
            ]);
            return;
        }

        if ($section === Section::Qualifications || $section === Section::Experience) {
            $this->forTeacher($id, function (PDO $db) use ($id, $section, $data): void {
                [$table, $fields] = $this->entries($section);
                $db->prepare("DELETE FROM $table WHERE teacher_id = ?")->execute([$id]);
                $columns = implode(', ', $fields);
                $placeholders = implode(', ', array_fill(0, count($fields) + 1, '?'));
                $insert = $db->prepare("INSERT INTO $table (teacher_id, $columns) VALUES ($placeholders)");
                foreach ($data['rows'] as $row) {
                    $insert->execute([$id, ...array_map(static fn (string $field) => $row[$field], $fields)]);
                }
                $this->saveSection($db, $id, $section, $section === Section::Experience
                    ? ['no_experience' => $data['no_experience']] : []);
            });
            return;
        }
        $this->saveSection($db, $id, $section, $data);
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

    private function forTeacher(int $id, Closure $operation): mixed
    {
        return Database::transaction(($this->database)(), static function (PDO $db) use ($id, $operation): mixed {
            $lock = $db->prepare('SELECT user_id FROM teacher_profiles WHERE user_id = ? FOR UPDATE');
            $lock->execute([$id]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('The teacher profile no longer exists.');
            }
            return $operation($db);
        });
    }
}

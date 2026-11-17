<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Closure;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class DirectoryRepository
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function adminTeacher(int $id): ?array
    {
        $db = ($this->database)();
        $profile = $db->prepare('SELECT user_id AS teacher_id, first_name, last_name FROM teacher_profiles WHERE user_id = ?');
        $profile->execute([$id]);
        $teacher = $profile->fetch();
        if ($teacher === false) {
            return null;
        }

        $record = $db->prepare('SELECT id, reference, title, bio, qualification, experience, state_code, city, availability_mask,
            professional_name, photograph IS NOT NULL AS has_photo, approval_basis, approved_at, revision
            FROM teacher_listings WHERE teacher_id = ?');
        $record->execute([$id]);
        $listing = $record->fetch();
        if ($listing === false) {
            return $teacher + ['listing' => null];
        }
        $tags = $db->prepare('SELECT kind, label FROM teacher_listing_tags WHERE listing_id = ? ORDER BY label');
        $tags->execute([$listing['id']]);
        $listing += ['subjects' => [], 'levels' => []];
        foreach ($tags->fetchAll() as $tag) {
            $listing[$tag['kind'] === 'subject' ? 'subjects' : 'levels'][] = $tag['label'];
        }
        return $teacher + ['listing' => $listing];
    }

    public function publish(int $actor, int $id, int $revision, array $fields, ?string $jpeg): string
    {
        if ($actor === $id) {
            return 'forbidden';
        }
        return Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $fields, $jpeg): string {
            $account = $this->lockTeacher($db, $id);
            if ($account === null) {
                return 'missing';
            }
            if ($account['suspended_at'] !== null || $account['verification_status'] === 'suspended'
                || $account['email_verified_at'] === null || !(bool) $account['has_role']) {
                return 'forbidden';
            }
            $old = $this->listingForUpdate($db, $id);
            if (($old === null ? 0 : (int) $old['revision']) !== $revision) {
                return 'stale';
            }
            if ($fields['photo_authorised'] && $jpeg === null && !($old['has_photo'] ?? false)) {
                return 'photo';
            }
            $reference = $old['reference'] ?? 'FT-' . strtoupper(bin2hex(random_bytes(10)));
            $values = [
                $fields['title'], $fields['bio'], $fields['qualification'], $fields['experience'],
                $fields['state_code'], $fields['city'], $fields['availability_mask'],
                $fields['professional_name'], $fields['approval_basis'],
            ];
            if ($old === null) {
                $db->prepare('INSERT INTO teacher_listings
                    (title, bio, qualification, experience, state_code, city, availability_mask,
                     professional_name, approval_basis, photograph, reference, teacher_id, approved_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6))')
                    ->execute([...$values, $fields['photo_authorised'] ? $jpeg : null, $reference, $id]);
                $listing = (int) $db->lastInsertId();
            } else {
                $db->prepare('UPDATE teacher_listings SET
                    title = ?, bio = ?, qualification = ?, experience = ?, state_code = ?, city = ?, availability_mask = ?,
                    professional_name = ?, approval_basis = ?, photograph = IF(?, COALESCE(?, photograph), NULL),
                    approved_at = CURRENT_TIMESTAMP(6), revision = revision + 1 WHERE id = ?')
                    ->execute([...$values, (int) $fields['photo_authorised'], $jpeg, $old['id']]);
                $listing = (int) $old['id'];
            }

            $db->prepare('DELETE FROM teacher_listing_tags WHERE listing_id = ?')->execute([$listing]);
            $insert = $db->prepare('INSERT INTO teacher_listing_tags (listing_id, kind, label)
                VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE listing_id = listing_id');
            foreach (['subject' => $fields['subjects'], 'level' => $fields['levels']] as $kind => $labels) {
                foreach ($labels as $label) {
                    $insert->execute([$listing, $kind, $label]);
                }
            }
            $db->prepare('UPDATE teacher_profiles SET published_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?')->execute([$id]);
            VerificationEvents::audit($db, $actor, 'directory.listing.published', [
                'teacher_id' => $id, 'reference' => $reference, 'revision' => $revision + 1,
                'name_authorised' => $fields['professional_name'] !== null,
                'photo_authorised' => $fields['photo_authorised'],
            ]);
            return 'saved';
        });
    }

    public function withdraw(int $actor, int $id, int $revision): string
    {
        if ($actor === $id) {
            return 'forbidden';
        }
        return Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision): string {
            if ($this->lockTeacher($db, $id) === null) {
                return 'missing';
            }
            $old = $this->listingForUpdate($db, $id);
            if ($old === null) {
                return 'missing';
            }
            if ((int) $old['revision'] !== $revision || $old['approved_at'] === null) {
                return 'stale';
            }
            $db->prepare('UPDATE teacher_listings SET approved_at = NULL, revision = revision + 1 WHERE id = ?')->execute([$old['id']]);
            $db->prepare('UPDATE teacher_profiles SET published_at = NULL WHERE user_id = ?')->execute([$id]);
            VerificationEvents::audit($db, $actor, 'directory.listing.withdrawn', [
                'teacher_id' => $id, 'reference' => $old['reference'], 'revision' => $revision + 1,
            ]);
            return 'saved';
        });
    }

    private function lockTeacher(PDO $db, int $id): ?array
    {
        $user = $db->prepare("SELECT u.email_verified_at, u.suspended_at,
            EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'teacher') AS has_role
            FROM users u WHERE u.id = ? FOR UPDATE");
        $user->execute([$id]);
        $account = $user->fetch();
        $profile = $db->prepare('SELECT verification_status FROM teacher_profiles WHERE user_id = ? FOR UPDATE');
        $profile->execute([$id]);
        $teacher = $profile->fetch();
        return $account === false || $teacher === false ? null : $account + $teacher;
    }

    private function listingForUpdate(PDO $db, int $id): ?array
    {
        $query = $db->prepare('SELECT id, reference, approved_at, revision, photograph IS NOT NULL AS has_photo
            FROM teacher_listings WHERE teacher_id = ? FOR UPDATE');
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }
}

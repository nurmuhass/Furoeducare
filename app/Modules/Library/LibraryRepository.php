<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Library;

use Closure;
use DomainException;
use Furoeducare\Modules\Admin\StaffAccess;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;
use Throwable;

final class LibraryRepository
{
    private const PUBLIC_COLUMNS = 'r.id, r.reference, r.title, r.author, r.description, r.publisher,
        r.publication_year, r.edition, r.isbn, r.access, r.revision, r.licence, r.rights_expires_on,
        r.material_extension, r.material_bytes, (r.cover_key IS NOT NULL) AS has_cover';

    public function __construct(private readonly Closure $database, private readonly LibraryFiles $files)
    {
    }

    public static function visible(array $row): bool
    {
        return $row['status'] === 'published' && $row['rights_confirmed_at'] !== null
            && $row['licence'] !== '' && $row['rights_reference'] !== '' && $row['material_key'] !== null
            && ($row['rights_expires_on'] === null || $row['rights_expires_on'] >= LibraryInput::today());
    }

    private static function visibleSql(): string
    {
        return "r.status = 'published' AND r.rights_confirmed_at IS NOT NULL AND r.licence <> ''
            AND r.rights_reference <> '' AND r.material_key IS NOT NULL
            AND (r.rights_expires_on IS NULL OR r.rights_expires_on >= ?)";
    }

    public function search(array $filters, int $before, bool $staff = false, ?int $owner = null, bool $saved = false): array
    {
        $db = ($this->database)();
        if ($saved) {
            self::requireAccount($db, $owner ?? 0);
            return $this->savedSearch($db, $owner, $before, $filters);
        }
        $parameters = [];
        $where = ['r.id < ?'];
        $parameters[] = $before;
        if (!$staff) {
            $where[] = self::visibleSql();
            $parameters[] = LibraryInput::today();
        }
        foreach (['q', 'category', 'subject', 'level'] as $filter) {
            $value = LibraryInput::text($filters[$filter] ?? '', $filter, $filter === 'q' ? 200 : 120);
            if ($value === '') {
                continue;
            }
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
            if ($filter === 'q') {
                $where[] = "(r.title LIKE ? ESCAPE '!' OR r.author LIKE ? ESCAPE '!')";
                array_push($parameters, $pattern, $pattern);
            } else {
                $where[] = "EXISTS (SELECT 1 FROM library_resource_tags t WHERE t.resource_id = r.id AND t.kind = ? AND t.label LIKE ? ESCAPE '!')";
                array_push($parameters, $filter, $pattern);
            }
        }
        $sql = 'SELECT ' . self::PUBLIC_COLUMNS . ($staff ? ', r.status' : '')
            . ' FROM library_resources r WHERE ' . implode(' AND ', $where)
            . ' ORDER BY r.id DESC LIMIT ' . ($staff ? '21' : '13');
        return $this->withTags(self::query($db, $sql, $parameters)->fetchAll());
    }

    private function savedSearch(PDO $db, int $owner, int $before, array $filters): array
    {
        $where = ['s.user_id = ?', 'r.id < ?'];
        $parameters = [LibraryInput::today(), $owner, $before];
        foreach (['q', 'category', 'subject', 'level'] as $filter) {
            $value = LibraryInput::text($filters[$filter] ?? '', $filter, $filter === 'q' ? 200 : 120);
            if ($value === '') {
                continue;
            }
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
            if ($filter === 'q') {
                $where[] = "(COALESCE(p.title, CASE WHEN r.status = 'removed' THEN 'Resource removed' ELSE s.title_snapshot END) LIKE ? ESCAPE '!' OR COALESCE(p.author, '') LIKE ? ESCAPE '!')";
                array_push($parameters, $pattern, $pattern);
            } else {
                $where[] = "EXISTS (SELECT 1 FROM library_resource_tags t WHERE t.resource_id = p.id AND t.kind = ? AND t.label LIKE ? ESCAPE '!')";
                array_push($parameters, $filter, $pattern);
            }
        }
        $rows = self::query($db, "SELECT r.id, r.reference, p.id IS NOT NULL AS is_available,
            COALESCE(p.title, CASE WHEN r.status = 'removed' THEN 'Resource removed' ELSE s.title_snapshot END) AS title,
            COALESCE(p.author, '') AS author, COALESCE(p.access, 'restricted') AS access,
            p.material_extension, p.has_cover
            FROM library_saved_resources s JOIN library_resources r ON r.id = s.resource_id
            LEFT JOIN (SELECT " . self::PUBLIC_COLUMNS . ' FROM library_resources r WHERE ' . self::visibleSql() . ') p ON p.id = r.id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 13', $parameters)->fetchAll();
        // Unpublished classification changes must not leak through saved records.
        foreach ($rows as &$row) {
            $row['tags'] = ['categories' => [], 'subjects' => [], 'levels' => []];
        }
        return $rows;
    }

    public function publicResource(string $reference): ?array
    {
        $row = self::query(($this->database)(), 'SELECT ' . self::PUBLIC_COLUMNS
            . ' FROM library_resources r WHERE r.reference = ? AND ' . self::visibleSql(),
            [$reference, LibraryInput::today()])->fetch();
        return $row === false ? null : $this->withTags([$row])[0];
    }

    public function staffResource(int $id, int $grantsBefore = PHP_INT_MAX): ?array
    {
        $db = ($this->database)();
        $row = self::query($db, 'SELECT * FROM library_resources WHERE id = ?', [$id])->fetch();
        if ($row === false) {
            return null;
        }
        $row = $this->withTags([$row])[0];
        $row['downloads'] = (int) self::query($db, 'SELECT COUNT(*) FROM library_downloads WHERE resource_id = ?', [$id])->fetchColumn();
        $grants = self::query($db, 'SELECT id, user_id, expires_at, revoked_at FROM library_access_grants WHERE resource_id = ? AND id < ? ORDER BY id DESC LIMIT 51', [$id, $grantsBefore])->fetchAll();
        $row['grants'] = array_slice($grants, 0, 50);
        $row['grants_next'] = count($grants) > 50 ? (int) $grants[49]['id'] : null;
        return $row;
    }

    private function withTags(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $tags = self::query(($this->database)(), 'SELECT resource_id, kind, label FROM library_resource_tags WHERE resource_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY label', $ids)->fetchAll();
        foreach ($rows as &$row) {
            $row['tags'] = ['categories' => [], 'subjects' => [], 'levels' => []];
            foreach ($tags as $tag) {
                if ((int) $tag['resource_id'] === (int) $row['id']) {
                    $plural = array_search($tag['kind'], LibraryInput::TAGS, true);
                    $row['tags'][$plural][] = $tag['label'];
                }
            }
        }
        return $rows;
    }

    public function save(int $actor, ?int $id, ?int $revision, array $input, ?array $material, ?array $cover): int
    {
        $db = ($this->database)();
        self::requireAccount($db, $actor, true);
        StaffAccess::requireIn($db, $actor, 'library.manage');
        $values = LibraryInput::resource($input);
        $staged = [];
        try {
            foreach (['material' => $material, 'cover' => $cover] as $kind => $upload) {
                if ($upload !== null && $upload !== [] && ($upload['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
                    $staged[$kind] = $kind === 'material' ? $this->files->storeMaterial($upload) : $this->files->storeCover($upload);
                }
            }
            if ($id === null && !isset($staged['material'])) {
                throw new DomainException('Upload a PDF or EPUB material.', 422);
            }
            $id = Database::transaction($db, function (PDO $db) use ($actor, $id, $revision, $values, $staged): int {
                self::requireAccount($db, $actor, true, true);
                StaffAccess::requireIn($db, $actor, 'library.manage', true);
                $old = $id === null ? null : self::locked($db, $id, $revision);
                if ($old !== null && $old['status'] === 'removed') {
                    throw new DomainException('Removed resources cannot be edited.', 409);
                }
                $fields = $values;
                unset($fields['tags'], $fields['rights_confirmed']);
                $fields['rights_confirmed_by'] = $values['rights_confirmed'] ? $actor : null;
                $fields['rights_confirmed_at'] = $values['rights_confirmed'] ? gmdate('Y-m-d H:i:s') : null;
                foreach ($staged as $kind => $file) {
                    $fields[$kind . '_key'] = $file['storage_key'];
                    foreach (['media_type', 'bytes', 'sha256'] as $property) {
                        $fields[$kind . '_' . $property] = $file[$property];
                    }
                    if ($kind === 'material') {
                        $fields['material_extension'] = $file['extension'];
                    }
                    if ($old !== null && $old[$kind . '_key'] !== null) {
                        self::queueRemoval($db, $old[$kind . '_key']);
                    }
                }
                if ($old === null) {
                    $fields['reference'] = 'LB-' . strtoupper(bin2hex(random_bytes(16)));
                    $fields['created_by'] = $actor;
                    self::query($db, 'INSERT INTO library_resources (' . implode(', ', array_keys($fields)) . ') VALUES ('
                        . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
                    $id = (int) $db->lastInsertId();
                } else {
                    $fields['status'] = $old['status'] === 'draft' ? 'draft' : 'unpublished';
                    $fields['revision'] = (int) $old['revision'] + 1;
                    self::update($db, $id, $fields);
                    self::revokeAll($db, $id, $actor, 'Resource changed.');
                }
                self::query($db, 'DELETE FROM library_resource_tags WHERE resource_id = ?', [$id]);
                foreach (LibraryInput::TAGS as $plural => $kind) {
                    foreach ($values['tags'][$plural] as $label) {
                        self::query($db, 'INSERT INTO library_resource_tags (resource_id, kind, label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE label = label', [$id, $kind, $label]);
                    }
                }
                VerificationEvents::audit($db, $actor, $old === null ? 'library.created' : 'library.updated', ['resource_id' => $id]);
                return $id;
            });
        } catch (Throwable $error) {
            // The commit outcome may be uncertain. Never delete a file until the database proves it is unreferenced.
            try {
                foreach ($staged as $file) {
                    self::queueRemoval($db, $file['storage_key']);
                }
                $this->cleanup();
            } catch (Throwable) {
                error_log('Library staged file cleanup deferred.');
            }
            throw $error;
        }
        $this->cleanupAfterCommit();
        return $id;
    }

    public function transition(int $actor, int $id, int $revision, string $action): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $action): void {
            self::requireAccount($db, $actor, true, true);
            StaffAccess::requireIn($db, $actor, 'library.manage', true);
            $row = self::locked($db, $id, $revision);
            $allowed = match ($action) {
                'publish' => ['draft', 'unpublished', 'archived'],
                'unpublish' => ['published'],
                'archive' => ['draft', 'unpublished', 'published'],
                'remove' => ['draft', 'unpublished', 'published', 'archived'],
                default => [],
            };
            if (!in_array($row['status'], $allowed, true)) {
                throw new DomainException('That publication transition is unavailable.', 409);
            }
            $fields = ['status' => match ($action) {
                'publish' => 'published', 'unpublish' => 'unpublished', 'archive' => 'archived', 'remove' => 'removed',
            }, 'revision' => $revision + 1];
            if ($action === 'publish') {
                $candidate = $row;
                $candidate['status'] = 'published';
                if (!self::visible($candidate)) {
                    throw new DomainException('Confirm current distribution rights, licence and rights evidence before publishing.', 422);
                }
                $fields['published_at'] = gmdate('Y-m-d H:i:s');
            }
            if ($action === 'remove') {
                foreach (['material', 'cover'] as $kind) {
                    if ($row[$kind . '_key'] !== null) {
                        self::queueRemoval($db, $row[$kind . '_key']);
                    }
                    foreach (['key', 'media_type', 'bytes', 'sha256'] as $property) {
                        $fields[$kind . '_' . $property] = null;
                    }
                }
                $fields += ['material_extension' => null, 'title' => 'Resource removed', 'author' => '',
                    'description' => '', 'publisher' => '', 'edition' => '', 'isbn' => '', 'publication_year' => null,
                    'licence' => '', 'rights_reference' => '', 'rights_confirmed_at' => null,
                    'rights_confirmed_by' => null, 'rights_expires_on' => null, 'removed_at' => gmdate('Y-m-d H:i:s')];
                self::query($db, 'DELETE FROM library_resource_tags WHERE resource_id = ?', [$id]);
            }
            self::update($db, $id, $fields);
            self::revokeAll($db, $id, $actor, 'Publication changed.');
            VerificationEvents::audit($db, $actor, 'library.' . $action, ['resource_id' => $id, 'revision' => $revision + 1]);
        });
        $this->cleanupAfterCommit();
    }

    public function grant(int $actor, int $id, int $revision, int $recipient, string $expiry, string $reason): void
    {
        $expiry = LibraryInput::grantExpiry($expiry);
        $reason = LibraryInput::text($reason, 'reason', 500, true);
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $recipient, $expiry, $reason): void {
            self::requireAccount($db, $actor, true, true);
            StaffAccess::requireIn($db, $actor, 'library.manage', true);
            $row = self::locked($db, $id, $revision);
            self::requireAccount($db, $recipient, false, true);
            if (!self::visible($row) || $row['access'] !== 'restricted') {
                throw new DomainException('Access grants require a published restricted resource with current rights.', 409);
            }
            self::query($db, 'UPDATE library_access_grants SET revoked_at = UTC_TIMESTAMP(6), revoked_by = ?, revocation_reason = ? WHERE resource_id = ? AND user_id = ? AND revoked_at IS NULL', [$actor, 'Superseded grant.', $id, $recipient]);
            self::query($db, 'INSERT INTO library_access_grants (resource_id, user_id, resource_revision, granted_by, reason, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [$id, $recipient, $revision, $actor, $reason, $expiry]);
            VerificationEvents::audit($db, $actor, 'library.access_granted', ['resource_id' => $id, 'user_id' => $recipient, 'grant_id' => (int) $db->lastInsertId()]);
        });
    }

    public function revoke(int $actor, int $id, int $revision, int $grant, string $reason): void
    {
        $reason = LibraryInput::text($reason, 'reason', 500, true);
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $grant, $reason): void {
            self::requireAccount($db, $actor, true, true);
            StaffAccess::requireIn($db, $actor, 'library.manage', true);
            self::locked($db, $id, $revision);
            $statement = self::query($db, 'UPDATE library_access_grants SET revoked_at = UTC_TIMESTAMP(6), revoked_by = ?, revocation_reason = ? WHERE id = ? AND resource_id = ? AND revoked_at IS NULL', [$actor, $reason, $grant, $id]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('This access grant is unavailable.', 409);
            }
            VerificationEvents::audit($db, $actor, 'library.access_revoked', ['resource_id' => $id, 'grant_id' => $grant]);
        });
    }

    public function bookmark(int $owner, string $reference, bool $save): void
    {
        Database::transaction(($this->database)(), function (PDO $db) use ($owner, $reference, $save): void {
            self::requireAccount($db, $owner, false, true);
            $row = self::query($db, 'SELECT * FROM library_resources WHERE reference = ? FOR SHARE', [$reference])->fetch();
            if ($row === false || ($save && !self::visible($row))) {
                throw new DomainException('This resource is unavailable.', 404);
            }
            if ($save) {
                self::query($db, 'INSERT INTO library_saved_resources (user_id, resource_id, title_snapshot) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE resource_id = resource_id', [$owner, $row['id'], $row['title']]);
            } else {
                self::query($db, 'DELETE FROM library_saved_resources WHERE user_id = ? AND resource_id = ?', [$owner, $row['id']]);
            }
        });
    }

    public function history(int $owner, int $before): array
    {
        $db = ($this->database)();
        self::requireAccount($db, $owner);
        return self::query($db, "SELECT d.id, d.created_at, r.reference,
            CASE WHEN r.status = 'removed' THEN 'Resource removed' ELSE d.title_snapshot END AS title,
            (" . self::visibleSql() . ') AS is_available FROM library_downloads d
            JOIN library_resources r ON r.id = d.resource_id WHERE d.user_id = ? AND d.id < ? ORDER BY d.id DESC LIMIT 21', [LibraryInput::today(), $owner, $before])->fetchAll();
    }

    /** Hold the resource lock until authorization, file integrity checks and download recording finish. */
    public function readResource(string|int $identifier, Closure $operation): mixed
    {
        return Database::transaction(($this->database)(), function (PDO $db) use ($identifier, $operation): mixed {
            $column = is_int($identifier) ? 'id' : 'reference';
            $row = self::query($db, 'SELECT * FROM library_resources WHERE ' . $column . ' = ? FOR SHARE', [$identifier])->fetch();
            if ($row === false) {
                throw new DomainException('This resource is unavailable.', 404);
            }
            return $operation($row, $db);
        });
    }

    public function grantExpiry(array $resource, int $owner, ?PDO $db = null): ?string
    {
        $db ??= ($this->database)();
        self::requireAccount($db, $owner, false, $db->inTransaction());
        $expiry = self::query($db, 'SELECT expires_at FROM library_access_grants WHERE resource_id = ? AND user_id = ?
            AND resource_revision = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(6)'
            . ($db->inTransaction() ? ' FOR SHARE' : ''), [$resource['id'], $owner, $resource['revision']])->fetchColumn();
        return $expiry === false ? null : $expiry;
    }

    public static function requireAccount(PDO $db, int $id, bool $admin = false, bool $lock = false): void
    {
        $user = self::query($db, 'SELECT email_verified_at, suspended_at FROM users WHERE id = ?' . ($lock ? ' FOR SHARE' : ''), [$id])->fetch();
        $roles = self::query($db, 'SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?' . ($lock ? ' FOR SHARE' : ''), [$id])->fetchAll(PDO::FETCH_COLUMN);
        if ($user === false || $user['email_verified_at'] === null || $user['suspended_at'] !== null
            || ($admin ? !in_array('admin', $roles, true) : array_intersect(['teacher', 'school', 'parent', 'admin'], $roles) === [])) {
            throw new DomainException('An authorised, verified active account is required.', 403);
        }
    }

    public function cleanup(int $limit = 50): int
    {
        $db = ($this->database)();
        $keys = $db->query('SELECT f.storage_key FROM library_file_removals f WHERE NOT EXISTS
            (SELECT 1 FROM library_resources r WHERE r.material_key = f.storage_key OR r.cover_key = f.storage_key)
            ORDER BY f.created_at LIMIT ' . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_COLUMN);
        $removed = 0;
        foreach ($keys as $key) {
            if ($this->files->delete($key)) {
                self::query($db, 'DELETE FROM library_file_removals WHERE storage_key = ?', [$key]);
                $removed++;
            }
        }
        return $removed;
    }

    private function cleanupAfterCommit(): void
    {
        try {
            $this->cleanup();
        } catch (Throwable) {
            // The committed publication decision remains successful. The durable queue is retried by the CLI.
            error_log('Library file cleanup deferred.');
        }
    }

    private static function queueRemoval(PDO $db, string $key): void
    {
        self::query($db, 'INSERT INTO library_file_removals (storage_key) VALUES (?) ON DUPLICATE KEY UPDATE storage_key = storage_key', [$key]);
    }

    private static function locked(PDO $db, int $id, ?int $revision): array
    {
        $row = self::query($db, 'SELECT * FROM library_resources WHERE id = ? FOR UPDATE', [$id])->fetch();
        if ($row === false) {
            throw new DomainException('This resource is unavailable.', 404);
        }
        if ((int) $row['revision'] !== $revision) {
            throw new DomainException('This resource changed. Reopen it before making another change.', 409);
        }
        return $row;
    }

    private static function update(PDO $db, int $id, array $fields): void
    {
        self::query($db, 'UPDATE library_resources SET ' . implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($fields))) . ' WHERE id = ?', [...array_values($fields), $id]);
    }

    private static function revokeAll(PDO $db, int $id, int $actor, string $reason): void
    {
        self::query($db, 'UPDATE library_access_grants SET revoked_at = UTC_TIMESTAMP(6), revoked_by = ?, revocation_reason = ? WHERE resource_id = ? AND revoked_at IS NULL', [$actor, $reason, $id]);
    }

    public static function query(PDO $db, string $sql, array $parameters): \PDOStatement
    {
        $statement = $db->prepare($sql);
        foreach (array_values($parameters) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $statement->execute();
        return $statement;
    }
}

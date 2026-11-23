<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Training;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class TrainingCatalogue
{
    private const MODULE_COLUMNS = 'm.id, m.title, m.description, m.category, m.objectives, m.target_participants,
        m.duration, m.delivery_format, m.prerequisites, m.materials_metadata, m.revision';
    private const PACKAGE_AVAILABLE = "p.status = 'published'
        AND EXISTS (SELECT 1 FROM training_package_modules pm WHERE pm.package_id = p.id)
        AND NOT EXISTS (SELECT 1 FROM training_package_modules pm JOIN training_modules m ON m.id = pm.module_id
            WHERE pm.package_id = p.id AND m.status <> 'published')";

    public function __construct(private readonly Closure $database)
    {
    }

    public function browse(string $search, int $before = PHP_INT_MAX, ?int $packagesBefore = null): array
    {
        $pattern = self::pattern($search);
        $db = ($this->database)();
        return ['modules' => TrainingAccess::query($db, 'SELECT ' . self::MODULE_COLUMNS . " FROM training_modules m
                WHERE m.status = 'published' AND m.id < ? AND (m.title LIKE ? ESCAPE '!' OR m.category LIKE ? ESCAPE '!')
                ORDER BY m.id DESC LIMIT 13", [$before, $pattern, $pattern])->fetchAll(),
            'packages' => TrainingAccess::query($db, 'SELECT p.id, p.title, p.description, p.revision FROM training_packages p
                WHERE ' . self::PACKAGE_AVAILABLE . " AND p.id < ? AND p.title LIKE ? ESCAPE '!' ORDER BY p.id DESC LIMIT 13", [$packagesBefore ?? $before, $pattern])->fetchAll()];
    }

    public function module(int $id, bool $staff = false): ?array
    {
        return TrainingAccess::query(($this->database)(), 'SELECT ' . self::MODULE_COLUMNS . ($staff ? ', m.status' : '')
            . ' FROM training_modules m WHERE m.id = ?' . ($staff ? '' : " AND m.status = 'published'"), [$id])->fetch() ?: null;
    }

    public function package(int $id, bool $staff = false): ?array
    {
        $db = ($this->database)();
        if (!$staff) {
            return Database::transaction($db, fn (PDO $db): ?array => $this->packageRead($db, $id, false));
        }
        return $this->packageRead($db, $id, true);
    }

    private function packageRead(PDO $db, int $id, bool $staff): ?array
    {
        $row = TrainingAccess::query($db, 'SELECT p.id, p.title, p.description, p.revision' . ($staff ? ', p.status' : '')
            . ' FROM training_packages p WHERE p.id = ?' . ($staff ? '' : ' AND ' . self::PACKAGE_AVAILABLE . ' FOR SHARE'), [$id])->fetch();
        if ($row === false) {
            return null;
        }
        $row['modules'] = TrainingAccess::query($db, 'SELECT ' . self::MODULE_COLUMNS . ', m.status'
            . ' FROM training_package_modules pm JOIN training_modules m ON m.id = pm.module_id WHERE pm.package_id = ? ORDER BY pm.position'
            . ($staff ? '' : ' FOR SHARE'), [$id])->fetchAll();
        if (!$staff) {
            foreach ($row['modules'] as &$module) {
                if ($module['status'] !== 'published') {
                    return null;
                }
                unset($module['status']);
            }
        }
        return $row;
    }

    public function staffModules(int $before): array
    {
        return TrainingAccess::query(($this->database)(), 'SELECT ' . self::MODULE_COLUMNS . ', m.status FROM training_modules m WHERE m.id < ? ORDER BY m.id DESC LIMIT 21', [$before])->fetchAll();
    }

    public function staffPackages(int $before): array
    {
        return TrainingAccess::query(($this->database)(), 'SELECT id, title, description, status, revision FROM training_packages WHERE id < ? ORDER BY id DESC LIMIT 21', [$before])->fetchAll();
    }

    public function choices(array $selectedModules = [], mixed $selectedPackage = null): array
    {
        $db = ($this->database)();
        $choices = ['modules' => TrainingAccess::query($db, 'SELECT ' . self::MODULE_COLUMNS . " FROM training_modules m WHERE m.status = 'published' ORDER BY m.title, m.id LIMIT 200")->fetchAll(),
            'packages' => TrainingAccess::query($db, 'SELECT p.id, p.title, p.description, p.revision FROM training_packages p WHERE '
                . self::PACKAGE_AVAILABLE . ' ORDER BY p.title, p.id LIMIT 200')->fetchAll()];
        $included = array_column($choices['modules'], 'id');
        foreach (array_slice($selectedModules, 0, 20) as $raw) {
            $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && !in_array($id, $included, true)) {
                $module = $this->module($id);
                if ($module !== null) {
                    $choices['modules'][] = $module;
                    $included[] = $id;
                }
            }
        }
        $id = filter_var($selectedPackage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false && !in_array($id, array_column($choices['packages'], 'id'), true)) {
            $package = $this->package($id);
            if ($package !== null) {
                unset($package['modules']);
                $choices['packages'][] = $package;
            }
        }
        return $choices;
    }

    public function saveModule(int $actor, ?int $id, ?int $revision, array $input): int
    {
        $fields = TrainingInput::module($input);
        return Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $fields): int {
            TrainingAccess::requireRole($db, $actor, 'admin', true);
            if ($id === null) {
                $fields['created_by'] = $actor;
                self::insert($db, 'training_modules', $fields);
                $id = (int) $db->lastInsertId();
            } else {
                self::locked($db, 'training_modules', $id, $revision);
                self::update($db, 'training_modules', $id, $fields + ['status' => 'draft', 'revision' => $revision + 1]);
            }
            VerificationEvents::audit($db, $actor, 'training.module_saved', ['module_id' => $id]);
            return $id;
        });
    }

    public function savePackage(int $actor, ?int $id, ?int $revision, array $input, array $moduleIds): int
    {
        $fields = ['title' => TrainingInput::text($input['title'] ?? '', 'title', 200),
            'description' => TrainingInput::text($input['description'] ?? '', 'description', 4000)];
        $moduleIds = TrainingInput::ids($moduleIds);
        return Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $fields, $moduleIds): int {
            TrainingAccess::requireRole($db, $actor, 'admin', true);
            if ($id === null) {
                $fields['created_by'] = $actor;
                self::insert($db, 'training_packages', $fields);
                $id = (int) $db->lastInsertId();
            } else {
                self::locked($db, 'training_packages', $id, $revision);
                self::update($db, 'training_packages', $id, $fields + ['status' => 'draft', 'revision' => $revision + 1]);
            }
            self::selectedModules($db, $moduleIds, false);
            TrainingAccess::query($db, 'DELETE FROM training_package_modules WHERE package_id = ?', [$id]);
            foreach ($moduleIds as $position => $module) {
                TrainingAccess::query($db, 'INSERT INTO training_package_modules (package_id, module_id, position) VALUES (?, ?, ?)', [$id, $module, $position + 1]);
            }
            VerificationEvents::audit($db, $actor, 'training.package_saved', ['package_id' => $id]);
            return $id;
        });
    }

    public function publish(int $actor, string $kind, int $id, int $revision, string $status): void
    {
        if (!in_array($kind, ['module', 'package'], true) || !in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new DomainException('Choose an available publication action.', 422);
        }
        $table = $kind === 'module' ? 'training_modules' : 'training_packages';
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $kind, $table, $id, $revision, $status): void {
            TrainingAccess::requireRole($db, $actor, 'admin', true);
            $row = self::locked($db, $table, $id, $revision);
            if ($row['status'] === $status) {
                throw new DomainException('The resource already has this publication status.', 409);
            }
            if ($kind === 'package' && $status === 'published') {
                $ids = TrainingAccess::query($db, 'SELECT module_id FROM training_package_modules WHERE package_id = ? ORDER BY position', [$id])->fetchAll(PDO::FETCH_COLUMN);
                self::selectedModules($db, TrainingInput::ids($ids));
            }
            $fields = ['status' => $status, 'revision' => $revision + 1];
            if ($status === 'published') {
                $fields['published_at'] = gmdate('Y-m-d H:i:s');
            }
            self::update($db, $table, $id, $fields);
            VerificationEvents::audit($db, $actor, 'training.' . $kind . '_publication', ['id' => $id, 'status' => $status]);
        });
    }

    /** Called inside submission/publication transactions; order locks consistently by module ID. */
    public static function selectedModules(PDO $db, array $ids, bool $requirePublished = true): array
    {
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        $rows = [];
        foreach ($sorted as $id) {
            $row = TrainingAccess::query($db, 'SELECT ' . self::MODULE_COLUMNS . ", m.status FROM training_modules m WHERE m.id = ? FOR SHARE", [$id])->fetch();
            if ($row === false || ($requirePublished && $row['status'] !== 'published')) {
                throw new DomainException('A selected module is no longer published. Reopen the catalogue.', 409);
            }
            unset($row['status']);
            $rows[$id] = $row;
        }
        return array_map(static fn (int $id): array => $rows[$id], $ids);
    }

    public static function locked(PDO $db, string $table, int $id, ?int $revision): array
    {
        $row = TrainingAccess::query($db, 'SELECT * FROM ' . $table . ' WHERE id = ? FOR UPDATE', [$id])->fetch();
        if ($row === false) {
            throw new DomainException('This training record is unavailable.', 404);
        }
        if ((int) $row['revision'] !== $revision) {
            throw new DomainException('This record changed. Reopen it before making another decision.', 409);
        }
        return $row;
    }

    public static function insert(PDO $db, string $table, array $fields): void
    {
        TrainingAccess::query($db, 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($fields)) . ') VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
    }

    public static function update(PDO $db, string $table, int $id, array $fields): void
    {
        TrainingAccess::query($db, 'UPDATE ' . $table . ' SET ' . implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($fields))) . ' WHERE id = ?', [...array_values($fields), $id]);
    }

    private static function pattern(string $search): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], TrainingInput::text($search, 'search', 200, false)) . '%';
    }
}

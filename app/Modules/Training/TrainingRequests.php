<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Training;

use Closure;
use DomainException;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Verification\VerificationEvents;
use PDO;

final class TrainingRequests
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function submit(int $owner, array $input, array $moduleIds, ?int $packageId, string $token): int
    {
        $values = TrainingInput::request($input);
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            throw new DomainException('Reopen the training request form before submitting.', 422);
        }
        if ($packageId !== null && ($packageId < 1 || $moduleIds !== [])) {
            throw new DomainException('Select individual modules or one package.', 422);
        }
        if ($packageId === null) {
            $moduleIds = TrainingInput::ids($moduleIds);
        }
        $hashIds = $moduleIds;
        sort($hashIds, SORT_NUMERIC);
        $hash = hash('sha256', json_encode([$values, $hashIds, $packageId], JSON_THROW_ON_ERROR));
        return Database::transaction(($this->database)(), function (PDO $db) use ($owner, $values, $moduleIds, $packageId, $token, $hash): int {
            // Serialise browser retries for the same school before checking the unique submission token.
            TrainingAccess::query($db, 'SELECT id FROM users WHERE id = ? FOR UPDATE', [$owner]);
            TrainingAccess::approvedSchool($db, $owner, true);
            $existing = TrainingAccess::query($db, 'SELECT id, payload_hash FROM training_requests WHERE school_id = ? AND client_token = ?', [$owner, $token])->fetch();
            if ($existing !== false) {
                if (!hash_equals($existing['payload_hash'], $hash)) {
                    throw new DomainException('This form was already submitted with different details. Open a new request.', 409);
                }
                return (int) $existing['id'];
            }
            $packageTitle = null;
            if ($packageId !== null) {
                $package = TrainingAccess::query($db, 'SELECT title, status FROM training_packages WHERE id = ? FOR SHARE', [$packageId])->fetch();
                if ($package === false || $package['status'] !== 'published') {
                    throw new DomainException('This package is no longer available.', 409);
                }
                $packageTitle = $package['title'];
                $moduleIds = TrainingInput::ids(TrainingAccess::query($db, 'SELECT module_id FROM training_package_modules WHERE package_id = ? ORDER BY position', [$packageId])->fetchAll(PDO::FETCH_COLUMN));
            }
            $modules = TrainingCatalogue::selectedModules($db, $moduleIds);
            $fields = $values;
            $fields['preferred_dates'] = json_encode($values['preferred_dates'], JSON_THROW_ON_ERROR);
            $fields += ['school_id' => $owner, 'package_id' => $packageId, 'package_title' => $packageTitle,
                'reference' => 'TR-' . strtoupper(bin2hex(random_bytes(16))), 'client_token' => $token, 'payload_hash' => $hash];
            TrainingCatalogue::insert($db, 'training_requests', $fields);
            $id = (int) $db->lastInsertId();
            foreach ($modules as $position => $module) {
                $module['selection_position'] = $position + 1;
                TrainingAccess::query($db, 'INSERT INTO training_request_modules (request_id, module_id, module_revision, title_snapshot, snapshot) VALUES (?, ?, ?, ?, ?)',
                    [$id, $module['id'], $module['revision'], $module['title'], json_encode($module, JSON_THROW_ON_ERROR)]);
            }
            self::history($db, $id, $owner, 'submit', null, 'submitted', 'School submitted a workshop request.', ['module_ids' => $moduleIds, 'package_id' => $packageId]);
            self::notifySchool($db, $owner, $owner, $id, 'submitted');
            foreach ($this->staffChoices() as $staff) {
                VerificationEvents::record($db, $owner, (int) $staff['id'], 'training.new_request',
                    ['training_request_id' => $id, 'recipient_role' => 'admin'], 'New training request',
                    'A school submitted an institutional training request. Open the training management queue.');
            }
            return $id;
        });
    }

    public function forSchool(int $owner, int $before): array
    {
        $db = ($this->database)();
        TrainingAccess::requireRole($db, $owner, 'school');
        return TrainingAccess::query($db, 'SELECT id, reference, status, participants, location, created_at, updated_at FROM training_requests WHERE school_id = ? AND id < ? ORDER BY id DESC LIMIT 21', [$owner, $before])->fetchAll();
    }

    public function forStaff(array $filters, int $before): array
    {
        $where = ['t.id < ?'];
        $parameters = [$before];
        $status = $filters['status'] ?? '';
        if ($status !== '') {
            if (!in_array($status, TrainingWorkflow::STATUSES, true)) {
                throw new DomainException('Choose a valid training request status.', 422);
            }
            $where[] = 't.status = ?';
            $parameters[] = $status;
        }
        $assigned = $filters['assigned_admin_id'] ?? '';
        if ($assigned !== '') {
            $id = filter_var($assigned, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new DomainException('Choose a valid assigned staff account.', 422);
            }
            $where[] = 't.assigned_admin_id = ?';
            $parameters[] = $id;
        }
        $search = TrainingInput::text($filters['q'] ?? '', 'search', 200, false);
        if ($search !== '') {
            $where[] = "(t.reference LIKE ? ESCAPE '!' OR s.school_name LIKE ? ESCAPE '!')";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($parameters, $pattern, $pattern);
        }
        return TrainingAccess::query(($this->database)(), 'SELECT t.id, t.reference, t.status, t.school_id, t.participants, t.location,
            t.assigned_admin_id, t.created_at, t.updated_at, s.school_name FROM training_requests t
            JOIN school_onboarding s ON s.user_id = t.school_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 21', $parameters)->fetchAll();
    }

    public function find(int $actor, bool $staff, int $id): ?array
    {
        $db = ($this->database)();
        TrainingAccess::requireRole($db, $actor, $staff ? 'admin' : 'school');
        $row = TrainingAccess::query($db, 'SELECT * FROM training_requests WHERE id = ?' . ($staff ? '' : ' AND school_id = ?'), $staff ? [$id] : [$id, $actor])->fetch();
        if ($row === false) {
            return null;
        }
        $row['preferred_dates'] = json_decode($row['preferred_dates'], true, flags: JSON_THROW_ON_ERROR);
        // Submission tokens and hashes are intake controls, not part of the tracking interface.
        unset($row['client_token'], $row['payload_hash']);
        $row['modules'] = TrainingAccess::query($db, "SELECT module_id, module_revision, title_snapshot, snapshot FROM training_request_modules WHERE request_id = ?
            ORDER BY CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot, '$.selection_position')) AS UNSIGNED), module_id", [$id])->fetchAll();
        foreach ($row['modules'] as &$module) {
            $module['snapshot'] = json_decode($module['snapshot'], true, flags: JSON_THROW_ON_ERROR);
        }
        $row['history'] = TrainingAccess::query($db, 'SELECT action, from_status, to_status, note, details, actor_id, created_at FROM training_request_history WHERE request_id = ? ORDER BY id DESC LIMIT 100', [$id])->fetchAll();
        if ($staff) {
            $row['school_name'] = TrainingAccess::query($db, 'SELECT school_name FROM school_onboarding WHERE user_id = ?', [$row['school_id']])->fetchColumn();
        }
        return $row;
    }

    public function staffChoices(): array
    {
        return TrainingAccess::query(($this->database)(), "SELECT u.id, u.email FROM users u WHERE u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'admin')
            AND EXISTS (SELECT 1 FROM staff_user_roles sr JOIN staff_role_permissions p ON p.role_id = sr.role_id WHERE sr.user_id = u.id AND p.permission = 'training.manage') ORDER BY u.id")->fetchAll();
    }

    public function change(int $actor, int $id, int $revision, string $action, array $input): void
    {
        $note = TrainingInput::text($input['note'] ?? '', 'decision note', 4000, in_array($action, ['approve', 'cancel', 'reschedule'], true));
        Database::transaction(($this->database)(), function (PDO $db) use ($actor, $id, $revision, $action, $input, $note): void {
            TrainingAccess::requireRole($db, $actor, 'admin', true);
            $row = TrainingCatalogue::locked($db, 'training_requests', $id, $revision);
            if (!TrainingWorkflow::allows($row['status'], $action)) {
                throw new DomainException('That decision is unavailable at this stage.', 409);
            }
            $fields = ['revision' => $revision + 1];
            if ($action === 'assign') {
                $assignee = filter_var($input['assigned_admin_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($assignee === false) {
                    throw new DomainException('Choose an active administrator for this request.', 422);
                }
                TrainingAccess::requireRole($db, $assignee, 'admin', true);
                $fields += ['assigned_admin_id' => $assignee, 'approved_by' => null, 'approved_at' => null, 'approval_notes' => null];
            } elseif ($action === 'approve') {
                self::requireAssignee($db, $row);
                TrainingAccess::approvedSchool($db, (int) $row['school_id'], true);
                $fields += ['approved_by' => $actor, 'approved_at' => gmdate('Y-m-d H:i:s'), 'approval_notes' => $note];
            } elseif (in_array($action, ['schedule', 'reschedule'], true)) {
                if ($row['approved_at'] === null) {
                    throw new DomainException('Record approval before scheduling this request.', 409);
                }
                self::requireAssignee($db, $row);
                TrainingAccess::approvedSchool($db, (int) $row['school_id'], true);
                $fields += TrainingInput::schedule($input);
                $fields['status'] = 'scheduled';
            } elseif ($action === 'complete') {
                if ($row['scheduled_end'] === null || strtotime($row['scheduled_end'] . ' UTC') > time()) {
                    throw new DomainException('Record completion after the scheduled session has ended.', 409);
                }
                $fields += ['status' => 'completed', 'outcome' => TrainingInput::text($input['outcome'] ?? '', 'outcome', 4000), 'completed_at' => gmdate('Y-m-d H:i:s')];
            } elseif ($action === 'cancel') {
                $fields += ['status' => 'cancelled', 'cancelled_at' => gmdate('Y-m-d H:i:s')];
            } else {
                $fields['status'] = $action;
            }
            TrainingCatalogue::update($db, 'training_requests', $id, $fields);
            $to = $fields['status'] ?? $row['status'];
            self::history($db, $id, $actor, $action, $row['status'], $to, $note, $fields);
            self::notifySchool($db, $actor, (int) $row['school_id'], $id, $action);
            if ($action === 'assign') {
                VerificationEvents::record($db, $actor, $fields['assigned_admin_id'], 'training.assigned_staff',
                    ['training_request_id' => $id, 'recipient_role' => 'admin'], 'Training request assigned',
                    'A training request has been assigned to you. Open the training management queue.');
            }
        });
    }

    private static function requireAssignee(PDO $db, array $request): void
    {
        if ($request['assigned_admin_id'] === null) {
            throw new DomainException('Assign an administrator before approval or scheduling.', 409);
        }
        TrainingAccess::requireRole($db, (int) $request['assigned_admin_id'], 'admin', true);
    }

    private static function history(PDO $db, int $id, int $actor, string $action, ?string $from, string $to, string $note, array $details): void
    {
        TrainingAccess::query($db, 'INSERT INTO training_request_history (request_id, actor_id, action, from_status, to_status, note, details) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $actor, $action, $from, $to, $note, json_encode($details, JSON_THROW_ON_ERROR)]);
        VerificationEvents::audit($db, $actor, 'training.request_' . $action, ['training_request_id' => $id, 'from' => $from, 'to' => $to]);
    }

    private static function notifySchool(PDO $db, int $actor, int $owner, int $id, string $action): void
    {
        VerificationEvents::record($db, $actor, $owner, 'training.' . $action, ['training_request_id' => $id],
            'Training request update', 'Your institutional training request has an update. Open your training requests to review the details.');
    }
}

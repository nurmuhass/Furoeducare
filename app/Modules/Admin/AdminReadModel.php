<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Furoeducare\Core\HttpException;
use PDO;

/** Safe projections only. Callers must authorize single-module methods before calling. */
final class AdminReadModel
{
    public function __construct(private readonly Closure $database)
    {
    }

    /** Identifiers and expressions in this map are application-owned, never request input. */
    private const SCOPES = [
        'teachers.view' => ['Teachers', 'teacher_profiles', 'verification_status', '/dashboard/admin/accounts?type=teacher'],
        'schools.view' => ['Schools', 'school_onboarding', "COALESCE(review_status, 'incomplete')", '/dashboard/admin/accounts?type=school'],
        'parents.view' => ['Parents', 'parent_profiles', "'registered'", '/dashboard/admin/accounts?type=parent'],
        'verification.view' => ['Teacher verification', 'teacher_profiles', 'verification_status', '/dashboard/admin/verification'],
        'directory.view' => ['Directory listings', 'teacher_listings', "IF(approved_at IS NULL, 'withdrawn', 'published')", '/dashboard/admin/listings'],
        'vacancies.view' => ['Vacancies', 'vacancies', 'status', '/dashboard/admin/vacancies'],
        'applications.view' => ['Applications', 'vacancy_applications', 'status', '/dashboard/admin/vacancies/applications'],
        'tutoring.view' => ['Tutoring requests', 'parent_tutoring_requests', 'status', '/dashboard/admin/tutoring-requests'],
        'candidates.view' => ['Candidate requests', 'candidate_requests', 'status', '/dashboard/admin/candidate-requests'],
        'cv_requests.view' => ['CV requests', 'candidate_disclosure_requests', 'status', '/dashboard/admin/cv-requests'],
        'placements.view' => ['Placements', 'placements', 'status', '/dashboard/admin/placements'],
        'library.view' => ['Library resources', 'library_resources', 'status', '/dashboard/admin/library'],
        'training.view' => ['Training modules', 'training_modules', 'status', '/dashboard/admin/training'],
        'messages.view' => ['Conversations', 'message_conversations', 'status', '/dashboard/admin/messages'],
        'email.view' => ['Email deliveries', 'email_outbox', 'status', '/dashboard/admin/email'],
    ];

    public function overview(array $permissions): array
    {
        $metrics = [];
        foreach (self::SCOPES as $permission => [$label, $table, , $path]) {
            if (in_array($permission, $permissions, true)) {
                $metrics[] = ['label' => $label, 'value' => (int) $this->rows("SELECT COUNT(*) AS total FROM {$table}")[0]['total'], 'path' => $path];
            }
        }
        $result = ['metrics' => $metrics];
        if (in_array('audit.view', $permissions, true)) {
            $result['activity'] = $this->rows('SELECT id, actor_user_id, action, created_at FROM audit_logs ORDER BY id DESC LIMIT ?', [10]);
        }
        return $result;
    }

    /** Search ids are opaque keyset cursors: source id * 16 + fixed source number. */
    public function search(array $permissions, string $q, int $before): array
    {
        $q = self::text($q, 200);
        if ($q === '') {
            return [];
        }
        $parts = [];
        $parameters = [];
        $sources = [
            ['teachers.view', 'teacher_profiles p JOIN users u ON u.id = p.user_id', 'p.user_id', "CONCAT(p.first_name, ' ', p.last_name)", 'u.email', 'p.verification_status', 'teacher', '/dashboard/admin/accounts/view?id=', 1],
            ['schools.view', 'school_onboarding p JOIN users u ON u.id = p.user_id', 'p.user_id', 'p.school_name', 'u.email', "COALESCE(p.review_status, 'incomplete')", 'school', '/dashboard/admin/accounts/view?id=', 2],
            ['parents.view', 'parent_profiles p JOIN users u ON u.id = p.user_id', 'p.user_id', "CONCAT(p.first_name, ' ', p.last_name)", 'u.email', "'registered'", 'parent', '/dashboard/admin/accounts/view?id=', 3],
            ['cv_requests.view', 'candidate_disclosure_requests p', 'p.id', 'p.reference', 'p.scope', 'p.status', 'cv_request', '/dashboard/admin/cv-requests?id=', 4],
            ['library.view', 'library_resources p', 'p.id', 'p.title', 'p.reference', 'p.status', 'library', '/dashboard/admin/library?id=', 5],
            ['training.view', 'training_modules p', 'p.id', 'p.title', 'p.category', 'p.status', 'training', '/dashboard/admin/training?id=', 6],
            ['vacancies.view', 'vacancies p', 'p.id', "COALESCE(p.content->>'$.title', CONCAT('Vacancy #', p.id))", "CONCAT('Vacancy #', p.id)", 'p.status', 'vacancy', '/dashboard/admin/vacancies?id=', 7, "p.status <> 'draft'"],
            ['applications.view', 'vacancy_applications p', 'p.id', 'p.job_title', 'p.candidate_reference', 'p.status', 'application', '/dashboard/admin/vacancies/application?id=', 8],
            ['tutoring.view', 'parent_tutoring_requests p', 'p.id', 'p.subject', "CONCAT('Tutoring request #', p.id)", 'p.status', 'tutoring', '/dashboard/admin/tutoring-requests?id=', 9, "p.status <> 'draft'"],
            ['candidates.view', 'candidate_requests p', 'p.id', "CONCAT('Candidate request #', p.id)", "CONCAT_WS(' ', p.category, p.selected_reference)", 'p.status', 'candidate', '/dashboard/admin/candidate-requests?id=', 10, "p.status <> 'draft'"],
            ['placements.view', 'placements p', 'p.id', "CONCAT('Placement #', p.id)", 'p.kind', 'p.status', 'placement', '/dashboard/admin/placements?id=', 11],
            ['messages.view', 'message_conversations p', 'p.id', 'p.subject', "CONCAT('Conversation #', p.id)", 'p.status', 'message', '/dashboard/admin/messages?id=', 12,
                "p.purged_at IS NULL AND (p.retention_hold = 1 OR p.status <> 'closed' OR p.closed_at > UTC_TIMESTAMP(6) - INTERVAL 365 DAY)"],
        ];
        foreach ($sources as $definition) {
            [$permission, $from, $key, $label, $detail, $status, $type, $path, $source, $visibility] = array_pad($definition, 10, '1 = 1');
            if (!in_array($permission, $permissions, true)) {
                continue;
            }
            // DECIMAL arithmetic avoids overflow for BIGINT database identifiers.
            $cursor = "(CAST({$key} AS DECIMAL(30,0)) * 16 + {$source})";
            // Email, public references and enums use different source collations. Every
            // UNION text projection needs one explicit collation, even for empty sources.
            $labelOutput = self::searchText($label);
            $detailOutput = self::searchText($detail);
            $pathOutput = self::searchText("CONCAT('{$path}', {$key})");
            $typeOutput = self::searchText("'{$type}'");
            $statusOutput = self::searchText($status);
            $parts[] = "SELECT {$cursor} AS id, {$labelOutput} AS label, {$detailOutput} AS detail, {$pathOutput} AS path,
                {$typeOutput} AS type, {$statusOutput} AS status FROM {$from}
                WHERE ({$visibility}) AND {$cursor} < ? AND ({$label} LIKE ? ESCAPE '!' OR {$detail} LIKE ? ESCAPE '!')";
            array_push($parameters, $before, self::pattern($q), self::pattern($q));
        }
        if ($parts === []) {
            return [];
        }
        $parameters[] = 21;
        $rows = $this->rows('SELECT id, label, detail, path, type, status FROM (' . implode(' UNION ALL ', $parts) . ') results ORDER BY id DESC LIMIT ?', $parameters);
        foreach ($rows as &$row) {
            if (in_array($row['type'], ['teacher', 'school', 'parent'], true)) {
                $row['path'] .= '&type=' . $row['type'];
            }
        }
        return $rows;
    }

    private static function searchText(string $expression): string
    {
        return "CONVERT({$expression} USING utf8mb4) COLLATE utf8mb4_0900_ai_ci";
    }

    private static function accountSource(string $type): array
    {
        return match ($type) {
            'teacher' => ['teacher_profiles', "CONCAT(p.first_name, ' ', p.last_name)", 'p.phone', "''", 'p.verification_status'],
            'school' => ['school_onboarding', 'p.school_name', "COALESCE(p.contact_phone, '')", "CONCAT_WS(', ', p.city, p.state_code)", "COALESCE(p.review_status, 'incomplete')"],
            'parent' => ['parent_profiles', "CONCAT(p.first_name, ' ', p.last_name)", 'p.phone', "CONCAT_WS(', ', p.city, p.state_code)", "'registered'"],
            default => throw new HttpException(422, 'Choose a valid account type.'),
        };
    }

    public function accounts(string $type, array $filters, int $before): array
    {
        [$table, $name, $phone, $location, $status] = self::accountSource($type);
        $where = ['u.id < ?'];
        $params = [$before];
        $q = self::text($filters['q'] ?? '', 200);
        if ($q !== '') {
            $where[] = "({$name} LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            array_push($params, self::pattern($q), self::pattern($q));
        }
        $filter = self::text($filters['status'] ?? '', 40);
        if ($filter !== '') {
            if ($filter === 'suspended') {
                $where[] = 'u.suspended_at IS NOT NULL';
            } elseif ($filter === 'active') {
                $where[] = 'u.suspended_at IS NULL';
            } elseif ($filter === 'unverified') {
                $where[] = 'u.email_verified_at IS NULL';
            } else {
                $where[] = "{$status} = ?";
                $params[] = $filter;
            }
        }
        $params[] = 21;
        return $this->rows("SELECT u.id, u.email, {$name} AS name, {$phone} AS phone, {$location} AS location,
            u.email_verified_at, u.suspended_at, u.auth_version, {$status} AS profile_status
            FROM users u JOIN {$table} p ON p.user_id = u.id WHERE " . implode(' AND ', $where) . ' ORDER BY u.id DESC LIMIT ?', $params);
    }

    public function account(int $id, ?string $type = null): ?array
    {
        if ($type !== null) {
            self::accountSource($type);
        }
        $rows = $this->rows('SELECT id, email, email_verified_at, suspended_at, auth_version, created_at FROM users WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }
        $account = $rows[0] + ['name' => '', 'phone' => '', 'location' => '', 'profile_status' => '', 'account_types' => []];
        $account['roles'] = array_column($this->rows('SELECT r.slug FROM roles r JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.slug', [$id]), 'slug');
        foreach (['teacher', 'school', 'parent'] as $profileType) {
            [$table, $name, $phone, $location, $status] = self::accountSource($profileType);
            $profiles = $this->rows("SELECT {$name} AS name, {$phone} AS phone, {$location} AS location, {$status} AS profile_status FROM {$table} p WHERE p.user_id = ?", [$id]);
            if ($profiles !== []) {
                if (($type === null && $account['account_types'] === []) || $type === $profileType) {
                    $account = array_replace($account, $profiles[0]);
                }
                $account['account_types'][] = $profileType;
            }
        }
        return $account;
    }

    public function listings(array $filters, int $before): array
    {
        $status = "CASE WHEN l.id IS NULL THEN 'not_listed' WHEN l.approved_at IS NULL THEN 'withdrawn' ELSE 'published' END";
        $where = ['p.user_id < ?'];
        $params = [$before];
        $q = self::text($filters['q'] ?? '', 200);
        if ($q !== '') {
            $where[] = "(CONCAT(p.first_name, ' ', p.last_name) LIKE ? ESCAPE '!' OR l.title LIKE ? ESCAPE '!' OR l.reference LIKE ? ESCAPE '!')";
            array_push($params, self::pattern($q), self::pattern($q), self::pattern($q));
        }
        $filter = self::text($filters['status'] ?? '', 40);
        if ($filter !== '') {
            if (!in_array($filter, ['published', 'withdrawn', 'not_listed'], true)) {
                throw new HttpException(422, 'Choose a valid listing status.');
            }
            $where[] = "{$status} = ?";
            $params[] = $filter;
        }
        $params[] = 21;
        return $this->rows("SELECT p.user_id AS id, CONCAT(p.first_name, ' ', p.last_name) AS name, COALESCE(l.title, '') AS title,
            COALESCE(l.reference, '') AS reference, {$status} AS listing_status, p.verification_status
            FROM teacher_profiles p LEFT JOIN teacher_listings l ON l.teacher_id = p.user_id WHERE " . implode(' AND ', $where) . ' ORDER BY p.user_id DESC LIMIT ?', $params);
    }

    public function cvRequests(array $filters, int $before): array
    {
        $where = ['r.id < ?'];
        $params = [$before];
        self::filters($filters, ['status' => 'r.status', 'scope' => 'r.scope'], $where, $params);
        $q = self::text($filters['q'] ?? '', 200);
        if ($q !== '') {
            $where[] = "r.reference LIKE ? ESCAPE '!'";
            $params[] = self::pattern($q);
        }
        $params[] = 21;
        return $this->rows('SELECT r.id, r.case_id, r.reference, r.scope, r.status, r.created_at, c.status AS case_status
            FROM candidate_disclosure_requests r JOIN candidate_requests c ON c.id = r.case_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT ?', $params);
    }

    public function email(array $filters, int $before): array
    {
        $where = ['id < ?'];
        $params = [$before];
        self::filters($filters, ['status' => 'status', 'template' => 'template'], $where, $params);
        $params[] = 21;
        $rows = $this->rows('SELECT id, user_id, template, status, attempts, max_attempts, last_error_code, provider_status, created_at, accepted_at
            FROM email_outbox WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ?', $params);
        foreach ($rows as &$row) {
            // Provider replies are untrusted free text; disclose recognized operational codes only.
            $row['provider_status'] = in_array($row['provider_status'], ['accepted', 'stored'], true) ? $row['provider_status'] : ($row['provider_status'] === null ? null : 'other');
            $safeErrors = ['lease_expired', 'recipient_or_preference_changed', 'acceptance_recording_failed', 'mail_processing_error', 'transport_error',
                'file_unavailable', 'file_conflict', 'message_invalid', 'smtp_permanent', 'smtp_temporary', 'smtp_uncertain', 'smtp_connection'];
            $row['last_error_code'] = $row['last_error_code'] === null ? null : (in_array($row['last_error_code'], $safeErrors, true) ? $row['last_error_code'] : 'transport_error');
        }
        return $rows;
    }

    public function reports(array $permissions, string $fromYmd, string $toYmd): array
    {
        $zone = new DateTimeZone('Africa/Lagos');
        $from = self::date($fromYmd, $zone);
        $to = self::date($toYmd, $zone);
        if ($from > $to) {
            throw new HttpException(422, 'The report end date must follow the start date.');
        }
        $utc = new DateTimeZone('UTC');
        $params = [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
        $result = [];
        foreach (self::SCOPES as $permission => [$label, $table, $status]) {
            if (in_array($permission, $permissions, true)) {
                $result[] = ['label' => $label, 'rows' => $this->rows("SELECT {$status} AS status, COUNT(*) AS total FROM {$table} WHERE created_at >= ? AND created_at < ? GROUP BY {$status} ORDER BY status", $params)];
            }
        }
        return $result;
    }

    public function audit(array $filters, int $before): array
    {
        $where = ['id < ?'];
        $params = [$before];
        self::filters($filters, ['action' => 'action'], $where, $params);
        $actor = $filters['actor'] ?? '';
        if ($actor !== '' && $actor !== null) {
            if (filter_var($actor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new HttpException(422, 'Choose a valid actor.');
            }
            $where[] = 'actor_user_id = ?';
            $params[] = (int) $actor;
        }
        $params[] = 31;
        return $this->rows('SELECT id, actor_user_id, action, created_at FROM audit_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ?', $params);
    }

    /** Actor activity only: audit context is deliberately never returned or searched. */
    public function activity(int $target): array
    {
        return $this->rows('SELECT id, actor_user_id, action, created_at FROM audit_logs WHERE actor_user_id = ? ORDER BY id DESC LIMIT ?', [$target, 20]);
    }

    private static function filters(array $filters, array $columns, array &$where, array &$params): void
    {
        foreach ($columns as $key => $column) {
            $value = self::text($filters[$key] ?? '', $key === 'action' ? 100 : 40);
            if ($value !== '') {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }
    }

    private static function text(mixed $value, int $maximum): string
    {
        if (!is_string($value) || strlen($value) > $maximum * 4 || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1 || preg_match_all('/./us', $value) > $maximum) {
            throw new HttpException(422, 'Enter a valid filter.');
        }
        return trim($value);
    }

    private static function pattern(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }

    private static function date(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            throw new HttpException(422, 'Enter valid report dates (YYYY-MM-DD).');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value >= '9999-12-31') {
            throw new HttpException(422, 'Enter valid report dates (YYYY-MM-DD).');
        }
        return $date;
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $db = ($this->database)();
        $query = $db->prepare($sql);
        foreach ($parameters as $index => $value) {
            $query->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}

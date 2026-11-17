<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PublicDirectory
{
    private const PAGE_SIZE = 12;
    private const PUBLIC_COLUMNS = 'l.id, l.reference, l.title, l.bio, l.qualification, l.experience, l.state_code, l.city,
        l.professional_name, l.photograph IS NOT NULL AS has_photo';
    private const MODE_SQL = <<<'SQL'
        l.availability_mask & CASE
            WHEN COALESCE(JSON_LENGTH(JSON_EXTRACT(p.sections, '$.availability.opportunities')), 0) = 0 THEN 15
            ELSE IF(JSON_CONTAINS(p.sections, '"full_time"', '$.availability.opportunities'), 1, 0)
                + IF(JSON_CONTAINS(p.sections, '"part_time"', '$.availability.opportunities'), 2, 0)
                + IF(JSON_CONTAINS(p.sections, '"private_tutoring"', '$.availability.opportunities'), 4, 0)
                + IF(JSON_CONTAINS(p.sections, '"online_tutoring"', '$.availability.opportunities'), 8, 0)
        END
        SQL;
    private const ELIGIBLE_SQL = <<<'SQL'
        l.approved_at IS NOT NULL AND p.published_at IS NOT NULL AND p.verification_status <> 'suspended'
        AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL
        AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = u.id AND r.slug = 'teacher')
        AND (p.sections->>'$.availability.status' = 'now'
            OR (p.sections->>'$.availability.status' = 'from_date'
                AND p.sections->>'$.availability.date' REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                AND p.sections->>'$.availability.date' <= :today))
        SQL;

    public function __construct(private readonly Closure $database)
    {
    }

    public function search(array $filters, ?string $after, ?string $before, ?int $owner = null, bool $savedOnly = false, int $requiredModes = 0): array
    {
        if ($savedOnly && $owner === null) {
            return ['listings' => [], 'previous' => null, 'next' => null];
        }
        $where = [];
        $having = [];
        $params = [];
        if ($requiredModes !== 0) {
            $having[] = '(current_modes & :required_modes) <> 0';
            $params['required_modes'] = $requiredModes;
        }
        if ($after !== null) {
            $where[] = 'l.reference > :cursor';
            $params['cursor'] = $after;
        } elseif ($before !== null) {
            $where[] = 'l.reference < :cursor';
            $params['cursor'] = $before;
        }
        foreach (['state' => 'l.state_code = :state', 'city' => "l.city LIKE :city ESCAPE '!'",
            'qualification' => "l.qualification LIKE :qualification ESCAPE '!'", 'experience' => 'l.experience >= :experience'] as $key => $condition) {
            if (isset($filters[$key])) {
                $where[] = $condition;
                $params[$key] = in_array($key, ['city', 'qualification'], true)
                    ? self::like($filters[$key]) . '%' : $filters[$key];
            }
        }
        foreach (['subject', 'level'] as $kind) {
            if (isset($filters[$kind])) {
                $where[] = "EXISTS (SELECT 1 FROM teacher_listing_tags t WHERE t.listing_id = l.id
                    AND t.kind = '$kind' AND t.label LIKE :$kind ESCAPE '!')";
                $params[$kind] = self::like($filters[$kind]) . '%';
            }
        }
        if (isset($filters['q'])) {
            $where[] = "(l.title LIKE :q_title ESCAPE '!' OR l.bio LIKE :q_bio ESCAPE '!'
                OR l.qualification LIKE :q_qualification ESCAPE '!' OR l.city LIKE :q_city ESCAPE '!'
                OR EXISTS (SELECT 1 FROM teacher_listing_tags t WHERE t.listing_id = l.id AND t.label LIKE :q_tag ESCAPE '!'))";
            foreach (['title', 'bio', 'qualification', 'city', 'tag'] as $key) {
                $params['q_' . $key] = '%' . self::like($filters['q']) . '%';
            }
        }
        if (isset($filters['tutoring'])) {
            $having[] = '(current_modes & :tutoring) <> 0';
            $params['tutoring'] = DirectoryFilters::MODES[DirectoryFilters::TUTORING[$filters['tutoring']]];
        }
        if (isset($filters['availability'])) {
            $having[] = '(current_modes & :availability) <> 0';
            $params['availability'] = DirectoryFilters::MODES[$filters['availability']];
        }

        $columns = self::PUBLIC_COLUMNS;
        $join = '';
        if ($owner !== null) {
            $columns .= ', b.user_id IS NOT NULL AS saved';
            $join = ($savedOnly ? ' INNER' : ' LEFT') . ' JOIN saved_teachers b ON b.listing_id = l.id AND b.user_id = :owner';
            $params['owner'] = $owner;
        }
        $reverse = $before !== null && $after === null;
        $rows = $this->visible($columns, $where, $having, $params, $join,
            ' ORDER BY l.reference ' . ($reverse ? 'DESC' : 'ASC') . ' LIMIT ' . (self::PAGE_SIZE + 1));
        $more = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        if ($reverse) {
            $rows = array_reverse($rows);
        }

        return [
            'listings' => $this->withTags($rows),
            'previous' => $rows !== [] && ($reverse ? $more : $after !== null) ? $rows[0]['reference'] : null,
            'next' => $rows !== [] && ($reverse ? $before !== null : $more) ? $rows[array_key_last($rows)]['reference'] : null,
        ];
    }

    public function find(string $reference, ?int $owner = null): ?array
    {
        $parameters = ['reference' => $reference];
        $columns = self::PUBLIC_COLUMNS;
        $join = '';
        if ($owner !== null) {
            $columns .= ', b.user_id IS NOT NULL AS saved';
            $join = ' LEFT JOIN saved_teachers b ON b.listing_id = l.id AND b.user_id = :owner';
            $parameters['owner'] = $owner;
        }
        return $this->withTags($this->visible($columns, ['l.reference = :reference'], [], $parameters, $join))[0] ?? null;
    }

    public function photograph(string $reference): ?string
    {
        $rows = $this->visible('l.photograph', ['l.reference = :reference', 'l.photograph IS NOT NULL'], [], ['reference' => $reference]);
        return $rows[0]['photograph'] ?? null;
    }

    /** Resolve and lock a still-visible target after locking both user rows in the write transaction. */
    public function lockTarget(string $reference): ?array
    {
        return $this->visible('l.id, l.teacher_id, l.title', ['l.reference = :reference'], [], ['reference' => $reference],
            suffix: ' FOR UPDATE OF l, p, u')[0] ?? null;
    }

    public function tutorSummaries(array $references, int $modeMask): array
    {
        if ($references === []) {
            return [];
        }
        $parameters = ['mode_mask' => $modeMask];
        $marks = [];
        foreach (array_values($references) as $index => $reference) {
            $key = 'tutor_' . $index;
            $marks[] = ':' . $key;
            $parameters[$key] = $reference;
        }
        return $this->visible('l.reference, l.title', ['l.reference IN (' . implode(', ', $marks) . ')'],
            ['(current_modes & :mode_mask) <> 0'], $parameters);
    }

    private function visible(string $columns, array $where, array $having, array $params, string $join = '', string $suffix = ''): array
    {
        $filters = implode(' AND ', [self::ELIGIBLE_SQL, ...$where]);
        $modes = implode(' AND ', ['current_modes <> 0', ...$having]);
        $sql = 'SELECT ' . $columns . ', (' . self::MODE_SQL . ') AS current_modes
            FROM teacher_listings l JOIN teacher_profiles p ON p.user_id = l.teacher_id JOIN users u ON u.id = p.user_id'
            . $join . ' WHERE ' . $filters . ' HAVING ' . $modes . $suffix;
        $query = ($this->database)()->prepare($sql);
        $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Lagos'));
        $query->execute(['today' => $today->format('Y-m-d')] + $params);
        return $query->fetchAll();
    }

    private function withTags(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $query = ($this->database)()->prepare(
            "SELECT listing_id, kind, label FROM teacher_listing_tags WHERE listing_id IN ($marks) ORDER BY label"
        );
        $query->execute($ids);
        $tags = [];
        foreach ($query->fetchAll() as $tag) {
            $tags[$tag['listing_id']][$tag['kind']][] = $tag['label'];
        }
        foreach ($rows as &$row) {
            $row['subjects'] = $tags[$row['id']]['subject'] ?? [];
            $row['levels'] = $tags[$row['id']]['level'] ?? [];
            unset($row['id']);
        }
        unset($row);
        return $rows;
    }

    private static function like(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}

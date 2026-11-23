<?php
declare(strict_types=1);

namespace Furoeducare\UI;

use Closure;

/** Small escaped, server-rendered components; no client template engine or dependency. */
final class Components
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function local(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && preg_match('/[\\\\\x00-\x20]/', $path) !== 1;
    }

    public static function metric(string $label, int|string $value, ?string $path = null, string $hint = ''): string
    {
        $body = '<span class="dashboard-metric-label">' . self::escape($label) . '</span><strong>' . self::escape((string) $value) . '</strong>'
            . ($hint !== '' ? '<small>' . self::escape($hint) . '</small>' : '');
        return $path !== null && self::local($path)
            ? '<a class="dashboard-metric" href="' . self::escape($path) . '">' . $body . '</a>'
            : '<div class="dashboard-metric">' . $body . '</div>';
    }

    public static function status(string $status, ?string $label = null): string
    {
        $tone = match ($status) {
            'approved', 'verified', 'published', 'confirmed', 'completed', 'active', 'successful' => 'success',
            'rejected', 'suspended', 'failed', 'unsuccessful' => 'danger',
            'pending', 'submitted', 'under_review', 'changes_required', 'pending_verification', 'pending_approval' => 'warning',
            default => 'neutral',
        };
        return '<span class="dashboard-status dashboard-status-' . $tone . '">' . self::escape($label ?? ucfirst(str_replace('_', ' ', $status))) . '</span>';
    }

    public static function empty(string $title, string $message): string
    {
        return '<div class="dashboard-state" data-empty-state><strong>' . self::escape($title) . '</strong><p>' . self::escape($message) . '</p></div>';
    }

    public static function error(array $errors): string
    {
        if ($errors === []) return '';
        $html = '<div class="dashboard-error" role="alert" tabindex="-1" data-error-summary><strong>Review the highlighted fields.</strong><ul>';
        foreach ($errors as $error) if (is_scalar($error)) $html .= '<li>' . self::escape((string) $error) . '</li>';
        return $html . '</ul></div>';
    }

    public static function table(array $rows, array $columns, string $caption, ?Closure $destination = null, ?Closure $format = null): string
    {
        if ($rows === []) return self::empty('No matching records', 'Records will appear here when available. Try clearing the filters.');
        $html = '<div class="table-responsive dashboard-table-scroll" role="region" aria-label="' . self::escape($caption) . '" tabindex="0"><table class="table dashboard-table"><caption>' . self::escape($caption) . '</caption><thead><tr>';
        foreach ($columns as $label) $html .= '<th scope="col">' . self::escape($label) . '</th>';
        if ($destination !== null) $html .= '<th scope="col">Open</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $key => $label) {
                $value = $format !== null ? $format($row[$key] ?? null, $key) : (string) ($row[$key] ?? '—');
                $html .= '<td>' . ($key === 'status' || str_ends_with($key, '_status') ? self::status($value) : self::escape($value)) . '</td>';
            }
            if ($destination !== null) {
                $path = $destination($row);
                $html .= '<td>' . (self::local($path) ? '<a href="' . self::escape($path) . '">View<span class="visually-hidden"> record ' . self::escape((string) ($row['id'] ?? '')) . '</span></a>' : 'Unavailable') . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    public static function pagination(string $path, array $query, ?int $next): string
    {
        if ($next === null || !self::local($path)) return '';
        $query['before'] = $next;
        $first = $query;
        unset($first['before']);
        return '<nav class="dashboard-pagination" aria-label="Pagination"><a href="' . self::escape($path . ($first === [] ? '' : '?' . http_build_query($first))) . '">Latest records</a><a class="btn button button-outline" rel="next" href="' . self::escape($path . '?' . http_build_query($query)) . '">Older records</a></nav>';
    }

    public static function search(string $path, string $query, string $label): string
    {
        if (!self::local($path)) return '';
        return '<form class="dashboard-search" method="get" action="' . self::escape($path) . '" role="search"><label>' . self::escape($label) . '<input class="form-control" type="search" name="q" maxlength="200" value="' . self::escape($query) . '"></label><button class="btn button button-outline" type="submit">Search</button></form>';
    }

    /** @param array<string,array{label:string,options?:array,type?:string}> $fields */
    public static function filters(string $path, array $fields, array $values): string
    {
        if (!self::local($path)) return '';
        $html = '<form method="get" action="' . self::escape($path) . '" class="form-panel dashboard-filters mb-4" aria-label="Filter records">';
        foreach ($fields as $name => $field) {
            $id = 'filter-' . substr(hash('sha256', $path . $name), 0, 12);
            $value = is_scalar($values[$name] ?? null) ? (string) $values[$name] : '';
            $html .= '<div><label class="form-label" for="' . $id . '">' . self::escape($field['label']) . '</label>';
            if (isset($field['options'])) {
                $html .= '<select class="form-select" id="' . $id . '" name="' . self::escape($name) . '">';
                foreach ($field['options'] as $key => $label) $html .= '<option value="' . self::escape((string) $key) . '"' . ($value === (string) $key ? ' selected' : '') . '>' . self::escape($label) . '</option>';
                $html .= '</select>';
            } elseif (($field['type'] ?? '') === 'checkbox') {
                $html .= '<input class="form-check-input d-block" id="' . $id . '" type="checkbox" name="' . self::escape($name) . '" value="1"' . ($value === '1' ? ' checked' : '') . '>';
            } else {
                $html .= '<input class="form-control" type="search" id="' . $id . '" name="' . self::escape($name) . '" value="' . self::escape($value) . '" maxlength="200">';
            }
            $html .= '</div>';
        }
        return $html . '<button class="btn button button-primary" type="submit">Apply filters</button><a href="' . self::escape($path) . '">Clear filters</a></form>';
    }
}

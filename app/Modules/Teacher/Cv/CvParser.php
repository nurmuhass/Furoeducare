<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

final class CvParser
{
    private const HEADINGS = [
        'summary' => ['about me', 'career objective', 'career profile', 'objective', 'personal statement', 'profile', 'professional profile', 'professional summary', 'summary'],
        'qualifications' => ['academic background', 'academic qualifications', 'education', 'education and qualifications', 'educational background', 'qualifications'],
        'experience' => ['career history', 'employment', 'employment history', 'experience', 'professional experience', 'teaching experience', 'work experience', 'work history'],
        'skills' => ['competencies', 'core competencies', 'professional skills', 'skills', 'technical skills'],
        'subjects' => ['subjects', 'subjects taught', 'teaching subjects'],
        'certifications' => ['certificates', 'certifications', 'licenses and certifications', 'professional certifications'],
        'ignore' => ['achievements', 'awards', 'contact', 'contact information', 'hobbies', 'interests', 'languages', 'personal details', 'references', 'referees', 'volunteering'],
    ];

    public function parse(#[\SensitiveParameter] string $text): array
    {
        $sections = [];
        $section = 'ignore';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\p{C}/u', '', str_replace("\t", ' ', $line)) ?? '');
            $line = preg_replace('/^(?:[•▪●*]|[-–])\s+/u', '', $line) ?? '';
            [$label, $remainder] = array_pad(explode(':', $line, 2), 2, '');
            $label = strtolower(trim(str_replace('&', 'and', $label)));
            foreach (self::HEADINGS as $key => $names) {
                if (in_array($label, $names, true)) {
                    $section = $key;
                    $line = trim($remainder);
                    break;
                }
            }
            if ($section !== 'ignore') {
                $sections[$section][] = $line;
            }
        }

        return [
            'version' => CvDraft::VERSION,
            'summary' => $this->clip(implode("\n", $sections['summary'] ?? []), 2500),
            'qualifications' => $this->records($sections['qualifications'] ?? [], true),
            'experience' => $this->records($sections['experience'] ?? [], false),
            'skills' => $this->items($sections['skills'] ?? [], '/[;,•]/u'),
            'subjects' => $this->items($sections['subjects'] ?? [], '/[;,•]/u'),
            'certifications' => $this->items($sections['certifications'] ?? [], '/[;•]/u'),
        ];
    }

    private function records(array $lines, bool $education): array
    {
        $fields = $education ? CvDraft::QUALIFICATION : CvDraft::EXPERIENCE;
        $primary = $education ? 'qualification' : 'position_title';
        $institution = $education ? 'institution' : 'organisation';
        $blank = array_fill_keys(array_keys($fields), null);
        $row = $blank;
        $rows = [];
        foreach ($lines as $line) {
            $parts = $line === '' ? [] : preg_split('/\s*\|\s*/u', $line);
            $values = $parts === [] ? [] : $this->recordLine($parts, $education);
            if ($parts === [] || isset($values[$primary], $row[$primary])
                || (isset($values[$institution], $row[$institution]) && $values[$institution] !== $row[$institution])) {
                if ($row !== $blank) {
                    $rows[] = $row;
                    if (count($rows) === CvDraft::LIMIT) {
                        return $rows;
                    }
                }
                $row = $blank;
            }
            if ($values === [] && $line !== '' && $education && $row[$primary] === null) {
                $values[$primary] = $this->clip($line, 160);
            } elseif ($values === [] && $line !== '' && !$education) {
                $values['description'] = $this->clip(trim(($row['description'] ?? '') . "\n" . $line), 1000);
            }
            foreach ($values as $key => $value) {
                if ($row[$key] === null || $key === 'description') {
                    $row[$key] = $value;
                }
            }
            if (isset($row['started_year'], $row['ended_year']) && $row['ended_year'] < $row['started_year']) {
                $row['ended_year'] = null;
            }
        }
        if ($row !== $blank && count($rows) < CvDraft::LIMIT) {
            $rows[] = $row;
        }
        return array_slice($rows, 0, CvDraft::LIMIT);
    }

    private function recordLine(array $parts, bool $education): array
    {
        $primary = $education ? 'qualification' : 'position_title';
        $institution = $education ? 'institution' : 'organisation';
        $values = [];
        foreach ($parts as $index => $part) {
            $part = trim($part);
            if (preg_match('/^(?:(?:dates?|year|completed)\s*:\s*)?((?:19|20)\d{2})(?:\s*[-–—]\s*((?:19|20)\d{2}|present|current|ongoing))?$/iu', $part, $date) === 1) {
                $years = $education ? ['completed_year' => (int) ($date[2] ?? $date[1])] : ['started_year' => (int) $date[1]];
                if (!$education && isset($date[2]) && ctype_digit($date[2])) {
                    $years['ended_year'] = (int) $date[2];
                }
                $values += array_filter($years, static fn (int $year): bool => $year >= 1900 && $year <= (int) gmdate('Y') + 10);
                continue;
            }

            [$label, $detail] = array_pad(explode(':', $part, 2), 2, '');
            $field = match (strtolower(trim($label))) {
                'role', 'position', 'job title', 'title' => $education ? null : $primary,
                'qualification', 'degree' => $education ? $primary : null,
                'employer', 'organisation', 'organization', 'company', 'school', 'institution' => $institution,
                'field of study', 'discipline' => $education ? 'field_of_study' : null,
                default => null,
            };
            if ($field !== null && $detail !== '') {
                $values[$field] = $this->clip($detail, 160);
            } elseif ($index === 0 && preg_match('/^(.+?)\s+at\s+(.+)$/iu', $part, $match) === 1) {
                $values[$primary] = $this->clip($match[1], 160);
                $values[$institution] = $this->clip($match[2], 160);
            } elseif ($index === 0 && count($parts) > 1 && isset($parts[1])
                && preg_match('/^(?:19|20)\d{2}\b/', $part) !== 1
                && !$this->institution($part)) {
                $values[$primary] = $this->clip($part, 160);
            } elseif ($index === 1 && isset($values[$primary]) && !isset($values[$institution])) {
                $values[$institution] = $this->clip($part, 160);
            } elseif ($this->institution($part)) {
                $values[$institution] = $this->clip($part, 160);
            } elseif ($education && $index > 1 && isset($values[$primary], $values[$institution])) {
                $values['field_of_study'] ??= $this->clip($part, 160);
            } elseif (($education && preg_match('/^(?:B\.?\s?(?:Ed|Sc|A|Tech)|M\.?\s?(?:Ed|Sc|A)|NCE|PGDE|HND|OND|Ph\.?D|Bachelor|Master|Diploma)\b/i', $part) === 1)
                || (!$education && preg_match('/^(?:[\p{L} \/&-]{1,70}\s+)?(?:teacher|tutor|lecturer|instructor|principal|coordinator|assistant)$/iu', $part) === 1)) {
                $values[$primary] = $this->clip($part, 160);
            }
        }
        return array_filter($values, static fn ($value): bool => $value !== null);
    }

    private function institution(string $line): bool
    {
        return preg_match('/\b(?:university|polytechnic|college|academy|school|centre|center|institute|limited|ltd)\b/iu', $line) === 1
            && preg_match('/[.!?]$/u', $line) !== 1;
    }

    private function items(array $lines, string $separator): array
    {
        $items = [];
        foreach ($lines as $line) {
            foreach (preg_split($separator, $line) ?: [] as $item) {
                $item = $this->clip($item, 100);
                if ($item !== null && !in_array($item, $items, true)) {
                    $items[] = $item;
                    if (count($items) === CvDraft::LIMIT) {
                        return $items;
                    }
                }
            }
        }
        return $items;
    }

    private function clip(string $text, int $limit): ?string
    {
        return preg_match('/\A.{1,' . $limit . '}/us', trim($text), $part) === 1 ? trim($part[0]) : null;
    }
}

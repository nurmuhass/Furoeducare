<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Library;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class LibraryInput
{
    public const ACCESS = ['free', 'restricted', 'future_paid'];
    public const TAGS = ['categories' => 'category', 'subjects' => 'subject', 'levels' => 'level'];

    public static function resource(array $input): array
    {
        $fields = [];
        foreach (['title' => 200, 'author' => 160, 'description' => 6000, 'publisher' => 160,
            'edition' => 80, 'isbn' => 32, 'licence' => 160, 'rights_reference' => 300] as $name => $maximum) {
            $fields[$name] = self::text($input[$name] ?? '', $name, $maximum, $name === 'title');
        }
        $fields['access'] = $input['access'] ?? 'free';
        if (!in_array($fields['access'], self::ACCESS, true)) {
            throw new DomainException('Choose free, restricted or future paid access.', 422);
        }
        $year = $input['publication_year'] ?? '';
        $fields['publication_year'] = $year === '' ? null : filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999]]);
        if ($fields['publication_year'] === false) {
            throw new DomainException('Enter a valid publication year or leave it blank.', 422);
        }
        $date = $input['rights_expires_on'] ?? '';
        $fields['rights_expires_on'] = $date === '' ? null : self::date($date);
        $fields['rights_confirmed'] = ($input['rights_confirmed'] ?? '') === '1';
        $fields['tags'] = [];
        foreach (self::TAGS as $plural => $kind) {
            $value = self::text($input[$plural] ?? '', $plural, 1300);
            $labels = [];
            foreach (preg_split('/\R/u', $value) as $line) {
                $label = self::text($line, $plural, 120);
                if ($label !== '') {
                    $labels[strtolower($label)] ??= $label;
                }
            }
            if (count($labels) > 10) {
                throw new DomainException('Use at most ten entries for each category, subject and education level.', 422);
            }
            $fields['tags'][$plural] = array_values($labels);
        }
        return $fields;
    }

    public static function text(mixed $value, string $label, int $maximum, bool $required = false): string
    {
        if (!is_string($value)) {
            throw new DomainException('Enter valid text for ' . str_replace('_', ' ', $label) . '.', 422);
        }
        $value = trim($value);
        if (($required && $value === '') || preg_match('/\A.{0,' . $maximum . '}\z/us', $value) !== 1
            || preg_match('/[^\P{Cc}\r\n\t]/u', $value) !== 0) {
            throw new DomainException('Use ' . ($required ? '1–' : 'up to ') . $maximum . ' characters for ' . str_replace('_', ' ', $label) . '.', 422);
        }
        return $value;
    }

    public static function grantExpiry(string $value): string
    {
        $zone = new DateTimeZone('Africa/Lagos');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
        $now = new DateTimeImmutable('now', $zone);
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value || $date <= $now || $date > $now->modify('+365 days')) {
            throw new DomainException('Choose a future access expiry within 365 days, in West Africa Time.', 422);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
    }

    public static function reference(string $value): bool
    {
        return preg_match('/\ALB-[A-F0-9]{32}\z/', $value) === 1;
    }

    private static function date(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Africa/Lagos')) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Enter a valid rights expiry date or leave it blank.', 422);
        }
        return $value;
    }
}

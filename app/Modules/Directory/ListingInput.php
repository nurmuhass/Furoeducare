<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Directory;

use Furoeducare\Core\Request;
use Furoeducare\Support\NigerianStates;
use InvalidArgumentException;

final class ListingInput
{
    private const PUBLIC_TEXT = [
        'title' => ['Professional title', 120],
        'bio' => ['Biography', 600],
        'qualification' => ['Qualification summary', 160],
    ];

    public static function draft(Request $request): array
    {
        $draft = [];
        foreach (['title', 'bio', 'qualification', 'experience', 'state_code', 'city', 'subjects', 'levels', 'approval_basis', 'professional_name'] as $key) {
            $draft[$key] = $request->form($key);
        }
        foreach (['authorised', 'name_authorised', 'photo_authorised'] as $key) {
            $draft[$key] = $request->form($key) === '1';
        }
        return $draft + ['availability' => $request->formArray('availability')];
    }

    public static function validate(array $draft, array $teacher): array
    {
        if (!$draft['authorised']) {
            throw new InvalidArgumentException('Confirm that the teacher authorised this public listing.');
        }
        $approval = self::text($draft['approval_basis'], 'Private authorisation record', 1000, multiline: true);
        $experience = filter_var($draft['experience'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 70]]);
        if ($experience === false) {
            throw new InvalidArgumentException('Enter teaching experience as a whole number of years from 0 to 70.');
        }
        if (!isset(NigerianStates::NAMES[$draft['state_code']])) {
            throw new InvalidArgumentException('Choose a Nigerian state or the Federal Capital Territory.');
        }

        $fields = [];
        foreach (self::PUBLIC_TEXT as $key => [$label, $max]) {
            $fields[$key] = self::publicText($draft[$key], $label, $max, $teacher, multiline: $key === 'bio');
        }
        $city = self::publicText($draft['city'], 'City or town', 80, $teacher, required: false);
        if ($city !== '' && (preg_match("/\\A[\\p{L}\\p{M}][\\p{L}\\p{M}.'’ -]*\\z/u", $city) !== 1
            || preg_match('/\b(?:avenue|street|road|lane|crescent|close|boulevard|drive|estate)\b/i', $city) === 1)) {
            throw new InvalidArgumentException('Enter a general city or town, such as Ikeja.');
        }

        $name = null;
        if ($draft['name_authorised']) {
            $name = self::publicText($draft['professional_name'], 'Public professional name', 100);
            if (preg_match("/\\A[\\p{L}\\p{M}][\\p{L}\\p{M}.'’ -]*\\z/u", $name) !== 1) {
                throw new InvalidArgumentException('Enter the authorised professional name using letters, spaces, and name punctuation.');
            }
        }

        return $fields + [
            'experience' => $experience,
            'state_code' => $draft['state_code'],
            'city' => $city,
            'availability_mask' => self::availabilityMask($draft['availability']),
            'subjects' => self::tags($draft['subjects'], 'Subjects', $teacher),
            'levels' => self::tags($draft['levels'], 'Teaching levels', $teacher),
            'professional_name' => $name,
            'photo_authorised' => $draft['photo_authorised'],
            'approval_basis' => $approval,
        ];
    }

    private static function availabilityMask(array $values): int
    {
        $mask = 0;
        foreach ($values as $value) {
            if (!is_string($value) || !isset(DirectoryFilters::MODES[$value]) || ($mask & DirectoryFilters::MODES[$value]) !== 0) {
                throw new InvalidArgumentException('Choose valid teaching opportunities.');
            }
            $mask |= DirectoryFilters::MODES[$value];
        }
        if ($mask === 0) {
            throw new InvalidArgumentException('Choose at least one teaching opportunity.');
        }
        return $mask;
    }

    private static function tags(string $raw, string $label, array $teacher): array
    {
        $raw = self::publicText($raw, $label, 800, $teacher, multiline: true);
        $items = [];
        foreach (preg_split('/[,\r\n]+/', $raw) as $value) {
            $item = self::text($value, $label, 60, required: false);
            if ($item !== '') {
                $items[strtolower($item)] ??= $item;
            }
        }
        if ($items === [] || count($items) > 12) {
            throw new InvalidArgumentException($label . ' must contain 1 to 12 entries, separated by commas or new lines.');
        }
        return array_values($items);
    }

    private static function publicText(string $value, string $label, int $max, array $teacher = [], bool $required = true, bool $multiline = false): string
    {
        $value = self::text($value, $label, $max, $required, $multiline);
        $contact = <<<'REGEX'
            ~ @ | \b(?:[a-z][a-z0-9+.-]*://|www\.)
                | (?<![\p{L}\p{N}.])(?:
                    [\p{L}\p{N}][\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.(?!ed\b)[a-z]{2,24}
                    | [a-z0-9]\.(?:com|net|org|ng|me|io|co|test|edu)
                )\b ~iux
            REGEX;
        $phone = '/\p{Nd}{7,}|(?:\p{Nd}[\s\p{Z}().\p{Pd}]*){8}\p{Nd}/u';
        $address = <<<'REGEX'
            ~ \b(?:p\.?\s*o\.?\s*box|post\s+office\s+box|p\.?\s*m\.?\s*b\.?)\b
                | \b(?:flat|suite|apt\.?|apartment|(?:unit|house)\s+no\.?)\s+\p{Nd}
                | \b\p{Nd}+[a-z]?[\s,]+(?:[\p{L}\p{M}\p{N}.-]+\s+){0,5}
                    (?:street|st\.?|road|rd\.?|avenue|ave\.?|lane|crescent|close|boulevard|drive|way)\b ~iux
            REGEX;
        if (preg_match($contact, $value) === 1 || preg_match($phone, $value) === 1 || preg_match($address, $value) === 1) {
            throw new InvalidArgumentException($label . ' must exclude contact details, links and street addresses.');
        }
        if ($teacher !== []) {
            $normalise = static fn (string $text): string => trim(preg_replace('/[\s\p{Z},]+/u', ' ', $text) ?? '');
            $first = preg_quote($normalise($teacher['first_name']), '/');
            $last = preg_quote($normalise($teacher['last_name']), '/');
            if ($first !== '' && $last !== '' && preg_match('/(?<![\p{L}\p{M}])(?:' . $first . ' ' . $last
                . '|' . $last . ' ' . $first . ')(?![\p{L}\p{M}])/iu', $normalise($value)) === 1) {
                throw new InvalidArgumentException($label . ' must exclude the teacher’s private name. Use the authorised name field if applicable.');
            }
        }
        return $value;
    }

    private static function text(string $value, string $label, int $max, bool $required = true, bool $multiline = false): string
    {
        $value = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
        if ($value === null || ($required && $value === '') || preg_match('/\A.{0,' . $max . '}\z/us', $value) !== 1
            || preg_match('/\p{C}/u', $multiline ? str_replace(["\r", "\n", "\t"], '', $value) : $value) !== 0) {
            throw new InvalidArgumentException($label . ' must ' . ($required ? 'contain 1 to ' : 'use up to ') . $max . ' characters.');
        }
        return $value;
    }
}

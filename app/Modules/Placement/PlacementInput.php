<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Placement;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

final class PlacementInput
{
    public const HOME_CHECKS = ['identity', 'references', 'home_plan', 'emergency_plan'];

    public static function text(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/\A.{1,1000}\z/us', $value) !== 1 || preg_match('/[^\P{Cc}\r\n\t]/u', $value) !== 0) {
            throw new DomainException("Enter $label of up to 1,000 characters.", 422);
        }
        return $value;
    }

    public static function discussion(string $value): string
    {
        $local = new DateTimeZone('Africa/Lagos');
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $local);
        if ($time === false || $time->format('Y-m-d\TH:i') !== $value || $time <= new DateTimeImmutable('now', $local)) {
            throw new DomainException('Choose a future interview or discussion time in West Africa Time.', 422);
        }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function startDate(string $value): string
    {
        $zone = new DateTimeZone('Africa/Lagos');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($date === false || $date->format('Y-m-d') !== $value || $date < new DateTimeImmutable('today', $zone)) {
            throw new DomainException('Choose a start date of today or later.', 422);
        }
        return $value;
    }

    public static function safeguarding(array $input): string
    {
        foreach (self::HOME_CHECKS as $check) {
            if (($input[$check] ?? '') !== '1') {
                throw new DomainException('Complete all home tutoring safeguarding checks.', 422);
            }
        }
        $reference = trim($input['safeguarding_reference'] ?? '');
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{5,119}\z/', $reference) !== 1) {
            throw new DomainException('Enter a restricted safeguarding record reference of 6–120 characters.', 422);
        }
        return $reference;
    }
}

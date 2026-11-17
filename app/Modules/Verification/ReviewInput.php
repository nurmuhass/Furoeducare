<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Verification;

use Furoeducare\Core\Request;
use InvalidArgumentException;

final class ReviewInput
{
    public const TEACHER_CHECKS = [
        'identity' => 'Identity and account information confirmed',
        'qualifications' => 'Professional qualifications confirmed',
        'professional_history' => 'Work history or declared lack of experience reviewed',
    ];

    public const SCHOOL_CHECKS = [
        'institution' => 'Institution identity and address confirmed',
        'representative' => 'Representative and school contact details confirmed',
    ];

    public static function reason(Request $request): string
    {
        $reason = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $request->form('reason'));
        if ($reason === null || $reason === '' || preg_match('/\A.{1,1500}\z/us', $reason) !== 1
            || preg_match('/[^\P{Cc}\r\n\t]/u', $reason) === 1) {
            throw new InvalidArgumentException('Enter decision notes of up to 1,500 characters.');
        }
        return $reason;
    }

    /** @return array<string, bool> */
    public static function checks(Request $request, array $allowed): array
    {
        $result = array_fill_keys(array_keys($allowed), false);
        foreach ($request->formArray('checks') as $key) {
            if (!is_string($key) || !array_key_exists($key, $result) || $result[$key]) {
                throw new InvalidArgumentException('Choose valid verification checks.');
            }
            $result[$key] = true;
        }
        return $result;
    }

    public static function passed(array $checks, array $required): bool
    {
        foreach ($required as $key => $label) {
            if (($checks[$key] ?? false) !== true) {
                return false;
            }
        }
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Messaging;

use DomainException;

final class MessageInput
{
    public const CONTEXTS = ['support', 'recruitment', 'vacancy', 'tutoring', 'candidate_information', 'placement', 'training'];

    public static function body(string $value): string
    {
        return self::text($value, 4000, 'a message');
    }

    public static function subject(string $value): string
    {
        $value = self::text($value, 160, 'a subject');
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new DomainException('Use a single line for the subject.', 422);
        }
        return $value;
    }

    public static function token(string $value): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
            throw new DomainException('Reopen the form before sending your message.', 422);
        }
        return $value;
    }

    private static function text(string $value, int $limit, string $label): string
    {
        $value = trim($value);
        if (preg_match('/\A.{1,' . $limit . '}\z/us', $value) !== 1 || preg_match('/[^\P{Cc}\r\n\t]/u', $value) !== 0) {
            throw new DomainException("Enter $label of up to $limit characters and remove invalid characters.", 422);
        }
        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Notification;

use DomainException;

final class NotificationInput
{
    public static function path(mixed $value): string
    {
        if (!is_string($value) || preg_match('~\A(?:/dashboard(?:/(?:teacher|school|parent|admin)(?:/[a-z][a-z-]*)*)?|/messages|/candidate-requests|/placements|/notifications|/settings/account)(?:\?id=[1-9][0-9]{0,17})?\z~D', $value) !== 1) {
            throw new DomainException('Choose a valid internal notification destination.', 422);
        }
        return $value;
    }

    public static function text(mixed $value, string $label, int $maximum): string
    {
        if (!is_string($value) || preg_match('/\A.{1,' . $maximum . '}\z/us', $value) !== 1
            || preg_match('/[^\P{Cc}\r\n\t]/u', $value) !== 0) {
            throw new DomainException('Enter valid ' . $label . ' using up to ' . $maximum . ' characters.', 422);
        }
        return $value;
    }
}

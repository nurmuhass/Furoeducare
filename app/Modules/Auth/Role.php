<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

enum Role: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case School = 'school';
    case Parent = 'parent';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Teacher => 'Teacher / NYSC Member',
            self::School => 'School / Educational Institution',
            self::Parent => 'Parent / Guardian',
        };
    }

    public function dashboard(): string
    {
        return '/dashboard/' . $this->value;
    }

    /** @return list<self> */
    public static function publicRoles(): array
    {
        return [self::Teacher, self::School, self::Parent];
    }
}

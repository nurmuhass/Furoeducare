<?php

declare(strict_types=1);

namespace Furoeducare\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $publicMessage)
    {
        parent::__construct($publicMessage);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Teacher\Cv;

use RuntimeException;

final class ExtractionFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

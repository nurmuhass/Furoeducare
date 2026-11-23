<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use RuntimeException;

final class TransportException extends RuntimeException
{
    // Only safe, fixed classifications belong in this exception; SMTP replies can contain PII.
    public function __construct(
        public readonly string $codeName,
        public readonly bool $retryable,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct($codeName);
    }
}

<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

interface MailTransport
{
    /** accepted means the relay acknowledged the message, not delivery to an inbox. */
    public function send(array $row, array $rendered): array;
}

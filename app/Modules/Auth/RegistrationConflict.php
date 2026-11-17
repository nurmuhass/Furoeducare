<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use DomainException;

abstract class RegistrationConflict extends DomainException
{
}

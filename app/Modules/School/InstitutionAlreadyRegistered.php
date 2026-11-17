<?php

declare(strict_types=1);

namespace Furoeducare\Modules\School;

use Furoeducare\Modules\Auth\RegistrationConflict;

final class InstitutionAlreadyRegistered extends RegistrationConflict
{
    public function __construct()
    {
        parent::__construct('This institution already has an account. Contact Furoeducare for help accessing it.');
    }
}

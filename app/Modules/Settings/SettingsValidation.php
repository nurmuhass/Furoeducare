<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Settings;

use DomainException;

final class SettingsValidation extends DomainException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Review the highlighted settings fields.', 422);
    }
}

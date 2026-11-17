<?php

declare(strict_types=1);

function parentFields(string $email, string $password, array $overrides = []): array
{
    return $overrides + [
        'first_name' => 'Adé', 'last_name' => 'Fixture', 'email' => $email, 'phone' => '080 1234 5678',
        'state_code' => 'LA', 'city' => 'Ikeja', 'password' => $password, 'password_confirmation' => $password,
        'terms' => '1', 'privacy' => '1',
    ];
}

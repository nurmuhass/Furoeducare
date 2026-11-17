<?php

declare(strict_types=1);

function schoolFields(string $email, string $password, array $overrides = []): array
{
    return $overrides + [
        'school_name' => 'Green Valley School', 'category' => 'combined', 'state_code' => 'OG',
        'city' => 'Abeokuta', 'address' => '12 Fixture Road', 'contact_email' => 'office@example.test',
        'contact_phone' => '080 1234 5678', 'representative_name' => 'Fixture Representative',
        'representative_position' => 'Head of school', 'email' => $email, 'password' => $password,
        'password_confirmation' => $password, 'terms' => '1', 'privacy' => '1',
    ];
}

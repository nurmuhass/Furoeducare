<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Parent;

use Closure;
use Furoeducare\Modules\Auth\LegalNotice;

final class ParentRepository
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function profile(int $owner): ?array
    {
        $query = ($this->database)()->prepare('SELECT * FROM parent_profiles WHERE user_id = ?');
        $query->execute([$owner]);
        return $query->fetch() ?: null;
    }

    /** Also used within the shared email verification transaction. */
    public function create(int $owner, array $data): void
    {
        ($this->database)()->prepare(
            'INSERT INTO parent_profiles (user_id, first_name, last_name, phone, state_code, city, terms_version, privacy_version, accepted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE user_id = user_id'
        )->execute([$owner, $data['first_name'], $data['last_name'], $data['phone'], $data['state_code'], $data['city'],
            LegalNotice::TERMS, LegalNotice::PRIVACY]);
    }

    public function updateProfile(int $owner, array $data): void
    {
        ($this->database)()->prepare(
            'UPDATE parent_profiles SET first_name = ?, last_name = ?, phone = ?, state_code = ?, city = ? WHERE user_id = ?'
        )->execute([$data['first_name'], $data['last_name'], $data['phone'], $data['state_code'], $data['city'], $owner]);
    }
}

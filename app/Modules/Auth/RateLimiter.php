<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Auth;

use Closure;
use Furoeducare\Core\Database;
use PDO;

final class RateLimiter
{
    public function __construct(private readonly Closure $database)
    {
    }

    public function allow(string $scope, string $identity, int $limit, int $window): bool
    {
        $key = $this->key($scope, $identity);
        return Database::transaction(($this->database)(), static function (PDO $db) use ($key, $limit, $window): bool {
            $db->prepare(
                'INSERT INTO auth_rate_limits (bucket, attempts, window_started_at) VALUES (?, 0, CURRENT_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE bucket = auth_rate_limits.bucket'
            )->execute([$key]);
            $lookup = $db->prepare(
                'SELECT attempts, TIMESTAMPDIFF(SECOND, window_started_at, CURRENT_TIMESTAMP(6)) AS age
                 FROM auth_rate_limits WHERE bucket = ? FOR UPDATE'
            );
            $lookup->execute([$key]);
            $row = $lookup->fetch();

            if ((int) $row['age'] >= $window) {
                $db->prepare(
                    'UPDATE auth_rate_limits SET attempts = 1, window_started_at = CURRENT_TIMESTAMP(6) WHERE bucket = ?'
                )->execute([$key]);
                return true;
            }
            if ((int) $row['attempts'] >= $limit) {
                return false;
            }

            $db->prepare('UPDATE auth_rate_limits SET attempts = attempts + 1 WHERE bucket = ?')->execute([$key]);
            return true;
        });
    }

    public function clear(string $scope, string $identity): void
    {
        ($this->database)()->prepare('DELETE FROM auth_rate_limits WHERE bucket = ?')
            ->execute([$this->key($scope, $identity)]);
    }

    public static function network(string $address): string
    {
        $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);
        if ($binary === false) {
            return 'unknown';
        }
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return bin2hex(substr($binary, 12));
        }
        return bin2hex(substr($binary, 0, 8));
    }

    private function key(string $scope, string $identity): string
    {
        return hash('sha256', $scope . "\0" . $identity, true);
    }
}

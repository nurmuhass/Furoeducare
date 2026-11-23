<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use InvalidArgumentException;

final class MailCrypto
{
    public static function encrypt(#[\SensitiveParameter] string $value, #[\SensitiveParameter] string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, self::key($key)));
    }

    public static function decrypt(#[\SensitiveParameter] string $value, #[\SensitiveParameter] string $key): string
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new InvalidArgumentException('Invalid encrypted mail payload.');
        }
        $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key($key));
        if ($plain === false) {
            throw new InvalidArgumentException('Cannot decrypt queued mail with the configured key.');
        }
        return $plain;
    }

    public static function key(#[\SensitiveParameter] string $value): string
    {
        $key = base64_decode($value, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('MAIL_ENCRYPTION_KEY must encode exactly 32 bytes.');
        }
        return $key;
    }
}

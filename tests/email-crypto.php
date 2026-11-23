<?php

declare(strict_types=1);

use Furoeducare\Modules\Email\MailCrypto;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

$key = base64_encode(random_bytes(32));
$token = bin2hex(random_bytes(32));
$encrypted = MailCrypto::encrypt($token, $key);
expect(!str_contains($encrypted, $token), 'An outbox secret must not expose its token.');
expect(MailCrypto::decrypt($encrypted, $key) === $token, 'The worker recovers the queued token.');
foreach (['', base64_encode(random_bytes(32))] as $wrong) {
    try {
        MailCrypto::decrypt($encrypted, $wrong);
        throw new RuntimeException('A wrong outbox key was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "PASS outbox secret encryption and wrong-key rejection.\n";

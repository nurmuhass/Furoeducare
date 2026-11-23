<?php

declare(strict_types=1);

namespace Furoeducare\Modules\Email;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Outbox
{
    /** Call on the business transaction's PDO connection to commit the event and mail together. */
    public static function enqueue(
        PDO $db, string $eventKey, int $user, string $template, array $payload = [],
        ?string $email = null, #[\SensitiveParameter] ?string $secret = null,
        ?string $tokenHash = null, ?string $expiresAt = null,
    ): int {
        if ($eventKey === '' || strlen($eventKey) > 240 || !in_array($template, EmailTemplates::names(), true)) {
            throw new InvalidArgumentException('A valid email event and template are required.');
        }
        if (array_diff(array_keys($payload), ['path', 'decision', 'flow', 'ref']) !== []) {
            throw new InvalidArgumentException('Only approved notification fields may be queued.');
        }
        foreach ($payload as $value) {
            if ($value !== null && (!is_string($value) || strlen($value) > 1000)) {
                throw new InvalidArgumentException('Invalid email payload value.');
            }
        }
        $lookup = $db->prepare('SELECT email FROM users WHERE id = ?');
        $lookup->execute([$user]);
        $address = $lookup->fetchColumn();
        if ($template === 'email_change') {
            $change = $db->prepare('SELECT c.new_email FROM account_email_changes c JOIN users u ON u.id = c.user_id
                WHERE c.user_id = ? AND c.token_hash = ? AND c.old_email COLLATE utf8mb4_0900_as_ci = u.email AND c.auth_version = u.auth_version
                AND c.consumed_at IS NULL AND c.cancelled_at IS NULL AND c.expires_at > UTC_TIMESTAMP(6)
                AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL');
            $change->execute([$user, $tokenHash]);
            $address = $change->fetchColumn();
        }
        if (!is_string($address) || ($email !== null && $email !== $address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email recipient must be the current account address.');
        }
        $essential = in_array($template, ['account_verification', 'password_reset', 'email_change'], true);
        if ($essential && ($secret === null || $tokenHash === null || strlen($tokenHash) !== 32 || $expiresAt === null)) {
            throw new InvalidArgumentException('Authentication mail requires an expiring encrypted token.');
        }
        if (!$essential && ($secret !== null || $tokenHash !== null || $expiresAt !== null)) {
            throw new InvalidArgumentException('Secret tokens are limited to approved authentication email flows.');
        }
        ksort($payload);
        $json = json_encode((object) $payload, JSON_THROW_ON_ERROR);
        $key = hash('sha256', $eventKey . "\0" . $user);
        $hash = hash('sha256', $template . "\0" . $address . "\0" . $json . "\0" . $tokenHash . "\0" . $expiresAt, true);
        $db->prepare('INSERT INTO email_outbox (user_id, recipient_email, template, payload, secret_payload, token_hash, expires_at,
            payload_hash, idempotency_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(email_outbox.id)')
            ->execute([$user, $address, $template, $json, $secret, $tokenHash, $expiresAt, $hash, $key]);
        $query = $db->prepare('SELECT id, payload_hash FROM email_outbox WHERE idempotency_key = ?');
        $query->execute([$key]);
        $row = $query->fetch();
        if ($row === false || !hash_equals($row['payload_hash'], $hash)) {
            throw new RuntimeException('An email event key was reused with different content.');
        }
        return (int) $row['id'];
    }
}

<?php
declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Settings\AppearancePreferences;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

try {
    expect(class_exists(AppearancePreferences::class), 'Account appearance preferences are available.');
    $config = Config::load(dirname(__DIR__));
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an already migrated disposable loopback furoeducare_test_<suffix> schema.');
    $db = Database::connect($config);
    $preferences = new AppearancePreferences(static fn (): PDO => $db);
    $ids = [];
    foreach (['owner', 'other', 'unverified', 'suspended'] as $kind) {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at, suspended_at) VALUES (?, ?, ?, ?)')
            ->execute(['appearance-' . $kind . '-' . bin2hex(random_bytes(6)) . '@example.test', '!fixture',
                $kind === 'unverified' ? null : gmdate('Y-m-d H:i:s'), $kind === 'suspended' ? gmdate('Y-m-d H:i:s') : null]);
        $ids[$kind] = (int) $db->lastInsertId();
    }
    expect($preferences->get($ids['owner']) === 'system', 'New accounts follow the system.');
    foreach (['light', 'dark', 'system'] as $choice) {
        $preferences->save($ids['owner'], $choice);
        expect($preferences->get($ids['owner']) === $choice, 'Each appearance persists.');
        expect($preferences->get($ids['other']) === 'system', 'Appearance remains account specific.');
    }
    foreach (['LIGHT', '', 'auto', '<script>'] as $invalid) {
        try { $preferences->save($ids['owner'], $invalid); throw new RuntimeException('Invalid appearance accepted.'); }
        catch (DomainException $error) { expect($error->getCode() === 422, 'Invalid appearance reports validation failure.'); }
    }
    foreach ([$ids['unverified'], $ids['suspended'], PHP_INT_MAX] as $denied) {
        try { $preferences->save($denied, 'dark'); throw new RuntimeException('Inactive account accepted.'); }
        catch (DomainException $error) { expect($error->getCode() === 403, 'Only active verified accounts can save.'); }
    }
    $db->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?')->execute([$ids['other']]);
    try { $preferences->save($ids['other'], 'dark', 1); throw new RuntimeException('Revoked preference version accepted.'); }
    catch (DomainException $error) { expect($error->getCode() === 403, 'An in-flight preference update rechecks the session version.'); }
    $query = $db->prepare("SELECT context FROM audit_logs WHERE actor_user_id = ? AND action = 'account.appearance_updated' ORDER BY id");
    $query->execute([$ids['owner']]);
    expect(array_map(static fn (array $row): string => json_decode($row['context'], true, flags: JSON_THROW_ON_ERROR)['appearance'], $query->fetchAll()) === ['light', 'dark', 'system'],
        'Successful saves audit the selected value; rejected writes produce no audit.');
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$ids['owner']]);
    $query = $db->prepare('SELECT COUNT(*) FROM account_preferences WHERE user_id = ?');
    $query->execute([$ids['owner']]);
    expect((int) $query->fetchColumn() === 0, 'Deleting the owner cascades appearance preferences.');
    echo "PASS appearance defaults, persistence, isolation, validation, authorization, audit and cascade.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Modules\Library\LibraryFiles;
use Furoeducare\Modules\Library\LibraryRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/library-fixtures.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-library-roles-' . bin2hex(random_bytes(6));
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_library_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an already migrated isolated loopback library test schema.');
    $db = Database::connect($config);
    expect(array_values(array_unique((new MigrationRunner($db, $root . '/database/migrations'))->status())) === ['applied'],
        'The supplemental suite requires the current complete schema.');
    mkdir($work, 0700);
    $password = 'Supplemental library fixture 746!';
    $suffix = bin2hex(random_bytes(6));
    $accounts = [];
    foreach (['admin', 'teacher', 'school'] as $role) {
        $email = $role . '-' . $suffix . '@example.test';
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        if ($role === 'admin') {
            grantTestStaffRole($db, $id);
        }
        $accounts[$role] = ['id' => $id, 'email' => $email];
    }
    $db->prepare("INSERT INTO school_onboarding (user_id, school_name, review_status) VALUES (?, 'Pending library fixture school', 'pending')")
        ->execute([$accounts['school']['id']]);
    serve($root, ['APP_ENV' => 'development', 'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail',
        'APP_LOG_FILE' => $work . '/app.log', 'LIBRARY_STORAGE_DIR' => $work . '/library'], $work . '/server.log',
        function (string $base) use ($db, $accounts, $password, $work, $root): void {
        $browsers = [];
        foreach ($accounts as $role => $account) {
            $browser = $browsers[$role] = new AuthBrowser($base);
            expect($browser->submit('/login', ['email' => $account['email'], 'password' => $password])[0] === 303, 'Supplemental account signs in.');
        }
        $admin = $browsers['admin'];
        $route = '/dashboard/admin/library';
        $image = imagecreatetruecolor(20, 30);
        ob_start();
        imagepng($image);
        $cover = (string) ob_get_clean();
        expect(libraryUpload($base, $admin, $route . '?new=1', libraryFields(['title' => 'Supplemental role resource']),
            ['material' => ['roles.pdf', 'application/pdf', sampleCvPdf(['Supplemental library role fixture'])],
                'cover' => ['roles.png', 'image/png', $cover]])[0] === 303,
            'Create a supplemental resource through the real upload endpoint.');
        $id = (int) $db->query('SELECT MAX(id) FROM library_resources')->fetchColumn();
        $row = static function () use ($db, $id): array {
            $query = $db->prepare('SELECT * FROM library_resources WHERE id = ?');
            $query->execute([$id]);
            return $query->fetch(PDO::FETCH_ASSOC);
        };
        $page = $route . '?id=' . $id;
        $action = static fn (string $action, array $fields = []): array => $admin->submit($route,
            $fields + ['action' => $action, 'id' => $id, 'revision' => $row()['revision']], $page);
        expect($action('publish')[0] === 303, 'Publish the supplemental resource.');
        $ref = $row()['reference'];
        $download = '/digital-library/download?ref=' . $ref;
        foreach (['teacher', 'school'] as $role) {
            expect($browsers[$role]->send($download)[0] === 200, 'Teachers and pending schools can download free resources.');
            expect(str_contains($browsers[$role]->send('/digital-library/history')[2], $ref), 'Each role can read its own library history.');
        }
        expect(libraryUpload($base, $admin, $page, libraryFields(['id' => $id, 'revision' => $row()['revision'],
            'title' => 'Supplemental role resource', 'access' => 'restricted']))[0] === 303
            && $action('publish')[0] === 303, 'Publish the same resource with restricted access.');
        foreach (['teacher', 'school'] as $role) {
            expect($browsers[$role]->send($download)[0] === 403, 'A registered role and prior free download cannot bypass restricted access.');
        }
        expect($action('grant', ['recipient_id' => $accounts['teacher']['id'], 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'Teacher permission'])[0] === 303,
            'Staff can explicitly authorize a teacher.');
        expect($browsers['teacher']->send($download)[0] === 200 && $browsers['school']->send($download)[0] === 403,
            'Teacher permission does not authorize a pending school account.');
        expect($action('grant', ['recipient_id' => $accounts['school']['id'], 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'Pending school permission'])[0] === 303
            && $browsers['school']->send($download)[0] === 200 && $browsers['teacher']->send($download)[0] === 200,
            'An explicit library grant permits the pending school without invalidating the teacher grant.');
        echo "PASS teacher and pending-school free/restricted access and account history.\n";

        $db->beginTransaction();
        $grant = $db->prepare("INSERT INTO library_access_grants
            (resource_id, user_id, resource_revision, expires_at, granted_by, reason, revoked_at)
            VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL 1 DAY), ?, ?, CURRENT_TIMESTAMP(6))");
        for ($n = 1; $n <= 51; $n++) {
            $grant->execute([$id, $accounts['teacher']['id'], $row()['revision'], $accounts['admin']['id'], 'Historical pagination fixture ' . $n]);
        }
        $db->commit();
        $repository = new LibraryRepository(static fn (): PDO => $db, new LibraryFiles($work . '/library', $root . '/public'));
        $first = $repository->staffResource($id);
        expect(count($first['grants']) === 50 && $first['grants_next'] !== null, 'The first staff grant page is bounded to fifty records.');
        $second = $repository->staffResource($id, $first['grants_next']);
        expect(count($second['grants']) === 3 && $second['grants_next'] === null
            && array_intersect(array_column($first['grants'], 'id'), array_column($second['grants'], 'id')) === [],
            'All fifty-three grants remain reachable on disjoint cursor pages.');
        $firstHtml = $admin->send($page)[2];
        expect(substr_count($firstHtml, 'Account #') === 50 && preg_match('/href="([^"]+)"[^>]*>Older grants<\//', $firstHtml, $older) === 1,
            'The staff form renders a bounded grant page with a continuation link.');
        $olderUrl = html_entity_decode($older[1], ENT_QUOTES);
        expect(str_contains($olderUrl, 'id=' . $id) && str_contains($olderUrl, 'grants_before=')
            && substr_count($admin->send($olderUrl)[2], 'Account #') === 3, 'Grant pagination preserves the resource and reaches the remaining records.');
        echo "PASS bounded staff grant pagination and complete disjoint continuation.\n";

        $teacher = $browsers['teacher'];
        expect($teacher->submit('/digital-library/save', ['action' => 'save', 'ref' => $ref], '/digital-library/resource?ref=' . $ref)[0] === 303,
            'Save the supplemental resource for removal checks.');
        $actor = $accounts['admin']['id'];
        $db->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$actor]);
        redirectIs($admin->send($route), '/login');
        $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$actor]);
        grantTestStaffRole($db, $actor);
        expect($admin->submit('/login', ['email' => $accounts['admin']['email'], 'password' => $password])[0] === 303,
            'Restoring a role still requires a new login after roleless session invalidation.');
        expect($action('archive')[0] === 303 && $teacher->send('/digital-library/resource?ref=' . $ref)[0] === 404,
            'Archive immediately withdraws the public resource.');
        $files = $row();
        expect($action('remove')[0] === 303 && $row()['status'] === 'removed', 'Remove leaves a persistent tombstone.');
        expect($admin->send($page)[0] === 200, 'Staff can inspect the removed read-only tombstone.');
        foreach (['material_key', 'cover_key'] as $key) {
            expect(!is_file($work . '/library/' . $files[$key]), 'Committed removal physically deletes the original and cover.');
        }
        $saved = $teacher->send('/digital-library/saved')[2];
        $history = $teacher->send('/digital-library/history')[2];
        expect(str_contains($saved, $ref) && str_contains($saved, 'Resource removed') && str_contains($saved, 'Remove saved resource')
            && !str_contains($saved, 'Supplemental role resource') && str_contains($history, $ref)
            && !str_contains($history, 'Supplemental role resource'), 'Saved resources and history retain safe removed references and owner controls.');
        foreach (['resource', 'cover', 'download'] as $endpoint) {
            expect($teacher->send('/digital-library/' . $endpoint . '?ref=' . $ref)[0] === 404, 'Removed links never recover metadata or files.');
        }
        echo "PASS live role revocation, reauthentication, archive and removed tombstone/file cleanup.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) fwrite(STDERR, implode('', array_slice(file($log), -8)));
    }
    exit(1);
}

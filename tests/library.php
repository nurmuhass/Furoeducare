<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/library-fixtures.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-library-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_library_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback schema named furoeducare_test_library_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty library test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seeds = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seeds->run(...));
    mkdir($work, 0700);
    $password = 'Library fixture password 891!';
    $account = static function (string $name, string $role) use ($db, $password): int {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        if ($role === 'admin') {
            grantTestStaffRole($db, $id);
        }
        return $id;
    };
    $staff = $account('librarian', 'admin');
    $reader = $account('reader', 'parent');
    $other = $account('other-reader', 'parent');
    serve($root, ['APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail',
        'APP_LOG_FILE' => $work . '/app.log', 'LIBRARY_STORAGE_DIR' => $work . '/library'],
        $work . '/server.log', function (string $base) use ($db, $password, $staff, $reader, $other, $work): void {
        $catalogue = '/digital-library';
        $adminRoute = '/dashboard/admin/library';
        $admin = new AuthBrowser($base);
        $guest = new AuthBrowser($base);
        $member = new AuthBrowser($base);
        $stranger = new AuthBrowser($base);
        $login = static function (AuthBrowser $browser, string $name) use ($password): void {
            expect($browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password])[0] === 303, 'Fixture user signs in.');
        };
        $login($admin, 'librarian');
        $login($member, 'reader');
        $login($stranger, 'other-reader');
        expect($guest->send($catalogue)[0] === 200, 'Anonymous visitors can open the catalogue.');
        redirectIs($guest->send($adminRoute), '/login');
        expect($member->send($adminRoute)[0] === 403, 'Registered readers cannot administer the library.');
        foreach (['saved', 'history'] as $path) {
            redirectIs($guest->send("$catalogue/$path"), '/login');
        }
        $new = $adminRoute . '?new=1';
        $pdf = sampleCvPdf(['Library algebra workbook', 'Solve an equation.']);
        $material = ['material' => ['workbook.pdf', 'application/pdf', $pdf]];
        expect(libraryUpload($base, $admin, $new, libraryFields(), $material, false)[0] === 403, 'Uploads require CSRF.');
        expect(libraryUpload($base, $admin, $new, libraryFields(), [])[0] === 422, 'A new resource requires a material.');
        expect(libraryUpload($base, $admin, $new, libraryFields(), ['material' => ['fake.pdf', 'application/pdf', '<script>unsafe</script>']])[0] === 422, 'A filename and claimed MIME cannot disguise an invalid PDF.');
        expect(libraryUpload($base, $admin, $new, libraryFields(), $material + ['cover' => ['bad.png', 'image/png', 'not an image']])[0] === 422, 'Cover content is validated.');
        expect(libraryUpload($base, $admin, $new, libraryFields(), ['material' => ['book.exe', 'application/pdf', $pdf]])[0] === 422, 'Only approved material extensions are accepted.');
        expect(libraryUpload($base, $admin, $new, libraryFields(['rights_expires_on' => 'not-a-date']), $material)[0] === 422, 'Rights expiry must be a valid ISO date.');
        expect((int) $db->query('SELECT COUNT(*) FROM library_resources')->fetchColumn() === 0, 'Rejected uploads do not create partial resources.');
        $image = imagecreatetruecolor(32, 48);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        expect(libraryUpload($base, $admin, $new, libraryFields(['rights_confirmed' => '']), $material + ['cover' => ['cover.png', 'image/png', $png]])[0] === 303, 'Staff can create a private unconfirmed PDF draft with cover.');
        $row = static function (int $id) use ($db): array {
            $query = $db->prepare('SELECT * FROM library_resources WHERE id = ?');
            $query->execute([$id]);
            return $query->fetch(PDO::FETCH_ASSOC);
        };
        $id = (int) $db->query('SELECT MAX(id) FROM library_resources')->fetchColumn();
        $resource = $row($id);
        $ref = $resource['reference'];
        expect(preg_match('/\ALB-[A-F0-9]{32}\z/', $ref) === 1, 'Resource references are opaque.');
        $page = $adminRoute . '?id=' . $id;
        $detail = "$catalogue/resource?ref=$ref";
        $download = "$catalogue/download?ref=$ref";
        $cover = "$catalogue/cover?ref=$ref";
        $action = static function (string $operation, array $fields = []) use ($admin, $adminRoute, $page, $row, $id): array {
            return $admin->submit($adminRoute, $fields + ['action' => $operation, 'id' => $id, 'revision' => $row($id)['revision']], $page);
        };
        foreach ([$detail, $download, $cover] as $route) {
            expect($guest->send($route)[0] === 404, 'Draft material and metadata remain private.');
        }
        $count = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM library_downloads')->fetchColumn();
        expect($admin->send($adminRoute . '/file?id=' . $id)[0] === 200 && $count() === 0, 'Admin preview does not count as a public download.');
        expect($member->send($adminRoute . '/file?id=' . $id)[0] === 403, 'Readers cannot use the staff preview route.');
        expect($action('publish')[0] === 422, 'An unconfirmed file cannot be published.');
        expect(libraryUpload($base, $admin, $page, libraryFields(['id' => $id, 'revision' => $row($id)['revision']]))[0] === 303,
            'Staff can explicitly confirm the current file and rights.');
        expect($action('publish')[0] === 303, 'Staff can publish the confirmed resource.');
        [$status, $headers, $body] = $guest->send($detail);
        expect($status === 200 && str_contains($body, 'Library algebra workbook'), 'Guests can read published metadata.');
        foreach (['Private permission reference', $resource['material_key'], (string) $staff . '@example.test'] as $secret) {
            expect(!str_contains($body, $secret), 'Public metadata excludes private rights and storage details.');
        }
        expect($guest->send($cover)[0] === 200, 'Published cover is served by opaque reference.');
        expect($guest->send($download, 'HEAD')[0] === 200 && $count() === 0, 'HEAD authorizes without recording a download.');
        [$status, $headers, $bytes] = $guest->send($download);
        expect($status === 200 && $bytes === $pdf && str_contains($headers['content-disposition'] ?? '', 'attachment')
            && ($headers['x-content-type-options'] ?? '') === 'nosniff' && $count() === 1, 'A guest can download the original free PDF safely.');
        expect($db->query('SELECT user_id FROM library_downloads LIMIT 1')->fetchColumn() === null, 'Guest download has no invented account owner.');
        foreach (['/storage/' . $resource['material_key'], '/' . $resource['material_key'], '/workbook.pdf'] as $guess) {
            expect($guest->send($guess)[0] === 404, 'Guessed original storage URLs never serve material.');
        }
        expect(str_contains($guest->send($catalogue . '?q=algebra&category=Workbooks&subject=Algebra&level=Junior%20secondary')[2], $ref), 'Search and classification filters combine.');
        foreach (['category=missing', 'subject=missing', 'level=missing', 'q=%25'] as $filter) {
            expect(!str_contains($guest->send($catalogue . '?' . $filter)[2], $ref), 'Nonmatching filters exclude the resource and escape wildcards.');
        }
        expect(!str_contains($guest->send($catalogue . '?before=' . $id)[2], $ref), 'The pagination cursor excludes the boundary resource.');
        $pagingIds = [];
        $db->beginTransaction();
        $stagePage = $db->prepare("INSERT INTO library_resources
            (reference, title, description, author, access, status, licence, rights_reference, rights_confirmed_at,
                rights_confirmed_by, material_key, material_media_type, material_extension, material_bytes, material_sha256)
            SELECT ?, ?, description, author, access, status, licence, rights_reference, rights_confirmed_at,
                rights_confirmed_by, material_key, material_media_type, material_extension, material_bytes, material_sha256
            FROM library_resources WHERE id = ?");
        $stageTag = $db->prepare("INSERT INTO library_resource_tags (resource_id, kind, label) VALUES (?, 'category', 'Paging')");
        for ($n = 1; $n <= 13; $n++) {
            $stagePage->execute(['LB-' . strtoupper(bin2hex(random_bytes(16))), 'Pagination fixture ' . $n, $id]);
            $pagingIds[] = $pagingId = (int) $db->lastInsertId();
            $stageTag->execute([$pagingId]);
        }
        $db->commit();
        $firstPage = $guest->send($catalogue . '?q=Pagination%20fixture&category=Paging')[2];
        expect(preg_match('/href="([^"]+)"[^>]*>More resources<\//', $firstPage, $more) === 1, 'Thirteen matching resources offer a next page.');
        $moreUrl = html_entity_decode($more[1], ENT_QUOTES);
        expect(str_contains($moreUrl, 'category=Paging') && str_contains($moreUrl, 'q=Pagination'), 'Catalogue pagination preserves search and category filters.');
        $secondPage = $guest->send($moreUrl)[2];
        preg_match_all('/resource\?ref=(LB-[A-F0-9]{32})/', $firstPage, $firstRefs);
        preg_match_all('/resource\?ref=(LB-[A-F0-9]{32})/', $secondPage, $secondRefs);
        $firstRefs = array_unique($firstRefs[1]);
        $secondRefs = array_unique($secondRefs[1]);
        expect(count($firstRefs) === 12 && count($secondRefs) === 1 && array_intersect($firstRefs, $secondRefs) === [],
            'Thirteen resources form two cursor pages with no duplicate or missing result.');
        $db->prepare('DELETE FROM library_resource_tags WHERE resource_id IN (' . implode(',', array_fill(0, 13, '?')) . ')')->execute($pagingIds);
        $db->prepare('DELETE FROM library_resources WHERE id IN (' . implode(',', array_fill(0, 13, '?')) . ')')->execute($pagingIds);
        expect($member->send($catalogue . '/save', 'POST', ['action' => 'save', 'ref' => $ref])[0] === 403, 'Saving requires CSRF.');
        expect($member->submit($catalogue . '/save', ['action' => 'save', 'ref' => $ref], $detail)[0] === 303, 'A member saves a book.');
        expect($member->submit($catalogue . '/save', ['action' => 'save', 'ref' => $ref], $detail)[0] === 303, 'Saving is idempotent.');
        expect((int) $db->query('SELECT COUNT(*) FROM library_saved_resources')->fetchColumn() === 1, 'Duplicate saves create one row.');
        expect(str_contains($member->send($catalogue . '/saved')[2], $ref) && !str_contains($stranger->send($catalogue . '/saved')[2], $ref), 'Saved books are owner scoped.');
        expect($stranger->submit($catalogue . '/save', ['action' => 'remove', 'ref' => $ref, 'user_id' => $reader], $detail)[0] === 303
            && str_contains($member->send($catalogue . '/saved')[2], $ref), 'A supplied user ID cannot delete another reader\'s saved book.');
        expect($member->submit($catalogue . '/save', ['action' => 'remove', 'ref' => $ref], $detail)[0] === 303
            && !str_contains($member->send($catalogue . '/saved')[2], $ref), 'The owner can remove a saved resource.');
        expect($member->submit($catalogue . '/save', ['action' => 'save', 'ref' => $ref], $detail)[0] === 303, 'The owner can save it again.');
        expect($member->send($download)[0] === 200 && $count() === 2, 'Member downloads are recorded.');
        expect(str_contains($member->send($catalogue . '/history')[2], $ref) && !str_contains($stranger->send($catalogue . '/history')[2], $ref), 'Download history is owner scoped.');
        echo "PASS publication, validated upload, guest downloads, filters, saved books and private history.\n";

        $edit = static function (array $fields, array $files = []) use ($base, $admin, $page, $row, $id): array {
            $current = $row($id);
            $values = array_replace(array_intersect_key($current, libraryFields()), $fields,
                ['id' => $id, 'revision' => $current['revision']]);
            return libraryUpload($base, $admin, $page, libraryFields($values), $files);
        };
        expect($edit(['access' => 'restricted'])[0] === 303, 'Staff can restrict access.');
        expect($action('publish')[0] === 303, 'Restricted resource can be published.');
        expect($guest->send($detail)[0] === 200, 'Restricted metadata remains discoverable.');
        $denied = static function (AuthBrowser $browser) use ($download, $count): void {
            $before = $count();
            $response = $browser->send($download);
            expect($response[0] !== 200 && $count() === $before,
                'Denied downloads never serve material or increment counts; HTTP ' . $response[0] . ', downloads before=' . $before . ', after=' . $count());
        };
        $denied($guest);
        $denied($member);
        $denied($admin);
        expect($action('grant', ['recipient_id' => $reader, 'expires_at' => '2020-01-01T10:00', 'reason' => 'Fixture permission'])[0] === 422, 'Grants need a future expiry.');
        expect($action('grant', ['recipient_id' => $reader, 'expires_at' => date('Y-m-d\TH:i', time() + 86400 * 400), 'reason' => 'Fixture permission'])[0] === 422, 'Grants are limited to 365 days.');
        expect($action('grant', ['recipient_id' => $reader, 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'Fixture permission'])[0] === 303, 'Staff explicitly grant a named account temporary access.');
        $grant = (int) $db->query('SELECT MAX(id) FROM library_access_grants')->fetchColumn();
        expect($member->send($download)[0] === 200, 'A valid grant authorizes this account.');
        $denied($stranger);
        expect($action('grant', ['recipient_id' => $other, 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'Independent second reader'])[0] === 303,
            'A second reader can hold an independent current grant.');
        $otherGrant = (int) $db->query('SELECT MAX(id) FROM library_access_grants')->fetchColumn();
        expect($member->send($download)[0] === 200 && $stranger->send($download)[0] === 200,
            'Granting another reader does not invalidate the first grant.');
        expect($action('revoke', ['grant_id' => $otherGrant, 'reason' => 'Second reader access ended'])[0] === 303
            && $member->send($download)[0] === 200, 'Revoking one reader preserves another reader\'s grant.');
        $denied($stranger);
        expect($action('revoke', ['grant_id' => $grant, 'reason' => 'Permission withdrawn'])[0] === 303, 'Staff can revoke a grant.');
        $denied($member);
        expect($action('grant', ['recipient_id' => $reader, 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'Renewed permission'])[0] === 303, 'A later explicit grant is possible.');
        $db->exec('UPDATE library_access_grants SET expires_at = DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE revoked_at IS NULL');
        $denied($member);
        expect($action('grant', ['recipient_id' => $reader, 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'New current grant'])[0] === 303, 'A fresh grant authorizes the current revision.');
        expect($edit(['description' => 'Revised description.'])[0] === 303, 'Metadata can be updated.');
        if ($row($id)['status'] !== 'published') {
            expect($action('publish')[0] === 303, 'Edited resource is republished explicitly.');
        }
        $denied($member);
        expect($edit(['access' => 'future_paid'])[0] === 303 && $action('publish')[0] === 303, 'Future paid metadata can be listed.');
        expect($action('grant', ['recipient_id' => $reader, 'expires_at' => date('Y-m-d\TH:i', time() + 86400), 'reason' => 'No payment bypass'])[0] !== 500, 'Paid access cannot cause a grant handler failure.');
        foreach ([$guest, $member, $admin] as $browser) {
            $denied($browser);
        }
        echo "PASS explicit account grants, expiry, revocation, revision invalidation and paid access denial.\n";

        expect($edit(['access' => 'free'], ['material' => ['book.epub', 'application/epub+zip', libraryEpub($work)]])[0] === 303, 'Staff can replace the material with a valid EPUB.');
        expect(!is_file($work . '/library/' . $resource['material_key']), 'The replaced original file is physically cleaned up after commit.');
        expect($row($id)['status'] !== 'published' && $guest->send($download)[0] === 404, 'Replacing material withdraws publication pending review.');
        expect($action('publish')[0] === 303 && $guest->send($download)[0] === 200, 'Explicit confirmation permits publishing the replacement.');
        $oldRevision = (int) $row($id)['revision'];
        expect($action('unpublish')[0] === 303 && $guest->send($detail)[0] === 404, 'Unpublishing takes immediate effect.');
        expect($admin->submit($adminRoute, ['action' => 'publish', 'id' => $id, 'revision' => $oldRevision], $page)[0] === 409, 'Stale staff forms cannot overwrite a newer revision.');
        expect($action('publish')[0] === 303, 'The current revision can be republished.');
        $db->exec("UPDATE library_resources SET rights_expires_on = '2020-01-01' WHERE id = $id");
        expect($guest->send($detail)[0] === 404 && $guest->send($cover)[0] === 404 && $guest->send($download)[0] === 404, 'Expired rights hide every public resource route.');
        $db->exec("UPDATE library_resources SET rights_expires_on = NULL WHERE id = $id");
        $db->exec("UPDATE users SET suspended_at = CURRENT_TIMESTAMP(6) WHERE id = $reader");
        expect($member->send($catalogue . '/history')[0] !== 200, 'An existing session loses private access immediately after suspension.');
        $db->exec("UPDATE users SET suspended_at = NULL WHERE id = $reader");
        $db->exec("DELETE FROM user_roles WHERE user_id = $staff");
        redirectIs($admin->send($adminRoute), '/login');
        $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$staff]);
        grantTestStaffRole($db, $staff);
        $login($admin, 'librarian');
        expect($action('archive')[0] === 303 && $guest->send($detail)[0] === 404, 'Archived material disappears from the public catalogue.');
        $beforeRemoval = $row($id);
        expect($action('remove')[0] === 303, 'Staff can remove a resource.');
        $removed = $row($id);
        expect($removed['status'] === 'removed' && empty($removed['material_key']) && empty($removed['cover_key']), 'Removal leaves a tombstone without active file references.');
        expect($admin->send($page)[0] === 200, 'Staff can still inspect the read-only removed tombstone.');
        foreach (['material_key', 'cover_key'] as $key) {
            expect(!is_file($work . '/library/' . $beforeRemoval[$key]), 'Removed files are physically deleted after the tombstone commits.');
        }
        expect((int) $db->query("SELECT COUNT(*) FROM library_saved_resources WHERE resource_id = $id")->fetchColumn() === 1
            && (int) $db->query("SELECT COUNT(*) FROM library_downloads WHERE resource_id = $id")->fetchColumn() > 0, 'Removal preserves saved and historical ownership records.');
        $login($member, 'reader');
        $history = $member->send($catalogue . '/history')[2];
        expect(str_contains($history, $ref) && !str_contains($history, 'Library algebra workbook'), 'History retains the removed reference without leaking scrubbed metadata.');
        $saved = $member->send($catalogue . '/saved')[2];
        expect(str_contains($saved, $ref) && str_contains($saved, 'Resource removed')
            && str_contains($saved, 'Remove saved resource') && !str_contains($saved, 'Library algebra workbook'),
            'Saved tombstones retain a safe reference and an owner removal control.');
        foreach ([$detail, $download, $cover] as $route) {
            expect($guest->send($route)[0] === 404, 'Removed resources cannot be recovered by old public links.');
        }
        expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = $staff AND action LIKE 'library.%'")->fetchColumn() > 0, 'Library administration is audited.');
        echo "PASS EPUB replacement, optimistic concurrency, rights expiry, live authority and removal tombstones.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) {
            fwrite(STDERR, implode('', array_slice(file($log), -8)));
        }
    }
    $exit = 1;
}
exit($exit);

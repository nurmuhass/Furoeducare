<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\School\SchoolRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-directory-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_directory_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback schema named furoeducare_test_directory_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty directory test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seed = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seed->run(...));
    mkdir($work, 0700);
    $password = 'Directory fixture passphrase 618!';
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
    $teacher = static function (string $name, string $status = 'now', string $date = '', array $choices = ['full_time', 'private_tutoring']) use ($db, $account): int {
        $id = $account($name, 'teacher');
        $db->prepare("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at, sections)
            VALUES (?, 'teacher', 'PrivateFirst', 'PrivateFamily', '+2348012345678', 'fixture', 'fixture', CURRENT_TIMESTAMP(6), ?)")
            ->execute([$id, json_encode([
                'availability' => ['status' => $status, 'date' => $date, 'opportunities' => $choices],
                'personal' => ['address' => 'Private Residential Address', 'reference' => 'Private Referee'],
                'biography' => ['bio' => 'Private administrative notes'],
            ], JSON_THROW_ON_ERROR)]);
        return $id;
    };
    $staff = $account('staff', 'admin');
    $primaryTeacher = $teacher('primary');
    $parentRepo = new ParentRepository(static fn (): PDO => $db);
    foreach (['parent', 'otherparent'] as $name) {
        $parent = $account($name, 'parent');
        $parentRepo->create($parent, ['first_name' => 'Parent', 'last_name' => 'Fixture',
            'phone' => '+2348012345679', 'state_code' => 'LA', 'city' => 'Ikeja']);
    }
    $school = $account('school', 'school');
    $schools = new SchoolRepository(static fn (): PDO => $db);
    $schools->create($school, schoolFields('school@example.test', $password));

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log',
    ], $work . '/server.log', function (string $base) use ($db, $password, $staff, $school, $primaryTeacher, $teacher, $work): void {
        $root = '/find-teachers';
        $adminPage = '/dashboard/admin/directory?id=' . $primaryTeacher;
        $login = static fn (AuthBrowser $b, string $name) => $b->submit('/login', ['email' => $name . '@example.test', 'password' => $password]);
        $admin = new AuthBrowser($base);
        $guest = new AuthBrowser($base);
        redirectIs($login($admin, 'staff'), '/dashboard/admin');
        expect($guest->send($root)[0] === 200 && str_contains($guest->send($root)[2], 'data-empty-state'), 'Visitors can open an empty directory.');
        redirectIs($guest->send($adminPage), '/login');
        expect($admin->send($adminPage)[0] === 200, 'Staff can open the listing approval form.');

        $fields = [
            'id' => $primaryTeacher, 'revision' => 0, 'operation' => 'publish',
            'title' => 'Mathematics and science tutor', 'bio' => 'Tutors use practical exercises to build confidence.',
            'qualification' => 'Bachelor of Education', 'experience' => '6', 'state_code' => 'LA', 'city' => 'Ikeja',
            'subjects' => "Mathematics\nScience", 'levels' => "Primary\nJunior secondary", 'availability' => ['full_time', 'private_tutoring'],
            'professional_name' => 'Unauthorised Display Name', 'approval_basis' => 'Private consent note reference',
        ];
        expect($admin->submit($adminPage, $fields, $adminPage)[0] === 422, 'Publishing requires explicit documented authorisation.');
        $fields['authorised'] = '1';
        expect($admin->submit($adminPage, ['bio' => 'Contact teacher@private.example or 08012345678'] + $fields, $adminPage)[0] === 422,
            'Personal contact details cannot be included in approved public text.');
        expect($admin->submit($adminPage, ['bio' => 'I am privatefirst PrivateFamily.'] + $fields, $adminPage)[0] === 422,
            'The private account name cannot be embedded in a biography.');
        expect($admin->send($adminPage, 'POST', $fields)[0] === 403, 'Publishing requires a CSRF token.');
        redirectIs($admin->submit($adminPage, $fields, $adminPage), $adminPage);
        $ref = (string) $db->query('SELECT reference FROM teacher_listings WHERE teacher_id = ' . $primaryTeacher)->fetchColumn();
        expect(preg_match('/\AFT-[A-F0-9]{20}\z/', $ref) === 1, 'Public references are opaque and stable.');
        $detail = $root . '/profile?ref=' . $ref;
        $request = $root . '/request?ref=' . $ref;
        [$status, $headers, $body] = $guest->send($detail);
        expect($status === 200 && ($headers['cache-control'] ?? '') === 'no-store'
            && str_contains($body, 'Mathematics and science tutor') && str_contains($body, 'Request this teacher'), 'Anyone can read the public projection.');
        foreach (['primary@example.test', '+2348012345678', 'PrivateFirst', 'PrivateFamily', 'Unauthorised Display Name',
            'Private consent note reference', 'Private Residential Address', 'Private Referee', 'Private administrative notes'] as $secret) {
            expect(!str_contains($body, $secret) && !str_contains($guest->send($root)[2], $secret), 'Private data is outside the public projection.');
        }
        expect($guest->send($root . '/photo?ref=' . $ref)[0] === 404, 'No photograph is served without specific authorisation.');
        $png = imagecreatetruecolor(24, 24);
        ob_start();
        imagepng($png);
        $picture = (string) ob_get_clean();
        $boundary = 'fixture-' . bin2hex(random_bytes(12));
        $cover = array_replace($fields, [
            'revision' => '1', '_csrf' => $admin->form($adminPage), 'photo_authorised' => '1',
            'name_authorised' => '1', 'professional_name' => 'Public Educator Fixture',
        ]);
        $multipart = '';
        foreach ($cover as $name => $value) {
            foreach ((array) $value as $part) {
                $field = is_array($value) ? $name . '[]' : $name;
                $multipart .= "--$boundary\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$part\r\n";
            }
        }
        $multipart .= "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"portrait.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n$picture\r\n--$boundary--\r\n";
        redirectIs(request($base, $adminPage, 'POST', $multipart, $admin->cookie, 'multipart/form-data; boundary=' . $boundary), $adminPage);
        [$photoStatus, $photoHeaders, $photo] = $guest->send($root . '/photo?ref=' . $ref);
        expect($photoStatus === 200 && $photoHeaders['content-type'] === 'image/jpeg'
            && $photoHeaders['cache-control'] === 'no-store' && str_starts_with($photo, "\xFF\xD8")
            && str_contains($guest->send($detail)[2], 'Public Educator Fixture'), 'Explicitly authorised portrait and name are served on the same reference.');
        echo "PASS explicit staff approval, anonymous access, and field-level privacy.\n";

        $staged = $db->prepare("INSERT INTO teacher_listings
            (teacher_id, reference, title, bio, qualification, experience, state_code, city, availability_mask, approval_basis, approved_at)
            VALUES (?, ?, ?, 'A short public biography.', 'Diploma', 2, 'OG', 'Abeokuta', ?, 'Fixture approval', ?)");
        $tag = $db->prepare("INSERT INTO teacher_listing_tags (listing_id, kind, label) VALUES (?, 'subject', 'History'), (?, 'level', 'Primary')");
        $stage = static function (int $n, string $status = 'now', string $date = '', ?string $approved = '2026-01-01 00:00:00') use ($teacher, $db, $staged, $tag): string {
            $id = $teacher('fixture' . $n, $status, $date, ['part_time', 'online_tutoring']);
            $reference = 'FT-' . str_pad(strtoupper(dechex($n)), 20, '0', STR_PAD_LEFT);
            $staged->execute([$id, $reference, 'History educator ' . $n, 10, $approved]);
            $listingId = (int) $db->lastInsertId();
            $tag->execute([$listingId, $listingId]);
            if ($approved !== null) {
                $db->prepare('UPDATE teacher_profiles SET published_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?')->execute([$id]);
            }
            return $reference;
        };
        for ($n = 1; $n <= 14; $n++) {
            $stage($n);
        }
        $invisible = [
            $stage(51, 'unavailable'), $stage(52, 'from_date', '2099-01-01'), $stage(53, approved: null),
        ];
        $suspendedRef = $stage(54);
        $db->exec("UPDATE users SET suspended_at = CURRENT_TIMESTAMP(6) WHERE email = 'fixture54@example.test'");
        $invisible[] = $suspendedRef;
        $goneRef = $stage(55);
        $db->exec("DELETE FROM user_roles WHERE user_id = (SELECT id FROM users WHERE email = 'fixture55@example.test')");
        $invisible[] = $goneRef;
        foreach ($invisible as $hidden) {
            foreach (['profile', 'request', 'photo'] as $endpoint) {
                expect($guest->send("$root/$endpoint?ref=$hidden")[0] === 404, 'All public lookup paths enforce visibility.');
            }
        }
        [, , $first] = $guest->send($root . '?subject=History&availability=part_time&tutoring=online');
        expect(substr_count($first, 'View public profile') === 12 && !str_contains($first, 'History educator 13'), 'First page contains 12 results.');
        expect(preg_match('/href="([^"]+)"[^>]*>Next page</', $first, $next) === 1, 'First page offers a next page.');
        $nextPage = html_entity_decode($next[1], ENT_QUOTES);
        expect(str_contains($nextPage, 'subject=History') && str_contains($nextPage, 'tutoring=online'), 'Pagination preserves filters.');
        [, , $second] = $guest->send($nextPage);
        expect(substr_count($second, 'View public profile') === 2 && str_contains($second, 'History educator 13')
            && str_contains($second, 'Previous page') && !str_contains($second, 'History educator 12'), 'The second page is filter-scoped and has no overlap.');
        [, , $filtered] = $guest->send($root . '?q=science&subject=math&level=Junior&state=LA&city=Ike&qualification=Bachelor&experience=5&availability=full_time&tutoring=home');
        expect(substr_count($filtered, 'View public profile') === 1 && str_contains($filtered, 'Mathematics and science tutor'), 'Combined filters use the approved projection and live modes.');
        expect(str_contains($guest->send($root . '?subject=%25')[2], 'data-empty-state'), 'Filter wildcards are treated literally.');
        $nowRef = $stage(56, 'from_date', '2020-01-01');
        expect($guest->send($root . '/profile?ref=' . $nowRef)[0] === 200, 'An approved listing is available once its start date has passed.');
        echo "PASS visibility on every route, all filters, and stable filter-preserving pagination.\n";

        redirectIs($guest->send($request), '/login');
        redirectIs($login($guest, 'parent'), $request);
        $parentEditor = '/dashboard/parent?tab=request-teacher&ref=' . $ref;
        $parentRequests = '/dashboard/parent?tab=requests';
        redirectIs($guest->send($request), $parentEditor);
        expect($guest->send($parentEditor)[0] === 200 && str_contains($guest->send($parentEditor)[2], $ref),
            'Sign-in restores the chosen teacher in the parent tutoring editor.');
        expect($guest->send($root . '/saved', 'POST', ['ref' => $ref, 'action' => 'save'])[0] === 403, 'Bookmark mutation requires CSRF.');
        redirectIs($guest->submit($root . '/saved', ['ref' => $ref, 'action' => 'save'], $detail), $detail);
        expect(str_contains($guest->send($root . '/saved')[2], $ref), 'The parent can read their saved teacher.');
        $secondParent = new AuthBrowser($base);
        redirectIs($login($secondParent, 'otherparent'), '/dashboard/parent');
        expect(!str_contains($secondParent->send($root . '/saved')[2], $ref), 'Saved teachers are owner-scoped.');
        redirectIs($guest->send($root . '/requests'), $parentRequests);
        redirectIs($secondParent->send($root . '/requests'), $parentRequests);
        expect($guest->send('/dashboard/admin/directory/requests')[0] === 403, 'Only staff can open the submitted request queue.');
        $schoolBrowser = new AuthBrowser($base);
        redirectIs($login($schoolBrowser, 'school'), '/dashboard/school');
        redirectIs($schoolBrowser->submit($root . '/request', ['ref' => $ref, 'notes' => 'Pending school private draft'], $request), $request);
        expect(str_contains($schoolBrowser->send($root . '/requests')[2], 'Pending school private draft')
            && !str_contains($admin->send('/dashboard/admin/directory/requests')[2], 'Pending school private draft'),
            'An unapproved school can review its private draft before it reaches staff.');
        $db->exec("UPDATE school_onboarding SET review_status = 'approved', approved_at = CURRENT_TIMESTAMP(6)");
        $cases = '/candidate-requests';
        $newCase = $cases . '?new=1&ref=' . $ref;
        redirectIs($schoolBrowser->send($request), $newCase);
        redirectIs($schoolBrowser->send($root . '/requests'), $cases);
        $draft = (int) $db->query("SELECT id FROM candidate_requests WHERE legacy_directory_id IS NOT NULL")->fetchColumn();
        $draftPage = $cases . '?id=' . $draft;
        expect($draft > 0 && str_contains($schoolBrowser->send($cases)[2], $draftPage)
            && str_contains($schoolBrowser->send($draftPage)[2], 'Pending school private draft'),
            'The owner retains the existing school draft after approval.');
        $db->prepare("INSERT INTO vacancies (school_id, status, content, published_at)
            VALUES (?, 'published', JSON_OBJECT('title', 'Directory fixture vacancy'), CURRENT_TIMESTAMP(6))")->execute([$school]);
        $vacancy = (int) $db->lastInsertId();
        $context = ['vacancy_id' => $vacancy, 'requirements' => 'Mathematics experience'];
        redirectIs($schoolBrowser->submit($cases, ['action' => 'link', 'id' => $draft] + $context, $draftPage), $draftPage);
        $submitted = $schoolBrowser->submit($cases, ['action' => 'create', 'ref' => $ref, 'purpose' => 'Submitted school directory request'] + $context, $newCase);
        expect($submitted[0] === 303 && str_starts_with($submitted[1]['location'] ?? '', $cases . '?id='), 'The directory preselects the teacher for a vacancy-linked request.');
        expect(str_contains($admin->send('/dashboard/admin/candidate-requests')[2], 'Submitted school directory request')
            && !str_contains($guest->send($detail)[2], 'Submitted school directory request'),
            'Approved school requests reach the central staff queue and remain private.');
        redirectIs($guest->submit($root . '/saved', ['ref' => $ref, 'action' => 'remove'], $detail), $detail);
        expect(!str_contains($guest->send($root . '/saved')[2], $ref), 'The owner can remove a saved teacher.');
        $register = new AuthBrowser($base);
        redirectIs($register->send($request), '/login');
        $registry = '/register/parent';
        $joinEmail = 'new-parent@example.test';
        redirectIs($register->submit($registry, parentFields($joinEmail, $password)), '/login');
        [$token, $emailFile] = tokenFile($work . '/mail', $joinEmail, '/verify-parent');
        expect(str_contains(file_get_contents($emailFile), 'ref=' . $ref), 'Verification email carries the selected public reference.');
        $newBrowser = new AuthBrowser($base);
        $verification = '/verify-parent?token=' . $token . '&ref=' . $ref;
        redirectIs($newBrowser->submit('/verify-parent', ['token' => $token, 'ref' => $ref] + parentFields($joinEmail, $password), $verification), $request);
        redirectIs($newBrowser->send($request), $parentEditor);
        expect($newBrowser->send($parentEditor)[0] === 200, 'Verification in a different browser returns to the selected request.');

        $db->prepare("UPDATE teacher_profiles SET sections = JSON_SET(sections, '$.availability.status', ?) WHERE user_id = ?")
            ->execute(['unavailable', $primaryTeacher]);
        expect($guest->send($detail)[0] === 404 && $guest->send($root . '/photo?ref=' . $ref)[0] === 404,
            'Pausing availability immediately hides the profile and its image.');
        $db->prepare("UPDATE teacher_profiles SET sections = JSON_SET(sections, '$.availability.status', ?) WHERE user_id = ?")
            ->execute(['now', $primaryTeacher]);
        expect($guest->send($detail)[0] === 200, 'Resuming availability restores the approved listing.');

        $revision = (int) $db->query("SELECT revision FROM teacher_listings WHERE reference = '$ref'")->fetchColumn();
        redirectIs($admin->submit($adminPage, ['id' => $primaryTeacher, 'revision' => $revision, 'operation' => 'withdraw'], $adminPage), $adminPage);
        expect($guest->send($detail)[0] === 404 && $guest->send($request)[0] === 404, 'Withdrawal takes effect immediately.');
        expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = $staff AND action LIKE 'directory.listing.%'")->fetchColumn() === 3,
            'Publication and withdrawal are audited.');
        echo "PASS authentication continuation, CSRF, saved teachers, private request intake, and immediate withdrawal.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) {
            fwrite(STDERR, implode('', array_slice(file($log), -8)));
        }
    }
    $exit = 1;
} finally {
    if ($exit === 0) {
        foreach ([$work . '/mail', $work . '/sessions', $work] as $directory) {
            if (is_dir($directory)) {
                foreach (glob($directory . '/*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($directory);
            }
        }
    }
}
exit($exit);

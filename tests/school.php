<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Auth\LegalNotice;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

function schoolReviewFields(PDO $db, int $id, string $decision, string $reason): array
{
    $query = $db->prepare('SELECT revision FROM school_onboarding WHERE user_id = ?');
    $query->execute([$id]);
    return ['id' => $id, 'revision' => $query->fetchColumn(), 'decision' => $decision, 'reason' => $reason,
        'checks' => ['institution', 'representative']];
}

function schoolRecruitmentAccess(AuthBrowser $browser, int $expected): void
{
    $dash = '/dashboard/school';
    expect($browser->submit($dash . '/vacancies/publish', [], $dash)[0] === 403, 'School accounts cannot publish vacancies directly.');
    expect($browser->submit($dash . '/recruitment/submit', [], $dash)[0] === $expected, 'Incorrect permission for recruitment submission.');
    foreach (['/teachers/restricted', '/shortlists'] as $path) {
        expect($browser->send($dash . $path)[0] === $expected, "Incorrect permission for $path.");
    }
}

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-school-test-' . bin2hex(random_bytes(6));
$mail = $work . '/mail';
$sessions = $work . '/sessions';
$serverLog = $work . '/server.log';
$errorLog = $work . '/app.log';
$exit = 0;

try {
    expect(mkdir($work, 0700), 'Cannot create temporary test directory.');
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_school_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development database named furoeducare_test_school_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty school test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seed = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seed->run(...));

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $sessions,
        'AUTH_MAIL_DIR' => $mail, 'APP_LOG_FILE' => $errorLog,
    ], $serverLog, function (string $base) use ($db, $mail): void {
        $signup = '/register/school';
        $verify = '/verify-school';
        $dash = '/dashboard/school';
        $profile = $dash . '?tab=profile';
        $drafts = $dash . '?tab=drafts';
        $reviews = '/dashboard/admin/schools';
        $review = $reviews . '/review';
        $password = 'School fixture passphrase 573!';
        $email = 'representative@example.test';
        $fields = schoolFields($email, $password);
        $school = new AuthBrowser($base);
        $anonymous = new AuthBrowser($base);

        expect($school->send($signup, 'POST', $fields)[0] === 403, 'Signup requires CSRF.');
        [$status, , $body] = $school->submit($signup, array_replace($fields, [
            'category' => 'invalid', 'contact_phone' => 'bad', 'address' => '', 'terms' => '0', 'privacy' => '0',
        ]));
        expect($status === 422 && str_contains($body, 'Enter the school address') && str_contains($body, 'Accept the Terms of Use')
            && str_contains($body, 'Acknowledge the Privacy Notice') && !str_contains($body, $password),
            'Signup must validate its required fields without echoing credentials.');
        redirectIs($school->submit($signup, $fields), '/login');
        expect($db->query('SELECT COUNT(*) FROM school_onboarding')->fetchColumn() === 0, 'The school profile requires email verification.');
        [$token, $firstMail] = tokenFile($mail, $email, $verify);
        $verification = $verify . '?token=' . $token;
        expect(str_contains($school->send($verification)[2], 'confirm_registration'), 'The initiating browser can confirm the signup draft.');
        expect($db->query('SELECT email_verified_at FROM users')->fetchColumn() === null, 'Opening a verification link changes no account state.');
        $cookie = $school->cookie;
        redirectIs($school->submit($verify, ['token' => $token, 'confirm_registration' => '1'], $verification), $dash);
        expect($school->cookie !== $cookie, 'Verification regenerates the session.');
        $record = $db->query('SELECT * FROM school_onboarding')->fetch();
        $id = (int) $record['user_id'];
        expect($record['review_status'] === 'pending' && $record['approved_at'] === null && $record['contact_phone'] === '+2348012345678'
            && $record['terms_version'] === LegalNotice::TERMS && $record['privacy_version'] === LegalNotice::PRIVACY,
            'Email verification stores the validated and acknowledged profile with pending status.');
        [$status, $headers, $body] = $school->send($dash);
        expect($status === 200 && ($headers['cache-control'] ?? '') === 'no-store' && str_contains($body, 'Pending approval'),
            'An email-verified school reaches the restricted overview.');
        foreach ([$profile, $drafts, $dash . '?tab=notifications', $dash . '?tab=settings', '/find-teachers', '/digital-library', '/services'] as $path) {
            expect($school->send($path)[0] === 200, "Pending schools can use $path.");
        }
        redirectIs($anonymous->send($dash . '/shortlists'), '/login');
        schoolRecruitmentAccess($school, 403);
        echo "PASS school registration, email verification, pending access and restricted recruitment routes.\n";

        $other = new AuthBrowser($base);
        $otherEmail = 'second-representative@example.test';
        $duplicate = schoolFields($otherEmail, $password, ['school_name' => 'green-valley SCHOOL', 'city' => 'abeoKuta']);
        redirectIs($other->submit($signup, $duplicate), '/login');
        [$otherToken] = tokenFile($mail, $otherEmail, $verify, [$firstMail]);
        $otherLink = $verify . '?token=' . $otherToken;
        expect($other->submit($verify, ['token' => $otherToken, 'confirm_registration' => '1'], $otherLink)[0] === 409,
            'A second account for the normalized institution must be rejected.');
        expect((int) $db->query('SELECT COUNT(*) FROM school_onboarding')->fetchColumn() === 1
            && $db->query("SELECT email_verified_at FROM users WHERE email = 'second-representative@example.test'")->fetchColumn() === null,
            'Duplicate registration must roll back role and email activation.');
        $second = new AuthBrowser($base);
        expect(!str_contains($second->send($otherLink)[2], 'confirm_registration'), 'A different browser must reenter the registration details.');
        $secondFields = array_replace($duplicate, ['school_name' => 'Oak Academy', 'city' => 'Ilaro']);
        expect($second->submit($verify, ['token' => $otherToken, 'email' => 'wrong@example.test'] + $secondFields, $otherLink)[0] === 422,
            'Cross-browser confirmation must match the intended email.');
        redirectIs($second->submit($verify, ['token' => $otherToken] + $secondFields, $otherLink), $dash);
        $secondId = (int) $db->query("SELECT user_id FROM school_onboarding WHERE school_name = 'Oak Academy'")->fetchColumn();
        expect(!str_contains($second->send($profile . '&user_id=' . $id)[2], 'Green Valley School'), 'Profile identifiers cannot read another school.');
        redirectIs($second->submit($dash . '/profile', array_replace($secondFields, [
            'contact_phone' => '08055555555', 'user_id' => $id, 'review_status' => 'approved', 'approved_at' => '2026-01-01 00:00:00',
        ]), $profile), $profile);
        $owned = $db->query('SELECT school_name, contact_phone, review_status FROM school_onboarding ORDER BY user_id')->fetchAll();
        expect($owned[0]['contact_phone'] === '+2348012345678' && $owned[1]['contact_phone'] === '+2348055555555'
            && $owned[1]['review_status'] === 'pending', 'Only the signed-in school’s editable profile fields can change.');
        expect($second->submit($dash . '/profile', $duplicate, $profile)[0] === 409, 'Editing a profile cannot claim a second institution’s identity.');

        $saved = $school->submit($dash . '/drafts', [
            'title' => 'Science teaching requirements', 'requirements' => 'Junior secondary chemistry.', 'status' => 'active', 'school_id' => $secondId,
        ], $drafts);
        $draftId = (int) $db->query('SELECT id FROM school_recruitment_drafts WHERE school_id = ' . $id)->fetchColumn();
        expect($draftId > 0, 'A pending school can save a private recruitment draft.');
        redirectIs($saved, $drafts . '&id=' . $draftId);
        expect($second->send($drafts . '&id=' . $draftId)[0] === 404
            && $second->submit($dash . '/drafts', ['draft_id' => $draftId, 'title' => 'Overwrite'], $drafts)[0] === 404,
            'Draft reads and writes must be scoped to their school.');
        redirectIs($school->submit($dash . '/drafts', ['draft_id' => $draftId, 'title' => 'Edited requirements'], $drafts), $drafts . '&id=' . $draftId);
        expect($db->query('SELECT title FROM school_recruitment_drafts')->fetchColumn() === 'Edited requirements', 'The school can update its own draft.');
        echo "PASS unique school identity, cross-browser verification, owner-scoped profiles and private drafts.\n";

        $parent = new AuthBrowser($base);
        verifiedParent($parent, $mail, 'parent@example.test', $password);
        redirectIs($parent->submit('/login', ['email' => 'parent@example.test', 'password' => $password]), '/dashboard/parent');
        expect($parent->send($dash)[0] === 403 && $parent->send($dash . '/teachers/restricted')[0] === 403,
            'An unrelated role cannot use school routes.');
        expect($school->send($reviews)[0] === 403 && $school->submit($review,
            schoolReviewFields($db, $id, 'approved', 'Self approval'), $dash)[0] === 403,
            'A school cannot access administrator reviews or approve itself.');
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute(['admin@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $reviewer = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'admin'")->execute([$reviewer]);
        $admin = new AuthBrowser($base);
        redirectIs($admin->submit('/login', ['email' => 'admin@example.test', 'password' => $password]), '/dashboard/admin');
        expect(str_contains($admin->send('/dashboard/admin')[2], 'Review school accounts')
            && str_contains($admin->send($reviews)[2], 'Green Valley School'), 'The administrator can find the review queue.');
        $detail = $reviews . '?id=' . $id;
        expect(str_contains($admin->send($detail)[2], $email), 'Staff can see the representative email.');
        expect($admin->send($review, 'POST', schoolReviewFields($db, $id, 'approved', 'Valid reason'))[0] === 403, 'Review requires CSRF.');
        expect($admin->submit($review, schoolReviewFields($db, $id, 'approved', "\u{00A0}\u{2002}"), $detail)[0] === 422,
            'Review decisions require a reason.');

        $decide = static function (string $status, string $reason) use ($db, $id, $admin, $review, $detail): void {
            redirectIs($admin->submit($review, schoolReviewFields($db, $id, $status, $reason), $detail), $detail);
        };
        $decide('corrections_required', 'Correct the office address <please>.');
        $overview = $school->send($dash)[2];
        expect(str_contains($overview, 'Changes required') && str_contains($overview, '&lt;please&gt;'), 'Schools see their escaped correction request.');
        expect(str_contains($admin->send('/dashboard/admin/verification/corrections')[2], 'Green Valley School'), 'School corrections enter their own queue.');
        expect($admin->submit($review, schoolReviewFields($db, $id, 'approved', 'Premature approval'), $detail)[0] === 422,
            'A school must update its profile before approval from a correction request.');
        expect(!str_contains($second->send($dash . '?tab=notifications')[2], 'Correct the office address'), 'Another school cannot see this decision.');
        $stale = schoolReviewFields($db, $id, 'approved', 'Stale review');
        redirectIs($school->submit($dash . '/profile', array_replace($fields, ['address' => '24 Fixture Road']), $profile), $profile);
        expect(str_contains($school->send($dash)[2], 'Pending approval'), 'Corrections return the updated profile to pending.');
        expect($admin->submit($review, $stale, $detail)[0] === 409, 'An obsolete review version cannot approve updated details.');
        expect($admin->submit($review, array_replace(schoolReviewFields($db, $id, 'approved', 'Missing check'),
            ['checks' => ['institution']]), $detail)[0] === 422, 'Approval requires all school checks.');
        $decide('approved', 'School confirmed.');
        expect($admin->submit($review, schoolReviewFields($db, $id, 'approved', 'Duplicate approval'), $detail)[0] === 422,
            'Duplicate approval is an invalid status transition.');
        schoolRecruitmentAccess($school, 501);
        $decide('rejected', 'Rejected on review.');
        schoolRecruitmentAccess($school, 403);
        $decide('approved', 'Approval after re-evaluation.');
        $parallel = new AuthBrowser($base);
        redirectIs($parallel->submit('/login', compact('email', 'password')), $dash);
        $decide('suspended', 'School access suspended.');
        redirectIs($school->send($dash), '/login');
        redirectIs($parallel->send($dash . '/shortlists'), '/login');
        expect($school->submit('/login', compact('email', 'password'))[0] === 403, 'Suspension denies new logins.');
        $decide('approved', 'School access restored.');
        redirectIs($parallel->send($dash), '/login');
        redirectIs($school->submit('/login', compact('email', 'password')), $dash);
        $history = $db->query('SELECT reviewer_id, decision, reason, created_at FROM school_reviews ORDER BY id')->fetchAll();
        expect(array_column($history, 'decision') === ['corrections_required', 'approved', 'rejected', 'approved', 'suspended', 'approved'],
            'Record each transition, including restored approval.');
        foreach ($history as $record) {
            expect((int) $record['reviewer_id'] === $reviewer && $record['reason'] !== '' && $record['created_at'] !== null,
                'Each decision records a reviewer, reason and timestamp.');
        }
        expect((int) $db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'verification.school.review'")->fetchColumn() === 6
            && (int) $db->query("SELECT COUNT(*) FROM verification_notifications WHERE event = 'verification.school.review'")->fetchColumn() === 6,
            'Each successful school decision emits one audit record and notification.');
        $updates = $school->send($dash . '?tab=notifications')[2];
        expect(str_contains($updates, 'School access restored.') && !str_contains($updates, 'admin@example.test'),
            'Schools can read their own decisions without staff account identifiers.');
        echo "PASS administrator-only decisions, stale reviews, correction and approval gates, and suspension revocation.\n";

        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute(['legacy@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $legacyId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'school'")->execute([$legacyId]);
        $db->prepare('INSERT INTO school_onboarding (user_id, school_name, approved_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute([$legacyId, 'Legacy Fixture College']);
        $legacy = new AuthBrowser($base);
        redirectIs($legacy->submit('/login', ['email' => 'legacy@example.test', 'password' => $password]), $dash);
        expect(str_contains($legacy->send($dash)[2], 'Complete your school profile'),
            'A legacy school keeps access to its restricted dashboard.');
        schoolRecruitmentAccess($legacy, 403);
        expect($admin->submit($review, schoolReviewFields($db, $legacyId, 'approved', 'Missing location'),
            $reviews . '?id=' . $legacyId)[0] === 422, 'An incomplete legacy profile cannot use an old approval to bypass full review.');
        $duplicateLegacy = new AuthBrowser($base);
        $legacyClaim = schoolFields('legacy-claim@example.test', $password, ['school_name' => 'legacy-fixture college']);
        redirectIs($duplicateLegacy->submit($signup, $legacyClaim), '/login');
        [$claimToken] = tokenFile($mail, $legacyClaim['email'], $verify);
        expect($duplicateLegacy->submit($verify, ['token' => $claimToken, 'confirm_registration' => '1'],
            $verify . '?token=' . $claimToken)[0] === 409, 'A name-only legacy account is reserved while its school completes its location.');
        $legacyValues = schoolFields('legacy@example.test', $password, ['school_name' => 'Legacy Fixture College', 'city' => 'Ota']);
        redirectIs($legacy->submit($dash . '/profile', $legacyValues, $profile), $profile);
        expect(str_contains($legacy->send($dash)[2], 'Pending approval'), 'Completing a formerly approved legacy account returns it to review.');
        echo "PASS legacy institution reservation, restricted access, and profile completion.\n";

        $probe = new AuthBrowser($base);
        $invalidToken = str_repeat('e', 64);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            expect($probe->submit($verify, ['token' => $invalidToken] + $fields, $signup)[0] === 422,
                'An inactive token cannot complete school registration.');
        }
        [$limited, $limitHeaders] = $probe->submit($verify, ['token' => $invalidToken] + $fields, $signup);
        expect($limited === 429 && isset($limitHeaders['retry-after']), 'Repeated school verification attempts must be rate-limited.');
        echo "PASS inactive verification tokens and rate limiting.\n";


    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    foreach ([$errorLog, $serverLog] as $file) {
        if (is_file($file)) {
            fwrite(STDERR, implode('', array_slice(file($file), -16)));
        }
    }
    $exit = 1;
} finally {
    foreach ([$mail, $sessions] as $directory) {
        if (is_dir($directory)) {
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }
    foreach ([$serverLog, $errorLog] as $file) {
        if (is_file($file)) unlink($file);
    }
    if (is_dir($work)) rmdir($work);
}
exit($exit);

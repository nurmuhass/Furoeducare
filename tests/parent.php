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

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-parent-test-' . bin2hex(random_bytes(6));
$mail = $work . '/mail';
$sessions = $work . '/sessions';
$serverLog = $work . '/server.log';
$errorLog = $work . '/app.log';
$exit = 0;

try {
    expect(mkdir($work, 0700), 'Cannot create temporary test directory.');
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_parent_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development database named furoeducare_test_parent_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty parent test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $sessions, 'AUTH_MAIL_DIR' => $mail, 'APP_LOG_FILE' => $errorLog,
    ], $serverLog, function (string $base) use ($db, $mail, $sessions): void {
        $signup = '/register/parent';
        $verify = '/verify-parent';
        $dash = '/dashboard/parent';
        $editor = $dash . '?tab=request-teacher';
        $requests = $dash . '?tab=requests';
        $settings = $dash . '?tab=settings';
        $profileAction = $dash . '/profile';
        $draftAction = $dash . '/requests';
        $submitAction = $draftAction . '/submit';
        $email = 'guardian@example.test';
        $password = 'Parent fixture passphrase 453!';
        $fields = parentFields($email, $password);
        $parent = new AuthBrowser($base);

        $signupBody = $parent->send($signup)[2];
        foreach (['first_name', 'last_name', 'email', 'phone', 'state_code', 'city', 'password', 'terms', 'privacy'] as $name) {
            expect(str_contains($signupBody, 'name="' . $name . '"'), "The parent form must collect $name.");
        }
        expect(!str_contains($signupBody, 'name="child_'), 'Registration should not offer child information fields.');
        expect($parent->send($signup, 'POST', $fields)[0] === 403, 'Registration requires CSRF.');
        [$status, , $body] = $parent->submit($signup, array_replace($fields, [
            'first_name' => "\u{00A0}", 'state_code' => 'XX', 'phone' => 'short',
            'terms' => '0', 'privacy' => '0', 'password_confirmation' => 'mismatch',
        ]));
        expect($status === 422 && str_contains($body, 'Enter the first name') && str_contains($body, 'Accept the Terms')
            && str_contains($body, 'Acknowledge the Privacy Notice') && !str_contains($body, $password),
            'The form must validate required adult data without echoing a password.');
        redirectIs($parent->submit($signup, $fields + ['child_name' => 'Uncollected fixture name']), '/login');
        $sessionFile = $sessions . '/sess_' . explode('=', $parent->cookie, 2)[1];
        expect(!str_contains(file_get_contents($sessionFile), 'Uncollected fixture name'), 'Unknown child fields cannot enter the registration draft.');
        expect($db->query('SELECT COUNT(*) FROM parent_profiles')->fetchColumn() === 0, 'An unverified email has no parent profile.');
        redirectIs($parent->submit($draftAction, ['subject' => 'Premature'], $signup), '/login');
        redirectIs($parent->submit($submitAction, ['id' => '1'], $signup), '/login');
        [$expired, $initialMail] = tokenFile($mail, $email, $verify);
        $db->exec('UPDATE email_verification_tokens SET expires_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND');
        expect($parent->send($verify . '?token=' . $expired)[0] === 422, 'Expired parent links must be refused.');
        $resend = '/resend-verification?flow=parent';
        redirectIs($parent->submit('/resend-verification', ['email' => $email, 'flow' => 'parent'], $resend), $resend);
        [$token] = tokenFile($mail, $email, $verify, [$initialMail]);
        $link = $verify . '?token=' . $token;
        expect(str_contains($parent->send($link)[2], 'confirm_registration'), 'The registration draft belongs to the initiating browser.');
        expect($db->query('SELECT email_verified_at FROM users')->fetchColumn() === null, 'GET does not verify an email.');
        $cookie = $parent->cookie;
        redirectIs($parent->submit($verify, ['token' => $token, 'confirm_registration' => '1'], $link), $dash);
        expect($cookie !== $parent->cookie, 'Email activation rotates the parent session.');
        $record = $db->query('SELECT * FROM parent_profiles')->fetch();
        $owner = (int) $record['user_id'];
        expect($record['first_name'] === 'Adé' && $record['phone'] === '+2348012345678' && $record['city'] === 'Ikeja'
            && $record['terms_version'] === LegalNotice::TERMS && $record['privacy_version'] === LegalNotice::PRIVACY
            && $record['accepted_at'] !== null, 'Email confirmation creates the adult profile and records the legal revisions.');
        echo "PASS adult-only account registration, server validation, email verification, session rotation, and pre-verification restrictions.\n";

        foreach (['overview', 'find-teachers', 'request-teacher', 'requests', 'recommended', 'saved',
            'arrangements', 'library', 'messages', 'notifications', 'settings'] as $tab) {
            [$code, $headers, $page] = $parent->send($dash . '?tab=' . $tab);
            expect($code === 200 && ($headers['cache-control'] ?? '') === 'no-store'
                && str_contains($page, 'Parent dashboard navigation'), "Parent dashboard tab $tab is available.");
        }
        expect(str_contains($parent->send($dash . '?tab=find-teachers')[2], 'href="/find-teachers"')
            && str_contains($parent->send($dash . '?tab=library')[2], 'href="/digital-library"')
            && $parent->send('/find-teachers')[0] === 200, 'Link to public teacher and library pages.');
        expect($parent->send('/dashboard/teacher')[0] === 403 && $parent->send('/dashboard/teacher/documents/download?id=1')[0] === 403,
            'Parent accounts cannot access private teacher features.');

        $sender = new AuthBrowser($base);
        $other = new AuthBrowser($base);
        $otherEmail = 'second-guardian@example.test';
        $otherFields = parentFields($otherEmail, $password, ['first_name' => 'Second']);
        redirectIs($sender->submit($signup, $otherFields), '/login');
        [$otherToken] = tokenFile($mail, $otherEmail, $verify);
        $otherLink = $verify . '?token=' . $otherToken;
        expect(!str_contains($other->send($otherLink)[2], 'confirm_registration'), 'A new browser cannot confirm another session’s draft.');
        expect($other->submit($verify, ['token' => $otherToken, 'email' => $email] + $otherFields, $otherLink)[0] === 422,
            'The verification token is bound to the verified mailbox.');
        redirectIs($other->submit($verify, ['token' => $otherToken] + $otherFields, $otherLink), $dash);
        expect($sender->send($otherLink)[0] === 422, 'A consumed token cannot be reused.');
        $otherSettings = $other->send($settings . '&user_id=' . $owner)[2];
        expect(!str_contains($otherSettings, 'value="' . $email . '"')
            && str_contains($otherSettings, 'value="' . $otherEmail . '"'), 'A forged profile ID cannot reveal another parent’s data.');
        redirectIs($other->submit($profileAction, ['user_id' => $owner, 'city' => 'Yaba', 'email' => $email,
            'terms_version' => 'forged'] + $otherFields, $settings), $settings);
        $profiles = $db->query('SELECT first_name, city, terms_version FROM parent_profiles ORDER BY user_id')->fetchAll();
        expect($profiles[0]['city'] === 'Ikeja' && $profiles[1]['city'] === 'Yaba'
            && $profiles[1]['terms_version'] === LegalNotice::TERMS && str_contains($other->send($settings)[2], $otherEmail),
            'Only the current parent’s editable values can change.');
        echo "PASS dashboard navigation, public-only teacher access, cross-browser verification, and profile ownership.\n";

        expect($parent->send($draftAction, 'POST', ['subject' => 'No CSRF'])[0] === 403, 'Draft creation requires CSRF.');
        $save = $parent->submit($draftAction, ['subject' => 'Mathematics', 'requirements' => 'Review <fractions>.',
            'parent_id' => '999', 'submitted_at' => '2026-01-01 00:00:00'], $editor);
        $id = (int) $db->query('SELECT id FROM parent_tutoring_requests')->fetchColumn();
        $detail = $requests . '&id=' . $id;
        $firstVersion = $db->query('SELECT updated_at FROM parent_tutoring_requests')->fetchColumn();
        redirectIs($save, $detail);
        expect($id > 0 && $db->query('SELECT submitted_at FROM parent_tutoring_requests')->fetchColumn() === null
            && str_contains($parent->send($detail)[2], 'Review &lt;fractions&gt;.'),
            'Draft creation scopes ownership, ignores forged submission state and escapes notes.');
        expect($parent->submit($submitAction, ['id' => $id, 'version' => $firstVersion], $detail)[0] === 422,
            'A parent can save an incomplete draft, and must complete it before submitting.');
        expect(!str_contains($other->send($requests)[2], 'Mathematics') && $other->send($detail)[0] === 404
            && $other->send($editor . '&id=' . $id)[0] === 404
            && $other->submit($draftAction, ['id' => $id, 'version' => $firstVersion, 'subject' => 'Overwrite'], $editor)[0] === 404
            && $other->submit($submitAction, ['id' => $id, 'version' => $firstVersion], $editor)[0] === 404,
            'A second parent cannot list, read, edit or submit another parent’s request.');
        $lessonFields = [
            'id' => $id, 'version' => $firstVersion, 'subject' => 'Basic Science', 'level' => 'primary',
            'learners' => '2', 'objectives' => 'Build observation skills.', 'mode' => 'online',
            'days' => ['wed'], 'time_from' => ['15:00'], 'time_to' => ['16:00'], 'sessions' => '1',
            'requirements' => 'Practice observation.',
        ];
        redirectIs($parent->submit($draftAction, $lessonFields, $editor . '&id=' . $id), $detail);
        expect($parent->submit($draftAction, ['subject' => 'Stale edit'] + $lessonFields, $editor . '&id=' . $id)[0] === 409,
            'A stale editor cannot overwrite a newer draft.');
        expect($parent->send($submitAction, 'POST', ['id' => $id])[0] === 403, 'Submission requires CSRF.');
        expect($parent->submit($submitAction, ['id' => $id, 'version' => $firstVersion], $detail)[0] === 409,
            'A draft edited since review must be reviewed again before submission.');
        $submission = ['id' => $id, 'version' => $db->query('SELECT updated_at FROM parent_tutoring_requests')->fetchColumn()];
        redirectIs($parent->submit($submitAction, $submission, $detail), $detail);
        $submitted = $db->query('SELECT subject, parent_id, status, submitted_at FROM parent_tutoring_requests')->fetch();
        expect($submitted['subject'] === 'Basic Science' && (int) $submitted['parent_id'] === $owner
            && $submitted['status'] === 'submitted' && $submitted['submitted_at'] !== null
            && str_contains($parent->send($detail)[2], 'Build observation skills.'),
            'Submission records the saved request for its owner.');
        expect($parent->submit($draftAction, ['subject' => 'Replace after submission', 'version' => $submission['version']] + $lessonFields, $detail)[0] === 409,
            'A saved submission cannot be changed through the draft endpoint.');
        redirectIs($parent->submit($submitAction, $submission, $detail), $detail);
        expect($db->query('SELECT submitted_at FROM parent_tutoring_requests')->fetchColumn() === $submitted['submitted_at'],
            'A repeated submission keeps the existing record and timestamp.');
        echo "PASS owner-only request listing, detail, draft edits, protected submission, and replay handling.\n";

        $seed = $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))');
        $grant = $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?');
        $seed->execute(['admin@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $grant->execute([(int) $db->lastInsertId(), 'admin']);
        $admin = new AuthBrowser($base);
        redirectIs($admin->submit('/login', ['email' => 'admin@example.test', 'password' => $password]), '/dashboard/admin');
        expect($admin->send($detail)[0] === 403 && $admin->submit($submitAction, ['id' => $id], '/dashboard/admin')[0] === 403,
            'Parent dashboard surfaces require the parent role.');
        $seed->execute(['legacy-parent@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $grant->execute([(int) $db->lastInsertId(), 'parent']);
        $legacy = new AuthBrowser($base);
        redirectIs($legacy->submit('/login', ['email' => 'legacy-parent@example.test', 'password' => $password]), $dash);
        expect(str_contains($legacy->send($dash)[2], 'Complete your parent profile')
            && $legacy->submit($draftAction, ['subject' => 'Too early'], $dash)[0] === 403,
            'A legacy verified parent completes their profile before saving requests.');
        redirectIs($legacy->submit($profileAction, parentFields('legacy-parent@example.test', $password), $dash), $dash);
        expect(str_contains($legacy->send($dash)[2], 'Email verified'), 'A legacy parent can finish their profile with the existing account.');
        $csrf = $parent->form($detail);
        $db->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')->execute([$owner]);
        redirectIs($parent->send($submitAction, 'POST', ['id' => $id, '_csrf' => $csrf]), '/login');
        echo "PASS non-parent restrictions, legacy account completion, and email status rechecked on submission.\n";
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
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    foreach ([$serverLog, $errorLog] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($work)) {
        rmdir($work);
    }
}
exit($exit);

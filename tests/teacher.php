<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Teacher\TeacherInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

function upload(string $base, AuthBrowser $browser, string $kind, string $name, string $bytes): array
{
    $boundary = 'fixture-' . bin2hex(random_bytes(12));
    $csrf = $browser->form('/dashboard/teacher?section=documents');
    $body = "--$boundary\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n$csrf\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"kind\"\r\n\r\n$kind\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"document\"; filename=\"$name\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n$bytes\r\n--$boundary--\r\n";
    return request($base, '/dashboard/teacher/documents/upload', 'POST', $body, $browser->cookie,
        'multipart/form-data; boundary=' . $boundary);
}

function saveSection(AuthBrowser $browser, string $section, array $fields): array
{
    return $browser->submit('/dashboard/teacher/profile', ['section' => $section] + $fields, '/dashboard/teacher?section=' . $section);
}

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-teacher-' . bin2hex(random_bytes(6));
$mail = $work . '/mail';
$sessions = $work . '/sessions';
$files = $work . '/documents';
$serverLog = $work . '/server.log';
$errorLog = $work . '/app.log';
$exit = 0;

try {
    expect(mkdir($work, 0700), 'Cannot create temporary test storage.');
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_teacher_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development database named furoeducare_test_teacher_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty teacher test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seed = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seed->run(...));

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $sessions,
        'AUTH_MAIL_DIR' => $mail, 'APP_LOG_FILE' => $errorLog, 'TEACHER_DOCUMENT_DIR' => $files,
    ], $serverLog, function (string $base) use ($db, $mail, $files, $work, $sessions): void {
        $signup = '/register/teacher';
        $verify = '/verify-teacher';
        $dash = '/dashboard/teacher';
        $docs = $dash . '?section=documents';
        $teacher = new AuthBrowser($base);
        $password = 'Teacher fixture password 572!';
        $fields = [
            'category' => 'nysc', 'first_name' => 'Adùnní', 'last_name' => 'Fixture',
            'email' => 'nysc@example.test', 'phone' => '080 1234 5678',
            'password' => $password, 'password_confirmation' => $password, 'terms' => '1', 'privacy' => '1',
        ];
        redirectIs($teacher->send($dash), '/login');
        expect($teacher->send($signup, 'POST', $fields)[0] === 403, 'Signup requires CSRF.');
        [$status, , $body] = $teacher->submit($signup, array_replace($fields, [
            'terms' => '0', 'privacy' => '0', 'category' => 'admin', 'phone' => 'bad', 'password_confirmation' => 'mismatch',
        ]));
        expect($status === 422 && str_contains($body, 'Accept the Terms of Use') && str_contains($body, 'Acknowledge the Privacy Notice')
            && str_contains($body, 'Choose Teacher or NYSC Member') && !str_contains($body, $password),
            'Signup must validate required fields and never echo passwords.');
        redirectIs($teacher->submit($signup, $fields), '/login');
        expect($db->query('SELECT email_verified_at FROM users')->fetchColumn() === null, 'Signup must leave the email unverified.');
        expect((int) $db->query('SELECT COUNT(*) FROM teacher_profiles')->fetchColumn() === 0, 'Signup must not save a profile yet.');
        expect((int) $db->query('SELECT COUNT(*) FROM user_roles')->fetchColumn() === 0, 'Signup must not grant a role before verification.');
        foreach (glob($sessions . '/*') as $sessionFile) {
            expect(!str_contains(file_get_contents($sessionFile), $password), 'Pending drafts must never store plaintext passwords.');
        }
        [$token, $originalMail] = tokenFile($mail, $fields['email'], $verify);
        $db->exec('UPDATE email_verification_tokens SET expires_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND');
        expect($teacher->send($verify . '?token=' . $token)[0] === 422, 'An expired link must not render a registration form.');
        $resend = '/resend-verification?flow=teacher';
        redirectIs($teacher->submit('/resend-verification', ['email' => $fields['email'], 'flow' => 'teacher'], $resend), $resend);
        $activeTokens = $db->query('SELECT COUNT(*) FROM email_verification_tokens WHERE expires_at > CURRENT_TIMESTAMP(6)')->fetchColumn();
        expect((int) $activeTokens === 1, 'A replacement teacher verification link is issued.');
        [$token] = tokenFile($mail, $fields['email'], $verify, [$originalMail]);
        $verification = $verify . '?token=' . $token;
        expect(str_contains($teacher->send($verification)[2], 'confirm_registration'), 'The original signup browser can confirm its draft.');
        expect($db->query('SELECT email_verified_at FROM users')->fetchColumn() === null, 'Opening a link must not verify the email.');
        $anonymous = $teacher->cookie;
        redirectIs($teacher->submit($verify, ['token' => $token, 'confirm_registration' => '1'], $verification), $dash);
        expect($teacher->cookie !== $anonymous, 'Activation must regenerate the session.');
        [$status, $headers, $body] = $teacher->send($dash);
        expect($status === 200 && ($headers['cache-control'] ?? '') === 'no-store'
            && str_contains($body, '1 of 10 sections complete') && str_contains($body, 'NYSC details (optional)'),
            'The owner should reach private onboarding with optional NYSC details.');
        $profile = $db->query('SELECT * FROM teacher_profiles')->fetch();
        expect($profile['category'] === 'nysc' && $profile['phone'] === '+2348012345678'
            && $profile['terms_version'] === TeacherInput::TERMS && $profile['privacy_version'] === TeacherInput::PRIVACY
            && $profile['accepted_at'] !== null && $profile['published_at'] === null,
            'Store normalized contact details, legal revisions, and private publication status.');
        expect(password_verify($password, $db->query('SELECT password_hash FROM users')->fetchColumn()), 'The original browser activates its chosen password.');
        $guest = new AuthBrowser($base);
        redirectIs($guest->submit($signup, array_replace($fields, ['email' => strtoupper($fields['email'])])), '/login');
        expect((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1 && count(glob($mail . '/*.eml')) === 2,
            'A verified account cannot be duplicated or overwritten.');
        echo "PASS validated teacher signup, consent, email activation, session regeneration, and duplicate prevention.\n";

        $other = new AuthBrowser($base);
        $chosen = 'Different browser teacher password 31!';
        $initial = array_replace($fields, ['email' => 'other@example.test', 'category' => 'teacher', 'first_name' => 'Initial']);
        redirectIs($guest->submit($signup, $initial), '/login');
        [$otherToken] = tokenFile($mail, $initial['email'], $verify);
        $otherLink = $verify . '?token=' . $otherToken;
        expect(!str_contains($other->send($otherLink)[2], 'confirm_registration'), 'A fresh browser must reenter registration details.');
        expect($other->submit($verify, ['token' => $otherToken, 'confirm_registration' => '1'], $otherLink)[0] === 422,
            'A different browser cannot activate a saved draft.');
        $otherFields = array_replace($initial, ['first_name' => 'Mailbox', 'password' => $chosen, 'password_confirmation' => $chosen]);
        expect($other->submit($verify, ['token' => $otherToken, 'email' => 'mistyped@example.test'] + $otherFields, $otherLink)[0] === 422,
            'A verification token cannot activate an address different from its intended mailbox.');
        redirectIs($other->submit($verify, ['token' => $otherToken] + $otherFields, $otherLink), $dash);
        $otherProfile = $db->query("SELECT p.first_name, u.password_hash FROM teacher_profiles p JOIN users u ON u.id = p.user_id
            WHERE u.email = 'other@example.test'")->fetch();
        expect($otherProfile['first_name'] === 'Mailbox' && password_verify($chosen, $otherProfile['password_hash'])
            && !password_verify($password, $otherProfile['password_hash']), 'The mailbox owner sets the activated profile and password.');
        expect($guest->send($otherLink)[0] === 422, 'A consumed link does not render a registration form.');
        expect($guest->submit($verify, ['token' => $otherToken, 'confirm_registration' => '1'], '/login')[0] === 422,
            'A completed link cannot activate the original draft.');
        expect(!str_contains($other->send($dash)[2], 'NYSC details (optional)'), 'Teacher accounts omit NYSC questions.');
        $parent = new AuthBrowser($base);
        verifiedAccount($parent, $mail, 'parent', 'parent@example.test', $password);
        redirectIs($parent->submit('/login', ['email' => 'parent@example.test', 'password' => $password]), '/dashboard/parent');
        expect($parent->send($dash)[0] === 403 && $parent->submit($dash . '/profile', ['section' => 'biography'], '/dashboard/parent')[0] === 403,
            'Another role cannot read or write the teacher dashboard.');
        echo "PASS cross-browser activation, single-use links, and role restrictions.\n";

        $teacherId = (int) $profile['user_id'];
        $saving = [
            'personal' => ['first_name' => 'Adùnní', 'last_name' => 'Fixture', 'phone' => '+2348023456789', 'nysc_batch' => 'Batch B', 'nysc_state' => 'Oyo', 'nysc_year' => '2025'],
            'biography' => ['bio' => 'I teach <physics> and use practical demonstrations.'],
            'qualifications' => ['rows' => [
                ['qualification' => 'B.Ed.', 'institution' => 'Fixture University', 'field_of_study' => 'Education', 'completed_year' => '2022'],
                ['qualification' => 'PGDE', 'institution' => 'Fixture Institute'],
            ]],
            'experience' => ['rows' => [
                ['position_title' => 'Teacher', 'organisation' => 'Fixture School', 'started_year' => '2023', 'ended_year' => '2025'],
                ['position_title' => 'Tutor', 'organisation' => 'Fixture Centre', 'started_year' => '2025'],
            ]],
            'subjects' => ['subjects' => "Physics, Mathematics\nBasic Science", 'levels' => 'Junior secondary'],
            'skills' => ['skills' => 'Lesson planning', 'certifications' => 'Safeguarding'],
            'locations' => ['locations' => "Ibadan\nLagos"],
            'preferences' => ['employment' => ['contract'], 'tutoring' => ['online']],
            'availability' => ['status' => 'from_date', 'date' => '2027-01-04'],
        ];
        expect($teacher->send($dash . '/profile', 'POST', ['section' => 'biography', 'bio' => 'Forbidden'])[0] === 403,
            'Section writes require CSRF.');
        redirectIs(saveSection($teacher, 'availability', ['status' => 'from_date']), $dash . '?section=availability');
        expect(str_contains($teacher->send($dash . '?section=availability')[2], '<li>Available from date</li>'), 'Draft availability shows a missing date.');
        redirectIs(saveSection($teacher, 'qualifications', ['rows' => [['qualification' => 'Draft degree']]]), $dash . '?section=qualifications');
        expect(str_contains($teacher->send($dash . '?section=qualifications')[2], 'Entry 1 needs institution.'), 'Partial qualification remains a draft.');
        foreach ($saving as $section => $values) {
            redirectIs(saveSection($teacher, $section, $values), $dash . '?section=' . $section);
        }
        expect((int) $db->query("SELECT COUNT(*) FROM teacher_qualifications WHERE teacher_id = $teacherId")->fetchColumn() === 2
            && (int) $db->query("SELECT COUNT(*) FROM teacher_experiences WHERE teacher_id = $teacherId")->fetchColumn() === 2,
            'Both repeatable sections retain multiple entries.');
        [$status, , $body] = saveSection($teacher, 'experience', ['rows' => [
            ['position_title' => 'Draft', 'organisation' => 'Fixture', 'started_year' => '2024', 'ended_year' => '2023'],
        ]]);
        expect($status === 422 && str_contains($body, 'end year cannot be before the start year')
            && (int) $db->query("SELECT COUNT(*) FROM teacher_experiences WHERE teacher_id = $teacherId")->fetchColumn() === 2,
            'Invalid replacement preserves saved entries.');
        redirectIs(saveSection($other, 'experience', ['no_experience' => '1']), $dash . '?section=experience');
        expect(str_contains($other->send($dash . '?section=experience')[2], '2 of 10 sections complete'), 'No-experience declaration completes the section.');
        $page = $teacher->send($dash . '?section=biography')[2];
        expect(str_contains($page, '9 of 10 sections complete') && str_contains($page, '&lt;physics&gt;')
            && !str_contains($other->send($dash . '?section=biography')[2], 'practical demonstrations'),
            'Saved sections survive independent updates, escape output, and stay owner-scoped.');
        expect(str_contains($teacher->send($dash)[2], 'Batch B'), 'Optional NYSC details persist.');
        echo "PASS all profile fields, progressive draft saving, multiple entries, completion tracking, and profile isolation.\n";

        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        expect(upload($base, $teacher, 'cv', 'resume.pdf', 'plain text')[0] === 422, 'Reject a file whose content does not match its extension.');
        redirectIs(upload($base, $teacher, 'cv', 'resume.pdf', $pdf), $docs);
        $first = $db->query('SELECT id, storage_key FROM teacher_documents')->fetch();
        $download = $dash . '/documents/download?id=' . $first['id'];
        [$status, $headers, $bytes] = $teacher->send($download);
        expect($status === 200 && $bytes === $pdf && ($headers['content-type'] ?? '') === 'application/pdf'
            && ($headers['content-disposition'] ?? '') === 'attachment; filename="resume.pdf"'
            && ($headers['cache-control'] ?? '') === 'no-store', 'CV is a private attachment with valid bytes.');
        expect($other->send($download)[0] === 404 && $parent->send($download)[0] === 403, 'Other accounts cannot download a private CV.');
        redirectIs($guest->send($download), '/login');
        expect($other->submit($dash . '/documents/remove', ['id' => $first['id']], $docs)[0] === 404, 'Another teacher cannot remove the CV.');
        expect(is_file($files . '/' . $first['storage_key'])
            && request($base, '/storage/teacher-documents/' . $first['storage_key'])[0] === 404, 'The stored file has no public URL.');

        $docx = $work . '/fixture.docx';
        $zip = new ZipArchive();
        expect($zip->open($docx, ZipArchive::CREATE) === true, 'Create the DOCX test fixture.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
        $zip->close();
        $docxBytes = file_get_contents($docx);
        redirectIs(upload($base, $teacher, 'cv', 'resume-updated.docx', $docxBytes), $docs);
        $newCvId = $db->query("SELECT id FROM teacher_documents WHERE kind = 'cv'")->fetchColumn();
        [$status, , $downloaded] = $teacher->send($dash . '/documents/download?id=' . $newCvId);
        expect($status === 200 && $downloaded === $docxBytes, 'DOCX attachments preserve binary bytes.');
        expect($teacher->send($download)[0] === 404 && !file_exists($files . '/' . $first['storage_key']), 'Replacing a CV removes the previous version.');
        redirectIs(upload($base, $teacher, 'certification', 'teaching-certificate.pdf', $pdf), $docs);
        expect((int) $db->query('SELECT COUNT(*) FROM teacher_documents')->fetchColumn() === 2, 'The CV and supporting file can coexist.');
        $public = $guest->send('/find-teachers')[2];
        expect(str_contains($teacher->send($docs)[2], '10 of 10 sections complete')
            && (int) $db->query('SELECT COUNT(*) FROM teacher_profiles WHERE published_at IS NOT NULL')->fetchColumn() === 0
            && !str_contains($public, 'Adùnní') && !str_contains($public, 'resume-updated.docx'),
            'A complete profile and original files remain private.');
        $cv = $db->query("SELECT id, storage_key FROM teacher_documents WHERE kind = 'cv'")->fetch();
        redirectIs($teacher->submit($dash . '/documents/remove', ['id' => $cv['id']], $docs), $docs);
        expect(!file_exists($files . '/' . $cv['storage_key'])
            && str_contains($teacher->send($docs)[2], '9 of 10 sections complete'), 'Removing the CV updates completion and deletes its private copy.');
        echo "PASS multipart validation, private documents, ownership, replacement, deletion, and non-publication.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    foreach ([$errorLog, $serverLog] as $log) {
        if (is_file($log)) {
            fwrite(STDERR, implode('', array_slice(file($log), -16)));
        }
    }
    $exit = 1;
} finally {
    foreach ([$mail, $sessions, $files] as $directory) {
        if (is_dir($directory)) {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    foreach ([$errorLog, $serverLog, $work . '/fixture.docx'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($work)) {
        rmdir($work);
    }
}
exit($exit);

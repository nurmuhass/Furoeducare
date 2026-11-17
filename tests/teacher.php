<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Teacher\TeacherInput;
use Furoeducare\Modules\Teacher\DashboardPage;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/cv-fixtures.php';

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

function runCvWorker(string $directory): void
{
    $arguments = [PHP_BINARY];
    if (php_ini_loaded_file() !== false) {
        array_push($arguments, '-c', php_ini_loaded_file());
    }
    array_push($arguments, 'bin/cv-worker.php', '--once');
    $log = $directory . '/worker.log';
    $process = proc_open($arguments, [
        0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a'],
    ], $pipes, dirname(__DIR__), array_replace(getenv(), ['TEACHER_DOCUMENT_DIR' => $directory . '/documents']));
    expect(is_resource($process), 'Cannot launch the extraction worker.');
    fclose($pipes[0]);
    $exit = proc_close($process);
    $output = file_get_contents($log);
    unlink($log);
    expect($exit === 0 && str_contains($output, 'CV extraction jobs processed: 1'), 'The worker must process one queued job. ' . $output);
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
            && str_contains($body, '1 of 10 sections complete')
            && str_contains($teacher->send($dash . '?section=personal')[2], 'NYSC details (optional)'),
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
        expect(!str_contains($other->send($dash . '?section=personal')[2], 'NYSC details (optional)'), 'Teacher accounts omit NYSC questions.');
        $parent = new AuthBrowser($base);
        verifiedParent($parent, $mail, 'parent@example.test', $password);
        redirectIs($parent->submit('/login', ['email' => 'parent@example.test', 'password' => $password]), '/dashboard/parent');
        expect($parent->send($dash)[0] === 403 && $parent->submit($dash . '/profile', ['section' => 'biography'], '/dashboard/parent')[0] === 403,
            'Another role cannot read or write the teacher dashboard.');
        echo "PASS cross-browser activation, single-use links, and role restrictions.\n";

        $teacherId = (int) $profile['user_id'];
        $otherId = (int) $db->query("SELECT user_id FROM teacher_profiles WHERE first_name = 'Mailbox'")->fetchColumn();
        $availabilityPage = $dash . '?tab=availability';
        $personalPage = $dash . '?tab=profile&section=personal';
        foreach (DashboardPage::cases() as $tab) {
            [$tabStatus, , $tabBody] = $teacher->send($tab->url());
            expect($tabStatus === 200 && str_contains($tabBody, '<h1>' . $tab->label() . '</h1>')
                && str_contains($tabBody, 'aria-label="Teacher dashboard navigation"'), 'Every dashboard tab must load with its heading.');
        }
        expect(str_contains($teacher->send(DashboardPage::Jobs->url())[2], 'href="/teaching-jobs"')
            && str_contains($teacher->send(DashboardPage::Applications->url())[2], 'No applications on this page.'),
            'The recruitment tabs link to the job board and show a private empty application list.');
        expect(str_contains($teacher->send(DashboardPage::SavedJobs->url())[2], 'No saved jobs on this page.'),
            'The new teacher sees an empty Saved Jobs tab.');
        foreach ([DashboardPage::Placements, DashboardPage::Library, DashboardPage::Messages] as $tab) {
            expect(str_contains($teacher->send($tab->url())[2], 'Not available yet'), 'Pending features must have informational pages.');
        }
        expect(str_contains($teacher->send(DashboardPage::Notifications->url())[2], 'No verification updates yet.'),
            'An account without verification events sees the notifications empty state.');
        foreach (['Total applications', 'Active applications', 'Interview invitations', 'Placement updates', 'Recent notifications'] as $metric) {
            expect(str_contains($body, $metric), "Overview must include $metric.");
        }
        expect(substr_count($body, '<dd>0</dd>') === 3
            && str_contains($body, 'Incomplete') && str_contains($body, 'CV needed'),
            'Overview must show actual application counts and profile and CV states.');

        $db->prepare('UPDATE teacher_profiles SET professional_verified_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?')->execute([$teacherId]);
        expect(!str_contains($teacher->send($dash)[2], 'Furoeducare has verified your professional profile.'),
            'A legacy timestamp alone cannot mark the professional review verified.');
        expect(!str_contains($teacher->send($personalPage . '&user_id=' . $otherId)[2], 'other@example.test')
            && !str_contains($other->send($personalPage . '&user_id=' . $teacherId)[2], 'nysc@example.test'),
            'Changing a URL identifier cannot read another teacher profile.');

        $forged = [
            'section' => 'personal', 'user_id' => $teacherId, 'first_name' => 'Mailbox', 'last_name' => 'Only own changes',
            'phone' => '08055555555', 'professional_verified_at' => '2026-11-01 00:00:00', 'published_at' => '2026-11-01 00:00:00',
        ];
        redirectIs($other->submit($dash . '/profile', $forged, $personalPage), $dash . '?section=personal');
        $protected = $db->query("SELECT user_id, last_name, professional_verified_at, published_at FROM teacher_profiles ORDER BY user_id")->fetchAll();
        expect($protected[0]['last_name'] === 'Fixture' && $protected[0]['professional_verified_at'] !== null
            && $protected[1]['last_name'] === 'Only own changes' && $protected[1]['professional_verified_at'] === null
            && $protected[1]['published_at'] === null, 'Teachers can update only their own editable profile fields.');
        $authBefore = $db->query('SELECT COUNT(*) FROM teacher_profiles')->fetchColumn();
        redirectIs($guest->send($dash . '/profile', 'POST', $forged), '/login');
        expect($parent->submit($dash . '/profile', $forged, '/dashboard/parent')[0] === 403
            && $db->query('SELECT COUNT(*) FROM teacher_profiles')->fetchColumn() === $authBefore,
            'Anonymous and non-teacher updates are denied.');
        expect($teacher->send($dash . '/profile', 'POST', ['section' => 'availability', 'availability_preferences' => '1'])[0] === 403,
            'Availability updates must require CSRF.');

        $availabilityFields = ['section' => 'availability', 'availability_preferences' => '1'];
        redirectIs(saveSection($teacher, 'availability', ['status' => 'now']), $dash . '?section=availability');
        expect(str_contains($teacher->send($availabilityPage)[2], 'Available now is already saved.'),
            'A legacy availability status should be visible without inventing preferences.');
        redirectIs($teacher->submit($dash . '/profile', $availabilityFields, $availabilityPage), $dash . '?section=availability');
        expect(str_contains($teacher->send($availabilityPage)[2], 'Available now is already saved.'),
            'Submitting an unchanged form should preserve legacy availability.');
        $modes = ['full_time', 'part_time', 'private_tutoring', 'online_tutoring'];
        redirectIs($teacher->submit($dash . '/profile', $availabilityFields + ['opportunities' => $modes, 'user_id' => $otherId], $availabilityPage),
            $dash . '?section=availability');
        $optionBody = $teacher->send($availabilityPage)[2];
        expect(substr_count($optionBody, 'checked') === 4 && !str_contains($other->send($availabilityPage)[2], 'checked'),
            'Compatible availability preferences persist for their owner only.');
        expect($teacher->submit($dash . '/profile', $availabilityFields + ['opportunities' => ['unavailable', 'full_time']], $availabilityPage)[0] === 422
            && substr_count($teacher->send($availabilityPage)[2], 'checked') === 4,
            'A conflicting save must leave the existing preferences unchanged.');
        redirectIs($other->submit($dash . '/profile', $availabilityFields + ['opportunities' => ['unavailable']], $availabilityPage),
            $dash . '?section=availability');
        expect(preg_match('/id="availability-unavailable"[^>]*\bchecked\b/', $other->send($availabilityPage)[2]) === 1
            && substr_count($teacher->send($availabilityPage)[2], 'checked') === 4,
            'Temporarily unavailable persists independently of another account.');
        echo "PASS dashboard sections, accurate overview, ownership, protected-field updates, and availability persistence.\n";

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
        expect(str_contains($other->send($dash . '?section=experience')[2], '3 of 10 sections complete'), 'No-experience declaration completes the section.');
        $page = $teacher->send($dash . '?section=biography')[2];
        expect(str_contains($page, '9 of 10 sections complete') && str_contains($page, '&lt;physics&gt;')
            && !str_contains($other->send($dash . '?section=biography')[2], 'practical demonstrations'),
            'Saved sections survive independent updates, escape output, and stay owner-scoped.');
        expect(str_contains($teacher->send($dash . '?section=personal')[2], 'Batch B'), 'Optional NYSC details persist.');
        echo "PASS all profile fields, progressive draft saving, multiple entries, completion tracking, and profile isolation.\n";

        $cvPage = $dash . '/cv';
        $lines = ['Professional Summary', 'CV bio that must not replace the saved one.', 'Work History',
            'Teacher at Original CV School | 2016 - 2018', 'Tutor at Separate Learning Centre | 2019 - Present',
            'Education', 'NCE | Fixture College | 2015', 'Skills', 'Assessment', 'Subjects', 'Biology',
            'Certifications', 'First aid', 'Child safeguarding'];
        $pdf = sampleCvPdf($lines);
        expect(upload($base, $teacher, 'cv', 'resume.pdf', 'plain text')[0] === 422, 'Reject a file whose content does not match its extension.');
        expect(upload($base, $teacher, 'cv', 'truncated.pdf', "%PDF-1.4\nmissing ending")[0] === 422,
            'Reject a PDF whose content fails the envelope check.');
        expect((int) $db->query('SELECT COUNT(*) FROM teacher_cv_extractions')->fetchColumn() === 0, 'Invalid uploads cannot queue a job.');

        redirectIs(upload($base, $teacher, 'cv', 'scan.pdf', sampleCvPdf([], true)), $docs);
        runCvWorker($work);
        expect(str_contains($teacher->send($docs)[2], 'OCR is not yet supported')
            && str_contains($teacher->send($docs)[2], 'continue with manual profile editing'),
            'A scanned CV keeps its original and explains the manual fallback.');

        redirectIs(upload($base, $teacher, 'cv', 'resume.pdf', $pdf), $docs);
        $first = $db->query('SELECT id, storage_key FROM teacher_documents')->fetch();
        expect(str_contains($teacher->send($docs)[2], 'Waiting for extraction.'), 'A valid upload queues extraction.');
        runCvWorker($work);
        [$status, $headers, $body] = $teacher->send($cvPage);
        expect($status === 200 && ($headers['cache-control'] ?? '') === 'no-store'
            && str_contains($body, 'Original CV School') && str_contains($body, 'Separate Learning Centre')
            && str_contains($body, 'Check accuracy') && str_contains($body, 'placeholder="Missing"'),
            'The owner sees editable suggestions with explicit review and missing-field hints.');
        redirectIs($other->send($cvPage), $docs);
        expect($parent->send($cvPage)[0] === 403, 'Parents cannot open teacher extraction drafts.');
        redirectIs($guest->send($cvPage), '/login');
        expect((int) $db->query("SELECT COUNT(*) FROM teacher_experiences WHERE teacher_id = $teacherId")->fetchColumn() === 2,
            'Extraction must not change existing profile records before confirmation.');

        $review = [
            'document_id' => $first['id'],
            'summary' => 'New bio from CV',
            'qualifications' => [['qualification' => 'NCE', 'institution' => 'Fixture College', 'completed_year' => '2015']],
            'experience' => [
                ['position_title' => 'Teacher', 'organisation' => 'Corrected CV School', 'started_year' => '2016', 'ended_year' => '2018'],
                ['position_title' => 'Tutor', 'organisation' => 'Separate Learning Centre', 'started_year' => '2019'],
            ],
            'skills' => "Assessment\nLesson planning",
            'subjects' => 'Biology',
            'certifications' => "First aid\nChild safeguarding",
        ];
        expect($teacher->send($cvPage, 'POST', $review + ['confirmed' => '1'])[0] === 403, 'CV confirmation requires CSRF.');
        expect($teacher->submit($cvPage, $review)[0] === 422, 'CV import requires explicit confirmation.');
        redirectIs($other->submit($cvPage, $review + ['confirmed' => '1'], $docs), $docs);
        redirectIs($teacher->submit($cvPage, $review + ['confirmed' => '1']), $docs);

        $stored = json_decode($db->query("SELECT sections FROM teacher_profiles WHERE user_id = $teacherId")->fetchColumn(), true);
        expect($stored['biography']['bio'] === 'I teach <physics> and use practical demonstrations.'
            && $stored['subjects']['levels'] === ['Junior secondary'] && in_array('Biology', $stored['subjects']['subjects'], true)
            && $stored['skills']['certifications'] === ['Safeguarding', 'First aid', 'Child safeguarding']
            && $stored['skills']['skills'] === ['Lesson planning', 'Assessment'],
            'Reviewed suggestions add missing data while keeping saved biography, levels, skills and certifications.');
        expect((int) $db->query("SELECT COUNT(*) FROM teacher_experiences WHERE teacher_id = $teacherId")->fetchColumn() === 4
            && (int) $db->query("SELECT COUNT(*) FROM teacher_experiences WHERE organisation = 'Corrected CV School'")->fetchColumn() === 1
            && (int) $db->query("SELECT COUNT(*) FROM teacher_qualifications WHERE teacher_id = $teacherId")->fetchColumn() === 3,
            'Apply edited rather than raw suggestions and retain existing repeatable rows.');
        $finished = $db->query('SELECT status, draft FROM teacher_cv_extractions')->fetch();
        expect($finished === ['status' => 'applied', 'draft' => null], 'Confirmation clears the transient JSON.');
        redirectIs($teacher->submit($cvPage, $review + ['confirmed' => '1'], $docs), $docs);
        expect((int) $db->query("SELECT COUNT(*) FROM teacher_experiences WHERE teacher_id = $teacherId")->fetchColumn() === 4,
            'A repeated confirmation cannot duplicate saved entries.');
        echo "PASS queued CV extraction, manual scan fallback, owner-only review, confirmed edits and preservation of saved fields.\n";
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
        sampleCvDocx($docx, ['Experience', 'Tutor at Alternate School | 2021 - Present']);
        $docxBytes = file_get_contents($docx);
        redirectIs(upload($base, $teacher, 'cv', 'resume-updated.docx', $docxBytes), $docs);
        $newCvId = $db->query("SELECT id FROM teacher_documents WHERE kind = 'cv'")->fetchColumn();
        runCvWorker($work);
        expect(str_contains($teacher->send($cvPage)[2], 'Alternate School'), 'The replacement DOCX provides a new draft.');
        redirectIs($teacher->submit($cvPage, $review + ['confirmed' => '1'], $docs), $docs);
        expect(str_contains($teacher->send($docs)[2], 'That extraction draft is no longer available.'), 'An old form cannot apply a replacement CV draft.');
        $db->exec("UPDATE teacher_cv_extractions SET expires_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 MINUTE WHERE document_id = " . (int) $newCvId);
        redirectIs($teacher->send($cvPage), $docs);
        expect(str_contains($teacher->send($docs)[2], 'extraction draft expired'), 'An expired draft cannot be opened.');
        [$status, , $downloaded] = $teacher->send($dash . '/documents/download?id=' . $newCvId);
        expect($status === 200 && $downloaded === $docxBytes, 'DOCX attachments preserve binary bytes.');
        expect($teacher->send($download)[0] === 404 && !file_exists($files . '/' . $first['storage_key']), 'Replacing a CV removes the previous version.');
        redirectIs(upload($base, $teacher, 'certification', 'teaching-certificate.pdf', $pdf), $docs);
        expect((int) $db->query('SELECT COUNT(*) FROM teacher_documents')->fetchColumn() === 2, 'The CV and supporting file can coexist.');
        $public = $guest->send('/find-teachers')[2];
        expect(str_contains($teacher->send($docs)[2], '10 of 10 sections complete')
            && str_contains($teacher->send($dash)[2], 'CV on file')
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

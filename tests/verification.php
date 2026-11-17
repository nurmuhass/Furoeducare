<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Teacher\Section;
use Furoeducare\Modules\Teacher\TeacherRepository;
use Furoeducare\Modules\Verification\TeacherEligibility;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-verification-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_verification_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback database named furoeducare_test_verification_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty verification test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seed = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seed->run(...));
    expect(mkdir($work, 0700) && mkdir($work . '/documents', 0700), 'Create private fixture directories.');
    $password = 'Verification fixture passphrase 712!';
    $account = static function (string $name, string $role, bool $verified = true) use ($db, $password): int {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, IF(?, CURRENT_TIMESTAMP(6), NULL))')
            ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT), (int) $verified]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        return $id;
    };
    $staff = $account('staff', 'admin');
    $id = $account('teacher', 'teacher');
    $other = $account('other', 'teacher');
    $profiles = new TeacherRepository(static fn (): PDO => $db);
    $profiles->create($id, ['category' => 'teacher', 'first_name' => 'Sade', 'last_name' => 'Fixture', 'phone' => '+2348012345678']);
    $profiles->create($other, ['category' => 'teacher', 'first_name' => 'Other', 'last_name' => 'Fixture', 'phone' => '+2348012345679']);
    $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'school'")->execute([$id]);
    $schools = new SchoolRepository(static fn (): PDO => $db);
    $schools->create($id, schoolFields('teacher@example.test', $password) + ['representative_email' => 'teacher@example.test']);
    $complete = static function () use ($profiles, $id): void {
        $profiles->save($id, Section::Biography, ['bio' => 'Secondary mathematics educator.']);
        $profiles->save($id, Section::Qualifications, ['rows' => [
            ['qualification' => 'BEd', 'institution' => 'Fixture University', 'field_of_study' => 'Education', 'completed_year' => 2020],
        ]]);
        $profiles->save($id, Section::Experience, ['rows' => [], 'no_experience' => true]);
        $profiles->save($id, Section::Subjects, ['subjects' => ['Mathematics'], 'levels' => ['Secondary']]);
        $profiles->save($id, Section::Skills, ['skills' => ['Lesson planning']]);
        $profiles->save($id, Section::Locations, ['locations' => ['Lagos']]);
        $profiles->save($id, Section::Preferences, ['employment' => ['full_time']]);
        $profiles->save($id, Section::Availability, ['status' => 'now', 'opportunities' => ['full_time']]);
    };
    $upload = static function (string $name) use ($work, $profiles, $id, $db): int {
        $key = bin2hex(random_bytes(32));
        $bytes = '%PDF-1.4 verification fixture';
        file_put_contents($work . '/documents/' . $key, $bytes);
        $profiles->addDocument($id, 'cv', $key, $name, 'application/pdf', strlen($bytes));
        return (int) $db->query('SELECT MAX(id) FROM teacher_documents')->fetchColumn();
    };
    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail',
        'APP_LOG_FILE' => $work . '/app.log', 'TEACHER_DOCUMENT_DIR' => $work . '/documents',
    ], $work . '/server.log', function (string $base) use ($db, $profiles, $password, $id, $other, $staff, $complete, $upload, $work): void {
        $admin = new AuthBrowser($base);
        $teacher = new AuthBrowser($base);
        $anon = new AuthBrowser($base);
        $login = static fn (AuthBrowser $browser, string $name) => $browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]);
        redirectIs($login($admin, 'staff'), '/dashboard/admin');
        redirectIs($login($teacher, 'teacher'), '/dashboard/teacher');
        $dash = '/dashboard/teacher';
        $submit = $dash . '/verification/submit';
        $queues = '/dashboard/admin/verification';
        $teachers = $queues . '/teachers';
        $documents = $queues . '/documents';
        $corrections = $queues . '/corrections';
        $detail = $teachers . '?id=' . $id;
        foreach ([$queues, $teachers, $documents, $corrections, '/dashboard/admin/schools'] as $path) {
            redirectIs($anon->send($path), '/login');
            expect($teacher->send($path)[0] === 403, 'Teachers cannot read administrator queues.');
            expect($admin->send($path)[0] === 200, 'Administrators can open each queue.');
        }
        expect($teacher->submit($submit, [], $dash)[0] === 422, 'An incomplete profile cannot enter the professional queue.');
        $eligibility = new TeacherEligibility(static fn (): PDO => $db, $profiles);
        $placement = static function () use ($db, $eligibility, $id): bool {
            try {
                Database::transaction($db, static fn () => $eligibility->requireForPlacement($id));
                return true;
            } catch (DomainException) {
                return false;
            }
        };
        expect(!$eligibility->canApply($id) && !$placement(), 'An incomplete profile cannot apply or be placed.');
        $complete();
        $cv = $upload('review-cv.pdf');
        $db->prepare("UPDATE teacher_cv_extractions SET status = 'ready', expires_at = CURRENT_TIMESTAMP(6) + INTERVAL 7 DAY WHERE document_id = ?")->execute([$cv]);
        expect($eligibility->canApply($id) && !$placement(), 'A complete, email-verified teacher can apply before verification.');
        $db->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')->execute([$id]);
        expect(!$eligibility->canApply($id), 'Applications require a verified email.');
        $db->prepare('UPDATE users SET email_verified_at = CURRENT_TIMESTAMP(6) WHERE id = ?')->execute([$id]);
        redirectIs($teacher->submit($submit, ['id' => $other], $dash), $dash);
        expect(str_contains($admin->send($teachers)[2], 'Sade Fixture'), 'A submitted profile appears in the pending teacher queue.');
        expect($teacher->submit($submit, [], $dash)[0] === 422, 'A pending profile cannot be submitted twice.');
        $revision = static fn (): int => (int) $db->query('SELECT verification_revision FROM teacher_profiles WHERE user_id = ' . $id)->fetchColumn();
        $decision = static fn (string $status, array $checks = [], ?int $version = null): array
            => $admin->submit($teachers . '/review', [
                'id' => $id, 'revision' => $version ?? $revision(), 'decision' => $status,
                'reason' => 'Please review <fixture evidence>.', 'checks' => $checks,
            ], $detail);
        $checks = ['identity', 'qualifications', 'professional_history'];
        expect($admin->send($teachers . '/review', 'POST', ['id' => $id])[0] === 403, 'Staff decisions require CSRF.');
        expect($decision('verified', $checks)[0] === 422, 'A teacher cannot be verified before the current CV review.');
        expect(str_contains($admin->send($documents)[2], 'review-cv.pdf'), 'An uploaded CV enters the document review queue.');
        $file = $documents . '?id=' . $cv;
        $download = $documents . '/download?id=' . $cv;
        expect($teacher->send($download)[0] === 403, 'The administrative file endpoint requires administrator privileges.');
        [$status, $headers, $bytes] = $admin->send($download);
        expect($status === 200 && ($headers['cache-control'] ?? '') === 'no-store'
            && str_starts_with($headers['content-disposition'] ?? '', 'attachment;') && str_starts_with($bytes, '%PDF'),
            'Only an authenticated administrator can retrieve the private attachment.');
        $docFields = ['id' => $cv, 'decision' => 'accepted', 'reason' => 'CV reviewed.'];
        $beforeDocumentDecision = $revision();
        redirectIs($admin->submit($documents . '/review', $docFields, $file), $file);
        expect($decision('verified', $checks, $beforeDocumentDecision)[0] === 409, 'A document decision invalidates an open professional review.');
        expect($admin->submit($documents . '/review', $docFields, $file)[0] === 422, 'A document cannot receive a duplicate decision.');
        expect($decision('verified', ['identity'])[0] === 422, 'Verification requires all checks.');
        expect($decision('verified', [...$checks, 'unexpected'])[0] === 422, 'Unknown checks cannot be recorded.');
        $old = $revision();
        redirectIs($decision('corrections_required'), $detail);
        expect(str_contains($admin->send($corrections)[2], 'Sade Fixture'), 'Requested corrections appear in a separate queue.');
        expect($decision('verified', $checks, $old)[0] === 409, 'A stale decision cannot bypass the correction process.');
        expect($decision('verified', $checks)[0] === 422 && !$placement(), 'A correction request cannot transition directly to verified.');
        expect(str_contains($teacher->send($dash . '?tab=notifications')[2], '&lt;fixture evidence&gt;'),
            'Teachers see their own correction notes safely escaped.');
        $visitor = new AuthBrowser($base);
        redirectIs($login($visitor, 'other'), $dash);
        expect(!str_contains($visitor->send($dash . '?tab=notifications&id=' . $id)[2], 'fixture evidence'),
            'Another teacher cannot read the recipient’s review notes.');
        $version = $revision();
        expect($teacher->submit($submit, [], $dash)[0] === 422, 'A correction requires an updated profile or document.');
        $profiles->save($id, Section::Biography, ['bio' => 'Corrected biography for review.']);
        redirectIs($teacher->submit($submit, [], $dash), $dash);
        expect($decision('verified', $checks, $version)[0] === 409, 'Reviewing an obsolete profile version must fail.');
        redirectIs($decision('verified', $checks), $detail);
        expect($placement(), 'A verified current profile with recorded checks is eligible for placement.');
        $profiles->save($id, Section::Availability, ['status' => 'unavailable', 'opportunities' => ['unavailable']]);
        expect($placement(), 'A scheduling change that keeps the profile complete preserves professional verification.');
        expect(!$profiles->profile($id)['published_at'], 'Verification does not publish a private profile.');
        $cv2 = $upload('replacement.pdf');
        expect(!$placement() && $eligibility->canApply($id), 'A replacement CV reopens verification and preserves application eligibility.');
        expect($admin->send($download)[0] === 404, 'The removed CV cannot be downloaded through staff routes.');
        foreach ($profiles->pendingDocumentKeys($id) as $key) {
            unlink($work . '/documents/' . $key);
            $profiles->finishDocumentRemoval($id, $key);
        }
        expect(str_contains($admin->send($detail)[2], 'CV reviewed.'), 'Attributable review notes survive deletion of the original file.');
        $file2 = $documents . '?id=' . $cv2;
        redirectIs($admin->submit($documents . '/review', ['id' => $cv2, 'decision' => 'rejected', 'reason' => 'Replace the file.'], $file2), $file2);
        expect($decision('verified', $checks)[0] === 422, 'A rejected current CV blocks verification.');
        redirectIs($decision('suspended'), $detail);
        expect(!$eligibility->canApply($id) && !$placement(), 'A suspended teacher cannot apply or be placed.');
        redirectIs($teacher->send($dash), '/login');
        expect($decision('verified', $checks)[0] === 422, 'A suspended teacher must be reopened before verification.');
        $schoolPage = '/dashboard/admin/schools?id=' . $id;
        $schoolReview = static fn (string $status): array => $admin->submit('/dashboard/admin/schools/review', [
            'id' => $id, 'revision' => $db->query('SELECT revision FROM school_onboarding WHERE user_id = ' . $id)->fetchColumn(),
            'decision' => $status, 'reason' => 'School account decision.',
        ], $schoolPage);
        redirectIs($schoolReview('suspended'), $schoolPage);
        redirectIs($decision('pending'), $detail);
        expect(!$eligibility->canApply($id) && $login($teacher, 'teacher')[0] === 403,
            'Reopening teacher verification preserves a separate school suspension.');
        redirectIs($schoolReview('rejected'), $schoolPage);
        redirectIs($login($teacher, 'teacher'), $dash);
        expect(!$placement(), 'Reopening a suspension requires a new verification decision.');
        $audit = $db->query("SELECT actor_user_id, context FROM audit_logs WHERE action = 'verification.teacher.review'")->fetchAll();
        expect(count($audit) === 4 && (int) $audit[0]['actor_user_id'] === $staff
            && !str_contains($audit[0]['context'], 'fixture evidence'), 'Audit history includes valid actor and transitions and excludes private notes.');
        expect((int) $db->query('SELECT COUNT(*) FROM verification_notifications WHERE user_id = ' . $id)->fetchColumn() >= 6
            && (int) $db->query('SELECT COUNT(*) FROM verification_notifications WHERE user_id = ' . $other)->fetchColumn() === 0,
            'Successful decisions emit durable, recipient-scoped notification events.');
        echo "PASS administrator authorization, queues, private documents, teacher transitions, notifications and placement gate.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Test artifacts, if created, are in ' . $work . PHP_EOL);
    $exit = 1;
} finally {
    if ($exit === 0 && is_dir($work)) {
        foreach ([$work . '/documents', $work . '/sessions', $work . '/mail', $work] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob($directory . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
}
exit($exit);

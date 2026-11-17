<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Verification\ReviewInput;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-vacancies-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_vacancies_[a-z0-9_]+\z/', $config->databaseName) === 1, 'Use an isolated vacancy test schema.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    $password = 'Vacancy fixture passphrase 619!';
    $account = static function (string $name, string $role, bool $verified = true) use ($db, $password): int {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, IF(?, CURRENT_TIMESTAMP(6), NULL))')
            ->execute([$name . '@example.test', password_hash($password, PASSWORD_DEFAULT), (int) $verified]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        return $id;
    };
    $staffId = $account('staff', 'admin');
    $teacher = $account('teacher', 'teacher');
    $schoolRepo = new SchoolRepository(static fn (): PDO => $db);
    foreach (['school', 'other', 'pending'] as $name) {
        $school = $account($name, 'school');
        $schoolRepo->create($school, schoolFields($name . '@example.test', $password, ['school_name' => ucfirst($name) . ' Academy']));
        if ($name !== 'pending') {
            $db->prepare("UPDATE school_onboarding SET review_status = 'approved' WHERE user_id = ?")->execute([$school]);
        }
    }
    $placementTeacher = $account('placement', 'teacher');
    $incomplete = $account('incomplete', 'teacher');
    $unverified = $account('unverified', 'teacher', false);
    foreach ([$teacher, $placementTeacher, $incomplete, $unverified] as $id) {
        $complete = $id !== $incomplete;
        $sections = $complete ? [
            'biography' => ['bio' => 'Private biography fixture.'], 'experience' => ['no_experience' => true],
            'personal' => ['address' => 'Private residential fixture', 'reference' => 'Private referee fixture'],
            'subjects' => ['subjects' => 'Science', 'levels' => 'Primary'], 'skills' => ['skills' => 'Lesson planning'],
            'locations' => ['locations' => 'Ikeja'], 'preferences' => ['employment' => ['full_time']],
            'availability' => ['status' => 'now'],
        ] : [];
        $db->prepare("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone,
            terms_version, privacy_version, accepted_at, sections, verification_status)
            VALUES (?, 'teacher', 'Synthetic', 'Educator', '+2348012345678', 'test', 'test', CURRENT_TIMESTAMP(6), ?, 'pending')")
            ->execute([$id, json_encode($sections, JSON_THROW_ON_ERROR)]);
        if ($complete) {
            $db->prepare("INSERT INTO teacher_qualifications (teacher_id, qualification, institution, field_of_study)
                VALUES (?, 'B.Ed', 'Test College', 'Science')")->execute([$id]);
            $db->prepare("INSERT INTO teacher_documents (teacher_id, kind, storage_key, display_name, media_type, bytes)
                VALUES (?, 'cv', ?, 'fixture.pdf', 'application/pdf', 100)")->execute([$id, bin2hex(random_bytes(32))]);
        }
    }

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log',
    ], $work . '/server.log', static function (string $base) use ($db, $password, $teacher, $placementTeacher, $staffId): void {
        $school = '/dashboard/school/vacancies';
        $admin = '/dashboard/admin/vacancies';
        $jobs = '/teaching-jobs';
        $applications = '/dashboard/teacher?tab=applications';
        $login = static function (string $name, ?string $path = null) use ($base, $password): AuthBrowser {
            $browser = new AuthBrowser($base);
            $response = $browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]);
            if ($path !== null) {
                redirectIs($response, $path);
            }
            return $browser;
        };
        $owner = $login('school', '/dashboard/school');
        $other = $login('other', '/dashboard/school');
        $pending = $login('pending', '/dashboard/school');
        $staff = $login('staff', '/dashboard/admin');
        $guest = new AuthBrowser($base);
        expect($owner->send($school)[0] === 200, 'Schools can open the vacancy workspace.');
        expect($guest->send($jobs)[0] === 200 && str_contains($guest->send($jobs)[2], 'data-empty-state'), 'Guests can browse an empty board.');
        $fields = [
            'title' => 'Primary Science Teacher', 'subjects' => 'Science, Mathematics', 'teaching_level' => 'Primary',
            'qualification' => 'B.Ed', 'experience' => '2', 'employment_type' => 'full_time',
            'state_code' => 'LA', 'city' => 'Ikeja', 'description' => 'Teach practical science lessons.',
            'responsibilities' => 'Plan lessons and assess progress.', 'skills' => 'Classroom management',
            'salary_min' => '90000', 'salary_max' => '150000', 'positions' => '2',
            'deadline' => (new DateTimeImmutable('tomorrow', new DateTimeZone('Africa/Lagos')))->format('Y-m-d'),
        ];
        expect($owner->submit($school, ['action' => 'save', 'salary_min' => '160000'] + $fields)[0] === 422, 'Reject inverted pay range.');
        redirectIs($pending->submit($school, ['action' => 'save'] + $fields), $school . '?id=1');
        expect($pending->submit($school, ['id' => '1', 'revision' => '1', 'action' => 'submit'], $school . '?id=1')[0] === 403, 'Pending schools cannot submit.');
        redirectIs($owner->submit($school, ['action' => 'save'] + $fields), $school . '?id=2');
        $own = $school . '?id=2';
        $review = $admin . '?id=2';
        $job = $jobs . '/view?id=2';
        expect($other->send($own)[0] === 404, 'Other schools cannot read the vacancy.');
        expect($other->submit($school, ['id' => '2', 'revision' => '1', 'action' => 'submit'])[0] === 404, 'Other schools cannot mutate it.');
        expect($guest->send($job)[0] === 404, 'Drafts are private.');
        expect($owner->submit($school, ['id' => '2', 'revision' => '1', 'action' => 'publish'], $own)[0] === 422, 'The school cannot publish.');
        redirectIs($owner->submit($school, ['id' => '2', 'revision' => '1', 'action' => 'submit'], $own), $own);
        expect($owner->send($review)[0] === 403, 'Moderation requires an administrator.');
        expect($staff->submit($admin, ['id' => '2', 'revision' => '2', 'action' => 'publish'], $review)[0] === 409, 'A submitted vacancy cannot skip review.');
        redirectIs($staff->submit($admin, ['id' => '2', 'revision' => '2', 'action' => 'start_review'], $review), $review);
        expect($staff->submit($admin, ['id' => '2', 'revision' => '3', 'action' => 'reject'], $review)[0] === 422, 'A rejection requires a reason.');
        redirectIs($staff->submit($admin, ['id' => '2', 'revision' => '3', 'action' => 'approve'], $review), $review);
        expect($guest->send($job)[0] === 404, 'Approval alone does not publish.');
        redirectIs($staff->submit($admin, ['id' => '2', 'revision' => '4', 'action' => 'publish'], $review), $review);
        expect($guest->send($job)[0] === 200 && str_contains($guest->send($jobs)[2], $fields['title']), 'Published vacancies are visible to guests.');
        expect(!str_contains($guest->send($job)[2], 'school@example.test'), 'Public jobs do not disclose representative contacts.');
        $db->exec("UPDATE school_onboarding SET school_name = 'Renamed Academy' WHERE user_id = (SELECT school_id FROM vacancies WHERE id = 2)");
        expect(str_contains($guest->send($job)[2], 'School Academy') && !str_contains($guest->send($job)[2], 'Renamed Academy'),
            'Changing a school display name does not rewrite an already published vacancy.');
        echo "PASS vacancy ownership, school approval, validation and management publication.\n";

        $apply = $jobs . '/apply';
        $candidate = $login('teacher', '/dashboard/teacher');
        $partial = $login('incomplete', '/dashboard/teacher');
        $unverified = $login('unverified');
        $applyFields = ['id' => '2', 'revision' => '5'];
        $savedJobs = '/dashboard/teacher?tab=saved-jobs';
        $bookmarkRoute = '/dashboard/teacher/saved-jobs';
        $bookmark = ['id' => '2', 'action' => 'save'];
        redirectIs($guest->send($bookmarkRoute, 'POST', $bookmark), '/login');
        expect($owner->submit($bookmarkRoute, $bookmark, $own)[0] === 403
            && $candidate->send($bookmarkRoute, 'POST', $bookmark)[0] === 403, 'Saving a job requires a teacher account and CSRF.');
        redirectIs($candidate->submit($bookmarkRoute, $bookmark, $job), $savedJobs);
        redirectIs($candidate->submit($bookmarkRoute, $bookmark, $job), $savedJobs);
        expect((int) $db->query('SELECT COUNT(*) FROM saved_jobs')->fetchColumn() === 1, 'Saving the same job twice is idempotent.');
        expect(!str_contains($partial->send($savedJobs . '&teacher_id=' . $teacher)[2], $fields['title']), 'Saved jobs are private.');
        redirectIs($partial->submit($bookmarkRoute, ['action' => 'remove', 'teacher_id' => $teacher] + $bookmark, $savedJobs), $savedJobs);
        expect(str_contains($candidate->send($savedJobs)[2], $fields['title']), 'A teacher cannot remove another teacher’s saved job.');
        redirectIs($unverified->send($apply, 'POST', $applyFields), '/login');
        expect($partial->submit($apply, $applyFields, $applications)[0] === 403, 'An incomplete profile cannot apply.');
        expect($owner->submit($apply, $applyFields, $own)[0] === 403, 'Schools cannot submit a teacher application.');
        expect($candidate->send($apply, 'POST', $applyFields)[0] === 403, 'Application requires CSRF.');
        expect($candidate->submit($apply, ['revision' => '4'] + $applyFields, $job)[0] === 409, 'Stale postings cannot be applied to.');
        $tooLong = $candidate->submit($apply, $applyFields + ['statement' => str_repeat('X', 2001)], $job);
        expect($tooLong[0] === 422 && str_contains($tooLong[2], '2,000 characters'), 'Oversized application statements are rejected.');
        $statement = 'Private application statement <b>fixture</b>';
        redirectIs($candidate->submit($apply, $applyFields + ['statement' => $statement, 'teacher_id' => $placementTeacher], $job), $applications);
        expect(str_contains($candidate->send($applications)[2], $fields['title']), 'A pending professional verification does not block applications.');
        $detail = $candidate->send($applications . '&id=1')[2];
        expect(str_contains($detail, 'School Academy'),
            'The application snapshot preserves the employer name advertised on the published revision.');
        $submission = $db->query('SELECT teacher_id, candidate_reference, profile_snapshot FROM vacancy_applications WHERE id = 1')->fetch();
        $reference = $submission['candidate_reference'];
        expect((int) $submission['teacher_id'] === $teacher && preg_match('/\ACA-[0-9A-F]{32}\z/', $reference) === 1,
            'The applicant comes from the session and receives an opaque internal reference.');
        expect(str_contains($detail, $reference) && str_contains($detail, 'Private application statement &lt;b&gt;fixture&lt;/b&gt;')
            && str_contains($detail, 'Lesson planning') && str_contains($detail, 'Test College'), 'The saved professional information and escaped statement are visible to the owner.');
        $db->prepare("UPDATE teacher_profiles SET sections = JSON_SET(sections, '$.skills.skills', 'Replacement skill') WHERE user_id = ?")
            ->execute([$teacher]);
        expect(str_contains($candidate->send($applications . '&id=1')[2], 'Lesson planning'), 'Later profile edits do not rewrite the submitted profile.');
        $profile = json_decode($submission['profile_snapshot'], true, flags: JSON_THROW_ON_ERROR);
        expect(array_diff(array_keys($profile), ['category','subjects','levels','skills','certifications','qualifications','experience']) === [],
            'The snapshot stores only the approved professional fields.');
        expect(!str_contains($partial->send($applications)[2], $fields['title']), 'Application lists are owner-scoped.');
        expect($candidate->submit($apply, $applyFields, $applications)[0] === 409, 'Duplicate applications are rejected.');
        $withdraw = '/dashboard/teacher/applications/withdraw';
        expect($partial->send($applications . '&id=1')[0] === 404
            && $partial->submit($withdraw, ['id' => '1', 'from' => 'submitted', 'action' => 'withdrawn'], $applications)[0] === 404,
            'Teachers cannot read or withdraw another teacher’s application.');

        $moderate = '/dashboard/admin/vacancies/application?id=1';
        $moderateRoute = $admin . '/application';
        $staffDetail = $staff->send($moderate)[2];
        expect(str_contains($staffDetail, $reference) && str_contains($staffDetail, 'Lesson planning'), 'Staff can review submitted professional information.');
        foreach (['teacher@example.test', '+2348012345678', 'Private biography fixture', 'Private referee fixture', 'fixture.pdf'] as $private) {
            expect(!str_contains($staffDetail, $private) && !str_contains($submission['profile_snapshot'], $private),
                'Private contact, references and document metadata are excluded from applications.');
        }
        expect($owner->send($moderate)[0] === 403 && $owner->send($admin . '/applications')[0] === 403
            && $owner->submit($moderateRoute, ['id' => '1', 'from' => 'submitted', 'action' => 'under_review'], $own)[0] === 403
            && $owner->send('/dashboard/admin/verification/documents/download?id=1')[0] === 403,
            'Schools cannot list, read or update applications or open protected candidate documents.');
        foreach ([$owner->send($own)[2], $guest->send($job)[2]] as $outside) {
            expect(!str_contains($outside, $reference) && !str_contains($outside, 'Private application statement'),
                'Application information is absent from school and public vacancy pages.');
        }
        redirectIs($staff->submit('/dashboard/admin/vacancies/application', ['id' => '1', 'from' => 'submitted', 'action' => 'under_review'], $moderate), $moderate);
        expect(str_contains($candidate->send($applications)[2], 'Under review')
            && (int) $db->query('SELECT COUNT(*) FROM vacancy_application_history')->fetchColumn() === 2, 'Application transitions are visible and retained.');
        echo "PASS teacher eligibility, private application tracking and duplicate prevention.\n";

        $finalist = $login('placement', '/dashboard/teacher');
        redirectIs($finalist->submit($apply, $applyFields, $job), $applications);
        $finalistDetail = $applications . '&id=2';
        $reviewFinalist = $moderateRoute . '?id=2';
        $move = static fn (string $from, string $to, string $note = '') => $staff->submit($moderateRoute,
            ['id' => '2', 'from' => $from, 'action' => $to, 'note' => $note], $reviewFinalist);
        expect($move('submitted', 'successful')[0] === 409 && $move('submitted', 'withdrawn')[0] === 409,
            'Staff cannot skip recruitment stages or withdraw on the applicant’s behalf.');
        redirectIs($move('submitted', 'under_review'), $reviewFinalist);
        expect($move('submitted', 'shortlisted')[0] === 409, 'A stale status form cannot advance an application.');
        redirectIs($move('under_review', 'shortlisted'), $reviewFinalist);
        redirectIs($move('shortlisted', 'interview_invited', 'Interview invitation fixture'), $reviewFinalist);
        expect(str_contains($finalist->send($finalistDetail)[2], 'Interview invitation fixture'), 'The applicant can track the invitation and note.');
        redirectIs($move('interview_invited', 'offer_pending'), $reviewFinalist);
        expect($move('offer_pending', 'successful')[0] === 409, 'Professional verification is required for final placement only.');
        $db->prepare("UPDATE teacher_profiles SET verification_status = 'verified', professional_verified_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?")
            ->execute([$placementTeacher]);
        expect($move('offer_pending', 'successful')[0] === 409, 'A verification timestamp alone cannot confirm placement.');
        $db->prepare("INSERT INTO teacher_document_reviews (id, document_id, teacher_id, reviewer_id, kind, decision, reason)
            SELECT id, id, teacher_id, ?, 'cv', 'accepted', 'fixture' FROM teacher_documents WHERE teacher_id = ? AND kind = 'cv'")
            ->execute([$staffId, $placementTeacher]);
        $db->prepare("INSERT INTO teacher_verification_reviews (teacher_id, reviewer_id, revision, decision, checks, reason)
            SELECT user_id, ?, verification_revision, 'verified', ?, 'fixture' FROM teacher_profiles WHERE user_id = ?")
            ->execute([$staffId, json_encode(array_fill_keys(array_keys(ReviewInput::TEACHER_CHECKS), true), JSON_THROW_ON_ERROR), $placementTeacher]);
        $db->exec("UPDATE school_onboarding SET review_status = 'pending' WHERE user_id = (SELECT school_id FROM vacancies WHERE id = 2)");
        expect($move('offer_pending', 'successful')[0] === 409, 'Final placement also requires an approved school.');
        $db->exec("UPDATE school_onboarding SET review_status = 'approved' WHERE user_id = (SELECT school_id FROM vacancies WHERE id = 2)");
        redirectIs($move('offer_pending', 'successful'), $reviewFinalist);
        expect(str_contains($finalist->send($finalistDetail)[2], 'Successful') && $move('successful', 'unsuccessful')[0] === 409
            && $finalist->submit($withdraw, ['id' => '2', 'from' => 'successful', 'action' => 'withdrawn'], $finalistDetail)[0] === 409,
            'Successful placement is visible and cannot be changed or withdrawn through the application flow.');
        expect($db->query('SELECT to_status FROM vacancy_application_history WHERE application_id = 2 ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN) === ['submitted','under_review','shortlisted','interview_invited','offer_pending','successful'],
            'Only successful state changes are recorded in the application timeline.');
        echo "PASS application statements, privacy, bookmarks, recruitment stages and verification at final placement.\n";

        $today = (new DateTimeImmutable('today', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
        $db->prepare('UPDATE vacancies SET application_deadline = ? WHERE id = 2')->execute([$today]);
        expect($guest->send($job)[0] === 200, 'A vacancy remains open on its deadline in Nigeria.');
        $db->exec("UPDATE vacancies SET application_deadline = '2000-01-01' WHERE id = 2");
        expect($guest->send($job)[0] === 404 && $candidate->submit($apply, $applyFields, $applications)[0] === 404, 'Expired vacancies are not public or open for applications.');
        $unavailableBookmark = $candidate->send($savedJobs)[2];
        expect(str_contains($unavailableBookmark, 'Vacancy unavailable') && !str_contains($unavailableBookmark, 'href="' . $job . '"')
            && $partial->submit($bookmarkRoute, $bookmark, $savedJobs)[0] === 404, 'Expired saved jobs show their previous title and block new bookmarks.');
        $db->prepare('UPDATE vacancies SET application_deadline = ? WHERE id = 2')->execute([$fields['deadline']]);
        redirectIs($owner->submit($school, ['id' => '2', 'revision' => '5', 'action' => 'save'] + $fields, $own), $own);
        expect($guest->send($job)[0] === 200, 'An unchanged save preserves publication.');
        redirectIs($owner->submit($school, ['id' => '2', 'revision' => '5', 'action' => 'save', 'title' => 'Revised Science Vacancy'] + $fields, $own), $own);
        expect($guest->send($job)[0] === 404 && str_contains($candidate->send($applications)[2], $fields['title']), 'Material edits pause publication and preserve the application record.');
        expect($staff->submit($admin, ['id' => '2', 'revision' => '5', 'action' => 'close'])[0] === 409, 'A stale moderation form cannot overwrite an edit.');
        expect(str_contains($staff->send($admin . '/applications')[2], $fields['title']), 'Staff can still review prior applications while a vacancy is redrafted.');
        $change = static fn (AuthBrowser $browser, string $route, string $view, int $revision, string $action, array $more = []) =>
            $browser->submit($route, $more + ['id' => '2', 'revision' => (string) $revision, 'action' => $action], $view);
        redirectIs($change($owner, $school, $own, 6, 'submit'), $own);
        redirectIs($change($staff, $admin, $review, 7, 'start_review'), $review);
        redirectIs($change($staff, $admin, $review, 8, 'reject', ['note' => 'Clarify the responsibilities.']), $review);
        expect(str_contains($owner->send($own)[2], 'Clarify the responsibilities.'), 'Rejection reasons are visible to the school.');
        redirectIs($change($owner, $school, $own, 9, 'save', ['title' => 'Revised Science Vacancy'] + $fields), $own);
        redirectIs($change($owner, $school, $own, 10, 'submit'), $own);
        redirectIs($change($staff, $admin, $review, 11, 'start_review'), $review);
        redirectIs($change($staff, $admin, $review, 12, 'approve'), $review);
        $db->exec("UPDATE vacancies SET application_deadline = '2000-01-01' WHERE id = 2");
        expect($change($staff, $admin, $review, 13, 'publish')[0] === 422, 'Staff cannot publish an expired vacancy.');
        $db->prepare('UPDATE vacancies SET application_deadline = ? WHERE id = 2')->execute([$fields['deadline']]);
        redirectIs($change($staff, $admin, $review, 13, 'publish'), $review);
        expect(str_contains($guest->send($job)[2], 'Revised Science Vacancy') && str_contains($guest->send($job)[2], 'Renamed Academy')
            && !str_contains($candidate->send($applications . '&id=1')[2], 'Revised Science Vacancy'), 'Renewed publication preserves the earlier application snapshot.');
        $db->exec("UPDATE school_onboarding SET review_status = 'pending' WHERE user_id = (SELECT school_id FROM vacancies WHERE id = 2)");
        expect($guest->send($job)[0] === 404, 'Loss of school approval hides its active vacancies.');
        $db->exec("UPDATE school_onboarding SET review_status = 'approved' WHERE user_id = (SELECT school_id FROM vacancies WHERE id = 2)");
        redirectIs($candidate->submit($withdraw, ['id' => '1', 'from' => 'under_review', 'action' => 'withdrawn'], $applications . '&id=1'), $applications . '&id=1');
        expect($candidate->submit($apply, ['id' => '2', 'revision' => '14'], $applications)[0] === 409, 'Withdrawal does not allow reapplication.');
        redirectIs($change($owner, $school, $own, 14, 'close'), $own);
        expect($guest->send($job)[0] === 404 && str_contains($owner->send($own)[2], 'Closed'), 'Closing unlists a vacancy while retaining school records.');
        redirectIs($candidate->submit($bookmarkRoute, ['action' => 'remove'] + $bookmark, $savedJobs), $savedJobs);
        expect(!str_contains($candidate->send($savedJobs)[2], $fields['title']), 'The owner can remove an unavailable job.');
        $pendingDraft = $school . '?id=1';
        $draftEdit = ['id' => '1', 'action' => 'save'];
        redirectIs($pending->submit($school, ['revision' => '1', 'qualification' => '01'] + $draftEdit + $fields, $pendingDraft), $pendingDraft);
        redirectIs($pending->submit($school, ['revision' => '2', 'qualification' => '1'] + $draftEdit + $fields, $pendingDraft), $pendingDraft);
        expect($db->query("SELECT content->>'$.qualification' FROM vacancies WHERE id = 1")->fetchColumn() === '1',
            'A text change is saved even when both values look numeric.');
        echo "PASS local deadlines, material edits and stale review checks.\n";
    });
} catch (Throwable $failure) {
    fwrite(STDERR, "FAIL " . $failure->getMessage() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) {
            fwrite(STDERR, implode('', array_slice(file($log), -8)));
        }
    }
    exit(1);
}

<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Teacher\Section;
use Furoeducare\Modules\Teacher\TeacherRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/school-fixtures.php';

function placementRow(PDO $db, string $sql, array $params = []): array
{
    $query = $db->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll();
    expect(count($rows) === 1, 'Expected one fixture record.');
    return $rows[0];
}

function placementStatus(array $response, int $status, string $message): void
{
    expect($response[0] === $status, "$message Expected HTTP $status, got {$response[0]}.");
}

function placementDenied(array $response, string $message): void
{
    expect(in_array($response[0], [403, 404, 409, 422], true), "$message Got HTTP {$response[0]}.");
}

function placementPage(AuthBrowser $browser, string $path): string
{
    $response = $browser->send($path);
    placementStatus($response, 200, "Open $path.");
    expect(in_array('no-store', array_map('trim', explode(',', $response[1]['cache-control'] ?? '')), true),
        "Private page $path should prevent caching.");
    return $response[2];
}

function placementVerify(AuthBrowser $staff, PDO $db, int $teacher, ?int $document = null): void
{
    if ($document !== null) {
        $documents = '/dashboard/admin/verification/documents';
        $file = $documents . '?id=' . $document;
        redirectIs($staff->submit($documents . '/review', [
            'id' => $document, 'decision' => 'accepted', 'reason' => 'Reviewed the fixture CV.',
        ], $file), $file);
    }
    $teachers = '/dashboard/admin/verification/teachers';
    $detail = $teachers . '?id=' . $teacher;
    $revision = placementRow($db, 'SELECT verification_revision FROM teacher_profiles WHERE user_id = ?', [$teacher]);
    redirectIs($staff->submit($teachers . '/review', [
        'id' => $teacher, 'revision' => $revision['verification_revision'], 'decision' => 'verified',
        'reason' => 'Confirmed the required professional fixture checks.',
        'checks' => ['identity', 'qualifications', 'professional_history'],
    ], $detail), $detail);
}

function placementFixtures(PDO $db, TeacherRepository $profiles, string $password): array
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $accounts = [];
    foreach ([
        'staff' => 'admin', 'other-staff' => 'admin', 'school' => 'school', 'other-school' => 'school',
        'parent' => 'parent', 'other-parent' => 'parent', 'teacher' => 'teacher', 'tutor' => 'teacher',
    ] as $name => $role) {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
            ->execute([$name . '@example.test', $hash]);
        $id = $accounts[$name] = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        if ($role === 'admin') {
            grantTestStaffRole($db, $id);
        }
    }
    $schools = new SchoolRepository(static fn (): PDO => $db);
    foreach (['school', 'other-school'] as $name) {
        $schools->create($accounts[$name], schoolFields($name . '@example.test', $password, ['school_name' => ucfirst($name) . ' Academy']));
        $db->prepare("UPDATE school_onboarding SET review_status = 'approved' WHERE user_id = ?")->execute([$accounts[$name]]);
    }
    $parents = new ParentRepository(static fn (): PDO => $db);
    foreach (['parent', 'other-parent'] as $name) {
        $parents->create($accounts[$name], [
            'first_name' => 'Guardian', 'last_name' => 'Fixture', 'phone' => '+2348012345601', 'state_code' => 'LA', 'city' => 'Ikeja',
        ]);
    }

    $references = ['teacher' => 'FT-1123456789ABCDEF0123', 'tutor' => 'FT-0123456789ABCDEF0123'];
    $documents = [];
    foreach ($references as $name => $reference) {
        $id = $accounts[$name];
        $profiles->create($id, [
            'category' => 'teacher', 'first_name' => 'PrivateGiven', 'last_name' => 'PrivateTeacher', 'phone' => '+2348012345602',
        ]);
        foreach ([
            [Section::Biography, ['bio' => 'Private background fixture.']],
            [Section::Qualifications, ['rows' => [[
                'qualification' => 'BEd', 'institution' => 'Private Fixture University', 'field_of_study' => 'Education', 'completed_year' => 2020,
            ]]]],
            [Section::Experience, ['rows' => [], 'no_experience' => true]],
            [Section::Subjects, ['subjects' => ['Mathematics'], 'levels' => ['Primary']]],
            [Section::Skills, ['skills' => ['Lesson planning']]],
            [Section::Locations, ['locations' => ['Lagos']]],
            [Section::Preferences, ['employment' => ['full_time'], 'tutoring' => ['private_tutoring', 'online_tutoring']]],
            [Section::Availability, ['status' => 'now', 'opportunities' => ['full_time', 'private_tutoring', 'online_tutoring']]],
        ] as [$section, $values]) {
            $profiles->save($id, $section, $values);
        }
        $profiles->addDocument($id, 'cv', bin2hex(random_bytes(32)), 'private-cv.pdf', 'application/pdf', 200);
        $documents[$name] = (int) placementRow($db, 'SELECT id FROM teacher_documents WHERE cv_teacher_id = ?', [$id])['id'];
        $db->prepare("UPDATE teacher_profiles SET published_at = UTC_TIMESTAMP(6), verification_status = 'pending' WHERE user_id = ?")
            ->execute([$id]);
        $db->prepare("INSERT INTO teacher_listings
            (teacher_id, reference, title, bio, qualification, experience, state_code, city, availability_mask, approval_basis, approved_at)
            VALUES (?, ?, 'Mathematics tutor', 'Primary mathematics lessons.', 'BEd', 5, 'LA', 'Ikeja', 13, 'Fixture approval', UTC_TIMESTAMP(6))")
            ->execute([$id, $reference]);
    }

    $deadline = (new DateTimeImmutable('+14 days', new DateTimeZone('Africa/Lagos')))->format('Y-m-d');
    $jobFields = [
        'title' => 'Mathematics vacancy', 'subjects' => 'Mathematics', 'teaching_level' => 'Primary',
        'qualification' => 'BEd', 'experience' => '2', 'employment_type' => 'full_time', 'city' => 'Ikeja',
        'state_code' => 'LA', 'deadline' => $deadline, 'positions' => '2', 'salary_min' => '', 'salary_max' => '',
        'description' => 'Teach mathematics.', 'responsibilities' => 'Plan lessons.', 'skills' => 'Classroom management',
    ];
    $schoolName = 'School Academy';
    $posting = json_encode($jobFields + ['published_school_name' => $schoolName], JSON_THROW_ON_ERROR);
    $snapshot = json_encode(['fields' => $jobFields, 'school_name' => $schoolName], JSON_THROW_ON_ERROR);
    $applications = [];
    foreach (['hire' => 'A', 'reject' => 'B', 'withdraw' => 'C'] as $name => $referenceDigit) {
        $db->prepare("INSERT INTO vacancies (school_id, status, content, application_deadline, published_at)
            VALUES (?, 'published', ?, ?, UTC_TIMESTAMP(6))")->execute([$accounts['school'], $posting, $deadline]);
        $vacancy = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO vacancy_applications (vacancy_id, teacher_id, status, vacancy_revision, job_title, job_snapshot,
            candidate_reference, application_statement)
            VALUES (?, ?, 'under_review', 1, ?, ?, ?, 'Private application fixture.')")
            ->execute([$vacancy, $accounts['teacher'], $jobFields['title'], $snapshot, 'CA-' . str_repeat($referenceDigit, 32)]);
        $applications[$name] = (int) $db->lastInsertId();
    }

    $details = json_encode([
        'subject' => 'Mathematics', 'level' => 'primary', 'learners' => 1, 'objectives' => 'Practice fractions',
        'mode' => 'home', 'state_code' => 'LA', 'city' => 'Ikeja', 'days' => ['sat'], 'sessions' => 1,
        'times' => [['from' => '12:00', 'to' => '13:00']],
    ], JSON_THROW_ON_ERROR);
    $db->prepare("INSERT INTO parent_tutoring_requests (parent_id, subject, requirements, submitted_at, status, lesson_details)
        VALUES (?, 'Mathematics', 'Practice fractions', UTC_TIMESTAMP(6), 'arrangement_pending', ?)")
        ->execute([$accounts['parent'], $details]);
    $tutoring = (int) $db->lastInsertId();
    $candidate = $db->prepare("INSERT INTO tutoring_candidates (request_id, teacher_id, teacher_reference, title)
        VALUES (?, ?, ?, 'Mathematics tutor')");
    foreach ($references as $name => $reference) {
        $candidate->execute([$tutoring, $accounts[$name], $reference]);
    }
    return compact('accounts', 'applications', 'documents', 'references', 'tutoring');
}

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-placements-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_placements_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback development schema named furoeducare_test_placements_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty placement test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    expect(mkdir($work, 0700), 'Create the temporary test directory.');
    $password = 'Placement fixture passphrase 647!';
    $profiles = new TeacherRepository(static fn (): PDO => $db);
    $fixture = placementFixtures($db, $profiles, $password);

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log',
    ], $work . '/server.log', static function (string $base) use ($db, $profiles, $fixture, $password): void {
        $staffPath = '/dashboard/admin/placements';
        $participantPath = '/placements';
        $login = static function (string $name, string $role) use ($base, $password): AuthBrowser {
            $browser = new AuthBrowser($base);
            redirectIs($browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]), '/dashboard/' . $role);
            return $browser;
        };
        $guest = new AuthBrowser($base);
        redirectIs($guest->send($staffPath), '/login');
        redirectIs($guest->send($participantPath), '/login');
        $staff = $login('staff', 'admin');
        $otherStaff = $login('other-staff', 'admin');
        $school = $login('school', 'school');
        $otherSchool = $login('other-school', 'school');
        $parent = $login('parent', 'parent');
        $otherParent = $login('other-parent', 'parent');
        $teacher = $login('teacher', 'teacher');
        $tutor = $login('tutor', 'teacher');
        $accounts = $fixture['accounts'];
        $row = static fn (int $id): array => placementRow($db, 'SELECT * FROM placements WHERE id = ?', [$id]);
        $application = static fn (int $id): array => placementRow($db, 'SELECT * FROM vacancy_applications WHERE id = ?', [$id]);
        $tutoring = static fn (): array => placementRow($db, 'SELECT * FROM parent_tutoring_requests WHERE id = ?', [$fixture['tutoring']]);
        $start = static fn (array $data): array => $staff->submit($staffPath, $data, '/dashboard/admin');
        $startId = static function (array $data) use ($start, $staffPath, $row, $accounts): int {
            $response = $start($data);
            placementStatus($response, 303, 'Staff could not open a placement.');
            $url = $response[1]['location'] ?? '';
            expect(preg_match('~^' . $staffPath . '\?id=([1-9][0-9]*)$~D', $url, $match) === 1, 'Starting a placement redirects to the new case.');
            $id = (int) $match[1];
            $record = $row($id);
            expect($record['status'] === 'screening' && (int) $record['assigned_admin_id'] === $accounts['staff'], 'Opening a case records its assigned screening staff.');
            return $id;
        };
        $manage = static fn (int $id, string $action, array $fields = []): array
            => $staff->submit($staffPath, $fields + [
                'action' => $action, 'id' => $id, 'revision' => $row($id)['revision'], 'note' => 'Private staff decision fixture.',
            ], $staffPath . '?id=' . $id);
        $advance = static function (int $id, string $action, string $status, array $fields = []) use ($manage, $staffPath, $row): void {
            redirectIs($manage($id, $action, $fields), $staffPath . '?id=' . $id);
            expect($row($id)['status'] === $status, "The placement should be $status after $action.");
        };
        $select = static function (AuthBrowser $owner, int $id) use ($participantPath, $row): void {
            $detail = $participantPath . '?id=' . $id;
            redirectIs($owner->submit($participantPath, [
                'action' => 'select', 'id' => $id, 'revision' => $row($id)['revision'], 'teacher_id' => 9999,
            ], $detail), $detail);
            expect($row($id)['status'] === 'selected' && $row($id)['confirmed_at'] === null, 'Selection must never confirm a placement.');
        };
        $lagos = new DateTimeZone('Africa/Lagos');
        $schedule = new DateTimeImmutable('tomorrow 11:30', $lagos);
        $appointment = ['scheduled_at' => $schedule->format('Y-m-d\TH:i')];
        $offer = [
            'offer_summary' => 'Start with a mathematics orientation.',
            'starts_on' => (new DateTimeImmutable('+7 days', $lagos))->format('Y-m-d'),
        ];

        placementStatus($school->send($staffPath), 403, 'A school opened the staffing queue.');
        $employment = ['action' => 'start_employment', 'application_id' => $fixture['applications']['hire']];
        placementStatus($staff->send($staffPath, 'POST', $employment), 403, 'Opening a placement without CSRF succeeded.');
        $hire = $startId($employment);
        $hirePath = $participantPath . '?id=' . $hire;
        $staffHire = $staffPath . '?id=' . $hire;
        $duplicate = $start($employment);
        if ($duplicate[0] === 303) {
            redirectIs($duplicate, $staffHire);
        } else {
            placementDenied($duplicate, 'A duplicate employment placement was created.');
        }
        expect((int) placementRow($db, 'SELECT COUNT(*) AS total FROM placements WHERE application_id = ?', [$employment['application_id']])['total'] === 1,
            'Starting the same application twice creates just one placement.');
        placementStatus($school->send($hirePath), 404, 'An employer read a candidate before staff released the shortlist.');
        placementDenied($manage($hire, 'confirm'), 'A staff member skipped the coordination steps.');
        placementDenied($otherStaff->submit($staffPath, [
            'id' => $hire, 'action' => 'shortlist', 'revision' => $row($hire)['revision'], 'note' => 'Unassigned actor fixture.',
        ], '/dashboard/admin'), 'An unassigned staff member advanced a placement.');
        $oldRevision = $row($hire)['revision'];
        $advance($hire, 'shortlist', 'shortlisted');
        placementDenied($manage($hire, 'schedule', ['revision' => $oldRevision] + $appointment), 'A stale scheduling action succeeded.');
        $advance($hire, 'schedule', 'discussion_scheduled', $appointment);
        expect(substr((string) $row($hire)['scheduled_at'], 0, 19) === $schedule->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'A WAT discussion appointment is stored in UTC.');
        $advance($hire, 'available', 'availability_confirmed');
        placementStatus($otherSchool->send($hirePath), 404, 'An unrelated school read the placement.');
        placementStatus($otherSchool->submit($participantPath, [
            'action' => 'select', 'id' => $hire, 'revision' => $row($hire)['revision'],
        ], '/dashboard/school'), 404, 'An unrelated school selected a candidate.');
        placementDenied($teacher->submit($participantPath, [
            'action' => 'select', 'id' => $hire, 'revision' => $row($hire)['revision'],
        ], '/dashboard/teacher'), 'A teacher selected themselves.');
        $select($school, $hire);
        expect((int) $row($hire)['teacher_id'] === $accounts['teacher'] && $application($employment['application_id'])['status'] !== 'successful',
            'Selection leaves the original candidate and application without a final placement.');
        $advance($hire, 'offer', 'offer_pending', $offer);
        placementDenied($manage($hire, 'confirm'), 'An unverified employment candidate was placed.');
        expect($row($hire)['confirmed_at'] === null && $application($employment['application_id'])['status'] === 'offer_pending',
            'Failed confirmation leaves both records unchanged.');

        placementVerify($staff, $db, $accounts['teacher'], $fixture['documents']['teacher']);
        $legacy = '/dashboard/admin/vacancies/application';
        placementDenied($staff->submit($legacy, [
            'id' => $employment['application_id'], 'action' => 'successful', 'from' => 'offer_pending', 'note' => 'Legacy bypass fixture.',
        ], $legacy . '?id=' . $employment['application_id']), 'The application route bypassed managed confirmation.');
        $vacancy = (int) $application($employment['application_id'])['vacancy_id'];
        $vacancyStatus = $db->prepare('UPDATE vacancies SET status = ? WHERE id = ?');
        $vacancyStatus->execute(['submitted', $vacancy]);
        placementDenied($manage($hire, 'confirm'), 'A vacancy awaiting renewed approval received a placement.');
        expect($row($hire)['status'] === 'offer_pending', 'A vacancy awaiting review leaves the offer unconfirmed.');
        $vacancyStatus->execute(['published', $vacancy]);
        $schoolStatus = $db->prepare('UPDATE school_onboarding SET review_status = ? WHERE user_id = ?');
        $schoolStatus->execute(['pending', $accounts['school']]);
        placementDenied($manage($hire, 'confirm'), 'A school that lost approval received a placement.');
        expect($row($hire)['status'] === 'offer_pending', 'Lost school approval leaves the offer unconfirmed.');
        $schoolStatus->execute(['approved', $accounts['school']]);
        $advance($hire, 'confirm', 'confirmed');
        expect($row($hire)['confirmed_at'] !== null && $application($employment['application_id'])['status'] === 'successful',
            'Staff confirmation atomically records final placement and application success.');
        placementDenied($manage($hire, 'confirm'), 'A second confirmation succeeded.');
        $advance($hire, 'close', 'closed');
        expect($row($hire)['closed_at'] !== null && $application($employment['application_id'])['status'] === 'successful',
            'Closing an employment placement preserves the successful application outcome.');
        placementDenied($start($employment), 'A closed employment placement let the same application open a second case.');
        $schoolSelection = placementRow($db, "SELECT actor_user_id, from_status, to_status FROM placement_history
            WHERE placement_id = ? AND action = 'select'", [$hire]);
        $staffConfirmation = placementRow($db, "SELECT actor_user_id, from_status, to_status FROM placement_history
            WHERE placement_id = ? AND action = 'confirm'", [$hire]);
        expect((int) $schoolSelection['actor_user_id'] === $accounts['school'] && $schoolSelection['from_status'] === 'availability_confirmed'
            && $schoolSelection['to_status'] === 'selected'
            && (int) $staffConfirmation['actor_user_id'] === $accounts['staff'] && $staffConfirmation['from_status'] === 'offer_pending'
            && $staffConfirmation['to_status'] === 'confirmed', 'History attributes selection and confirmation to their separate actors and stages.');
        foreach ([$school, $teacher] as $recipient) {
            $body = placementPage($recipient, $hirePath);
            expect(!str_contains($body, 'Private staff decision fixture.') && !str_contains($body, 'Private application fixture.'),
                'Placement status exposed restricted staff or application material.');
        }
        echo "PASS employment placement, staff authority, participant isolation, verification, duplicate handling and closure.\n";

        $rejected = $startId(['action' => 'start_employment', 'application_id' => $fixture['applications']['reject']]);
        $advance($rejected, 'reject', 'rejected');
        placementDenied($manage($rejected, 'confirm'), 'A rejected placement was later confirmed.');
        expect($row($rejected)['confirmed_at'] === null && $application($fixture['applications']['reject'])['status'] !== 'successful',
            'Rejection never creates a placement success.');
        $withdrawnApplication = $fixture['applications']['withdraw'];
        $withdrawn = $startId(['action' => 'start_employment', 'application_id' => $withdrawnApplication]);
        $teacherApplication = '/dashboard/teacher?tab=applications&id=' . $withdrawnApplication;
        redirectIs($teacher->submit('/dashboard/teacher/applications/withdraw', [
            'id' => $withdrawnApplication, 'action' => 'withdrawn', 'from' => 'under_review', 'note' => 'Candidate withdrew.',
        ], $teacherApplication), $teacherApplication);
        expect($row($withdrawn)['status'] === 'rejected' && $application($withdrawnApplication)['status'] === 'withdrawn',
            'Withdrawal closes a live placement before it can be confirmed.');
        echo "PASS rejected placement remains terminal and source withdrawal ends a live placement.\n";

        $homeInput = [
            'action' => 'start_tutoring', 'tutoring_id' => $fixture['tutoring'], 'reference' => $fixture['references']['tutor'], 'mode' => 'home',
        ];
        placementDenied($start(['mode' => 'online'] + $homeInput), 'A home-only tutoring request opened an online placement.');
        placementDenied($start(['reference' => 'FT-FFFFFFFFFFFFFFFFFFFF'] + $homeInput), 'An unshortlisted tutor was added to the placement.');
        $home = $startId($homeInput);
        $homePath = $participantPath . '?id=' . $home;
        placementDenied($start(['reference' => $fixture['references']['teacher']] + $homeInput), 'A second live case opened for the same tutoring request.');
        $advance($home, 'shortlist', 'shortlisted');
        $advance($home, 'schedule', 'discussion_scheduled', $appointment);
        $advance($home, 'available', 'availability_confirmed');
        placementStatus($otherParent->send($homePath), 404, 'A different parent read a private tutoring placement.');
        $select($parent, $home);
        expect($tutoring()['status'] === 'arrangement_pending' && $tutoring()['confirmed_teacher_id'] === null,
            'Parent selection cannot close the recruitment request or confirm a tutor.');
        $advance($home, 'offer', 'offer_pending', $offer);
        placementVerify($staff, $db, $accounts['tutor'], $fixture['documents']['tutor']);
        $legacyTutoring = '/dashboard/admin/tutoring-requests';
        placementDenied($staff->submit($legacyTutoring, [
            'id' => $fixture['tutoring'], 'version' => $tutoring()['updated_at'], 'action' => 'transition',
            'status' => 'confirmed', 'ref' => $fixture['references']['tutor'], 'note' => 'Legacy home bypass fixture.',
        ], $legacyTutoring . '?id=' . $fixture['tutoring']), 'The tutoring request route bypassed safeguarding.');
        placementDenied($manage($home, 'confirm'), 'A verified home tutor was placed without safeguarding.');
        $checklist = ['identity' => '1', 'references' => '1', 'home_plan' => '1', 'emergency_plan' => '1',
            'safeguarding_reference' => 'SAFEGUARD-FIXTURE-817'];
        placementDenied($manage($home, 'safeguard', ['references' => '0'] + $checklist), 'An incomplete identity and reference review was accepted.');
        $advance($home, 'safeguard', 'offer_pending', $checklist);
        expect($row($home)['safeguarding_checked_at'] !== null && (int) $row($home)['safeguarding_checked_by'] === $accounts['staff'],
            'Safeguarding review records an attributable timestamp.');
        $profiles->save($accounts['tutor'], Section::Qualifications, ['rows' => [[
            'qualification' => 'MEd', 'institution' => 'Private Updated University', 'field_of_study' => 'Education', 'completed_year' => 2024,
        ]]]);
        placementDenied($manage($home, 'confirm'), 'A material profile edit did not invalidate professional verification.');
        placementVerify($staff, $db, $accounts['tutor']);
        placementDenied($manage($home, 'confirm'), 'A new professional revision reused old safeguarding checks.');
        $advance($home, 'safeguard', 'offer_pending', $checklist);
        $availability = $db->prepare("UPDATE teacher_profiles SET sections = JSON_SET(sections, '$.availability.status', ?) WHERE user_id = ?");
        $availability->execute(['unavailable', $accounts['tutor']]);
        placementDenied($manage($home, 'confirm'), 'Final tutoring confirmation used stale public availability.');
        $availability->execute(['now', $accounts['tutor']]);
        $advance($home, 'confirm', 'confirmed');
        expect($tutoring()['status'] === 'confirmed' && (int) $tutoring()['confirmed_teacher_id'] === $accounts['tutor'],
            'Staff confirmation links the safeguarded tutor to the request.');
        $parentPage = placementPage($parent, $homePath);
        expect(!str_contains($parentPage, $checklist['safeguarding_reference']) && !str_contains($parentPage, 'Private staff decision fixture.'),
            'Home safeguarding evidence stays private to staff.');
        placementPage($tutor, $homePath);
        placementDenied($otherParent->send($homePath), 'A different parent read a confirmed arrangement.');
        placementDenied($start(['reference' => $fixture['references']['teacher']] + $homeInput), 'A confirmed tutoring request accepted a second candidate placement.');
        echo "PASS home tutoring placement with current safeguarding, private evidence and a single confirmed arrangement.\n";

        $events = $db->prepare("SELECT event FROM verification_notifications WHERE user_id = ? AND event LIKE 'placement.%'");
        foreach (['school', 'parent', 'teacher', 'tutor'] as $recipient) {
            $events->execute([$accounts[$recipient]]);
            expect($events->fetchColumn() !== false, "Placement status updates reached the $recipient account.");
        }
        foreach (['other-school', 'other-parent'] as $unrelated) {
            $events->execute([$accounts[$unrelated]]);
            expect($events->fetchColumn() === false, "An unrelated $unrelated account received a placement update.");
        }
        echo "PASS placement updates reach only the relevant accounts.\n";
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
        foreach ([$work . '/sessions', $work . '/mail', $work] as $directory) {
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

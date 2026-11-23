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
require __DIR__ . '/support/cv-fixtures.php';
require __DIR__ . '/support/school-fixtures.php';

function disclosureStatus(array $response, int $expected, string $message): void
{
    expect($response[0] === $expected, "$message Expected HTTP $expected, received {$response[0]}.");
}

function disclosureDenied(array $response, string $message): void
{
    expect(in_array($response[0], [403, 404, 405, 409, 422], true), "$message Received HTTP {$response[0]}.");
}

function disclosureRows(PDO $db, string $sql, array $params = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function disclosureRow(PDO $db, string $sql, array $params = []): array
{
    $rows = disclosureRows($db, $sql, $params);
    expect(count($rows) === 1, "Expected one fixture row for $sql.");
    return $rows[0];
}

function disclosurePage(AuthBrowser $browser, string $path): string
{
    $response = $browser->send($path);
    disclosureStatus($response, 200, "Could not open $path.");
    expect(in_array('no-store', array_map('trim', explode(',', $response[1]['cache-control'] ?? '')), true),
        "Private page $path must use no-store.");
    return $response[2];
}

function disclosurePost(AuthBrowser $browser, string $route, string $form, array $fields): array
{
    return $browser->submit($route, $fields, $form);
}

function disclosureSafe(string $body, array $restricted): void
{
    foreach ($restricted as $value) {
        expect(!str_contains($body, $value), "Private fixture value appeared in a response: $value");
    }
}

function disclosureCv(PDO $db, TeacherRepository $profiles, string $directory, int $teacher, string $name, string $bytes): array
{
    $key = bin2hex(random_bytes(32));
    expect(file_put_contents($directory . '/' . $key, $bytes) === strlen($bytes), 'Could not write the private CV fixture.');
    $profiles->addDocument($teacher, 'cv', $key, $name, 'application/pdf', strlen($bytes));
    $row = disclosureRow($db, 'SELECT id FROM teacher_documents WHERE cv_teacher_id = ?', [$teacher]);
    return ['id' => (int) $row['id'], 'key' => $key, 'name' => $name, 'bytes' => $bytes];
}

function disclosureAcceptCv(AuthBrowser $staff, int $document): void
{
    $route = '/dashboard/admin/verification/documents';
    $detail = $route . '?id=' . $document;
    redirectIs($staff->submit($route . '/review', [
        'id' => $document, 'decision' => 'accepted', 'reason' => 'Reviewed the fixture document.',
    ], $detail), $detail);
}

function seedDisclosureFixtures(PDO $db, TeacherRepository $profiles, string $directory, string $password): array
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $account = static function (string $name, string $role) use ($db, $hash): int {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute([$name . '@example.test', $hash]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        if ($role === 'admin') {
            grantTestStaffRole($db, $id);
        }
        return $id;
    };

    $staff = $account('case-manager', 'admin');
    $account('other-staff', 'admin');
    $schools = new SchoolRepository(static fn (): PDO => $db);
    $schoolIds = [];
    foreach (['school', 'other-school', 'pending-school'] as $name) {
        $id = $schoolIds[$name] = $account($name, 'school');
        $schools->create($id, schoolFields($name . '@example.test', $password, ['school_name' => ucfirst($name) . ' Academy']));
        if ($name !== 'pending-school') {
            $db->prepare("UPDATE school_onboarding SET review_status = 'approved' WHERE user_id = ?")->execute([$id]);
        }
    }

    $parentIds = [];
    $parents = new ParentRepository(static fn (): PDO => $db);
    foreach (['parent', 'other-parent'] as $name) {
        $id = $parentIds[$name] = $account($name, 'parent');
        $parents->create($id, [
            'first_name' => 'Guardian', 'last_name' => 'Fixture', 'phone' => '+2348012345601', 'state_code' => 'LA', 'city' => 'Ikeja',
        ]);
    }

    $teacher = $account('private-teacher', 'teacher');
    $applicant = $account('private-applicant', 'teacher');
    $restricted = [
        'PrivateGiven', 'PrivateSurname', 'private-teacher@example.test', '+2348012345701',
        'Private biography marker', 'Private residential marker', 'Private referee marker', 'Private application marker',
        'Fixture Teaching College', 'Fixture Employer Marker', 'Private experience marker',
    ];
    foreach ([$teacher, $applicant] as $id) {
        $profiles->create($id, ['category' => 'teacher', 'first_name' => 'PrivateGiven', 'last_name' => 'PrivateSurname', 'phone' => '+2348012345701']);
        $profiles->save($id, Section::Qualifications, ['rows' => [[
            'qualification' => 'BEd Mathematics', 'institution' => 'Fixture Teaching College',
            'field_of_study' => 'Mathematics', 'completed_year' => 2020,
        ]]]);
        $profiles->save($id, Section::Biography, ['bio' => 'Private biography marker']);
        $profiles->save($id, Section::Experience, ['no_experience' => false, 'rows' => [[
            'position_title' => 'Mathematics Instructor', 'organisation' => 'Fixture Employer Marker',
            'started_year' => 2020, 'ended_year' => 2024, 'description' => 'Private experience marker',
        ]]]);
        $profiles->save($id, Section::Subjects, ['subjects' => ['Mathematics'], 'levels' => ['Secondary']]);
        $profiles->save($id, Section::Availability, ['status' => 'now', 'opportunities' => ['full_time', 'private_tutoring', 'online_tutoring']]);
        $db->prepare("UPDATE teacher_profiles SET sections = JSON_SET(sections, '$.personal',
            JSON_OBJECT('address', 'Private residential marker', 'referee', 'Private referee marker')),
            published_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?")->execute([$id]);
    }
    $reference = 'FT-0123456789ABCDEF0123';
    $title = 'Approved mathematics educator';
    $db->prepare("INSERT INTO teacher_listings
        (teacher_id, reference, title, bio, qualification, experience, state_code, city, availability_mask, approval_basis, approved_at)
        VALUES (?, ?, ?, 'Public mathematics summary.', 'BEd', 5, 'LA', 'Ikeja', 13, 'Public listing fixture', CURRENT_TIMESTAMP(6))")
        ->execute([$teacher, $reference, $title]);
    $cv = disclosureCv($db, $profiles, $directory, $teacher, 'private-current-cv.pdf', sampleCvPdf(['Private original CV fixture']));
    $otherCv = disclosureCv($db, $profiles, $directory, $applicant, 'private-unrelated-cv.pdf', sampleCvPdf(['Private unrelated CV fixture']));
    $restricted = [...$restricted, $cv['name'], $cv['key'], 'Private original CV fixture',
        $otherCv['name'], $otherCv['key'], 'Private unrelated CV fixture'];

    $vacancyInsert = $db->prepare("INSERT INTO vacancies (school_id, status, content, published_at)
        VALUES (?, 'published', JSON_OBJECT('title', 'Fixture Mathematics Vacancy'), CURRENT_TIMESTAMP(6))");
    $vacancyInsert->execute([$schoolIds['school']]);
    $vacancy = (int) $db->lastInsertId();
    $vacancyInsert->execute([$schoolIds['other-school']]);
    $otherVacancy = (int) $db->lastInsertId();
    $application = $db->prepare("INSERT INTO vacancy_applications
        (vacancy_id, teacher_id, vacancy_revision, job_title, job_snapshot, candidate_reference, application_statement)
        VALUES (?, ?, 1, 'Fixture Mathematics Vacancy', JSON_OBJECT(), ?, 'Private application marker')");
    $appRef = 'CA-' . str_repeat('A', 32);
    $otherAppRef = 'CA-' . str_repeat('B', 32);
    $application->execute([$vacancy, $applicant, $appRef]);
    $application->execute([$otherVacancy, $applicant, $otherAppRef]);

    return compact('staff', 'schoolIds', 'parentIds', 'teacher', 'applicant', 'reference', 'title', 'cv', 'otherCv',
        'vacancy', 'otherVacancy', 'appRef', 'otherAppRef', 'restricted');
}

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-disclosure-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_disclosure_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback database named furoeducare_test_disclosure_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty disclosure test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    expect(mkdir($work, 0700) && mkdir($work . '/documents', 0700), 'Could not create temporary private fixture directories.');

    $password = 'Disclosure fixture passphrase 625!';
    $profiles = new TeacherRepository(static fn (): PDO => $db);
    $fixture = seedDisclosureFixtures($db, $profiles, $work . '/documents', $password);
    $policyVersion = 'disclosure-fixture-v1';
    $environment = [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000', 'AUTH_SESSION_DIR' => $work . '/sessions',
        'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log', 'TEACHER_DOCUMENT_DIR' => $work . '/documents',
        'DISCLOSURE_POLICY_VERSION' => $policyVersion, 'DISCLOSURE_POLICY_APPROVAL_REF' => 'REVIEW-FIXTURE-2026',
        'DISCLOSURE_PRIVACY_NOTICE_URL' => 'https://furoeducare.example.test/privacy/disclosures',
        'DISCLOSURE_PRIVACY_CONTACT' => 'privacy@example.test',
    ];
    serve($root, $environment, $work . '/server.log', static function (string $base) use (
        $db, $profiles, $fixture, $password, $root, $work, $environment, $policyVersion,
    ): void {
        $login = static function (string $name, string $role) use ($base, $password): AuthBrowser {
            $browser = new AuthBrowser($base);
            redirectIs($browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]), '/dashboard/' . $role);
            return $browser;
        };
        $staff = $login('case-manager', 'admin');
        $otherStaff = $login('other-staff', 'admin');
        $school = $login('school', 'school');
        $otherSchool = $login('other-school', 'school');
        $pendingSchool = $login('pending-school', 'school');
        $teacher = $login('private-teacher', 'teacher');
        $applicant = $login('private-applicant', 'teacher');
        $parent = $login('parent', 'parent');
        $otherParent = $login('other-parent', 'parent');
        $guest = new AuthBrowser($base);

        $ownerRoute = '/candidate-requests';
        $staffRoute = '/dashboard/admin/candidate-requests';
        $downloadRoute = $ownerRoute . '/download';
        $profileRoute = $ownerRoute . '/profile';
        $directoryProfile = '/find-teachers/profile?ref=' . $fixture['reference'];
        redirectIs($guest->send($staffRoute), '/login');
        redirectIs($guest->send($ownerRoute), '/login');
        disclosureStatus($teacher->send($staffRoute), 403, 'Teacher account opened the staff queue.');
        disclosureStatus($school->send($staffRoute), 403, 'School account opened the staff queue.');
        $public = $guest->send($directoryProfile);
        disclosureStatus($public, 200, 'The approved public listing is unavailable.');
        expect(str_contains($public[2], $fixture['title']), 'The public view should show the approved listing headline.');
        disclosureSafe($public[2], $fixture['restricted']);

        $new = $ownerRoute . '?new=1&ref=' . $fixture['reference'];
        $create = [
            'action' => 'create', 'vacancy_id' => $fixture['vacancy'], 'ref' => $fixture['reference'],
            'purpose' => 'Private recruitment fixture', 'requirements' => 'Secondary mathematics appointment.',
        ];
        disclosureStatus($school->send($ownerRoute, 'POST', $create), 403, 'Case creation accepted a missing CSRF token.');
        disclosureDenied(disclosurePost($pendingSchool, $ownerRoute, '/dashboard/school', $create), 'A pending school created a case.');
        disclosureDenied(disclosurePost($school, $ownerRoute, $new, ['vacancy_id' => $fixture['otherVacancy']] + $create),
            'A school linked a vacancy it does not own.');
        disclosureDenied(disclosurePost($teacher, $ownerRoute, '/dashboard/teacher', $create), 'A teacher created a disclosure case as a school.');
        $response = disclosurePost($school, $ownerRoute, $new, ['owner_id' => $fixture['schoolIds']['other-school']] + $create);
        disclosureStatus($response, 303, 'The approved school could not open a candidate request.');
        $case = (int) disclosureRow($db, 'SELECT id FROM candidate_requests')['id'];
        $detail = $ownerRoute . '?id=' . $case;
        $staffDetail = $staffRoute . '?id=' . $case;
        redirectIs($response, $detail);
        disclosureStatus($otherSchool->send($detail), 404, 'Another school read a private case.');
        disclosureStatus($parent->send($detail), 404, 'A parent read a private school case.');
        disclosureSafe(disclosurePage($school, $detail), $fixture['restricted']);

        $staffPost = static fn (string $action, array $fields = []): array
            => disclosurePost($staff, $staffRoute, $staffDetail, ['id' => $case, 'action' => $action] + $fields);
        $ownerPost = static fn (string $scope, string $ref, array $fields = []): array
            => disclosurePost($school, $ownerRoute, $detail, $fields + ['id' => $case, 'action' => 'request', 'ref' => $ref,
                'scope' => $scope, 'ack' => '1', 'notice_version' => $policyVersion]);
        redirectIs($staffPost('claim'), $staffDetail);
        disclosureStatus(disclosurePost($otherStaff, $staffRoute, '/dashboard/admin', [
            'action' => 'add', 'id' => $case, 'ref' => $fixture['reference'],
        ]), 403, 'An unassigned staff account added a candidate.');
        disclosureDenied($staffPost('add', ['ref' => $fixture['otherAppRef']]), 'Staff added an applicant from a different vacancy.');
        redirectIs($staffPost('add', ['ref' => $fixture['reference']]), $staffDetail);
        redirectIs($staffPost('add', ['ref' => $fixture['appRef']]), $staffDetail);
        expect(!str_contains(disclosurePage($school, $detail), $fixture['appRef']), 'An unpublished shortlist appeared for the owner.');
        disclosureDenied($ownerPost('original_cv', $fixture['appRef']), 'An unpublished shortlist allowed a disclosure request.');
        redirectIs($staffPost('publish'), $staffDetail);
        $shortlist = disclosurePage($school, $detail);
        expect(str_contains($shortlist, $fixture['title']) && str_contains($shortlist, $fixture['appRef']),
            'The published shortlist should show the approved listing headline and the linked candidate reference.');
        disclosureSafe($shortlist, $fixture['restricted']);
        disclosureSafe(disclosurePage($otherSchool, $ownerRoute), [$fixture['appRef'], $create['purpose']]);

        $requestFields = ['id' => $case, 'action' => 'request', 'ref' => $fixture['reference'], 'scope' => 'original_cv',
            'ack' => '1', 'notice_version' => $policyVersion];
        disclosureDenied(disclosurePost($otherSchool, $ownerRoute, '/dashboard/school', $requestFields), 'A school requested disclosure from another school’s case.');
        disclosureDenied($ownerPost('original_cv', $fixture['reference'], ['ack' => '']), 'The requester skipped purpose acknowledgement.');
        disclosureStatus($ownerPost('original_cv', $fixture['reference'], ['notice_version' => 'retired-version']), 409,
            'A stale form acknowledged a newer disclosure notice.');
        disclosureDenied($ownerPost('original_cv', $fixture['otherAppRef']), 'A requester asked for an unlisted candidate.');
        disclosureDenied($ownerPost('personal', $fixture['reference']), 'The requester used an unsupported disclosure scope.');
        redirectIs($ownerPost('original_cv', $fixture['reference'], ['teacher_id' => $fixture['applicant']]), $detail);
        redirectIs($ownerPost('professional', $fixture['reference']), $detail);
        redirectIs($ownerPost('professional', $fixture['appRef']), $detail);
        $disclosureQueue = $staffRoute . '?queue=disclosure';
        expect(str_contains(disclosurePage($staff, $disclosureQueue), $create['purpose']), 'A pending CV request was missing from the disclosure queue.');
        disclosureStatus($school->send($disclosureQueue), 403, 'An owner opened the staff disclosure queue.');
        $requestId = static fn (string $scope, string $ref): int => (int) disclosureRow($db,
            'SELECT id FROM candidate_disclosure_requests WHERE case_id = ? AND scope = ? AND reference = ?', [$case, $scope, $ref])['id'];
        $originalRequest = $requestId('original_cv', $fixture['reference']);
        $profileRequest = $requestId('professional', $fixture['reference']);
        $applicantRequest = $requestId('professional', $fixture['appRef']);
        expect((int) $db->query('SELECT COUNT(*) FROM candidate_disclosure_grants')->fetchColumn() === 0,
            'A request automatically granted access.');
        disclosureDenied($school->send($profileRoute . '?id=' . $profileRequest), 'A guessed profile URL bypassed approval.');
        disclosureDenied(disclosurePost($school, $downloadRoute, $detail, ['id' => $originalRequest]), 'A guessed download ID bypassed approval.');

        $evidence = 'External specific consent recorded with the teacher for this recipient and purpose: fixture consent record 19.';
        $approval = ['decision' => 'approve', 'reason' => 'Scoped fixture decision.', 'evidence' => $evidence, 'review_ok' => '1', 'hours' => '4',
            'notice_version' => $policyVersion];
        $originalApproval = ['disclosure_id' => $originalRequest, 'document_id' => $fixture['cv']['id']] + $approval;
        disclosureDenied(disclosurePost($teacher, $staffRoute, '/dashboard/teacher', ['id' => $case, 'action' => 'decide'] + $originalApproval),
            'A candidate approved their own disclosure.');
        disclosureDenied(disclosurePost($otherStaff, $staffRoute, '/dashboard/admin', ['id' => $case, 'action' => 'decide'] + $originalApproval),
            'An unassigned staff account approved disclosure.');
        disclosureDenied($staffPost('decide', $originalApproval), 'Approval accepted a CV that staff had not reviewed.');
        disclosureAcceptCv($staff, $fixture['cv']['id']);
        disclosureAcceptCv($staff, $fixture['otherCv']['id']);
        foreach ([['evidence' => ''], ['review_ok' => ''], ['hours' => '25'], ['document_id' => $fixture['otherCv']['id']]] as $invalid) {
            disclosureDenied($staffPost('decide', $invalid + $originalApproval), 'An invalid original CV decision succeeded.');
        }
        $staffDownload = '/dashboard/admin/verification/documents/download?id=' . $fixture['cv']['id'];
        $staffReview = '/dashboard/admin/verification/documents?id=' . $fixture['cv']['id'];
        $decisionPage = disclosurePage($staff, $staffDetail . '&disclosure=' . $originalRequest);
        expect(str_contains($decisionPage, $staffReview) || str_contains($decisionPage, $staffDownload),
            'The disclosure decision page must link to staff review of the exact current CV.');
        disclosureStatus($school->send($staffDownload), 403, 'The owner could download via the administrator endpoint.');
        expect($staff->send($staffDownload)[2] === $fixture['cv']['bytes'], 'The staff-only CV review did not return the fixture bytes.');
        disclosureStatus($staffPost('decide', ['notice_version' => 'retired-version'] + $originalApproval), 409,
            'A stale staff form approved access under a newer disclosure policy.');
        redirectIs($staffPost('decide', $originalApproval), $staffDetail);
        redirectIs($ownerPost('original_cv', $fixture['reference']), $detail);
        $duplicate = disclosureRow($db, "SELECT id FROM candidate_disclosure_requests
            WHERE case_id = ? AND scope = 'original_cv' AND status = 'pending'", [$case]);
        disclosureStatus($staffPost('decide', ['disclosure_id' => $duplicate['id']] + $originalApproval), 409,
            'Management issued a second active grant for the same exact CV and case.');
        redirectIs($staffPost('decide', ['disclosure_id' => $duplicate['id'], 'decision' => 'reject'] + $originalApproval), $staffDetail);

        $revision = static fn (int $teacherId): int => (int) disclosureRow($db,
            'SELECT verification_revision FROM teacher_profiles WHERE user_id = ?', [$teacherId])['verification_revision'];
        $profileApproval = ['disclosure_id' => $profileRequest, 'profile_revision' => $revision($fixture['teacher'])] + $approval;
        disclosureDenied($staffPost('decide', ['hours' => '169'] + $profileApproval), 'Profile approval exceeded its lifetime limit.');
        disclosureDenied($staffPost('decide', ['profile_revision' => $profileApproval['profile_revision'] + 1] + $profileApproval),
            'Approval accepted a different profile version.');
        redirectIs($staffPost('decide', $profileApproval), $staffDetail);
        $grant = static fn (int $requestId): array => disclosureRow($db, 'SELECT * FROM candidate_disclosure_grants WHERE disclosure_id = ?', [$requestId]);
        $original = $grant($originalRequest);
        $professional = $grant($profileRequest);
        expect((int) $original['recipient_id'] === $fixture['schoolIds']['school'] && (int) $original['teacher_id'] === $fixture['teacher']
            && (int) $original['document_id'] === $fixture['cv']['id'] && $original['policy_version'] === $policyVersion
            && $original['lawful_basis'] === 'specific_consent' && $original['evidence_reference'] === $evidence
            && (int) $original['approved_by'] === $fixture['staff'],
            'The CV grant must bind the session owner, requested teacher, reviewed document and active policy.');
        expect((int) $professional['profile_revision'] === $profileApproval['profile_revision'],
            'The profile grant must bind the reviewed revision.');
        $snapshot = json_decode($professional['profile_data'], true, flags: JSON_THROW_ON_ERROR);
        expect(count($snapshot) === 2 && count($snapshot['qualifications']) === 1 && count($snapshot['experience']) === 1
            && count($snapshot['qualifications'][0]) === 3 && count($snapshot['experience'][0]) === 3,
            'The approved snapshot stores only allowlisted professional fields.');
        disclosureSafe($professional['profile_data'], $fixture['restricted']);

        $originalId = (int) $original['id'];
        $professionalId = (int) $professional['id'];
        $profile = $profileRoute . '?id=' . $professionalId;
        disclosureDenied($school->send($downloadRoute . '?id=' . $originalId), 'A GET downloaded the original CV.');
        disclosureStatus($school->send($downloadRoute, 'POST', ['id' => $originalId]), 403, 'A download accepted a missing CSRF token.');
        disclosureDenied($school->send($profileRoute . '?id=' . $originalId), 'A CV grant exposed the professional profile.');
        disclosureDenied(disclosurePost($school, $downloadRoute, $detail, ['id' => $professionalId]), 'A profile grant exposed the original CV.');
        disclosureDenied(disclosurePost($otherSchool, $downloadRoute, '/dashboard/school', ['id' => $originalId]), 'Another school downloaded the CV.');
        disclosureDenied($otherSchool->send($profile), 'Another school viewed the approved profile.');
        disclosureDenied($otherParent->send($profile), 'An unrelated parent viewed the approved profile.');
        redirectIs($guest->send($profile), '/login');

        $body = disclosurePage($school, $profile);
        expect(str_contains($body, 'BEd Mathematics') && str_contains($body, 'Mathematics Instructor'),
            'The scoped profile should show approved qualifications and positions.');
        disclosureSafe($body, [...$fixture['restricted'], $evidence]);
        disclosureSafe(disclosurePage($school, $detail), [...$fixture['restricted'], $evidence]);
        $download = static fn (): array => disclosurePost($school, $downloadRoute, $detail, ['id' => $originalId]);
        [$status, $headers, $bytes] = $download();
        expect($status === 200 && $bytes === $fixture['cv']['bytes']
            && in_array('no-store', array_map('trim', explode(',', $headers['cache-control'] ?? '')), true)
            && ($headers['content-type'] ?? '') === 'application/pdf'
            && ($headers['content-disposition'] ?? '') === 'attachment; filename="candidate-cv.pdf"',
            'An approved CV download must return the exact reviewed bytes in a private attachment.');
        $record = disclosureRow($db, 'SELECT * FROM candidate_disclosure_downloads WHERE grant_id = ?', [$originalId]);
        expect((int) $record['recipient_id'] === $fixture['schoolIds']['school'] && (int) $record['document_id'] === $fixture['cv']['id'],
            'The successful download must record the recipient and document. Failed attempts must not create success records.');
        $downloadAudit = disclosureRow($db, "SELECT actor_user_id, context FROM audit_logs WHERE action = 'disclosure.cv_downloaded'");
        expect((int) $downloadAudit['actor_user_id'] === $fixture['schoolIds']['school'], 'The download audit must identify the authenticated recipient.');
        disclosureSafe($downloadAudit['context'], [...$fixture['restricted'], $evidence]);
        echo "PASS scoped candidate cases, published shortlists, explicit disclosure and exact private assets.\n";

        $applicantApproval = ['disclosure_id' => $applicantRequest, 'profile_revision' => $revision($fixture['applicant'])] + $approval;
        $disabled = ['DISCLOSURE_PRIVACY_NOTICE_URL' => 'http://insecure.example.test/privacy'] + $environment;
        serve($root, $disabled, $work . '/disabled-server.log', static function (string $disabledBase) use (
            $school, $staff, $profile, $downloadRoute, $detail, $originalId, $staffRoute, $staffDetail, $case, $applicantApproval,
        ): void {
            $owner = new AuthBrowser($disabledBase);
            $owner->cookie = $school->cookie;
            $manager = new AuthBrowser($disabledBase);
            $manager->cookie = $staff->cookie;
            disclosureDenied($owner->send($profile), 'An incomplete policy configuration allowed profile access.');
            disclosureDenied(disclosurePost($owner, $downloadRoute, $detail, ['id' => $originalId]),
                'An incomplete policy configuration allowed a CV download.');
            disclosureDenied(disclosurePost($manager, $staffRoute, $staffDetail, ['id' => $case, 'action' => 'decide'] + $applicantApproval),
                'An incomplete policy configuration allowed a new approval.');
        });
        $db->prepare('UPDATE candidate_disclosure_grants SET expires_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = ?')
            ->execute([$professionalId]);
        disclosureDenied($school->send($profile), 'An expired grant still showed the private profile.');
        $db->prepare('UPDATE candidate_disclosure_grants SET expires_at = ? WHERE id = ?')->execute([$professional['expires_at'], $professionalId]);
        $profiles->save($fixture['teacher'], Section::Qualifications, ['rows' => [[
            'qualification' => 'MEd Mathematics', 'institution' => 'Replacement Teaching College',
            'field_of_study' => 'Mathematics', 'completed_year' => 2024,
        ]]]);
        disclosureDenied($school->send($profile), 'A stale profile revision remained accessible after professional edits.');
        redirectIs($staffPost('revoke', ['grant_id' => $originalId, 'reason' => 'Fixture revocation.']), $staffDetail);
        disclosureDenied($download(), 'A revoked CV grant still downloaded.');
        expect($grant($originalRequest)['revoked_at'] !== null, 'Staff revocation must be durable.');

        redirectIs($staffPost('decide', $applicantApproval), $staffDetail);
        $applicantProfile = $profileRoute . '?id=' . $grant($applicantRequest)['id'];
        expect(str_contains(disclosurePage($school, $applicantProfile), 'BEd Mathematics'),
            'A linked non-public applicant can share a reviewed professional profile.');
        $schoolReview = $db->prepare('UPDATE school_onboarding SET review_status = ? WHERE user_id = ?');
        $schoolReview->execute(['pending', $fixture['schoolIds']['school']]);
        disclosureDenied($school->send($applicantProfile), 'A school that lost approval retained access to a private profile.');
        $schoolReview->execute(['approved', $fixture['schoolIds']['school']]);
        $privacy = '/privacy/controls';
        redirectIs($applicant->submit($privacy, ['action' => 'pause']), $privacy);
        disclosureDenied($school->send($applicantProfile), 'Teacher pause left a live disclosure grant.');
        expect($grant($applicantRequest)['revoked_at'] !== null, 'Pausing disclosure must revoke existing grants durably.');
        redirectIs($applicant->submit($privacy, ['action' => 'resume']), $privacy);
        disclosureDenied($school->send($applicantProfile), 'Teacher resume revived a revoked grant.');
        echo "PASS fail-closed policy, expiry, profile versioning, staff revocation and teacher pause.\n";

        $privacyDetail = 'Review the fixture <private instruction>.';
        $privacyRequest = ['action' => 'submit', 'right_type' => 'access', 'details' => $privacyDetail, 'account_id' => $fixture['teacher']];
        disclosureStatus($applicant->send($privacy, 'POST', $privacyRequest), 403, 'Privacy submission accepted a missing CSRF token.');
        $submission = $applicant->submit($privacy, $privacyRequest);
        $right = disclosureRow($db, 'SELECT id, account_id, status FROM candidate_privacy_requests');
        expect((int) $right['account_id'] === $fixture['applicant'] && $right['status'] === 'submitted', 'Privacy requests must belong to the authenticated account.');
        $rightPath = $privacy . '?id=' . $right['id'];
        $privacyStaff = '/dashboard/admin/privacy-requests';
        $rightReview = $privacyStaff . '?id=' . $right['id'];
        redirectIs($submission, $rightPath);
        disclosureStatus($teacher->send($rightPath), 404, 'Another teacher viewed a private data rights request.');
        disclosureStatus($applicant->send($rightReview), 403, 'The requester opened the staff privacy workspace.');
        expect(str_contains(disclosurePage($staff, $rightReview), 'Review the fixture &lt;private instruction&gt;.'),
            'The staff privacy reviewer must see the escaped details.');
        $outcome = 'Verified identity outside the app and arranged the requested copy.';
        redirectIs($staff->submit($privacyStaff, ['id' => $right['id'], 'staff_note' => $outcome], $rightReview), $rightReview);
        expect(str_contains(disclosurePage($applicant, $rightPath), $outcome), 'The requester should see staff’s recorded response.');
        $audit = disclosureRows($db, "SELECT action, actor_user_id, context FROM audit_logs
            WHERE action IN ('privacy.request_submitted', 'privacy.request_resolved') ORDER BY id");
        expect(array_column($audit, 'action') === ['privacy.request_submitted', 'privacy.request_resolved']
            && array_map('intval', array_column($audit, 'actor_user_id')) === [$fixture['applicant'], $fixture['staff']],
            'Privacy right submission and resolution require distinct audit actors.');
        disclosureSafe(implode(' ', array_column($audit, 'context')), [$privacyDetail, $outcome]);
        echo "PASS private rights requests and attributable staff responses.\n";

        $parentRequests = '/dashboard/parent?tab=requests';
        $lesson = [
            'subject' => 'Private tutoring fixture', 'level' => 'primary', 'learners' => '1', 'mode' => 'online',
            'objectives' => 'Compare fractions.', 'days' => ['tue'], 'time_from' => ['16:30'],
            'time_to' => ['17:15'], 'sessions' => '1', 'ref' => $fixture['reference'],
        ];
        $draft = $parent->submit('/dashboard/parent/requests', $lesson, '/dashboard/parent?tab=request-teacher&ref=' . $fixture['reference']);
        disclosureStatus($draft, 303, 'The parent could not save a tutoring draft.');
        $tutoring = disclosureRow($db, 'SELECT id, updated_at FROM parent_tutoring_requests WHERE parent_id = ?', [$fixture['parentIds']['parent']]);
        $tutoringDetail = $parentRequests . '&id=' . $tutoring['id'];
        redirectIs($draft, $tutoringDetail);
        expect(disclosureRows($db, 'SELECT id FROM candidate_requests WHERE tutoring_id = ?', [$tutoring['id']]) === [],
            'A private tutoring draft became a staff disclosure case.');
        $submission = ['id' => $tutoring['id'], 'version' => $tutoring['updated_at']];
        redirectIs($parent->submit('/dashboard/parent/requests/submit', $submission, $tutoringDetail), $tutoringDetail);
        $parentCase = disclosureRow($db, 'SELECT id, requester_id, requester_role FROM candidate_requests WHERE tutoring_id = ?', [$tutoring['id']]);
        expect((int) $parentCase['requester_id'] === $fixture['parentIds']['parent'] && $parentCase['requester_role'] === 'parent',
            'The submitted tutoring case must be bound to its parent owner.');
        $parentId = (int) $parentCase['id'];
        $parentDetail = $ownerRoute . '?id=' . $parentId;
        $parentStaffDetail = $staffRoute . '?id=' . $parentId;
        disclosureStatus($otherParent->send($parentDetail), 404, 'Another parent viewed a private tutoring case.');
        disclosureStatus($school->send($parentDetail), 404, 'A school viewed a private tutoring case.');
        $parentStaffPost = static fn (string $action, array $fields = []): array
            => $staff->submit($staffRoute, ['id' => $parentId, 'action' => $action] + $fields, $parentStaffDetail);
        redirectIs($parentStaffPost('claim'), $parentStaffDetail);
        disclosureDenied($parentStaffPost('add', ['ref' => $fixture['appRef']]), 'A vacancy applicant was added to an unrelated tutoring shortlist.');
        redirectIs($parentStaffPost('add', ['ref' => $fixture['reference']]), $parentStaffDetail);
        redirectIs($parentStaffPost('publish'), $parentStaffDetail);
        $parentDisclosure = ['id' => $parentId] + $requestFields;
        redirectIs($parent->submit($ownerRoute, $parentDisclosure, $parentDetail), $parentDetail);
        $parentRequest = (int) disclosureRow($db, 'SELECT id FROM candidate_disclosure_requests WHERE case_id = ?', [$parentId])['id'];
        redirectIs($parentStaffPost('decide', ['disclosure_id' => $parentRequest] + $originalApproval), $parentStaffDetail);
        $parentGrant = (int) $grant($parentRequest)['id'];
        $parentDownload = static fn (): array => $parent->submit($downloadRoute, ['id' => $parentGrant], $parentDetail);
        expect($parentDownload()[2] === $fixture['cv']['bytes'], 'The assigned grant did not return the reviewed parent case CV.');
        disclosureDenied($school->submit($downloadRoute, ['id' => $parentGrant], $detail), 'A school downloaded a grant from another case.');
        $replacement = disclosureCv($db, $profiles, $work . '/documents', $fixture['teacher'],
            'private-replacement-cv.pdf', sampleCvPdf(['Replacement CV fixture']));
        disclosureAcceptCv($staff, $replacement['id']);
        expect(is_file($work . '/documents/' . $fixture['cv']['key']), 'Keep the old file on disk to verify current-version access checks.');
        disclosureDenied($parentDownload(), 'A grant followed a replaced CV instead of becoming inaccessible.');
        redirectIs($parentStaffPost('close', ['reason' => 'Tutoring case complete.']), $parentStaffDetail);
        disclosureDenied($parent->submit($ownerRoute, $parentDisclosure, $parentDetail), 'A closed case accepted new disclosure requests.');
        echo "PASS submitted tutoring case ownership, linked source isolation and replaced CV access.\n";
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

<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Directory\PublicDirectory;
use Furoeducare\Modules\Disclosure\CandidateRepository;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Training\TrainingRequests;
use Furoeducare\Modules\Vacancy\ApplicationRepository;
use Furoeducare\Modules\Vacancy\ApplicationStatus;
use Furoeducare\Modules\Verification\VerificationEvents;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';
require __DIR__ . '/support/school-fixtures.php';

$db = null;
try {
    $config = Config::load(dirname(__DIR__));
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_notifications_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an existing migrated disposable loopback notifications test schema.');
    $db = Database::connect($config);
    expect($db->query("SHOW COLUMNS FROM verification_notifications LIKE 'in_app_visible'")->fetch() !== false,
        'The existing schema includes central notification migrations.');
    $suffix = bin2hex(random_bytes(8));
    $read = static function (string $sql, array $params = []) use ($db): array {
        $query = $db->prepare($sql);
        $query->execute($params);
        return $query->fetchAll();
    };
    $accounts = static function (string $tag) use ($db, $suffix): array {
        $ids = [];
        foreach (['teacher', 'school', 'admin'] as $role) {
            $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
                ->execute([$tag . '-' . $role . '-' . $suffix . '@example.test', '!notification-domain']);
            $ids[$role] = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$ids[$role], $role]);
            if ($role === 'admin') {
                grantTestStaffRole($db, $ids[$role]);
            }
        }
        return $ids;
    };

    $db->beginTransaction();
    $ids = $accounts('interview');
    $db->prepare("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at)
        VALUES (?, 'teacher', 'PrivateCandidate', 'InterviewFixture', '+2348012345678', 'fixture', 'fixture', UTC_TIMESTAMP(6))")
        ->execute([$ids['teacher']]);
    $db->prepare("INSERT INTO school_onboarding (user_id, school_name) VALUES (?, 'Interview notification fixture')")->execute([$ids['school']]);
    $db->prepare("INSERT INTO vacancies (school_id, status, content) VALUES (?, 'published', JSON_OBJECT('title','PrivateVacancyFixture'))")
        ->execute([$ids['school']]);
    $vacancy = (int) $db->lastInsertId();
    $reference = 'CA-' . strtoupper(bin2hex(random_bytes(16)));
    $db->prepare("INSERT INTO vacancy_applications (vacancy_id, teacher_id, status, vacancy_revision, job_title, job_snapshot, candidate_reference)
        VALUES (?, ?, 'shortlisted', 1, 'PrivateVacancyFixture', JSON_OBJECT(), ?)")->execute([$vacancy, $ids['teacher'], $reference]);
    $application = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO placements (kind, application_id, requester_id, teacher_id, teacher_reference, assigned_admin_id, status)
        VALUES ('employment', ?, ?, ?, ?, ?, 'discussion_scheduled')")
        ->execute([$application, $ids['school'], $ids['teacher'], $reference, $ids['admin']]);
    $placement = (int) $db->lastInsertId();
    ApplicationRepository::recordPlacementStage($db, $application, $ids['admin'], ApplicationStatus::Shortlisted, ApplicationStatus::InterviewInvited);
    foreach ([1, 2] as $_retry) {
        VerificationEvents::record($db, $ids['admin'], $ids['teacher'], 'placement.discussion_scheduled',
            ['placement_id' => $placement], 'Discussion scheduled', 'View the discussion in your dashboard.');
    }
    $ledger = $read('SELECT event, category, path, in_app_visible FROM verification_notifications WHERE user_id = ?', [$ids['teacher']]);
    $mail = $read('SELECT template, payload FROM email_outbox WHERE user_id = ?', [$ids['teacher']]);
    expect(count($ledger) === 2 && count(array_filter($ledger, static fn (array $row): bool => (int) $row['in_app_visible'] === 1)) === 2,
        'Managed application stage and placement scheduling produce two visible updates, including an identical adapter retry.');
    expect(count(array_filter($mail, static fn (array $row): bool => $row['template'] === 'interview_invitation')) === 1,
        'Managed stage and scheduling produce exactly one interview invitation.');
    foreach ($mail as $item) {
        $payload = json_decode($item['payload'], true, flags: JSON_THROW_ON_ERROR);
        expect(array_diff(array_keys($payload), ['path', 'decision']) === [] && !str_contains($item['payload'], 'Private'),
            'Interview and application email payloads remain generic.');
    }
    expect(count(array_filter($ledger, static fn (array $row): bool => $row['path'] === '/placements?id=' . $placement && $row['category'] === 'recruitment')) === 1,
        'Interview navigation and shared category target the managed placement.');
    $db->rollBack();
    echo "PASS real managed interview hooks produce two inbox updates and one invitation, with idempotent scheduling retries.\n";

    $ids = $accounts('workflow');
    $connection = static fn (): PDO => $db;
    $schools = new SchoolRepository($connection);
    $schools->create($ids['school'], schoolFields('workflow-school-' . $suffix . '@example.test', 'Fixture password 192!',
        ['school_name' => 'Notification Domain School ' . $suffix, 'city' => 'DomainCity' . $suffix]));
    $db->prepare("UPDATE school_onboarding SET review_status = 'approved', approved_at = UTC_TIMESTAMP(6) WHERE user_id = ?")->execute([$ids['school']]);
    $db->prepare("INSERT INTO vacancies (school_id, status, content) VALUES (?, 'published', JSON_OBJECT('title','Generic vacancy'))")
        ->execute([$ids['school']]);
    $vacancy = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO candidate_requests (requester_id, requester_role, category, vacancy_id, purpose, requirements)
        VALUES (?, 'school', 'recruitment', ?, 'PrivatePurposeFixture', 'PrivateRequirementsFixture')")->execute([$ids['school'], $vacancy]);
    $case = (int) $db->lastInsertId();
    $candidates = new CandidateRepository($connection, new PublicDirectory($connection), $schools, new ParentRepository($connection));
    $candidates->manage($ids['admin'], $case, 'claim', null);
    $candidates->manage($ids['admin'], $case, 'claim', null);
    $claimed = $read("SELECT event, path FROM verification_notifications WHERE user_id = ? AND event = 'candidate.claim'", [$ids['school']]);
    $claimMail = $read("SELECT payload FROM email_outbox WHERE user_id = ? AND template = 'recruitment_update'", [$ids['school']]);
    expect(count($claimed) === 1 && $claimed[0]['path'] === '/candidate-requests' && count($claimMail) === 1,
        'Repeated claim by the existing assignee produces one owner notification and email.');
    expect(json_decode($claimMail[0]['payload'], true, flags: JSON_THROW_ON_ERROR)['path'] === '/candidate-requests'
        && !str_contains($claimMail[0]['payload'], 'Private'), 'Candidate owner mail uses generic authorised case navigation.');
    echo "PASS candidate claim retries avoid duplicate owner delivery and preserve generic case links.\n";

    $db->prepare("INSERT INTO training_modules (title, description, category, objectives, target_participants, duration,
        delivery_format, prerequisites, materials_metadata, status, created_by, published_at)
        VALUES ('Notification training fixture', 'Generic module description', 'Teaching', 'Improve teaching', 'Teachers',
        'Two hours', 'online', 'None', 'Workbook description', 'published', ?, UTC_TIMESTAMP(6))")->execute([$ids['admin']]);
    $module = (int) $db->lastInsertId();
    $training = new TrainingRequests($connection);
    $input = ['participants' => '12', 'location' => 'PrivateTrainingLocation', 'preferred_dates' => gmdate('Y-m-d', time() + 86400 * 8),
        'requirements' => 'PrivateTrainingRequirements'];
    $token = bin2hex(random_bytes(16));
    $request = $training->submit($ids['school'], $input, [$module], null, $token);
    expect($training->submit($ids['school'], $input, [$module], null, $token) === $request, 'Training submission retry returns the same request.');
    $training->change($ids['admin'], $request, 1, 'under_review', []);
    $schoolTraining = $read("SELECT event_key, event, path FROM verification_notifications WHERE user_id = ? AND event LIKE 'training.%' ORDER BY id", [$ids['school']]);
    expect(count($schoolTraining) === 2 && $schoolTraining[0]['event'] === 'training.submitted' && $schoolTraining[1]['event'] === 'training.under_review',
        'Training submission retry emits once and the next revision emits its own status event.');
    expect($schoolTraining[0]['event_key'] === hash('sha256', 'training.submitted:' . $request . ':revision:1')
        && $schoolTraining[1]['event_key'] === hash('sha256', 'training.under_review:' . $request . ':revision:2'),
        'Training central source identities include request revisions.');
    foreach ($schoolTraining as $item) {
        expect($item['path'] === '/dashboard/school/training?id=' . $request, 'Training owner destination targets the current request.');
    }
    $adminTraining = $read("SELECT path FROM verification_notifications WHERE user_id = ? AND event = 'training.new_request'", [$ids['admin']]);
    expect(count($adminTraining) === 1 && $adminTraining[0]['path'] === '/dashboard/admin/training/requests?id=' . $request,
        'Training staff announcement emits once with its management destination.');
    $trainingMail = $read("SELECT user_id, payload FROM email_outbox WHERE user_id IN (?, ?) AND template = 'training_confirmation'", [$ids['school'], $ids['admin']]);
    expect(count($trainingMail) === 3, 'Two school events and one new-request staff event queue generic training mail.');
    foreach ($trainingMail as $item) {
        $payload = json_decode($item['payload'], true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($payload) === ['path'] && !str_contains($item['payload'], 'Private'), 'Training mail excludes intake details and free text.');
    }
    echo "PASS training submit and review hooks use distinct revision identities, role-specific destinations and generic payloads.\n";
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

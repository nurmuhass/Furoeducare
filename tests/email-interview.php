<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Modules\Vacancy\ApplicationRepository;
use Furoeducare\Modules\Vacancy\ApplicationStatus;
use Furoeducare\Modules\Verification\VerificationEvents;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/staff-fixtures.php';

$db = null;
try {
    $config = Config::load(dirname(__DIR__));
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_email_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use a migrated disposable loopback email test schema.');
    $db = Database::connect($config);
    $db->beginTransaction();
    $accounts = [];
    foreach (['teacher', 'school', 'admin'] as $role) {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))')
            ->execute([bin2hex(random_bytes(8)) . '@example.test', '!event-fixture']);
        $accounts[$role] = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$accounts[$role], $role]);
        if ($role === 'admin') {
            grantTestStaffRole($db, $accounts[$role]);
        }
    }
    $db->prepare("INSERT INTO teacher_profiles (user_id, category, first_name, last_name, phone, terms_version, privacy_version, accepted_at)
        VALUES (?, 'teacher', 'Interview', 'Fixture', '+2348012345678', 'fixture', 'fixture', UTC_TIMESTAMP(6))")
        ->execute([$accounts['teacher']]);
    $db->prepare("INSERT INTO school_onboarding (user_id, school_name) VALUES (?, 'Interview fixture school')")->execute([$accounts['school']]);
    $db->prepare("INSERT INTO vacancies (school_id, status, content) VALUES (?, 'published', JSON_OBJECT('title','Fixture vacancy'))")
        ->execute([$accounts['school']]);
    $vacancy = (int) $db->lastInsertId();
    $reference = 'CA-' . strtoupper(bin2hex(random_bytes(16)));
    $db->prepare("INSERT INTO vacancy_applications (vacancy_id, teacher_id, status, vacancy_revision, job_title, job_snapshot, candidate_reference)
        VALUES (?, ?, 'shortlisted', 1, 'Fixture vacancy', JSON_OBJECT(), ?)")->execute([$vacancy, $accounts['teacher'], $reference]);
    $application = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO placements (kind, application_id, requester_id, teacher_id, teacher_reference, assigned_admin_id, status)
        VALUES ('employment', ?, ?, ?, ?, ?, 'discussion_scheduled')")
        ->execute([$application, $accounts['school'], $accounts['teacher'], $reference, $accounts['admin']]);
    $placement = (int) $db->lastInsertId();
    ApplicationRepository::recordPlacementStage($db, $application, $accounts['admin'], ApplicationStatus::Shortlisted, ApplicationStatus::InterviewInvited);
    VerificationEvents::record($db, $accounts['admin'], $accounts['teacher'], 'placement.discussion_scheduled',
        ['placement_id' => $placement], 'Discussion scheduled', 'View the discussion in your dashboard.');
    $query = $db->prepare("SELECT COUNT(*) FROM email_outbox WHERE user_id = ? AND template = 'interview_invitation'");
    $query->execute([$accounts['teacher']]);
    expect((int) $query->fetchColumn() === 1, 'Managed scheduling and its companion application change send one invitation to the teacher.');
    $db->rollBack();
    echo "PASS managed interview scheduling queues one invitation per teacher.\n";
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

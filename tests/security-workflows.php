<?php

declare(strict_types=1);

use Furoeducare\Core\{Config, Database};
use Furoeducare\Modules\Auth\{Role, RateLimiter};
use Furoeducare\Modules\Directory\{PublicDirectory, DirectoryInteractions};
use Furoeducare\Modules\Disclosure\{CandidateRepository, DisclosurePolicy, DisclosureRepository, PrivacyRepository};
use Furoeducare\Modules\Parent\{ParentRepository, TutoringRepository};
use Furoeducare\Modules\School\SchoolRepository;
use Furoeducare\Modules\Teacher\{TeacherRepository, TeacherDocuments};
use Furoeducare\Modules\Messaging\{MessageRepository, MessageContext};
use Furoeducare\Modules\Vacancy\VacancyRepository;
use Furoeducare\Modules\Verification\TeacherEligibility;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/school-fixtures.php';
require __DIR__ . '/support/auth-browser.php';
require __DIR__ . '/support/cv-fixtures.php';

// This suite deliberately reuses a migrated test schema. Every fixture is uniquely named;
// cleanup touches only IDs created by this invocation and never resets shared tables.
$root = dirname(__DIR__);
$config = Config::load($root);
expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
    && preg_match('/\Afuroeducare_test_[a-z0-9_]+\z/D', $config->databaseName) === 1, 'Use a loopback test schema.');
$db = Database::connect($config);
$connection = static fn (): PDO => $db;
$run = 'security-workflows-' . bin2hex(random_bytes(8));
$work = $root . '/storage/local-preview/' . $run;
mkdir($work, 0700);
mkdir($work . '/documents', 0700);
$documentFiles = [];
$users = [];
$cases = [];
$tutoringIds = [];
$vacancyIds = [];
$disclosureIds = [];
$grantIds = [];
$conversationIds = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { expect($condition, $message); $checks++; };
$denied = static function (callable $operation, string $message) use ($assert): void {
    try { $operation(); } catch (DomainException $error) {
        $assert(in_array($error->getCode(), [403, 404, 409, 422], true), $message . ' Unexpected denial status.');
        return;
    }
    throw new RuntimeException($message . ' Operation was allowed.');
};
$sql = static function (string $query, array $values = []) use ($db): PDOStatement {
    $statement = $db->prepare($query); $statement->execute($values); return $statement;
};
try {
    $account = static function (string $role) use ($sql, $db, $run, &$users): int {
        $sql('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, UTC_TIMESTAMP(6))',
            [$run . '-' . count($users) . '@example.test', password_hash('Security fixture passphrase 802!', PASSWORD_DEFAULT)]);
        $id = $users[] = (int) $db->lastInsertId();
        $sql('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
        return $id;
    };
    $parent = $account('parent'); $otherParent = $account('parent');
    $school = $account('school'); $otherSchool = $account('school');
    $teacher = $account('teacher');
    $reviewer = $account('admin');
    $parents = new ParentRepository($connection); $schools = new SchoolRepository($connection);
    foreach ([$parent, $otherParent] as $id) $parents->create($id,
        ['first_name' => 'PrivateGuardian', 'last_name' => 'Fixture', 'phone' => '+2348012345601', 'state_code' => 'LA', 'city' => 'Ikeja']);
    foreach ([$school, $otherSchool] as $id) $schools->create($id,
        schoolFields($run . '-' . $id . '@example.test', 'Security fixture passphrase 802!', ['school_name' => $run . ' Academy ' . $id]));
    $sql("UPDATE school_onboarding SET review_status = 'approved', approved_at = UTC_TIMESTAMP(6) WHERE user_id = ?", [$school]);
    $profiles = new TeacherRepository($connection);
    $profiles->create($teacher, ['category' => 'teacher', 'first_name' => 'PRIVATE-FIRST', 'last_name' => 'PRIVATE-LAST', 'phone' => '+2348098765432']);
    $eligibility = new TeacherEligibility($connection, $profiles);
    try {
        Database::transaction($db, fn () => $eligibility->requireForPlacement($teacher));
        throw new RuntimeException('Incomplete unverified teacher received final placement eligibility.');
    } catch (DomainException $error) {
        $assert($error->getMessage() === 'Final placement requires current professional verification.', 'Final placement requires professional verification.');
    }
    $sql("UPDATE teacher_profiles SET published_at = UTC_TIMESTAMP(6), sections = ? WHERE user_id = ?",
        [json_encode(['availability' => ['status' => 'now', 'opportunities' => ['private_tutoring']], 'personal' => ['address' => 'PRIVATE-ADDRESS']]), $teacher]);
    $ref = 'FT-' . strtoupper(bin2hex(random_bytes(10)));
    $sql("INSERT INTO teacher_listings (teacher_id, reference, title, bio, qualification, experience, state_code, city,
        availability_mask, photograph, approval_basis, approved_at) VALUES (?, ?, 'Public tutor', 'Public bio', 'BEd', 3, 'LA', 'Ikeja', 4, ?, 'Fixture consent', UTC_TIMESTAMP(6))",
        [$teacher, $ref, 'authorised-photo-bytes']);
    $directory = new PublicDirectory($connection);
    $public = $directory->find($ref);
    $assert($public !== null && $directory->photograph($ref) === 'authorised-photo-bytes', 'Visible authorised listing and photo.');
    foreach (['teacher_id', 'first_name', 'last_name', 'phone', 'email', 'sections', 'approval_basis', 'photograph', 'id'] as $private)
        $assert(!array_key_exists($private, $public), 'Public projection excludes ' . $private);
    foreach ([['users', 'suspended_at', 'UTC_TIMESTAMP(6)', 'NULL', 'id'],
        ['users', 'email_verified_at', 'NULL', 'UTC_TIMESTAMP(6)', 'id'],
        ['teacher_profiles', 'published_at', 'NULL', 'UTC_TIMESTAMP(6)', 'user_id'],
        ['teacher_listings', 'approved_at', 'NULL', 'UTC_TIMESTAMP(6)', 'teacher_id']] as [$table, $column, $bad, $restore, $key]) {
        $sql("UPDATE $table SET $column = $bad WHERE $key = ?", [$teacher]);
        $assert($directory->find($ref) === null && $directory->photograph($ref) === null, 'Listing and photo fail closed after ' . $column);
        $sql("UPDATE $table SET $column = $restore WHERE $key = ?", [$teacher]);
    }
    $sql("INSERT INTO vacancies (school_id, content) VALUES (?, ?)", [$school, '{"title":"Private draft"}']);
    $vacancy = $vacancyIds[] = (int) $db->lastInsertId();
    $vacancies = new VacancyRepository($connection, $schools);
    $assert($vacancies->find($vacancy, $otherSchool) === null, 'School cannot read another school vacancy.');
    $denied(fn () => $vacancies->edit($vacancy, $otherSchool, 1, ['deadline' => '']), 'School cannot edit another school vacancy.');
    $denied(fn () => $vacancies->transition($vacancy, $otherSchool, false, 1, 'submit', ''), 'Pending unrelated school cannot submit vacancy.');
    $sql("INSERT INTO parent_tutoring_requests (parent_id, subject, requirements, lesson_details, status) VALUES (?, 'Mathematics', 'Private requirements', '{\"mode\":\"home\"}', 'submitted')", [$parent]);
    $tutoring = $tutoringIds[] = (int) $db->lastInsertId();
    $tutors = new TutoringRepository($connection, $directory);
    $assert($tutors->owned($otherParent, $tutoring) === null, 'Parent cannot read unrelated tutoring request.');
    $denied(fn () => $tutors->submit($otherParent, $tutoring, ''), 'Parent cannot submit unrelated tutoring request.');
    $candidates = new CandidateRepository($connection, $directory, $schools, $parents);
    $sql("INSERT INTO candidate_requests (requester_id, requester_role, category, tutoring_id, selected_teacher_id, selected_reference, purpose, requirements)
        VALUES (?, 'parent', 'tutoring', ?, ?, ?, 'Tutoring', 'Private requirements')", [$parent, $tutoring, $teacher, $ref]);
    $case = $cases[] = (int) $db->lastInsertId();
    $assert($candidates->ownerCase($otherParent, Role::Parent, $case) === null, 'Candidate case is owner scoped.');
    $assert(!$candidates->eligible($otherSchool, Role::School), 'Pending school cannot recruit.');
    $denied(fn () => $candidates->manage($parent, $case, 'claim', null), 'Parent cannot claim managed candidate case.');
    $sql("INSERT INTO candidate_disclosure_requests (case_id, teacher_id, reference, scope, status, notice_version)
        VALUES (?, ?, ?, 'professional', 'approved', ?)", [$case, $teacher, $ref, $config->disclosurePolicyVersion]);
    $disclosure = $disclosureIds[] = (int) $db->lastInsertId();
    $revision = (int) $sql('SELECT verification_revision FROM teacher_profiles WHERE user_id = ?', [$teacher])->fetchColumn();
    $sql("INSERT INTO candidate_disclosure_grants (disclosure_id, recipient_id, teacher_id, scope, profile_revision, profile_data,
        lawful_basis, evidence_reference, policy_version, expires_at) VALUES (?, ?, ?, 'professional', ?, ?, 'specific_consent', 'fixture-consent', ?, UTC_TIMESTAMP(6) + INTERVAL 1 HOUR)",
        [$disclosure, $parent, $teacher, $revision, '{"qualifications":[],"experience":[]}', $config->disclosurePolicyVersion]);
    $grant = $grantIds[] = (int) $db->lastInsertId();
    $disclosures = new DisclosureRepository($connection, $candidates, new DisclosurePolicy($config), $profiles,
        new TeacherDocuments($profiles, $work . '/documents', $root . '/public'));
    $denied(fn () => $disclosures->professional($otherParent, Role::Parent, $grant), 'Grant cannot be used by another recipient.');
    $denied(fn () => $disclosures->download($parent, Role::Parent, $grant), 'Professional scope cannot download original CV.');
    $assert((new DisclosurePolicy($config))->active(), 'Runner must enable the approved fixture disclosure policy.');
    $assert($disclosures->professional($parent, Role::Parent, $grant) == ['qualifications' => [], 'experience' => []], 'Current scoped grant allows intended recipient.');
    $sql('UPDATE teacher_profiles SET verification_revision = verification_revision + 1 WHERE user_id = ?', [$teacher]);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Changed profile invalidates previous disclosure.');
    $sql('UPDATE teacher_profiles SET verification_revision = ? WHERE user_id = ?', [$revision, $teacher]);
    $sql('UPDATE candidate_disclosure_grants SET expires_at = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = ?', [$grant]);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Expired grant denies access.');
    $sql('UPDATE candidate_disclosure_grants SET expires_at = UTC_TIMESTAMP(6) + INTERVAL 1 HOUR WHERE id = ?', [$grant]);
    foreach ([$teacher, $parent] as $suspended) {
        $sql('UPDATE users SET suspended_at = UTC_TIMESTAMP(6) WHERE id = ?', [$suspended]);
        $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Suspended participant cannot use grant.');
        $sql('UPDATE users SET suspended_at = NULL WHERE id = ?', [$suspended]);
    }
    foreach (['draft', 'closed'] as $status) {
        $sql('UPDATE candidate_requests SET status = ? WHERE id = ?', [$status, $case]);
        $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Inactive case cannot use grant.');
    }
    $sql("UPDATE candidate_requests SET status = 'submitted' WHERE id = ?", [$case]);
    $sql("UPDATE candidate_disclosure_requests SET status = 'rejected' WHERE id = ?", [$disclosure]);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Unapproved disclosure request cannot use grant.');
    $sql("UPDATE candidate_disclosure_requests SET status = 'approved' WHERE id = ?", [$disclosure]);
    $sql("UPDATE candidate_disclosure_grants SET policy_version = 'old-fixture-policy' WHERE id = ?", [$grant]);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Grant from old policy cannot be used.');
    $sql('UPDATE candidate_disclosure_grants SET policy_version = ? WHERE id = ?', [$config->disclosurePolicyVersion, $grant]);
    $sql("UPDATE teacher_profiles SET verification_status = 'suspended' WHERE user_id = ?", [$teacher]);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Professionally suspended teacher cannot be disclosed.');
    $assert($directory->photograph($ref) === null, 'Professionally suspended teacher photo denied.');
    $sql("UPDATE teacher_profiles SET verification_status = 'pending' WHERE user_id = ?", [$teacher]);
    $privacy = new PrivacyRepository($connection); $privacy->setPaused($teacher, true);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Teacher withdrawal denies access.');
    $privacy->setPaused($teacher, false);
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $grant), 'Resume does not resurrect revoked grant.');
    $cvBytes = sampleCvPdf(['Accepted workflow CV fixture', 'Mathematics teacher']);
    $cvKey = bin2hex(random_bytes(32));
    $cvPath = $documentFiles[] = $work . '/documents/' . $cvKey;
    $assert(file_put_contents($cvPath, $cvBytes) === strlen($cvBytes), 'Private accepted CV fixture stored.');
    $sql("INSERT INTO teacher_documents (teacher_id, kind, storage_key, display_name, media_type, bytes)
        VALUES (?, 'cv', ?, 'private-original-cv.pdf', 'application/pdf', ?)", [$teacher, $cvKey, strlen($cvBytes)]);
    $document = (int) $db->lastInsertId();
    $sql("INSERT INTO teacher_document_reviews (id, document_id, teacher_id, reviewer_id, kind, decision, reason)
        VALUES (?, ?, ?, ?, 'cv', 'accepted', 'Reviewed unique fixture CV')", [$document, $document, $teacher, $reviewer]);
    $sql("INSERT INTO candidate_disclosure_requests (case_id, teacher_id, reference, scope, status, notice_version)
        VALUES (?, ?, ?, 'original_cv', 'approved', ?)", [$case, $teacher, $ref, $config->disclosurePolicyVersion]);
    $originalRequest = $disclosureIds[] = (int) $db->lastInsertId();
    $sql("INSERT INTO candidate_disclosure_grants (disclosure_id, recipient_id, teacher_id, scope, document_id,
        lawful_basis, evidence_reference, policy_version, approved_by, expires_at)
        VALUES (?, ?, ?, 'original_cv', ?, 'specific_consent', 'fixture-original-consent', ?, ?, UTC_TIMESTAMP(6) + INTERVAL 1 HOUR)",
        [$originalRequest, $parent, $teacher, $document, $config->disclosurePolicyVersion, $reviewer]);
    $originalGrant = $grantIds[] = (int) $db->lastInsertId();
    $original = $disclosures->download($parent, Role::Parent, $originalGrant);
    $assert($original->status === 200 && $original->body === $cvBytes, 'Approved original CV transfer preserves exact PDF bytes.');
    $assert(($original->headers['Content-Type'] ?? '') === 'application/pdf'
        && ($original->headers['Content-Disposition'] ?? '') === 'attachment; filename="candidate-cv.pdf"'
        && ($original->headers['X-Content-Type-Options'] ?? '') === 'nosniff', 'Original CV transfer has safe attachment headers.');
    $assert((int) $sql('SELECT COUNT(*) FROM candidate_disclosure_downloads WHERE grant_id = ? AND recipient_id = ? AND document_id = ?',
        [$originalGrant, $parent, $document])->fetchColumn() === 1, 'Successful original CV transfer records exact grant, recipient and document.');
    $assert((int) $sql("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = ? AND action = 'disclosure.cv_downloaded' AND context->>'$.grant_id' = ? AND context->>'$.document_id' = ?",
        [$parent, $originalGrant, $document])->fetchColumn() === 1, 'Successful original CV transfer records exact audit event.');
    $denied(fn () => $disclosures->download($otherParent, Role::Parent, $originalGrant), 'Original CV grant rejects wrong recipient.');
    $denied(fn () => $disclosures->download($parent, Role::Parent, PHP_INT_MAX), 'Original CV requires an actual grant.');
    $denied(fn () => $disclosures->professional($parent, Role::Parent, $originalGrant), 'Original CV grant cannot be used for professional scope.');
    $sql('UPDATE teacher_documents SET delete_requested_at = UTC_TIMESTAMP(6) WHERE id = ?', [$document]);
    $denied(fn () => $disclosures->download($parent, Role::Parent, $originalGrant), 'Replaced or deleted original CV version cannot be downloaded.');
    $sql('UPDATE teacher_documents SET delete_requested_at = NULL WHERE id = ?', [$document]);
    $assert((int) $sql('SELECT COUNT(*) FROM candidate_disclosure_downloads WHERE grant_id = ?', [$originalGrant])->fetchColumn() === 1,
        'Denied original CV attempts do not record successful downloads.');
    $sql("INSERT INTO message_conversations (owner_id, owner_role, subject, context_type, client_token) VALUES (?, 'parent', 'Private conversation', 'support', ?)", [$parent, bin2hex(random_bytes(16))]);
    $conversation = $conversationIds[] = (int) $db->lastInsertId();
    $messages = new MessageRepository($connection, new MessageContext($connection), new RateLimiter($connection));
    $denied(fn () => $messages->thread($otherParent, false, $conversation), 'Unrelated parent cannot read conversation.');
    $denied(fn () => $messages->send($otherParent, false, $conversation, 'Unauthorised message', bin2hex(random_bytes(16))), 'Unrelated parent cannot send to conversation.');
    $denied(fn () => (new MessageContext($connection))->requireOwned($otherParent, Role::Parent, 'tutoring', $tutoring), 'Messaging cannot link an unrelated tutoring request.');
    serve($root, ['APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log',
        'TEACHER_DOCUMENT_DIR' => $work . '/documents'],
        $work . '/server.log', static function (string $base) use ($sql, $assert, $parent, $originalGrant, $cvBytes, $otherParent, $otherSchool, $case, $grant, $conversation, $tutoring, $vacancy, $ref): void {
            $login = static function (int $id, string $role) use ($base, $sql): AuthBrowser {
                $browser = new AuthBrowser($base);
                $email = $sql('SELECT email FROM users WHERE id = ?', [$id])->fetchColumn();
                redirectIs($browser->submit('/login', ['email' => $email, 'password' => 'Security fixture passphrase 802!']), '/dashboard/' . $role);
                return $browser;
            };
            $parentBrowser = $login($otherParent, 'parent');
            $download = $parentBrowser->submit('/candidate-requests/download', ['id' => $originalGrant], '/candidate-requests');
            $assert(in_array($download[0], [403, 404], true) && $download[2] !== $cvBytes, 'HTTP original CV grant rejects wrong recipient.');
            $recipientBrowser = $login($parent, 'parent');
            $download = $recipientBrowser->submit('/candidate-requests/download', ['id' => $originalGrant], '/candidate-requests');
            $assert($download[0] === 200 && $download[2] === $cvBytes, 'HTTP approved original CV transfers exact accepted bytes.');
            $assert(($download[1]['content-disposition'] ?? '') === 'attachment; filename="candidate-cv.pdf"'
                && str_contains($download[1]['cache-control'] ?? '', 'no-store'), 'HTTP original CV uses attachment and no-store headers.');
            foreach (['/candidate-requests?id=' . $case, '/candidate-requests/profile?id=' . $grant,
                '/messages?id=' . $conversation, '/dashboard/parent?tab=requests&id=' . $tutoring] as $path) {
                $response = $parentBrowser->send($path);
                $assert(in_array($response[0], [403, 404], true), 'HTTP owner boundary denies ' . $path . ' (received ' . $response[0] . ')');
                $assert(!str_contains($response[2], 'Private requirements') && !str_contains($response[2], 'Private conversation'), 'HTTP denial contains no unrelated private record.');
            }
            $schoolBrowser = $login($otherSchool, 'school');
            $assert(in_array($schoolBrowser->send('/dashboard/school/vacancies?id=' . $vacancy)[0], [403, 404], true), 'HTTP school vacancy IDOR denied.');
            $assert($schoolBrowser->send('/candidate-requests?new=1&ref=' . $ref)[0] === 403, 'HTTP pending school recruitment bypass denied.');
            $public = request($base, '/find-teachers/profile?ref=' . $ref);
            $assert($public[0] === 200, 'Anonymous public profile remains usable.');
            foreach (['PRIVATE-FIRST', 'PRIVATE-LAST', 'PRIVATE-ADDRESS', '+2348098765432'] as $private)
                $assert(!str_contains($public[2], $private), 'HTTP public profile excludes private PII.');
        });
    $assert((int) $sql('SELECT COUNT(*) FROM candidate_disclosure_downloads WHERE grant_id = ? AND recipient_id = ? AND document_id = ?',
        [$originalGrant, $parent, $document])->fetchColumn() === 2, 'Service and HTTP each record one successful original CV download.');
    $assert((int) $sql("SELECT COUNT(*) FROM audit_logs WHERE actor_user_id = ? AND action = 'disclosure.cv_downloaded' AND context->>'$.grant_id' = ? AND context->>'$.document_id' = ?",
        [$parent, $originalGrant, $document])->fetchColumn() === 2, 'Service and HTTP each record one exact original CV audit event.');
    $sql("UPDATE candidate_disclosure_grants SET revoked_at = UTC_TIMESTAMP(6), revocation_reason = 'Fixture revocation' WHERE id = ?", [$originalGrant]);
    $denied(fn () => $disclosures->download($parent, Role::Parent, $originalGrant), 'Revoked original CV grant denies transfer.');
    $assert((int) $sql('SELECT COUNT(*) FROM candidate_disclosure_downloads WHERE grant_id = ?', [$originalGrant])->fetchColumn() === 2,
        'Revoked original CV attempt adds no successful download record.');
    $sql('INSERT INTO teacher_qualifications (teacher_id, qualification, institution, field_of_study, completed_year) VALUES (?, ?, ?, ?, 2020)',
        [$teacher, 'BEd Mathematics', 'Fixture College', 'Mathematics']);
    $completeSections = [
        'biography' => ['bio' => 'Complete professional fixture biography.'],
        'experience' => ['no_experience' => true],
        'subjects' => ['subjects' => ['Mathematics'], 'levels' => ['Primary']],
        'skills' => ['skills' => ['Lesson planning']], 'locations' => ['locations' => ['Lagos']],
        'preferences' => ['tutoring' => ['home']],
        'availability' => ['status' => 'now', 'opportunities' => ['private_tutoring']],
    ];
    $sql('UPDATE teacher_profiles SET sections = ? WHERE user_id = ?', [json_encode($completeSections), $teacher]);
    $assert(TeacherEligibility::complete($profiles->profile($teacher)), 'Accepted-CV fixture is fully complete before testing professional verification gate.');
    try {
        Database::transaction($db, fn () => $eligibility->requireForPlacement($teacher));
        throw new RuntimeException('Complete professionally unverified teacher received final eligibility.');
    } catch (DomainException $error) {
        $assert($error->getMessage() === 'Final placement requires current professional verification.', 'Complete unverified teacher still denied final eligibility.');
    }
    $sql("UPDATE teacher_profiles SET verification_status = 'verified', professional_verified_at = UTC_TIMESTAMP(6) WHERE user_id = ?", [$teacher]);
    $sql("INSERT INTO teacher_verification_reviews (teacher_id, reviewer_id, revision, decision, checks, reason)
        VALUES (?, ?, ?, 'verified', ?, 'Reviewed complete unique fixture')", [$teacher, $reviewer, $revision,
        json_encode(['identity' => true, 'qualifications' => true, 'professional_history' => true])]);
    $assert(Database::transaction($db, fn () => $eligibility->requireForPlacement($teacher)) === $revision,
        'Complete professionally verified teacher with accepted CV and matching review is eligible.');
    $sql('UPDATE teacher_profiles SET verification_revision = verification_revision + 1 WHERE user_id = ?', [$teacher]);
    try {
        Database::transaction($db, fn () => $eligibility->requireForPlacement($teacher));
        throw new RuntimeException('Stale professional review received final eligibility.');
    } catch (DomainException $error) {
        $assert($error->getMessage() === 'Final placement requires all professional checks to be confirmed.', 'Stale professional review cannot confirm final placement.');
    }
    echo "Security workflow checks passed: $checks\n";
} finally {
    foreach ($conversationIds as $id) $sql('DELETE FROM message_conversations WHERE id = ?', [$id]);
    foreach ($grantIds as $id) {
        $sql('DELETE FROM candidate_disclosure_downloads WHERE grant_id = ?', [$id]);
        $sql('DELETE FROM candidate_disclosure_grants WHERE id = ?', [$id]);
    }
    foreach ($disclosureIds as $id) $sql('DELETE FROM candidate_disclosure_requests WHERE id = ?', [$id]);
    foreach ($cases as $id) $sql('DELETE FROM candidate_requests WHERE id = ?', [$id]);
    foreach ($tutoringIds as $id) $sql('DELETE FROM parent_tutoring_requests WHERE id = ?', [$id]);
    foreach ($vacancyIds as $id) $sql('DELETE FROM vacancies WHERE id = ?', [$id]);
    if (isset($teacher)) {
        $sql('DELETE FROM teacher_document_reviews WHERE teacher_id = ?', [$teacher]);
        $sql('DELETE FROM teacher_documents WHERE teacher_id = ?', [$teacher]);
    }
    foreach ($users as $id) {
        $sql('DELETE FROM audit_logs WHERE actor_user_id = ?', [$id]);
        $sql('DELETE FROM users WHERE id = ?', [$id]);
    }
    foreach ($documentFiles as $path) if (is_file($path)) unlink($path);
}

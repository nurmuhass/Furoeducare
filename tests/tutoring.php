<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Parent\ParentRepository;
use Furoeducare\Modules\Teacher\Section;
use Furoeducare\Modules\Teacher\TeacherRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-tutoring-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_tutoring_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback database named furoeducare_test_tutoring_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty tutoring test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $migrations->withCurrentSchema((new SeedRunner($db, $root . '/database/seeders'))->run(...));
    expect(mkdir($work, 0700), 'Create the temporary test directory.');

    $password = 'Tutoring fixture passphrase 572!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $account = static function (string $name, string $role) use ($db, $hash): int {
        $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
            ->execute([$name . '@example.test', $hash]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?')->execute([$id, $role]);
        return $id;
    };
    $staff = $account('staff', 'admin');
    $ownerId = $account('parent', 'parent');
    $otherId = $account('other-parent', 'parent');
    $account('legacy-parent', 'parent');
    $teacherId = $account('private-tutor', 'teacher');
    $requestedId = $account('requested-tutor', 'teacher');
    $parents = new ParentRepository(static fn (): PDO => $db);
    foreach ([$ownerId, $otherId] as $parentId) {
        $parents->create($parentId, [
            'first_name' => 'Guardian', 'last_name' => 'Fixture', 'phone' => '+2348012345601', 'state_code' => 'LA', 'city' => 'Ikeja',
        ]);
    }
    $profiles = new TeacherRepository(static fn (): PDO => $db);
    $profiles->create($teacherId, [
        'category' => 'teacher', 'first_name' => 'PrivateGiven', 'last_name' => 'PrivateSurname', 'phone' => '+2348012345602',
    ]);
    $profiles->create($requestedId, [
        'category' => 'teacher', 'first_name' => 'Requested', 'last_name' => 'Fixture', 'phone' => '+2348012345603',
    ]);
    $profiles->save($requestedId, Section::Availability, ['status' => 'now', 'opportunities' => ['private_tutoring']]);
    foreach ([
        [Section::Biography, ['bio' => 'Private professional background.']],
        [Section::Qualifications, ['rows' => [[
            'qualification' => 'BEd', 'institution' => 'Private Fixture University', 'field_of_study' => 'Education', 'completed_year' => 2020,
        ]]]],
        [Section::Experience, ['rows' => [], 'no_experience' => true]],
        [Section::Subjects, ['subjects' => ['Mathematics'], 'levels' => ['Primary']]],
        [Section::Skills, ['skills' => ['Lesson planning']]],
        [Section::Locations, ['locations' => ['Lagos']]],
        [Section::Preferences, ['tutoring' => ['private_tutoring', 'online_tutoring']]],
        [Section::Availability, ['status' => 'now', 'opportunities' => ['private_tutoring', 'online_tutoring']]],
    ] as [$section, $values]) {
        $profiles->save($teacherId, $section, $values);
    }
    $db->prepare('UPDATE teacher_profiles SET published_at = CURRENT_TIMESTAMP(6) WHERE user_id IN (?, ?)')->execute([$teacherId, $requestedId]);
    $reference = 'FT-0123456789ABCDEF0123';
    $requestedReference = 'FT-1123456789ABCDEF0123';
    $title = 'Approved mathematics tutor';
    $listing = $db->prepare("INSERT INTO teacher_listings
        (teacher_id, reference, title, bio, qualification, experience, state_code, city, availability_mask, approval_basis, approved_at)
        VALUES (?, ?, ?, 'Mathematics lessons.', 'Bachelor of Education', 6, 'LA', 'Ikeja', ?, 'Private publication consent', CURRENT_TIMESTAMP(6))");
    $listing->execute([$teacherId, $reference, $title, 12]);
    $candListing = (int) $db->lastInsertId();
    $listing->execute([$requestedId, $requestedReference, 'Requested mathematics tutor', 4]);
    $db->prepare("INSERT INTO teacher_listing_tags (listing_id, kind, label) VALUES (?, 'subject', 'Mathematics')")
        ->execute([$candListing]);

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => 'http://127.0.0.1:8000',
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail',
        'APP_LOG_FILE' => $work . '/app.log',
    ], $work . '/server.log', function (string $base) use (
        $db, $password, $ownerId, $otherId, $staff, $teacherId, $requestedId, $reference, $requestedReference, $title,
    ): void {
        $dash = '/dashboard/parent';
        $editor = $dash . '?tab=request-teacher';
        $requests = $dash . '?tab=requests';
        $notifications = $dash . '?tab=notifications';
        $save = $dash . '/requests';
        $submit = $save . '/submit';
        $queue = '/dashboard/admin/tutoring-requests';
        $directory = '/find-teachers';
        $publicProfile = $directory . '/profile?ref=' . $reference;
        $guest = new AuthBrowser($base);
        $owner = new AuthBrowser($base);
        $other = new AuthBrowser($base);
        $admin = new AuthBrowser($base);
        $teacher = new AuthBrowser($base);
        $login = static fn (AuthBrowser $browser, string $name): array
            => $browser->submit('/login', ['email' => $name . '@example.test', 'password' => $password]);
        $read = static function (AuthBrowser $browser, string $path): string {
            [$status, $headers, $body] = $browser->send($path);
            expect($status === 200 && ($headers['cache-control'] ?? '') === 'no-store', "Expected a private, uncached page at $path.");
            return $body;
        };
        $request = static function (int $id) use ($db): array {
            $query = $db->prepare('SELECT * FROM parent_tutoring_requests WHERE id = ?');
            $query->execute([$id]);
            $row = $query->fetch();
            expect($row !== false, "Tutoring request $id exists.");
            return $row;
        };
        $newRequestId = static fn (): int => (int) $db->query('SELECT MAX(id) FROM parent_tutoring_requests')->fetchColumn();

        redirectIs($guest->send($queue), '/login');
        redirectIs($guest->send($requests), '/login');
        redirectIs($login($owner, 'parent'), $dash);
        redirectIs($login($other, 'other-parent'), $dash);
        redirectIs($login($admin, 'staff'), '/dashboard/admin');
        redirectIs($login($teacher, 'private-tutor'), '/dashboard/teacher');
        expect($owner->send($queue)[0] === 403 && $teacher->send($queue)[0] === 403, 'The tutoring queue requires staff access.');

        $selected = $editor . '&ref=' . $requestedReference;
        $legacy = new AuthBrowser($base);
        $selectedEntry = $directory . '/request?ref=' . $requestedReference;
        redirectIs($legacy->send($selectedEntry), '/login');
        redirectIs($login($legacy, 'legacy-parent'), $selectedEntry);
        redirectIs($legacy->send($selectedEntry), $selected);
        expect(str_contains($read($legacy, $selected), 'name="ref" value="' . $requestedReference . '"'),
            'An older parent account keeps the selected reference while finishing profile setup.');
        redirectIs($legacy->submit($dash . '/profile', ['ref' => $requestedReference]
            + parentFields('legacy-parent@example.test', $password), $selected), $selected);
        redirectIs($owner->send($directory . '/request?ref=' . $requestedReference), $selected);
        $form = $read($owner, $selected);
        expect(str_contains($form, $requestedReference), 'The parent editor carries the chosen public reference.');
        foreach (['subject', 'level', 'learners', 'objectives', 'mode', 'state_code', 'city', 'days[]', 'time_from[]', 'time_to[]',
            'sessions', 'qualifications', 'requirements'] as $field) {
            expect(str_contains($form, 'name="' . $field . '"'), "The parent can enter $field in the editor.");
        }
        $lesson = [
            'subject' => 'Private Weekday Mathematics', 'level' => 'primary', 'learners' => '2',
            'objectives' => 'Practice <fraction comparisons>.', 'mode' => 'home',
            'days' => ['tue', 'thu'], 'time_from' => ['16:30', '17:30'], 'time_to' => ['17:15', '18:15'],
            'sessions' => '2', 'qualifications' => 'Primary mathematics experience.',
            'requirements' => 'Private request accommodations.', 'ref' => $requestedReference,
        ];
        $draft = $owner->submit($save, $lesson, $selected);
        $id = $newRequestId();
        $detail = $requests . '&id=' . $id;
        $staffDetail = $queue . '?id=' . $id;
        redirectIs($draft, $detail);
        $version = $request($id)['updated_at'];
        expect($request($id)['selected_teacher_reference'] === $requestedReference, 'The saved draft keeps the approved listing reference.');
        expect($owner->submit($submit, ['id' => $id, 'version' => $version], $detail)[0] === 422,
            'Home lessons require a state and city before submission.');
        expect($admin->send($staffDetail)[0] === 404
            && $admin->submit($queue, ['id' => $id, 'version' => $version, 'action' => 'transition', 'status' => 'closed', 'note' => 'Draft closure attempt.'], $queue)[0] === 404
            && !str_contains($read($admin, $queue), $lesson['subject']), 'Staff can only see submitted tutoring requests.');
        expect($other->send($detail)[0] === 404 && $teacher->send($detail)[0] === 403
            && !str_contains($read($other, $requests), $lesson['subject']), 'Private drafts only appear for their owner.');

        $edit = ['id' => $id, 'version' => $version, 'state_code' => 'LA', 'city' => 'Ikeja'] + $lesson;
        expect($owner->submit($save, ['time_to' => ['15:00', '18:15']] + $edit, $editor . '&id=' . $id)[0] === 422,
            'A time range must end after it starts.');
        redirectIs($owner->submit($save, $edit, $editor . '&id=' . $id), $detail);
        $submission = ['id' => $id, 'version' => $request($id)['updated_at']];
        $reviewPage = $read($owner, $detail);
        expect(str_contains($reviewPage, 'action="' . $submit . '"')
            && str_contains($reviewPage, 'name="version" value="' . $submission['version'] . '"'),
            'The parent can submit the reviewed version from the tracking detail.');
        $availability = $db->prepare("UPDATE teacher_profiles SET sections = JSON_SET(sections, '$.availability.status', ?) WHERE user_id = ?");
        $availability->execute(['unavailable', $requestedId]);
        expect($owner->submit($submit, $submission, $detail)[0] === 409 && $request($id)['status'] === 'draft',
            'Submission rechecks that the chosen listing is still available.');
        $availability->execute(['now', $requestedId]);
        redirectIs($owner->submit($submit, $submission, $detail), $detail);
        redirectIs($owner->submit($submit, $submission, $detail), $detail);
        expect($request($id)['status'] === 'submitted' && str_contains($read($admin, $queue), $lesson['subject'])
            && !str_contains($read($admin, '/dashboard/admin/directory/requests'), $lesson['subject']),
            'Submitted tutoring requests enter the tutoring queue once and stay outside school directory intake.');
        $ownerTracking = $read($owner, $detail);
        $staffTracking = $read($admin, $staffDetail);
        foreach (['Practice &lt;fraction comparisons&gt;.', 'Tuesday', 'Thursday', '16:30', '17:15', '17:30', '18:15', 'Ikeja'] as $value) {
            expect(str_contains($ownerTracking, $value) && str_contains($staffTracking, $value),
                "Both the owner and staff can review the saved lesson detail $value.");
        }
        echo "PASS parent intake, home scheduling requirements, selected listing availability, and private queue enrollment.\n";

        $staffPost = static fn (array $fields): array => $admin->submit($queue, $fields + [
            'id' => $id, 'version' => $request($id)['updated_at'],
        ], $staffDetail);
        $transition = static fn (string $status, array $fields = []): array
            => $staffPost(['action' => 'transition', 'status' => $status] + $fields);
        $candidate = static fn (string $action): array => $staffPost(['action' => $action, 'ref' => $reference]);
        $review = ['action' => 'transition', 'status' => 'under_review', 'id' => $id, 'version' => $request($id)['updated_at']];
        expect(str_contains($read($admin, $staffDetail), 'parent@example.test')
            && !str_contains($read($other, $notifications), 'parent@example.test'), 'Staff can read the adult contact needed for coordination.');
        expect($admin->send($queue, 'POST', $review)[0] === 403
            && $owner->submit($queue, $review, $detail)[0] === 403, 'Staff writes require both role authorization and CSRF.');
        expect($transition('confirmed', ['ref' => $reference])[0] === 409, 'Staff cannot skip the matching and arrangement stages.');
        $note = 'Tutoring staff update <fixture evidence>.';
        redirectIs($transition('under_review', ['note' => $note]), $staffDetail);
        expect(str_contains($read($owner, $detail), 'Tutoring staff update &lt;fixture evidence&gt;.'),
            'The owner sees stage notes safely escaped in the private request history.');
        expect($transition('matching', ['version' => $review['version']])[0] === 409
            && $request($id)['status'] === 'under_review', 'Stale staff writes do not change the request.');
        redirectIs($transition('matching'), $staffDetail);
        expect(preg_match('/name="subject"[^>]*value=""/s', $read($admin, $staffDetail)) === 1,
            'A private request subject is not copied into the GET search field.');
        expect(str_contains($read($admin, $staffDetail . '&search=1&subject=Mathematics'), $title),
            'Staff can find available tutors by their approved directory subjects.');
        expect($transition('shortlisted')[0] === 409, 'A shortlist requires a candidate.');
        $availability->execute(['unavailable', $teacherId]);
        expect($candidate('add_candidate')[0] === 409, 'Staff cannot add an unavailable listing.');
        $availability->execute(['now', $teacherId]);
        redirectIs($candidate('add_candidate'), $staffDetail);
        expect(str_contains($read($admin, $staffDetail), $reference) && !str_contains($read($owner, $detail), $reference),
            'Potential candidates stay in the staff workspace before shortlisting.');
        redirectIs($candidate('remove_candidate'), $staffDetail);
        expect($transition('shortlisted')[0] === 409, 'Removing the only candidate leaves the shortlist empty.');
        redirectIs($candidate('add_candidate'), $staffDetail);
        $availability->execute(['unavailable', $teacherId]);
        expect($transition('shortlisted')[0] === 409, 'A now-unavailable candidate cannot publish a shortlist.');
        $availability->execute(['now', $teacherId]);
        redirectIs($transition('shortlisted'), $staffDetail);
        expect($candidate('remove_candidate')[0] === 409, 'A released shortlist must retain a compatible candidate.');
        expect($request($id)['status'] === 'shortlisted' && str_contains($read($admin, $staffDetail), $title),
            'Staff can shortlist a publicly listed teacher before professional verification.');
        $recommendations = $read($owner, $detail) . $read($owner, $dash . '?tab=recommended');
        expect(str_contains($recommendations, $title)
            && !str_contains($read($other, $dash . '?tab=recommended'), $title),
            'Only the requesting parent sees the recommendation.');
        $availability->execute(['unavailable', $teacherId]);
        expect(!str_contains($read($owner, $detail), $title), 'A paused listing disappears from parent recommendations.');
        expect($transition('arrangement_pending')[0] === 409, 'An unavailable shortlist cannot advance to arrangements.');
        $availability->execute(['now', $teacherId]);
        redirectIs($transition('arrangement_pending'), $staffDetail);
        echo "PASS staff authorization, legal transitions, candidate eligibility, and owner-only recommendations.\n";

        $db->prepare("INSERT INTO teacher_documents (teacher_id, kind, storage_key, display_name, media_type, bytes)
            VALUES (?, 'cv', ?, 'private-current-cv.pdf', 'application/pdf', 30)")
            ->execute([$teacherId, bin2hex(random_bytes(32))]);
        $documentId = (int) $db->lastInsertId();
        $db->prepare("INSERT INTO teacher_document_reviews (id, document_id, teacher_id, reviewer_id, kind, decision, reason)
            VALUES (?, ?, ?, ?, 'cv', 'accepted', 'Private CV review notes')")
            ->execute([$documentId, $documentId, $teacherId, $staff]);
        $db->prepare("UPDATE teacher_profiles SET verification_status = 'verified', verification_revision = 3,
            professional_verified_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?")->execute([$teacherId]);
        $checks = ['identity' => true, 'qualifications' => true, 'professional_history' => true];
        $db->prepare("INSERT INTO teacher_verification_reviews (teacher_id, reviewer_id, revision, decision, checks, reason)
            VALUES (?, ?, 3, 'verified', ?, 'Private professional verification evidence')")
            ->execute([$teacherId, $staff, json_encode($checks, JSON_THROW_ON_ERROR)]);
        expect($transition('confirmed', ['ref' => $reference])[0] === 409,
            'An administrator cannot confirm a fully verified tutor through the tutoring request endpoint.');
        $pending = $request($id);
        expect($pending['status'] === 'arrangement_pending' && $pending['confirmed_teacher_id'] === null,
            'A denied direct confirmation keeps the arrangement pending without a confirmed tutor.');
        echo "PASS tutoring confirmation requires the managed placement workflow.\n";

        $events = $db->prepare("SELECT event, title FROM verification_notifications WHERE user_id = ? AND event LIKE 'tutoring.%' ORDER BY id");
        $events->execute([$ownerId]);
        $ownerEvents = $events->fetchAll();
        expect(array_column($ownerEvents, 'event') === [
            'tutoring.submitted', 'tutoring.under_review', 'tutoring.matching', 'tutoring.shortlisted',
            'tutoring.arrangement_pending',
        ], 'Only recorded transitions notify the parent, with a single event for a replayed submission.');
        $events->execute([$otherId]);
        expect($events->fetchAll() === [], 'An unrelated parent receives no event for another parent’s request.');
        $arrangementTitle = htmlspecialchars($ownerEvents[array_key_last($ownerEvents)]['title'], ENT_QUOTES, 'UTF-8');
        $ownerNotifications = $read($owner, $notifications);
        expect($arrangementTitle !== '' && str_contains($ownerNotifications, $arrangementTitle)
            && !str_contains($read($other, $notifications . '&id=' . $id), $arrangementTitle), 'The notification tab is owner-scoped.');
        [$otherStatus, , $otherDetail] = $other->send($detail);
        expect($otherStatus === 404 && !str_contains($otherDetail, 'fixture evidence'),
            'A different parent cannot see the submitted request or its staff updates.');
        $ownerPages = $recommendations . $ownerNotifications . $read($owner, $detail) . $read($owner, $dash . '?tab=arrangements');
        foreach ([
            'private-tutor@example.test', '+2348012345602', 'PrivateGiven', 'PrivateSurname', 'Private Fixture University',
            'Private publication consent', 'private-current-cv.pdf', 'Private CV review notes',
            'Private professional verification evidence',
        ] as $secret) {
            expect(!str_contains($ownerPages, $secret), "The parent views must keep $secret private.");
        }
        foreach ([$directory, $publicProfile] as $path) {
            [$status, , $public] = $guest->send($path);
            expect($status === 200 && !str_contains($public, $lesson['subject']) && !str_contains($public, 'Private request accommodations.')
                && !str_contains($public, 'fixture evidence'), 'Public pages exclude tutoring request details and notes.');
        }

        $untargeted = ['subject' => 'Second private tutoring request', 'mode' => 'online', 'ref' => ''] + $lesson;
        $draft = $owner->submit($save, $untargeted, $editor);
        $closingId = $newRequestId();
        $closingDetail = $requests . '&id=' . $closingId;
        $staffClosingDetail = $queue . '?id=' . $closingId;
        redirectIs($draft, $closingDetail);
        redirectIs($owner->submit($submit, ['id' => $closingId, 'version' => $request($closingId)['updated_at']], $closingDetail), $closingDetail);
        $closing = ['id' => $closingId, 'version' => $request($closingId)['updated_at'], 'action' => 'transition', 'status' => 'closed'];
        foreach (['', '   '] as $blankNote) {
            expect($admin->submit($queue, ['note' => $blankNote] + $closing, $staffClosingDetail)[0] === 422
                && $request($closingId)['status'] === 'submitted', 'Staff must give a reason to close a request.');
        }
        redirectIs($admin->submit($queue, ['note' => 'Private closure reason.'] + $closing, $staffClosingDetail), $staffClosingDetail);
        $events->execute([$ownerId]);
        $latestEvent = array_slice($events->fetchAll(), -1)[0];
        expect($request($closingId)['status'] === 'closed' && str_contains($read($owner, $closingDetail), 'Private closure reason.')
            && $latestEvent['event'] === 'tutoring.closed', 'A reasoned closure updates the parent history and sends a notification.');
        $copyable = ['subject' => 'Keep existing lesson details', 'state_code' => 'LA', 'city' => 'Ikeja'] + $lesson;
        $draft = $owner->submit($save, $copyable, $selected);
        $convertedId = $newRequestId();
        $converted = $requests . '&id=' . $convertedId;
        redirectIs($draft, $converted);
        expect($request($convertedId)['selected_teacher_reference'] === $requestedReference, 'The new draft starts with the selected teacher.');
        $availability->execute(['unavailable', $requestedId]);
        redirectIs($owner->submit($save, ['id' => $convertedId, 'version' => $request($convertedId)['updated_at'],
            'general_request' => '1'] + $copyable, $editor . '&id=' . $convertedId), $converted);
        expect($request($convertedId)['selected_teacher_reference'] === null
            && $request($convertedId)['subject'] === $copyable['subject'], 'The parent can keep the draft and remove an unavailable specific tutor.');
        redirectIs($owner->submit($submit, ['id' => $convertedId, 'version' => $request($convertedId)['updated_at']], $converted), $converted);
        echo "PASS private parent updates and notifications, public projection isolation, and reasoned closure.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    foreach ([$work . '/app.log', $work . '/server.log'] as $log) {
        if (is_file($log)) {
            fwrite(STDERR, implode('', array_slice(file($log), -12)));
        }
    }
    $exit = 1;
} finally {
    foreach ([$work . '/mail', $work . '/sessions', $work] as $directory) {
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
exit($exit);

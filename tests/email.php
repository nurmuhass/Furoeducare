<?php

declare(strict_types=1);

use Furoeducare\Core\Config;
use Furoeducare\Core\Database;
use Furoeducare\Core\Database\MigrationRunner;
use Furoeducare\Core\Database\SeedRunner;
use Furoeducare\Modules\Email\Outbox;
use Furoeducare\Modules\Email\EmailWorker;
use Furoeducare\Modules\Email\EmailTemplates;
use Furoeducare\Modules\Email\MailTransport;
use Furoeducare\Modules\Email\TransportException;
use Furoeducare\Modules\Email\EmailPreferences;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';
require __DIR__ . '/support/auth-browser.php';

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . '/furo-email-' . bin2hex(random_bytes(6));
$exit = 0;
try {
    expect(class_exists(Outbox::class) && class_exists(EmailWorker::class), 'Transactional email outbox and worker are available.');
    foreach (EmailTemplates::names() as $template) {
        $payload = in_array($template, ['account_verification', 'password_reset', 'email_change'], true)
            ? ['token' => str_repeat('a', 64)] : ['path' => '/dashboard/teacher'];
        $rendered = EmailTemplates::render($template, $payload, 'https://education.example.test');
        expect($rendered['subject'] !== '' && $rendered['text'] !== '' && str_contains($rendered['html'], 'https://education.example.test/'),
            'Every supported template renders text, HTML and a configured-origin action.');
        expect(!str_contains($rendered['subject'], "\r") && !str_contains($rendered['subject'], "\n"),
            'Template subjects cannot inject mail headers.');
    }
    foreach (['https://attacker.example.test', '//attacker.example.test', '/dashboard/teacher?secret=private', '/messages#private'] as $path) {
        $rejected = false;
        try {
            EmailTemplates::render('new_application', ['path' => $path], 'https://education.example.test');
        } catch (InvalidArgumentException) { $rejected = true; }
        expect($rejected, 'Email action paths reject external destinations and unapproved query or fragment data.');
    }
    putenv('MAIL_TRANSPORT=file');
    putenv('AUTH_MAIL_DIR=' . $work . '/mail');
    putenv('APP_LOG_FILE=' . $work . '/app.log');
    putenv('MAIL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
    putenv('MAIL_FROM=notifications@example.test');
    $config = Config::load($root);
    expect($config->environment === 'development' && $config->databaseHost === '127.0.0.1'
        && preg_match('/\Afuroeducare_test_email_[a-z0-9_]+\z/', $config->databaseName) === 1,
        'Use an empty loopback database named furoeducare_test_email_<suffix>.');
    $db = Database::connect($config);
    expect($db->query('SHOW TABLES')->fetchAll() === [], 'Start with an empty email test schema.');
    $migrations = new MigrationRunner($db, $root . '/database/migrations');
    $migrations->migrate();
    $seed = new SeedRunner($db, $root . '/database/seeders');
    $migrations->withCurrentSchema($seed->run(...));
    expect(mkdir($work, 0700), 'Create private email test artifacts.');
    $db->prepare('INSERT INTO users (email, password_hash, email_verified_at) VALUES (?, ?, CURRENT_TIMESTAMP(6))')
        ->execute(['recipient@example.test', password_hash('Email test fixture password 712!', PASSWORD_DEFAULT)]);
    $user = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'teacher'")->execute([$user]);
    $connection = static fn (): PDO => $db;
    $worker = new EmailWorker($connection, $config);
    $row = static function (int $id) use ($db): array {
        $query = $db->prepare('SELECT * FROM email_outbox WHERE id = ?');
        $query->execute([$id]);
        return $query->fetch();
    };

    // Catches committing mail independently from the business transaction.
    $db->beginTransaction();
    Outbox::enqueue($db, 'rolled-back-event', $user, 'new_application', ['path' => '/dashboard/teacher/applications']);
    $db->rollBack();
    expect((int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0,
        'Rolling back the business transaction also removes its pending email.');

    // Catches duplicate provider submissions for one recipient and event.
    $id = Outbox::enqueue($db, 'application:42:updated', $user, 'new_application', ['path' => '/dashboard/teacher/applications']);
    $duplicate = Outbox::enqueue($db, 'application:42:updated', $user, 'new_application', ['path' => '/dashboard/teacher/applications']);
    expect($id === $duplicate && (int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 1,
        'Repeating the same business event returns its existing outbox row.');
    $rejected = false;
    try {
        Outbox::enqueue($db, 'application:42:updated', $user, 'new_application', ['path' => '/messages']);
    } catch (RuntimeException) { $rejected = true; }
    expect($rejected, 'An event key cannot silently acquire different notification content.');
    $rejected = false;
    try {
        Outbox::enqueue($db, 'private-business-note', $user, 'new_application', ['message' => 'Confidential recruitment discussion']);
    } catch (InvalidArgumentException) { $rejected = true; }
    expect($rejected && (int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 1,
        'Private business content is rejected before entering the queue.');
    $counts = $worker->run();
    expect($counts['accepted'] === 1 && $row($id)['status'] === 'accepted', 'File transport durably accepts the queued message.');
    $files = glob($work . '/mail/*.eml');
    expect(count($files) === 1, 'File transport writes a single MIME message.');
    $mime = file_get_contents($files[0]);
    expect(str_contains($mime, 'recipient@example.test') && preg_match('/^Message-ID:\s*<[^>]+>/mi', $mime) === 1,
        'Stored mail has the intended recipient and a stable Message-ID.');
    $worker->run();
    expect(count(glob($work . '/mail/*.eml')) === 1 && file_get_contents($files[0]) === $mime,
        'A repeated worker run does not resend or rewrite accepted mail.');

    // The injected transport exercises worker policy without external delivery.
    $failing = new class implements MailTransport {
        public int $calls = 0;
        public function send(array $row, array $rendered): array
        {
            $this->calls++;
            throw new TransportException('smtp_temporary', true, false);
        }
    };
    $retryWorker = new EmailWorker($connection, $config, $failing);
    $retry = Outbox::enqueue($db, 'retry-event', $user, 'new_application');
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $counts = $retryWorker->run();
        $current = $row($retry);
        expect((int) $current['attempts'] === $attempt, 'Each provider submission increments the durable attempt count.');
        if ($attempt < 5) {
            expect($counts['retried'] === 1 && $current['status'] === 'pending', 'Temporary failure is rescheduled.');
            $retryWorker->run();
            expect($failing->calls === $attempt, 'Backoff prevents immediate retries.');
            $db->prepare('UPDATE email_outbox SET available_at = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE id = ?')->execute([$retry]);
        } else {
            expect($counts['failed'] === 1 && $current['status'] === 'failed', 'The fifth failed submission exhausts the retry budget.');
        }
    }
    $retryWorker->run();
    expect($failing->calls === 5, 'Exhausted mail cannot be submitted a sixth time.');
    expect((int) $db->query('SELECT COUNT(*) FROM email_delivery_attempts WHERE outbox_id = ' . $retry)->fetchColumn() === 5,
        'Every attempted delivery has a durable attempt record.');

    // Catches blindly resending a message after a worker dies during delivery.
    $abandoned = Outbox::enqueue($db, 'abandoned-event', $user, 'new_application');
    $db->prepare("UPDATE email_outbox SET status = 'processing', locked_until = CURRENT_TIMESTAMP(6) - INTERVAL 1 SECOND, lock_token = ? WHERE id = ?")
        ->execute([str_repeat('a', 32), $abandoned]);
    $counts = $retryWorker->run();
    expect($row($abandoned)['status'] === 'uncertain' && $failing->calls === 5,
        'Expired delivery leases are quarantined without automatic resend.');

    $preferences = new EmailPreferences($connection);
    expect($preferences->forUser($user)['recruitment'], 'Optional notifications default to enabled.');
    $optional = Outbox::enqueue($db, 'queued-before-opt-out', $user, 'new_application');
    $preferences->save($user, []);
    $counts = $worker->run();
    expect($counts['suppressed'] === 1 && $row($optional)['status'] === 'suppressed',
        'Opting out suppresses optional mail that was already queued.');
    expect(count(glob($work . '/mail/*.eml')) === 1, 'Suppressed mail never reaches the transport.');
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token, true);
    $db->prepare('INSERT INTO password_reset_tokens (token_hash, user_id, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP(6) + INTERVAL 30 MINUTE)')
        ->execute([$tokenHash, $user]);
    $expires = $db->query('SELECT DATE_FORMAT(UTC_TIMESTAMP(6) + INTERVAL 30 MINUTE, \'%Y-%m-%d %H:%i:%s.%f\')')->fetchColumn();
    $security = Outbox::enqueue($db, 'essential-password-reset', $user, 'password_reset', [], null,
        \Furoeducare\Modules\Email\MailCrypto::encrypt($token, $config->mailOptions['encryption_key']), $tokenHash, $expires);
    expect(!str_contains($row($security)['payload'], $token) && !str_contains($row($security)['secret_payload'], $token),
        'Pending authentication tokens are encrypted and never stored in the ordinary JSON payload.');
    $counts = $worker->run();
    expect($counts['accepted'] === 1 && $row($security)['secret_payload'] === null,
        'Password reset remains deliverable with every optional category disabled and erases queued secret after acceptance.');
    expect(count(glob($work . '/mail/*.eml')) === 2, 'Essential security email bypasses optional preferences.');
    $stale = Outbox::enqueue($db, 'consumed-password-reset', $user, 'password_reset', [], null,
        \Furoeducare\Modules\Email\MailCrypto::encrypt($token, $config->mailOptions['encryption_key']), $tokenHash, $expires);
    $db->prepare('UPDATE password_reset_tokens SET consumed_at = CURRENT_TIMESTAMP(6) WHERE token_hash = ?')->execute([$tokenHash]);
    $worker->run();
    expect($row($stale)['status'] === 'suppressed' && $row($stale)['secret_payload'] === null,
        'Already consumed security links are suppressed and their queued secrets erased.');
    expect(count(glob($work . '/mail/*.eml')) === 2, 'A consumed link is never handed to the transport.');
    $preferences->save($user, array_keys(EmailPreferences::CATEGORIES));

    serve($root, [
        'APP_ENV' => 'development', 'APP_URL' => $config->baseUrl, 'MAIL_TRANSPORT' => 'file',
        'MAIL_FROM' => 'notifications@example.test', 'MAIL_ENCRYPTION_KEY' => getenv('MAIL_ENCRYPTION_KEY'),
        'AUTH_SESSION_DIR' => $work . '/sessions', 'AUTH_MAIL_DIR' => $work . '/mail', 'APP_LOG_FILE' => $work . '/app.log',
    ], $work . '/server.log', function (string $base) use ($preferences, $user): void {
        $anonymous = new AuthBrowser($base);
        redirectIs($anonymous->send('/settings/notifications'), '/login');
        $browser = new AuthBrowser($base);
        redirectIs($browser->submit('/login', ['email' => 'recipient@example.test', 'password' => 'Email test fixture password 712!']), '/dashboard/teacher');
        expect($browser->send('/settings/notifications', 'POST', ['enabled' => ['messages']])[0] === 403,
            'Notification preference updates require CSRF.');
        expect($browser->submit('/settings/notifications', ['user_id' => $user + 1, 'enabled' => ['messages']])[0] === 422,
            'Submitted account identifiers cannot retarget preference changes.');
        expect($preferences->forUser($user)['recruitment'], 'Rejected owner injection preserves the account preferences.');
        redirectIs($browser->submit('/settings/notifications', ['enabled' => ['messages']]), '/settings/notifications');
        expect(!$preferences->forUser($user)['recruitment'] && $preferences->forUser($user)['messages'],
            'Authenticated settings change only the current account categories.');
    });
    $preferences->save($user, array_keys(EmailPreferences::CATEGORIES));

    // Real PHPMailer SMTP dialogue, entirely on loopback, including DATA outcomes.
    foreach (['250' => 'accepted', '451' => 'pending', '550' => 'failed', 'disconnect' => 'uncertain'] as $response => $wanted) {
        $ready = $work . '/smtp-' . $response . '.ready';
        $process = proc_open([PHP_BINARY, __DIR__ . '/support/smtp-fixture.php', $ready, (string) $response],
            [0 => ['pipe', 'r'], 1 => ['file', $work . '/smtp.log', 'a'], 2 => ['file', $work . '/smtp.log', 'a']], $pipes, $root);
        expect(is_resource($process), 'Start the local SMTP protocol fixture.');
        fclose($pipes[0]);
        try {
            for ($wait = 0; $wait < 100 && !is_file($ready); $wait++) { usleep(20000); }
            expect(is_file($ready), 'SMTP fixture publishes its ephemeral loopback endpoint.');
            $endpoint = trim(file_get_contents($ready));
            putenv('MAIL_TRANSPORT=smtp');
            putenv('MAIL_SMTP_HOST=127.0.0.1');
            putenv('MAIL_SMTP_PORT=' . substr(strrchr($endpoint, ':'), 1));
            putenv('MAIL_SMTP_USERNAME=');
            putenv('MAIL_SMTP_PASSWORD=');
            putenv('MAIL_SMTP_ENCRYPTION=none');
            putenv('MAIL_TIMEOUT=2');
            $smtpConfig = Config::load($root);
            $smtpWorker = new EmailWorker($connection, $smtpConfig);
            $smtpId = Outbox::enqueue($db, 'smtp-' . $response, $user, 'new_application');
            $smtpWorker->run();
            expect($row($smtpId)['status'] === $wanted, 'SMTP DATA response ' . $response . ' produces ' . $wanted . '.');
            if ($wanted === 'pending') {
                $db->prepare("UPDATE email_outbox SET status = 'failed' WHERE id = ?")->execute([$smtpId]);
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
    $log = is_file($work . '/app.log') ? file_get_contents($work . '/app.log') : '';
    expect(str_contains($log, 'email.delivery_failure') && !str_contains($log, 'recipient@example.test') && !str_contains($log, $token)
        && !str_contains($log, 'Confidential recruitment discussion'), 'Operational mail logs exclude recipient addresses, tokens and private business text.');

    $before = (int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
    $beforeNotifications = (int) $db->query('SELECT COUNT(*) FROM verification_notifications')->fetchColumn();
    $context = ['to' => 'verified', 'revision' => 1, 'checks' => ['Private identity-check findings']];
    $db->beginTransaction();
    \Furoeducare\Modules\Verification\VerificationEvents::record($db, $user, $user, 'verification.teacher.review',
        $context, 'Private review title', 'Private professional history notes');
    expect((int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === $before + 1,
        'A successful review notification queues its email on the same connection.');
    $db->rollBack();
    expect((int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === $before
        && (int) $db->query('SELECT COUNT(*) FROM verification_notifications')->fetchColumn() === $beforeNotifications,
        'Rolling back a review removes its in-app notification and email together.');
    Database::transaction($db, static function (PDO $db) use ($user, $context): void {
        \Furoeducare\Modules\Verification\VerificationEvents::record($db, $user, $user, 'verification.teacher.review',
            $context, 'Private review title', 'Private professional history notes');
    });
    $notification = (int) $db->query('SELECT MAX(id) FROM verification_notifications')->fetchColumn();
    \Furoeducare\Modules\Email\EmailEvents::notification($db, $notification, $user, 'verification.teacher.review', $context);
    $business = $db->query('SELECT * FROM email_outbox ORDER BY id DESC LIMIT 1')->fetch();
    expect((int) $db->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === $before + 1,
        'Processing the same durable notification again does not enqueue a duplicate email.');
    expect($business['template'] === 'teacher_verification'
        && json_decode($business['payload'], true) == ['decision' => 'verified', 'path' => '/dashboard/teacher']
        && !str_contains($business['payload'], 'Private'),
        'Business emails receive only fixed template navigation and outcome, excluding private review titles, notes and checks.');

    // A provider acknowledgement followed by DB failure must never schedule a resend.
    $successful = new class implements MailTransport {
        public int $calls = 0;
        public function send(array $row, array $rendered): array
        {
            $this->calls++;
            return ['provider_status' => 'accepted', 'provider_id' => 'loopback-ack'];
        }
    };
    $acknowledgedWorker = new EmailWorker($connection, $config, $successful);
    $db->exec("CREATE TRIGGER email_test_accept_failure BEFORE UPDATE ON email_outbox FOR EACH ROW
        BEGIN IF NEW.status = 'accepted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected acceptance persistence failure'; END IF; END");
    try {
        $counts = $acknowledgedWorker->run();
        expect($counts['uncertain'] === 1 && $successful->calls === 1 && $row((int) $business['id'])['status'] === 'uncertain',
            'A database failure after provider acknowledgement quarantines delivery instead of declaring failure or retrying.');
        $acknowledgedWorker->run();
        expect($successful->calls === 1, 'An acknowledged message is never automatically resubmitted after persistence failure.');
    } finally {
        $db->exec('DROP TRIGGER email_test_accept_failure');
    }

    echo "PASS transactional outbox, event idempotency, MIME and SMTP delivery, bounded retries, uncertain leases and account-scoped preferences.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Test artifacts, if created, are in ' . $work . PHP_EOL);
    $exit = 1;
} finally {
    if ($exit === 0 && is_dir($work)) {
        foreach (glob($work . '/mail/*') as $file) {
            if (is_file($file)) { unlink($file); }
        }
        if (is_file($work . '/mail/.spool.lock')) { unlink($work . '/mail/.spool.lock'); }
        if (is_dir($work . '/mail')) { rmdir($work . '/mail'); }
        foreach (glob($work . '/sessions/*') as $file) {
            if (is_file($file)) { unlink($file); }
        }
        if (is_dir($work . '/sessions')) { rmdir($work . '/sessions'); }
        foreach (glob($work . '/*') as $file) {
            if (is_file($file)) { unlink($file); }
        }
        rmdir($work);
    }
}
exit($exit);



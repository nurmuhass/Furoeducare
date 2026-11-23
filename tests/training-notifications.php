<?php

declare(strict_types=1);

use Furoeducare\Modules\Email\EmailEvents;
use Furoeducare\Modules\Email\EmailTemplates;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

// Capture the outbox SQL boundary without a database or SMTP dependency.
final class TrainingMailDatabase extends PDO
{
    public array $queued = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TrainingMailStatement($this, $query);
    }
}

final class TrainingMailStatement extends PDOStatement
{
    public function __construct(private readonly TrainingMailDatabase $db, private readonly string $sql) {}
    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->sql, 'INSERT INTO email_outbox')) {
            $this->db->queued = $params;
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return 'school@example.test'; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return ['id' => 1, 'payload_hash' => $this->db->queued[7]];
    }
}

$db = new TrainingMailDatabase();
foreach (['submitted', 'under_review', 'discussion', 'assigned', 'approved', 'scheduled', 'rescheduled', 'completed', 'cancelled'] as $event) {
    EmailEvents::notification($db, 123, 8, 'training.' . $event, [
        'training_request_id' => 41,
        'to' => $event,
        'requirements' => 'Private requirements',
        'outcome' => 'Private outcome',
        'notes' => 'Private staff note',
    ]);
    expect($db->queued[0] === 8 && $db->queued[2] === 'training_confirmation', 'Training events queue the existing training template for the school.');
    $payload = json_decode($db->queued[3], true, 512, JSON_THROW_ON_ERROR);
    expect($payload === ['path' => '/dashboard/school/training?id=41'], 'Only the authenticated request link enters training mail payloads.');
    $mail = EmailTemplates::render($db->queued[2], $payload, 'https://example.test');
    expect(str_contains($mail['text'], 'https://example.test/dashboard/school/training?id=41'), 'School mail links to its authenticated request.');
    expect(!str_contains($mail['text'] . $mail['html'], 'Private'), 'Private request information never enters training mail.');
    expect(!str_contains($mail['subject'], 'confirmation'), 'Intake and progress mail do not imply a confirmed session.');
}
EmailEvents::notification($db, 124, 12, 'training.assigned', [
    'training_request_id' => 41,
    'recipient_role' => 'admin',
    'requirements' => 'Private requirements',
    'outcome' => 'Private outcome',
    'notes' => 'Private staff note',
]);
expect($db->queued[0] === 12, 'Staff training notifications target the selected administrator.');
$staffPayload = json_decode($db->queued[3], true, 512, JSON_THROW_ON_ERROR);
expect($staffPayload === ['path' => '/dashboard/admin/training/requests?id=41'], 'Staff mail includes only its authenticated request path, without the internal role flag or private fields.');
$staff = EmailTemplates::render('training_confirmation', ['path' => '/dashboard/admin/training/requests?id=41'], 'https://example.test');
expect(str_contains($staff['text'], 'https://example.test/dashboard/admin/training/requests?id=41'), 'The safe navigation allowlist supports the staff request route.');
expect(EmailTemplates::category('training_confirmation') === 'training', 'Training mail uses the existing notification preference.');
echo "PASS generic training event emails, private payload exclusion, authenticated role links and training preferences.\n";

<?php

declare(strict_types=1);

use Furoeducare\Modules\Email\EmailTemplates;
use Furoeducare\Modules\Notification\NotificationCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

final class CatalogDatabase extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CatalogStatement($query);
    }
}
final class CatalogStatement extends PDOStatement
{
    public function __construct(private readonly string $sql) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return str_contains($this->sql, 'owner_id') ? 7 : 12; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return ['school']; }
}

$db = new CatalogDatabase();
$cases = [
    ['verification.teacher.review', ['revision' => 3], 'account', '/dashboard/teacher'],
    ['verification.teacher.evidence_changed', [], 'account', '/dashboard/teacher'],
    ['verification.document.review', ['document_id' => 9], 'account', '/dashboard/teacher'],
    ['verification.school.review', ['revision' => 4], 'account', '/dashboard/school'],
    ['verification.school.resubmitted', [], 'account', '/dashboard/school'],
    ['disclosure.approve', ['disclosure_id' => 8], 'recruitment', '/candidate-requests'],
    ['disclosure.revoked', ['grant_id' => 9], 'recruitment', '/candidate-requests'],
    ['messaging.new_message', ['message_id' => 30, 'conversation_id' => 10], 'messages', '/messages?id=10'],
    ['placement.discussion_scheduled', ['placement_id' => 5, 'history_id' => 11], 'recruitment', '/placements?id=5'],
    ['placement.confirmed', ['placement_id' => 5, 'history_id' => 12], 'placements', '/placements?id=5'],
    ['tutoring.shortlisted', ['request_id' => 4, 'history_id' => 9], 'tutoring', '/dashboard/parent'],
    ['training.assigned_staff', ['training_request_id' => 2, 'recipient_role' => 'admin'], 'training', '/dashboard/admin/training/requests?id=2'],
    ['training.scheduled', ['training_request_id' => 2], 'training', '/dashboard/school/training?id=2'],
    ['vacancy.transition', ['vacancy_id' => 2, 'revision' => 3], 'recruitment', '/dashboard/school/vacancies?id=2'],
    ['application.submitted', ['application_id' => 3, 'history_id' => 4], 'recruitment', '/dashboard/teacher'],
    ['application.interview_invited', ['application_id' => 3, 'history_id' => 5], 'recruitment', '/dashboard/teacher'],
    ['candidate.publish', ['case_id' => 2, 'history_id' => 4], 'recruitment', '/candidate-requests'],
    ['account.verified', ['event_key' => 'welcome:7'], 'account', '/dashboard/school'],
    ['account.password_changed', ['revision' => 3], 'account', '/dashboard/school'],
];
foreach ($cases as [$event, $context, $category, $path]) {
    $context += ['note' => 'Private note', 'token' => 'secret', 'candidate_name' => 'Unreleased candidate'];
    $description = NotificationCatalog::describe($db, 7, $event, $context);
    expect($description['category'] === $category && $description['path'] === $path, 'Event mapping preserves category and approved destination: ' . $event);
    expect($description['category'] === EmailTemplates::category($description['template']), 'Both channel gates use the email category.');
    expect(array_diff(array_keys($description['payload']), ['path', 'decision']) === [], 'Only generic navigation and status are included in mail.');
    expect(!str_contains(json_encode($description), 'Private note') && !str_contains(json_encode($description), 'Unreleased candidate'), 'Private domain data is excluded.');
    EmailTemplates::render($description['template'], $description['payload'], 'https://example.test');
    $reordered = array_reverse($context, true);
    $reordered['note'] = 'Different private note';
    expect(NotificationCatalog::describe($db, 7, $event, $reordered)['key'] === $description['key'], 'Identity is independent of array order and free text.');
}
$first = NotificationCatalog::describe($db, 7, 'placement.discussion_scheduled', ['placement_id' => 5, 'history_id' => 11]);
$next = NotificationCatalog::describe($db, 7, 'placement.discussion_scheduled', ['placement_id' => 5, 'history_id' => 12]);
expect($first['key'] !== $next['key'], 'Repeated scheduling actions are distinct history events.');
expect(NotificationCatalog::describe($db, 7, 'account.verified', ['event_key' => 'welcome:7'])['key'] === 'welcome:7', 'Explicit legacy event keys are preserved.');
expect(NotificationCatalog::describe($db, 7, 'application.interview_invited', ['application_id' => 3, 'history_id' => 5, 'send_invitation' => false])['template'] === 'recruitment_update', 'Managed placement sends its own single interview invitation.');
foreach ([['unknown.event', []], ['messaging.new_message', ['message_id' => 1]], ['candidate.publish', ['case_id' => 3]], ['vacancy.transition', ['vacancy_id' => -1, 'revision' => 1]]] as [$event, $context]) {
    $rejected = false;
    try { NotificationCatalog::describe($db, 7, $event, $context); } catch (InvalidArgumentException) { $rejected = true; }
    expect($rejected, 'Unknown events and missing source identities are rejected.');
}
echo "PASS notification catalog maps source identities, channel categories and private navigation.\n";

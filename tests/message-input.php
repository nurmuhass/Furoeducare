<?php

declare(strict_types=1);

use Furoeducare\Modules\Messaging\MessageInput;
use Furoeducare\Modules\Messaging\MessageContext;
use Furoeducare\Modules\Auth\Role;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/support/http.php';

expect(MessageInput::body(' <script>alert(1)</script> ') === '<script>alert(1)</script>', 'Store text without interpreting HTML.');
expect(MessageInput::body("First line\nSecond line") === "First line\nSecond line", 'Preserve message line breaks.');
foreach (['', '   ', str_repeat('x', 4001), "bad\0body", "\xFF"] as $invalid) {
    try {
        MessageInput::body($invalid);
        throw new RuntimeException('Invalid text was accepted.');
    } catch (DomainException $error) {
        expect($error->getCode() === 422, 'Reject invalid message text with a validation error.');
    }
}
expect(MessageInput::token(str_repeat('a', 32)) === str_repeat('a', 32), 'Accept a valid send token.');
try {
    MessageInput::token('invalid');
    throw new RuntimeException('An invalid token was accepted.');
} catch (DomainException $error) {
    expect($error->getCode() === 422, 'Require a bounded retry token.');
}
expect(in_array('training', MessageInput::CONTEXTS, true), 'Training is a managed conversation context.');
$databaseCalls = 0;
$lookup = new class {
    public array $params = [];
    public bool $found = true;
    public function execute(array $params): void { $this->params = $params; }
    public function fetch(): array|false { return $this->found ? ['id' => 41] : false; }
};
$connection = new class($lookup) {
    public string $sql = '';
    public function __construct(private readonly object $lookup) {}
    public function prepare(string $sql): object { $this->sql = $sql; return $this->lookup; }
};
$context = new MessageContext(static function () use (&$databaseCalls, $connection): object {
    $databaseCalls++;
    return $connection;
});
foreach ([Role::Teacher, Role::Parent, Role::Admin] as $role) {
    try {
        $context->requireOwned(8, $role, 'training', 41);
        throw new RuntimeException('A non-school role linked a training request.');
    } catch (DomainException $error) {
        expect($error->getCode() === 404, 'Hide training requests from unsupported account roles.');
    }
}
expect($databaseCalls === 0, 'Reject unsupported training roles before database access.');
foreach ([null, 0, -1] as $id) {
    try {
        $context->requireOwned(8, Role::School, 'training', $id);
        throw new RuntimeException('A training context omitted its request.');
    } catch (DomainException $error) {
        expect($error->getCode() === 422, 'Require a positive training request number.');
    }
}
$context->requireOwned(8, Role::School, 'training', 41);
expect($lookup->params === [41, 8], 'Scope training requests to the current school account.');
expect($connection->sql === 'SELECT id FROM training_requests WHERE id = ? AND school_id = ?', 'Training ownership has no approval/status gate after submission.');
$lookup->found = false;
try {
    $context->requireOwned(9, Role::School, 'training', 41);
    throw new RuntimeException('Another school linked an unowned training request.');
} catch (DomainException $error) {
    expect($error->getCode() === 404, 'Reject another school or a missing training request.');
}
echo "PASS text validation, line breaks, bounded retry tokens and school-owned training contexts.\n";

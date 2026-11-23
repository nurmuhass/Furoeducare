<?php
declare(strict_types=1);

use Furoeducare\Core\View;
use Furoeducare\Modules\Auth\Role;

require dirname(__DIR__) . '/vendor/autoload.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$root = dirname(__DIR__);
$view = new View($root . '/resources/views/layout.php', 'Furoeducare');
$common = ['signedIn' => true, 'csrf' => 'csrf-fixture', 'notice' => '', 'errors' => [], 'values' => [],
    'viewer' => ['id' => 42, 'email' => 'owner@example.test', 'roles' => []], 'appearance' => 'system',
    'profileLinks' => [], 'phoneProfiles' => [], 'pendingEmail' => null, 'deactivation' => null, 'teacher' => null];
$render = static fn (array $data = []): string => $view->render($root . '/app/Modules/Settings/Views/account.php', 'Account settings', $data + $common);
$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$html = $render();
$assert(str_contains($html, '<h1>Account settings</h1>') && str_contains($html, 'owner@example.test'), 'Empty profile state retains account controls.');
$assert(!str_contains($html, 'name="user_id"') && !str_contains($html, 'value="42"'), 'Account ownership cannot be edited.');
$assert(str_contains($html, '/settings/notifications') && str_contains($html, '/privacy/controls') && str_contains($html, '/privacy-policy') && str_contains($html, '/terms'), 'Related settings and canonical legal links exist.');
$assert(!str_contains($html, 'value="admin_profile"') && !str_contains($html, 'name="profile_role"'), 'Empty roles do not show role forms.');
$assert(substr_count($html, '<form ') === substr_count($html, 'name="_csrf" value="csrf-fixture"'), 'Every form contains CSRF.');
$assert(preg_match('/<input[^>]+type="password"[^>]+value=/', $html) !== 1, 'Passwords never have values.');
$html = $render(['notice' => '<script>notice</script>', 'errors' => ['password' => '<img src=x>', 'new_email' => 'Invalid email'],
    'values' => ['current_password' => 'SECRET-CURRENT', 'password' => 'SECRET-NEW', 'password_confirmation' => 'SECRET-CONFIRM', 'new_email' => '"><script>email</script>']]);
$assert(!str_contains($html, 'SECRET-') && !str_contains($html, '<script>email</script>') && !str_contains($html, '<img src=x>'), 'Secrets are discarded and untrusted text escaped.');
$assert(str_contains($html, 'data-error-summary') && str_contains($html, 'aria-invalid="true"') && str_contains($html, 'aria-describedby="account-password-password-error'), 'Errors have summary and field associations.');
$html = $render(['viewer' => ['id' => 42, 'email' => 'staff@example.test', 'roles' => [Role::Admin, Role::Teacher, Role::School]],
    'phoneProfiles' => [['role' => 'admin', 'label' => 'Staff contact', 'phone' => '+2348000000000'], ['role' => 'teacher', 'label' => 'Teacher contact', 'phone' => '+2348111111111']],
    'profileLinks' => [['label' => 'School profile', 'path' => '/dashboard/school?tab=profile'], ['label' => 'Unsafe', 'path' => '//evil.test']],
    'teacher' => ['public_status' => 'pending', 'disclosures_paused' => true, 'availability_path' => '/dashboard/teacher?tab=availability']]);
$assert(str_contains($html, 'value="admin_profile"') && substr_count($html, 'name="profile_role"') === 2, 'Admin and owned phone forms render.');
$assert(str_contains($html, 'Management controls publication') && str_contains($html, 'renewed review') && str_contains($html, 'one account'), 'Teacher publication and school identity rules are explained.');
$assert(!str_contains($html, '//evil.test') && str_contains($html, '/dashboard/teacher?tab=availability'), 'Only local profile links and existing availability controls render.');
$document = new DOMDocument();
$previousXmlErrors = libxml_use_internal_errors(true);
$document->loadHTML($html);
libxml_clear_errors();
libxml_use_internal_errors($previousXmlErrors);
$xpath = new DOMXPath($document);
$ids = [];
foreach ($xpath->query('//*[@id]') as $element) {
    $id = $element->getAttribute('id');
    $assert(!isset($ids[$id]), 'Multi-role settings keep field IDs unique: ' . $id);
    $ids[$id] = true;
}
foreach ($xpath->query('//input[@type="password"]') as $input) {
    $assert(!$input->hasAttribute('value') && in_array($input->getAttribute('autocomplete'), ['current-password', 'new-password'], true), 'Every password input is blank and uses the correct autocomplete purpose.');
}
$html = $render(['values' => ['profile_role' => 'teacher', 'phone' => '08099999999'], 'errors' => ['phone' => 'Check this phone'],
    'phoneProfiles' => [['role' => 'teacher', 'label' => 'Teacher', 'phone' => '08011111111'], ['role' => 'parent', 'label' => 'Parent', 'phone' => '08022222222']]]);
$assert(str_contains($html, 'value="08099999999"') && str_contains($html, 'value="08022222222"') && !str_contains($html, 'value="08011111111"'), 'Submitted phone value is redisplayed only for the selected owned role.');
$html = $render(['values' => ['_action' => 'password'], 'errors' => ['current_password' => 'Incorrect current password']]);
$assert(substr_count($html, 'name="current_password" aria-invalid="true"') === 1, 'Only the submitted form marks its current password invalid.');
$html = $render(['pendingEmail' => ['new_email' => 'pending@example.test', 'expires_at' => '2026-11-24 12:00:00'],
    'deactivation' => ['id' => 12, 'status' => 'pending', 'reason' => '<script>reason</script>', 'created_at' => '2026-11-23 12:00:00']]);
$assert(str_contains($html, 'pending@example.test') && str_contains($html, '2026-11-24 12:00:00 UTC') && str_contains($html, 'value="cancel_email"'), 'Pending email has expiry and cancellation.');
$assert(str_contains($html, 'value="cancel_deactivation"') && !str_contains($html, 'value="deactivate"') && !str_contains($html, '<script>reason</script>'), 'Pending deactivation can be cancelled and escapes reason.');
$html = $render(['deactivation' => ['id' => 12, 'status' => 'completed', 'reason' => '', 'created_at' => '2026-11-23 12:00:00']]);
$assert(!str_contains($html, 'value="cancel_deactivation"'), 'Completed request has no cancel action.');
restore_error_handler();
echo "PASS account settings empty, role, escaped, secret-free, pending and accessible field views.\n";

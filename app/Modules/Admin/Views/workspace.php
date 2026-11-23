<?php
$root = '/dashboard/admin';
$page = $data['page'];
$statusChoices = match ($page) {
    'accounts' => ['active' => 'Active', 'suspended' => 'Suspended', 'unverified' => 'Email not verified'],
    'listings' => ['published' => 'Published', 'withdrawn' => 'Withdrawn', 'not_listed' => 'Not listed'],
    'cv-requests' => ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'],
    'email' => ['pending' => 'Pending', 'processing' => 'Processing', 'accepted' => 'Provider accepted', 'failed' => 'Failed', 'suppressed' => 'Suppressed', 'uncertain' => 'Uncertain'],
    default => [],
};
$can = static fn (string $permission): bool => in_array($permission, $data['permissions'], true);
$format = static function (mixed $value, string $key = '') use ($data): string {
    if ($value === null || $value === '') return '—';
    if (str_ends_with($key, '_at')) return $data['wat']((string) $value) . ' WAT';
    if (is_array($value)) return implode(', ', array_map(static fn ($v): string => is_array($v) ? (string) ($v['name'] ?? $v['id'] ?? '') : (string) $v, $value));
    return (string) $value;
};
$table = static function (array $rows, array $columns, ?Closure $destination = null) use ($format, $data): void {
    echo \Furoeducare\UI\Components::table($rows, $columns, $data['title'], $destination, $format);
};
$confirmation = static function (string $suffix) use ($escape): void { ?>
    <div class="mt-3">
<label class="form-label" for="reason-<?= $escape($suffix) ?>">Reason for this change</label>
<textarea class="form-control" id="reason-<?= $escape($suffix) ?>" name="reason" minlength="10" maxlength="500" rows="2" required>
</textarea>
</div>
    <div class="form-check my-3">
<input class="form-check-input" type="checkbox" id="confirmed-<?= $escape($suffix) ?>" name="confirmed" value="1" required>
<label class="form-check-label" for="confirmed-<?= $escape($suffix) ?>">I reviewed this record and confirm this change.</label>
</div>
<?php };
$csrf = static function () use ($escape, $data): void { ?>
<input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
<?php };
$rows = $data['rows'] ?? [];
$filters = $data['filters'] ?? [];
$perPage = $page === 'audit' ? 30 : 20;
?>
<section class="page-band">
<div class="site-shell">
<p class="text-uppercase small text-body-secondary mb-2">Administration workspace</p>
<h1>
<?= $escape($data['title']) ?>
</h1>
<p class="text-body-secondary mb-0">
<?= $escape($data['description'] ?? '') ?>
</p>
</div>
</section>
<div class="site-shell section-space">
<?php if (in_array($page, ['staff', 'roles'], true)): ?>
    <nav class="d-flex flex-wrap gap-3 mb-4" aria-label="Staff administration">
        <a href="<?= $root ?>/staff"<?= $page === 'staff' ? ' aria-current="page"' : '' ?>>Staff assignments</a>
        <a href="<?= $root ?>/roles"<?= $page === 'roles' ? ' aria-current="page"' : '' ?>>Roles and permissions</a>
    </nav>
<?php endif; ?>
<?php if ($data['notice'] !== ''): ?>
<p class="alert alert-success" role="status">
<?= $escape($data['notice']) ?>
</p>
<?php endif; ?>
<?php if ($page === 'problem'): ?>
    <div class="admin-panel">
<p role="alert">
<?= $escape($data['message']) ?>
</p>
<p>Your change has not been applied. Reopen the record to review its current details.</p>
<a href="<?= $escape($data['returnPath'] ?? $root) ?>">Return to administration</a>
</div>
<?php elseif ($page === 'overview'): ?>
    <div class="row g-3 mb-4">
<?php foreach ($data['summary']['metrics'] ?? [] as $metric): ?>
<div class="col-sm-6 col-xl-3">
<?= \Furoeducare\UI\Components::metric($metric['label'], (int) $metric['value'], $metric['path'], 'Open workspace') ?>
</div>
<?php endforeach; ?>
</div>
    <?php if (($data['summary']['metrics'] ?? []) === []): ?>
<div class="admin-panel" data-empty-state>
<h2 class="h5">Your staff access is ready to configure</h2>
<p>Ask an authorised staff manager to assign the permissions needed for your work.</p>
</div>
<?php endif; ?>
    <section class="admin-panel">
<h2 class="h5">Recent activity</h2>
<?php $table($data['summary']['activity'] ?? [], ['id' => 'Event', 'actor_user_id' => 'Staff account', 'action' => 'Action', 'created_at' => 'Recorded']); ?>
</section>
<?php elseif ($page === 'account'): $account = $data['account']; ?>
    <a href="<?= $root ?>/accounts?type=<?= $escape($data['type']) ?>" class="d-inline-block mb-3">← Account directory</a>
    <section class="admin-panel">
<h2 class="h4">
<?= $escape($account['name'] ?: $account['email']) ?>
</h2>
<dl class="row">
<?php foreach (['id' => 'Account ID', 'email' => 'Email', 'phone' => 'Phone', 'location' => 'Location', 'roles' => 'Account roles', 'profile_status' => 'Profile status', 'email_verified_at' => 'Email verified', 'suspended_at' => 'Suspended', 'created_at' => 'Registered'] as $key => $label): ?>
<dt class="col-sm-3">
<?= $label ?>
</dt>
<dd class="col-sm-9">
<?= $escape($format($account[$key] ?? null, $key)) ?>
</dd>
<?php endforeach; ?>
</dl>
<nav class="d-flex flex-wrap gap-3" aria-label="Account workflows">
    <?php if ($can('verification.view') && $data['type'] === 'teacher'): ?>
        <a href="<?= $root ?>/verification/teachers?id=<?= (int) $account['id'] ?>">Review teacher verification</a>
    <?php elseif ($can('verification.view') && $data['type'] === 'school'): ?>
        <a href="<?= $root ?>/schools?id=<?= (int) $account['id'] ?>">Review school onboarding</a>
    <?php endif; ?>
    <?php if ($can('directory.view') && $data['type'] === 'teacher'): ?>
        <a href="<?= $root ?>/directory?id=<?= (int) $account['id'] ?>">Review public listing</a>
    <?php endif; ?>
</nav>
</section>
    <?php if ($can('accounts.manage') && (!in_array('admin', $account['roles'], true) || $can('staff.manage')) && (int) $account['id'] !== (int) $data['viewer']['id']): $suspending = $account['suspended_at'] === null; ?>
<section class="admin-panel">
<h2 class="h5">
<?= $suspending ? 'Suspend account access' : 'Restore account access' ?>
</h2>
<p>Changes invalidate existing account sessions. Protected staff accounts are checked again before the change is saved.</p>
<form method="post" action="<?= $root ?>/accounts/action" data-admin-confirm="Confirm this account access change?">
<?php $csrf(); ?>
<input type="hidden" name="type" value="<?= $escape($data['type']) ?>">
<input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
<input type="hidden" name="version" value="<?= (int) $account['auth_version'] ?>">
<input type="hidden" name="action" value="<?= $suspending ? 'suspend' : 'restore' ?>">
<?php $confirmation('account'); ?>
<button class="btn <?= $suspending ? 'btn-danger' : 'btn-primary' ?>" type="submit">
<?= $suspending ? 'Suspend access' : 'Restore access' ?>
</button>
</form>
</section>
<?php endif; ?>
    <?php if ($can('audit.view')): ?>
<section class="admin-panel">
<h2 class="h5">Account activity</h2>
<?php $table($data['activity'], ['id' => 'Event', 'actor_user_id' => 'Actor', 'action' => 'Action', 'created_at' => 'Recorded']); ?>
</section>
<?php endif; ?>
<?php elseif ($page === 'settings'): $settings = $data['settings']; ?>
    <section class="admin-panel">
<h2 class="h5">Operational settings</h2>
<p>The notice appears throughout this workspace. The support address is linked in the workspace footer.</p>
<?php if (!$can('settings.manage')): ?>
<dl>
<dt>Staff notice</dt>
<dd>
<?= $escape($settings['admin_notice'] ?: '—') ?>
</dd>
<dt>Support email</dt>
<dd>
<?= $escape($settings['support_email'] ?: '—') ?>
</dd>
</dl>
<?php else: ?>
<form method="post" action="<?= $root ?>/settings" data-admin-confirm="Save these operational settings?">
<?php $csrf(); ?>
<input type="hidden" name="revision" value="<?= (int) $settings['revision'] ?>">
<div class="mb-3">
<label for="admin-notice" class="form-label">Staff notice</label>
<textarea id="admin-notice" class="form-control" name="admin_notice" maxlength="500" rows="3">
<?= $escape($settings['admin_notice']) ?>
</textarea>
</div>
<div class="mb-3">
<label for="support-email" class="form-label">Support email</label>
<input id="support-email" class="form-control" type="email" name="support_email" maxlength="254" value="<?= $escape($settings['support_email']) ?>">
</div>
<?php $confirmation('settings'); ?>
<button class="btn btn-primary" type="submit">Save settings</button>
</form>
<?php endif; ?>
</section>
<?php elseif ($page === 'roles'): ?>
    <p>Roles grant explicit capabilities. You can grant only permissions you currently hold. Built-in roles are protected.</p>
    <?php foreach ($data['roles'] as $role): ?>
<section class="admin-panel">
<h2 class="h5">
<?= $escape($role['name']) ?> <?php if ($role['built_in'] || !$can('staff.manage')): ?>
<span class="admin-status">Built-in</span>
<?php endif; ?>
</h2>
    <?php if ($role['built_in'] || !$can('staff.manage')): ?>
<p class="small mb-0">
<?= $escape(implode(', ', array_map(static fn (string $permission): string => \Furoeducare\Modules\Admin\StaffPermissions::ALL[$permission] ?? $permission, $role['permissions']))) ?>
</p>
    <?php else: ?>
<form method="post" action="<?= $root ?>/roles" data-admin-confirm="Save this role and its permissions?">
<?php $csrf(); ?>
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
<input type="hidden" name="revision" value="<?= (int) $role['revision'] ?>">
<label class="form-label" for="role-name-<?= (int) $role['id'] ?>">Role name</label>
<input class="form-control mb-3" name="name" id="role-name-<?= (int) $role['id'] ?>" value="<?= $escape($role['name']) ?>" maxlength="100" required>
<div class="admin-permissions">
<?php foreach ($data['permissionChoices'] as $permission): ?>
<label class="form-check">
<input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $escape($permission) ?>"<?= in_array($permission, $role['permissions'], true) ? ' checked' : '' ?>>
<span class="form-check-label">
<?= $escape(\Furoeducare\Modules\Admin\StaffPermissions::ALL[$permission] ?? $permission) ?>
</span>
</label>
<?php endforeach; ?>
</div>
<?php $confirmation('role-' . $role['id']); ?>
<button class="btn btn-primary" type="submit">Save role</button>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
    <?php if ($can('staff.manage')): ?>
<section class="admin-panel">
<h2 class="h5">Create a staff role</h2>
<form method="post" action="<?= $root ?>/roles" data-admin-confirm="Create this staff role?">
<?php $csrf(); ?>
<input type="hidden" name="action" value="save">
<label for="role-name-new" class="form-label">Role name</label>
<input class="form-control mb-3" id="role-name-new" name="name" maxlength="100" required>
<fieldset>
<legend class="h6">Permissions</legend>
<div class="admin-permissions">
<?php foreach ($data['permissionChoices'] as $permission): ?>
<label class="form-check">
<input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $escape($permission) ?>">
<span class="form-check-label">
<?= $escape(\Furoeducare\Modules\Admin\StaffPermissions::ALL[$permission] ?? $permission) ?>
</span>
</label>
<?php endforeach; ?>
</div>
</fieldset>
<?php $confirmation('role-new'); ?>
<button class="btn btn-primary" type="submit">Create role</button>
</form>
</section>
<?php endif; ?>
<?php elseif ($page === 'reports'): ?>
    <?php require __DIR__ . '/reports.php'; ?><?php else: ?>
    <?php if ($page === 'accounts'): ?>
<nav class="d-flex flex-wrap gap-3 mb-4" aria-label="Account types">
<?php foreach (['teacher' => 'Teachers', 'school' => 'Schools', 'parent' => 'Parents'] as $type => $label): ?>
<a href="<?= $root ?>/accounts?type=<?= $type ?>"<?= $data['type'] === $type ? ' aria-current="page"' : '' ?>>
<?= $label ?>
</a>
<?php endforeach; ?>
</nav>
<?php endif; ?>
    <form method="get" action="<?= $escape($data['listPath']) ?>" class="admin-panel">
<div class="row g-3 align-items-end">
    <?php if ($page === 'accounts'): ?>
<input type="hidden" name="type" value="<?= $escape($data['type']) ?>">
<?php endif; ?>
    <?php foreach ($data['filterLabels'] as $key => $label): ?>
<div class="col-sm-6 col-xl-3">
<label class="form-label" for="filter-<?= $escape($key) ?>">
<?= $escape($label) ?>
</label>
<?php if ($key === 'status' && $statusChoices !== []): ?>
    <select id="filter-status" class="form-select" name="status">
        <option value="">All statuses</option>
        <?php foreach ($statusChoices as $statusValue => $statusLabel): ?>
            <option value="<?= $escape($statusValue) ?>"<?= ($filters['status'] ?? '') === $statusValue ? ' selected' : '' ?>><?= $escape($statusLabel) ?></option>
        <?php endforeach; ?>
    </select>
<?php else: ?>
    <input id="filter-<?= $escape($key) ?>" class="form-control" name="<?= $escape($key) ?>" value="<?= $escape($filters[$key] ?? '') ?>" maxlength="200"<?= $key === 'q' ? ' type="search"' : '' ?>>
<?php endif; ?>
</div>
<?php endforeach; ?>
    <div class="col-auto">
<button class="btn btn-primary" type="submit">
<?= $page === 'search' ? 'Search' : 'Apply filters' ?>
</button>
</div>
<div class="col-auto">
<a href="<?= $escape($data['listPath'] . ($page === 'accounts' ? '?type=' . $data['type'] : '')) ?>">Clear filters</a>
</div>
</div>
</form>
    <?php if ($page === 'staff'): ?>
        <?php if ($can('staff.manage')): ?>
            <p class="text-body-secondary">To appoint a staff member, search for an existing verified account by email and assign the appropriate roles. The account keeps its existing sign-in details.</p>
        <?php endif; ?>
        <?php if ($rows === []): ?>
<p class="admin-panel" data-empty-state>No matching staff accounts.</p>
<?php endif; ?>
        <?php foreach (array_slice($rows, 0, 20) as $member): $roleIds = array_map(static fn ($role): int => (int) (is_array($role) ? $role['id'] : $role), $member['staff_roles']); ?>
<section class="admin-panel">
<h2 class="h5">
<?= $escape($member['email']) ?>
</h2>
<p class="small">Account #<?= (int) $member['id'] ?> · <?= $member['suspended_at'] === null ? 'Active' : 'Suspended' ?>
</p>
        <?php if (!$can('staff.manage') || (int) $member['id'] === (int) $data['viewer']['id']): ?>
<p>Staff assignment changes require management permission and a different target account.</p>
        <?php else: ?>
<form method="post" action="<?= $root ?>/staff" data-admin-confirm="Replace this account’s staff role assignments?">
<?php $csrf(); ?>
<input type="hidden" name="action" value="assign">
<input type="hidden" name="target_id" value="<?= (int) $member['id'] ?>">
<input type="hidden" name="version" value="<?= (int) $member['auth_version'] ?>">
<fieldset>
<legend class="h6">Staff roles</legend>
<div class="admin-permissions">
<?php foreach ($data['roles'] as $role): ?>
<label class="form-check">
<input type="checkbox" class="form-check-input" name="role_ids[]" value="<?= (int) $role['id'] ?>"<?= in_array((int) $role['id'], $roleIds, true) ? ' checked' : '' ?>>
<span class="form-check-label">
<?= $escape($role['name']) ?>
</span>
</label>
<?php endforeach; ?>
</div>
</fieldset>
<?php $confirmation('staff-' . $member['id']); ?>
<button class="btn btn-primary" type="submit">Save assignments</button>
</form>
<?php endif; ?>
<?php if ($can('staff.manage') && $can('accounts.manage') && (int) $member['id'] !== (int) $data['viewer']['id'] && ($member['is_staff'] ?? $roleIds !== [])): $suspending = $member['suspended_at'] === null; ?>
    <hr class="my-4">
    <h3 class="h6"><?= $suspending ? 'Suspend staff account access' : 'Restore staff account access' ?></h3>
    <p class="small text-body-secondary">This changes account access and invalidates existing sessions. Protected administrator access is checked before saving.</p>
    <form method="post" action="<?= $root ?>/staff" data-admin-confirm="Confirm this staff account access change?">
        <?php $csrf(); ?>
        <input type="hidden" name="action" value="<?= $suspending ? 'suspend' : 'restore' ?>">
        <input type="hidden" name="target_id" value="<?= (int) $member['id'] ?>">
        <input type="hidden" name="version" value="<?= (int) $member['auth_version'] ?>">
        <?php $confirmation('staff-access-' . $member['id']); ?>
        <button type="submit" class="btn <?= $suspending ? 'btn-danger' : 'btn-primary' ?>"><?= $suspending ? 'Suspend access' : 'Restore access' ?></button>
    </form>
<?php endif; ?>
</section>
<?php endforeach; ?>
    <?php else: ?>
<section class="admin-panel">
    <?php if ($page === 'accounts'): $table(array_slice($rows, 0, $perPage), ['id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'profile_status' => 'Profile', 'suspended_at' => 'Suspended'], static fn ($row) => $root . '/accounts/view?type=' . $data['type'] . '&id=' . (int) $row['id']);
    elseif ($page === 'listings'): $table(array_slice($rows, 0, $perPage), ['id' => 'Teacher ID', 'name' => 'Teacher', 'title' => 'Listing', 'reference' => 'Reference', 'listing_status' => 'Listing status', 'verification_status' => 'Verification'], static fn ($row) => $root . '/directory?id=' . (int) $row['id']);
    elseif ($page === 'search'): $table(array_slice($rows, 0, $perPage), ['type' => 'Record type', 'label' => 'Record', 'detail' => 'Details', 'status' => 'Status'], static fn ($row) => $row['path']);
    elseif ($page === 'cv-requests'): $table(array_slice($rows, 0, $perPage), ['reference' => 'Reference', 'scope' => 'Scope', 'status' => 'Status', 'created_at' => 'Requested'], static fn ($row) => $root . '/candidate-requests?id=' . (int) $row['case_id'] . '&queue=disclosure');
    elseif ($page === 'email'): $table(array_slice($rows, 0, $perPage), ['id' => 'ID', 'user_id' => 'Account', 'template' => 'Template', 'status' => 'Status', 'attempts' => 'Attempts', 'max_attempts' => 'Limit', 'last_error_code' => 'Failure code', 'provider_status' => 'Provider code', 'created_at' => 'Queued', 'accepted_at' => 'Accepted']);
    elseif ($page === 'audit'): $table(array_slice($rows, 0, $perPage), ['id' => 'Event', 'actor_user_id' => 'Actor account', 'action' => 'Action', 'created_at' => 'Recorded']); endif; ?>
    </section>
<?php endif; ?>
    <?php if (count($rows) > $perPage): $query = $filters + ($page === 'accounts' ? ['type' => $data['type']] : []); ?>
<?= \Furoeducare\UI\Components::pagination($data['listPath'], $query, (int) $rows[$perPage - 1]['id']) ?>
<?php endif; ?>
<?php endif; ?>
</div>

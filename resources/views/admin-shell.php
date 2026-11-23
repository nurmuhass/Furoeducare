<?php
$navigation = \Furoeducare\Modules\Admin\StaffPermissions::navigation($data['adminPermissions'] ?? []);
$adminPath = $data['adminPath'] ?? '';
$adminType = $data['type'] ?? $data['adminType'] ?? 'teacher';
$activePath = null;
$activeSpecificity = -1;
foreach ($navigation as $item) {
    $destination = parse_url($item['path']);
    $path = $destination['path'] ?? '';
    parse_str($destination['query'] ?? '', $query);
    $matchesPath = $adminPath === $path
        || ($path !== '/dashboard/admin' && str_starts_with($adminPath, $path . '/'));
    if ($matchesPath && (!isset($query['type']) || $query['type'] === $adminType)
        && strlen($path) > $activeSpecificity) {
        $activePath = $item['path'];
        $activeSpecificity = strlen($path);
    }
}
$adminReadOnly = ($data['adminCanManage'] ?? true) === false;
$group = '';
?>
<div class="admin-workspace" data-admin-workspace>
    <aside class="admin-sidebar" id="admin-navigation" aria-label="Administration menu">
        <button type="button" class="btn btn-outline-secondary admin-close-menu" data-admin-close-menu aria-controls="admin-navigation" hidden>Close menu</button>
        <a class="brand admin-brand" href="/dashboard/admin"><span class="brand-mark" aria-hidden="true"></span><span><?= $escape($data['appName']) ?><small>Administration</small></span></a>
        <nav aria-label="Administration">
            <?php foreach ($navigation as $link): ?>
                <?php if (($link['group'] ?? '') !== $group): $group = $link['group'] ?? ''; ?><p class="admin-nav-group"><?= $escape($group) ?></p><?php endif; ?>
                <a class="admin-nav-link" href="<?= $escape($link['path']) ?>"<?= $activePath === $link['path'] ? ' aria-current="page"' : '' ?>><?= $escape($link['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-end"><a href="/">View public website</a><?php require $partials . '/appearance-picker.php'; ?></div>
    </aside>
    <div class="admin-body">
        <header class="admin-topbar">
            <button type="button" class="btn btn-outline-secondary admin-menu" data-admin-menu aria-controls="admin-navigation" aria-expanded="false" hidden>Menu</button>
            <?php if (in_array('overview.view', $data['adminPermissions'] ?? [], true)): ?><form method="get" action="/dashboard/admin/search" class="admin-global-search" role="search"><label class="visually-hidden" for="admin-search">Search administration</label><input id="admin-search" class="form-control" name="q" type="search" placeholder="Search records…" maxlength="200"><button class="btn btn-outline-secondary" type="submit">Search</button></form><?php endif; ?>
            <div class="admin-account"><span class="small admin-role-label"><?= $escape(implode(', ', $data['adminRoles'] ?? [])) ?></span><?php require $partials . '/notification-bell.php'; ?><form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $escape($data['csrf'] ?? '') ?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Log out</button></form></div>
        </header>
        <?php if (($data['adminNotice'] ?? '') !== ''): ?><div class="admin-operational-notice" role="status"><?= $escape($data['adminNotice']) ?></div><?php endif; ?>
        <main id="main" tabindex="-1" class="admin-main"<?= $adminReadOnly ? ' data-admin-readonly' : '' ?>>
            <?php if ($adminReadOnly): ?>
                <aside class="admin-readonly-notice" aria-label="Read-only workspace">
                    <strong>Read-only access</strong>
                    <p class="mb-0">You can review this module. Changes require an authorised staff member with management permission.</p>
                </aside>
            <?php endif; ?>
            <?= $data['content'] ?>
        </main>
        <footer class="admin-footer"><span><?= $escape($data['appName']) ?> administration · All times WAT</span><?php if (($data['adminSupportEmail'] ?? '') !== ''): ?><a href="mailto:<?= $escape($data['adminSupportEmail']) ?>">Contact support</a><?php endif; ?></footer>
    </div>
</div>

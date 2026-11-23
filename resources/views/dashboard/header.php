<header class="dashboard-header">
    <div class="dashboard-header-context"><button class="btn button button-outline dashboard-menu" type="button" data-dashboard-menu aria-expanded="false" aria-controls="dashboard-navigation" hidden>Menu</button><span><?= $escape($role->label()) ?></span></div>
    <div class="dashboard-header-actions">
        <?php require $partials . '/notification-bell.php'; ?>
        <details class="dashboard-account" data-dashboard-account><summary>My account</summary><div class="dashboard-account-panel">
            <p><?= $escape($data['dashboardEmail']) ?></p>
            <?php foreach ($data['dashboardRoles'] as $other): ?>
                <?php if ($other !== $role): ?><a href="<?= $escape($other->dashboard()) ?>"><?= $escape($other->label()) ?></a><?php endif; ?>
            <?php endforeach; ?>
            <a href="/settings/account">Account settings</a><a href="/settings/notifications">Notification preferences</a><a href="/privacy/controls">Privacy controls</a>
            <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $escape($data['dashboardCsrf']) ?>"><button class="btn button button-outline" type="submit">Log out</button></form>
        </div></details>
    </div>
</header>
<?php if ($role === \Furoeducare\Modules\Auth\Role::Admin && in_array('overview.view', $data['adminPermissions'] ?? [], true)): ?>
    <div class="dashboard-header-search"><?= \Furoeducare\UI\Components::search('/dashboard/admin/search', is_string($data['dashboardQuery']['q'] ?? null) ? $data['dashboardQuery']['q'] : '', 'Search administration') ?></div>
<?php endif; ?>

<?php

use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Teacher\DashboardPage;

$tab = $data['tab'];
$profile = $data['profile'];
$name = trim($profile['first_name'] . ' ' . $profile['last_name']);
$category = $profile['category'] === 'nysc' ? 'NYSC Member' : 'Teacher';
$initials = '';
foreach ([$profile['first_name'], $profile['last_name']] as $part) {
    if (preg_match('/\A\X/u', $part, $match) === 1) {
        $initials .= strtoupper($match[0]);
    }
}
$sections = [
    'Workspace' => [DashboardPage::Overview, DashboardPage::Profile, DashboardPage::Documents, DashboardPage::Availability],
    'Recruitment' => [DashboardPage::Jobs, DashboardPage::Applications, DashboardPage::Placements, DashboardPage::SavedJobs],
    'Resources and account' => [DashboardPage::Library, DashboardPage::Messages, DashboardPage::Notifications, DashboardPage::Settings],
];
$complete = count(array_filter($data['missing'], static fn (array $items): bool => $items === []));
$total = count($data['missing']);
$percent = (int) round(100 * $complete / $total);
?>
<div class="teacher-workspace" data-teacher-workspace>
    <header class="teacher-header">
        <div class="teacher-header-context">
            <button class="teacher-menu-toggle btn button button-outline" type="button" data-teacher-menu-toggle
                aria-controls="teacher-navigation" aria-expanded="false" hidden>
                <span class="menu-icon" aria-hidden="true"></span> Menu
            </button>
            <span class="teacher-header-title"><?= $category === 'Teacher' ? 'Teacher dashboard' : 'NYSC dashboard' ?></span>
        </div>

        <details class="teacher-account" data-teacher-account>
            <summary>
                <span class="teacher-avatar" aria-hidden="true"><?= $escape($initials) ?></span>
                <span class="teacher-account-name"><?= $escape($name) ?></span>
                <span class="teacher-chevron" aria-hidden="true"></span>
                <span class="sr-only">Account menu</span>
            </summary>
            <div class="teacher-account-panel">
                <div class="teacher-account-details">
                    <strong><?= $escape($name) ?></strong>
                    <span><?= $escape($data['email']) ?></span>
                </div>
                <a href="<?= $escape(DashboardPage::Settings->url()) ?>">Account settings</a>
                <?php foreach ($data['roleLinks'] as $role): ?>
                    <?php if ($role !== Role::Teacher): ?>
                        <a href="<?= $escape($role->dashboard()) ?>"><?= $escape($role->label()) ?> dashboard</a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <a href="/">Public website</a>
                <form method="post" action="/logout">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <button type="submit">Log out</button>
                </form>
            </div>
        </details>
    </header>

    <aside id="teacher-navigation" class="teacher-sidebar">
        <a class="brand teacher-brand" href="<?= $escape(DashboardPage::Overview->url()) ?>">
            <span class="brand-mark" aria-hidden="true"></span><?= $escape($data['appName']) ?>
        </a>
        <nav aria-label="Teacher dashboard navigation">
            <?php foreach ($sections as $group => $links): ?>
                <div class="teacher-nav-group">
                    <p><?= $escape($group) ?></p>
                    <ul>
                        <?php foreach ($links as $item): ?>
                            <li><a href="<?= $escape($item->url()) ?>" <?= $tab === $item ? 'aria-current="page"' : '' ?>>
                                <?= $escape($item->label()) ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main id="main" class="teacher-main" tabindex="-1">
        <div class="teacher-main-inner">
            <div class="teacher-page-heading">
                <h1><?= $escape($data['title']) ?></h1>
                <?php if ($tab === DashboardPage::Overview): ?>
                    <p>Welcome, <?= $escape($profile['first_name']) ?>.</p>
                <?php endif; ?>
            </div>
            <?php if ($data['notice'] !== ''): ?><p class="auth-notice teacher-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
            <?php require __DIR__ . '/' . $data['panel'] . '.php'; ?>
        </div>
    </main>
</div>

<?php
$currentPath = $data['path'] ?? '';
$primaryLinks = [
    '/about' => 'About',
    '/services' => 'Services',
    '/find-teachers' => 'Find teachers',
    '/teaching-jobs' => 'Teaching jobs',
    '/digital-library' => 'Library',
    '/training-workshops' => 'Training',
    '/contact' => 'Contact',
];
?>
<header class="site-header" data-site-header>
    <div class="site-shell header-inner">
        <a class="brand" href="/" <?= $currentPath === '/' ? 'aria-current="page"' : '' ?>>
            <span class="brand-mark" aria-hidden="true"></span>
            <span><?= $escape($data['appName']) ?></span>
        </a>
        <button class="menu-toggle btn" type="button" data-menu-toggle aria-controls="public-navigation" aria-expanded="false" hidden>
            <span class="menu-icon" aria-hidden="true"></span> Menu
        </button>
        <nav id="public-navigation" class="site-nav" aria-label="Primary">
            <ul class="nav-links">
                <?php foreach ($primaryLinks as $path => $label): ?>
                    <li><a href="<?= $escape($path) ?>" <?= $currentPath === $path ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="nav-actions">
                <?php if ($data['signedIn'] ?? false): ?>
                    <a class="nav-login" href="/dashboard">Dashboard</a>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <button class="btn button button-outline button-small" type="submit">Log out</button>
                    </form>
                <?php else: ?>
                    <a class="nav-login" href="/login" <?= $currentPath === '/login' ? 'aria-current="page"' : '' ?>>Log in</a>
                    <a class="btn button button-primary button-small" href="/register" <?= $currentPath === '/register' ? 'aria-current="page"' : '' ?>>Get started</a>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</header>

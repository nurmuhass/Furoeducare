<aside class="dashboard-sidebar" id="dashboard-navigation" aria-label="<?= $escape($role->label()) ?> menu">
    <button type="button" class="btn button button-outline dashboard-close" data-dashboard-close hidden>Close menu</button>
    <a class="brand dashboard-brand" href="<?= $escape($role->dashboard()) ?>"><span class="brand-mark" aria-hidden="true"></span><span><?= $escape($data['appName']) ?><small><?= $escape($role->label()) ?></small></span></a>
    <nav aria-label="<?= $escape($role->label()) ?> dashboard navigation">
        <?php $groups = []; foreach ($navigation as $link) $groups[$link['group']][] = $link; ?>
        <?php foreach ($groups as $group => $links): ?>
            <section class="dashboard-nav-section" aria-label="<?= $escape($group) ?>">
                <h2><?= $escape($group) ?></h2><ul>
                    <?php foreach ($links as $link): ?><li>
                        <?php if ($link['path'] !== ''): ?><a href="<?= $escape($link['path']) ?>"<?= $active === $link['path'] ? ' aria-current="page"' : '' ?>><?= $escape($link['label']) ?></a>
                        <?php else: ?><span class="dashboard-nav-disabled" aria-disabled="true"><?= $escape($link['label']) ?><small><?= $escape($link['hint']) ?></small></span><?php endif; ?>
                    </li><?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </nav>
    <div class="dashboard-sidebar-end"><a href="/">Public website</a><?php require $partials . '/appearance-picker.php'; ?></div>
</aside>

<?php
$staff = $data['staff'];
$root = $data['root'];
$filters = $data['filters'];
?>
<section class="page-band"><div class="site-shell">
    <p class="eyebrow">Furoeducare resources</p>
    <h1><?= $escape($data['title']) ?></h1>
    <p>Find reading and learning materials by subject, level or category. Each resource shows its access requirements.</p>
    <nav class="d-flex gap-3 flex-wrap" aria-label="Library navigation">
        <a href="/digital-library">Browse library</a>
        <?php if ($data['signedIn']): ?><a href="/digital-library/saved">Saved resources</a><a href="/digital-library/history">My downloads</a><?php else: ?><a href="/login">Sign in to save resources</a><?php endif; ?>
        <?php if ($staff): ?><a href="<?= $escape($root) ?>?new=1">Add a resource</a><?php endif; ?>
    </nav>
</div></section>
<div class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <form method="get" action="<?= $escape($root) ?>" class="form-panel mb-4" role="search" aria-label="Search library">
        <div class="row g-3 align-items-end">
            <?php foreach (['q' => ['Search title or author', 200], 'category' => ['Category', 120], 'subject' => ['Subject', 120], 'level' => ['Level', 120]] as $key => [$label, $limit]): ?>
                <div class="<?= $key === 'q' ? 'col-lg-4' : 'col-sm-6 col-lg-2' ?>">
                    <label for="library-<?= $escape($key) ?>" class="form-label"><?= $escape($label) ?></label>
                    <input class="form-control" type="search" id="library-<?= $escape($key) ?>" name="<?= $escape($key) ?>" maxlength="<?= $limit ?>" value="<?= $escape($filters[$key]) ?>">
                </div>
            <?php endforeach; ?>
            <div class="col-lg-2"><button class="btn button button-primary w-100" type="submit">Search</button></div>
        </div>
        <a class="d-inline-block mt-3" href="<?= $escape($root) ?>">Clear filters</a>
    </form>
    <?php if ($data['rows'] === []): ?>
        <section class="form-panel text-center py-5" data-empty-state>
            <h2 class="h4"><?= $data['saved'] ? 'No saved resources to show' : 'No resources to show' ?></h2>
            <p><?= $data['saved'] ? 'Save a resource from its details page to return to it here.' : 'Try a broader search or return later as resources are added.' ?></p>
        </section>
    <?php else: ?>
        <div class="row g-4">
        <?php foreach ($data['rows'] as $resource): $link = $staff ? $root . '?id=' . (int) $resource['id'] : '/digital-library/resource?ref=' . rawurlencode($resource['reference']); ?>
            <div class="col-sm-6 col-lg-4"><article class="form-panel h-100 d-flex flex-column">
                <div class="rounded bg-light border d-flex align-items-center justify-content-center mb-3 overflow-hidden" style="height:220px">
                    <?php if (!$staff && !empty($resource['has_cover'])): ?>
                        <img src="/digital-library/cover?ref=<?= $escape(rawurlencode($resource['reference'])) ?>" alt="Cover of <?= $escape($resource['title']) ?>" loading="lazy" class="w-100 h-100" style="object-fit:contain">
                    <?php else: ?><span class="text-center text-secondary"><span aria-hidden="true" class="d-block fs-1">&#128214;</span><?= $escape(strtoupper($resource['material_extension'] ?? 'Document')) ?> resource</span><?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2"><span class="status-badge"><?= $escape(ucfirst(str_replace('_', ' ', $resource['access']))) ?></span><?php if ($staff): ?><span class="status-badge"><?= $escape(ucfirst($resource['status'])) ?></span><?php endif; ?></div>
                <h2 class="h4"><?php if ($staff || ($resource['is_available'] ?? true)): ?><a href="<?= $escape($link) ?>"><?= $escape($resource['title']) ?></a><?php else: ?><?= $escape($resource['title']) ?><?php endif; ?></h2>
                <p><?= $resource['author'] !== '' ? 'By ' . $escape($resource['author']) : 'Author not supplied' ?></p>
                <?php foreach (['subjects', 'levels'] as $tag): ?>
                    <?php if (!empty($resource['tags'][$tag])): ?><p class="small mb-2"><?= $escape(implode(' · ', $resource['tags'][$tag])) ?></p><?php endif; ?>
                <?php endforeach; ?>
                <?php if ($staff || ($resource['is_available'] ?? true)): ?>
                    <p class="mt-auto mb-0"><a href="<?= $escape($link) ?>"><?= $staff ? 'Manage resource' : 'View resource' ?> <span aria-hidden="true">&rarr;</span></a></p>
                <?php else: ?><p class="mt-auto">Currently unavailable</p><?php endif; ?>
                <?php if ($data['saved']): ?><form action="/digital-library/save" method="post" class="mt-3">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="ref" value="<?= $escape($resource['reference']) ?>">
                    <button class="btn button button-outline" name="action" value="remove">Remove saved resource</button>
                </form><?php endif; ?>
            </article></div>
        <?php endforeach; ?>
        </div>
        <?php if ($data['next'] !== null): ?><a class="btn button button-outline mt-4" href="<?= $escape($root . '?' . http_build_query($filters + ['before' => $data['next']])) ?>">More resources</a><?php endif; ?>
    <?php endif; ?>
</div>

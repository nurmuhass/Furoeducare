<section class="page-band">
    <div class="site-shell resource-grid">
        <div>
            <?php require $partials . '/breadcrumb.php'; ?>
            <p class="eyebrow"><span aria-hidden="true"></span> <?= $escape($data['category']) ?></p>
            <h1><?= $escape($data['title']) ?></h1>
            <p class="page-lede"><?= $escape($data['lead']) ?></p>
        </div>
        <aside class="resource-empty tone-<?= $escape($data['tone']) ?>" data-empty-state aria-labelledby="empty-heading">
            <span class="service-icon icon-<?= $escape($data['icon']) ?>" aria-hidden="true"></span>
            <h2 id="empty-heading"><?= $escape($data['emptyTitle']) ?></h2>
            <p><?= $escape($data['emptyText']) ?></p>
            <a class="text-link" href="<?= $escape($data['next'][0]) ?>"><?= $escape($data['next'][1]) ?> <span aria-hidden="true">→</span></a>
        </aside>
    </div>
</section>
<section class="site-shell section-space" aria-labelledby="resource-guide">
    <div class="section-heading"><h2 id="resource-guide"><?= $escape($data['section']) ?></h2></div>
    <div class="details-grid">
        <?php foreach ($data['details'] as [$title, $description]): ?>
            <article class="detail-card">
                <h3><?= $escape($title) ?></h3>
                <p><?= $escape($description) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ($data['path'] === '/training-workshops'): ?>
        <img class="training-image" src="/assets/educators.jpg" width="1448" height="1086" loading="lazy"
             alt="Educators review materials together in a school library.">
    <?php endif; ?>
</section>

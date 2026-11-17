<section class="page-band">
    <div class="site-shell">
        <a href="<?= $escape($data['root']) ?>">Verification queues</a>
        <h1 class="my-3"><?= $escape($data['title']) ?></h1>
        <?php if ($data['filters'] ?? false): ?>
            <nav class="button-row mb-3" aria-label="Teacher queue filter">
                <a href="<?= $escape($data['root']) ?>/teachers" <?= $data['filter'] === 'pending' ? 'aria-current="page"' : '' ?>>Pending verification</a>
                <a href="<?= $escape($data['root']) ?>/teachers?status=all" <?= $data['filter'] === 'all' ? 'aria-current="page"' : '' ?>>All teacher profiles</a>
            </nav>
        <?php endif; ?>
        <?php foreach ($data['groups'] as $group): ?>
            <section class="form-panel mb-3">
                <h2><?= $escape($group['title']) ?></h2>
                <?php if ($group['items'] === []): ?>
                    <p>No items to review on this page.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach (array_slice($group['items'], 0, 30) as $row): ?>
                            <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                                <a href="<?= $escape($group['prefix']) ?>?id=<?= (int) $row[$group['idKey']] ?>"><?= $escape($row['name']) ?></a>
                                <span><?= $escape($row['context']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($group['items']) > 30): ?>
                        <a href="<?= $escape($group['nextPrefix']) ?><?= (int) $group['items'][29][$group['idKey']] ?>">Older <?= $escape(strtolower($group['title'])) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</section>

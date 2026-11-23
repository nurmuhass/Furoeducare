<section class="page-band"><div class="site-shell"><a href="/digital-library">Back to digital library</a><h1 class="mt-3">My download history</h1><p>Downloads recorded for your account.</p></div></section>
<div class="site-shell section-space">
    <?php if ($data['rows'] === []): ?><?= \Furoeducare\UI\Components::empty('No downloads recorded', 'Your account downloads will appear here.') ?>
    <?php else: ?>
        <?= \Furoeducare\UI\Components::table(array_map(static fn (array $row): array => [
            'title' => $row['title'], 'reference' => $row['reference'],
            'downloaded' => $data['wat']((string) ($row['downloaded_at'] ?? $row['created_at'])),
        ], $data['rows']), ['title' => 'Resource', 'reference' => 'Reference', 'downloaded' => 'Downloaded (WAT)'], 'My download history') ?>
        <?= \Furoeducare\UI\Components::pagination('/digital-library/history', [], $data['next']) ?>
    <?php endif; ?>
</div>

<?php
$requestBase = $data['staffPath'];
$pageSize = $data['pageSize'];
?>
<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard/admin">Back to dashboard</a>
        <h1 class="my-3">Privacy requests</h1>
        <nav class="button-row mb-3" aria-label="Privacy request status">
            <a href="<?= $escape($requestBase) ?>" <?= $data['filter'] === 'submitted' ? 'aria-current="page"' : '' ?>>Submitted</a>
            <a href="<?= $escape($requestBase) ?>?status=resolved" <?= $data['filter'] === 'resolved' ? 'aria-current="page"' : '' ?>>Resolved</a>
        </nav>
        <section class="form-panel" aria-label="Privacy requests">
            <?php require __DIR__ . '/privacy-request-list.php'; ?>
            <?php if (count($data['requests']) > $pageSize): ?>
                <a href="<?= $escape($requestBase) ?>?status=<?= $escape($data['filter']) ?>&amp;before=<?= (int) $data['requests'][$pageSize - 1]['id'] ?>">Older requests</a>
            <?php endif; ?>
        </section>
    </div>
</section>

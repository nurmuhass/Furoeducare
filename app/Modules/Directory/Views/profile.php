<?php $listing = $data['listing']; ?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="/find-teachers">Find teachers</a>
        <h1 class="mt-3"><?= $escape($data['title']) ?></h1>
        <?php if ($listing['professional_name'] !== null): ?><p class="page-lede"><?= $escape($listing['title']) ?></p><?php endif; ?>
        <p>Ref <?= $escape($listing['reference']) ?></p>
    </div>
</section>
<section class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <div class="form-panel">
        <?php if ($listing['has_photo']): ?>
            <img class="directory-profile-photo mb-3" src="/find-teachers/photo?ref=<?= $escape($listing['reference']) ?>"
                 width="144" height="144" alt="Professional portrait">
        <?php endif; ?>
        <h2>Professional profile</h2>
        <p class="mb-4 text-break"><?= $escape($listing['bio']) ?></p>
        <?php require __DIR__ . '/facts.php'; ?>
        <div class="button-row mt-3"><?php require __DIR__ . '/actions.php'; ?></div>
    </div>
</section>

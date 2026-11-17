<?php

$fieldPrefix = 'parent';
require $partials . '/form-fields.php';
$registration = false;
$values = $data['values'];
$errors = $data['errors'];
?>
<section class="page-band">
    <div class="site-shell access-grid">
        <div class="form-panel access-form">
            <h1><?= $escape($data['title']) ?></h1>
            <p class="form-description">Add the parent or guardian’s details to use the dashboard.</p>
            <?php if ($errors !== []): ?><p class="form-summary" role="alert">Review the highlighted fields and try again.</p><?php endif; ?>
            <form class="auth-form" method="post" action="/dashboard/parent/profile">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <?php if (($data['reference'] ?? null) !== null): ?><input type="hidden" name="ref" value="<?= $escape($data['reference']) ?>"><?php endif; ?>
                <?php require __DIR__ . '/profile-fields.php'; ?>
                <?php require $partials . '/legal-acceptance.php'; ?>
                <button type="submit" class="btn button button-primary">Save and open dashboard</button>
            </form>
        </div>
    </div>
</section>

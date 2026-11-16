<section class="page-band">
    <div class="site-shell page-intro">
        <h1><?= $escape($data['title']) ?></h1>
        <p class="page-lede"><?= $escape($data['message']) ?></p>
        <a class="text-link section-link" href="<?= $data['signedIn'] ? '/dashboard' : '/login' ?>">
            <?= $data['signedIn'] ? 'Return to your dashboard' : 'Return to log in' ?>
        </a>
    </div>
</section>

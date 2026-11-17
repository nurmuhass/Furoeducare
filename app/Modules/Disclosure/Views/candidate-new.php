<section class="page-band">
    <div class="site-shell page-intro">
        <a href="<?= $escape($data['root']) ?>">Back to candidate requests</a>
        <h1 class="mt-3">Request candidates</h1>
        <p>Choose a vacancy and tell Furoeducare what the school needs for this placement.</p>
    </div>
</section>
<section class="site-shell section-space">
    <div class="form-panel">
        <?php if ($data['vacancies'] === []): ?>
            <p>Your school needs an eligible vacancy to start a candidate request.</p>
            <a href="/dashboard/school">Return to your school dashboard</a>
        <?php else: ?>
            <?php if ($data['reference'] !== null): ?>
                <p>Selected teacher <strong><?= $escape($data['reference']) ?></strong></p>
            <?php endif; ?>
            <form class="auth-form" method="post" action="<?= $escape($data['root']) ?>">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="action" value="create">
                <?php if ($data['reference'] !== null): ?>
                    <input type="hidden" name="ref" value="<?= $escape($data['reference']) ?>">
                <?php endif; ?>
                <?php
                $vacancyPurpose = '';
                $vacancyRequirements = '';
                require __DIR__ . '/candidate-vacancy-fields.php';
                ?>
                <button class="btn button button-primary" type="submit">Send candidate request</button>
            </form>
        <?php endif; ?>
    </div>
</section>

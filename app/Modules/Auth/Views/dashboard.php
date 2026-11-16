<?php

use Furoeducare\Modules\Auth\Role;

$role = $data['role'];
$school = $data['school'];
?>
<section class="page-band">
    <div class="site-shell page-intro">
        <h1><?= $escape($data['title']) ?></h1>
        <p class="page-lede"><?= $escape($role->label()) ?></p>
    </div>
</section>
<section class="site-shell section-space">
    <div class="form-panel auth-dashboard">
        <h2>Account overview</h2>
        <p class="form-description">Signed in as <?= $escape($data['email']) ?>.</p>
        <?php if ($role === Role::School): ?>
            <?php if ($school !== null): ?>
                <p class="form-description"><?= $escape($school['school_name']) ?></p>
            <?php endif; ?>
            <?php if (($school['approved_at'] ?? null) === null): ?>
                <span class="status-badge">Pending management approval</span>
                <p>Your school dashboard is restricted while management reviews the account. School vacancy posting requires approval.</p>
            <?php else: ?>
                <span class="status-badge">School approved</span>
                <p>Management has approved this school account.</p>
            <?php endif; ?>
        <?php elseif ($role === Role::Teacher || $role === Role::Parent): ?>
            <p><?= $role === Role::Teacher ? 'Your teacher account is ready for onboarding.' : 'Your parent account is ready for onboarding.' ?></p>
        <?php endif; ?>
        <?php if (count($data['roleLinks']) > 1): ?>
            <nav aria-label="Your dashboards" class="button-row auth-related">
                <?php foreach ($data['roleLinks'] as $link): ?>
                    <?php if ($link !== $role): ?>
                        <a href="<?= $escape($link->dashboard()) ?>"><?= $escape($link->label()) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </div>
</section>

<?php

use Furoeducare\Modules\Auth\Role;

$role = $data['role'];
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
        <p><a href="/notifications">Notifications</a></p>
        <p><a href="/settings/notifications">Notification settings</a></p>
        <?php if ($role === Role::Admin): ?>
            <p class="form-next"><a href="/dashboard/admin/messages">Management messages</a></p>
            <p class="form-next"><a href="/dashboard/admin/verification">Verification queues</a></p>
            <p class="form-next"><a href="/dashboard/admin/schools">Review school accounts</a></p>
            <p class="form-next"><a href="/dashboard/admin/verification/teachers?status=all">Manage public teacher listings</a></p>
            <p class="form-next"><a href="/dashboard/admin/candidate-requests">Candidate requests and disclosure review</a></p>
            <p class="form-next"><a href="/dashboard/admin/placements">Managed placements</a></p>
            <p class="form-next"><a href="/dashboard/admin/library">Manage digital library</a></p>
            <p class="form-next"><a href="/dashboard/admin/training">Manage training catalogue</a></p>
            <p class="form-next"><a href="/dashboard/admin/training/requests">Training requests</a></p>
            <p class="form-next"><a href="/dashboard/admin/privacy-requests">Data rights requests</a></p>
            <p class="form-next"><a href="/privacy/controls">My privacy controls</a></p>
            <p class="form-next"><a href="/dashboard/admin/tutoring-requests">Tutoring requests</a></p>
            <p class="form-next"><a href="/dashboard/admin/vacancies">Vacancy moderation</a></p>
            <p class="form-next"><a href="/dashboard/admin/vacancies/applications">Teaching applications</a></p>
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

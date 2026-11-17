<?php

use Furoeducare\Modules\Auth\Role;
use Furoeducare\Modules\Parent\ParentController;

$root = $data['staff'] ? $data['staffRoot'] : $data['root'];
?>
<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard">Back to dashboard</a>
        <h1 class="my-3"><?= $escape($data['title']) ?></h1>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
        <?php if ($data['staff']): ?>
            <nav aria-label="Candidate request queues" class="d-flex flex-wrap gap-3 mb-3">
                <a href="<?= $escape($root) ?>"<?= $data['pendingDisclosure'] ? '' : ' aria-current="page"' ?>>All requests</a>
                <a href="<?= $escape($root) ?>?queue=disclosure"<?= $data['pendingDisclosure'] ? ' aria-current="page"' : '' ?>>Pending disclosure</a>
            </nav>
        <?php elseif ($data['role'] === Role::School): ?>
            <p><a class="btn button button-primary" href="<?= $escape($root) ?>?new=1">Request candidates for a vacancy</a></p>
        <?php elseif ($data['role'] === Role::Parent): ?>
            <p><a href="<?= $escape(ParentController::REQUESTS) ?>">Manage tutoring requests</a></p>
        <?php endif; ?>

        <?php if ($data['cases'] === []): ?>
            <p>There are no candidate requests to show.</p>
        <?php else: ?>
            <ul class="list-unstyled">
                <?php foreach ($data['cases'] as $case): ?>
                    <li class="border-bottom py-3">
                        <a class="fw-semibold" href="<?= $escape($root) ?>?id=<?= (int) $case['id'] ?>">
                            <?= $case['category'] === 'tutoring' ? 'Tutoring' : 'Recruitment' ?> request #<?= (int) $case['id'] ?>
                        </a>
                        <span class="status-badge ms-2"><?= $escape(ucfirst(str_replace('_', ' ', $case['status']))) ?></span>
                        <?php if ($data['staff'] && ($case['disclosure_pending'] ?? false)): ?>
                            <span class="status-badge ms-2">Disclosure pending</span>
                        <?php endif; ?>
                        <p class="text-break mb-0"><?= $escape($case['purpose']) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($data['next'] !== null): ?>
                <a href="<?= $escape($root) ?>?<?= $data['pendingDisclosure'] ? 'queue=disclosure&amp;' : '' ?>before=<?= (int) $data['next'] ?>">Older requests</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

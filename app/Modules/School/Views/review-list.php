<?php

use Furoeducare\Modules\School\SchoolInput;
use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolStatus;
?>
<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard/admin/verification">Verification queues</a>
        <h1 class="my-3"><?= $escape($data['title']) ?></h1>
        <nav class="button-row mb-3" aria-label="School queue filter">
            <a href="/dashboard/admin/schools" <?= $data['filter'] === 'pending' ? 'aria-current="page"' : '' ?>>Pending approval</a>
            <a href="/dashboard/admin/schools?status=all" <?= $data['filter'] === 'all' ? 'aria-current="page"' : '' ?>>All school accounts</a>
        </nav>
        <div class="form-panel">
            <?php if ($data['schools'] === []): ?>
                <p>No school accounts to review on this page.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($data['schools'] as $school): ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <strong><?= $escape($school['school_name']) ?></strong>
                                <?php if (SchoolPolicy::profileComplete($school)): ?>
                                    <p><?= $escape($school['city']) ?>, <?= $escape(SchoolInput::STATES[$school['state_code']]) ?></p>
                                <?php else: ?><p>Profile incomplete</p><?php endif; ?>
                            </div>
                            <span class="status-badge"><?= $escape(SchoolStatus::forSchool($school)->label()) ?></span>
                            <a href="/dashboard/admin/schools?id=<?= (int) $school['user_id'] ?>">Review <span class="sr-only"><?= $escape($school['school_name']) ?></span></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($data['next'] !== null): ?><a href="/dashboard/admin/schools?status=<?= $escape($data['filter']) ?>&amp;before=<?= $data['next'] ?>">Older school accounts</a><?php endif; ?>
        </div>
    </div>
</section>

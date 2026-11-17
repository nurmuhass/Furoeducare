<?php $staff = $data['staff']; ?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="<?= $staff ? '/dashboard/admin' : '/find-teachers' ?>"><?= $staff ? 'Administrator dashboard' : 'Find teachers' ?></a>
        <h1 class="mt-3"><?= $escape($data['title']) ?></h1>
    </div>
</section>
<section class="site-shell section-space">
    <div class="form-panel">
        <?php if ($data['requests'] === []): ?>
            <p class="mb-0" data-empty-state>No teacher requests on this page.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($data['requests'] as $item): ?>
                    <li class="list-group-item">
                        <h2 class="h6">Teacher reference <?= $escape($item['reference']) ?></h2>
                        <p><span class="status-badge"><?= $item['submitted_at'] === null ? 'Private draft' : 'Submitted' ?></span></p>
                        <?php if ($staff): ?><p class="text-break"><?= $escape(ucfirst($item['requester_role'])) ?> · <?= $escape($item['email']) ?></p><?php endif; ?>
                        <p class="directory-request-notes text-break"><?= $escape($item['notes']) ?></p>
                        <?php if ($item['submitted_at'] !== null): ?>
                            <small><?= $escape(substr($item['submitted_at'], 0, 16)) ?> UTC</small>
                        <?php else: ?>
                            <a href="/find-teachers/request?ref=<?= $escape($item['reference']) ?>">Open draft</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($data['next'] !== null): ?>
                <a class="mt-3 d-inline-block" href="<?= $staff ? '/dashboard/admin/directory/requests' : '/find-teachers/requests' ?>?before=<?= $data['next'] ?>">Older requests</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

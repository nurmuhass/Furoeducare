<?php if ($notifications === []): ?>
    <p class="teacher-muted">No verification updates yet.</p>
<?php else: ?>
    <ul class="list-unstyled">
        <?php foreach ($notifications as $event): ?>
            <li class="mb-3">
                <strong><?= $escape($event['title']) ?></strong>
                <p class="text-break mb-1"><?= $escape($event['message']) ?></p>
                <small><?= $escape(substr($event['created_at'], 0, 16)) ?> UTC</small>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($data['placementEvents'] === []): ?>
    <p class="teacher-muted">You have no placement updates yet.</p>
<?php else: ?>
    <?php $notifications = $data['placementEvents']; require __DIR__ . '/verification-events.php'; ?>
<?php endif; ?>
<a href="/placements">View my placements</a>

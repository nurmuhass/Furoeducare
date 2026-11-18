<?php if ($terminal): ?>
    <?php return; ?>
<?php endif; ?>

<?php if (!$assigned): ?>
    <section class="form-panel mb-4" aria-labelledby="placement-assign-heading">
        <h2 id="placement-assign-heading" class="h4">Manage placement</h2>
        <p>Assign this placement to yourself to record decisions and manage the next steps.</p>
        <form method="post" action="<?= $escape($root) ?>">
            <?php require __DIR__ . '/placement-form-fields.php'; ?>
            <button class="btn button button-primary" name="action" value="assign" type="submit">Assign to me</button>
        </form>
    </section>
    <?php return; ?>
<?php endif; ?>

<?php
$actions = match ($status) {
    'screening' => ['shortlist' => 'Shortlist candidate'],
    'shortlisted' => ['schedule' => $tutoring ? 'Schedule discussion' : 'Schedule interview'],
    'discussion_scheduled' => [
        'available' => 'Confirm candidate availability',
        'schedule' => $tutoring ? 'Reschedule discussion' : 'Reschedule interview',
    ],
    'availability_confirmed' => ['select' => $tutoring ? 'Record parent selection' : 'Record school selection'],
    'selected' => ['offer' => $tutoring ? 'Record arrangement' : 'Record offer'],
    'offer_pending' => $home
        ? ['safeguard' => 'Record home tutoring checks'] + ($placement['safeguarding_checked_at'] !== null ? ['confirm' => 'Confirm placement'] : [])
        : ['confirm' => 'Confirm placement'],
    'confirmed' => ['close' => 'Close placement'],
    default => [],
};
if ($status !== 'confirmed') {
    $actions['reject'] = 'Reject placement';
}
?>
<div class="row g-4 mb-4">
    <?php foreach ($actions as $action => $actionLabel): ?>
        <div class="col-md-6">
            <?php require __DIR__ . '/staff-decision.php'; ?>
        </div>
    <?php endforeach; ?>
</div>

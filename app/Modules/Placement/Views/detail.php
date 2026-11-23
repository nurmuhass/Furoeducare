<?php

use Furoeducare\Modules\Parent\AdminTutoringController;
use Furoeducare\Modules\Parent\ParentController;
use Furoeducare\Modules\Vacancy\VacancyController;
use Furoeducare\Modules\Verification\VerificationController;

$placement = $data['placement'];
$root = $data['root'];
$staff = $data['staff'];
$id = (int) $placement['id'];
$status = $placement['status'];
$tutoring = $placement['kind'] === 'tutoring';
$home = $tutoring && $placement['mode'] === 'home';
$assigned = $staff && (int) $placement['assigned_admin_id'] === $data['viewer'];
$terminal = in_array($status, ['rejected', 'closed'], true);
$wat = new DateTimeZone('Africa/Lagos');
$utc = new DateTimeZone('UTC');
$time = static fn (string $value): DateTimeImmutable => (new DateTimeImmutable($value, $utc))->setTimezone($wat);
$statusLabel = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
?>
<section class="page-band">
    <div class="site-shell">
        <a class="text-link" href="<?= $escape($root) ?>">Back to placements</a>
        <h1 class="my-3"><?= $escape($data['title']) ?></h1>
        <p><?= $tutoring ? 'Private tutoring arrangement' : 'School employment placement' ?>
            <span class="status-badge ms-2"><?= $escape($statusLabel($status)) ?></span>
        </p>
        <?php if (!$staff): ?>
            <p><a href="/messages?new=1&amp;context_type=placement&amp;context_id=<?= $id ?>">Message management about this placement</a></p>
        <?php endif; ?>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
    </div>
</section>

<div class="site-shell section-space">
    <section class="form-panel mb-4" aria-labelledby="placement-details-heading">
        <h2 id="placement-details-heading" class="h4">Placement details</h2>
        <dl class="mb-0">
            <dt>Candidate reference</dt>
            <dd><?= $escape($placement['teacher_reference']) ?></dd>
            <?php if ($tutoring): ?>
                <dt>Lesson format</dt>
                <dd><?= $home ? 'Home tutoring' : 'Online tutoring' ?></dd>
                <?php if (!$staff && (int) $placement['requester_id'] === $data['viewer']): ?>
                    <dt>Tutoring request</dt>
                    <dd><a href="<?= $escape(ParentController::REQUESTS . '&id=' . (int) $placement['tutoring_id']) ?>">View your tutoring request</a></dd>
                <?php endif; ?>
            <?php elseif (($placement['source_label'] ?? '') !== ''): ?>
                <dt>Vacancy</dt>
                <dd class="text-break"><?= $escape($placement['source_label']) ?></dd>
            <?php endif; ?>
            <?php if ($placement['scheduled_at'] !== null): ?>
                <?php $scheduled = $time($placement['scheduled_at']); ?>
                <dt><?= $tutoring ? 'Discussion' : 'Interview' ?></dt>
                <dd><time datetime="<?= $escape($scheduled->format(DATE_ATOM)) ?>"><?= $escape($scheduled->format('j M Y, H:i')) ?> WAT</time></dd>
            <?php endif; ?>
            <?php if (is_string($placement['offer_summary']) && $placement['offer_summary'] !== ''): ?>
                <dt><?= $tutoring ? 'Arrangement details' : 'Offer details' ?></dt>
                <dd class="directory-request-notes text-break"><?= $escape($placement['offer_summary']) ?></dd>
            <?php endif; ?>
            <?php if ($placement['starts_on'] !== null): ?>
                <dt>Start date</dt>
                <dd><time datetime="<?= $escape($placement['starts_on']) ?>"><?= $escape($placement['starts_on']) ?></time></dd>
            <?php endif; ?>
            <?php if ($placement['confirmed_at'] !== null): ?>
                <?php $confirmed = $time($placement['confirmed_at']); ?>
                <dt>Confirmed</dt>
                <dd><time datetime="<?= $escape($confirmed->format(DATE_ATOM)) ?>"><?= $escape($confirmed->format('j M Y, H:i')) ?> WAT</time></dd>
            <?php endif; ?>
            <?php if ($placement['closed_at'] !== null): ?>
                <?php $closed = $time($placement['closed_at']); ?>
                <dt>Closed</dt>
                <dd><time datetime="<?= $escape($closed->format(DATE_ATOM)) ?>"><?= $escape($closed->format('j M Y, H:i')) ?> WAT</time></dd>
            <?php endif; ?>
            <?php if ($staff): ?>
                <dt>Source</dt>
                <?php if ($tutoring): ?>
                    <dd><a href="<?= $escape(AdminTutoringController::ROOT) ?>?id=<?= (int) $placement['tutoring_id'] ?>">
                        Tutoring request #<?= (int) $placement['tutoring_id'] ?>
                    </a></dd>
                <?php else: ?>
                    <dd><a href="<?= $escape(VacancyController::STAFF) ?>/application?id=<?= (int) $placement['application_id'] ?>">
                        Teaching application #<?= (int) $placement['application_id'] ?>
                    </a></dd>
                <?php endif; ?>
                <dt>Assigned administrator</dt>
                <dd><?= $assigned ? 'You' : ($placement['assigned_admin_id'] === null ? 'Unassigned' : '#' . (int) $placement['assigned_admin_id']) ?></dd>
                <dt>Professional checks</dt>
                <dd><a href="<?= $escape(VerificationController::ROOT) ?>/teachers?id=<?= (int) $placement['teacher_id'] ?>">Open teacher verification</a></dd>
                <?php if ($home && $placement['safeguarding_checked_at'] !== null): ?>
                    <?php $checked = $time($placement['safeguarding_checked_at']); ?>
                    <dt>Home safeguarding review</dt>
                    <dd><time datetime="<?= $escape($checked->format(DATE_ATOM)) ?>"><?= $escape($checked->format('j M Y, H:i')) ?> WAT</time></dd>
                    <dt>Safeguarding evidence reference</dt>
                    <dd class="directory-request-notes text-break"><?= $escape((string) $placement['safeguarding_reference']) ?></dd>
                <?php endif; ?>
            <?php endif; ?>
        </dl>
    </section>

    <?php if ($staff): ?>
        <?php require __DIR__ . '/staff-actions.php'; ?>
        <section class="form-panel" aria-labelledby="placement-history-heading">
            <h2 id="placement-history-heading" class="h4">Staff history</h2>
            <?php if ($placement['history'] === []): ?>
                <p>There are no recorded decisions for this placement.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($placement['history'] as $event): ?>
                        <?php $recorded = $time($event['created_at']); ?>
                        <li class="border-bottom py-3">
                            <p class="mb-1">
                                <strong><?= $escape($statusLabel($event['action'])) ?></strong>
                                <time class="ms-2" datetime="<?= $escape($recorded->format(DATE_ATOM)) ?>"><?= $escape($recorded->format('j M Y, H:i')) ?> WAT</time>
                            </p>
                            <p class="mb-1">
                                <?php if (is_string($event['from_status']) && $event['from_status'] !== $event['to_status']): ?>
                                    <?= $escape($statusLabel($event['from_status'])) ?> →
                                <?php endif; ?>
                                <?= $escape($statusLabel($event['to_status'])) ?>
                                <?php if ($event['actor_user_id'] !== null): ?>
                                    <span class="ms-2">Recorded by account #<?= (int) $event['actor_user_id'] ?></span>
                                <?php endif; ?>
                            </p>
                            <?php if (is_string($event['note']) && $event['note'] !== ''): ?>
                                <p class="directory-request-notes text-break mb-0"><?= $escape($event['note']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php elseif ($status === 'availability_confirmed' && (int) ($placement['requester_id'] ?? 0) === $data['viewer']): ?>
        <section class="form-panel" aria-labelledby="placement-selection-heading">
            <h2 id="placement-selection-heading" class="h4">Select this candidate</h2>
            <p>Furoeducare will coordinate the offer or arrangement and confirm the placement after the required checks.</p>
            <form method="post" action="<?= $escape($root) ?>">
                <?php require __DIR__ . '/placement-form-fields.php'; ?>
                <button class="btn button button-primary" name="action" value="select" type="submit">Select candidate</button>
            </form>
        </section>
    <?php endif; ?>
</div>

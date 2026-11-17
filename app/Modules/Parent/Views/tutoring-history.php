<?php use Furoeducare\Modules\Parent\TutoringStatus; ?>
<h3 class="h5 mt-4">Request history</h3>
<?php if ($history === []): ?><p>New updates will appear here.</p><?php endif; ?>
<ol class="list-group list-group-flush">
    <?php foreach ($history as $event): ?>
        <?php $when = (new DateTimeImmutable($event['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Lagos')); ?>
        <li class="list-group-item">
            <strong><?= $escape(TutoringStatus::from($event['to_status'])->label()) ?></strong>
            <time class="d-block small" datetime="<?= $escape($when->format(DATE_ATOM)) ?>"><?= $escape($when->format('j M Y, H:i')) ?> WAT</time>
            <?php if ($event['note'] !== ''): ?><p class="mb-0 text-break"><?= nl2br($escape($event['note'])) ?></p><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>

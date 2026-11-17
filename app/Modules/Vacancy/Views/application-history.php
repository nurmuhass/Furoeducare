<?php use Furoeducare\Modules\Vacancy\ApplicationStatus; ?>
<ol class="list-group list-group-flush">
    <?php foreach ($history as $entry): ?>
        <?php $when = (new DateTimeImmutable($entry['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Lagos')); ?>
        <li class="list-group-item">
            <strong><?= $escape(ApplicationStatus::fromStored($entry['to_status'])->label()) ?></strong>
            <time class="d-block small" datetime="<?= $escape($when->format(DATE_ATOM)) ?>"><?= $escape($when->format('j M Y, H:i')) ?> WAT</time>
            <?php if ($entry['note'] !== ''): ?><p class="mb-0 text-break"><?= nl2br($escape($entry['note'])) ?></p><?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>

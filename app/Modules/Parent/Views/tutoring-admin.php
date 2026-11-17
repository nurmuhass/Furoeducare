<?php

use Furoeducare\Modules\Parent\AdminTutoringController;
use Furoeducare\Modules\Parent\TutoringStatus;

$root = AdminTutoringController::ROOT;
$record = $data['record'];
?>
<section class="page-band">
    <div class="site-shell">
        <?php if ($record === null): ?>
            <a href="/dashboard">Back to dashboard</a>
            <h1 class="my-3">Tutoring requests</h1>
        <?php else: ?>
            <a href="<?= $escape($root) ?>">Back to tutoring requests</a>
            <h1 class="my-3">Tutoring request #<?= (int) $record['id'] ?></h1>
        <?php endif; ?>

        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>

        <?php if ($record !== null): ?>
            <?php require __DIR__ . '/tutoring-admin-detail.php'; ?>
        <?php else: ?>
            <form class="button-row mb-4" method="get" action="<?= $escape($root) ?>">
                <label class="form-label mb-0" for="tutoring-status">Request stage</label>
                <select class="form-select w-auto" id="tutoring-status" name="status">
                    <option value="">All stages</option>
                    <?php foreach (TutoringStatus::cases() as $stage): ?>
                        <?php if ($stage === TutoringStatus::Draft) continue; ?>
                        <option value="<?= $escape($stage->value) ?>" <?= $stage === $data['selected'] ? 'selected' : '' ?>>
                            <?= $escape($stage->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn button button-outline" type="submit">Filter</button>
            </form>

            <?php if ($data['rows'] === []): ?>
                <p class="form-panel">No tutoring requests on this page.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($data['rows'] as $item): ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                            <a class="text-break" href="<?= $escape($root) ?>?id=<?= (int) $item['id'] ?>">
                                Request #<?= (int) $item['id'] ?> · <?= $escape($item['subject']) ?>
                            </a>
                            <span class="status-badge"><?= $escape(TutoringStatus::from($item['status'])->label()) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($data['before'] !== null || $data['next'] !== null): ?>
                <?php $query = ['status' => $data['selected']?->value]; ?>
                <nav class="button-row mt-3" aria-label="Tutoring request pages">
                    <?php if ($data['before'] !== null): ?>
                        <a href="<?= $escape($root . '?' . http_build_query($query)) ?>">Newest requests</a>
                    <?php endif; ?>
                    <?php if ($data['next'] !== null): ?>
                        <a href="<?= $escape($root . '?' . http_build_query($query + ['before' => $data['next']])) ?>">Older requests</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php

use Furoeducare\Modules\Parent\AdminTutoringController;
use Furoeducare\Modules\Vacancy\VacancyController;

$root = $data['root'];
$source = $data['source'];
$staff = $data['staff'];
?>
<section class="page-band">
    <div class="site-shell">
        <a class="text-link" href="/dashboard">Back to dashboard</a>
        <h1 class="my-3"><?= $escape($data['title']) ?></h1>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
        <?php if (!$staff): ?>
            <p>Follow your school employment placements and private tutoring arrangements coordinated by Furoeducare.</p>
        <?php endif; ?>
    </div>
</section>

<div class="site-shell section-space">
    <?php if ($staff): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <section class="form-panel h-100" aria-labelledby="start-employment-heading">
                    <h2 id="start-employment-heading" class="h4">Start a school placement</h2>
                    <p><a href="<?= $escape(VacancyController::STAFF) ?>/applications">Open teaching applications</a></p>
                    <form class="auth-form" method="post" action="<?= $escape($root) ?>">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <div>
                            <label class="form-label" for="placement-application">Application number</label>
                            <input class="form-control" type="number" min="1" id="placement-application" name="application_id"
                                   value="<?= $escape((string) ($source['application'] ?? '')) ?>" required>
                        </div>
                        <button class="btn button button-primary" name="action" value="start_employment" type="submit">
                            Start screening
                        </button>
                    </form>
                </section>
            </div>
            <div class="col-md-6">
                <section class="form-panel h-100" aria-labelledby="start-tutoring-heading">
                    <h2 id="start-tutoring-heading" class="h4">Start a tutoring placement</h2>
                    <p><a href="<?= $escape(AdminTutoringController::ROOT) ?>">Open tutoring requests</a></p>
                    <form class="auth-form" method="post" action="<?= $escape($root) ?>">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <div>
                            <label class="form-label" for="placement-tutoring">Tutoring request number</label>
                            <input class="form-control" type="number" min="1" id="placement-tutoring" name="tutoring_id"
                                   value="<?= $escape((string) ($source['tutoring'] ?? '')) ?>" required>
                        </div>
                        <div>
                            <label class="form-label" for="placement-reference">Shortlisted tutor reference</label>
                            <input class="form-control" id="placement-reference" name="reference" maxlength="23"
                                   value="<?= $escape($source['reference'] ?? '') ?>" spellcheck="false" required>
                        </div>
                        <div>
                            <label class="form-label" for="placement-format">Lesson format</label>
                            <select class="form-select" id="placement-format" name="mode" required>
                                <option value="">Choose a format</option>
                                <option value="home"<?= ($source['mode'] ?? '') === 'home' ? ' selected' : '' ?>>Home tutoring</option>
                                <option value="online"<?= ($source['mode'] ?? '') === 'online' ? ' selected' : '' ?>>Online tutoring</option>
                            </select>
                        </div>
                        <button class="btn button button-primary" name="action" value="start_tutoring" type="submit">
                            Start screening
                        </button>
                    </form>
                </section>
            </div>
        </div>
    <?php endif; ?>

    <section aria-labelledby="placement-list-heading">
        <h2 id="placement-list-heading" class="h4">Placement activity</h2>
        <?php if ($data['placements'] === []): ?>
            <p>There are no placements to show yet.</p>
        <?php else: ?>
            <ul class="list-unstyled">
                <?php foreach ($data['placements'] as $placement): ?>
                    <li class="border-bottom py-3">
                        <a href="<?= $escape($root) ?>?id=<?= (int) $placement['id'] ?>" class="fw-semibold">
                            <?= $placement['kind'] === 'tutoring' ? 'Private tutoring' : 'School employment' ?> #<?= (int) $placement['id'] ?>
                        </a>
                        <span class="status-badge ms-2"><?= $escape(ucfirst(str_replace('_', ' ', $placement['status']))) ?></span>
                        <p class="mb-0 mt-1">
                            <?php if (($placement['source_label'] ?? '') !== ''): ?><?= $escape($placement['source_label']) ?> · <?php endif; ?>
                            <?= $escape($placement['teacher_reference']) ?>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($data['next'] !== null): ?>
                <a class="text-link" href="<?= $escape($root) ?>?before=<?= (int) $data['next'] ?>">Older placements</a>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

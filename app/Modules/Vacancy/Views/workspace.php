<?php
use Furoeducare\Modules\Vacancy\ApplicationStatus;
use Furoeducare\Modules\Vacancy\VacancyInput;
use Furoeducare\Modules\Vacancy\VacancyRepository;
use Furoeducare\Modules\Vacancy\VacancyStatus;

$staff = $data['staff'];
$root = $data['root'];
$vacancy = $data['vacancy'];
$status = $data['status'];
$selected = $data['selected'];
?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="<?= $staff ? '/dashboard/admin' : '/dashboard/school' ?>">Back to dashboard</a>
        <h1 class="mt-3"><?= $escape($data['title']) ?></h1>
        <p class="page-lede"><?= $staff ? 'Review submissions, publish approved postings and manage applications.' : 'Draft teaching vacancies and track their progress through review.' ?></p>
        <?php if ($staff): ?><a href="/dashboard/admin/vacancies/applications">All teaching applications</a><?php endif; ?>
    </div>
</section>
<section class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <?php if (!$staff && !$data['canSubmit']): ?>
        <p class="form-panel mb-4">You can save drafts while your school awaits approval. Submission requires school approval.</p>
    <?php endif; ?>
    <?php if ($data['new'] || $vacancy !== null): ?>
        <a class="d-inline-block mb-3" href="<?= $escape($root) ?>">All vacancies</a>
        <article class="form-panel mb-4">
            <?php if ($vacancy === null): ?>
                <h2 class="mb-3">New vacancy draft</h2>
                <?php require __DIR__ . '/editor.php'; ?>
            <?php else: ?>
                <h2 class="text-break"><?= $escape($vacancy['fields']['title'] ?: 'Untitled draft') ?></h2>
                <?php if (!$staff): ?><p><a href="/messages?new=1&amp;context_type=vacancy&amp;context_id=<?= (int) $vacancy['id'] ?>">Message management about this vacancy</a></p><?php endif; ?>
                <p><span class="status-badge"><?= $escape($status->label()) ?></span></p>
                <?php if ($staff): ?><p class="text-break"><?= $escape($vacancy['school_name']) ?></p><?php endif; ?>
                <?php if ($status === VacancyStatus::Published && $vacancy['application_deadline'] < VacancyInput::today()): ?>
                    <p>The application deadline has passed.</p>
                <?php endif; ?>
                <?php if (!$staff && $status->editable()): ?>
                    <?php if (in_array($status, [VacancyStatus::Approved, VacancyStatus::Published], true)): ?>
                        <p>Saving changed vacancy details returns the vacancy to draft. Submit it again for review.</p>
                    <?php endif; ?>
                    <?php require __DIR__ . '/editor.php'; ?>
                <?php else: ?>
                    <?php $fields = $vacancy['fields']; require __DIR__ . '/facts.php'; ?>
                <?php endif; ?>
                <?php $actions = $status->actions($staff, $data['canSubmit']); ?>
                <?php if ($actions !== []): ?>
                    <form method="post" action="<?= $escape($root) ?>" class="mt-4">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $vacancy['id'] ?>">
                        <input type="hidden" name="revision" value="<?= (int) $vacancy['revision'] ?>">
                        <?php if ($staff): ?>
                            <label class="form-label" for="vacancy-review-note">Note to the school (required for rejection)</label>
                            <textarea class="form-control mb-3" id="vacancy-review-note" name="note" maxlength="1000" rows="3"></textarea>
                        <?php elseif ($status === VacancyStatus::Draft): ?>
                            <p>Save changes before submitting this draft for review.</p>
                        <?php endif; ?>
                        <div class="button-row">
                            <?php foreach ($actions as $action => $unused): ?>
                                <button class="btn button <?= $action === 'reject' || $action === 'close' ? 'button-outline' : 'button-primary' ?>"
                                    name="action" value="<?= $escape($action) ?>" type="submit"><?= $escape(VacancyRepository::ACTIONS[$action]) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </article>
        <?php if ($vacancy !== null): ?>
            <div class="row g-4">
                <section class="<?= $staff ? 'col-lg-6' : 'col-12' ?>">
                    <div class="detail-card">
                        <h2 class="h4">Vacancy history</h2>
                        <ol class="list-group list-group-flush">
                            <?php foreach ($data['history'] as $entry): ?>
                                <li class="list-group-item">
                                    <strong><?= $escape(VacancyStatus::from($entry['to_status'])->label()) ?></strong>
                                    <small class="d-block"><?= $escape(substr($entry['created_at'], 0, 16)) ?> UTC</small>
                                    <?php if ($entry['note'] !== ''): ?><p class="mb-0 text-break"><?= nl2br($escape($entry['note'])) ?></p><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </section>
                <?php if ($staff): ?>
                    <section class="col-lg-6">
                        <div class="detail-card">
                            <h2 class="h4">Applications</h2>
                            <?php if ($data['candidates']['rows'] === []): ?><p>No applications on this page.</p><?php endif; ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($data['candidates']['rows'] as $candidate): ?>
                                    <li class="list-group-item">
                                        <a href="<?= $escape($root) ?>/application?id=<?= (int) $candidate['id'] ?>"><?= $escape($candidate['candidate_reference']) ?></a>
                                        <p class="mb-0"><?= $escape(ApplicationStatus::from($candidate['status'])->label()) ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($data['candidates']['next'] !== null): ?>
                                <a href="<?= $escape($root) ?>?id=<?= (int) $vacancy['id'] ?>&amp;before=<?= $data['candidates']['next'] ?>">Older applications</a>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($staff): ?>
            <form class="button-row mb-4" method="get" action="<?= $escape($root) ?>">
                <label class="form-label mb-0" for="vacancy-status">Vacancy status</label>
                <select class="form-select w-auto" id="vacancy-status" name="status">
                    <option value="">All review stages</option>
                    <?php foreach (VacancyStatus::cases() as $stage): ?>
                        <?php if ($stage === VacancyStatus::Draft) continue; ?>
                        <option value="<?= $escape($stage->value) ?>" <?= $stage === $selected ? 'selected' : '' ?>><?= $escape($stage->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn button button-outline" type="submit">Filter</button>
            </form>
        <?php else: ?>
            <a class="btn button button-primary mb-4" href="<?= $escape($root) ?>?new=1">Create vacancy draft</a>
        <?php endif; ?>
        <?php if ($data['rows'] === []): ?>
            <p class="form-panel">No vacancies on this page.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($data['rows'] as $item): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                        <div class="text-break">
                            <a href="<?= $escape($root) ?>?id=<?= (int) $item['id'] ?>"><?= $escape($item['fields']['title'] ?: 'Untitled draft') ?></a>
                            <?php if ($staff): ?><p class="mb-0"><?= $escape($item['school_name']) ?></p><?php endif; ?>
                        </div>
                        <span class="status-badge align-self-start"><?= $escape(VacancyStatus::from($item['status'])->label()) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($data['next'] !== null): ?>
            <a class="d-inline-block mt-3" href="<?= $escape($root . '?' . http_build_query(['before' => $data['next'], 'status' => $selected?->value])) ?>">Older vacancies</a>
        <?php endif; ?>
    <?php endif; ?>
</section>

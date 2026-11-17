<?php use Furoeducare\Modules\Vacancy\ApplicationStatus; ?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="/dashboard/admin/vacancies">Vacancy moderation</a>
        <h1 class="mt-3">Teaching applications</h1>
    </div>
</section>
<section class="site-shell section-space">
    <?php if ($data['rows'] === []): ?><p class="form-panel">No applications on this page.</p><?php endif; ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($data['rows'] as $entry): ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                <div class="text-break">
                    <a href="/dashboard/admin/vacancies/application?id=<?= (int) $entry['id'] ?>"><?= $escape($entry['candidate_reference']) ?></a>
                    <p class="mb-0"><?= $escape($entry['job_title']) ?></p>
                </div>
                <span class="status-badge align-self-start"><?= $escape(ApplicationStatus::from($entry['status'])->label()) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($data['next'] !== null): ?><a href="/dashboard/admin/vacancies/applications?before=<?= $data['next'] ?>">Older applications</a><?php endif; ?>
</section>

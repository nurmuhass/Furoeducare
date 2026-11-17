<?php use Furoeducare\Modules\Teacher\DashboardPage; ?>
<section class="teacher-card">
    <h2>Your bookmarks</h2>
    <?php if ($data['jobs'] === []): ?>
        <p>No saved jobs on this page. <a href="/teaching-jobs">Browse teaching jobs</a>.</p>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($data['jobs'] as $job): ?>
                <li class="list-group-item d-flex flex-wrap gap-3 justify-content-between">
                    <div class="text-break">
                        <?php if ($job['is_open']): ?>
                            <a href="/teaching-jobs/view?id=<?= (int) $job['vacancy_id'] ?>"><?= $escape($job['saved_title']) ?></a>
                        <?php else: ?>
                            <p class="mb-1"><?= $escape($job['saved_title']) ?></p>
                            <span class="teacher-tag">Vacancy unavailable</span>
                        <?php endif; ?>
                    </div>
                    <form method="post" action="/dashboard/teacher/saved-jobs">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $job['vacancy_id'] ?>">
                        <button class="btn button button-outline" name="action" value="remove" type="submit">Remove saved job</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($data['next'] !== null): ?><a href="<?= $escape(DashboardPage::SavedJobs->url()) ?>&amp;before=<?= $data['next'] ?>">More saved jobs</a><?php endif; ?>
</section>

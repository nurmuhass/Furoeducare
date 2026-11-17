<?php
use Furoeducare\Modules\Teacher\DashboardPage;
use Furoeducare\Modules\Vacancy\ApplicationStatus;

$application = $data['application'];
$path = DashboardPage::Applications->url();
$vacancyViews = dirname(__DIR__, 2) . '/Vacancy/Views';
?>
<?php if ($application === null): ?>
    <section class="teacher-card">
        <h2>Submitted applications</h2>
        <?php if ($data['applications'] === []): ?>
            <p>No applications on this page. <a href="/teaching-jobs">Browse teaching jobs</a>.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($data['applications'] as $entry): ?>
                    <li class="list-group-item d-flex flex-wrap gap-2 justify-content-between">
                        <div>
                            <a class="text-break" href="<?= $escape($path) ?>&amp;id=<?= (int) $entry['id'] ?>"><?= $escape($entry['job_title']) ?></a>
                            <small class="d-block"><?= $escape($entry['candidate_reference']) ?></small>
                        </div>
                        <span class="teacher-tag"><?= $escape(ApplicationStatus::from($entry['status'])->label()) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($data['next'] !== null): ?><a href="<?= $escape($path) ?>&amp;before=<?= $data['next'] ?>">Older applications</a><?php endif; ?>
    </section>
<?php else: ?>
    <?php $status = ApplicationStatus::from($application['status']); $fields = $application['job']['fields']; ?>
    <a href="<?= $escape($path) ?>">All applications</a>
    <section class="teacher-card my-3">
        <h2 class="text-break"><?= $escape($application['job_title']) ?></h2>
        <p class="text-break"><?= $escape($application['job']['school_name']) ?> · Posting at time of application</p>
        <?php require $vacancyViews . '/facts.php'; ?>
    </section>
    <div class="teacher-card mb-3">
        <?php require $vacancyViews . '/application-details.php'; ?>
    </div>
    <section class="teacher-card">
        <h2>Status history</h2>
        <?php $history = $data['history']; require $vacancyViews . '/application-history.php'; ?>
        <?php if ($status->transitions(false) !== []): ?>
            <form class="mt-3" method="post" action="/dashboard/teacher/applications/withdraw">
                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $application['id'] ?>">
                <input type="hidden" name="from" value="<?= $escape($status->value) ?>">
                <p>Withdrawing ends this application. You can submit one application per vacancy.</p>
                <button class="btn button button-outline" type="submit" name="action" value="withdrawn">Withdraw application</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php use Furoeducare\Modules\Vacancy\VacancyInput; ?>
<section class="page-band">
    <div class="site-shell page-intro">
        <h1>Teaching jobs</h1>
        <p class="page-lede">Explore open teaching vacancies from approved schools and educational institutions.</p>
    </div>
</section>
<section class="site-shell section-space">
    <?php if ($data['rows'] === []): ?>
        <div class="form-panel" data-empty-state>
            <h2>No open vacancies</h2>
            <p class="mb-0">Check back for new teaching vacancies.</p>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($data['rows'] as $vacancy): ?>
                <?php $fields = $vacancy['fields']; ?>
                <div class="col-md-6 col-xl-4">
                    <article class="detail-card h-100 d-flex flex-column">
                        <span class="status-badge align-self-start"><?= $escape(VacancyInput::EMPLOYMENT[$fields['employment_type']]) ?></span>
                        <h2 class="h4 mt-3 text-break"><?= $escape($fields['title']) ?></h2>
                        <p class="text-break"><?= $escape($vacancy['school_name']) ?> · <?= $escape($fields['city']) ?></p>
                        <dl>
                            <dt>Subjects</dt><dd class="text-break"><?= $escape($fields['subjects']) ?></dd>
                            <dt>Teaching level</dt><dd class="text-break"><?= $escape($fields['teaching_level']) ?></dd>
                            <dt>Apply by</dt><dd><time datetime="<?= $escape($fields['deadline']) ?>"><?= $escape($fields['deadline']) ?></time></dd>
                        </dl>
                        <a class="btn button button-outline align-self-start mt-auto" href="/teaching-jobs/view?id=<?= (int) $vacancy['id'] ?>">View vacancy</a>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($data['next'] !== null): ?>
        <nav class="button-row mt-4" aria-label="Vacancy pages"><a href="/teaching-jobs?before=<?= $data['next'] ?>">Older vacancies</a></nav>
    <?php endif; ?>
</section>

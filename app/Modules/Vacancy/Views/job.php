<?php
use Furoeducare\Modules\Teacher\DashboardPage;
use Furoeducare\Modules\Vacancy\ApplicationData;
$vacancy = $data['vacancy'];
?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="/teaching-jobs">All teaching jobs</a>
        <h1 class="my-3 text-break"><?= $escape($vacancy['fields']['title']) ?></h1>
        <p class="page-lede text-break"><?= $escape($vacancy['school_name']) ?></p>
    </div>
</section>
<section class="site-shell section-space">
    <div class="row g-4 align-items-start">
        <div class="col-lg-8 form-panel">
            <?php $fields = $vacancy['fields']; require __DIR__ . '/facts.php'; ?>
        </div>
        <aside class="col-lg-4">
            <div class="detail-card">
                <h2>Apply for this vacancy</h2>
                <?php if ($data['alreadyApplied']): ?>
                    <p>Your application is on file.</p>
                    <a href="<?= $escape(DashboardPage::Applications->url()) ?>">My applications</a>
                <?php elseif ($data['canApply']): ?>
                    <p>Submit your saved subjects, teaching levels, skills, certifications, qualifications and experience for Furoeducare to review.</p>
                    <form method="post" action="/teaching-jobs/apply">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $vacancy['id'] ?>">
                        <input type="hidden" name="revision" value="<?= (int) $vacancy['revision'] ?>">
                        <label class="form-label" for="application-statement">Application statement (optional)</label>
                        <textarea class="form-control<?= $data['statementError'] === '' ? '' : ' is-invalid' ?>" id="application-statement"
                            name="statement" rows="5" maxlength="<?= ApplicationData::STATEMENT_LIMIT ?>"
                            aria-describedby="statement-help<?= $data['statementError'] === '' ? '' : ' statement-error' ?>"
                            <?= $data['statementError'] === '' ? '' : 'aria-invalid="true"' ?>><?= $escape($data['statement']) ?></textarea>
                        <p class="form-text" id="statement-help">Use up to 2,000 characters. Keep personal contact details out of your statement.</p>
                        <?php if ($data['statementError'] !== ''): ?>
                            <p class="form-error" id="statement-error" role="alert"><?= $escape($data['statementError']) ?></p>
                        <?php endif; ?>
                        <button class="btn button button-primary" type="submit">Submit application</button>
                    </form>
                <?php elseif ($data['teacher']): ?>
                    <p>A complete teacher profile is required before you can apply.</p>
                    <a href="<?= $escape(DashboardPage::Profile->url()) ?>">Complete professional profile</a>
                <?php elseif (!$data['signedIn']): ?>
                    <p>Sign in with your teacher account to apply.</p>
                    <div class="button-row">
                        <a class="btn button button-primary" href="/login">Sign in</a>
                        <a href="/register/teacher">Register as a teacher</a>
                    </div>
                <?php else: ?>
                    <p>Applications are available to verified teacher and NYSC accounts with complete profiles.</p>
                <?php endif; ?>
            </div>
            <?php if ($data['teacher']): ?>
                <div class="detail-card mt-3">
                    <form method="post" action="/dashboard/teacher/saved-jobs">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $vacancy['id'] ?>">
                        <button class="btn button button-outline" name="action" value="<?= $data['saved'] ? 'remove' : 'save' ?>"
                            type="submit"><?= $data['saved'] ? 'Remove saved job' : 'Save job' ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>

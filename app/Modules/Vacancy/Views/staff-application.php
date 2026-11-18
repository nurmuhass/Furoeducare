<?php
use Furoeducare\Modules\Vacancy\ApplicationStatus;

$application = $data['application'];
$status = ApplicationStatus::from($application['status']);
$fields = $application['job']['fields'];
$history = $data['history'];
$transitions = array_filter($status->transitions(true), static fn (ApplicationStatus $next): bool => $next !== ApplicationStatus::Successful);
?>
<section class="page-band">
    <div class="site-shell page-intro">
        <a href="/dashboard/admin/vacancies/applications">Teaching applications</a>
        <h1 class="mt-3">Review application</h1>
        <a href="/dashboard/admin/verification/teachers?id=<?= (int) $application['teacher_id'] ?>">Review teacher profile</a>
    </div>
</section>
<section class="site-shell section-space">
    <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
    <div class="row g-4">
        <section class="col-lg-7">
            <div class="form-panel">
                <?php require __DIR__ . '/application-details.php'; ?>
                <hr>
                <h2 class="text-break"><?= $escape($application['job_title']) ?></h2>
                <p><?= $escape($application['job']['school_name']) ?> · Posting at time of application</p>
                <?php require __DIR__ . '/facts.php'; ?>
            </div>
        </section>
        <section class="col-lg-5">
            <div class="detail-card mb-4">
                <h2 class="h4">Application status</h2>
                <p><span class="status-badge"><?= $escape($status->label()) ?></span></p>
                <?php if ($application['placement_id'] !== null): ?>
                    <p><a href="/dashboard/admin/placements?id=<?= (int) $application['placement_id'] ?>">Open managed placement</a></p>
                <?php elseif (in_array($status, [ApplicationStatus::UnderReview, ApplicationStatus::Shortlisted, ApplicationStatus::InterviewInvited, ApplicationStatus::OfferPending], true)): ?>
                    <p><a href="/dashboard/admin/placements?application_id=<?= (int) $application['id'] ?>">Start managed placement</a></p>
                <?php endif; ?>
                <?php if ($transitions !== []): ?>
                    <form method="post" action="/dashboard/admin/vacancies/application">
                        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $application['id'] ?>">
                        <input type="hidden" name="from" value="<?= $escape($status->value) ?>">
                        <label class="form-label" for="application-note">Note to the applicant (optional)</label>
                        <textarea class="form-control mb-3" name="note" id="application-note" rows="3" maxlength="1000"></textarea>
                        <div class="button-row">
                            <?php foreach ($transitions as $next): ?>
                                <button class="btn button button-outline" name="action" value="<?= $escape($next->value) ?>" type="submit"><?= $escape(match ($next) {
                                    ApplicationStatus::UnderReview => 'Start review',
                                    ApplicationStatus::Shortlisted => 'Shortlist',
                                    ApplicationStatus::InterviewInvited => 'Record interview invitation',
                                    ApplicationStatus::OfferPending => 'Mark offer pending',
                                    ApplicationStatus::Unsuccessful => 'Mark unsuccessful',
                                }) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <div class="detail-card">
                <h2 class="h4">Status history</h2>
                <?php require __DIR__ . '/application-history.php'; ?>
            </div>
        </section>
    </div>
</section>

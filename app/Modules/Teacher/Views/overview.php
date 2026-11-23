<?php

use Furoeducare\Modules\Teacher\DashboardPage;
use Furoeducare\Modules\Verification\TeacherStatus;

$verification = TeacherStatus::from($profile['verification_status']);
$published = $profile['published_at'] !== null;
$hasCv = in_array('cv', array_column($profile['sections']['documents']['files'], 'kind'), true);
?>
<div class="teacher-overview-primary">
    <section class="teacher-card teacher-profile-summary" aria-labelledby="profile-completion-title">
        <div class="teacher-card-heading">
            <h2 id="profile-completion-title">Profile completion</h2>
            <span class="teacher-tag"><?= $published ? 'Published' : 'Private profile' ?></span>
        </div>
        <div class="teacher-progress-value"><?= $percent ?>%</div>
        <progress class="teacher-progress" value="<?= $percent ?>" max="100" aria-labelledby="profile-completion-title"></progress>
        <p class="teacher-muted"><?= $complete ?> of <?= $total ?> sections complete</p>
        <a class="btn button button-primary" href="<?= $escape(DashboardPage::Profile->url()) ?>">Edit professional profile</a>
    </section>

    <div class="teacher-readiness">
        <section class="teacher-card" aria-labelledby="verification-title">
            <h2 id="verification-title">Professional verification</h2>
            <p class="teacher-status-value"><span class="teacher-tag <?= $verification === TeacherStatus::Verified ? 'teacher-tag-success' : '' ?>"><?= $escape($verification->label()) ?></span></p>
            <p class="teacher-muted"><?= $escape(match ($verification) {
                TeacherStatus::Incomplete => $complete === $total ? 'Your profile is ready to submit.' : 'Complete the required profile sections to submit for review.',
                TeacherStatus::Pending => 'Furoeducare will review your profile. Professional verification is required for final placement.',
                TeacherStatus::Verified => 'Furoeducare has verified your professional profile.',
                TeacherStatus::CorrectionsRequired => 'Read the decision notes in Notifications and make the requested changes.',
                TeacherStatus::Rejected => 'Read the decision notes in Notifications.',
                TeacherStatus::Suspended => 'Contact Furoeducare for help with your account.',
            }) ?></p>
            <?php if ($complete === $total && in_array($verification, [TeacherStatus::Incomplete, TeacherStatus::CorrectionsRequired], true)): ?>
                <form method="post" action="/dashboard/teacher/verification/submit">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <button class="btn button button-primary" type="submit"><?= $verification === TeacherStatus::Incomplete ? 'Submit for verification' : 'Resubmit for verification' ?></button>
                </form>
            <?php endif; ?>
        </section>
        <section class="teacher-card" aria-labelledby="cv-overview-title">
            <h2 id="cv-overview-title">CV and documents</h2>
            <p class="teacher-status-value"><?= $hasCv ? 'CV on file' : 'CV needed' ?></p>
            <a href="<?= $escape(DashboardPage::Documents->url()) ?>"><?= $hasCv ? 'View document status' : 'Upload your CV' ?></a>
        </section>
    </div>
</div>

<section class="teacher-overview-section" aria-labelledby="activity-title">
    <h2 id="activity-title">Application activity</h2>
    <p><a href="<?= $escape(DashboardPage::Applications->url()) ?>">View my applications</a></p>
    <div class="dashboard-metrics" aria-label="Application metrics">
        <?php foreach (['Total applications' => $data['applicationCounts']['total'],
            'Active applications' => $data['applicationCounts']['active'], 'Interview invitations' => $data['applicationCounts']['interviews']] as $metric => $value): ?>
            <?= \Furoeducare\UI\Components::metric($metric, (int) $value) ?>
        <?php endforeach; ?>
    </div>
</section>

<div class="teacher-overview-updates">
    <section class="teacher-card" aria-labelledby="placement-title">
        <h2 id="placement-title">Placement updates</h2>
        <?php require __DIR__ . '/placement-updates.php'; ?>
    </section>
    <section class="teacher-card" aria-labelledby="recent-notifications-title">
        <h2 id="recent-notifications-title">Recent notifications</h2>
        <?php $notifications = $data['recentVerificationEvents']; require __DIR__ . '/verification-events.php'; ?>
        <?php if ($notifications !== []): ?><a href="<?= $escape(DashboardPage::Notifications->url()) ?>">All notifications</a><?php endif; ?>
    </section>
</div>

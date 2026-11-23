<?php

use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolStatus;

$school = $data['school'];
$status = SchoolStatus::forSchool($school);
$complete = SchoolPolicy::profileComplete($school);
$reviews = $data['reviews'];
$tab = $data['tab'];
$values = ($data['values'] ?? []) + $school + ['email' => $data['email']];
$errors = $data['errors'] ?? [];
$fieldPrefix = 'school';
require $partials . '/form-fields.php';
?>
<section class="page-band">
    <div class="site-shell">
        <div class="page-intro mb-4">
            <p class="page-lede"><?= $escape($school['school_name']) ?></p>
            <h1><?= $escape($data['title']) ?></h1>
        </div>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>

        <div class="row g-4 align-items-start">
            <div class="col-12">
                <div class="form-panel">
                    <?= \Furoeducare\UI\Components::error($errors) ?>
                    <?php switch ($tab):
                        case 'overview': ?>
                            <h2>Approval status</h2>
                            <p><?= \Furoeducare\UI\Components::status($status->value, $status->label()) ?></p>
                            <?php if (isset($reviews[0]) && $reviews[0]['decision'] === $status->value): ?>
                                <p class="my-2"><?= $escape($reviews[0]['reason']) ?></p>
                            <?php endif; ?>
                            <?php if (!$complete): ?>
                                <p class="mb-3">Complete your school profile before it can be reviewed for recruitment access.</p>
                            <?php elseif ($status === SchoolStatus::CorrectionsRequired): ?>
                                <p class="mb-3">Update the requested details in your profile to send your school back for review.</p>
                            <?php elseif (!SchoolPolicy::canRecruit($school)): ?>
                                    <p class="mb-3">Submitting vacancies or recruitment requests, accessing restricted teacher information and receiving approved shortlists require school approval.</p>
                            <?php else: ?>
                                    <p class="mb-3">Your school is approved to submit vacancies for management review.</p>
                            <?php endif; ?>
                            <div class="button-row">
                                <a class="btn button button-primary" href="/dashboard/school?tab=profile">Edit school profile</a>
                                <a class="btn button button-outline" href="/dashboard/school?tab=drafts">Manage recruitment drafts</a>
                                <?php if ($complete): ?><a class="btn button button-outline" href="/dashboard/school/vacancies">Manage teaching vacancies</a><?php endif; ?>
                                <?php if (SchoolPolicy::canRecruit($school)): ?><a class="btn button button-outline" href="/candidate-requests">Candidate requests</a><?php endif; ?>
                                <?php if (SchoolPolicy::canRecruit($school)): ?><a class="btn button button-outline" href="/placements">View placements</a><?php endif; ?>
                            </div>
                            <h2 class="mt-4">Public resources</h2>
                            <ul>
                                <li><a href="/find-teachers">Find teachers</a></li>
                                <?php if ($complete): ?>
                                    <li><a href="/find-teachers/saved">Saved teachers</a></li>
                                    <li><a href="/find-teachers/requests">Teacher requests and drafts</a></li>
                                <?php endif; ?>
                                <li><a href="/digital-library">Digital library</a></li>
                                <li><a href="/services">Explore services</a></li>
                            </ul>
                            <?php break;
                        case 'profile': ?>
                            <form class="auth-form" method="post" action="/dashboard/school/profile">
                                <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                                <?php $registration = false; require __DIR__ . '/profile-fields.php'; ?>
                                <?php if (!$complete) require __DIR__ . '/legal-fields.php'; ?>
                                <?php if ($status === SchoolStatus::Approved): ?>
                                    <p class="form-next">Changes to the institution’s identity, location, contact or representative details will send your profile back for review.</p>
                                <?php endif; ?>
                                <button class="btn button button-primary" type="submit">Save school profile</button>
                            </form>
                            <?php break;
                        case 'drafts':
                            require __DIR__ . '/drafts.php';
                            break;
                        case 'notifications': ?>
                            <h2>School review updates</h2>
                            <?php if ($reviews === []): ?>
                                <p>School review decisions will appear here.</p>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($reviews as $entry): ?>
                                        <li class="list-group-item">
                                            <strong><?= $escape(SchoolStatus::from($entry['decision'])->label()) ?></strong>
                                            <p><?= $escape($entry['reason']) ?></p>
                                            <small><?= $escape(substr($entry['created_at'], 0, 16)) ?> UTC</small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <h2 class="mt-4">Placement updates</h2>
                            <?php if ($data['placementNotices'] === []): ?>
                                <p>Placement updates will appear here after management publishes a shortlist.</p>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($data['placementNotices'] as $notice): ?>
                                        <li class="list-group-item">
                                            <strong><?= $escape($notice['title']) ?></strong>
                                            <p><?= $escape($notice['message']) ?></p>
                                            <small><?= $escape(substr($notice['created_at'], 0, 16)) ?> UTC</small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (SchoolPolicy::canRecruit($school)): ?><a href="/placements">View placements</a><?php endif; ?>
                            <h2 class="mt-4">Message updates</h2>
                            <?php if ($data['messageNotices'] === []): ?>
                                <p>You have no message notifications yet.</p>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($data['messageNotices'] as $notice): ?>
                                        <li class="list-group-item">
                                            <strong><?= $escape($notice['title']) ?></strong>
                                            <p><?= $escape($notice['message']) ?></p>
                                            <small><?= $escape(substr($notice['created_at'], 0, 16)) ?> UTC</small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <a href="/messages">Open messages</a>
                            <?php break;
                        case 'settings': ?>
                            <p><a href="/settings/account">Account security, phone, appearance and deactivation requests</a></p>
                            <p><a href="/settings/notifications">Notification settings</a></p>
                            <p><a href="/privacy/controls">Privacy controls and data rights</a></p>
                            <h2>Account</h2>
                            <p class="mb-2">Verified representative email <?= $escape($data['email']) ?></p>
                            <a href="/forgot-password">Reset password</a>
                            <?php break;
                    endswitch; ?>
                </div>
            </div>
        </div>
    </div>
</section>

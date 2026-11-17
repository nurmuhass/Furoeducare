<?php

use Furoeducare\Modules\Parent\AdminTutoringController;
use Furoeducare\Modules\Parent\ParentController;

$case = $data['case'];
$caseId = (int) $case['id'];
$staff = $data['staff'];
$root = $staff ? $data['staffRoot'] : $data['root'];
$casePath = $root . '?id=' . $caseId;
$closed = $case['status'] === 'closed';
$published = $case['shortlisted_at'] !== null;
$tutoring = $case['category'] === 'tutoring';
$contextLinked = $tutoring ? $case['tutoring_id'] !== null : $case['vacancy_id'] !== null;
$policy = $data['policy'];
$policyActive = $policy->active();
$scopeNames = ['professional' => 'Professional profile', 'original_cv' => 'Original CV'];
$accessOptions = array_column($data['shortlist'], null, 'reference');
if ($case['selected_reference'] !== null) {
    $accessOptions[$case['selected_reference']] ??= ['reference' => $case['selected_reference'], 'title' => 'Selected teacher'];
}
?>
<section class="page-band">
    <div class="site-shell">
        <a href="<?= $escape($root) ?>">Back to candidate requests</a>
        <h1 class="my-3"><?= $escape($data['title']) ?></h1>
        <p><span class="status-badge"><?= $escape(ucfirst(str_replace('_', ' ', $case['status']))) ?></span></p>
        <?php if ($data['notice'] !== ''): ?>
            <p class="auth-notice" role="status"><?= $escape($data['notice']) ?></p>
        <?php endif; ?>
        <dl>
            <dt>Purpose</dt>
            <dd class="text-break"><?= $escape($case['purpose']) ?></dd>
            <dt>Requirements</dt>
            <dd class="directory-request-notes text-break"><?= $escape($case['requirements']) ?></dd>
            <?php if ($tutoring && $case['tutoring_id'] !== null): ?>
                <dt>Tutoring request</dt>
                <dd><a href="<?= $escape($staff ? AdminTutoringController::ROOT . '?id=' . (int) $case['tutoring_id'] : ParentController::REQUESTS) ?>">
                    Open tutoring request #<?= (int) $case['tutoring_id'] ?>
                </a></dd>
            <?php elseif (!$tutoring && $case['vacancy_id'] !== null): ?>
                <dt>Vacancy</dt>
                <dd><?= $escape($case['vacancy_title'] ?? '#' . (int) $case['vacancy_id']) ?></dd>
            <?php endif; ?>
            <?php if ($case['selected_reference'] !== null): ?>
                <dt>Selected teacher</dt>
                <dd><?= $escape($case['selected_reference']) ?></dd>
            <?php endif; ?>
            <?php if ($staff): ?>
                <dt>Recipient account</dt>
                <dd><?= $escape(ucfirst($case['requester_role'])) ?> #<?= (int) $case['requester_id'] ?></dd>
                <dt>Assigned administrator</dt>
                <dd><?= $data['assignedToViewer'] ? 'You' : ($case['assigned_admin_id'] === null ? 'Unassigned' : '#' . (int) $case['assigned_admin_id']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</section>

<div class="site-shell section-space">
    <?php if (!$staff && $data['canLink']): ?>
        <section class="form-panel mb-4" aria-labelledby="candidate-link-heading">
            <h2 id="candidate-link-heading">Link this request to a vacancy</h2>
            <?php if ($data['vacancies'] === []): ?>
                <p>Your school needs an eligible vacancy to continue this request.</p>
            <?php else: ?>
                <form class="auth-form" method="post" action="<?= $escape($root) ?>">
                    <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= $caseId ?>">
                    <input type="hidden" name="action" value="link">
                    <?php
                    $vacancyPurpose = $case['purpose'];
                    $vacancyRequirements = $case['requirements'];
                    require __DIR__ . '/candidate-vacancy-fields.php';
                    ?>
                    <button class="btn button button-primary" type="submit">Link vacancy</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section aria-labelledby="candidate-shortlist-heading">
        <h2 id="candidate-shortlist-heading"><?= $staff && !$published ? 'Shortlist in preparation' : 'Candidate shortlist' ?></h2>
        <?php if ($data['shortlist'] === []): ?>
            <p><?= $published ? 'There are no available candidates on this shortlist.' : 'The shortlist has yet to be published.' ?></p>
        <?php else: ?>
            <ul class="list-unstyled">
                <?php foreach ($data['shortlist'] as $candidate): ?>
                    <li class="border-bottom py-3">
                        <?php if ($candidate['title'] !== null): ?>
                            <strong><?= $escape($candidate['title']) ?></strong>
                            <span class="ms-1"><?= $escape($candidate['reference']) ?></span>
                        <?php else: ?>
                            <strong><?= $escape($candidate['reference']) ?></strong>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($staff): ?>
            <?php require __DIR__ . '/candidate-case-actions.php'; ?>
        <?php endif; ?>
    </section>

    <section class="mt-5" aria-labelledby="candidate-disclosure-heading">
        <h2 id="candidate-disclosure-heading">Disclosure requests</h2>
        <?php if ($policy->noticeUrl !== '' || $policy->contact !== ''): ?>
            <p>
                <?php if ($policy->noticeUrl !== ''): ?>
                    <a href="<?= $escape($policy->noticeUrl) ?>" rel="noreferrer">Disclosure privacy notice</a>
                <?php endif; ?>
                <?php if ($policy->contact !== ''): ?>
                    <a href="mailto:<?= $escape($policy->contact) ?>">Contact the privacy team</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (!$policyActive): ?>
            <p>Access approvals and approved materials are paused until the disclosure policy is active.</p>
        <?php endif; ?>
        <?php if (!$staff && $contextLinked && !$closed && $case['status'] !== 'draft' && $accessOptions !== []): ?>
            <?php require __DIR__ . '/candidate-disclosure-request.php'; ?>
        <?php endif; ?>
        <?php require __DIR__ . '/candidate-disclosure-list.php'; ?>
        <?php if ($staff && $data['selected'] !== null): ?>
            <?php require __DIR__ . '/candidate-disclosure-review.php'; ?>
        <?php endif; ?>
    </section>
</div>

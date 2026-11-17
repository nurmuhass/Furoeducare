<?php

use Furoeducare\Modules\Verification\VerificationController;

$selection = $data['selected'];
$preview = $data['preview'];
$original = $selection['scope'] === 'original_cv';
$documentId = $preview['document_id'] ?? null;
$profileRevision = $preview['profile_revision'] ?? null;
$profile = $preview['profile'] ?? [];
$scopeAvailable = $original ? $documentId !== null : $profileRevision !== null
    && (($profile['qualifications'] ?? []) !== [] || ($profile['experience'] ?? []) !== []);
$currentNoticeAccepted = ($selection['notice_version'] ?? null) === $policy->version;
$canApprove = $scopeAvailable && $policyActive && $currentNoticeAccepted && !$closed;
$reviewId = (int) $selection['id'];
?>
<section class="form-panel mt-4" id="candidate-disclosure-review" aria-labelledby="candidate-disclosure-review-title">
    <h3 id="candidate-disclosure-review-title">Review <?= $escape($scopeNames[$selection['scope']]) ?> request</h3>
    <p>Candidate <?= $escape($selection['reference']) ?> · Recipient <?= $escape(ucfirst($case['requester_role'])) ?> #<?= (int) $case['requester_id'] ?></p>
    <p class="text-break">Purpose <?= $escape($case['purpose']) ?></p>
    <?php if (!$scopeAvailable): ?>
        <p>The requested material is currently unavailable for approval.</p>
    <?php elseif ($original): ?>
        <p><a href="<?= $escape(VerificationController::ROOT) ?>/documents?id=<?= (int) $documentId ?>">Review the exact CV in document verification</a></p>
    <?php else: ?>
        <?php require __DIR__ . '/candidate-profile-facts.php'; ?>
    <?php endif; ?>

    <?php if ($policyActive && !$closed && !$currentNoticeAccepted): ?>
        <p>The recipient must submit the request again to acknowledge the current privacy notice.</p>
    <?php endif; ?>
    <?php if ($canApprove): ?>
        <h4 class="h5 mt-4">Approve access</h4>
        <p>Use a record of the teacher’s specific consent for this recipient account, purpose, material and policy version <?= $escape($policy->version) ?>.</p>
        <form class="auth-form" method="post" action="<?= $escape($root) ?>">
            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
            <input type="hidden" name="id" value="<?= $caseId ?>">
            <input type="hidden" name="action" value="decide">
            <input type="hidden" name="notice_version" value="<?= $escape($policy->version) ?>">
            <input type="hidden" name="disclosure_id" value="<?= $reviewId ?>">
            <input type="hidden" name="decision" value="approve">
            <?php if ($original): ?>
                <input type="hidden" name="document_id" value="<?= (int) $documentId ?>">
            <?php else: ?>
                <input type="hidden" name="profile_revision" value="<?= (int) $profileRevision ?>">
            <?php endif; ?>
            <div>
                <label class="form-label" for="candidate-disclosure-evidence">Consent evidence reference</label>
                <input class="form-control" id="candidate-disclosure-evidence" name="evidence" minlength="8" maxlength="160" required>
                <p class="form-text">Enter the reference for the independently established consent record.</p>
            </div>
            <div>
                <label class="form-label" for="candidate-disclosure-hours">Hours until access expires</label>
                <input class="form-control" id="candidate-disclosure-hours" type="number" name="hours" min="1"
                       max="<?= $policy->limitHours($selection['scope']) ?>" required>
            </div>
            <div>
                <label class="form-label" for="candidate-disclosure-approve-reason">Approval reason</label>
                <textarea class="form-control" id="candidate-disclosure-approve-reason" name="reason" maxlength="1000" required></textarea>
            </div>
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="review_ok" value="1" required>
                <span class="form-check-label">I reviewed this exact material and confirmed specific consent. The material is limited to what this recipient needs for the stated purpose and contains no sensitive, private contact or third-party data.</span>
            </label>
            <button class="btn button button-primary" type="submit">Approve access</button>
        </form>
    <?php endif; ?>

    <form class="auth-form mt-4" method="post" action="<?= $escape($root) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
        <input type="hidden" name="id" value="<?= $caseId ?>">
        <input type="hidden" name="action" value="decide">
        <input type="hidden" name="disclosure_id" value="<?= $reviewId ?>">
        <input type="hidden" name="decision" value="reject">
        <div>
            <label class="form-label" for="candidate-disclosure-reject-reason">Rejection reason</label>
            <textarea class="form-control" id="candidate-disclosure-reject-reason" name="reason" maxlength="1000" required></textarea>
        </div>
        <button class="btn button button-outline" type="submit">Reject access</button>
    </form>
</section>

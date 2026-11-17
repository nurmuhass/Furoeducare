<?php

use Furoeducare\Modules\School\SchoolInput;
use Furoeducare\Modules\School\SchoolPolicy;
use Furoeducare\Modules\School\SchoolStatus;
use Furoeducare\Modules\Verification\ReviewInput;

$school = $data['school'];
$errors = $data['errors'];
$fieldPrefix = 'school-review';
require $partials . '/form-fields.php';
$display = [
    'Category' => SchoolInput::CATEGORIES[$school['category'] ?? ''] ?? null,
    'State or FCT' => SchoolInput::STATES[$school['state_code'] ?? ''] ?? null,
    'City or town' => $school['city'], 'Address' => $school['address'],
    'School contact email' => $school['contact_email'], 'School telephone' => $school['contact_phone'],
    'Representative' => $school['representative_name'], 'Position' => $school['representative_position'],
    'Representative email' => $school['representative_email'],
];
$canApprove = SchoolPolicy::profileComplete($school) && $school['email_verified_at'] !== null;
$checkLabels = ReviewInput::SCHOOL_CHECKS;
$checked = $data['checks'];
?>
<section class="page-band">
    <div class="site-shell">
        <a href="/dashboard/admin/schools">School account reviews</a>
        <h1 class="my-3"><?= $escape($school['school_name']) ?></h1>
        <p class="mb-3"><span class="status-badge"><?= $escape(SchoolStatus::forSchool($school)->label()) ?></span></p>
        <?php if ($data['notice'] !== ''): ?><p class="auth-notice mb-3" role="status"><?= $escape($data['notice']) ?></p><?php endif; ?>
        <div class="row g-4 align-items-start">
            <section class="col-lg-6">
                <div class="form-panel">
                    <h2>School details</h2>
                    <dl class="mt-3">
                        <?php foreach ($display as $label => $value): ?>
                            <dt><?= $escape($label) ?></dt><dd class="text-break"><?= $escape($value ?? 'Missing') ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <p><?= $school['email_verified_at'] === null ? 'Representative email not verified' : 'Representative email verified' ?></p>
                    <?php if ($school['account_suspended_at'] !== null): ?><p>Sign-in is suspended for this account.</p><?php endif; ?>
                </div>
            </section>
            <section class="col-lg-6">
                <div class="form-panel">
                    <h2>Record a decision</h2>
                    <?php if ($data['selfReview']): ?>
                        <p>Another administrator must review this account.</p>
                    <?php else: ?>
                        <?php if (!$canApprove): ?><p>A complete profile and verified email are required for approval.</p><?php endif; ?>
                        <?php if ($errors !== []): ?><p class="form-summary" role="alert">Review the highlighted fields and try again.</p><?php endif; ?>
                        <form class="auth-form" method="post" action="/dashboard/admin/schools/review">
                            <input type="hidden" name="_csrf" value="<?= $escape($data['csrf']) ?>">
                            <input type="hidden" name="id" value="<?= (int) $school['user_id'] ?>">
                            <input type="hidden" name="revision" value="<?= (int) $school['revision'] ?>">
                            <?php
                            $choices = [];
                            foreach (SchoolStatus::forSchool($school)->allowedDecisions() as $decision) {
                                if ($canApprove || $decision !== SchoolStatus::Approved) {
                                    $choices[$decision->value] = $decision->label();
                                }
                            }
                            $field('decision', 'Decision', $data['decision']?->value ?? '', 'select', $choices, $errors['decision'] ?? '', required: true);
                            $field('reason', 'Decision reason', $data['reason'], 'textarea',
                                ['required' => '', 'maxlength' => 1500, 'rows' => 4], $errors['reason'] ?? '', 'The school can read this reason in its notifications.');
                            ?>
                            <?php require dirname(__DIR__, 2) . '/Verification/Views/checks.php'; ?>
                            <button class="btn button button-primary" type="submit">Record review</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <section class="col-12">
                <div class="form-panel">
                    <h2>Review history</h2>
                    <?php if ($data['history'] === []): ?>
                        <p>No decisions recorded.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($data['history'] as $entry): ?>
                                <li class="list-group-item">
                                    <strong><?= $escape(SchoolStatus::from($entry['decision'])->label()) ?></strong>
                                    <p><?= $escape($entry['reason']) ?></p>
                                    <?php if ($entry['checks'] !== null): ?>
                                        <?php $recorded = json_decode($entry['checks'], true, flags: JSON_THROW_ON_ERROR); ?>
                                        <ul>
                                            <?php foreach ($checkLabels as $key => $label): ?>
                                                <li><?= $escape($label) ?> · <?= ($recorded[$key] ?? false) ? 'Confirmed' : 'Unconfirmed' ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <small><?= $escape($entry['reviewer_email']) ?> · <?= $escape(substr($entry['created_at'], 0, 16)) ?> UTC</small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</section>
